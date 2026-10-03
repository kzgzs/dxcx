<?php
declare(strict_types=1);

namespace app\common\service;

use think\facade\Db;

/**
 * 秒杀演示数据生成器（与运行方式解耦，命令 demo:seckill 共用同一份逻辑）。
 *
 * 生成若干秒杀商品 + 5 笔演示订单，状态覆盖：
 *   已支付(快递) / 已支付(到店自提) / 已发货(含快递单号) / 已完成(含核销时间) / 已关闭
 * 订单号前缀 DEMO-SK-；--purge 只删此前缀，--reset 先删再建。
 */
class DemoSeckillGenerator
{
    private const ORDER_PREFIX = 'DEMO-SK-';

    /**
     * @param array{reset?:bool,purge?:bool,aid?:int} $opts
     */
    public function run(array $opts = []): void
    {
        $reset = !empty($opts['reset']);
        $purge = !empty($opts['purge']);
        $aid   = (int) ($opts['aid'] ?? 1);

        $this->purge($aid);
        if ($purge) {
            $this->log('已清除全部 ' . self::ORDER_PREFIX . ' 演示订单');
            return;
        }
        if ($reset) {
            $this->log('已先清除旧演示数据，开始重建…');
        }

        $now   = time();
        $prod  = $this->ensureProduct($aid, $now);
        $proid = (int) $prod['id'];
        $ggid  = (int) Db::name('seckill_guige')->where('aid', $aid)->where('proid', $proid)->value('id');

        $cases = [
            // [状态, 配送方式, 备注]
            ['status' => 1, 'freight_type' => 0, 'freight_text' => '快递配送', 'paytype' => '微信支付', 'label' => '已支付-快递'],
            ['status' => 1, 'freight_type' => 1, 'freight_text' => '到店自提', 'paytype' => '积分支付', 'label' => '已支付-到店自提'],
            ['status' => 2, 'freight_type' => 0, 'freight_text' => '快递配送', 'paytype' => '微信支付', 'label' => '已发货-含快递单号'],
            ['status' => 3, 'freight_type' => 1, 'freight_text' => '到店自提', 'paytype' => '微信支付', 'label' => '已完成-含核销时间'],
            ['status' => 4, 'freight_type' => 0, 'freight_text' => '快递配送', 'paytype' => '微信支付', 'label' => '已关闭'],
        ];

        $i = 0;
        foreach ($cases as $c) {
            $i++;
            $num = $i;
            $ordernum = self::ORDER_PREFIX . date('YmdHis') . sprintf('%03d', $num);
            $sellPrice = 10.00;
            $qty       = 1;
            $total     = round($sellPrice * $qty, 2);

            $row = [
                'aid'        => $aid,
                'bid'        => 0,
                'mid'        => 1,
                'ordernum'   => $ordernum,
                'title'      => '秒杀订单',
                'proid'      => $proid,
                'proname'    => $prod['name'],
                'propic'     => (string) $prod['pic'],
                'ggid'       => $ggid,
                'ggname'     => '默认规格',
                'num'        => $qty,
                'sell_price' => $sellPrice,
                'totalprice' => $total,
                'product_price' => $total,
                'createtime' => $now - $i * 3600,
                'status'     => (int) $c['status'],
                'linkman'    => '演示用户' . $num,
                'tel'        => '1380000' . sprintf('%04d', $num),
                'area'       => '河南省 南阳市 镇平县',
                'area2'      => '河南省 南阳市 镇平县',
                'address'    => '演示路 ' . $num . ' 号',
                'platform'   => 'wx',
                'paytype'    => $c['paytype'],
                'freight_type' => (int) $c['freight_type'],
                'freight_text' => $c['freight_text'],
                'remark'     => '',
                'delete'     => 0,
            ];

            // 已支付/已发货/已完成：都已支付
            if ((int) $c['status'] >= 1) {
                $row['paytime'] = $row['createtime'] + 60;
            }
            // 已发货：写快递单号 + 发货时间
            if ((int) $c['status'] === 2) {
                $row['express_com']  = 'EMS';
                $row['express_no']   = 'DEMOSK' . date('Ymd') . sprintf('%04d', $num);
                $row['send_time']    = $row['createtime'] + 1800;
            }
            // 已完成：写核销/收货时间
            if ((int) $c['status'] === 3) {
                $row['collect_time'] = $row['createtime'] + 7200;
                $row['hexiao_code']  = 'HX' . sprintf('%06d', $num);
            }

            Db::name('seckill_order')->insert($row);
            $this->log('  生成订单 ' . $ordernum . '（' . $c['label'] . '）');
        }

        $this->log('完成：共生成 ' . count($cases) . ' 笔演示订单，商品「' . $prod['name'] . '」');
    }

    /** 清除本生成器产生的全部演示订单（按订单号前缀） */
    public function purge(int $aid): void
    {
        Db::name('seckill_order')->where('aid', $aid)->whereLike('ordernum', self::ORDER_PREFIX . '%')->delete();
    }

    /** 确保存在一个演示秒杀商品（不存在则建一个） */
    private function ensureProduct(int $aid, int $now): array
    {
        $row = Db::name('seckill_product')->where('aid', $aid)->where('name', 'like', '演示秒杀%')->order('id', 'asc')->find();
        if ($row) {
            return $row;
        }

        $date = date('Y-m-d', $now);
        $proid = Db::name('seckill_product')->insertGetId([
            'aid'          => $aid,
            'bid'          => 0,
            'name'         => '演示秒杀商品-' . date('md'),
            'procode'      => 'DEMOSK' . date('mdHis'),
            'pic'          => '',
            'pics'         => '',
            'sales'        => 0,
            'detail'       => '[]',
            'market_price' => 20.00,
            'sell_price'   => 10.00,
            'cost_price'   => 6.00,
            'stock'        => 1000,
            'sort'         => 0,
            'status'       => 1,
            'perlimit'     => 0,
            'buymax'       => 0,
            'seckill_date' => $date,
            'seckill_time' => 10,
            'starttime'    => strtotime($date . ' 10:00:00'),
            'ischecked'    => 1,
            'gettj'        => 'all',
            'createtime'   => $now,
        ]);

        Db::name('seckill_guige')->insert([
            'aid'          => $aid,
            'proid'        => $proid,
            'name'         => '默认规格',
            'market_price' => 20.00,
            'cost_price'   => 6.00,
            'sell_price'   => 10.00,
            'weight'       => 0,
            'stock'        => 1000,
            'sales'        => 0,
        ]);

        return Db::name('seckill_product')->where('id', $proid)->find();
    }

    private function log(string $msg): void
    {
        echo $msg . PHP_EOL;
    }
}
