<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;

/**
 * 抽奖活动服务（大转盘 dzp / 刮刮卡 ggk，admin 后台 + 客户端抽奖共用）
 *
 * 表：choujiang（活动，奖项 j0-j9 扁平字段 jNmc/jNpic/jNtp/jNsl/jNyj）、
 *     choujiang_record（抽奖记录：jx 奖项 0未中奖 / jxtp 1实物2红包 / status 0未领奖1已领）、
 *     choujiang_sharelog（分享加次数日志）
 *
 * 奖项口径（对照参考系统）：
 * - j0 固定为「不中奖」槽位，j1-j9 为真实奖项
 * - 中奖概率由数量推导：gl = 数量 / 全部奖项数量合计 * 100（数量即权重）
 * - 抽奖按「剩余数量 = jNsl - jNyj」加权随机；数量耗尽的奖项自动出局
 *
 * 防超发口径（区别于旧系统「先查后扣」）：
 * - 中奖扣减一律条件更新 where jNsl > jNyj，affected>0 才算扣成功
 * - 并发下只有一个请求能把该奖项 +1，抢不到即降级为不中奖
 * - 领奖核销 status 0→1 同样条件更新，天然幂等
 */
class LotteryService
{
    /** 活动类型 */
    public const TYPE_DZP = 'dzp'; // 大转盘
    public const TYPE_GGK = 'ggk'; // 刮刮卡

    /** 奖项槽位：j0 不中奖，j1-j9 真实奖项 */
    public const PRIZE_NONE = 0;
    public const PRIZE_MAX  = 9;

    /** 记录领奖状态 */
    public const RECORD_UNRECEIVED = 0; // 未领奖
    public const RECORD_RECEIVED   = 1; // 已领奖

    /** 活动状态 */
    public const STATUS_OFF = 0;
    public const STATUS_ON  = 1;

    /* ================= 活动管理 ================= */

    /**
     * 活动分页列表（type/status/名称/阶段 筛选）
     *
     * @param array $filters {type?, status?, phase?, name?}
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function actList(int $aid, int $page, int $pagesize, array $filters): array
    {
        $query = ScopeService::query('choujiang', $aid);

        $type = trim((string) ($filters['type'] ?? ''));
        if ('' !== $type && in_array($type, [self::TYPE_DZP, self::TYPE_GGK], true)) {
            $query->where('type', $type);
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if ('' !== $status) {
            $query->where('status', (int) $status ? 1 : 0);
        }

        // 阶段筛选：未开始 / 进行中 / 已结束
        $now   = time();
        $phase = trim((string) ($filters['phase'] ?? ''));
        if ('notstart' === $phase) {
            $query->where('starttime', '>', $now);
        } elseif ('ongoing' === $phase) {
            $query->where('starttime', '<=', $now)->where('endtime', '>=', $now);
        } elseif ('ended' === $phase) {
            $query->where('endtime', '<', $now);
        }

        $name = trim((string) ($filters['name'] ?? ''));
        if ('' !== $name) {
            $query->whereLike('name', '%' . $name . '%');
        }

        $total = (clone $query)->count();

        $rows = $query->order('id', 'desc')->page($page, $pagesize)->select()->toArray();

        $list = array_map(fn (array $row): array => $this->formatAct($row, false), $rows);

        return ['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pagesize];
    }

    /**
     * 活动详情（编辑回显：含奖项数组与推导概率）
     *
     * @return array<string, mixed>
     */
    public function detail(int $aid, int $id): array
    {
        return $this->formatAct($this->findAct($aid, $id), true);
    }

    /**
     * 新增/编辑活动
     *
     * @param array $data {id?, type, name, banner?, starttime, endtime, guize?, pertotal, perday,
     *                    shareaddnum, sharedaytimes, sharetimes, sharetitle?, sharelink?, sharepic?, sharedesc?,
     *                    status?, prizes:[{mc, pic?, tp?, sl}]}
     * @return array{id: int}
     */
    public function save(int $aid, array $data): array
    {
        $id   = (int) ($data['id'] ?? 0);
        $type = trim((string) ($data['type'] ?? ''));
        if (!in_array($type, [self::TYPE_DZP, self::TYPE_GGK], true)) {
            throw new BusinessException('活动类型错误，仅支持大转盘或刮刮卡');
        }

        $name = trim((string) ($data['name'] ?? ''));
        if ('' === $name) {
            throw new BusinessException('请填写活动名称');
        }

        $starttime = $this->toTime($data['starttime'] ?? 0);
        $endtime   = $this->toTime($data['endtime'] ?? 0);
        if ($starttime <= 0 || $endtime <= 0) {
            throw new BusinessException('请填写完整的起止时间');
        }
        if ($endtime <= $starttime) {
            throw new BusinessException('结束时间必须晚于开始时间');
        }

        $pertotal      = max(0, (int) ($data['pertotal'] ?? 0));
        $perday        = max(0, (int) ($data['perday'] ?? 0));
        $shareaddnum   = max(0, (int) ($data['shareaddnum'] ?? 0));
        $sharedaytimes = max(0, (int) ($data['sharedaytimes'] ?? 0));
        $sharetimes    = max(0, (int) ($data['sharetimes'] ?? 0));

        $prizes = $this->normalizePrizes($data['prizes'] ?? [], $aid, $id);

        $info = array_merge([
            'type'          => $type,
            'name'          => $name,
            'banner'        => trim((string) ($data['banner'] ?? '')),
            'starttime'     => $starttime,
            'endtime'       => $endtime,
            'guize'         => (string) ($data['guize'] ?? ''),
            'pertotal'      => $pertotal,
            'perday'        => $perday,
            'shareaddnum'   => $shareaddnum,
            'sharedaytimes' => $sharedaytimes,
            'sharetimes'    => $sharetimes,
            'sharetitle'    => trim((string) ($data['sharetitle'] ?? '')),
            'sharelink'     => trim((string) ($data['sharelink'] ?? '')),
            'sharepic'      => trim((string) ($data['sharepic'] ?? '')),
            'sharedesc'     => trim((string) ($data['sharedesc'] ?? '')),
            'status'        => array_key_exists('status', $data) ? ((int) $data['status'] ? 1 : 0) : 1,
        ], $prizes);

        if ($id > 0) {
            $this->findAct($aid, $id);
            $info['updatetime'] = (string) time();
            Db::name('choujiang')->where('aid', $aid)->where('id', $id)->update($info);
        } else {
            $info['aid']        = $aid;
            $info['cjnum']      = 0;
            $info['zjnum']      = 0;
            $info['createtime'] = (string) time();
            $id                 = (int) Db::name('choujiang')->insertGetId($info);
        }

        return ['id' => $id];
    }

    /**
     * 删除活动（已有抽奖记录时拒绝，防数据悬空）
     */
    public function delete(int $aid, int $id): void
    {
        $this->findAct($aid, $id);

        $recordCount = ScopeService::query('choujiang_record', $aid)->where('hid', $id)->count();
        if ($recordCount > 0) {
            throw new BusinessException('该活动已产生抽奖记录，不能删除');
        }

        Db::name('choujiang')->where('aid', $aid)->where('id', $id)->delete();
    }

    /**
     * 上架/下架（status 0/1）
     */
    public function setStatus(int $aid, int $id, int $status): void
    {
        $this->findAct($aid, $id);

        Db::name('choujiang')->where('aid', $aid)->where('id', $id)
            ->update(['status' => $status ? 1 : 0]);
    }

    /* ================= 抽奖记录 ================= */

    /**
     * 中奖记录分页列表
     *
     * @param array $filters {hid?, nickname?, jxmc?, status?(-1未中奖/0未领/1已领), start_date?, end_date?}
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function recordList(int $aid, int $page, int $pagesize, array $filters): array
    {
        $query = ScopeService::query('choujiang_record', $aid);

        $hid = (int) ($filters['hid'] ?? 0);
        if ($hid > 0) {
            $query->where('hid', $hid);
        }

        $mid = (int) ($filters['mid'] ?? 0);
        if ($mid > 0) {
            $query->where('mid', $mid);
        }

        $nickname = trim((string) ($filters['nickname'] ?? ''));
        if ('' !== $nickname) {
            $query->whereLike('nickname', '%' . $nickname . '%');
        }

        $jxmc = trim((string) ($filters['jxmc'] ?? ''));
        if ('' !== $jxmc) {
            $query->whereLike('jxmc', '%' . $jxmc . '%');
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if ('' !== $status) {
            $status = (int) $status;
            if (-1 === $status) {
                $query->where('jx', 0); // 未中奖
            } else {
                // 未领/已领均指中奖记录
                $query->where('status', $status ? 1 : 0)->where('jx', '>', 0);
            }
        }

        $startDate = trim((string) ($filters['start_date'] ?? ''));
        if ('' !== $startDate && false !== strtotime($startDate)) {
            $query->where('createtime', '>=', strtotime($startDate));
        }
        $endDate = trim((string) ($filters['end_date'] ?? ''));
        if ('' !== $endDate && false !== strtotime($endDate)) {
            $query->where('createtime', '<', strtotime($endDate) + 86400);
        }

        $total = (clone $query)->count();

        $rows = $query->order('id', 'desc')->page($page, $pagesize)->select()->toArray();

        $list = array_map(static fn (array $row): array => [
            'id'         => (int) $row['id'],
            'hid'        => (int) ($row['hid'] ?? 0),
            'name'       => (string) ($row['name'] ?? ''),
            'mid'        => (int) ($row['mid'] ?? 0),
            'nickname'   => (string) ($row['nickname'] ?? ''),
            'headimg'    => (string) ($row['headimg'] ?? ''),
            'jx'         => (int) ($row['jx'] ?? 0),
            'jxtp'       => (int) ($row['jxtp'] ?? 1),
            'jxmc'       => (string) ($row['jxmc'] ?? ''),
            'linkman'    => (string) ($row['linkman'] ?? ''),
            'tel'        => (string) ($row['tel'] ?? ''),
            'createtime' => (int) ($row['createtime'] ?? 0),
            'status'     => (int) ($row['status'] ?? 0),
            'code'       => (string) ($row['code'] ?? ''),
            'remark'     => (string) ($row['remark'] ?? ''),
        ], $rows);

        return ['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pagesize];
    }

    /**
     * 领奖核销（0→1 条件更新幂等；首次核销生成核销码）
     *
     * @return array{code: string}
     */
    public function recordVerify(int $aid, int $id): array
    {
        if ($id <= 0) {
            throw new BusinessException('缺少记录ID');
        }

        $record = ScopeService::query('choujiang_record', $aid)->where('id', $id)->find();
        if (empty($record)) {
            throw new BusinessException('抽奖记录不存在');
        }
        if ((int) $record['jx'] <= 0) {
            throw new BusinessException('该记录未中奖，无需核销');
        }
        if (self::RECORD_RECEIVED === (int) $record['status']) {
            throw new BusinessException('该奖品已领取，请勿重复核销');
        }

        $code = trim((string) ($record['code'] ?? ''));
        if ('' === $code) {
            $code = $this->genVerifyCode();
        }

        $affected = Db::name('choujiang_record')
            ->where('aid', $aid)
            ->where('id', $id)
            ->where('status', self::RECORD_UNRECEIVED)
            ->update([
                'status'     => self::RECORD_RECEIVED,
                'code'       => $code,
                'hexiaoqr'   => $code,
                'updatetime' => time(),
            ]);

        if ($affected <= 0) {
            throw new BusinessException('核销失败，记录状态已变化');
        }

        return ['code' => $code];
    }

    /* ================= 抽奖核心（概率 + 防超发） ================= */

    /**
     * 抽奖（客户端入口，admin 后台不调用）
     *
     * 流程：活动校验 → 次数校验（总次数/每天次数）→ 剩余数量加权随机
     *      → 条件更新扣减奖项（抢不到降级不中奖）→ 写记录并累计活动计数
     *
     * @return array{jx: int, jxmc: string, pic: string, jxtp: int}
     */
    public function draw(int $aid, int $hid, int $mid, string $nickname = '', string $headimg = ''): array
    {
        $act = $this->findAct($aid, $hid);

        if (self::STATUS_ON !== (int) $act['status']) {
            throw new BusinessException('活动已下架');
        }
        $now = time();
        if ($now < (int) $act['starttime']) {
            throw new BusinessException('活动尚未开始');
        }
        if ($now > (int) $act['endtime']) {
            throw new BusinessException('活动已结束');
        }

        // 次数校验：pertotal 总次数 / perday 每日次数（0 为不限；分享加次数并入额度）
        $sharelog  = Db::name('choujiang_sharelog')
            ->where('aid', $aid)->where('hid', $hid)->where('mid', $mid)->find();
        $extraTotal = (int) ($sharelog['extratimes'] ?? 0);
        $extraDay   = (string) ($sharelog['updatetime'] ?? '') === date('Y-m-d')
            ? (int) ($sharelog['adddaytimes'] ?? 0)
            : 0;

        $baseQuery = Db::name('choujiang_record')->where('aid', $aid)->where('hid', $hid)->where('mid', $mid);
        $totalNum  = (clone $baseQuery)->count();
        $pertotal  = (int) $act['pertotal'];
        if ($pertotal > 0 && $totalNum >= $pertotal + $extraTotal) {
            throw new BusinessException('您的抽奖次数已用完');
        }
        $dayNum = (clone $baseQuery)->where('createtime', '>=', strtotime(date('Y-m-d')))->count();
        $perday = (int) $act['perday'];
        if ($perday > 0 && $dayNum >= $perday + $extraDay) {
            throw new BusinessException('今日抽奖次数已用完，明天再来吧');
        }

        // 奖池：剩余数量作权重，数量耗尽出局
        $pool = [];
        for ($i = 1; $i <= self::PRIZE_MAX; $i++) {
            $remain = (int) $act['j' . $i . 'sl'] - (int) $act['j' . $i . 'yj'];
            if ('' !== trim((string) $act['j' . $i . 'mc']) && $remain > 0) {
                $pool[$i] = $remain;
            }
        }

        $jx  = self::PRIZE_NONE;
        $sum = array_sum($pool);
        if ($sum > 0) {
            $rand = mt_rand(1, $sum);
            $acc  = 0;
            foreach ($pool as $idx => $weight) {
                $acc += $weight;
                if ($rand <= $acc) {
                    $jx = $idx;
                    break;
                }
            }
        }

        // 防超发：条件更新 where jNsl > jNyj，并发下只有一个请求能扣成功
        if ($jx > 0) {
            $affected = Db::name('choujiang')
                ->where('aid', $aid)
                ->where('id', $hid)
                ->where('j' . $jx . 'sl', '>', Db::raw('j' . $jx . 'yj'))
                ->inc('j' . $jx . 'yj')
                ->inc('zjnum')
                ->update();

            if ($affected <= 0) {
                // 并发下刚被抢空：降级为不中奖
                $jx = self::PRIZE_NONE;
            }
        }

        // 活动累计抽奖次数
        Db::name('choujiang')->where('aid', $aid)->where('id', $hid)->inc('cjnum')->update();

        $jxmc = $jx > 0 ? (string) $act['j' . $jx . 'mc'] : (string) $act['j0mc'];
        $pic  = $jx > 0 ? (string) ($act['j' . $jx . 'pic'] ?? '') : (string) ($act['j0pic'] ?? '');
        $jxtp = $jx > 0 ? (int) ($act['j' . $jx . 'tp'] ?? 1) : 1;

        Db::name('choujiang_record')->insert([
            'aid'        => $aid,
            'hid'        => $hid,
            'name'       => (string) $act['name'],
            'mid'        => $mid,
            'nickname'   => $nickname,
            'headimg'    => $headimg,
            'jx'         => $jx,
            'jxtp'       => $jxtp,
            'jxmc'       => $jxmc,
            'createtime' => time(),
            'createdate' => date('Y-m-d'),
            'status'     => self::RECORD_UNRECEIVED,
        ]);

        return ['jx' => $jx, 'jxmc' => $jxmc, 'pic' => $pic, 'jxtp' => $jxtp];
    }

    /**
     * 分享加次数（对照参考系统：每次分享 +shareaddnum 次，受 sharetimes 总上限 / sharedaytimes 每日上限约束）
     *
     * @return array{added: int, extratimes: int, adddaytimes: int, sharedaytimes: int, sharecounttimes: int}
     */
    public function shareAddTimes(int $aid, int $hid, int $mid): array
    {
        $act = $this->findAct($aid, $hid);

        if (self::STATUS_ON !== (int) $act['status']) {
            throw new BusinessException('活动已下架');
        }

        $today     = date('Y-m-d');
        $shareadd  = (int) ($act['shareaddnum'] ?? 0);
        $dayLimit  = (int) ($act['sharedaytimes'] ?? 0);
        $totalLimit = (int) ($act['sharetimes'] ?? 0);

        if ($shareadd <= 0) {
            throw new BusinessException('该活动未开启分享加次数');
        }

        $log = Db::name('choujiang_sharelog')
            ->where('aid', $aid)->where('hid', $hid)->where('mid', $mid)->find();

        $shareDayTimes  = (string) ($log['updatetime'] ?? '') === $today ? (int) ($log['sharedaytimes'] ?? 0) : 0;
        $shareCountTimes = (int) ($log['sharecounttimes'] ?? 0);
        $addDayTimes    = (string) ($log['updatetime'] ?? '') === $today ? (int) ($log['adddaytimes'] ?? 0) : 0;
        $addTimes       = (int) ($log['addtimes'] ?? 0);
        $extraTimes     = (int) ($log['extratimes'] ?? 0);

        if ($dayLimit > 0 && $shareDayTimes >= $dayLimit) {
            throw new BusinessException('今日分享次数已用完');
        }
        if ($totalLimit > 0 && $shareCountTimes >= $totalLimit) {
            throw new BusinessException('分享总次数已用完');
        }

        // 剩余可加额度（总上限口径：累计加分次数 × shareaddnum ≤ 总额度，0 不限）
        $added = $shareadd;

        $newShareDay   = $shareDayTimes + 1;
        $newShareCount = $shareCountTimes + 1;
        $newAddTimes   = $addTimes + $added;
        $newExtra      = $extraTimes + $added;

        $row = [
            'sharedaytimes'   => $newShareDay,
            'sharecounttimes' => $newShareCount,
            'adddaytimes'     => $addDayTimes + $added,
            'addtimes'        => $newAddTimes,
            'extratimes'      => $newExtra,
            'updatetime'      => $today,
        ];

        if (empty($log)) {
            Db::name('choujiang_sharelog')->insert(array_merge($row, [
                'aid' => $aid,
                'hid' => $hid,
                'mid' => $mid,
            ]));
        } else {
            Db::name('choujiang_sharelog')->where('id', (int) $log['id'])->update($row);
        }

        return [
            'added'           => $added,
            'extratimes'      => $newExtra,
            'adddaytimes'     => $addDayTimes + $added,
            'sharedaytimes'   => $newShareDay,
            'sharecounttimes' => $newShareCount,
        ];
    }

    /**
     * 剩余抽奖次数（供 C 端展示；0 为不限时返回 -1）
     *
     * @return array{pertotal_left: int, perday_left: int}
     */
    public function leftTimes(int $aid, int $hid, int $mid): array
    {
        $act = $this->findAct($aid, $hid);

        $sharelog   = Db::name('choujiang_sharelog')
            ->where('aid', $aid)->where('hid', $hid)->where('mid', $mid)->find();
        $extraTotal = (int) ($sharelog['extratimes'] ?? 0);
        $extraDay   = (string) ($sharelog['updatetime'] ?? '') === date('Y-m-d')
            ? (int) ($sharelog['adddaytimes'] ?? 0)
            : 0;

        $base     = Db::name('choujiang_record')->where('aid', $aid)->where('hid', $hid)->where('mid', $mid);
        $totalNum = (clone $base)->count();
        $dayNum   = (clone $base)->where('createtime', '>=', strtotime(date('Y-m-d')))->count();

        $pertotal = (int) ($act['pertotal'] ?? 0);
        $perday   = (int) ($act['perday'] ?? 0);

        return [
            'pertotal_left' => $pertotal > 0 ? max(0, $pertotal + $extraTotal - $totalNum) : -1,
            'perday_left'   => $perday > 0 ? max(0, $perday + $extraDay - $dayNum) : -1,
        ];
    }

    /**
     * C 端领奖（校验记录归属后复用核销链路，0→1 幂等）
     *
     * @return array{code: string}
     */
    public function claim(int $aid, int $mid, int $recordId): array
    {
        $record = ScopeService::query('choujiang_record', $aid)->where('id', $recordId)->find();

        if (empty($record)) {
            throw new BusinessException('抽奖记录不存在', 404, 404);
        }
        if ((int) ($record['mid'] ?? 0) !== $mid) {
            throw new BusinessException('只能领取自己的中奖记录');
        }

        return $this->recordVerify($aid, $recordId);
    }

    /* ================= 内部工具 ================= */

    /**
     * 查找本租户活动（不存在抛错）
     *
     * @return array<string, mixed>
     */
    protected function findAct(int $aid, int $id): array
    {
        if ($id <= 0) {
            throw new BusinessException('缺少活动ID');
        }

        $act = ScopeService::query('choujiang', $aid)->where('id', $id)->find();
        if (empty($act)) {
            throw new BusinessException('抽奖活动不存在');
        }

        return $act;
    }

    /**
     * 活动行格式化（列表精简 / 详情带奖项与推导概率）
     *
     * @return array<string, mixed>
     */
    protected function formatAct(array $row, bool $withPrizes): array
    {
        $now   = time();
        $start = (int) ($row['starttime'] ?? 0);
        $end   = (int) ($row['endtime'] ?? 0);
        if ($start > $now) {
            $phase = 'notstart';
        } elseif ($end < $now) {
            $phase = 'ended';
        } else {
            $phase = 'ongoing';
        }

        $data = [
            'id'            => (int) $row['id'],
            'type'          => (string) ($row['type'] ?? ''),
            'name'          => (string) ($row['name'] ?? ''),
            'banner'        => (string) ($row['banner'] ?? ''),
            'starttime'     => $start,
            'endtime'       => $end,
            'phase'         => $phase,
            'status'        => (int) ($row['status'] ?? 1),
            'cjnum'         => (int) ($row['cjnum'] ?? 0),
            'zjnum'         => (int) ($row['zjnum'] ?? 0),
            'pertotal'      => (int) ($row['pertotal'] ?? 0),
            'perday'        => (int) ($row['perday'] ?? 0),
            'shareaddnum'   => (int) ($row['shareaddnum'] ?? 0),
            'createtime'    => (int) ($row['createtime'] ?? 0),
        ];

        if ($withPrizes) {
            $data['guize']         = (string) ($row['guize'] ?? '');
            $data['sharedaytimes'] = (int) ($row['sharedaytimes'] ?? 0);
            $data['sharetimes']    = (int) ($row['sharetimes'] ?? 0);
            $data['sharetitle']    = (string) ($row['sharetitle'] ?? '');
            $data['sharelink']     = (string) ($row['sharelink'] ?? '');
            $data['sharepic']      = (string) ($row['sharepic'] ?? '');
            $data['sharedesc']     = (string) ($row['sharedesc'] ?? '');
            $data['prizes']        = $this->parsePrizes($row);
        }

        return $data;
    }

    /**
     * 扁平字段解析为奖项数组（j0 不中奖 + j1-j9），概率由数量推导
     *
     * @return array<int, array<string, mixed>>
     */
    protected function parsePrizes(array $row): array
    {
        $prizes = [];
        $sum    = 0;

        for ($i = self::PRIZE_NONE; $i <= self::PRIZE_MAX; $i++) {
            $sl = (int) ($row['j' . $i . 'sl'] ?? 0);
            $prizes[] = [
                'index' => $i,
                'mc'    => (string) ($row['j' . $i . 'mc'] ?? ''),
                'pic'   => (string) ($row['j' . $i . 'pic'] ?? ''),
                'tp'    => $i > 0 ? (int) ($row['j' . $i . 'tp'] ?? 1) : 1,
                'sl'    => $sl,
                'yj'    => (int) ($row['j' . $i . 'yj'] ?? 0),
            ];
            $sum += $sl;
        }

        // 概率 = 数量/总数（保留两位，参考系统口径）
        foreach ($prizes as &$prize) {
            $prize['gl'] = $sum > 0 ? round($prize['sl'] / $sum * 100, 2) : 0.0;
        }
        unset($prize);

        return $prizes;
    }

    /**
     * 入参奖项规范化为扁平字段（j0mc 缺省「谢谢参与」；编辑时数量不得低于已出奖数）
     *
     * @return array<string, mixed> j0mc..j9yj 扁平 map
     */
    protected function normalizePrizes(mixed $input, int $aid, int $id): array
    {
        $rows = is_array($input) ? array_values($input) : [];

        $old = [];
        if ($id > 0) {
            $old = ScopeService::query('choujiang', $aid)->where('id', $id)->find() ?: [];
        }

        $flat = [];
        for ($i = self::PRIZE_NONE; $i <= self::PRIZE_MAX; $i++) {
            $one = isset($rows[$i]) && is_array($rows[$i]) ? $rows[$i] : [];

            $mc = trim((string) ($one['mc'] ?? ''));
            $sl = max(0, (int) ($one['sl'] ?? 0));
            $tp = (int) ($one['tp'] ?? 1);

            if (self::PRIZE_NONE === $i) {
                // j0 不中奖槽位恒存在
                $mc = '' === $mc ? '谢谢参与' : $mc;
            } else {
                if (!in_array($tp, [1, 2], true)) {
                    $tp = 1;
                }
                // 编辑校验：数量不能低于已出奖数，防奖品凭空超发
                $oldYj = (int) ($old['j' . $i . 'yj'] ?? 0);
                if ('' !== $mc && $sl < $oldYj) {
                    throw new BusinessException(sprintf('奖项%d的数量不能低于已出奖数%d', $i, $oldYj));
                }
                // 空槽位：名称与数量都空视为未启用；已出过奖的奖项不允许移除
                if ('' === $mc && $sl <= 0) {
                    if ($oldYj > 0) {
                        throw new BusinessException(sprintf('奖项%d已出奖%d份，不能移除该奖项', $i, $oldYj));
                    }
                    $flat['j' . $i . 'mc']  = null;
                    $flat['j' . $i . 'pic'] = null;
                    $flat['j' . $i . 'tp']  = 1;
                    $flat['j' . $i . 'sl']  = null;
                    continue;
                }
                if ('' === $mc) {
                    throw new BusinessException(sprintf('奖项%d请填写奖品名称', $i));
                }
            }

            $flat['j' . $i . 'mc']  = $mc;
            $flat['j' . $i . 'pic'] = trim((string) ($one['pic'] ?? ''));
            $flat['j' . $i . 'sl']  = $sl;
            if ($i > 0) {
                $flat['j' . $i . 'tp'] = $tp;
            }
        }

        return $flat;
    }

    /**
     * 时间归一：兼容时间戳 / Y-m-d H:i:s 字符串
     */
    protected function toTime(mixed $value): int
    {
        if (is_numeric($value)) {
            return (int) $value;
        }

        $value = trim((string) $value);
        if ('' === $value) {
            return 0;
        }

        $ts = strtotime($value);

        return false === $ts ? 0 : $ts;
    }

    /**
     * 核销码：12 位大写字母数字
     */
    protected function genVerifyCode(): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code  = '';
        for ($i = 0; $i < 12; $i++) {
            $code .= $chars[mt_rand(0, strlen($chars) - 1)];
        }

        return $code;
    }
}
