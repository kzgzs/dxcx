<?php
// +----------------------------------------------------------------------
// | 团购活动示例数据生成器：生成商品+订单，状态覆盖已支付/已发货/已完成/已关闭，自提+快递
// +----------------------------------------------------------------------
namespace app\common\service;

use think\facade\Db;

class DemoTuangouGenerator
{
    private string $prefix = 'DEMO-TG-';
    private int $aid = 1;
    private int $now;

    public function __construct()
    {
        $this->now = time();
    }

    /** 仅删除本生成器数据（按订单号前缀），不动任何手工数据 */
    public function purge(): void
    {
        Db::name('tuangou_order')->where('ordernum', 'like', $this->prefix . '%')->delete();
        // 商品按名称标记前缀删除
        Db::name('tuangou_product')->where('name', 'like', '【演示团购】%')->delete();
    }

    /** 生成完整演示数据（幂等：先 purge） */
    public function generate(): void
    {
        $this->purge();

        $productId = $this->makeProduct();
        $this->makeOrders($productId);
    }

    private function makeProduct(): int
    {
        $pricedata = json_encode([
            ['num' => 1, 'money' => 80],
            ['num' => 2, 'money' => 60],
            ['num' => 3, 'money' => 50],
        ], JSON_UNESCAPED_UNICODE);

        return Db::name('tuangou_product')->insertGetId([
            'aid' => $this->aid,
            'bid' => 0,
            'name' => '【演示团购】双人精品套餐',
            'cid' => 0,
            'pic' => '/static/demo/tuangou.png',
            'pics' => '/static/demo/tuangou.png',
            'sellpoint' => '精选食材，双人同行更优惠，限时抢购',
            'fuwupoint' => '',
            'procode' => '',
            'sell_price' => 80,
            'market_price' => 199,
            'cost_price' => 30,
            'stock' => 100,
            'sales' => 0,
            'weight' => 100,
            'perlimit' => 0,
            'sort' => 0,
            'status' => 1,
            'detail' => '[]',
            'pricedata' => $pricedata,
            'starttime' => $this->now - 86400 * 30,
            'endtime' => $this->now + 86400 * 30,
            'showtj' => '-1',
            'gettj' => '-1',
            'freighttype' => 1,
            'freightdata' => '',
            'freightcontent' => '',
            'scoredkmaxset' => 0,
            'scoredkmaxval' => 0,
            'contact_require' => 1,
            'commissionset' => 0,
            'commissiondata1' => '{}',
            'commissiondata2' => '{}',
            'commissiondata3' => '{}',
            'vipdiscount' => 0,
            'ischecked' => 1,
            'comment_score' => 5,
            'comment_num' => 0,
            'comment_haopercent' => 100,
            'createtime' => $this->now - 86400 * 30,
        ]);
    }

    private function baseOrder(int $proid, string $proname, int $freightType, string $linkman, string $tel, string $address): array
    {
        return [
            'aid' => $this->aid,
            'bid' => 0,
            'mid' => 0,
            'ordernum' => $this->prefix . date('YmdHis') . substr((string) mt_rand(1000, 9999), 0, 4),
            'buytype' => 1,
            'teamid' => 0,
            'title' => $proname,
            'proid' => $proid,
            'proname' => $proname,
            'propic' => '/static/demo/tuangou.png',
            'ggid' => 0,
            'ggname' => '',
            'num' => 1,
            'cost_price' => 30,
            'sell_price' => 80,
            'totalprice' => 80,
            'product_price' => 80,
            'freight_price' => 0,
            'createtime' => $this->now - 86400,
            'status' => 0,
            'linkman' => $linkman,
            'tel' => $tel,
            'area' => '河南省南阳市',
            'area2' => '',
            'address' => $address,
            'paytype' => 'wechat',
            'paytypeid' => 1,
            'freight_type' => $freightType,
            'platform' => 'wx',
            'iscomment' => 0,
        ];
    }

    private function makeOrders(int $proid): void
    {
        $proname = '【演示团购】双人精品套餐';

        // 1. 已支付（快递）freight_type=0
        $o = $this->baseOrder($proid, $proname, 0, '张三', '13800000001', '宛城区人民路1号');
        $o['status'] = 1;
        $o['paytime'] = $this->now - 3600;
        Db::name('tuangou_order')->insert($o);

        // 2. 已支付（自提）freight_type=1
        $o = $this->baseOrder($proid, $proname, 1, '李四', '13800000002', '到店自提');
        $o['status'] = 1;
        $o['paytime'] = $this->now - 3600;
        Db::name('tuangou_order')->insert($o);

        // 3. 已发货（快递）
        $o = $this->baseOrder($proid, $proname, 0, '王五', '13800000003', '卧龙区文化路2号');
        $o['status'] = 2;
        $o['paytime'] = $this->now - 86400 * 2;
        $o['send_time'] = $this->now - 86400;
        $o['express_com'] = '圆通速递';
        $o['express_no'] = 'YT' . date('Ymd') . mt_rand(100000000, 999999999);
        Db::name('tuangou_order')->insert($o);

        // 4. 已完成（快递，自动收货）
        $o = $this->baseOrder($proid, $proname, 0, '赵六', '13800000004', '卧龙区建设路3号');
        $o['status'] = 3;
        $o['paytime'] = $this->now - 86400 * 6;
        $o['send_time'] = $this->now - 86400 * 5;
        $o['collect_time'] = $this->now - 86400 * 4;
        $o['express_com'] = '中通快递';
        $o['express_no'] = 'ZT' . date('Ymd') . mt_rand(100000000, 999999999);
        Db::name('tuangou_order')->insert($o);

        // 5. 已完成（自提核销）
        $o = $this->baseOrder($proid, $proname, 1, '孙七', '13800000005', '到店自提');
        $o['status'] = 3;
        $o['paytime'] = $this->now - 86400 * 3;
        $o['send_time'] = $this->now - 86400 * 2;
        $o['collect_time'] = $this->now - 86400 * 2;
        $o['hexiao_code'] = 'HX' . mt_rand(100000, 999999);
        Db::name('tuangou_order')->insert($o);

        // 6. 已关闭（未支付）
        $o = $this->baseOrder($proid, $proname, 0, '周八', '13800000006', '宛城区新华东路4号');
        $o['status'] = 4;
        Db::name('tuangou_order')->insert($o);
    }
}
