<?php

declare(strict_types=1);

namespace app\common\service;

use think\facade\Db;

/**
 * 营销数据统计服务（批次4-E 任务190 E2-6）
 *
 * 口径对照参考系统 yingxiao/DataStatistics：
 * - 六模块订单表聚合：shop/collage/kanjia/seckill/tuangou/yuyue（表不存在的跳过）
 * - 已支付口径：status in (1,2,3) 且 paytime>0，delete=0（有 delete 列的表）
 * - 销售占比/订单额/客单价、金额分布档位（11档）、时间趋势（今日按小时/其余按天）、
 *   时间范围：today / 7days / 30days / month
 */
class MarketingStatService
{
    /** 模块 → 订单表（name 展示名；delete 是否有 delete 列） */
    private const MODULES = [
        'shop'    => ['table' => 'shop_order', 'name' => '商城', 'delete' => true],
        'collage' => ['table' => 'collage_order', 'name' => '多人拼团', 'delete' => true],
        'kanjia'  => ['table' => 'kanjia_order', 'name' => '砍价', 'delete' => true],
        'seckill' => ['table' => 'seckill_order', 'name' => '整点秒杀', 'delete' => true],
        'tuangou' => ['table' => 'tuangou_order', 'name' => '团购', 'delete' => true],
        'yuyue'   => ['table' => 'yuyue_order', 'name' => '预约', 'delete' => false],
    ];

    /** 金额分布档位（对照参考系统） */
    private const MONEY_BUCKETS = [
        ['label' => '￥0.01~100', 'min' => 0.01, 'max' => 100],
        ['label' => '￥100.01~400', 'min' => 100.01, 'max' => 400],
        ['label' => '￥400.01~700', 'min' => 400.01, 'max' => 700],
        ['label' => '￥700.01~1000', 'min' => 700.01, 'max' => 1000],
        ['label' => '￥1000.01~1300', 'min' => 1000.01, 'max' => 1300],
        ['label' => '￥1300.01~1900', 'min' => 1300.01, 'max' => 1900],
        ['label' => '￥1900.01~2900', 'min' => 1900.01, 'max' => 2900],
        ['label' => '￥2900.01~3600', 'min' => 2900.01, 'max' => 3600],
        ['label' => '￥3600.01~4600', 'min' => 3600.01, 'max' => 4600],
        ['label' => '￥4600.01~5000', 'min' => 4600.01, 'max' => 5000],
        ['label' => '￥5000+', 'min' => 5000.01, 'max' => null],
    ];

    /**
     * 各模块销售汇总（订单额/订单数/占比/客单价）
     *
     * @return array{modules: array<int, array<string, mixed>>, total_money: float, total_num: int}
     */
    public function summary(int $aid, string $range): array
    {
        [$start, $end] = $this->rangeOf($range);

        $modules   = [];
        $totalMoney = 0.0;
        $totalNum   = 0;

        foreach ($this->availableModules() as $key => $meta) {
            $row = $this->baseQuery($aid, $key)
                ->whereBetween('paytime', [$start, $end])
                ->field('COUNT(*) as num, IFNULL(SUM(totalprice),0) as money')
                ->find();

            $money = round((float) ($row['money'] ?? 0), 2);
            $num   = (int) ($row['num'] ?? 0);

            $modules[] = [
                'key'    => $key,
                'name'   => $meta['name'],
                'money'  => $money,
                'num'    => $num,
                'kedan'  => $num > 0 ? round($money / $num, 2) : 0.0,
                'percent' => 0.0,
            ];

            $totalMoney += $money;
            $totalNum   += $num;
        }

        foreach ($modules as &$module) {
            $module['percent'] = $totalMoney > 0 ? round($module['money'] / $totalMoney * 100, 2) : 0.0;
        }

        unset($module);

        return [
            'modules'     => $modules,
            'total_money' => round($totalMoney, 2),
            'total_num'   => $totalNum,
            'total_kedan' => $totalNum > 0 ? round($totalMoney / $totalNum, 2) : 0.0,
        ];
    }

    /**
     * 金额分布档位（全模块合计订单数）
     *
     * @return array{labels: array<int, string>, values: array<int, int>}
     */
    public function moneyDist(int $aid, string $range): array
    {
        [$start, $end] = $this->rangeOf($range);

        $totals = array_fill(0, count(self::MONEY_BUCKETS), 0);

        foreach ($this->availableModules() as $key => $meta) {
            $cases = [];

            foreach (self::MONEY_BUCKETS as $i => $bucket) {
                $max = $bucket['max'];
                $cond = null === $max
                    ? 'totalprice >= ' . $bucket['min']
                    : 'totalprice >= ' . $bucket['min'] . ' AND totalprice <= ' . $max;

                $cases[] = 'SUM(CASE WHEN ' . $cond . ' THEN 1 ELSE 0 END) as b' . $i;
            }

            $row = $this->baseQuery($aid, $key)
                ->whereBetween('paytime', [$start, $end])
                ->fieldRaw(implode(', ', $cases))
                ->find();

            foreach (self::MONEY_BUCKETS as $i => $bucket) {
                $totals[$i] += (int) ($row['b' . $i] ?? 0);
            }
        }

        return [
            'labels' => array_column(self::MONEY_BUCKETS, 'label'),
            'values' => $totals,
        ];
    }

    /**
     * 时间趋势（today 按小时 24 点；7days/30days/month 按天）
     *
     * @return array{labels: array<int, string>, series: array<string, array<string, mixed>>, total: array<int, float>}
     */
    public function trend(int $aid, string $range): array
    {
        [$start, $end] = $this->rangeOf($range);

        $byHour = 'today' === $range;
        $fmt    = $byHour ? '%H' : '%Y-%m-%d';

        // 生成完整标签轴
        $labels = [];

        if ($byHour) {
            for ($h = 0; $h < 24; $h++) {
                $labels[] = str_pad((string) $h, 2, '0', STR_PAD_LEFT) . ':00';
            }
        } else {
            for ($t = $start; $t <= $end; $t += 86400) {
                $labels[] = date('Y-m-d', $t);
            }
        }

        $series = [];
        $total  = array_fill(0, count($labels), 0.0);

        foreach ($this->availableModules() as $key => $meta) {
            $rows = $this->baseQuery($aid, $key)
                ->whereBetween('paytime', [$start, $end])
                ->fieldRaw("DATE_FORMAT(FROM_UNIXTIME(paytime), '{$fmt}') as bucket, IFNULL(SUM(totalprice),0) as money")
                ->group('bucket')
                ->select()
                ->toArray();

            $map = [];

            foreach ($rows as $row) {
                $map[(string) $row['bucket']] = round((float) $row['money'], 2);
            }

            $values = [];

            foreach ($labels as $i => $label) {
                $key2 = $byHour ? substr($label, 0, 2) : $label;
                $v    = (float) ($map[$key2] ?? 0);

                $values[] = $v;
                $total[$i] = round($total[$i] + $v, 2);
            }

            $series[$key] = ['name' => $meta['name'], 'data' => $values];
        }

        return ['labels' => $labels, 'series' => $series, 'total' => $total];
    }

    /* ================= 内部工具 ================= */

    /**
     * 可用模块（表不存在的跳过）
     *
     * @return array<string, array{table: string, name: string, delete: bool}>
     */
    protected function availableModules(): array
    {
        static $cached = null;

        if (null !== $cached) {
            return $cached;
        }

        $cached = [];

        foreach (self::MODULES as $key => $meta) {
            try {
                $rows = Db::query('SHOW TABLES LIKE \'' . Db::getConfig('prefix') . $meta['table'] . '\'');

                if (!empty($rows)) {
                    $cached[$key] = $meta;
                }
            } catch (\Throwable) {
                // 表不存在则跳过该模块
            }
        }

        return $cached;
    }

    /**
     * 基础查询（aid + 已支付口径 + delete 过滤）
     */
    protected function baseQuery(int $aid, string $module): \think\db\Query
    {
        $meta  = self::MODULES[$module];
        $query = Db::name($meta['table'])
            ->where('aid', $aid)
            ->whereIn('status', [1, 2, 3])
            ->where('paytime', '>', 0);

        if ($meta['delete']) {
            $query->where('delete', 0);
        }

        return $query;
    }

    /**
     * 时间范围解析
     *
     * @return array{0: int, 1: int}
     */
    protected function rangeOf(string $range): array
    {
        $todayStart = strtotime(date('Y-m-d'));

        return match ($range) {
            '7days'  => [$todayStart - 6 * 86400, time()],
            '30days' => [$todayStart - 29 * 86400, time()],
            'month'  => [strtotime(date('Y-m-01')), time()],
            default  => [$todayStart, time()],
        };
    }
}
