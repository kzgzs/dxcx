<?php

declare(strict_types=1);

namespace app\common\service;

use think\facade\Db;

/**
 * 砍价活动演示数据生成器（与运行方式解耦，命令 demo:kanjia 共用同一份逻辑）。
 *
 * 生成目标：让租户后台「客户端·营销 → 砍价活动 → 订单管理」四种状态行操作按钮差异可见：
 *  - 已支付（status=1）：到店自提单显「核销」；快递单显「发货」
 *  - 已发货（status=2）：显「查物流」（含快递单号/物流公司/发货时间）
 *  - 已关闭（status=4）：仅「查看详情 / 删除订单」
 *  - 已完成（status=3）：显「查物流」（含核销/收货时间 collect_time）
 *
 * 幂等：订单号统一以 DEMO-KJ- 前缀（清理标识），已存在则跳过；--reset 先删后建；--purge 仅删。
 * 商品复用已存在的【示例】砍价商品（由 demo:products 生成），不重复造商品。
 */
class DemoKanjiaGenerator
{
    /** 演示订单号前缀（同时作为幂等与清理标识） */
    public const ORDER_PREFIX = 'DEMO-KJ-';

    private int $aid = 1;
    private bool $reset = false;
    private array $logs = [];

    /**
     * 生成演示订单。
     *
     * @return array{aid:int, created:int, skipped:int, logs:array<int,string>}
     */
    public function generate(int $aid, bool $reset = false): array
    {
        $this->aid   = max(1, $aid);
        $this->reset = $reset;
        $this->logs  = [];

        $this->log('<info>==== 砍价演示订单生成开始（aid=' . $this->aid . ($this->reset ? '，--reset 重建' : '') . '）====</info>');

        if (empty(Db::name('admin')->where('id', $this->aid)->find())) {
            $this->log('<error>平台租户 admin.id=' . $this->aid . ' 不存在。</error>');
            return $this->result(0, 0);
        }

        // --reset：先清理旧演示订单
        if ($this->reset) {
            $this->purge($this->aid);
            $this->log('  已清理旧演示订单');
        }

        // 选取两个【示例】砍价商品（平台自营 bid=0 优先）
        $products = Db::name('kanjia_product')
            ->where('aid', $this->aid)
            ->whereLike('name', '【示例】%')
            ->order('bid', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        if (empty($products)) {
            $this->log('<error>未找到【示例】砍价商品，请先执行 php think demo:products 生成示例商品。</error>');
            return $this->result(0, 0);
        }

        $pFreight = $products[0]; // 快递单商品
        $pPickup  = $products[1] ?? $products[0]; // 自提单商品

        // 取一个可用会员
        $mid = (int) Db::name('member')->where('aid', $this->aid)->order('id', 'asc')->value('id');
        if ($mid <= 0) {
            $mid = 1;
        }

        $now = time();
        $created = 0;
        $skipped = 0;

        $specs = [
            // [key, status, freight_type, freight_text, proid, proname, propic, sell_price, product_price, totalprice, extra]
            [
                'paid_pickup', 1, 1, '到店自提1号店',
                $pPickup['id'], $pPickup['name'], $pPickup['pic'], (float) $pPickup['sell_price'],
                69.00, 69.00,
                ['linkman' => '王菲菲', 'tel' => '17854553149', 'area' => '山东省临沂市兰山区', 'address' => '大官苑社区6号楼二单元801门口（北门自提）', 'paytype' => 'wechat', 'paytime' => $now - 86400],
            ],
            [
                'paid_express', 1, 0, '普通快递(10元)',
                $pFreight['id'], $pFreight['name'], $pFreight['pic'], (float) $pFreight['sell_price'],
                79.00, 89.00, // 79商品价 + 10运费
                ['linkman' => '张测试', 'tel' => '13845552655', 'area' => '山东省临沂市兰山区', 'address' => '上海路大厦1201室（上海路与成都路交汇）', 'paytype' => '余额支付', 'paytime' => $now - 7200],
            ],
            [
                'shipped', 2, 0, '普通快递(10元)',
                $pFreight['id'], $pFreight['name'], $pFreight['pic'], (float) $pFreight['sell_price'],
                99.00, 109.00,
                ['linkman' => '李发货', 'tel' => '17800000222', 'area' => '山东省临沂市兰山区', 'address' => '北城新区沂蒙路1号', 'paytype' => 'wechat', 'paytime' => $now - 172800, 'express_com' => 'EMS', 'express_no' => 'EM' . date('Ymd') . '8888', 'send_time' => $now - 86400],
            ],
            [
                'closed', 4, 0, '普通快递(10元)',
                $pFreight['id'], $pFreight['name'], $pFreight['pic'], (float) $pFreight['sell_price'],
                100.00, 110.00,
                ['linkman' => '已关闭', 'tel' => '17854553149', 'area' => '山东省临沂市兰山区', 'address' => '已取消地址', 'paytype' => '', 'paytime' => 0],
            ],
            [
                'done', 3, 0, '普通快递(10元)',
                $pFreight['id'], $pFreight['name'], $pFreight['pic'], (float) $pFreight['sell_price'],
                69.00, 79.00,
                ['linkman' => '王完成', 'tel' => '17811111111', 'area' => '山东省临沂市兰山区', 'address' => '商城路与沂州路交汇', 'paytype' => 'wechat', 'paytime' => $now - 604800, 'express_com' => '顺丰速运', 'express_no' => 'SF' . date('Ymd', $now - 604800) . '6666', 'send_time' => $now - 590400, 'collect_time' => $now - 432000],
            ],
        ];

        foreach ($specs as $spec) {
            [$key, $status, $ftype, $ftext, $proid, $proname, $propic, $sellPrice, $productPrice, $totalPrice, $extra] = $spec;

            $ordernum = self::ORDER_PREFIX . strtoupper($key);

            $exists = Db::name('kanjia_order')->where('ordernum', $ordernum)->find();
            if ($exists) {
                $this->log('  跳过（已存在）：' . $ordernum . '（status=' . $status . '）');
                $skipped++;
                continue;
            }

            $row = [
                'aid'           => $this->aid,
                'bid'           => 0,
                'mid'           => $mid,
                'ordernum'      => $ordernum,
                'title'         => (string) $proname,
                'proid'         => (int) $proid,
                'proname'       => (string) $proname,
                'propic'        => (string) $propic,
                'num'           => 1,
                'totalprice'    => $totalPrice,
                'product_price' => $productPrice,
                'freight_price' => round($totalPrice - $productPrice, 2),
                'sell_price'    => $sellPrice,
                'createtime'    => $extra['paytime'] > 0 ? $extra['paytime'] - 600 : $now - 86400,
                'status'        => $status,
                'linkman'       => $extra['linkman'],
                'tel'           => $extra['tel'],
                'area'          => $extra['area'],
                'address'       => $extra['address'],
                'freight_text'  => $ftext,
                'freight_type'  => $ftype,
                'paytype'       => $extra['paytype'],
                'paytime'       => $extra['paytime'],
                'send_time'     => $extra['send_time'] ?? 0,
                'collect_time'  => $extra['collect_time'] ?? 0,
                'express_com'   => $extra['express_com'] ?? '',
                'express_no'    => $extra['express_no'] ?? '',
                'hexiao_code'   => strtoupper(bin2hex(random_bytes(8))),
                'platform'      => 'wx',
                'delete'        => 0,
                'refund_status' => 0,
            ];

            Db::name('kanjia_order')->insert($row);
            $created++;
            $statusText = match ($status) {
                1 => '已支付',
                2 => '已发货',
                3 => '已完成',
                4 => '已关闭',
                default => '未支付',
            };
            $ftypeText = 1 === $ftype ? '到店自提' : '快递配送';
            $this->log('  新建：' . $ordernum . '（' . $statusText . ' / ' . $ftypeText . ' / ' . $ftext . '）');
        }

        $this->log('');
        $this->log('<info>==== 生成结束：新建订单 ' . $created . ' 笔，跳过 ' . $skipped . ' 笔 ====</info>');

        return $this->result($created, $skipped);
    }

    /**
     * 清理本生成器产出的全部演示订单（只删 ordernum 以 DEMO-KJ- 开头的记录）。
     */
    public function purge(int $aid): array
    {
        $aid = max(1, $aid);

        $count = Db::name('kanjia_order')
            ->where('aid', $aid)
            ->whereLike('ordernum', self::ORDER_PREFIX . '%')
            ->delete();

        return ['orders' => (int) $count];
    }

    /**
     * @return array{aid:int, created:int, skipped:int, logs:array<int,string>}
     */
    private function result(int $created, int $skipped): array
    {
        return [
            'aid'     => $this->aid,
            'created' => $created,
            'skipped' => $skipped,
            'logs'    => $this->logs,
        ];
    }

    private function log(string $line): void
    {
        $this->logs[] = $line;
    }
}
