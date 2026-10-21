<?php

declare(strict_types=1);

namespace app\common\service;

use think\facade\Db;

/**
 * 收银台演示数据生成器（扩展·收银台：收银订单全状态 + 交班打印机）
 *
 * 口径对齐点大收银订单列表：
 * - status 0待支付 1已支付 2挂单 4已关闭 10已退款；refund_status 0无 1已退款
 * - 支付方式 paytypeid：1微信 3现金 4余额
 * - 订单商品行含 proid2/ggid，供订单统计「按商品/按规格」聚合
 * - 打印机 wifiprint_set machine_type=0 status=1，供收银设置交班打印机/订单打印小票
 *
 * 幂等：ordernum 前缀 DEMO-CS-，重复执行不重复生成；--purge 清除。
 */
class DemoCashierGenerator
{
    protected int $aid = 1;

    public const PREFIX = 'DEMO-CS-';

    public function setAid(int $aid): void
    {
        $this->aid = $aid > 0 ? $aid : 1;
    }

    public function purge(): void
    {
        $orderIds = Db::name('cashier_order')
            ->where('aid', $this->aid)->whereLike('ordernum', self::PREFIX . '%')
            ->column('id');
        if (!empty($orderIds)) {
            Db::name('cashier_order_goods')->where('orderid', 'in', $orderIds)->delete();
            Db::name('cashier_order')->where('id', 'in', $orderIds)->delete();
        }
        Db::name('wifiprint_set')
            ->where('aid', $this->aid)->whereLike('name', self::PREFIX . '%')
            ->delete();
    }

    public function generate(): void
    {
        // 已生成则跳过（幂等）
        $exists = Db::name('cashier_order')
            ->where('aid', $this->aid)->whereLike('ordernum', self::PREFIX . '%')
            ->count();
        if ($exists > 0) {
            return;
        }

        /* ---------- 交班打印机（小票打印机 machine_type=0 启用） ---------- */
        Db::name('wifiprint_set')->insert([
            'aid'          => $this->aid,
            'bid'          => 0,
            'name'         => self::PREFIX . '前台打印机',
            'machine_type' => 0,
            'status'       => 1,
            'type'         => 1,
            'client_id'    => 'demo-client-id',
            'client_secret' => 'demo-secret',
            'machine_code' => 'DEMO001',
            'print_cashdesk' => 1,
            'createtime'   => time(),
        ]);

        /* ---------- 商品素材（真实商品，供统计聚合与退款回库存） ---------- */
        $products = Db::name('shop_product')->where('aid', $this->aid)
            ->field('id,name,pic,sell_price,cost_price')->limit(4)->select()->toArray();
        if (empty($products)) {
            $products = [
                ['id' => 0, 'name' => '直接收款', 'pic' => '', 'sell_price' => 0.01, 'cost_price' => 0],
            ];
        }
        $guiges = Db::name('shop_guige')->where('aid', $this->aid)
            ->field('id,name,sell_price,cost_price')->limit(4)->select()->toArray();

        $member = Db::name('member')->where('aid', $this->aid)->field('id')->find();
        $mid = (int) ($member['id'] ?? 0);

        $now = time();

        // 订单模板：[status, paytypeid, paytype, 商品行数, 备注]
        $cases = [
            [1, 3, '现金', 2, ''],
            [1, 1, '微信扫码', 1, ''],
            [1, 4, '余额', 2, ''],
            [2, 0, '', 1, '挂单待取'],
            [10, 3, '现金', 1, ''],
            [0, 0, '', 1, ''],
            [1, 3, '现金', 3, ''],
            [4, 0, '', 1, ''],
        ];

        foreach ($cases as $i => $case) {
            [$status, $paytypeid, $paytype, $goodsCount, $remark] = $case;

            $ordernum = self::PREFIX . date('YmdHis', $now - $i * 86400) . str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT);
            $createtime = $now - $i * 86400;
            $paid = in_array($status, [1, 10], true);

            // 商品行
            $total = 0.0;
            $preTotal = 0.0;
            $goodsRows = [];
            for ($g = 0; $g < $goodsCount; $g++) {
                $p = $products[($i + $g) % count($products)];
                $num = ($g % 3) + 1;
                $sell = (float) ($p['sell_price'] > 0 ? $p['sell_price'] : 10 + $g * 5);
                $cost = (float) ($p['cost_price'] ?? 0);
                $lineTotal = round($sell * $num, 2);
                $total = round($total + $lineTotal, 2);
                $preTotal = round($preTotal + $lineTotal, 2);

                $guige = null;
                if (!empty($guiges) && ($i + $g) % 2 === 0) {
                    $guige = $guiges[($i + $g) % count($guiges)];
                }

                $goodsRows[] = [
                    'aid'        => $this->aid,
                    'bid'        => 0,
                    'mid'        => $mid,
                    'orderid'    => 0, // 订单插入后回填
                    'ordernum'   => $ordernum,
                    'proid'      => (string) ($p['id'] ?? 0),
                    'proid2'     => (string) ($p['id'] ?? 0),
                    'proname'    => (string) $p['name'],
                    'propic'     => (string) ($p['pic'] ?? ''),
                    'protype'    => 1,
                    'sell_price' => $sell,
                    'cost_price' => $cost,
                    'num'        => $num,
                    'totalprice' => $lineTotal,
                    'real_totalprice' => $lineTotal,
                    'pre_price'  => $sell,
                    'createtime' => $createtime,
                    'ggid'       => $guige ? (int) $guige['id'] : 0,
                    'ggname'     => $guige ? (string) $guige['name'] : '',
                    'barcode'    => '',
                    'is_gj'      => 0,
                ];
            }

            $refund = 10 === $status;
            $orderId = Db::name('cashier_order')->insertGetId([
                'aid'          => $this->aid,
                'bid'          => 0,
                'ordernum'     => $ordernum,
                'status'       => $status,
                'remark'       => $remark,
                'cashier_id'   => 0,
                'mid'          => $mid,
                'payorderid'   => 0,
                'paytypeid'    => $paytypeid,
                'paytype'      => $paytype,
                'paynum'       => '',
                'paytime'      => $paid ? $createtime + 60 : null,
                'totalprice'   => $total,
                'pre_totalprice' => $preTotal,
                'coupon_money' => 0,
                'coupon_rid'   => 0,
                'scoredk_money' => 0,
                'leveldk_money' => 0,
                'moling_money' => 0,
                'remove_zero'  => 0,
                'remove_zero_length' => 0,
                'uid'          => 0,
                'hangup_time'  => 2 === $status ? $createtime : null,
                'refund_reason' => $refund ? '顾客退货' : null,
                'refund_money' => $refund ? $total : 0,
                'refund_status' => $refund ? 1 : 0,
                'refund_time'  => $refund ? $createtime + 3600 : null,
                'createtime'   => $createtime,
            ]);

            foreach ($goodsRows as $row) {
                $row['orderid'] = $orderId;
                Db::name('cashier_order_goods')->insert($row);
            }
        }
    }
}
