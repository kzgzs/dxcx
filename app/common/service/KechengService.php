<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;

/**
 * 知识付费服务（批次5-D1 任务197：课程与交易）
 *
 * 对标参考系统 canzhao/app/controller/ApiKecheng.php + KechengList.php/KechengSet.php：
 * - 课程/分类/章节 CRUD（B端）+ 订单/学习记录查询 + 系统设置
 * - C端：列表/目录/分类三级/详情/章节学习/下单/订单/进度上报/下一章/学习送积分
 * - 支付：统一支付单 pay_order order_type='kecheng'，回调走 PayService::handleBizPaySuccess
 *   分发到本服务 onPaid()（订单 0→1 + join_num++，幂等）
 * - 分销：createOrder 预计算三级分销（commissionset 四模式 + can_agent 门槛 + commission1own 自购返佣），
 *   写 member_commission_record 三条 status=0 冻结，结算沿用 CommissionSettleService（TYPES 已含 kecheng）
 *
 * 题库考试（getTiku/nextquestion/tofinish/complete）属批次5-D2 任务198，本服务不实现；
 * 预留 isChapterPassed()/hasTikuForChapter() 供 D2 与顺序解锁共用。
 */
class KechengService
{
    /** 订单状态 */
    public const ORDER_UNPAID = 0;
    public const ORDER_PAID   = 1;

    /* ==================== B端：课程分类 ==================== */

    /**
     * 分类列表（全量返回，前端按 pid 组树）
     *
     * @return array{list: array<int, array<string, mixed>>}
     */
    public function categoryList(int $aid): array
    {
        $rows = ScopeService::query('kecheng_category', $aid)
            ->order('sort', 'desc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        foreach ($rows as &$row) {
            $row['count'] = (int) ScopeService::query('kecheng_list', $aid)
                ->where('cid', 'like', '%' . (string) $row['id'] . '%')
                ->count();
        }
        unset($row);

        return ['list' => $rows];
    }

    /**
     * 分类新增/编辑
     *
     * @param array<string, mixed> $data
     * @return array{id: int}
     */
    public function categorySave(int $aid, array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ('' === $name) {
            throw new BusinessException('请填写分类名称');
        }

        $id  = (int) ($data['id'] ?? 0);
        $row = [
            'pid'    => (int) ($data['pid'] ?? 0),
            'name'   => $name,
            'pic'    => (string) ($data['pic'] ?? ''),
            'status' => (int) ($data['status'] ?? 1),
            'sort'   => (int) ($data['sort'] ?? 0),
        ];

        if ($id > 0) {
            $exists = ScopeService::query('kecheng_category', $aid)->where('id', $id)->find();
            if (empty($exists)) {
                throw new BusinessException('分类不存在', 404, 404);
            }
            Db::name('kecheng_category')->where('id', $id)->where('aid', $aid)->update($row);
        } else {
            $row['aid']        = $aid;
            $row['bid']        = 0;
            $row['pcid']       = '0';
            $row['createtime'] = time();
            $id                = (int) Db::name('kecheng_category')->insertGetId($row);
        }

        return ['id' => $id];
    }

    /**
     * 分类删除（有子分类或关联课程拒绝）
     */
    public function categoryDelete(int $aid, int $id): void
    {
        $exists = ScopeService::query('kecheng_category', $aid)->where('id', $id)->find();
        if (empty($exists)) {
            throw new BusinessException('分类不存在', 404, 404);
        }

        $child = ScopeService::query('kecheng_category', $aid)->where('pid', $id)->count();
        if ($child > 0) {
            throw new BusinessException('请先删除子分类');
        }

        $used = ScopeService::query('kecheng_list', $aid)
            ->where('cid', 'like', '%' . (string) $id . '%')
            ->count();
        if ($used > 0) {
            throw new BusinessException('该分类下还有课程，不能删除');
        }

        Db::name('kecheng_category')->where('id', $id)->where('aid', $aid)->delete();
    }

    /* ==================== B端：课程 ==================== */

    /**
     * 课程列表
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function courseList(int $aid, string $keyword, string $status, int $cid, int $page, int $pageSize): array
    {
        $query = ScopeService::query('kecheng_list', $aid);

        if ('' !== $keyword) {
            $query->where('name', 'like', '%' . $keyword . '%');
        }
        if ('' !== $status) {
            $query->where('status', (int) $status);
        }
        if ($cid > 0) {
            $query->where('cid', 'like', '%' . (string) $cid . '%');
        }

        $total = (clone $query)->count();

        $rows = $query->order('sort', 'desc')
            ->order('id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        foreach ($rows as &$row) {
            $row['commissiondata1'] = json_decode((string) ($row['commissiondata1'] ?: ''), true) ?: [];
            $row['commissiondata2'] = json_decode((string) ($row['commissiondata2'] ?: ''), true) ?: [];
            $row['commissiondata3'] = json_decode((string) ($row['commissiondata3'] ?: ''), true) ?: [];
            $row['lvprice_data']    = json_decode((string) ($row['lvprice_data'] ?: ''), true) ?: [];
            $row['chapter_count']   = (int) ScopeService::query('kecheng_chapter', $aid)
                ->where('kcid', (int) $row['id'])
                ->count();
        }
        unset($row);

        return ['list' => $rows, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize];
    }

    /**
     * 课程详情（B端编辑用）
     *
     * @return array<string, mixed>
     */
    public function courseDetail(int $aid, int $id): array
    {
        $row = ScopeService::query('kecheng_list', $aid)->where('id', $id)->find();
        if (empty($row)) {
            throw new BusinessException('课程不存在', 404, 404);
        }

        $row['commissiondata1'] = json_decode((string) ($row['commissiondata1'] ?: ''), true) ?: [];
        $row['commissiondata2'] = json_decode((string) ($row['commissiondata2'] ?: ''), true) ?: [];
        $row['commissiondata3'] = json_decode((string) ($row['commissiondata3'] ?: ''), true) ?: [];
        $row['lvprice_data']    = json_decode((string) ($row['lvprice_data'] ?: ''), true) ?: [];

        return $row;
    }

    /**
     * 课程新增/编辑（含分销配置/答题参数/会员价/购买权限）
     *
     * @param array<string, mixed> $data
     * @return array{id: int}
     */
    public function courseSave(int $aid, array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ('' === $name) {
            throw new BusinessException('请填写课程名称');
        }

        $price = (float) ($data['price'] ?? 0);
        if ($price < 0) {
            throw new BusinessException('价格不能为负');
        }

        $id  = (int) ($data['id'] ?? 0);
        $row = [
            'name'            => $name,
            'pic'             => (string) ($data['pic'] ?? ''),
            'pics'            => (string) ($data['pics'] ?? ''),
            'cid'             => (string) ($data['cid'] ?? '0'),
            'kctype'          => (int) ($data['kctype'] ?? 4),
            'price'           => $price,
            'market_price'    => (float) ($data['market_price'] ?? 0),
            'detail'          => (string) ($data['detail'] ?? ''),
            'detail_text'     => (string) ($data['detail_text'] ?? ''),
            'detail_pics'     => (string) ($data['detail_pics'] ?? ''),
            'sort'            => (int) ($data['sort'] ?? 0),
            'status'          => (int) ($data['status'] ?? 1),
            'gettj'           => (string) ($data['gettj'] ?? '-1'),
            'gettjurl'        => (string) ($data['gettjurl'] ?? ''),
            'gettjtip'        => (string) ($data['gettjtip'] ?? ''),
            'showtj'          => (string) ($data['showtj'] ?? '-1'),
            'sxdate'          => (int) ($data['sxdate'] ?? 0),
            'isdt'            => (int) ($data['isdt'] ?? 1),
            'dtnum'           => (int) ($data['dtnum'] ?? 5),
            'hgscore'         => (int) ($data['hgscore'] ?? 60),
            'commissionset'   => (int) ($data['commissionset'] ?? 0),
            'commissiondata1' => json_encode((array) ($data['commissiondata1'] ?? []), JSON_UNESCAPED_UNICODE),
            'commissiondata2' => json_encode((array) ($data['commissiondata2'] ?? []), JSON_UNESCAPED_UNICODE),
            'commissiondata3' => json_encode((array) ($data['commissiondata3'] ?? []), JSON_UNESCAPED_UNICODE),
            'lvprice'         => (int) ($data['lvprice'] ?? 0),
            'lvprice_data'    => json_encode((array) ($data['lvprice_data'] ?? []), JSON_UNESCAPED_UNICODE),
            'orderlearn'      => (int) ($data['orderlearn'] ?? 0),
            'learnhg'         => (int) ($data['learnhg'] ?? 0),
            'lecturerid'      => (int) ($data['lecturerid'] ?? 0),
            'fenhongset'      => (int) ($data['fenhongset'] ?? 1),
        ];

        if ($id > 0) {
            $exists = ScopeService::query('kecheng_list', $aid)->where('id', $id)->find();
            if (empty($exists)) {
                throw new BusinessException('课程不存在', 404, 404);
            }
            // 虚拟销量：后台可调整基数
            if (array_key_exists('join_num', $data)) {
                $row['join_num'] = max(0, (int) $data['join_num']);
            }
            Db::name('kecheng_list')->where('id', $id)->where('aid', $aid)->update($row);
        } else {
            $row['aid']        = $aid;
            $row['bid']        = 0;
            $row['pcid']       = '0';
            $row['join_num']   = max(0, (int) ($data['join_num'] ?? 0));
            $row['createtime'] = time();
            $id                = (int) Db::name('kecheng_list')->insertGetId($row);
        }

        return ['id' => $id];
    }

    /**
     * 课程删除（有已支付订单拒绝；级联删章节/学习记录）
     */
    public function courseDelete(int $aid, int $id): void
    {
        $exists = ScopeService::query('kecheng_list', $aid)->where('id', $id)->find();
        if (empty($exists)) {
            throw new BusinessException('课程不存在', 404, 404);
        }

        $paid = ScopeService::query('kecheng_order', $aid)
            ->where('kcid', $id)
            ->where('status', self::ORDER_PAID)
            ->count();
        if ($paid > 0) {
            throw new BusinessException('该课程已有已支付订单，不能删除');
        }

        Db::name('kecheng_list')->where('id', $id)->where('aid', $aid)->delete();
        Db::name('kecheng_chapter')->where('kcid', $id)->where('aid', $aid)->delete();
        Db::name('kecheng_studylog')->where('kcid', $id)->where('aid', $aid)->delete();
        Db::name('kecheng_tiku')->where('kcid', $id)->where('aid', $aid)->delete();
        Db::name('kecheng_order')->where('kcid', $id)->where('aid', $aid)->delete();
    }

    /**
     * 课程上下架
     */
    public function courseStatus(int $aid, int $id, int $status): void
    {
        $exists = ScopeService::query('kecheng_list', $aid)->where('id', $id)->find();
        if (empty($exists)) {
            throw new BusinessException('课程不存在', 404, 404);
        }

        Db::name('kecheng_list')->where('id', $id)->where('aid', $aid)->update(['status' => $status]);
    }

    /* ==================== B端：章节 ==================== */

    /**
     * 章节列表（按课程，返回 parent_id 供前端组树）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function chapterList(int $aid, int $kcid, int $page, int $pageSize): array
    {
        $query = ScopeService::query('kecheng_chapter', $aid)->where('kcid', $kcid);

        $total = (clone $query)->count();

        $rows = $query->order('sort', 'desc')
            ->order('id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        return ['list' => $rows, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize];
    }

    /**
     * 章节新增/编辑（kctype 1图文 2音频 3视频）
     *
     * @param array<string, mixed> $data
     * @return array{id: int}
     */
    public function chapterSave(int $aid, array $data): array
    {
        $kcid = (int) ($data['kcid'] ?? 0);
        $kc   = ScopeService::query('kecheng_list', $aid)->where('id', $kcid)->find();
        if (empty($kc)) {
            throw new BusinessException('课程不存在', 404, 404);
        }

        $name = trim((string) ($data['name'] ?? ''));
        if ('' === $name) {
            throw new BusinessException('请填写章节名称');
        }

        $kctype = (int) ($data['kctype'] ?? 1);
        if (!in_array($kctype, [1, 2, 3], true)) {
            throw new BusinessException('章节类型无效');
        }

        $id  = (int) ($data['id'] ?? 0);
        $row = [
            'kcid'           => $kcid,
            'name'           => $name,
            'pic'            => (string) ($data['pic'] ?? ''),
            'kctype'         => $kctype,
            'detail'         => (string) ($data['detail'] ?? ''),
            'voice_url'      => (string) ($data['voice_url'] ?? ''),
            'video_url'      => (string) ($data['video_url'] ?? ''),
            'video_duration' => (string) ($data['video_duration'] ?? ''),
            'ismianfei'      => (int) ($data['ismianfei'] ?? 2),
            'isjinzhi'       => (int) ($data['isjinzhi'] ?? 0),
            'jumpurl'        => (string) ($data['jumpurl'] ?? ''),
            'parent_id'      => (int) ($data['parent_id'] ?? 0),
            'give_score'     => (int) ($data['give_score'] ?? 0),
            'sort'           => (int) ($data['sort'] ?? 0),
            'status'         => (int) ($data['status'] ?? 1),
        ];

        if ($id > 0) {
            $exists = ScopeService::query('kecheng_chapter', $aid)->where('id', $id)->where('kcid', $kcid)->find();
            if (empty($exists)) {
                throw new BusinessException('章节不存在', 404, 404);
            }
            Db::name('kecheng_chapter')->where('id', $id)->where('aid', $aid)->update($row);
        } else {
            $row['aid']        = $aid;
            $row['bid']        = (int) ($kc['bid'] ?? 0);
            $row['createtime'] = time();
            $id                = (int) Db::name('kecheng_chapter')->insertGetId($row);
        }

        return ['id' => $id];
    }

    /**
     * 章节删除（有子章节拒绝）
     */
    public function chapterDelete(int $aid, int $id): void
    {
        $exists = ScopeService::query('kecheng_chapter', $aid)->where('id', $id)->find();
        if (empty($exists)) {
            throw new BusinessException('章节不存在', 404, 404);
        }

        $child = ScopeService::query('kecheng_chapter', $aid)->where('parent_id', $id)->count();
        if ($child > 0) {
            throw new BusinessException('请先删除子章节');
        }

        Db::name('kecheng_chapter')->where('id', $id)->where('aid', $aid)->delete();
    }

    /* ==================== B端：订单/学习记录/设置 ==================== */

    /**
     * 课程订单列表
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function orderList(int $aid, string $keyword, string $status, int $page, int $pageSize): array
    {
        $query = ScopeService::queryAs('kecheng_order', $aid, 'o')
            ->leftJoin('member m', 'm.id = o.mid')
            ->field('o.*, m.tel as member_tel, m.nickname as member_nickname');

        if ('' !== $status) {
            $query->where('o.status', (int) $status);
        }
        if ('' !== $keyword) {
            $query->where(function ($q) use ($keyword) {
                $q->where('o.title', 'like', '%' . $keyword . '%')
                    ->whereOr('o.ordernum', 'like', '%' . $keyword . '%')
                    ->whereOr('m.tel', 'like', '%' . $keyword . '%');
            });
        }

        $total = (clone $query)->count();

        $rows = $query->order('o.id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        foreach ($rows as &$row) {
            $row['createtime_text'] = date('Y-m-d H:i', (int) ($row['createtime'] ?: 0));
            $row['paytime_text']    = !empty($row['paytime']) ? date('Y-m-d H:i', (int) $row['paytime']) : '';
        }
        unset($row);

        return ['list' => $rows, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize];
    }

    /**
     * 学习记录列表
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function studylogList(int $aid, string $keyword, int $kcid, int $page, int $pageSize): array
    {
        $query = ScopeService::queryAs('kecheng_studylog', $aid, 's')
            ->leftJoin('member m', 'm.id = s.mid')
            ->field('s.*, m.tel as member_tel, m.nickname as member_nickname');

        if ($kcid > 0) {
            $query->where('s.kcid', $kcid);
        }
        if ('' !== $keyword) {
            $query->where(function ($q) use ($keyword) {
                $q->where('s.title', 'like', '%' . $keyword . '%')
                    ->whereOr('m.tel', 'like', '%' . $keyword . '%');
            });
        }

        $total = (clone $query)->count();

        $rows = $query->order('s.id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        foreach ($rows as &$row) {
            $row['createtime_text'] = date('Y-m-d H:i', (int) ($row['createtime'] ?: 0));
        }
        unset($row);

        return ['list' => $rows, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize];
    }

    /**
     * 课程设置读取（无记录返回默认）
     *
     * @return array<string, mixed>
     */
    public function syssetGet(int $aid): array
    {
        $row = ScopeService::query('kecheng_sysset', $aid)->find();

        return $row ?: [
            'aid'                        => $aid,
            'details_rec'                => 1,
            'showcommission'             => 0,
            'show_lvupsavemoney'         => 0,
            'show_join_num'              => 1,
            'show_buyed_kecheng_price'   => 1,
            'upgrade_text'               => '',
            'ios_canbuy'                 => 1,
            'send_score_num'             => 0,
            'give_score_num'             => 0,
            'lecturer_open'              => 0,
        ];
    }

    /**
     * 课程设置保存（按 aid upsert）
     *
     * @param array<string, mixed> $data
     */
    public function syssetSave(int $aid, array $data): void
    {
        $row = [
            'details_rec'              => (int) ($data['details_rec'] ?? 1),
            'showcommission'           => (int) ($data['showcommission'] ?? 0),
            'show_lvupsavemoney'       => (int) ($data['show_lvupsavemoney'] ?? 0),
            'show_join_num'            => (int) ($data['show_join_num'] ?? 1),
            'show_buyed_kecheng_price' => (int) ($data['show_buyed_kecheng_price'] ?? 1),
            'upgrade_text'             => (string) ($data['upgrade_text'] ?? ''),
            'ios_canbuy'               => (int) ($data['ios_canbuy'] ?? 1),
            'send_score_num'           => (int) ($data['send_score_num'] ?? 0),
            'give_score_num'           => (int) ($data['give_score_num'] ?? 0),
            'lecturer_open'            => (int) ($data['lecturer_open'] ?? 0),
        ];

        $exists = ScopeService::query('kecheng_sysset', $aid)->find();

        if ($exists) {
            Db::name('kecheng_sysset')->where('id', (int) $exists['id'])->update($row);
        } else {
            $row['aid'] = $aid;
            Db::name('kecheng_sysset')->insert($row);
        }
    }

    /* ==================== C端：列表/分类 ==================== */

    /**
     * 课程列表（C端；member 可为 null 匿名）
     *
     * @param array<string, mixed>|null $member
     * @return array{list: array<int, array<string, mixed>>}
     */
    public function apiList(int $aid, ?array $member, int $cid, string $keyword, int $pagenum): array
    {
        $query = ScopeService::query('kecheng_list', $aid)
            ->where('status', 1)
            ->where('bid', 0);

        if ($cid > 0) {
            // 含子分类
            $cids = [$cid];
            $childIds = ScopeService::query('kecheng_category', $aid)->where('pid', $cid)->column('id');
            $cids     = array_merge($cids, array_map('intval', $childIds));

            $parts = [];
            foreach ($cids as $c) {
                $parts[] = 'find_in_set(' . $c . ',cid)';
            }
            $query->whereRaw('(' . implode(' or ', $parts) . ')');
        }
        if ('' !== $keyword) {
            $query->where('name', 'like', '%' . $keyword . '%');
        }

        // 显示等级过滤 showtj：-1 全部 / 0 关注 / 指定等级
        $memberLevel = $member ? (string) ($member['levelid'] ?? 0) : '';
        $query->where(function ($q) use ($memberLevel, $member) {
            $q->whereRaw("find_in_set('-1',showtj)");
            if (null !== $member) {
                $q->whereOrRaw("find_in_set('" . $memberLevel . "',showtj)");
                if (1 === (int) ($member['subscribe'] ?? 0)) {
                    $q->whereOrRaw("find_in_set('0',showtj)");
                }
            }
        });

        $rows = $query->field('id,pic,name,price,market_price,join_num,kctype,lvprice,lvprice_data,cid,showtj')
            ->order('sort', 'desc')
            ->order('id', 'desc')
            ->page($pagenum, 10)
            ->select()
            ->toArray();

        foreach ($rows as &$row) {
            $row['count'] = (int) ScopeService::query('kecheng_chapter', $aid)
                ->where('kcid', (int) $row['id'])
                ->count();
        }
        unset($row);

        return ['list' => $this->formatProductList($aid, $rows, $member)];
    }

    /**
     * 目录列表（章节，含学习进度；需登录）
     *
     * @return array{list: array<int, array<string, mixed>>}
     */
    public function apiMuluList(int $aid, int $mid, int $kcid, string $keyword, int $pagenum): array
    {
        $query = ScopeService::query('kecheng_chapter', $aid)
            ->where('status', 1)
            ->where('kcid', $kcid)
            ->where('parent_id', 0);

        if ('' !== $keyword) {
            $query->where('name', 'like', '%' . $keyword . '%');
        }

        $rows = $query->field('id,pic,name,kctype,ismianfei,kcid,video_duration,video_url,sort,isjinzhi,give_score')
            ->order('sort', 'desc')
            ->order('id', 'desc')
            ->page($pagenum, 10)
            ->select()
            ->toArray();

        foreach ($rows as $k => $row) {
            if ((float) ($row['video_duration'] ?: 0) > 0) {
                $rows[$k]['duration'] = $this->formatDuration((int) (float) $row['video_duration']);
            }

            $study = ScopeService::query('kecheng_studylog', $aid)
                ->where('mid', $mid)
                ->where('mlid', (int) $row['id'])
                ->field('id,status,currentTime,jindu')
                ->order('id', 'desc')
                ->find();

            if ($study) {
                $rows[$k]['currentTime']   = $study['currentTime'];
                $rows[$k]['jindu']         = (string) ($study['jindu'] ?? '0');
                $rows[$k]['study_status']  = 1 === (int) $study['status'] ? 1 : 0;
                if (1 === (int) $study['status']) {
                    $rows[$k]['jindu'] = '100';
                }
            } else {
                $rows[$k]['study_status'] = 0;
            }

            // 答题状态（D2 消费口径：题库是否存在/是否合格）
            $rows[$k]['tiku_status']   = $this->hasTikuForChapter($aid, $kcid, (int) $row['id']) ? 1 : 0;
            $rows[$k]['record_status'] = $this->isChapterPassed($aid, $mid, $kcid, (int) $row['id']) ? 1 : 0;
            $rows[$k]['key']           = $k;

            // 子章节
            $children = ScopeService::query('kecheng_chapter', $aid)
                ->where('parent_id', (int) $row['id'])
                ->field('id,pic,name,kctype,ismianfei,kcid,video_duration,video_url,sort,isjinzhi,give_score')
                ->order('sort', 'desc')
                ->order('id', 'desc')
                ->select()
                ->toArray();

            foreach ($children as $ck => $c) {
                if ((float) ($c['video_duration'] ?: 0) > 0) {
                    $children[$ck]['duration'] = $this->formatDuration((int) (float) $c['video_duration']);
                }
                $cstudy = ScopeService::query('kecheng_studylog', $aid)
                    ->where('mid', $mid)
                    ->where('mlid', (int) $c['id'])
                    ->field('id,status,currentTime,jindu')
                    ->order('id', 'desc')
                    ->find();
                if ($cstudy) {
                    $children[$ck]['currentTime'] = $cstudy['currentTime'];
                    $children[$ck]['jindu']       = 1 === (int) $cstudy['status'] ? '100' : (string) ($cstudy['jindu'] ?? '0');
                    $children[$ck]['study_status'] = 1 === (int) $cstudy['status'] ? 1 : 0;
                } else {
                    $children[$ck]['study_status'] = 0;
                }
            }
            $rows[$k]['children'] = $children;
        }

        return ['list' => $rows];
    }

    /**
     * 一级分类（三级分类的第一级：平台分类）
     *
     * @return array{list: array<int, array<string, mixed>>}
     */
    public function apiCategory3(int $aid): array
    {
        $rows = ScopeService::query('kecheng_category', $aid)
            ->where('bid', 0)
            ->where('pid', 0)
            ->where('status', 1)
            ->order('sort', 'desc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        return ['list' => $rows];
    }

    /**
     * 二三级分类（按父级）
     *
     * @return array{list: array<int, array<string, mixed>>}
     */
    public function apiDownclist3(int $aid, int $pid): array
    {
        $rows = ScopeService::query('kecheng_category', $aid)
            ->where('pid', $pid)
            ->where('status', 1)
            ->order('sort', 'desc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        foreach ($rows as $k => $row) {
            $rows[$k]['child'] = ScopeService::query('kecheng_category', $aid)
                ->where('bid', '>', 0)
                ->where('pid', 0)
                ->where('pcid', (string) $row['id'])
                ->where('status', 1)
                ->order('sort', 'desc')
                ->order('id', 'asc')
                ->select()
                ->toArray();
        }

        return ['list' => $rows];
    }

    /**
     * 分类下课程（按分类聚合分页）
     *
     * @param array<string, mixed>|null $member
     * @return array{list: array, total: int, page_size: int}
     */
    public function apiClassifyKecheng(int $aid, ?array $member, int $cid, int $lastId, int $lastClassifyId): array
    {
        $pageSize = 10;

        $clist = ScopeService::query('kecheng_category', $aid)
            ->where('status', 1)
            ->where(function ($q) use ($cid) {
                $q->where('pid', $cid)->whereOr('id', $cid);
            })
            ->order('sort', 'desc')
            ->order('id', 'asc')
            ->column('id,pid,name,pic,sort,createtime', 'id');

        if ($lastClassifyId > 0) {
            $key   = array_search($lastClassifyId, array_keys($clist), true);
            $clist = false === $key ? [] : array_slice($clist, (int) $key, null, true);
        }

        $resultData = [];
        $totalCount = 0;

        foreach ($clist as $v) {
            $sub = ScopeService::query('kecheng_list', $aid)
                ->where('status', 1)
                ->where('bid', 0)
                ->where('cid', (string) $v['id']);

            if ($lastId > 0 && (int) $v['id'] === $lastClassifyId) {
                $sub->where('id', '>', $lastId);
            }

            $rs = $sub->order('sort', 'desc')->order('id', 'asc')->limit($pageSize)->select()->toArray();

            if (empty($rs)) {
                continue;
            }

            $rs        = $this->formatProductList($aid, $rs, $member);
            $remaining = $pageSize - $totalCount;
            $reCount   = count($rs);

            if ($remaining >= $reCount) {
                $v['child'] = $rs;
                $resultData[] = $v;
                $totalCount += $reCount;
            } else {
                $v['child']   = array_slice($rs, 0, $remaining);
                $resultData[] = $v;
                $totalCount += $remaining;
                break;
            }

            if ($totalCount >= $pageSize) {
                break;
            }
        }

        return ['list' => $resultData, 'total' => $totalCount, 'page_size' => $pageSize];
    }

    /**
     * 课程详情（C端；匿名可看，登录附会员价/是否已购/预计佣金）
     *
     * @param array<string, mixed>|null $member
     * @return array<string, mixed>
     */
    public function apiDetail(int $aid, ?array $member, int $id): array
    {
        $product = ScopeService::query('kecheng_list', $aid)->where('id', $id)->find();
        if (empty($product)) {
            throw new BusinessException('课程不存在', 404, 404);
        }
        if (0 === (int) $product['status']) {
            throw new BusinessException('课程未上架');
        }

        // 显示等级限制 showtj：-1 全部 / -2 游客 / 0 关注 / 指定等级（'0' 为合法值，不可用 ?: 回退）
        $levelids = explode(',', (string) ($product['showtj'] ?? '-1'));
        if (!in_array('-1', $levelids, true)) {
            if (null === $member) {
                if (!in_array('-2', $levelids, true)) {
                    throw new BusinessException('请先登录', 401, 401);
                }
            } else {
                $ok = in_array((string) ($member['levelid'] ?? 0), $levelids, true)
                    || (in_array('0', $levelids, true) && 1 === (int) ($member['subscribe'] ?? 0));
                if (!$ok) {
                    throw new BusinessException('课程状态不可见', 403, 403);
                }
            }
        }

        if (empty($product['pics'])) {
            $product['pics'] = (string) $product['pic'];
        }
        $product['pics'] = explode(',', (string) $product['pics']);
        $product         = $this->formatProduct($aid, $product, $member);

        $product['count'] = (int) ScopeService::query('kecheng_chapter', $aid)
            ->where('kcid', $id)
            ->count();

        // 是否已购买
        $product['ispay'] = 0;
        if (null !== $member) {
            $order = ScopeService::query('kecheng_order', $aid)
                ->where('kcid', $id)
                ->where('mid', (int) $member['id'])
                ->where('status', self::ORDER_PAID)
                ->order('id', 'desc')
                ->find();

            if ($order) {
                // 当时免费、现在收费的等级：仍需付费
                $product['ispay'] = ((float) $order['totalprice'] == 0.0 && (float) $product['price'] > 0) ? 0 : 1;
            }
        }

        // 预计佣金（showcommission 开启 + 会员可分销）
        $kechengset = $this->syssetGet($aid);
        $product['commission'] = $this->previewCommission($aid, $product, $member, 1 === (int) ($kechengset['showcommission'] ?? 0));

        // 详情推荐
        $tjlist = [];
        if (1 === (int) ($kechengset['details_rec'] ?? 0)) {
            $tjlist = ScopeService::query('kecheng_list', $aid)
                ->where('status', 1)
                ->where('bid', 0)
                ->limit(6)
                ->order('id', 'desc')
                ->select()
                ->toArray();
            $tjlist = $this->formatProductList($aid, $tjlist, $member);
            foreach ($tjlist as &$tj) {
                $tj['count'] = (int) ScopeService::query('kecheng_chapter', $aid)->where('kcid', (int) $tj['id'])->count();
            }
            unset($tj);
        }

        return [
            'product'    => $product,
            'kechengset' => $kechengset,
            'tjdatalist' => $tjlist,
        ];
    }

    /* ==================== C端：章节学习 ==================== */

    /**
     * 章节学习详情（进入学习主入口；对标 mldetail）
     *
     * - 未指定章节：按最近学习记录定位，无记录建首章档案
     * - 免费课无订单自动补 status=1 订单 + join_num++（幂等）
     * - orderlearn=1 顺序学习 / learnhg=1 章节考试合格解锁（判定见 isChapterPassed）
     *
     * @return array<string, mixed>
     */
    public function apiMlDetail(int $aid, int $mid, int $kcid, int $chapterId): array
    {
        $kc = ScopeService::query('kecheng_list', $aid)->where('id', $kcid)->find();
        if (empty($kc)) {
            throw new BusinessException('课程不存在', 404, 404);
        }

        $member = $this->memberOf($aid, $mid);
        $kc     = $this->formatProduct($aid, $kc, $member);

        // 章节全集（顶级，sort desc/id desc 与参考系统一致）
        $chapters = ScopeService::query('kecheng_chapter', $aid)
            ->where('kcid', $kcid)
            ->where('status', 1)
            ->order('sort', 'desc')
            ->order('id', 'desc')
            ->column('*', 'id');

        if (empty($chapters)) {
            throw new BusinessException('课程章节不存在');
        }

        $order = ScopeService::query('kecheng_order', $aid)
            ->where('kcid', $kcid)
            ->where('mid', $mid)
            ->where('status', self::ORDER_PAID)
            ->order('id', 'desc')
            ->find();

        if ($chapterId <= 0) {
            $detail    = null;
            $studylog  = ScopeService::query('kecheng_studylog', $aid)
                ->where('mid', $mid)
                ->where('kcid', $kcid)
                ->order('id', 'desc')
                ->find();

            if ($studylog) {
                if (0 === (int) $studylog['status']) {
                    // 学习中的章节
                    $detail = Db::name('kecheng_chapter')->where('id', (int) $studylog['mlid'])->where('aid', $aid)->find();
                } elseif (1 === (int) $studylog['status']) {
                    $holdMlid = 0;
                    // 章节考试合格解锁：本章有题库且未合格 → 停留在本章
                    if (1 === (int) ($kc['learnhg'] ?? 0)
                        && $this->hasTikuForChapter($aid, $kcid, (int) $studylog['mlid'])
                        && !$this->isChapterPassed($aid, $mid, $kcid, (int) $studylog['mlid'])) {
                        $holdMlid = (int) $studylog['mlid'];
                    }

                    if ($holdMlid > 0) {
                        $detail = Db::name('kecheng_chapter')->where('id', $holdMlid)->where('aid', $aid)->find();
                    } else {
                        // 下一章
                        if (count($chapters) > 1) {
                            $detail = $this->nextChapterOf($chapters, (int) $studylog['mlid']);
                            if (empty($detail)) {
                                $detail = $chapters[(int) $studylog['mlid']] ?? null;
                            }
                        } else {
                            $detail = $chapters[(int) $studylog['mlid']] ?? null;
                        }
                    }
                } else {
                    throw new BusinessException('课程章节记录出错');
                }
            } else {
                // 无学习记录：从第一章开始建档
                $detail = reset($chapters);
                $this->addStudyRecord($aid, $mid, $kcid, (array) $detail);
                $studylog = ScopeService::query('kecheng_studylog', $aid)
                    ->where('mid', $mid)
                    ->where('kcid', $kcid)
                    ->where('mlid', (int) $detail['id'])
                    ->order('id', 'desc')
                    ->find();
            }

            if (empty($detail)) {
                throw new BusinessException('课程章节不存在');
            }

            // 免费课自动成单（幂等）/ 付费校验
            if ((float) $kc['price'] == 0.0 && empty($order)) {
                $order = $this->ensureFreeOrder($aid, $mid, $kc);
            } elseif ((float) $kc['price'] > 0 && empty($order)) {
                throw new BusinessException('请先购买课程');
            } elseif ((float) $kc['price'] > 0 && (float) ($order['totalprice'] ?? 0) == 0.0) {
                throw new BusinessException('请先购买课程');
            }
        } else {
            $detail = ScopeService::query('kecheng_chapter', $aid)
                ->where('id', $chapterId)
                ->where('status', 1)
                ->find();
            if (empty($detail)) {
                throw new BusinessException('章节不存在', 404, 404);
            }

            $this->assertChapterUnlock($aid, $mid, $kc, $chapters, $chapterId);

            $studylog = ScopeService::query('kecheng_studylog', $aid)
                ->where('mid', $mid)
                ->where('kcid', $kcid)
                ->where('mlid', $chapterId)
                ->order('id', 'desc')
                ->find();

            if (empty($studylog)) {
                $this->addStudyRecord($aid, $mid, $kcid, $detail);
                $studylog = ScopeService::query('kecheng_studylog', $aid)
                    ->where('mid', $mid)
                    ->where('kcid', $kcid)
                    ->where('mlid', $chapterId)
                    ->order('id', 'desc')
                    ->find();
            }
        }

        // 免费章节豁免购买校验
        if (1 !== (int) ($detail['ismianfei'] ?? 2) && (float) $kc['price'] > 0 && empty($order)) {
            throw new BusinessException('请先购买课程');
        }
        if (0 === (int) ($detail['status'] ?? 1)) {
            throw new BusinessException('章节未上架');
        }

        // 上次播放位置
        if ((int) $detail['kctype'] > 1) {
            $detail['startTime'] = $studylog ? (float) ($studylog['currentTime'] ?? 0) : 0.0;
        }

        $detail['ispay']    = ($order && 1 === (int) $order['status']) ? 1 : 0;
        $detail['isstudy']  = $studylog ? (int) $studylog['status'] : 0;
        $detail['isdt']     = (int) ($kc['isdt'] ?? 0);
        $detail['price']    = $kc['price'];
        $detail['orderlearn'] = (int) ($kc['orderlearn'] ?? 0);
        $detail['learnhg']    = (int) ($kc['learnhg'] ?? 0);

        $detail['count'] = (int) ScopeService::query('kecheng_studylog', $aid)
            ->where('kcid', (int) $detail['kcid'])
            ->where('status', 1)
            ->where('mid', $mid)
            ->group('mlid')
            ->count();
        $detail['kccount'] = (int) ScopeService::query('kecheng_chapter', $aid)
            ->where('kcid', (int) $detail['kcid'])
            ->count();

        // 当前学到第几个
        $learnkey = 0;
        foreach ($chapters as $cv) {
            if ((int) $cv['id'] === (int) ($studylog['mlid'] ?? 0)) {
                break;
            }
            ++$learnkey;
        }
        $detail['learnkey'] = $learnkey;

        // 课程级考试是否合格（D2 展示口径）
        $iskaoshi = ScopeService::query('kecheng_record', $aid)
            ->where('kcid', (int) $detail['kcid'])
            ->where('mid', $mid)
            ->where('ishg', 1)
            ->count();

        Db::name('kecheng_list')->where('id', (int) $detail['kcid'])->inc('readnum', 1)->update();

        return [
            'detail'    => $detail,
            'studylog'  => $studylog ?: [],
            'chapters'  => array_values($chapters),
            'iskaoshi'  => $iskaoshi > 0 ? 1 : 0,
            'sysset'    => $this->syssetGet($aid),
        ];
    }

    /**
     * 学习进度上报（对标 addstudy）
     *
     * 音视频：jindu = currentTime/video_duration*100，播满置完成；图文直接完成
     *
     * @return array{jindu: string, status: int}
     */
    public function apiAddStudy(int $aid, int $mid, int $logid, float $currentTime, string $playJd): array
    {
        $study = ScopeService::query('kecheng_studylog', $aid)
            ->where('mid', $mid)
            ->where('id', $logid)
            ->find();
        if (empty($study)) {
            throw new BusinessException('学习记录不存在', 404, 404);
        }

        $ml = Db::name('kecheng_chapter')->where('id', (int) $study['mlid'])->where('aid', $aid)->find();
        if (empty($ml)) {
            throw new BusinessException('章节不存在', 404, 404);
        }

        if (1 === (int) $study['status']) {
            return ['jindu' => '100', 'status' => 1]; // 已完成：幂等
        }

        $data = ['createtime' => time()];
        if ($currentTime > 0) {
            $data['currentTime'] = $currentTime;
        }

        $jindu  = (string) ($study['jindu'] ?? '0');
        $status = 0;

        if (in_array((int) $ml['kctype'], [2, 3], true)) {
            $duration = (float) ($ml['video_duration'] ?: 0);
            if ('' !== $playJd) {
                $jindu = $playJd;
            } elseif ($duration > 0 && $currentTime > 0) {
                $jindu = (string) min(100, (int) ($currentTime / $duration * 100));
            }
            if ($duration > 0 && $currentTime >= $duration) {
                $status = 1;
                $jindu  = '100';
            }
        } else {
            // 图文：上报即学完
            $status = 1;
            $jindu  = '100';
        }

        if (1 === $status) {
            $data['status'] = 1;
            $data['jindu']  = '100';
        } else {
            $data['jindu'] = $jindu;
        }

        Db::name('kecheng_studylog')->where('id', (int) $study['id'])->where('status', 0)->update($data);

        return ['jindu' => $data['jindu'] ?? $jindu, 'status' => $status];
    }

    /**
     * 下一章（对标 nextsection：id 升序取大于当前的第一个）
     *
     * @return array{id: int}
     */
    public function apiNextSection(int $aid, int $id, int $kcid): array
    {
        $detail = ScopeService::query('kecheng_chapter', $aid)
            ->where('id', '>', $id)
            ->where('kcid', $kcid)
            ->order('id', 'asc')
            ->find();

        return ['id' => $detail ? (int) $detail['id'] : 0];
    }

    /**
     * 学习送积分（kecheng_give_scorelog 去重）
     *
     * @return array{score: int, msg: string}
     */
    public function apiGiveScore(int $aid, int $mid, int $kccid): array
    {
        $exists = Db::name('kecheng_give_scorelog')
            ->where('aid', $aid)
            ->where('mid', $mid)
            ->where('kccid', $kccid)
            ->find();
        if ($exists) {
            throw new BusinessException('已赠送');
        }

        $chapter = Db::name('kecheng_chapter')->where('id', $kccid)->where('aid', $aid)->find();
        if (empty($chapter)) {
            throw new BusinessException('章节不存在', 404, 404);
        }

        $giveScore = (int) ($chapter['give_score'] ?? 0);
        if ($giveScore <= 0) {
            throw new BusinessException('该章节未设置赠送积分');
        }

        Db::transaction(function () use ($aid, $mid, $kccid, $giveScore, $chapter) {
            Db::name('kecheng_give_scorelog')->insert([
                'aid'        => $aid,
                'bid'        => (int) ($chapter['bid'] ?? 0),
                'mid'        => $mid,
                'kccid'      => $kccid,
                'score'      => $giveScore,
                'createtime' => time(),
            ]);

            // 加积分 + 流水（口径对齐 ScoreService：type=1 赠送）
            Db::name('member')->where('id', $mid)->where('aid', $aid)->inc('score', $giveScore)->update();
            Db::name('score_log')->insert([
                'aid'         => $aid,
                'mid'         => $mid,
                'score'       => $giveScore,
                'remark'      => '学习章节赠送：' . (string) ($chapter['name'] ?? ''),
                'type'        => 1,
                'create_time' => time(),
            ]);
        });

        return ['score' => $giveScore, 'msg' => '恭喜获得' . $giveScore . '积分'];
    }

    /**
     * 课程海报模板（C端消费 PosterService：type=kecheng 首个模板）
     *
     * @return array<string, mixed>
     */
    public function apiGetPoster(int $aid, int $mid, int $proid, string $platform): array
    {
        $product = ScopeService::query('kecheng_list', $aid)->where('id', $proid)->find();
        if (empty($product)) {
            throw new BusinessException('课程不存在', 404, 404);
        }

        $member = $this->memberOf($aid, $mid);

        $template = Db::name('admin_set_poster')
            ->where('aid', $aid)
            ->where('type', 'kecheng')
            ->where('platform', $platform)
            ->order('id', 'asc')
            ->find();

        return [
            'template'       => $template ?: [],
            'textReplaceArr' => [
                '[头像]'       => (string) ($member['headimg'] ?? ''),
                '[昵称]'       => (string) ($member['nickname'] ?? ''),
                '[姓名]'       => (string) ($member['realname'] ?? ''),
                '[手机号]'     => (string) ($member['tel'] ?? ''),
                '[课程名称]'   => (string) ($product['name'] ?? ''),
                '[课程销售价]' => (string) ($product['price'] ?? ''),
                '[课程市场价]' => (string) ($product['market_price'] ?? ''),
                '[商品图片]'   => (string) ($product['pic'] ?? ''),
            ],
            'page'  => '/activity/kecheng/product',
            'scene' => 'id_' . $proid . '-pid_' . $mid,
        ];
    }

    /* ==================== C端：下单/订单 ==================== */

    /**
     * 创建课程订单（对标 createOrder）
     *
     * gettj 购买权限 → 会员价 → 三级分销预计算 → kecheng_order(status=0)
     * → member_commission_record×3(status=0 冻结) → 统一支付单 pay_order(type=kecheng)
     *
     * @return array{orderid: int, ordernum: string, price: float, payorderid: int, out_trade_no: string}
     */
    public function apiCreateOrder(int $aid, int $mid, int $kcid): array
    {
        $kc = ScopeService::query('kecheng_list', $aid)->where('id', $kcid)->find();
        if (empty($kc)) {
            throw new BusinessException('课程不存在', 404, 404);
        }
        if (0 === (int) $kc['status']) {
            throw new BusinessException('课程未上架');
        }

        $member = $this->memberOf($aid, $mid);

        // 购买权限 gettj：-1 全部 / 0 关注 / 指定等级（'0' 为合法值，不可用 ?: 回退）
        $gettj = explode(',', (string) ($kc['gettj'] ?? '-1'));
        if (!in_array('-1', $gettj, true)
            && !in_array((string) ($member['levelid'] ?? 0), $gettj, true)
            && (!in_array('0', $gettj, true) || 1 !== (int) ($member['subscribe'] ?? 0))) {
            $tip = (string) ($kc['gettjtip'] ?: '没有权限购买该课程');
            throw new BusinessException($tip, 403, 403);
        }

        $kc = $this->formatProduct($aid, $kc, $member);

        // 免费课直接成单（无需支付）
        if ((float) $kc['price'] == 0.0) {
            $orderId = $this->insertFreeOrder($aid, $mid, $kc);

            return ['orderid' => $orderId, 'ordernum' => '', 'price' => 0.0, 'payorderid' => 0, 'out_trade_no' => '', 'free' => true];
        }

        $ordernum = date('ymdHis') . random_int(100000, 999999);
        $price    = round((float) $kc['price'], 2);

        $data = [
            'aid'        => $aid,
            'mid'        => $mid,
            'bid'        => (int) ($kc['bid'] ?? 0),
            'pic'        => (string) ($kc['pic'] ?? ''),
            'createtime' => time(),
            'ordernum'   => $ordernum,
            'platform'   => 'wx',
            'title'      => (string) ($kc['name'] ?? ''),
            'kcid'       => $kcid,
            'totalprice' => $price,
            'pay_amount' => $price,
            'price'      => $price,
            'status'     => self::ORDER_UNPAID,
            'parent1'    => 0,
            'parent2'    => 0,
            'parent3'    => 0,
            'parent1commission' => 0,
            'parent2commission' => 0,
            'parent3commission' => 0,
            'parent1score' => 0,
            'parent2score' => 0,
            'parent3score' => 0,
            'iscommission' => 0,
        ];

        // 三级分销预计算（commissionset != -1）
        if (-1 !== (int) $kc['commissionset']) {
            $data = $this->calcCommission($aid, $mid, $kc, $member, $data);
        }

        $orderId = (int) Db::name('kecheng_order')->insertGetId($data);

        // 冻结佣金记录（status=0，待 CommissionSettleService 结算）
        $this->writeCommissionRecords($aid, $mid, $orderId, (int) $kc['id'], $data);

        // 统一支付单（pay_order，PayService::payBiz 复用）
        $payOrderId = $this->createPayOrder($aid, $mid, $orderId, $ordernum, (string) $kc['name'], $price);
        $payOrder   = Db::name('pay_order')->where('id', $payOrderId)->find();

        Db::name('kecheng_order')->where('id', $orderId)->update(['payorderid' => $payOrderId]);

        return [
            'orderid'      => $orderId,
            'ordernum'     => $ordernum,
            'price'        => $price,
            'payorderid'   => $payOrderId,
            'out_trade_no' => (string) ($payOrder['out_trade_no'] ?? ''),
            'free'         => false,
        ];
    }

    /**
     * 我的课程订单列表
     *
     * @return array{list: array<int, array<string, mixed>>}
     */
    public function apiOrderList(int $aid, int $mid, string $keyword, int $pagenum): array
    {
        $query = ScopeService::query('kecheng_order', $aid)
            ->where('mid', $mid)
            ->where('status', self::ORDER_PAID);

        if ('' !== $keyword) {
            $query->where('title', 'like', '%' . $keyword . '%');
        }

        $rows = $query->order('id', 'desc')
            ->page($pagenum, 10)
            ->select()
            ->toArray();

        foreach ($rows as $k => $row) {
            $rows[$k]['count'] = (int) ScopeService::query('kecheng_studylog', $aid)
                ->where('kcid', (int) $row['kcid'])
                ->where('status', 1)
                ->where('mid', $mid)
                ->group('mlid')
                ->count();
            $rows[$k]['kccount'] = (int) ScopeService::query('kecheng_chapter', $aid)
                ->where('kcid', (int) $row['kcid'])
                ->count();

            $list = ScopeService::query('kecheng_list', $aid)
                ->where('id', (int) $row['kcid'])
                ->field('isdt,orderlearn,learnhg')
                ->find();
            $rows[$k]['isdt']       = $list ? (int) $list['isdt'] : 0;
            $rows[$k]['orderlearn'] = $list ? (int) $list['orderlearn'] : 0;
            $rows[$k]['learnhg']    = $list ? (int) $list['learnhg'] : 0;

            $rows[$k]['createtime_text'] = date('Y-m-d H:i', (int) ($row['createtime'] ?: 0));
        }

        return ['list' => $rows];
    }

    /* ==================== 支付回调（PayService 分发） ==================== */

    /**
     * 课程订单支付成功入账（幂等：行锁 + status=0 条件更新）
     *
     * 动作：订单 0→1 + paytime/paytype/paytypeid/paynum；课程 join_num++
     * 分销记录保持冻结（status=0），由 CommissionSettleService 定时结算
     */
    public function onPaid(int $orderId, string $payType, string $payNum): bool
    {
        return (bool) Db::transaction(function () use ($orderId, $payType, $payNum) {
            $order = Db::name('kecheng_order')->where('id', $orderId)->lock(true)->find();

            if (empty($order) || self::ORDER_UNPAID !== (int) $order['status']) {
                return false; // 已支付：幂等不重复处理
            }

            Db::name('kecheng_order')
                ->where('id', $orderId)
                ->where('status', self::ORDER_UNPAID)
                ->update([
                    'status'    => self::ORDER_PAID,
                    'paytime'   => time(),
                    'paytype'   => $payType,
                    'paytypeid' => PayService::PAY_TYPE_IDS[$payType] ?? 0,
                    'paynum'    => $payNum,
                ]);

            Db::name('kecheng_list')
                ->where('id', (int) $order['kcid'])
                ->inc('join_num', 1)
                ->update();

            return true;
        });
    }

    /* ==================== 会员价/分销计算 ==================== */

    /**
     * 会员价格式化（对标 formatproduct）
     *
     * lvprice=1：按 lvprice_data[等级].money_price 取价；
     * 否则按 member_level.kecheng_discount 折扣（0<d<10 时 price*d*0.1）
     *
     * @param array<string, mixed>        $product
     * @param array<string, mixed>|null   $member
     * @return array<string, mixed>
     */
    public function formatProduct(int $aid, array $product, ?array $member): array
    {
        $product['price_origin'] = $product['price'];

        if (null === $member) {
            return $product;
        }

        if (1 === (int) ($product['lvprice'] ?? 0)) {
            $lvpriceData     = json_decode((string) ($product['lvprice_data'] ?: ''), true) ?: [];
            $levelid         = (string) ($member['levelid'] ?? 0);
            $product['price'] = (float) ($lvpriceData[$levelid]['money_price'] ?? $product['price']);
        } else {
            $memberlevel = Db::name('member_level')->where('aid', $aid)->where('id', (int) ($member['levelid'] ?? 0))->find();
            if (!empty($memberlevel)
                && (float) $memberlevel['kecheng_discount'] > 0
                && (float) $memberlevel['kecheng_discount'] < 10) {
                $product['market_price'] = $product['price'];
                $product['price']        = round((float) $product['price'] * (float) $memberlevel['kecheng_discount'] * 0.1, 2);
            }
        }

        return $product;
    }

    /**
     * 列表批量会员价（对标 formatprolist）
     *
     * @param array<int, array<string, mixed>> $list
     * @param array<string, mixed>|null        $member
     * @return array<int, array<string, mixed>>
     */
    public function formatProductList(int $aid, array $list, ?array $member): array
    {
        if (null === $member) {
            return $list;
        }

        $memberlevel = Db::name('member_level')->where('aid', $aid)->where('id', (int) ($member['levelid'] ?? 0))->find();

        foreach ($list as $k => $v) {
            if (1 === (int) ($v['lvprice'] ?? 0)) {
                $lvpriceData   = json_decode((string) ($v['lvprice_data'] ?: ''), true) ?: [];
                $levelid       = (string) ($member['levelid'] ?? 0);
                if (isset($lvpriceData[$levelid]['money_price'])) {
                    $list[$k]['price'] = (float) $lvpriceData[$levelid]['money_price'];
                }
            } elseif (!empty($memberlevel)
                && (float) $memberlevel['kecheng_discount'] > 0
                && (float) $memberlevel['kecheng_discount'] < 10) {
                $list[$k]['market_price'] = $v['price'];
                $list[$k]['price']        = round((float) $v['price'] * (float) $memberlevel['kecheng_discount'] * 0.1, 2);
            }
        }

        return $list;
    }

    /**
     * 三级分销预计算（对标 createOrder 分销段）
     *
     * can_agent 门槛：parent1 等级 can_agent!=0 / parent2 >1 / parent3 >2
     * commissionset：0按等级 1按商品比例(commissiondata1) 2固定金额(commissiondata2) 3积分(commissiondata3)
     * commission1own=1 时自购返佣（pid=mid）
     *
     * @param array<string, mixed> $kc
     * @param array<string, mixed> $member
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function calcCommission(int $aid, int $mid, array $kc, array $member, array $data): array
    {
        $commissionTotal = (float) $data['totalprice'];

        $aglevel = Db::name('member_level')->where('aid', $aid)->where('id', (int) ($member['levelid'] ?? 0))->find();
        if (!empty($aglevel) && (int) $aglevel['can_agent'] > 0 && 1 === (int) $aglevel['commission1own']) {
            $member['pid'] = $mid; // 自购返佣
        }

        $parent1 = $parent2 = $parent3 = null;
        $aglevel1 = $aglevel2 = $aglevel3 = null;

        if (!empty($member['pid'])) {
            $parent1 = Db::name('member')->where('aid', $aid)->where('id', (int) $member['pid'])->find();
            if ($parent1) {
                $aglevel1 = Db::name('member_level')->where('aid', $aid)->where('id', (int) $parent1['levelid'])->find();
                if ($aglevel1 && 0 !== (int) $aglevel1['can_agent']) {
                    $data['parent1'] = (int) $parent1['id'];
                }
            }
        }
        if ($parent1 && !empty($parent1['pid'])) {
            $parent2 = Db::name('member')->where('aid', $aid)->where('id', (int) $parent1['pid'])->find();
            if ($parent2) {
                $aglevel2 = Db::name('member_level')->where('aid', $aid)->where('id', (int) $parent2['levelid'])->find();
                if ($aglevel2 && (int) $aglevel2['can_agent'] > 1) {
                    $data['parent2'] = (int) $parent2['id'];
                }
            }
        }
        if ($parent2 && !empty($parent2['pid'])) {
            $parent3 = Db::name('member')->where('aid', $aid)->where('id', (int) $parent2['pid'])->find();
            if ($parent3) {
                $aglevel3 = Db::name('member_level')->where('aid', $aid)->where('id', (int) $parent3['levelid'])->find();
                if ($aglevel3 && (int) $aglevel3['can_agent'] > 2) {
                    $data['parent3'] = (int) $parent3['id'];
                }
            }
        }

        $commissionset = (int) $kc['commissionset'];

        if (1 === $commissionset) {
            // 按商品设置的分销比例
            $cd = json_decode((string) ($kc['commissiondata1'] ?: ''), true) ?: [];
            if ($cd) {
                if ($aglevel1) {
                    $data['parent1commission'] = round((float) ($cd[(string) $aglevel1['id']]['commission1'] ?? 0) * $commissionTotal * 0.01, 2);
                }
                if ($aglevel2) {
                    $data['parent2commission'] = round((float) ($cd[(string) $aglevel2['id']]['commission2'] ?? 0) * $commissionTotal * 0.01, 2);
                }
                if ($aglevel3) {
                    $data['parent3commission'] = round((float) ($cd[(string) $aglevel3['id']]['commission3'] ?? 0) * $commissionTotal * 0.01, 2);
                }
            }
        } elseif (2 === $commissionset) {
            // 按固定金额
            $cd = json_decode((string) ($kc['commissiondata2'] ?: ''), true) ?: [];
            if ($cd) {
                if ($aglevel1) {
                    $data['parent1commission'] = round((float) ($cd[(string) $aglevel1['id']]['commission1'] ?? 0), 2);
                }
                if ($aglevel2) {
                    $data['parent2commission'] = round((float) ($cd[(string) $aglevel2['id']]['commission2'] ?? 0), 2);
                }
                if ($aglevel3) {
                    $data['parent3commission'] = round((float) ($cd[(string) $aglevel3['id']]['commission3'] ?? 0), 2);
                }
            }
        } elseif (3 === $commissionset) {
            // 提成是积分
            $cd = json_decode((string) ($kc['commissiondata3'] ?: ''), true) ?: [];
            if ($cd) {
                if ($aglevel1) {
                    $data['parent1score'] = (int) ($cd[(string) $aglevel1['id']]['commission1'] ?? 0);
                }
                if ($aglevel2) {
                    $data['parent2score'] = (int) ($cd[(string) $aglevel2['id']]['commission2'] ?? 0);
                }
                if ($aglevel3) {
                    $data['parent3score'] = (int) ($cd[(string) $aglevel3['id']]['commission3'] ?? 0);
                }
            }
        } else {
            // 0：按会员等级设置的分销比例（commissiontype=1 固定金额按单）
            if ($aglevel1) {
                $data['parent1commission'] = 1 === (int) $aglevel1['commissiontype']
                    ? round((float) $aglevel1['commission1'], 2)
                    : round((float) $aglevel1['commission1'] * $commissionTotal * 0.01, 2);
            }
            if ($aglevel2) {
                $data['parent2commission'] = 1 === (int) $aglevel2['commissiontype']
                    ? round((float) $aglevel2['commission2'], 2)
                    : round((float) $aglevel2['commission2'] * $commissionTotal * 0.01, 2);
            }
            if ($aglevel3) {
                $data['parent3commission'] = 1 === (int) $aglevel3['commissiontype']
                    ? round((float) $aglevel3['commission3'], 2)
                    : round((float) $aglevel3['commission3'] * $commissionTotal * 0.01, 2);
            }
        }

        return $data;
    }

    /**
     * 写三条冻结佣金记录（member_commission_record status=0）
     *
     * @param array<string, mixed> $data 订单行（含 parentN/parentNcommission/parentNscore）
     */
    protected function writeCommissionRecords(int $aid, int $mid, int $orderId, int $kcid, array $data): void
    {
        $levels = [
            1 => '下级购买课程奖励',
            2 => '下二级购买课程奖励',
            3 => '下三级购买课程奖励',
        ];

        foreach ($levels as $lv => $remark) {
            $parentMid  = (int) ($data['parent' . $lv] ?? 0);
            $commission = (float) ($data['parent' . $lv . 'commission'] ?? 0);
            $score      = (int) ($data['parent' . $lv . 'score'] ?? 0);

            if ($parentMid > 0 && ($commission > 0 || $score > 0)) {
                Db::name('member_commission_record')->insert([
                    'aid'        => $aid,
                    'mid'        => $parentMid,
                    'frommid'    => $mid,
                    'orderid'    => $orderId,
                    'ogid'       => $kcid,
                    'type'       => 'kecheng',
                    'commission' => $commission,
                    'score'      => $score,
                    'remark'     => $remark,
                    'createtime' => time(),
                    'endtime'    => 0,
                    'status'     => 0,
                    'levelid'    => 0,
                ]);
            }
        }
    }

    /**
     * 预计佣金（详情页展示；对标 detail 佣金段）
     *
     * @param array<string, mixed>        $product
     * @param array<string, mixed>|null   $member
     */
    protected function previewCommission(int $aid, array $product, ?array $member, bool $show): float
    {
        if (!$show || null === $member) {
            return 0.0;
        }

        $userlevel = Db::name('member_level')->where('aid', $aid)->where('id', (int) ($member['levelid'] ?? 0))->find();
        if (empty($userlevel) || 0 === (int) $userlevel['can_agent']) {
            return 0.0;
        }

        $commission = 0.0;
        $priceOrigin = (float) ($product['price_origin'] ?? $product['price']);
        $commissionset = (int) ($product['commissionset'] ?? 0);

        if (1 === $commissionset) {
            $cd = json_decode((string) ($product['commissiondata1'] ?: ''), true) ?: [];
            $commission = (float) ($cd[(string) $userlevel['id']]['commission1'] ?? 0) * $priceOrigin * 0.01;
        } elseif (2 === $commissionset) {
            $cd = json_decode((string) ($product['commissiondata2'] ?: ''), true) ?: [];
            $commission = (float) ($cd[(string) $userlevel['id']]['commission1'] ?? 0);
        } elseif (3 === $commissionset) {
            $cd = json_decode((string) ($product['commissiondata3'] ?: ''), true) ?: [];
            $commission = (float) ($cd[(string) $userlevel['id']]['commission1'] ?? 0);
        } elseif (0 === $commissionset) {
            $commission = 1 === (int) ($userlevel['commissiontype'] ?? 0)
                ? (float) $userlevel['commission1']
                : (float) $userlevel['commission1'] * $priceOrigin * 0.01;
        }

        return round($commission, 2);
    }

    /* ==================== 解锁判定（顺序学习/考试合格，D2 共用） ==================== */

    /**
     * 章节是否考试合格（D2 交接点：只查 kecheng_record.ishg）
     *
     * 口径：章节无题库 → 视为合格（无需考试）；有题库 → 存在 ishg=1 记录才合格
     * D2 考试逻辑（getTiku/complete）写 kecheng_record 后本方法自然生效
     */
    public function isChapterPassed(int $aid, int $mid, int $kcid, int $mlid): bool
    {
        if (!$this->hasTikuForChapter($aid, $kcid, $mlid)) {
            return true; // 无题库：无需考试
        }

        return ScopeService::query('kecheng_record', $aid)
            ->where('kcid', $kcid)
            ->where('mlid', $mlid)
            ->where('mid', $mid)
            ->where('ishg', 1)
            ->count() > 0;
    }

    /**
     * 章节是否有题库（status=1；D2 管理题库后生效）
     */
    public function hasTikuForChapter(int $aid, int $kcid, int $mlid): bool
    {
        return ScopeService::query('kecheng_tiku', $aid)
            ->where('kcid', $kcid)
            ->where('mlid', $mlid)
            ->where('status', 1)
            ->count() > 0;
    }

    /**
     * 章节解锁校验（orderlearn 顺序学习 + learnhg 考试合格解锁；对标 mldetail 指定章节段）
     *
     * @param array<string, mixed>                            $kc
     * @param array<int|string, array<string, mixed>>         $chapters 顶级章节（sort desc/id desc）
     */
    protected function assertChapterUnlock(int $aid, int $mid, array $kc, array $chapters, int $chapterId): void
    {
        if (1 === (int) ($kc['orderlearn'] ?? 0)) {
            // 顺序学习：上一章必须学完（且考试合格）
            $preid = 0;
            foreach ($chapters as $cv) {
                if ((int) $cv['id'] === $chapterId) {
                    break;
                }
                $preid = (int) $cv['id'];
            }

            if ($preid > 0) {
                $log = ScopeService::query('kecheng_studylog', $aid)
                    ->where('mid', $mid)
                    ->where('mlid', $preid)
                    ->where('kcid', (int) $kc['id'])
                    ->where('status', 1)
                    ->order('id', 'desc')
                    ->find();

                if (empty($log)) {
                    throw new BusinessException('请按顺序从上往下学习');
                }

                if (1 === (int) ($kc['learnhg'] ?? 0) && $this->hasTikuForChapter($aid, (int) $kc['id'], $preid)) {
                    if (!$this->isChapterPassed($aid, $mid, (int) $kc['id'], $preid)) {
                        throw new BusinessException('上一章的答题未合格，请考核合格后继续学习');
                    }
                }
            }
        } elseif (1 === (int) ($kc['learnhg'] ?? 0)) {
            // 仅考试合格解锁：最近一章若有题库须合格
            $log = ScopeService::query('kecheng_studylog', $aid)
                ->where('mid', $mid)
                ->where('kcid', (int) $kc['id'])
                ->order('id', 'desc')
                ->find();

            if ($log && (int) $log['mlid'] !== $chapterId
                && $this->hasTikuForChapter($aid, (int) $kc['id'], (int) $log['mlid'])
                && !$this->isChapterPassed($aid, $mid, (int) $kc['id'], (int) $log['mlid'])) {
                throw new BusinessException('上一章的答题未合格，请考核合格后继续学习');
            }
        }
    }

    /* ==================== 内部工具 ==================== */

    /**
     * 读取会员（不存在抛 404）
     *
     * @return array<string, mixed>
     */
    protected function memberOf(int $aid, int $mid): array
    {
        $member = Db::name('member')->where('aid', $aid)->where('id', $mid)->find();
        if (empty($member)) {
            throw new BusinessException('会员不存在', 404, 404);
        }

        return $member;
    }

    /**
     * 免费课自动成单（幂等：已有 status=1 订单直接返回）
     *
     * @param array<string, mixed> $kc
     * @return array<string, mixed>
     */
    protected function ensureFreeOrder(int $aid, int $mid, array $kc): array
    {
        $order = ScopeService::query('kecheng_order', $aid)
            ->where('kcid', (int) $kc['id'])
            ->where('mid', $mid)
            ->where('status', self::ORDER_PAID)
            ->order('id', 'desc')
            ->find();

        if ($order) {
            return $order;
        }

        $orderId = $this->insertFreeOrder($aid, $mid, $kc, round((float) $kc['price'], 2));

        return ScopeService::query('kecheng_order', $aid)->where('id', $orderId)->find();
    }

    /**
     * 写免费订单（status=1）+ join_num++
     *
     * price 为下单时刻会员价（锁定口径：后续等级涨价不影响已购，对标 totalprice==0 判定）
     *
     * @param array<string, mixed> $kc
     */
    protected function insertFreeOrder(int $aid, int $mid, array $kc, float $price = 0.0): int
    {
        $ordernum = date('ymdHis') . random_int(100000, 999999);

        $orderId = (int) Db::name('kecheng_order')->insertGetId([
            'aid'        => $aid,
            'mid'        => $mid,
            'bid'        => (int) ($kc['bid'] ?? 0),
            'pic'        => (string) ($kc['pic'] ?? ''),
            'createtime' => time(),
            'ordernum'   => $ordernum,
            'platform'   => 'wx',
            'title'      => (string) ($kc['name'] ?? ''),
            'kcid'       => (int) $kc['id'],
            'totalprice' => $price,
            'pay_amount' => $price,
            'price'      => $price,
            'status'     => self::ORDER_PAID,
            'paytime'    => time(),
            'iscommission' => 0,
        ]);

        Db::name('kecheng_list')->where('aid', $aid)->where('id', (int) $kc['id'])->inc('join_num', 1)->update();

        return $orderId;
    }

    /**
     * 建档学习记录（无记录才建；图文章节直接完成）
     *
     * @param array<string, mixed> $chapter
     */
    protected function addStudyRecord(int $aid, int $mid, int $kcid, array $chapter): void
    {
        $count = ScopeService::query('kecheng_studylog', $aid)
            ->where('kcid', $kcid)
            ->where('mlid', (int) $chapter['id'])
            ->where('mid', $mid)
            ->count();

        if ($count > 0) {
            return;
        }

        $data = [
            'aid'        => $aid,
            'mid'        => $mid,
            'bid'        => (int) ($chapter['bid'] ?? 0),
            'pic'        => (string) ($chapter['pic'] ?? ''),
            'mlid'       => (int) $chapter['id'],
            'createtime' => time(),
            'platform'   => 'wx',
            'title'      => (string) ($chapter['name'] ?? ''),
            'kcid'       => $kcid,
            'status'     => 0,
            'jindu'      => '0',
            'currentTime' => 0,
        ];

        if (1 === (int) ($chapter['kctype'] ?? 0)) {
            // 图文：进入即学完
            $data['status'] = 1;
            $data['jindu']  = '100';
        }

        Db::name('kecheng_studylog')->insertGetId($data);
    }

    /**
     * 创建统一支付单（pay_order order_type='kecheng'；PayService::payBiz 复用 status=0 单）
     */
    protected function createPayOrder(int $aid, int $mid, int $orderId, string $ordernum, string $title, float $amount): int
    {
        $exists = Db::name('pay_order')
            ->where('order_type', 'kecheng')
            ->where('biz_order_id', $orderId)
            ->where('status', 0)
            ->find();

        if ($exists) {
            return (int) $exists['id'];
        }

        $outTradeNo = 'PT' . date('YmdHis') . 'K'
            . str_pad((string) ($orderId % 100000000), 8, '0', STR_PAD_LEFT);

        return (int) Db::name('pay_order')->insertGetId([
            'aid'          => $aid,
            'order_type'   => 'kecheng',
            'biz_order_id' => $orderId,
            'out_trade_no' => $outTradeNo,
            'pay_amount'   => $amount,
            'pay_type'     => '',
            'status'       => 0,
            'pay_time'     => 0,
            'createtime'   => time(),
        ]);
    }

    /**
     * 章节序列中取下一章（参考系统 getNextArrayValue 口径：按数组顺序的下一个）
     *
     * @param array<int|string, array<string, mixed>> $chapters
     * @return array<string, mixed>|null
     */
    protected function nextChapterOf(array $chapters, int $currentId): ?array
    {
        $next = false;
        foreach ($chapters as $cv) {
            if ($next) {
                return $cv;
            }
            if ((int) $cv['id'] === $currentId) {
                $next = true;
            }
        }

        return null;
    }

    /**
     * 秒数 → 时分秒显示
     */
    protected function formatDuration(int $seconds): string
    {
        if ($seconds > 3600) {
            $hours = intdiv($seconds, 3600);
            $min   = intdiv($seconds % 3600, 60);
            $sec   = $seconds % 60;

            return sprintf('%d:%02d:%02d', $hours, $min, $sec);
        }

        return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }
}
