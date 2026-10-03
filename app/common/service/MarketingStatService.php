<?php

declare(strict_types=1);

namespace app\common\service;

use think\facade\Db;

/**
 * 租户后台 - 营销数据统计服务
 *
 * 口径对照参考系统 yingxiao/DataStatistics（点大商城）：
 * - 六模块订单聚合：shop/collage/kanjia/seckill/tuangou/yuyue（表不存在的跳过）
 * - 已支付口径：status in (1,2,3) 且 paytime>0，delete=0（有 delete 列的表）
 * - 净额销售额 = sum(totalprice - refund_money)；订单数 = 总单数 - 退款单数
 * - 累计支付会员 = 全模块去重 mid；客单价 = 净额销售额 / 净订单数
 * - 退款率 = 退款单数 / 净订单数；会员消费按 11 个累计消费区间分桶
 * - 时间范围：today / 7days / 30days / month / custom(自定义起止)
 * - 趋势指标 qus：sales 销售额 / orders 订单量 / members 支付人数
 *
 * 本库适配说明：shop 等业务订单均落各 *_order 表（payorder 流水无 shop 类型记录），
 * 故直接聚合各订单表，时间归属用 paytime（与本项目 StatService 一致）。
 */
class MarketingStatService
{
    /** 模块 → 订单表（name 展示名；delete 是否有 delete 列；freight 是否参与配送方式筛选） */
    private const MODULES = [
        'shop'    => ['table' => 'shop_order',    'name' => '商城',     'delete' => true, 'freight' => true],
        'collage' => ['table' => 'collage_order', 'name' => '多人拼团', 'delete' => true, 'freight' => true],
        'kanjia'  => ['table' => 'kanjia_order',  'name' => '砍价',     'delete' => true, 'freight' => true],
        'seckill' => ['table' => 'seckill_order', 'name' => '整点秒杀', 'delete' => true, 'freight' => true],
        'tuangou' => ['table' => 'tuangou_order', 'name' => '团购',     'delete' => true, 'freight' => true],
        'yuyue'   => ['table' => 'yuyue_order',   'name' => '预约',     'delete' => true, 'freight' => false],
    ];

    /** 会员累计消费区间（对照参考系统 money_arr） */
    private const MONEY_BUCKETS = [
        ['label' => '￥0.01~100',     'min' => 0.01, 'max' => 100],
        ['label' => '￥100.01~400',   'min' => 100.01, 'max' => 400],
        ['label' => '￥400.01~700',   'min' => 400.01, 'max' => 700],
        ['label' => '￥700.01~1000',  'min' => 700.01, 'max' => 1000],
        ['label' => '￥1000.01~1300', 'min' => 1000.01, 'max' => 1300],
        ['label' => '￥1300.01~1900', 'min' => 1300.01, 'max' => 1900],
        ['label' => '￥1900.01~2900', 'min' => 1900.01, 'max' => 2900],
        ['label' => '￥2900.01~3600', 'min' => 2900.01, 'max' => 3600],
        ['label' => '￥3600.01~4600', 'min' => 3600.01, 'max' => 4600],
        ['label' => '￥4600.01~5000', 'min' => 4600.01, 'max' => 5000],
        ['label' => '￥5000+',        'min' => 5000.01, 'max' => null],
    ];

    /**
     * 数据统计总览（一次返回卡片/占比/趋势/会员消费/明细表）
     *
     * @param string   $range      today/7days/30days/month/custom
     * @param int|null $customStart custom 起始日（Unix 秒，当天 0 点）
     * @param int|null $customEnd   custom 结束日（Unix 秒，当天 0 点）
     * @param string   $bid        -1 全部 / 0 平台 / >0 指定商户
     * @param string   $freightId  '' 全部 / 配送方式 id
     * @param string   $qus        sales/orders/members
     */
    public function index(int $aid, string $range, ?int $customStart, ?int $customEnd, string $bid, string $freightId, string $qus): array
    {
        [$s, $e] = $this->rangeOf($range, $customStart, $customEnd);
        $byHour  = 'today' === $range;
        $points  = $this->buildPoints($s, $e, $byHour);

        $typeDateArr = [];
        $xszb        = [];
        $sxqsdb      = [];
        $allMids     = [];
        $midSpend    = []; // mid => 累计净额消费

        $totalMoney  = 0.0;
        $totalOrders = 0;

        foreach ($this->availableModules() as $key => $meta) {
            // ---- 汇总：净额销售额/退款额/订单数/退款单数/支付会员 ----
            $row = $this->paidQuery($aid, $meta, $bid, $freightId)
                ->whereBetween('paytime', [$s, $e])
                ->fieldRaw(
                    'COUNT(*) as cnt, '
                    . 'SUM(CASE WHEN refund_money > 0 THEN 1 ELSE 0 END) as refund_cnt, '
                    . 'COALESCE(SUM(totalprice - refund_money),0) as net_money, '
                    . 'COALESCE(SUM(refund_money),0) as refund_money, '
                    . 'COUNT(DISTINCT CASE WHEN refund_money = 0 THEN mid END) as paid_members'
                )
                ->find();

            $cnt         = (int) ($row['cnt'] ?? 0);
            $refundCnt   = (int) ($row['refund_cnt'] ?? 0);
            $netMoney    = round((float) ($row['net_money'] ?? 0), 2);
            $refundMoney = round((float) ($row['refund_money'] ?? 0), 2);
            $orderNum    = $cnt - $refundCnt;
            $kdj         = $orderNum > 0 ? round($netMoney / $orderNum, 2) : 0.0;
            $tkl         = $orderNum > 0 ? round($refundCnt / $orderNum * 100, 2) : 0.0;

            $totalMoney  += $netMoney;
            $totalOrders += $orderNum;

            $typeDateArr[] = [
                'name'           => $meta['name'],
                'total_money'    => $netMoney,
                'order_num'      => $orderNum,
                'member_num'     => (int) ($row['paid_members'] ?? 0),
                'type_kdj'       => $kdj,
                'z_refund_money' => $refundMoney,
                'tkl'            => $tkl,
            ];

            // ---- 全量 mid（含退款单，用于全局去重会员数与消费分桶） ----
            $mids = $this->paidQuery($aid, $meta, $bid, $freightId)
                ->whereBetween('paytime', [$s, $e])
                ->column('mid');
            $allMids = array_merge($allMids, $mids);

            $spendRows = $this->paidQuery($aid, $meta, $bid, $freightId)
                ->whereBetween('paytime', [$s, $e])
                ->fieldRaw('mid, COALESCE(SUM(totalprice - refund_money),0) as spend')
                ->group('mid')
                ->select()
                ->toArray();
            foreach ($spendRows as $sr) {
                $mid = (int) $sr['mid'];
                $midSpend[$mid] = ($midSpend[$mid] ?? 0.0) + (float) $sr['spend'];
            }

            // ---- 销售趋势对比（按 7 个时间点） ----
            $sxqsdb[] = [
                'name' => $meta['name'],
                'type' => 'line',
                'data' => $this->trendValues($aid, $meta, $bid, $freightId, $points, $qus),
            ];
        }

        $totalMoney = round($totalMoney, 2);
        $allMids    = array_values(array_unique(array_map('intval', $allMids)));
        $totalMembers = count($allMids);
        $totalKdj  = $totalOrders > 0 ? round($totalMoney / $totalOrders, 2) : 0.0;

        foreach ($typeDateArr as $t) {
            $xszb[] = [
                'name'    => $t['name'],
                'value'   => $t['total_money'],
                'percent' => $totalMoney > 0 ? round($t['total_money'] / $totalMoney * 100, 2) : 0.0,
            ];
        }

        // ---- 会员消费分桶 ----
        $memberData = [];
        foreach (self::MONEY_BUCKETS as $bucket) {
            $rs = 0; // 人数
            $rf = 0.0; // 累计消费
            foreach ($midSpend as $spend) {
                $lo = $bucket['min'];
                $hi = $bucket['max'] ?? PHP_FLOAT_MAX;
                if ($spend >= $lo && $spend <= $hi) {
                    $rs++;
                    $rf += $spend;
                }
            }
            $memberData[] = [
                'value'            => $rs,
                'zb'               => $totalMembers > 0 ? round($rs / $totalMembers * 100, 2) : 0.0,
                'totalConsumption' => round($rf, 2),
                'rjxf'             => $rs > 0 ? round($rf / $rs, 2) : 0.0,
            ];
        }

        return [
            'total_money'      => $totalMoney,
            'total_order_num'  => $totalOrders,
            'total_member_num' => $totalMembers,
            'total_kdj'        => $totalKdj,
            'xszb'             => $xszb,
            'type_date_arr'    => $typeDateArr,
            'date_arr'         => $points['labels'],
            'sxqsdb'           => $sxqsdb,
            'member_data'      => $memberData,
            'money_arr'        => array_column(self::MONEY_BUCKETS, 'label'),
        ];
    }

    /**
     * 筛选项：商家 + 配送方式
     *
     * @return array{business: array<int, array{id:int,name:string}>, freight: array<int, array{id:int,name:string}>}
     */
    public function options(int $aid): array
    {
        $business = Db::name('business')
            ->where('aid', $aid)
            ->where('status', 1)
            ->field('id, name')
            ->order('id desc')
            ->select()
            ->toArray();

        $freight = Db::name('freight')
            ->where('aid', $aid)
            ->field('id, name')
            ->select()
            ->toArray();

        return [
            'business' => array_map(fn($r) => ['id' => (int) $r['id'], 'name' => (string) $r['name']], $business),
            'freight'  => array_map(fn($r) => ['id' => (int) $r['id'], 'name' => (string) $r['name']], $freight),
        ];
    }

    /* ================= 内部工具 ================= */

    /**
     * 趋势取值（sales 净额销售额 / orders 订单量 / members 支付人数）
     *
     * @param array{labels: array<int,string>, bs: array<int,int>, be: array<int,int>} $points
     * @return array<int, float|int>
     */
    protected function trendValues(int $aid, array $meta, string $bid, string $freightId, array $points, string $qus): array
    {
        $n = count($points['labels']);
        $out = array_fill(0, $n, 0);

        if ('members' === $qus) {
            for ($i = 0; $i < $n; $i++) {
                $row = $this->paidQuery($aid, $meta, $bid, $freightId)
                    ->whereBetween('paytime', [$points['bs'][$i], $points['be'][$i]])
                    ->fieldRaw('COUNT(DISTINCT mid) as c')
                    ->find();
                $out[$i] = (int) ($row['c'] ?? 0);
            }
            return $out;
        }

        $cases = [];
        for ($i = 0; $i < $n; $i++) {
            $bs = $points['bs'][$i];
            $be = $points['be'][$i];
            if ('orders' === $qus) {
                $cases[] = "SUM(CASE WHEN paytime BETWEEN {$bs} AND {$be} THEN 1 ELSE 0 END) as b{$i}";
            } else {
                $cases[] = "SUM(CASE WHEN paytime BETWEEN {$bs} AND {$be} THEN totalprice - refund_money ELSE 0 END) as b{$i}";
            }
        }

        $row = $this->paidQuery($aid, $meta, $bid, $freightId)
            ->fieldRaw(implode(', ', $cases))
            ->find();

        for ($i = 0; $i < $n; $i++) {
            $out[$i] = 'orders' === $qus
                ? (int) ($row['b' . $i] ?? 0)
                : round((float) ($row['b' . $i] ?? 0), 2);
        }

        return $out;
    }

    /**
     * 已支付订单基础查询（每次新建，避免查询对象复用）
     */
    protected function paidQuery(int $aid, array $meta, string $bid, string $freightId): \think\db\Query
    {
        $q = Db::name($meta['table'])
            ->where('aid', $aid)
            ->whereIn('status', [1, 2, 3])
            ->where('paytime', '>', 0);

        if ($meta['delete']) {
            $q->where('delete', 0);
        }

        // 商户：-1 全部 / 0 平台 / >0 指定商户
        $bid = trim($bid);
        if ('0' === $bid) {
            $q->where('bid', 0);
        } elseif ('' !== $bid && '-1' !== $bid) {
            $q->where('bid', (int) $bid);
        }

        // 配送方式（yuyue 不参与）
        if ($meta['freight'] && '' !== $freightId) {
            $q->where('freight_id', (int) $freightId);
        }

        return $q;
    }

    /**
     * 可用模块（表不存在的跳过）
     *
     * @return array<string, array{table:string,name:string,delete:bool,freight:bool}>
     */
    protected function availableModules(): array
    {
        static $cached = null;

        if (null !== $cached) {
            return $cached;
        }

        $cached = [];
        $prefix = Db::getConfig('prefix');

        foreach (self::MODULES as $key => $meta) {
            try {
                $rows = Db::query('SHOW TABLES LIKE \'' . $prefix . $meta['table'] . '\'');
                if (!empty($rows)) {
                    $cached[$key] = $meta;
                }
            } catch (\Throwable) {
                // 表不存在则跳过
            }
        }

        return $cached;
    }

    /**
     * 时间范围解析（对照参考系统）
     *
     * @return array{0: int, 1: int}
     */
    protected function rangeOf(string $range, ?int $customStart, ?int $customEnd): array
    {
        $todayStart = strtotime(date('Y-m-d'));

        switch ($range) {
            case '7days':
                $e = $todayStart + 86399;
                return [$e - 7 * 86400, $e];
            case '30days':
                $e = $todayStart + 86399;
                return [$e - 30 * 86400, $e];
            case 'month':
                return [strtotime(date('Y-m-01')), mktime(23, 59, 59, (int) date('m'), (int) date('t'), (int) date('Y'))];
            case 'custom':
                $s = $customStart ?? $todayStart;
                $e = ($customEnd ?? $todayStart) + 86400 - 1;
                return [$s, $e];
            default:
                return [$todayStart, time()];
        }
    }

    /**
     * 趋势时间点（对照参考系统 getSevenTimePoints：7 个点 6 段）
     *
     * @return array{labels: array<int,string>, bs: array<int,int>, be: array<int,int>}
     */
    protected function buildPoints(int $s, int $e, bool $byHour): array
    {
        $interval = (int) floor(($e - $s) / 6);
        $points = [];
        for ($i = 0; $i < 7; $i++) {
            $points[$i] = $s + $i * $interval;
        }

        $labels = [];
        $bs = [];
        $be = [];

        for ($i = 0; $i < 7; $i++) {
            if ($byHour) {
                $labels[$i] = date('H:i', $points[$i]);
                $bs[$i] = 0 === $i ? $points[0] : $points[$i - 1];
                $be[$i] = strtotime(date('Y-m-d', $s) . ' ' . date('H:i', $points[$i]) . ':59');
            } else {
                $labels[$i] = date('Y-m-d', $points[$i]);
                $prevDay = 0 === $i ? $points[0] : $points[$i - 1];
                $bs[$i] = strtotime(date('Y-m-d', $prevDay) . ' 00:00:00');
                $be[$i] = strtotime(date('Y-m-d', $points[$i]) . ' 23:59:59');
            }
        }

        return ['labels' => $labels, 'bs' => $bs, 'be' => $be];
    }
}
