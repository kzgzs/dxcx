<?php
// +----------------------------------------------------------------------
// | 积分兑换示例数据生成器：生成商品+订单，状态覆盖已支付/未支付/已发货/已完成/已关闭，自提+快递
// +----------------------------------------------------------------------
namespace app\common\service;

use think\facade\Db;

class DemoScoreshopGenerator
{
    private string $prefix = 'DEMO-SCORE-';
    private int $aid = 1;
    private int $now;

    public function __construct()
    {
        $this->now = time();
    }

    /** 仅删除本生成器数据（按订单号前缀），不动任何手工数据 */
    public function purge(): void
    {
        $orderIds = Db::name('scoreshop_order')->where('ordernum', 'like', $this->prefix . '%')->column('id');
        if (!empty($orderIds)) {
            Db::name('scoreshop_order_goods')->whereIn('orderid', $orderIds)->delete();
        }
        Db::name('scoreshop_order')->where('ordernum', 'like', $this->prefix . '%')->delete();
        // 商品按名称标记前缀删除
        $proIds = Db::name('scoreshop_product')->where('name', 'like', '【演示积分】%')->column('id');
        if (!empty($proIds)) {
            Db::name('scoreshop_guige')->whereIn('proid', $proIds)->delete();
            Db::name('scoreshop_product')->whereIn('id', $proIds)->delete();
        }
    }

    /** 生成完整演示数据（幂等：先 purge） */
    public function generate(): void
    {
        $this->purge();

        $categoryId = $this->makeCategory();
        $productId = $this->makeProduct($categoryId);
        $this->makeOrders($productId);
    }

    private function makeCategory(): int
    {
        $exist = Db::name('scoreshop_category')->where('aid', $this->aid)->where('name', '演示分类')->find();
        if ($exist) {
            return (int) $exist['id'];
        }
        return (int) Db::name('scoreshop_category')->insertGetId([
            'aid' => $this->aid, 'pid' => 0, 'name' => '演示分类',
            'pic' => '', 'status' => 1, 'sort' => 0, 'createtime' => $this->now,
        ]);
    }

    private function makeProduct(int $cid): int
    {
        $productId = (int) Db::name('scoreshop_product')->insertGetId([
            'aid' => $this->aid, 'bid' => 0, 'cid' => $cid,
            'name' => '【演示积分】精品陶瓷茶杯',
            'pic' => '/static/demo/scoreshop.png',
            'pics' => '/static/demo/scoreshop.png',
            'detail' => '[]',
            'sell_price' => 59.00,
            'cost_price' => 20.00,
            'score_price' => 100,
            'money_price' => 3.00,
            'buymax' => 0,
            'everyday_buymax' => 0,
            'lvprice' => 0,
            'weight' => 500,
            'sort' => 0,
            'status' => 1,
            'stock' => 200,
            'sales' => 0,
            'freighttype' => 1,
            'freightdata' => '',
            'freightcontent' => '',
            'contact_require' => 1,
            'commissionset' => 0,
            'commissiondata1' => '',
            'commissiondata2' => '',
            'commissiondata3' => '',
            'commissiondata5' => '',
            'showtj' => '-1',
            'gettj' => '-1',
            'ischecked' => 1,
            'createtime' => $this->now - 86400 * 7,
        ]);

        Db::name('scoreshop_guige')->insert([
            'aid' => $this->aid, 'proid' => $productId, 'name' => '默认规格',
            'pic' => '', 'market_price' => 59.00, 'cost_price' => 20.00,
            'score_price' => 100, 'money_price' => 3.00, 'weight' => 500,
            'stock' => 200, 'sales' => 0,
        ]);

        return $productId;
    }

    private function baseOrder(int $proid, string $title, int $freightType, string $freightText, string $linkman, string $tel, string $address): array
    {
        return [
            'aid' => $this->aid, 'bid' => 0, 'mid' => 0,
            'ordernum' => $this->prefix . date('YmdHis') . substr((string) mt_rand(1000, 9999), 0, 4),
            'title' => $title,
            'totalscore' => 100,
            'totalmoney' => 3.00,
            'totalprice' => 3.00,
            'totalnum' => 1,
            'freight_price' => 0,
            'createtime' => $this->now - 86400,
            'status' => 0,
            'linkman' => $linkman,
            'tel' => $tel,
            'area' => '河南省南阳市',
            'address' => $address,
            'message' => '',
            'remark' => '',
            'paytype' => 'wechat',
            'paytypeid' => 1,
            'freight_type' => $freightType,
            'freight_text' => $freightText,
            'platform' => 'wx',
            'hexiao_code' => strtoupper(bin2hex(random_bytes(6))),
        ];
    }

    private function makeOrderGoods(int $orderId, string $ordernum, int $proid, string $title): void
    {
        Db::name('scoreshop_order_goods')->insert([
            'aid' => $this->aid, 'bid' => 0, 'mid' => 0,
            'orderid' => $orderId, 'ordernum' => $ordernum,
            'proid' => $proid, 'name' => $title, 'pic' => '/static/demo/scoreshop.png',
            'ggid' => 0, 'ggname' => '默认规格', 'num' => 1,
            'sell_price' => 59.00, 'cost_price' => 20.00,
            'score_price' => 100, 'money_price' => 3.00,
            'totalscore' => 100, 'totalmoney' => 3.00,
            'status' => 0, 'createtime' => $this->now - 86400,
        ]);
    }

    private function makeOrders(int $proid): void
    {
        $title = '【演示积分】精品陶瓷茶杯';

        // 1. 已支付 + 普通快递
        $o = $this->baseOrder($proid, $title, 0, '普通快递(3元)', '张三', '13800000001', '宛城区人民路1号');
        $o['status'] = 1;
        $o['paytime'] = $this->now - 3600;
        $oid = Db::name('scoreshop_order')->insertGetId($o);
        $this->makeOrderGoods($oid, $o['ordernum'], $proid, $title);

        // 2. 已支付 + 同城配送（配送员）
        $o = $this->baseOrder($proid, $title, 2, '同城配送(2元)', '李四', '13800000002', '卧龙区文化路2号');
        $o['status'] = 1;
        $o['paytime'] = $this->now - 3600;
        $oid = Db::name('scoreshop_order')->insertGetId($o);
        $this->makeOrderGoods($oid, $o['ordernum'], $proid, $title);

        // 3. 已支付 + 到店自提（可核销）
        $o = $this->baseOrder($proid, $title, 1, '到店自提', '王五', '13800000003', '到店自提');
        $o['status'] = 1;
        $o['paytime'] = $this->now - 3600;
        $oid = Db::name('scoreshop_order')->insertGetId($o);
        $this->makeOrderGoods($oid, $o['ordernum'], $proid, $title);

        // 4. 已发货 + 快递（含快递单号）
        $o = $this->baseOrder($proid, $title, 0, '普通快递(3元)', '赵六', '13800000004', '卧龙区建设路3号');
        $o['status'] = 2;
        $o['paytime'] = $this->now - 86400 * 2;
        $o['send_time'] = $this->now - 86400;
        $o['express_com'] = '圆通速递';
        $o['express_no'] = 'YT' . date('Ymd') . mt_rand(100000000, 999999999);
        $oid = Db::name('scoreshop_order')->insertGetId($o);
        $this->makeOrderGoods($oid, $o['ordernum'], $proid, $title);

        // 5. 已完成 + 自提核销（含核销时间）
        $o = $this->baseOrder($proid, $title, 1, '到店自提', '孙七', '13800000005', '到店自提');
        $o['status'] = 3;
        $o['paytime'] = $this->now - 86400 * 3;
        $o['send_time'] = $this->now - 86400 * 2;
        $o['collect_time'] = $this->now - 86400 * 2;
        $oid = Db::name('scoreshop_order')->insertGetId($o);
        $this->makeOrderGoods($oid, $o['ordernum'], $proid, $title);

        // 6. 已关闭（未支付）
        $o = $this->baseOrder($proid, $title, 0, '普通快递(3元)', '周八', '13800000006', '宛城区新华东路4号');
        $o['status'] = 4;
        $oid = Db::name('scoreshop_order')->insertGetId($o);
        $this->makeOrderGoods($oid, $o['ordernum'], $proid, $title);

        // 7. 未支付
        $o = $this->baseOrder($proid, $title, 0, '普通快递(3元)', '吴九', '13800000007', '宛城区滨河路5号');
        $o['status'] = 0;
        $oid = Db::name('scoreshop_order')->insertGetId($o);
        $this->makeOrderGoods($oid, $o['ordernum'], $proid, $title);
    }
}
