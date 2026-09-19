<?php

declare(strict_types=1);

namespace app\common\service;

use think\facade\Db;

/**
 * 租户统计服务（首页面板 + 趋势 + 销售统计）
 *
 * 口径约定：
 * - 已支付订单口径：shop_order.status in (1,2,3) 且 delete=0
 * - 收款/销售额按 paytime 归属日期，今日0点 = strtotime(date('Y-m-d'))
 * - 退款单内部语义（见 RefundService）：2=申请中 1=已退款 0=已驳回
 *   待退款数 = refund_status=2；已退金额 = refund_status=1 的 refund_money 合计
 * - 聚合一律单次 GROUP BY，不逐期循环查询
 */
class StatService
{
    /** 已支付订单状态集（待发货/已发货/已收货） */
    private const PAID_STATUS = [1, 2, 3];

    /**
     * 首页面板汇总数据
     */
    public function dashboard(int $aid): array
    {
        $todayStart = strtotime(date('Y-m-d'));
        $monthStart = strtotime(date('Y-m-01'));

        // ---- 订单状态计数（单次 GROUP BY） ----
        $statusRows = ScopeService::query('shop_order', $aid)
            ->where('delete', 0)
            ->field('status, count(*) as cnt')
            ->group('status')
            ->select()
            ->toArray();

        $statusCount = [0 => 0, 1 => 0, 2 => 0, 3 => 0, 4 => 0];
        foreach ($statusRows as $row) {
            $statusCount[(int) $row['status']] = (int) $row['cnt'];
        }

        // ---- 待退款数（申请中，refund_status=2） ----
        $waitRefund = ScopeService::query('shop_refund_order', $aid)
            ->where('deleted', 0)
            ->where('refund_status', 2)
            ->count();

        // ---- 已退款金额合计（refund_status=1） ----
        $refundMoney = (float) ScopeService::query('shop_refund_order', $aid)
            ->where('deleted', 0)
            ->where('refund_status', 1)
            ->sum('refund_money');

        // ---- 收款/订单数：今日/本月/累计（单次条件聚合） ----
        $paid = ScopeService::query('shop_order', $aid)
            ->where('delete', 0)
            ->whereIn('status', self::PAID_STATUS)
            ->where('paytime', '>', 0)
            ->field(
                'coalesce(sum(case when paytime >= ' . $todayStart . ' then totalprice else 0 end), 0) as today_money, '
                . 'sum(case when paytime >= ' . $todayStart . ' then 1 else 0 end) as today_orders, '
                . 'coalesce(sum(case when paytime >= ' . $monthStart . ' then totalprice else 0 end), 0) as month_money, '
                . 'sum(case when paytime >= ' . $monthStart . ' then 1 else 0 end) as month_orders, '
                . 'coalesce(sum(totalprice), 0) as total_money, '
                . 'count(*) as total_orders'
            )
            ->find();

        // ---- 会员：总数 / 今日新增 / 本月新增（单次条件聚合） ----
        $member = ScopeService::query('member', $aid)
            ->field(
                'count(*) as total, '
                . 'sum(case when createtime >= ' . $todayStart . ' then 1 else 0 end) as today_new, '
                . 'sum(case when createtime >= ' . $monthStart . ' then 1 else 0 end) as month_new, '
                . 'coalesce(sum(money), 0) as balance_total, '
                . 'coalesce(sum(buymoney), 0) as spend_total'
            )
            ->find();

        // ---- 充值总额（recharge_order 已支付 status=1） ----
        $rechargeTotal = (float) ScopeService::query('recharge_order', $aid)
            ->where('status', 1)
            ->sum('money');

        return [
            // 订单状态计数
            'wait_pay'     => $statusCount[0],
            'wait_ship'    => $statusCount[1],
            'shipped'      => $statusCount[2],
            'received'     => $statusCount[3],
            'wait_refund'  => $waitRefund,
            // 收款与订单（今日/本月/累计）
            'today_money'  => round((float) ($paid['today_money'] ?? 0), 2),
            'today_orders' => (int) ($paid['today_orders'] ?? 0),
            'month_money'  => round((float) ($paid['month_money'] ?? 0), 2),
            'month_orders' => (int) ($paid['month_orders'] ?? 0),
            'total_money'  => round((float) ($paid['total_money'] ?? 0), 2),
            'total_orders' => (int) ($paid['total_orders'] ?? 0),
            // 会员
            'member_total'  => (int) ($member['total'] ?? 0),
            'member_today'  => (int) ($member['today_new'] ?? 0),
            'member_month'  => (int) ($member['month_new'] ?? 0),
            'balance_total' => round((float) ($member['balance_total'] ?? 0), 2),
            'spend_total'   => round((float) ($member['spend_total'] ?? 0), 2),
            // 充值与退款
            'recharge_total' => round($rechargeTotal, 2),
            'refund_money'   => round($refundMoney, 2),
        ];
    }

    /**
     * 近 N 天趋势（按日聚合：订单金额 / 订单数 / 新增会员）
     *
     * @return array{days: int, list: array<int, array{date: string, money: float, orders: int, members: int}>}
     */
    public function trend(int $aid, int $days): array
    {
        $days = max(7, min(90, $days));

        $start = strtotime(date('Y-m-d', strtotime('-' . ($days - 1) . ' days')));

        // 订单按支付日聚合（单次 GROUP BY）
        $orderRows = ScopeService::query('shop_order', $aid)
            ->where('delete', 0)
            ->whereIn('status', self::PAID_STATUS)
            ->where('paytime', '>=', $start)
            ->fieldRaw("FROM_UNIXTIME(paytime, '%Y-%m-%d') as d, coalesce(sum(totalprice), 0) as money, count(*) as orders")
            ->group('d')
            ->select()
            ->toArray();

        $orderMap = [];
        foreach ($orderRows as $row) {
            $orderMap[(string) $row['d']] = $row;
        }

        // 会员按注册日聚合（单次 GROUP BY）
        $memberRows = ScopeService::query('member', $aid)
            ->where('createtime', '>=', $start)
            ->fieldRaw("FROM_UNIXTIME(createtime, '%Y-%m-%d') as d, count(*) as members")
            ->group('d')
            ->select()
            ->toArray();

        $memberMap = [];
        foreach ($memberRows as $row) {
            $memberMap[(string) $row['d']] = (int) $row['members'];
        }

        // PHP 侧补齐无数据日期（仅拼数组，不发查询）
        $list = [];
        for ($ts = $start; $ts <= time(); $ts = strtotime('+1 day', $ts)) {
            $date     = date('Y-m-d', $ts);
            $order    = $orderMap[$date] ?? null;
            $list[]   = [
                'date'    => $date,
                'money'   => round((float) ($order['money'] ?? 0), 2),
                'orders'  => (int) ($order['orders'] ?? 0),
                'members' => $memberMap[$date] ?? 0,
            ];
        }

        return ['days' => $days, 'list' => $list];
    }

    /**
     * 销售统计（年→月→日 三级钻取，单次 GROUP BY 聚合）
     *
     * - 不传 month：按年聚合 12 个月（%Y-%m）
     * - 传 month 不传 day：按月聚合每日（%Y-%m-%d）
     * - 传 day：按日聚合 24 小时（%H）
     * - tjtype：1=成交额 sum(totalprice) 2=成交量 count
     */
    /**
     * 商品销售统计（对齐参考系统 ShopOrder::tongji 的按商品/按规格口径）
     *
     * 数据源：shop_order_goods og INNER JOIN shop_order o ON o.id=og.orderid
     * 仅统计已支付订单 o.status in (1,2,3) 且未删除；退款审核完成（本项目 refund_status=1）的部分从销量/金额/成本中扣减。
     *
     * @param array $p type=2 按规格(ggid)，否则按商品(proid)；page/limit；field/order 排序；
     *                mdid/freight_id/mid/proname；ctime_start/ctime_end(按下单 og.createtime)；
     *                paytime_start/paytime_end(按付款 o.paytime)，时间均为 Unix 秒
     * @return array{list: array<int, array<string, mixed>>, total: int, totaldata: array<string, float|int>}
     */
    public function goodsSales(int $aid, array $p): array
    {
        $byGuige  = (int) ($p['type'] ?? 0) === 2;
        $groupCol = $byGuige ? 'ggid' : 'proid';

        $page  = max(1, (int) ($p['page'] ?? 1));
        $limit = min(100, max(1, (int) ($p['limit'] ?? 15)));
        // 导出：一次性取全量（对齐参考系统 excel=1 时大分页）
        if ((int) ($p['export'] ?? 0) === 1) {
            $page  = 1;
            $limit = 100000;
        }

        // 排序白名单（防注入）：仅允许聚合列别名
        $sortMap = [
            'num'       => 'num',
            'totalprice' => 'totalprice',
            'avgprice'  => 'avgprice',
            'chengben'  => 'chengben',
            'lirun'     => 'lirun',
        ];
        $sortField = $sortMap[(string) ($p['field'] ?? '')] ?? 'totalprice';
        $sortDir   = strtolower((string) ($p['order'] ?? '')) === 'asc' ? 'asc' : 'desc';

        // 统一筛选条件（$g 为商品明细别名：主查询 og / 退款 rog）
        $buildWhere = function ($q, string $g) use ($aid, $p): void {
            $q->where("{$g}.aid", $aid)
                ->whereIn('shop_order.status', self::PAID_STATUS)
                ->where('shop_order.delete', 0);

            if (!empty($p['mdid'])) {
                $q->where('shop_order.mdid', (int) $p['mdid']);
            }
            if (isset($p['freight_id']) && '' !== $p['freight_id']) {
                $q->where('shop_order.freight_id', (int) $p['freight_id']);
            }
            if (!empty($p['mid'])) {
                $q->where("{$g}.mid", (int) $p['mid']);
            }
            if (!empty($p['proname'])) {
                $q->where("{$g}.name", 'like', '%' . $p['proname'] . '%');
            }
            if (!empty($p['ctime_start'])) {
                $q->where("{$g}.createtime", '>=', (int) $p['ctime_start']);
            }
            if (!empty($p['ctime_end'])) {
                $q->where("{$g}.createtime", '<=', (int) $p['ctime_end']);
            }
            if (!empty($p['paytime_start'])) {
                $q->where('shop_order.paytime', '>=', (int) $p['paytime_start']);
            }
            if (!empty($p['paytime_end'])) {
                $q->where('shop_order.paytime', '<=', (int) $p['paytime_end']);
            }
        };

        $aggFields =
            "MAX(og.proid) as proid, MAX(og.ggid) as ggid, MAX(og.name) as name, MAX(og.pic) as pic, "
            . "MAX(og.ggname) as ggname, COALESCE(SUM(og.num),0) as num, "
            . "COALESCE(SUM(og.totalprice),0) as totalprice, "
            . "COALESCE(SUM(og.totalprice)/NULLIF(SUM(og.num),0),0) as avgprice, "
            . "COALESCE(SUM(og.cost_price*og.num),0) as chengben, "
            . "COALESCE(SUM(og.totalprice-og.cost_price*og.num),0) as lirun";

        // 分组总数（用分组子查询计数，与明细分页口径完全一致，正确处理 ggid/proid 为 NULL 的组）
        $groupSub = Db::name('shop_order_goods')->alias('og')
            ->join('shop_order', 'shop_order.id = og.orderid', 'INNER')
            ->where(function ($q) use ($buildWhere): void {
                $buildWhere($q, 'og');
            })
            ->fieldRaw("og.{$groupCol}")
            ->group("og.{$groupCol}")
            ->buildSql();
        $total = (int) Db::table($groupSub . ' g')->count();

        // 明细分页
        $rows = Db::name('shop_order_goods')->alias('og')
            ->join('shop_order', 'shop_order.id = og.orderid', 'INNER')
            ->where(function ($q) use ($buildWhere): void {
                $buildWhere($q, 'og');
            })
            ->fieldRaw($aggFields)
            ->group("og.{$groupCol}")
            ->orderRaw("{$sortField} {$sortDir}")
            ->page($page, $limit)
            ->select()
            ->toArray();

        // 全量汇总（无分组）
        $agg = Db::name('shop_order_goods')->alias('og')
            ->join('shop_order', 'shop_order.id = og.orderid', 'INNER')
            ->where(function ($q) use ($buildWhere): void {
                $buildWhere($q, 'og');
            })
            ->fieldRaw($aggFields)
            ->find();

        // 已完成退款扣减（refund_status=1 为本项目“已退款”口径），按分组键一次性聚合
        $refundRows = Db::name('shop_refund_order_goods')->alias('rog')
            ->join('shop_order', 'shop_order.id = rog.orderid', 'INNER')
            ->where(function ($q) use ($buildWhere): void {
                $buildWhere($q, 'rog');
            })
            ->where('shop_order.refund_status', 1)
            ->fieldRaw(
                "rog.{$groupCol} as gk, COALESCE(SUM(rog.refund_num),0) as rn, "
                . "COALESCE(SUM(rog.refund_money),0) as rm, COALESCE(SUM(rog.cost_price*rog.refund_num),0) as rc"
            )
            ->group("rog.{$groupCol}")
            ->select()
            ->toArray();

        $refundMap = [];
        foreach ($refundRows as $rf) {
            $refundMap[(string) $rf['gk']] = $rf;
        }

        // 全量退款合计
        $refundAll = Db::name('shop_refund_order_goods')->alias('rog')
            ->join('shop_order', 'shop_order.id = rog.orderid', 'INNER')
            ->where(function ($q) use ($buildWhere): void {
                $buildWhere($q, 'rog');
            })
            ->where('shop_order.refund_status', 1)
            ->fieldRaw(
                'COALESCE(SUM(rog.refund_num),0) as rn, COALESCE(SUM(rog.refund_money),0) as rm, '
                . 'COALESCE(SUM(rog.cost_price*rog.refund_num),0) as rc'
            )
            ->find();

        $list = [];
        foreach ($rows as $k => $v) {
            $rf = $refundMap[(string) $v[$groupCol]] ?? null;

            $num        = (int) $v['num'] - (int) ($rf['rn'] ?? 0);
            $totalprice = round((float) $v['totalprice'] - (float) ($rf['rm'] ?? 0), 2);
            $chengben   = round((float) $v['chengben'] - (float) ($rf['rc'] ?? 0), 2);
            $lirun      = round($totalprice - $chengben, 2);
            $avgprice   = $num > 0 ? round($totalprice / $num, 2) : round($totalprice, 2);

            $list[] = [
                'ph'         => ($page - 1) * $limit + $k + 1,
                'proid'      => (int) $v['proid'],
                'ggid'       => (int) $v['ggid'],
                'name'       => (string) $v['name'],
                'pic'        => (string) $v['pic'],
                'ggname'     => (string) $v['ggname'],
                'num'        => $num,
                'totalprice' => $totalprice,
                'avgprice'   => $avgprice,
                'chengben'   => $chengben,
                'lirun'      => $lirun,
            ];
        }

        $totalNum  = (int) ($agg['num'] ?? 0) - (int) ($refundAll['rn'] ?? 0);
        $totalMoney = round((float) ($agg['totalprice'] ?? 0) - (float) ($refundAll['rm'] ?? 0), 2);
        $totalCost  = round((float) ($agg['chengben'] ?? 0) - (float) ($refundAll['rc'] ?? 0), 2);

        return [
            'list'  => $list,
            'total' => $total,
            'totaldata' => [
                'num'        => $totalNum,
                'totalprice' => $totalMoney,
                'chengben'   => $totalCost,
                'lirun'      => round($totalMoney - $totalCost, 2),
            ],
        ];
    }

    public function sales(int $aid, int $year, int $month, int $day, int $tjtype): array
    {
        $nowYear = (int) date('Y');
        $year    = ($year >= 2000 && $year <= 2100) ? $year : $nowYear;
        $tjtype  = 2 === $tjtype ? 2 : 1;

        if ($month < 1 || $month > 12) {
            // ---- 年级：12 个月 ----
            $level = 'year';
            $month = 0;
            $day   = 0;
            $from  = strtotime(sprintf('%04d-01-01', $year));
            $to    = strtotime(sprintf('%04d-01-01', $year + 1));
            $fmt   = '%Y-%m';

            $labels = [];
            for ($m = 1; $m <= 12; $m++) {
                $labels[] = sprintf('%04d-%02d', $year, $m);
            }
        } elseif ($day < 1) {
            // ---- 月级：当月每日 ----
            $level     = 'month';
            $day       = 0;
            $monthFrom = strtotime(sprintf('%04d-%02d-01', $year, $month));
            $from      = $monthFrom;
            $to        = strtotime(date('Y-m-01', strtotime('+1 month', $monthFrom)));
            $fmt       = '%Y-%m-%d';

            $dayNum = (int) date('t', $monthFrom);
            $labels = [];
            for ($d = 1; $d <= $dayNum; $d++) {
                $labels[] = sprintf('%04d-%02d-%02d', $year, $month, $d);
            }
        } else {
            // ---- 日级：24 小时 ----
            $level = 'day';
            $day   = min($day, (int) date('t', strtotime(sprintf('%04d-%02d-01', $year, $month))));
            $from  = strtotime(sprintf('%04d-%02d-%02d', $year, $month, $day));
            $to    = strtotime(date('Y-m-d', $from) . ' +1 day');
            $fmt   = '%H';

            $labels = [];
            for ($h = 0; $h < 24; $h++) {
                $labels[] = sprintf('%02d', $h);
            }
        }

        $valExpr = 2 === $tjtype ? 'count(*)' : 'coalesce(sum(totalprice), 0)';

        $rows = ScopeService::query('shop_order', $aid)
            ->where('delete', 0)
            ->whereIn('status', self::PAID_STATUS)
            ->where('paytime', '>=', $from)
            ->where('paytime', '<', $to)
            ->fieldRaw("FROM_UNIXTIME(paytime, '{$fmt}') as period, {$valExpr} as val")
            ->group('period')
            ->select()
            ->toArray();

        $valMap = [];
        foreach ($rows as $row) {
            $valMap[(string) $row['period']] = (float) $row['val'];
        }

        $list     = [];
        $totalVal = 0.0;
        $maxVal   = 0.0;
        $maxDate  = '';

        foreach ($labels as $label) {
            $val      = $valMap[$label] ?? 0.0;
            $totalVal += $val;

            if ($val > $maxVal) {
                $maxVal  = $val;
                $maxDate = $label;
            }

            $list[] = ['label' => $label, 'val' => $val];
        }

        // 占比（合计为 0 时占比归 0）
        foreach ($list as &$item) {
            $item['val']     = 2 === $tjtype ? (int) $item['val'] : round($item['val'], 2);
            $item['percent'] = $totalVal > 0 ? round($item['val'] / $totalVal * 100, 2) : 0.0;
        }
        unset($item);

        return [
            'level'    => $level,
            'tjtype'   => $tjtype,
            'year'     => $year,
            'month'    => $month,
            'day'      => $day,
            'list'     => $list,
            'totalval' => 2 === $tjtype ? (int) $totalVal : round($totalVal, 2),
            'maxval'   => 2 === $tjtype ? (int) $maxVal : round($maxVal, 2),
            'maxdate'  => $maxDate,
        ];
    }
}
