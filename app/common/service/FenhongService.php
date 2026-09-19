<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;

/**
 * 分红体系服务（任务183 批次3-C）
 *
 * 对标参考系统 canzhao/app/common/Fenhong.php：
 * - jiesuan()：扫描 og.isfenhong=0、status in 1,2,3、refund_num=0 的订单商品行，
 *   标 isfenhong=1 后分发到三套计算（幂等：事务内行锁 + 条件更新）
 * - gdfenhong()：股东分红——member_level.fenhong>0 的等级名单按等级比例分，
 *   fenhong_num 份数上限 / fenhong_max_money 封顶（发放时裁剪并写 member_fenhong_max_log）
 * - teamfenhong()：团队分红——沿 path 向上，下单人等级 teamfenhonglv 代数内
 *   按 teamfenhongbl 比例或 teamfenhong_money 每单固定额（teamfenhongonly/包含自己 teamfenhong_self）
 * - areafenhong()：区域分红——等级 areafenhong 1省2市3区县 + areafenhongbl>0，
 *   名单来自 member（跟随等级/独立设置）与 member_level_record，按订单收货地 area2 匹配
 * - fafang()：统一写 member_fenhonglog status=0 待发放，
 *   拆分 send_commission/send_money/send_score/send_fuchi 四载体
 * - examineSend()：发放审核 status 0→1 入账（addcommission/addmoney/addscore）或驳回 2
 *
 * member_fenhonglog.status：0 未发放 / 1 已发放 / 2 已退回
 * type 取值：fenhong（股东）/ teamfenhong（团队）/ areafenhong（区域）
 */
class FenhongService
{
    /** 每批结算的订单商品行数 */
    protected const BATCH = 50;

    /** 分红类型 → member_level 配置存在性校验 key */
    protected const TYPES = ['fenhong', 'teamfenhong', 'areafenhong'];

    /* ================= 结算入口 ================= */

    /**
     * 全租户分红结算（ScheduleRun 调用）
     *
     * @return array{tenants: int, ogcount: int, logs: int, total: float}
     */
    public function jiesuanAll(): array
    {
        $aids = Db::name('admin_set')->column('aid');
        $result = ['tenants' => 0, 'ogcount' => 0, 'logs' => 0, 'total' => 0.0];

        foreach ($aids as $aid) {
            try {
                $one = $this->jiesuan((int) $aid);
                ++$result['tenants'];
                $result['ogcount'] += $one['ogcount'];
                $result['logs'] += $one['logs'];
                $result['total'] = round($result['total'] + $one['total'], 2);
            } catch (\Throwable $e) {
                // 单租户失败不影响其他租户
            }
        }

        return $result;
    }

    /**
     * 单租户分红结算：扫描未分红订单行 → 标记 → 三套计算 → fafang 落 member_fenhonglog
     *
     * @param int    $aid       租户ID
     * @param int    $starttime 订单创建时间下限（0=不限）
     * @param int    $endtime   订单创建时间上限（0=now）
     * @return array{ogcount: int, logs: int, total: float, detail: array<string, int>}
     */
    public function jiesuan(int $aid, int $starttime = 0, int $endtime = 0): array
    {
        if ($endtime <= 0) {
            $endtime = time();
        }

        $sysset = $this->sysset($aid);
        $result = ['ogcount' => 0, 'logs' => 0, 'total' => 0.0, 'detail' => ['fenhong' => 0, 'teamfenhong' => 0, 'areafenhong' => 0]];

        while (true) {
            $batch = $this->settleBatch($aid, $sysset, $starttime, $endtime, $result);

            if (0 === $batch) {
                break;
            }
        }

        return $result;
    }

    /**
     * 单批结算（事务）：行锁取未分红订单行 → 标记 isfenhong=1 → 三套计算 → 写分红记录
     *
     * @param array<string, mixed> $sysset
     * @param array{ogcount: int, logs: int, total: float, detail: array<string, int>} $result 累计结果（引用）
     * @return int 本批处理的订单行数（0=无数据终止）
     */
    protected function settleBatch(int $aid, array $sysset, int $starttime, int $endtime, array &$result): int
    {
        $count = 0;

        Db::transaction(function () use ($aid, $sysset, $starttime, $endtime, &$result, &$count): void {
            $oglist = $this->scanPending($aid, $starttime, $endtime)->lock(true)->select()->toArray();

            if (empty($oglist)) {
                return;
            }

            $count = count($oglist);
            $ids = array_map(static fn ($row): int => (int) $row['id'], $oglist);

            // 幂等标记：条件更新（事务内已行锁，双重保险）
            Db::name('shop_order_goods')->whereIn('id', $ids)->where('isfenhong', 0)
                ->update(['isfenhong' => 1, 'isteamfenhong' => 1]);

            // 分红基数规整（real_totalmoney 优先，其次销售价减各类抵扣）
            foreach ($oglist as $k => $og) {
                $oglist[$k]['real_totalprice'] = $this->settlePrice((array) $og);
                $oglist[$k]['module'] = 'shop';
            }

            $remarkMap = ['fenhong' => '股东分红', 'teamfenhong' => '团队分红', 'areafenhong' => '区域代理分红'];

            foreach (self::TYPES as $type) {
                $fhArr = $this->calc($aid, $sysset, $type, $oglist);

                if (empty($fhArr)) {
                    continue;
                }

                $logIds = $this->fafang($aid, $fhArr, $type, $remarkMap[$type]);
                $result['detail'][$type] += count($logIds);
                $result['logs'] += count($logIds);

                foreach ($fhArr as $midfh) {
                    $result['total'] = round($result['total'] + (float) $midfh['totalcommission'], 2);
                }
            }

            $result['ogcount'] += $count;
        });

        return $count;
    }

    /**
     * 扫描待分红订单商品行（og.isfenhong=0、status in 1,2,3、refund_num=0）
     */
    protected function scanPending(int $aid, int $starttime, int $endtime): \think\db\BaseQuery
    {
        $query = Db::name('shop_order_goods')->alias('og')
            ->join('shop_order o', 'o.id = og.orderid')
            ->field('og.*, o.area2, o.paytime')
            ->where('og.aid', $aid)
            ->where('og.isfenhong', 0)
            ->whereIn('og.status', [1, 2, 3])
            ->where('og.refund_num', 0)
            ->where('og.createtime', '>=', $starttime)
            ->where('og.createtime', '<', $endtime)
            ->order('og.id', 'asc')
            ->limit(self::BATCH);

        return $query;
    }

    /* ================= 预览（只读，不落库） ================= */

    /**
     * 时段预览：按分红类型试算未分红订单行
     *
     * @param string $type      fenhong/teamfenhong/areafenhong
     * @param int    $rangeType 1上月 2本月 3昨日 4今日 10全部
     * @return array{range: array{start:int,end:int}, ogcount: int, allprice: float, total: float, membercount: int, list: array}
     */
    public function preview(int $aid, string $type, int $rangeType): array
    {
        [$start, $end] = $this->rangeBounds($rangeType);
        $sysset = $this->sysset($aid);

        $oglist = Db::name('shop_order_goods')->alias('og')
            ->join('shop_order o', 'o.id = og.orderid')
            ->field('og.*, o.area2, o.paytime')
            ->where('og.aid', $aid)
            ->where('og.isfenhong', 0)
            ->whereIn('og.status', [1, 2, 3])
            ->where('og.refund_num', 0)
            ->where('og.createtime', '>=', $start)
            ->where('og.createtime', '<', $end)
            ->order('og.id', 'asc')
            ->limit(500)
            ->select()
            ->toArray();

        $allprice = 0.0;

        foreach ($oglist as $k => $og) {
            $price = $this->fenhongPrice($sysset, (array) $og);
            $oglist[$k]['real_totalprice'] = $this->settlePrice((array) $og);
            $oglist[$k]['module'] = 'shop';
            if ($price > 0) {
                $allprice = round($allprice + $price, 2);
            }
        }

        $fhArr = empty($oglist) ? [] : $this->calc($aid, $sysset, $type, $oglist);

        $list = [];
        $total = 0.0;
        $mids = array_map('intval', array_keys($fhArr));

        $memberMap = [];
        if ($mids) {
            $rows = Db::name('member')->where('aid', $aid)->whereIn('id', $mids)->field('id,nickname,levelid')->select()->toArray();
            foreach ($rows as $row) {
                $memberMap[(int) $row['id']] = $row;
            }
        }
        $levelNames = $this->levelNames($aid);

        foreach ($fhArr as $mid => $midfh) {
            $total = round($total + (float) $midfh['totalcommission'], 2);
            $list[] = [
                'mid'        => (int) $mid,
                'nickname'   => (string) ($memberMap[(int) $mid]['nickname'] ?? ('会员' . $mid)),
                'levelid'    => (int) ($midfh['levelid'] ?? 0),
                'levelname'  => (string) ($levelNames[(int) ($midfh['levelid'] ?? 0)] ?? '-'),
                'commission' => round((float) $midfh['totalcommission'], 2),
                'ogcount'    => count((array) $midfh['ogids']),
            ];
        }

        usort($list, static fn ($a, $b): int => $b['commission'] <=> $a['commission']);

        return [
            'range'       => ['start' => $start, 'end' => $end],
            'ogcount'     => count($oglist),
            'allprice'    => $allprice,
            'total'       => $total,
            'membercount' => count($list),
            'list'        => array_slice($list, 0, 200),
        ];
    }

    /**
     * 时段边界（对标参考系统 teamfenhong 页 type 取值）：1上月 2本月 3昨日 4今日 10全部
     *
     * @return array{0: int, 1: int}
     */
    public function rangeBounds(int $rangeType): array
    {
        switch ($rangeType) {
            case 1: // 上月
                return [(int) strtotime(date('Y-m-01') . ' -1 month'), (int) strtotime(date('Y-m-01'))];
            case 2: // 本月
                return [(int) strtotime(date('Y-m-01')), time()];
            case 3: // 昨日
                $start = (int) strtotime(date('Y-m-d')) - 86400;
                return [$start, $start + 86400];
            case 4: // 今日
                return [(int) strtotime(date('Y-m-d')), time()];
            default: // 全部
                return [1, time()];
        }
    }

    /* ================= 三套分红计算 ================= */

    /**
     * 按类型分发计算
     *
     * @param array<string, mixed> $sysset
     * @param array<int, array>    $oglist
     * @return array<int, array<string, mixed>> mid => 累计发放数据
     */
    protected function calc(int $aid, array $sysset, string $type, array $oglist): array
    {
        switch ($type) {
            case 'fenhong':
                return $this->gdfenhong($aid, $sysset, $oglist);
            case 'teamfenhong':
                return $this->teamfenhong($aid, $sysset, $oglist);
            case 'areafenhong':
                return $this->areafenhong($aid, $sysset, $oglist);
            default:
                return [];
        }
    }

    /**
     * 股东分红（对标参考系统 Fenhong::gdfenhong 核心）
     *
     * 等级 member_level.fenhong>0 → 等级名单（levelstarttime 早于下单时间、
     * 未达 fenhong_max_money 封顶、fenhong_num 份数上限）→ 总额=比例×基数 平均分
     *
     * @param array<string, mixed> $sysset
     * @param array<int, array>    $oglist
     * @return array<int, array<string, mixed>>
     */
    protected function gdfenhong(int $aid, array $sysset, array $oglist): array
    {
        $fhlevels = Db::name('member_level')->where('aid', $aid)->where('fenhong', '>', 0)->order('sort,id')->select()->toArray();

        if (empty($fhlevels)) {
            return [];
        }

        $fhArr = [];

        foreach ($oglist as $og) {
            $fenhongprice = $this->fenhongPrice($sysset, (array) $og);

            if ($fenhongprice <= 0) {
                continue;
            }

            foreach ($fhlevels as $fhlevel) {
                $where = [
                    ['aid', '=', $aid],
                    ['levelid', '=', (int) $fhlevel['id']],
                    ['levelstarttime', '<', (int) ($og['createtime'] ?? 0)],
                ];

                if ((float) ($fhlevel['fenhong_max_money'] ?? 0) > 0) {
                    $where[] = ['total_fenhong_partner', '<', (float) $fhlevel['fenhong_max_money']];
                }

                $query = Db::name('member')->where($where)->field('id,total_fenhong_partner,levelid')->order('levelstarttime,id');

                if ((int) ($fhlevel['fenhong_num'] ?? 0) > 0) {
                    $query->limit((int) $fhlevel['fenhong_num']);
                }

                $midlist = $query->select()->toArray();

                if (empty($midlist)) {
                    continue;
                }

                $totalcommission = (float) $fhlevel['fenhong'] * $fenhongprice * 0.01;

                if ($totalcommission <= 0) {
                    continue;
                }

                $per = $totalcommission / count($midlist);

                foreach ($midlist as $item) {
                    $this->accumulate($fhArr, (int) $item['id'], $per, (int) $og['id'], (int) $fhlevel['id'], (string) ($og['module'] ?? 'shop'));
                }
            }
        }

        return $fhArr;
    }

    /**
     * 团队分红（对标参考系统 Fenhong::teamfenhong 核心）
     *
     * 沿下单人 path 向上（含自己视 teamfenhong_self），收款人等级 teamfenhonglv 代数内：
     * teamfenhong_money 每单固定额（一单一次）+ teamfenhongbl 比例；teamfenhongonly 只给最近同级上级
     *
     * @param array<string, mixed> $sysset
     * @param array<int, array>    $oglist
     * @return array<int, array<string, mixed>>
     */
    protected function teamfenhong(int $aid, array $sysset, array $oglist): array
    {
        $teamlevels = Db::name('member_level')->where('aid', $aid)
            ->where('teamfenhonglv', '>', 0)
            ->where(function ($query) {
                $query->where('teamfenhongbl', '>', 0)->whereOr('teamfenhong_money', '>', 0);
            })
            ->select()
            ->toArray();

        if (empty($teamlevels)) {
            return [];
        }

        $levelMap = [];
        foreach ($teamlevels as $row) {
            $levelMap[(int) $row['id']] = (array) $row;
        }

        $fhArr = [];

        foreach ($oglist as $og) {
            $fenhongprice = $this->fenhongPrice($sysset, (array) $og);

            if ($fenhongprice <= 0 && !array_filter($levelMap, static fn ($lv): bool => (float) ($lv['teamfenhong_money'] ?? 0) > 0)) {
                continue;
            }

            $member = Db::name('member')->where('id', (int) $og['mid'])->where('aid', $aid)->find();

            if (empty($member)) {
                continue;
            }

            $pids = (string) ($member['path'] ?? '');
            $pids = '' !== $pids ? $pids . ',' . (int) $og['mid'] : (string) (int) $og['mid'];

            $parentList = Db::name('member')->where('aid', $aid)->whereIn('id', array_map('intval', explode(',', $pids)))
                ->order(Db::raw('field(id,' . $pids . ')'))
                ->select()
                ->toArray();
            $parentList = array_reverse($parentList); // 由近及远：自己、上一级、上二级……

            $level_i = 0;
            $hasfhlevelids = [];
            $orderRewarded = []; // orderid => true（每单固定额一单一次）

            foreach ($parentList as $parent) {
                $leveldata = $levelMap[(int) ($parent['levelid'] ?? 0)] ?? null;

                if (empty($leveldata)) {
                    continue;
                }

                // 不包含自己：下单人本人跳过且不占层级
                if ((int) $parent['id'] === (int) $og['mid'] && (int) ($leveldata['teamfenhong_self'] ?? 0) !== 1) {
                    continue;
                }

                ++$level_i;

                if ($level_i > (int) $leveldata['teamfenhonglv']) {
                    continue;
                }

                // 只给最近的同等级上级分红
                if ((int) ($leveldata['teamfenhongonly'] ?? 0) === 1 && in_array((int) $parent['levelid'], $hasfhlevelids, true)) {
                    continue;
                }
                $hasfhlevelids[] = (int) $parent['levelid'];

                $commission = 0.0;

                // 每单固定奖励（同一订单只发一次）
                if ((float) ($leveldata['teamfenhong_money'] ?? 0) > 0 && empty($orderRewarded[(int) ($parent['id'])][(int) $og['orderid']])) {
                    $commission += (float) $leveldata['teamfenhong_money'];
                    $orderRewarded[(int) $parent['id']][(int) $og['orderid']] = true;
                }

                // 比例分红
                if ((float) ($leveldata['teamfenhongbl'] ?? 0) > 0 && $fenhongprice > 0) {
                    $commission += (float) $leveldata['teamfenhongbl'] * $fenhongprice * 0.01;
                }

                if ($commission <= 0) {
                    continue;
                }

                $this->accumulate($fhArr, (int) $parent['id'], $commission, (int) $og['id'], (int) $leveldata['id'], (string) ($og['module'] ?? 'shop'));
            }
        }

        return $fhArr;
    }

    /**
     * 区域分红（对标参考系统 Fenhong::areafenhong 核心）
     *
     * 等级 areafenhong 1省2市3区县 + areafenhongbl>0；名单 = 该等级跟随会员（member.areafenhong=0）
     * + 会员独立设置（member.areafenhong=tier 且比例>0）+ member_level_record 区域记录；
     * 按订单收货地 area2（省,市,区）匹配；areafenhongmaxnum 封顶分红单数
     *
     * @param array<string, mixed> $sysset
     * @param array<int, array>    $oglist
     * @return array<int, array<string, mixed>>
     */
    protected function areafenhong(int $aid, array $sysset, array $oglist): array
    {
        $areafhlevels = Db::name('member_level')->where('aid', $aid)
            ->whereIn('areafenhong', [1, 2, 3])
            ->where('areafenhongbl', '>', 0)
            ->order('areafenhong,id')
            ->select()
            ->toArray();

        // 独立区域代理（会员表直接挂 areafenhong + 比例）与分组等级区域记录
        $indepMembers = Db::name('member')->where('aid', $aid)
            ->whereIn('areafenhong', [1, 2, 3])
            ->where('areafenhongbl', '>', 0)
            ->field('id,levelid,areafenhong,areafenhongbl,areafenhong_province,areafenhong_city,areafenhong_area')
            ->select()
            ->toArray();

        $recordMembers = Db::name('member_level_record')->where('aid', $aid)
            ->whereIn('areafenhong', [1, 2, 3])
            ->where('areafenhongbl', '>', 0)
            ->field('mid as id,levelid,areafenhong,areafenhongbl,areafenhong_province,areafenhong_city,areafenhong_area')
            ->select()
            ->toArray();

        if (empty($areafhlevels) && empty($indepMembers) && empty($recordMembers)) {
            return [];
        }

        $fhArr = [];
        $maxnumCache = []; // mid => 已获得区域分红条数（status 0/1）

        foreach ($oglist as $og) {
            $fenhongprice = $this->fenhongPrice($sysset, (array) $og);

            if ($fenhongprice <= 0) {
                continue;
            }

            $areaArr = explode(',', (string) ($og['area2'] ?? ''));
            $province = trim((string) ($areaArr[0] ?? ''));
            $city = trim((string) ($areaArr[1] ?? ''));
            $area = trim((string) ($areaArr[2] ?? ''));

            if ('' === $province) {
                continue; // 无收货地无法匹配区域
            }

            // 1) 等级名单：跟随等级设置的会员（member.areafenhong=0），
            // 按会员自身代理区域（areafenhong_province/city/area）匹配后按等级比例均分
            foreach ($areafhlevels as $fhlevel) {
                $tier = (int) $fhlevel['areafenhong'];

                $memberlist = Db::name('member')->where('aid', $aid)
                    ->where('levelid', (int) $fhlevel['id'])
                    ->where('areafenhong', 0)
                    ->field('id,levelid,areafenhong_province,areafenhong_city,areafenhong_area')
                    ->select()
                    ->toArray();

                if (empty($memberlist)) {
                    continue;
                }

                $matched = [];

                foreach ($memberlist as $m) {
                    if ($this->areaMatch($tier, $province, $city, $area, (string) ($m['areafenhong_province'] ?? ''), (string) ($m['areafenhong_city'] ?? ''), (string) ($m['areafenhong_area'] ?? ''))) {
                        $matched[] = $m;
                    }
                }

                if (empty($matched)) {
                    continue;
                }

                $total = (float) $fhlevel['areafenhongbl'] * $fenhongprice * 0.01;
                $per = $total / count($matched);

                if ($per <= 0) {
                    continue;
                }

                foreach ($matched as $m) {
                    if (!$this->areaMaxnumOk($aid, (int) $m['id'], (int) ($fhlevel['areafenhongmaxnum'] ?? 0), $maxnumCache)) {
                        continue;
                    }
                    $this->accumulate($fhArr, (int) $m['id'], $per, (int) $og['id'], (int) $fhlevel['id'], (string) ($og['module'] ?? 'shop'));
                }
            }

            // 2) 独立代理（会员表 + 分组等级记录）：按各自比例
            foreach (array_merge($indepMembers, $recordMembers) as $m) {
                $tier = (int) $m['areafenhong'];

                if (!$this->areaMatch($tier, $province, $city, $area, (string) ($m['areafenhong_province'] ?? ''), (string) ($m['areafenhong_city'] ?? ''), (string) ($m['areafenhong_area'] ?? ''))) {
                    continue;
                }

                $commission = (float) $m['areafenhongbl'] * $fenhongprice * 0.01;

                if ($commission <= 0) {
                    continue;
                }

                $mid = (int) $m['id'];

                if (!$this->areaMaxnumOk($aid, $mid, 0, $maxnumCache)) {
                    continue;
                }

                $this->accumulate($fhArr, $mid, $commission, (int) $og['id'], (int) ($m['levelid'] ?? 0), (string) ($og['module'] ?? 'shop'));
            }
        }

        return $fhArr;
    }

    /**
     * 区域匹配：1省匹配省；2市匹配省+市；3区县匹配省+市+区
     */
    protected function areaMatch(int $tier, string $province, string $city, string $area, string $mProvince, string $mCity, string $mArea): bool
    {
        if ('' === $mProvince || $mProvince !== $province) {
            return false;
        }

        if ($tier >= 2) {
            if ('' === $mCity || $mCity !== $city) {
                return false;
            }
        }

        if ($tier >= 3) {
            if ('' === $mArea || $mArea !== $area) {
                return false;
            }
        }

        return true;
    }

    /**
     * 区域分红单数上限（member_level.areafenhongmaxnum，0=不限）
     *
     * @param array<int, int> $cache
     */
    protected function areaMaxnumOk(int $aid, int $mid, int $maxnum, array &$cache): bool
    {
        if ($maxnum <= 0) {
            return true;
        }

        if (!isset($cache[$mid])) {
            $cache[$mid] = (int) Db::name('member_fenhonglog')
                ->where('aid', $aid)
                ->where('mid', $mid)
                ->where('type', 'areafenhong')
                ->whereIn('status', [0, 1])
                ->count();
        }

        if ($cache[$mid] >= $maxnum) {
            return false;
        }

        ++$cache[$mid];

        return true;
    }

    /* ================= 发放落库 ================= */

    /**
     * 统一发放落库（对标参考系统 Fenhong::fafang）：写 member_fenhonglog status=0 待发放
     *
     * @param array<int, array<string, mixed>> $fhArr mid => 累计数据
     * @return array<int, int> 新增 log ids
     */
    protected function fafang(int $aid, array $fhArr, string $type, string $remark): array
    {
        $logIds = [];
        $now = time();

        foreach ($fhArr as $mid => $midfh) {
            $totalcommission = round((float) $midfh['totalcommission'], 2);

            if ($totalcommission <= 0 && (float) ($midfh['score'] ?? 0) <= 0) {
                continue;
            }

            $ogids = (array) $midfh['ogids'];

            $logIds[] = (int) Db::name('member_fenhonglog')->insertGetId([
                'aid'             => $aid,
                'mid'             => (int) $mid,
                'frommid'         => (int) ($midfh['frommid'] ?? 0),
                'commission'      => $totalcommission,
                'remark'          => (string) ($midfh['remark'] ?? $remark),
                'type'            => $type,
                'createtime'      => $now,
                'ogids'           => implode(',', array_map('strval', array_unique($ogids))),
                'module'          => (string) ($midfh['module'] ?? 'shop'),
                'send_commission' => round((float) ($midfh['commission'] ?? $totalcommission), 2),
                'send_money'      => round((float) ($midfh['money'] ?? 0), 2),
                'send_score'      => round((float) ($midfh['score'] ?? 0), 2),
                'send_fuchi'      => round((float) ($midfh['fuchi'] ?? 0), 2),
                'status'          => 0,
                'levelid'         => (int) ($midfh['levelid'] ?? 0),
                'sendtime_yj'     => 0,
            ]);
        }

        return $logIds;
    }

    /* ================= 发放审核 ================= */

    /**
     * 发放审核（对标参考系统 Commission::examineSend）
     *
     * st=1 通过：status 0→1，按载体入账（send_commission→佣金 / send_money→余额 / send_score→积分）；
     *           股东分红叠加 total_fenhong_partner 封顶裁剪（写 member_fenhong_max_log）
     * st=2 驳回：status 0→2
     *
     * @return array{status: int, msg: string}
     */
    public function examineSend(int $aid, int $id, int $st, int $uid): array
    {
        if (2 === $st) {
            $affected = Db::name('member_fenhonglog')
                ->where('aid', $aid)->where('id', $id)->where('status', 0)
                ->update(['status' => 2, 'send_remark' => '审核驳回']);

            if ($affected <= 0) {
                throw new BusinessException('记录不存在或已处理');
            }

            return ['status' => 2, 'msg' => '已驳回'];
        }

        if (1 !== $st) {
            throw new BusinessException('非法的审核状态');
        }

        $result = ['status' => 1, 'msg' => '发放成功'];

        Db::transaction(function () use ($aid, $id, $uid, &$result): void {
            $log = Db::name('member_fenhonglog')->where('aid', $aid)->where('id', $id)->where('status', 0)->lock(true)->find();

            if (empty($log)) {
                throw new BusinessException('记录不存在或已处理');
            }

            $log = (array) $log;
            $sendCommission = round((float) ($log['send_commission'] ?? 0), 2);
            $sendMoney = round((float) ($log['send_money'] ?? 0), 2);
            $sendScore = (int) round((float) ($log['send_score'] ?? 0));
            $sendRemark = '';

            // 股东分红封顶裁剪
            if ('fenhong' === (string) $log['type'] && $sendCommission > 0) {
                $sendCommission = $this->applyFenhongMax($aid, $log, $sendCommission, $sendRemark);
            }

            if ($sendCommission <= 0 && $sendMoney <= 0 && $sendScore <= 0) {
                // 全部被封顶裁剪：直接标记已发放
                Db::name('member_fenhonglog')->where('id', (int) $log['id'])->update([
                    'status' => 1, 'send_commission' => 0, 'send_remark' => $sendRemark ?: '超出分红上限，未实际发放',
                ]);
                $result['msg'] = '超出分红上限，未实际发放';

                return;
            }

            $member = Db::name('member')->where('id', (int) $log['mid'])->where('aid', $aid)->lock(true)->find();

            if (empty($member)) {
                throw new BusinessException('受益会员不存在');
            }

            $member = (array) $member;
            $remarkBase = (string) ($log['remark'] ?? '');

            // 入账：佣金
            if ($sendCommission > 0) {
                $after = round((float) ($member['commission'] ?? 0) + $sendCommission, 2);
                Db::name('member')->where('id', (int) $log['mid'])->where('aid', $aid)->update([
                    'commission'      => $after,
                    'totalcommission' => round((float) ($member['totalcommission'] ?? 0) + $sendCommission, 2),
                ]);
                Db::name('member_commissionlog')->insert([
                    'aid'        => $aid,
                    'mid'        => (int) $log['mid'],
                    'frommid'    => (int) ($log['frommid'] ?? 0),
                    'commission' => $sendCommission,
                    'after'      => $after,
                    'createtime' => time(),
                    'remark'     => $remarkBase,
                    'fhtype'     => (string) $log['type'],
                    'uid'        => $uid,
                    'fhid'       => (int) $log['id'],
                ]);
            }

            // 入账：余额
            if ($sendMoney > 0) {
                $after = round((float) ($member['money'] ?? 0) + $sendMoney, 2);
                Db::name('member')->where('id', (int) $log['mid'])->where('aid', $aid)->update(['money' => $after]);
                Db::name('member_moneylog')->insert([
                    'aid'        => $aid,
                    'mid'        => (int) $log['mid'],
                    'money'      => $sendMoney,
                    'after'      => $after,
                    'createtime' => time(),
                    'remark'     => $remarkBase,
                    'paytype'    => 'fenhong',
                ]);
            }

            // 入账：积分
            if ($sendScore > 0) {
                $after = (int) ($member['score'] ?? 0) + $sendScore;
                Db::name('member')->where('id', (int) $log['mid'])->where('aid', $aid)->update(['score' => $after]);
                Db::name('member_scorelog')->insert([
                    'aid'        => $aid,
                    'bid'        => 0,
                    'mid'        => (string) (int) $log['mid'],
                    'score'      => $sendScore,
                    'after'      => $after,
                    'used'       => 0,
                    'createtime' => time(),
                    'remark'     => $remarkBase,
                    'type'       => 1,
                    'channel'    => 'fenhong',
                    'status'     => 0,
                    'uid'        => $uid,
                ]);
            }

            // 股东分红累计（封顶基数）
            if ('fenhong' === (string) $log['type'] && $sendCommission > 0) {
                Db::name('member')->where('id', (int) $log['mid'])->where('aid', $aid)
                    ->update(['total_fenhong_partner' => round((float) ($member['total_fenhong_partner'] ?? 0) + $sendCommission, 2)]);
            }

            Db::name('member_fenhonglog')->where('id', (int) $log['id'])->update([
                'status'          => 1,
                'send_commission' => $sendCommission,
                'send_remark'     => $sendRemark ?: ('审核发放' . ($uid > 0 ? '（操作人' . $uid . '）' : '')),
            ]);
        });

        return $result;
    }

    /**
     * 股东分红封顶裁剪：等级 fenhong_max_money>0 时按 total_fenhong_partner 剩余额度裁剪，写封顶记录
     *
     * @param array<string, mixed> $log
     */
    protected function applyFenhongMax(int $aid, array $log, float $sendCommission, string &$sendRemark): float
    {
        $level = Db::name('member_level')->where('aid', $aid)->where('id', (int) ($log['levelid'] ?? 0))->find();
        $maxMoney = (float) ($level['fenhong_max_money'] ?? 0);

        if ($maxMoney <= 0) {
            return $sendCommission;
        }

        $hadTotal = (float) Db::name('member')->where('aid', $aid)->where('id', (int) $log['mid'])->value('total_fenhong_partner');
        $remain = round($maxMoney - $hadTotal, 2);

        if ($remain >= $sendCommission) {
            return $sendCommission;
        }

        $new = $remain > 0 ? $remain : 0.0;
        $sendRemark = sprintf('分红金额超过上限%.2f，实际发放%.2f', $maxMoney, $new);

        Db::name('member_fenhong_max_log')->insert([
            'aid'        => $aid,
            'mid'        => (int) $log['mid'],
            'levelid'    => (int) ($log['levelid'] ?? 0),
            'type'       => 'fenhong',
            'max_money'  => $maxMoney,
            'had_total'  => $hadTotal,
            'send_money' => $new,
            'logid'      => (int) $log['id'],
            'createtime' => time(),
            'remark'     => $sendRemark,
        ]);

        return $new;
    }

    /**
     * 一键发放：按类型批量审核通过所有待发放记录
     *
     * @return array{sent: int, skipped: int, total: float}
     */
    public function sendAll(int $aid, string $type, int $uid): array
    {
        $ids = Db::name('member_fenhonglog')
            ->where('aid', $aid)->where('type', $type)->where('status', 0)
            ->order('id', 'asc')
            ->column('id');

        $result = ['sent' => 0, 'skipped' => 0, 'total' => 0.0];

        foreach ($ids as $id) {
            try {
                $this->examineSend($aid, (int) $id, 1, $uid);
                ++$result['sent'];
            } catch (\Throwable $e) {
                ++$result['skipped'];
            }
        }

        $result['total'] = (float) Db::name('member_fenhonglog')
            ->where('aid', $aid)->where('type', $type)->whereIn('status', [0, 1])->sum('commission');

        return $result;
    }

    /* ================= 分红记录列表 ================= */

    /**
     * 分红记录列表（B 端三页的待发放/已发放区域）
     *
     * @return array{list: array, total: int, page: int, pagesize: int, stat: array{pending: int, paid: int, pending_money: float}}
     */
    public function logList(int $aid, string $type, int $status, int $page, int $pageSize): array
    {
        $page = max(1, $page);
        $pageSize = min(100, max(1, $pageSize));

        $base = function () use ($aid, $type, $status): \think\db\BaseQuery {
            $query = Db::name('member_fenhonglog')->alias('l')
                ->leftJoin('member m', 'm.id = l.mid')
                ->where('l.aid', $aid)
                ->where('l.type', $type);

            if ($status >= 0) {
                $query->where('l.status', $status);
            }

            return $query;
        };

        $total = (clone ($base()))->count();

        $rows = $base()
            ->field('l.*, m.nickname, m.tel')
            ->order('l.id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        $statusText = [0 => '未发放', 1 => '已发放', 2 => '已退回'];

        foreach ($rows as $k => $row) {
            $rows[$k]['status_text'] = $statusText[(int) $row['status']] ?? '-';
            $rows[$k]['nickname'] = (string) ($row['nickname'] ?? ('会员' . $row['mid']));
        }

        $statQuery = Db::name('member_fenhonglog')->where('aid', $aid)->where('type', $type);
        $stat = [
            'pending'       => (int) (clone $statQuery)->where('status', 0)->count(),
            'paid'          => (int) (clone $statQuery)->where('status', 1)->count(),
            'pending_money' => round((float) (clone $statQuery)->where('status', 0)->sum('commission'), 2),
        ];

        return ['list' => $rows, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize, 'stat' => $stat];
    }

    /* ================= 内部工具 ================= */

    /**
     * 累加某会员的分红（跨订单行合并，ogids 收集）
     *
     * @param array<int, array<string, mixed>> $fhArr
     */
    protected function accumulate(array &$fhArr, int $mid, float $commission, int $ogid, int $levelid, string $module): void
    {
        $commission = round($commission, 2);

        if (isset($fhArr[$mid])) {
            $fhArr[$mid]['totalcommission'] = round((float) $fhArr[$mid]['totalcommission'] + $commission, 2);
            $fhArr[$mid]['commission'] = round((float) $fhArr[$mid]['commission'] + $commission, 2);
            $fhArr[$mid]['ogids'][] = $ogid;
            if ($levelid > 0) {
                $fhArr[$mid]['levelid'] = $levelid;
            }
        } else {
            $fhArr[$mid] = [
                'totalcommission' => $commission,
                'commission'      => $commission,
                'money'           => 0,
                'score'           => 0,
                'fuchi'           => 0,
                'ogids'           => [$ogid],
                'levelid'         => $levelid,
                'module'          => $module,
            ];
        }
    }

    /**
     * 分红基数（fhjiesuantype：0 按销售金额 / 1 按销售利润）
     *
     * @param array<string, mixed> $sysset
     * @param array<string, mixed> $og
     */
    protected function fenhongPrice(array $sysset, array $og): float
    {
        $price = (float) $this->settlePrice($og);

        if ((int) ($sysset['fhjiesuantype'] ?? 0) === 1) {
            $price -= (float) ($og['cost_price'] ?? 0) * (int) ($og['num'] ?? 0);
        }

        return $price > 0 ? round($price, 2) : 0.0;
    }

    /**
     * 成交价格规整（对标参考系统 jiesuan）：real_totalmoney 优先，其次 totalprice 减抵扣
     *
     * @param array<string, mixed> $og
     */
    protected function settlePrice(array $og): float
    {
        $real = (float) ($og['real_totalmoney'] ?? 0);

        if ($real > 0) {
            return round($real, 2);
        }

        $total = (float) ($og['totalprice'] ?? 0)
            - (float) ($og['leveldk_money'] ?? 0)
            - (float) ($og['scoredk_money'] ?? 0)
            - (float) ($og['manjian_money'] ?? 0);

        return round($total, 2);
    }

    /**
     * 租户 admin_set 行
     *
     * @return array<string, mixed>
     */
    protected function sysset(int $aid): array
    {
        $row = Db::name('admin_set')->where('aid', $aid)->find();

        return empty($row) ? ['aid' => $aid] : (array) $row;
    }

    /**
     * 等级名称映射（id => name）
     *
     * @return array<int, string>
     */
    protected function levelNames(int $aid): array
    {
        $rows = Db::name('member_level')->where('aid', $aid)->field('id,name')->select()->toArray();
        $map = [];

        foreach ($rows as $row) {
            $map[(int) $row['id']] = (string) $row['name'];
        }

        return $map;
    }
}
