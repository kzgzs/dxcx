<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;

/**
 * 优惠券租户后台管理服务（P13-1 客户端营销补全）
 *
 * 表口径（复用基线 coupon / coupon_record + 补建 coupon_set）：
 * - coupon        type(1代金 2礼品 3计次 4运费抵 10折扣 11兑换)/money/discount/minprice/
 *                 stock(库存)/getnum(已领)/used(已用)/perlimit(每人限领)/
 *                 yxqtype(1定范围 2领后N天)/yxqdate(天数)/yxqtime/
 *                 starttime,endtime(varchar 'Y-m-d H:i:s')/gettj(人群)/isgive(转赠)/
 *                 tolist(主动领)/fwtype(0全场 1类目 2商品)/categoryids/productids/
 *                 price,score(付费购)/status(1启用 0停用)/deleted(软删)
 * - coupon_record 领取记录：status(0未使用 1已使用)，过期按 endtime 查询时实时计算（不落库）
 * - coupon_set    过期提醒设置：expire_status / expire_rules（逗号分隔天数）
 *
 * 发放口径：批量发券整体事务 + 券行锁 + 条件更新 getnum<stock 防超发
 */
class CouponManageService
{
    /** 券类型（对齐参考系统 getCouponTypeTxt 口径） */
    public const TYPE_MAP = [
        1  => '代金券',
        2  => '礼品券',
        3  => '计次券',
        4  => '运费抵扣券',
        10 => '折扣券',
        11 => '兑换券',
    ];

    /** 有效期类型 */
    protected const YXQ_RANGE = 1; // 定时间范围
    protected const YXQ_DAYS  = 2; // 领取后N天

    /** getnum>0 后禁止修改的核心面值字段 */
    protected const CORE_COLUMNS = ['name', 'type', 'money', 'discount', 'minprice'];

    /* ================= 券管理 ================= */

    /**
     * 券列表（type/status 空=全部；keyword 模糊匹配名称）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function manageList(int $aid, string $type, string $status, string $keyword, int $page, int $pageSize): array
    {
        $query = ScopeService::query('coupon', $aid)->where('deleted', 0);

        if ('' !== $type && 'all' !== $type) {
            $query->where('type', (int) $type);
        }

        if ('' !== $status) {
            $query->where('status', (int) $status);
        }

        if ('' !== $keyword) {
            $query->where('name', 'like', '%' . $keyword . '%');
        }

        $total = (clone $query)->count();

        $rows = $query->order('id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        return [
            'list'     => array_map(fn (array $row) => $this->formatCoupon($row), $rows),
            'total'    => $total,
            'page'     => $page,
            'pagesize' => $pageSize,
        ];
    }

    /**
     * 券详情（编辑回显用，完整字段）
     *
     * @return array<string, mixed>
     */
    public function detail(int $aid, int $id): array
    {
        return $this->formatCoupon($this->findCoupon($aid, $id));
    }

    /**
     * 新增/编辑优惠券（id>0 编辑；getnum>0 锁核心面值字段）
     *
     * @param array<string, mixed> $data
     * @return array{id: int}
     */
    public function save(int $aid, array $data): array
    {
        $id = (int) ($data['id'] ?? 0);

        if ($id > 0) {
            return ['id' => $this->update($aid, $id, $data)];
        }

        $insert = $this->buildData($aid, $data, null);

        $insert['aid']        = $aid;
        $insert['bid']        = 0;
        $insert['getnum']     = 0;
        $insert['used']       = 0;
        $insert['status']     = $insert['status'] ?? 1;
        $insert['deleted']    = 0;
        $insert['createtime'] = time();

        return ['id' => (int) Db::name('coupon')->insertGetId($insert)];
    }

    /**
     * 启用/停用
     */
    public function status(int $aid, int $id, int $status): void
    {
        if (!in_array($status, [0, 1], true)) {
            throw new BusinessException('状态值无效');
        }

        $coupon = $this->findCoupon($aid, $id);

        Db::name('coupon')->where('id', (int) $coupon['id'])->update(['status' => $status]);
    }

    /**
     * 软删除（getnum>0 拒绝）
     */
    public function delete(int $aid, int $id): void
    {
        $coupon = $this->findCoupon($aid, $id);

        if ((int) ($coupon['getnum'] ?? 0) > 0) {
            throw new BusinessException('该优惠券已被领取，不能删除');
        }

        Db::name('coupon')->where('id', (int) $coupon['id'])->update(['deleted' => 1]);
    }

    /**
     * 复制优惠券（名称加「复制-」前缀，领取/使用数清零）
     *
     * @return array{id: int}
     */
    public function copy(int $aid, int $id): array
    {
        $coupon = $this->findCoupon($aid, $id);

        unset($coupon['id']);

        $coupon['name']       = '复制-' . (string) ($coupon['name'] ?? '');
        $coupon['getnum']     = 0;
        $coupon['used']       = 0;
        $coupon['createtime'] = time();

        return ['id' => (int) Db::name('coupon')->insertGetId($coupon)];
    }

    /* ================= 领取记录 ================= */

    /**
     * 领取记录（status 空=全部 0未使用 1已使用 2已过期；过期按 endtime 查询时实时计算不落库）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function records(int $aid, int $couponId, int $mid, string $status, string $keyword, int $startTime, int $endTime, int $page, int $pageSize): array
    {
        $now   = time();
        $query = ScopeService::queryAs('coupon_record', $aid, 'r')
            ->leftJoin('member m', 'm.id = r.mid')
            ->field('r.*, m.nickname, m.headimg, m.tel as member_phone');

        if ($couponId > 0) {
            $query->where('r.couponid', $couponId);
        }

        if ($mid > 0) {
            $query->where('r.mid', $mid);
        }

        if ('' !== $keyword) {
            $query->where(function ($q) use ($keyword) {
                $q->where('m.nickname', 'like', '%' . $keyword . '%')
                    ->whereOr('m.tel', 'like', '%' . $keyword . '%');
            });
        }

        if ($startTime > 0) {
            $query->where('r.createtime', '>=', $startTime);
        }

        if ($endTime > 0) {
            $query->where('r.createtime', '<', $endTime);
        }

        // 状态筛选（0 未使用需排除已过期；2 过期=status0 且 endtime 已过，实时计算）
        if ('1' === $status) {
            $query->where('r.status', 1);
        } elseif ('0' === $status) {
            $query->where('r.status', 0)
                ->where(function ($q) use ($now) {
                    $q->where('r.endtime', 0)->whereOr('r.endtime', '>=', $now);
                });
        } elseif ('2' === $status) {
            $query->where('r.status', 0)
                ->where('r.endtime', '>', 0)
                ->where('r.endtime', '<', $now);
        }

        $total = (clone $query)->count();

        $rows = $query->order('r.id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        return [
            'list'     => array_map(fn (array $row) => $this->formatRecord($row, $now), $rows),
            'total'    => $total,
            'page'     => $page,
            'pagesize' => $pageSize,
        ];
    }

    /* ================= 批量发放 ================= */

    /**
     * 批量发放优惠券（整体事务 + 券行锁 + 条件更新 getnum<stock 防超发）
     *
     * @param int[] $mids 指定会员（sendtype=0）
     * @param array{nickname?: string, levelid?: int, ctime_start?: int, ctime_end?: int} $condition 会员条件（sendtype=1）
     * @return array{sucnum: int, errnum: int}
     */
    public function send(int $aid, int $couponId, int $sendType, array $mids, array $condition, int $perNum): array
    {
        if ($couponId <= 0) {
            throw new BusinessException('缺少优惠券ID');
        }

        if ($perNum <= 0 || $perNum > 100) {
            throw new BusinessException('每人发放数量须在 1~100 之间');
        }

        return Db::transaction(function () use ($aid, $couponId, $sendType, $mids, $condition, $perNum) {
            // 行锁抢占券行，防并发发放超发
            $coupon = Db::name('coupon')
                ->where('id', $couponId)
                ->where('aid', $aid)
                ->where('deleted', 0)
                ->lock(true)
                ->find();

            if (empty($coupon)) {
                throw new BusinessException('优惠券不存在', 404, 404);
            }

            if (1 !== (int) ($coupon['status'] ?? 0)) {
                throw new BusinessException('优惠券已停用，不能发放');
            }

            $members = $this->resolveMembers($aid, $sendType, $mids, $condition);

            if (empty($members)) {
                throw new BusinessException('没有符合发放条件的会员');
            }

            // 库存预校验：需求总量不得超过剩余库存
            $need      = count($members) * $perNum;
            $available = (int) ($coupon['stock'] ?? 0) - (int) ($coupon['getnum'] ?? 0);

            if ($need > $available) {
                throw new BusinessException('库存不足：需发放 ' . $need . ' 张，剩余 ' . max($available, 0) . ' 张');
            }

            $perLimit = (int) ($coupon['perlimit'] ?? 1);
            $sucnum   = 0;
            $errnum   = 0;

            foreach ($members as $member) {
                $mid = (int) $member['id'];

                // 每人限领校验（含历史已领）
                $mine = (int) Db::name('coupon_record')
                    ->where('aid', $aid)
                    ->where('mid', $mid)
                    ->where('couponid', $couponId)
                    ->count();

                if ($mine >= $perLimit) {
                    $errnum += $perNum;
                    continue;
                }

                for ($i = 0; $i < $perNum; $i++) {
                    if ($mine + $i >= $perLimit) {
                        $errnum += $perNum - $i;
                        break;
                    }

                    // 条件更新防超发：并发下只有 getnum<stock 才能扣量成功
                    $affected = Db::name('coupon')
                        ->where('id', $couponId)
                        ->whereRaw('getnum < stock')
                        ->inc('getnum', 1)
                        ->update();

                    if (1 !== $affected) {
                        $errnum += $perNum - $i;
                        break;
                    }

                    $this->insertRecord($aid, $mid, $coupon, '后台发放');
                    $sucnum++;
                }
            }

            if (0 === $sucnum) {
                throw new BusinessException('发放失败：会员均已达限领数量');
            }

            return ['sucnum' => $sucnum, 'errnum' => $errnum];
        });
    }

    /* ================= 手工核销 ================= */

    /**
     * 领取记录状态变更（手工核销/反核销；计次券、券包禁止手工核销）
     *
     * @param int[] $ids
     */
    public function recordStatus(int $aid, array $ids, int $st): void
    {
        if (empty($ids)) {
            throw new BusinessException('缺少记录ID');
        }

        if (!in_array($st, [0, 1], true)) {
            throw new BusinessException('状态值无效');
        }

        Db::transaction(function () use ($aid, $ids, $st) {
            foreach ($ids as $id) {
                $record = Db::name('coupon_record')
                    ->where('id', (int) $id)
                    ->where('aid', $aid)
                    ->lock(true)
                    ->find();

                if (empty($record)) {
                    throw new BusinessException('领取记录不存在：' . $id, 404, 404);
                }

                $type = (int) ($record['type'] ?? 1);

                if (3 === $type) {
                    throw new BusinessException('计次券不能手工修改状态');
                }

                if (20 === $type) {
                    throw new BusinessException('券包不能手工修改状态');
                }

                if ((int) $record['status'] === $st) {
                    continue; // 幂等：已是目标状态
                }

                // 过期记录禁止核销
                if (1 === $st) {
                    $end = (int) ($record['endtime'] ?? 0);

                    if ($end > 0 && time() > $end) {
                        throw new BusinessException('优惠券已过期，不能核销：' . $id);
                    }
                }

                Db::name('coupon_record')->where('id', (int) $record['id'])->update([
                    'status'  => $st,
                    'usetime' => 1 === $st ? time() : 0,
                ]);

                // 同步券已使用计数
                $couponId = (int) ($record['couponid'] ?? 0);

                if ($couponId > 0) {
                    if (1 === $st) {
                        Db::name('coupon')->where('id', $couponId)->inc('used', 1)->update();
                    } else {
                        Db::name('coupon')->where('id', $couponId)->where('used', '>', 0)->dec('used', 1)->update();
                    }
                }
            }
        });
    }

    /* ================= 过期提醒设置（coupon_set） ================= */

    /**
     * 读取过期提醒设置
     *
     * @return array{expire_status: int, expire_rules: int[]}
     */
    public function getSet(int $aid): array
    {
        $row = ScopeService::query('coupon_set', $aid)->where('bid', 0)->find();

        return [
            'expire_status' => (int) ($row['expire_status'] ?? 0),
            'expire_rules'  => $this->csvToInts((string) ($row['expire_rules'] ?? '')),
        ];
    }

    /**
     * 保存过期提醒设置（按 aid+bid=0 upsert）
     *
     * @param array<string, mixed> $data
     */
    public function saveSet(int $aid, array $data): void
    {
        $status = (int) ($data['expire_status'] ?? 0);

        if (!in_array($status, [0, 1], true)) {
            throw new BusinessException('过期提醒开关值无效');
        }

        $rules = $data['expire_rules'] ?? [];

        if (is_string($rules)) {
            $rules = $this->csvToInts($rules);
        }

        if (!is_array($rules)) {
            throw new BusinessException('过期提醒规则格式无效');
        }

        $rules = array_values(array_unique(array_filter(array_map('intval', $rules), static fn (int $v) => $v > 0 && $v <= 30)));

        $update = [
            'expire_status' => $status,
            'expire_rules'  => implode(',', $rules),
        ];

        $exists = ScopeService::query('coupon_set', $aid)->where('bid', 0)->find();

        if ($exists) {
            Db::name('coupon_set')->where('id', (int) $exists['id'])->update($update);
        } else {
            $update['aid']        = $aid;
            $update['bid']        = 0;
            $update['createtime'] = time();

            Db::name('coupon_set')->insert($update);
        }
    }

    /* ================= 内部工具 ================= */

    /**
     * 编辑更新（getnum>0 时核心面值字段 name/type/money/discount/minprice 禁止修改）
     *
     * @param array<string, mixed> $data
     */
    protected function update(int $aid, int $id, array $data): int
    {
        $coupon = $this->findCoupon($aid, $id);

        $coreLocked = (int) ($coupon['getnum'] ?? 0) > 0;

        foreach (self::CORE_COLUMNS as $column) {
            if (!array_key_exists($column, $data)) {
                continue;
            }

            $current = $coupon[$column] ?? null;
            $changed = 'name' === $column
                ? trim((string) $data[$column]) !== (string) $current
                : abs((float) $data[$column] - (float) $current) > 0.0001;

            if ($changed && $coreLocked) {
                throw new BusinessException('该优惠券已被领取，禁止修改核心面值字段（名称/类型/金额/折扣/门槛）');
            }
        }

        $merged = array_merge($coupon, $data);
        $update = $this->buildData($aid, $merged, $coupon);

        unset($update['aid'], $update['bid'], $update['getnum'], $update['used'], $update['createtime']);

        if (empty($update)) {
            return $id;
        }

        Db::name('coupon')->where('id', $id)->update($update);

        return $id;
    }

    /**
     * 构建落库数据（新增/编辑共用校验）
     *
     * @param array<string, mixed> $data   提交数据（编辑时为合并后全量）
     * @param array<string, mixed>|null $old 原行（编辑时非空）
     * @return array<string, mixed>
     */
    protected function buildData(int $aid, array $data, ?array $old): array
    {
        $name = trim((string) ($data['name'] ?? ''));

        if ('' === $name) {
            throw new BusinessException('请填写优惠券名称');
        }

        $type = (int) ($data['type'] ?? 0);

        if (!isset(self::TYPE_MAP[$type])) {
            throw new BusinessException('优惠券类型无效');
        }

        $money    = $this->parseAmount($data['money'] ?? 0, '抵扣金额');
        $discount = (float) ($data['discount'] ?? 0);
        $minprice = $this->parseAmount($data['minprice'] ?? 0, '最低消费金额');

        // 面值分支校验
        if (1 === $type || 4 === $type) {
            if ($money <= 0) {
                throw new BusinessException((4 === $type ? '运费抵扣券' : '代金券') . '抵扣金额须大于0');
            }
            $discount = 0.0;
        } elseif (10 === $type) {
            if ($discount < 1 || $discount > 9.9) {
                throw new BusinessException('折扣券折扣值须在 1~9.9 之间');
            }
            $money = 0.0;
        } else {
            $discount = 0.0; // 礼品/计次/兑换券不涉折扣
        }

        $stock = (int) ($data['stock'] ?? 0);

        if ($stock <= 0) {
            throw new BusinessException('库存（发行总量）必须大于0');
        }

        // 编辑时不允许把库存降到已领数以下
        if (null !== $old && $stock < (int) ($old['getnum'] ?? 0)) {
            throw new BusinessException('库存不能小于已领取数量');
        }

        $perlimit = (int) ($data['perlimit'] ?? 1);

        if ($perlimit <= 0) {
            throw new BusinessException('每人限领数量必须大于0');
        }

        // 有效期
        $yxqtype = (int) ($data['yxqtype'] ?? self::YXQ_RANGE);

        if (!in_array($yxqtype, [self::YXQ_RANGE, self::YXQ_DAYS], true)) {
            throw new BusinessException('有效期类型无效');
        }

        $yxqdate = (int) ($data['yxqdate'] ?? 0);
        $yxqtime = trim((string) ($data['yxqtime'] ?? ''));

        $startStamp = $this->stampOf($data['starttime'] ?? '');
        $endStamp   = $this->stampOf($data['endtime'] ?? '');

        if (self::YXQ_DAYS === $yxqtype) {
            if ($yxqdate <= 0) {
                throw new BusinessException('请填写领取后有效天数');
            }
            // 领取窗口可选；填了须合法
            if ($startStamp > 0 && $endStamp > 0 && $endStamp <= $startStamp) {
                throw new BusinessException('领取结束时间必须晚于开始时间');
            }
        } else {
            if ($startStamp <= 0 || $endStamp <= 0) {
                throw new BusinessException('请填写完整的有效期起止时间');
            }

            if ($endStamp <= $startStamp) {
                throw new BusinessException('有效期结束时间必须晚于开始时间');
            }
        }

        // 适用人群/范围
        $gettj = $this->normalizeCsv($data['gettj'] ?? '-1');
        $fwtype = (int) ($data['fwtype'] ?? 0);

        if (!in_array($fwtype, [0, 1, 2], true)) {
            throw new BusinessException('适用范围类型无效');
        }

        $categoryids = 1 === $fwtype ? $this->normalizeCsv($data['categoryids'] ?? '') : '';
        $productids  = 2 === $fwtype ? $this->normalizeCsv($data['productids'] ?? '') : '';

        if (1 === $fwtype && '' === $categoryids) {
            throw new BusinessException('请选择指定类目');
        }

        if (2 === $fwtype && '' === $productids) {
            throw new BusinessException('请选择指定商品');
        }

        // 付费购（price/score 均为 0 表示免费领取）
        $price = $this->parseAmount($data['price'] ?? 0, '购买金额');
        $score = (int) ($data['score'] ?? 0);

        if ($score < 0) {
            throw new BusinessException('所需积分不能为负');
        }

        $isgive = (int) ($data['isgive'] ?? 0);
        $tolist = (int) ($data['tolist'] ?? 1);

        if (!in_array($isgive, [0, 1], true) || !in_array($tolist, [0, 1], true)) {
            throw new BusinessException('转赠/领取开关值无效');
        }

        $status = isset($data['status']) ? (int) $data['status'] : (null !== $old ? (int) ($old['status'] ?? 1) : 1);

        if (!in_array($status, [0, 1], true)) {
            throw new BusinessException('状态值无效');
        }

        $build = [
            'name'        => $name,
            'type'        => $type,
            'money'       => $money,
            'discount'    => $discount,
            'minprice'    => $minprice,
            'stock'       => $stock,
            'perlimit'    => $perlimit,
            'yxqtype'     => $yxqtype,
            'yxqdate'     => self::YXQ_DAYS === $yxqtype ? $yxqdate : 0,
            'yxqtime'     => $yxqtime,
            'starttime'   => $startStamp > 0 ? date('Y-m-d H:i:s', $startStamp) : '',
            'endtime'     => $endStamp > 0 ? date('Y-m-d H:i:s', $endStamp) : '',
            'gettj'       => $gettj,
            'isgive'      => $isgive,
            'tolist'      => $tolist,
            'fwtype'      => $fwtype,
            'categoryids' => $categoryids,
            'productids'  => $productids,
            'price'       => $price,
            'score'       => $score,
            'status'      => $status,
        ];

        // 计次券次数
        if (3 === $type) {
            $limitCount = (int) ($data['limit_count'] ?? (null !== $old ? ($old['limit_count'] ?? 10) : 10));

            if ($limitCount <= 0) {
                throw new BusinessException('计次券可用次数须大于0');
            }

            $build['limit_count'] = $limitCount;
        }

        // 说明文案（可选）
        foreach (['gettips', 'usetips'] as $key) {
            if (array_key_exists($key, $data)) {
                $build[$key] = trim((string) $data[$key]);
            }
        }

        if (array_key_exists('sort', $data)) {
            $build['sort'] = (int) $data['sort'];
        }

        return $build;
    }

    /**
     * 解析发放目标会员
     *
     * @param int[] $mids
     * @param array<string, mixed> $condition
     * @return array<int, array<string, mixed>>
     */
    protected function resolveMembers(int $aid, int $sendType, array $mids, array $condition): array
    {
        if (0 === $sendType) {
            $mids = array_values(array_unique(array_filter(array_map('intval', $mids), static fn (int $v) => $v > 0)));

            if (empty($mids)) {
                throw new BusinessException('请选择要发放的会员');
            }

            if (count($mids) > 1000) {
                throw new BusinessException('单次最多发放给 1000 名会员');
            }

            return ScopeService::query('member', $aid)
                ->whereIn('id', $mids)
                ->field('id')
                ->select()
                ->toArray();
        }

        if (1 === $sendType) {
            $query = ScopeService::query('member', $aid)->field('id');

            $nickname = trim((string) ($condition['nickname'] ?? ''));

            if ('' !== $nickname) {
                $query->where('nickname', 'like', '%' . $nickname . '%');
            }

            $levelid = (int) ($condition['levelid'] ?? 0);

            if ($levelid > 0) {
                $query->where('levelid', $levelid);
            }

            $ctimeStart = (int) ($condition['ctime_start'] ?? 0);
            $ctimeEnd   = (int) ($condition['ctime_end'] ?? 0);

            if ($ctimeStart > 0) {
                $query->where('createtime', '>=', $ctimeStart);
            }

            if ($ctimeEnd > 0) {
                $query->where('createtime', '<', $ctimeEnd);
            }

            return $query->limit(1000)->select()->toArray();
        }

        throw new BusinessException('发放方式参数无效');
    }

    /**
     * 写入领取记录（快照券面值；yxqtype=2 时按领取后N天计算到期）
     *
     * @param array<string, mixed> $coupon
     */
    protected function insertRecord(int $aid, int $mid, array $coupon, string $remark): int
    {
        $now   = time();
        $start = $this->stampOf($coupon['starttime'] ?? '');
        $end   = $this->stampOf($coupon['endtime'] ?? '');

        if (self::YXQ_DAYS === (int) ($coupon['yxqtype'] ?? self::YXQ_RANGE) && (int) ($coupon['yxqdate'] ?? 0) > 0) {
            $start = $now;
            $end   = $now + (int) $coupon['yxqdate'] * 86400;
        }

        return (int) Db::name('coupon_record')->insertGetId([
            'aid'          => $aid,
            'bid'          => 0,
            'mid'          => $mid,
            'couponid'     => (int) $coupon['id'],
            'couponname'   => (string) ($coupon['name'] ?? ''),
            'money'        => (float) ($coupon['money'] ?? 0),
            'discount'     => (float) ($coupon['discount'] ?? 0),
            'minprice'     => (float) ($coupon['minprice'] ?? 0),
            'type'         => (int) ($coupon['type'] ?? 1),
            'limit_count'  => (int) ($coupon['limit_count'] ?? 1),
            'starttime'    => $start,
            'endtime'      => $end,
            'status'       => 0,
            'used_count'   => 0,
            'remark'       => $remark,
            'from_mid'     => 0,
            'receive_time' => $now,
            'usetime'      => 0,
            'createtime'   => $now,
        ]);
    }

    /**
     * 查找本租户未删除的券（不存在抛 404）
     *
     * @return array<string, mixed>
     */
    protected function findCoupon(int $aid, int $id): array
    {
        if ($id <= 0) {
            throw new BusinessException('缺少优惠券ID');
        }

        $coupon = ScopeService::query('coupon', $aid)
            ->where('id', $id)
            ->where('deleted', 0)
            ->find();

        if (empty($coupon)) {
            throw new BusinessException('优惠券不存在', 404, 404);
        }

        return $coupon;
    }

    /**
     * 金额解析（两位小数校验）
     */
    protected function parseAmount(mixed $value, string $label): float
    {
        if (null === $value || '' === $value) {
            return 0.0;
        }

        $str = (string) $value;

        if (!preg_match('/^\d+(\.\d{1,2})?$/', $str)) {
            throw new BusinessException($label . '格式无效（最多两位小数）');
        }

        return (float) $str;
    }

    /**
     * 逗号分隔数字串归一化（去空去重；'-1' 原样保留表示不限制）
     */
    protected function normalizeCsv(mixed $value): string
    {
        if (is_array($value)) {
            $parts = $value;
        } else {
            $parts = explode(',', (string) $value);
        }

        $parts = array_filter(array_map(static fn ($v) => trim((string) $v), $parts), static fn ($v) => '' !== $v);

        if (empty($parts)) {
            return '';
        }

        if (in_array('-1', $parts, true)) {
            return '-1';
        }

        return implode(',', array_unique($parts));
    }

    /**
     * 逗号分隔字符串转 int 数组
     *
     * @return int[]
     */
    protected function csvToInts(string $value): array
    {
        if ('' === trim($value)) {
            return [];
        }

        return array_values(array_filter(array_map('intval', explode(',', $value)), static fn (int $v) => $v > 0));
    }

    /**
     * varchar 时间列/时间戳 → Unix 秒（数字串直接取用，其余 strtotime）
     */
    protected function stampOf(mixed $value): int
    {
        if (is_int($value)) {
            return max($value, 0);
        }

        $str = trim((string) $value);

        if ('' === $str) {
            return 0;
        }

        if (ctype_digit($str)) {
            return (int) $str;
        }

        return strtotime($str) ?: 0;
    }

    /**
     * 券行格式化（列表/详情共用）
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function formatCoupon(array $row): array
    {
        $now       = time();
        $start     = $this->stampOf($row['starttime'] ?? '');
        $end       = $this->stampOf($row['endtime'] ?? '');
        $yxqtype   = (int) ($row['yxqtype'] ?? self::YXQ_RANGE);
        $type      = (int) ($row['type'] ?? 1);
        $phase     = 'ongoing';

        if (self::YXQ_RANGE === $yxqtype) {
            if ($start > 0 && $now < $start) {
                $phase = 'not_started';
            } elseif ($end > 0 && $now > $end) {
                $phase = 'ended';
            }
        }

        return [
            'id'          => (int) ($row['id'] ?? 0),
            'name'        => (string) ($row['name'] ?? ''),
            'type'        => $type,
            'type_txt'    => self::TYPE_MAP[$type] ?? (string) $type,
            'money'       => (float) ($row['money'] ?? 0),
            'discount'    => (float) ($row['discount'] ?? 0),
            'minprice'    => (float) ($row['minprice'] ?? 0),
            'stock'       => (int) ($row['stock'] ?? 0),
            'getnum'      => (int) ($row['getnum'] ?? 0),
            'used'        => (int) ($row['used'] ?? 0),
            'perlimit'    => (int) ($row['perlimit'] ?? 1),
            'yxqtype'     => $yxqtype,
            'yxqdate'     => (int) ($row['yxqdate'] ?? 0),
            'yxqtime'     => (string) ($row['yxqtime'] ?? ''),
            'starttime'   => (string) ($row['starttime'] ?? ''),
            'endtime'     => (string) ($row['endtime'] ?? ''),
            'start_stamp' => $start,
            'end_stamp'   => $end,
            'phase'       => $phase,
            'gettj'       => (string) ($row['gettj'] ?? '-1'),
            'isgive'      => (int) ($row['isgive'] ?? 0),
            'tolist'      => (int) ($row['tolist'] ?? 1),
            'fwtype'      => (int) ($row['fwtype'] ?? 0),
            'categoryids' => (string) ($row['categoryids'] ?? ''),
            'productids'  => (string) ($row['productids'] ?? ''),
            'price'       => (float) ($row['price'] ?? 0),
            'score'       => (int) ($row['score'] ?? 0),
            'limit_count' => (int) ($row['limit_count'] ?? 1),
            'gettips'     => (string) ($row['gettips'] ?? ''),
            'usetips'     => (string) ($row['usetips'] ?? ''),
            'sort'        => (int) ($row['sort'] ?? 0),
            'status'      => (int) ($row['status'] ?? 1),
            'create_time' => (int) ($row['createtime'] ?? 0),
        ];
    }

    /**
     * 记录行格式化（过期实时计算为 2，不落库）
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function formatRecord(array $row, int $now): array
    {
        $status = (int) ($row['status'] ?? 0);

        if (0 === $status) {
            $end    = (int) ($row['endtime'] ?? 0);
            $status = ($end > 0 && $now > $end) ? 2 : 0;
        }

        $type = (int) ($row['type'] ?? 1);

        return [
            'id'           => (int) ($row['id'] ?? 0),
            'mid'          => (int) ($row['mid'] ?? 0),
            'nickname'     => (string) ($row['nickname'] ?? ''),
            'headimg'      => (string) ($row['headimg'] ?? ''),
            'member_phone' => (string) ($row['member_phone'] ?? ''),
            'couponid'     => (int) ($row['couponid'] ?? 0),
            'couponname'   => (string) ($row['couponname'] ?? ''),
            'money'        => (float) ($row['money'] ?? 0),
            'discount'     => (float) ($row['discount'] ?? 0),
            'minprice'     => (float) ($row['minprice'] ?? 0),
            'type'         => $type,
            'type_txt'     => self::TYPE_MAP[$type] ?? (string) $type,
            'status'       => $status,
            'starttime'    => (int) ($row['starttime'] ?? 0),
            'endtime'      => (int) ($row['endtime'] ?? 0),
            'receive_time' => (int) ($row['receive_time'] ?? ($row['createtime'] ?? 0)),
            'use_time'     => (int) ($row['usetime'] ?? 0),
            'order_id'     => (int) ($row['order_id'] ?? 0),
            'remark'       => (string) ($row['remark'] ?? ''),
            'from_mid'     => (int) ($row['from_mid'] ?? 0),
        ];
    }
}
