<?php
// +----------------------------------------------------------------------
// | 物流助手示例数据生成器：运单(1正常/4已取消) + 已绑定物流账号 + 打印员
// | 幂等：订单号/客户编码/openid 统一前缀 DEMO-WL-；purge 仅按前缀删本生成器数据，不碰手工数据。
// | 状态口径（对标参考系统 Miandan）：1正常 4已取消；
// |   账号 status_code：0绑定成功 3已解绑；打印员落库 miandan_printer。
// +----------------------------------------------------------------------
namespace app\common\service;

use think\facade\Db;

class DemoMiandanGenerator
{
    /** 订单号/客户编码/openid 前缀 */
    private string $prefix = 'DEMO-WL-';
    private int $aid = 1;
    private int $now;

    public function __construct()
    {
        $this->now = time();
    }

    public function setAid(int $aid): void
    {
        $this->aid = $aid;
    }

    /** 仅删除本生成器数据（按前缀），不动任何手工数据 */
    public function purge(): void
    {
        $like = $this->prefix . '%';
        Db::name('miandan_order')->where('order_id', 'like', $like)->delete();
        Db::name('miandan_account')->where('biz_id', 'like', $like)->delete();
        Db::name('miandan_printer')->where('openid', 'like', $like)->delete();
    }

    /** 生成完整演示数据（幂等：先 purge） */
    public function generate(): void
    {
        $this->purge();
        $this->makeOrders();
        $this->makeAccounts();
        $this->makePrinters();
    }

    /* ---------------- 运单 ---------------- */
    private function makeOrders(): void
    {
        // [状态, 订单号后缀, 运单号, 收件人, 手机, 省, 市, 区, 详细地址, 商品名, 多少天前]
        $cases = [
            [1, '260915105704818412', 'SF0210098407090', '11', '15666666666', '北京市', '北京市', '东城区', '不是', '尚鸟直享加厚五层魔力洗碗巾(10片装)', 2],
            [1, '2609031207268794', 'SF0219287537717', '111', '11111111111', '天津市', '天津市', '河东区', '金城江街道第二初级中学', '鼎极商城系统', 3],
            [1, '260528090450967843', 'SF0215832792231', '李四', '18888888888', '广东省', '广州市', '越秀区', '重庆北站北广场', '权限查看', 5],
            [4, '251224100926521589', 'SF0253357214409', '足v', '17854553149', '北京市', '北京市', '东城区', '按国栋', '鼎极商城', 8],
            [4, '250627201449587521', 'SF0284416246685', '龙', '18888888888', '山东省', '潍坊市', '潍城区', '五月阳光功能农业(南京)有限公司', '1.5米床单', 10],
        ];

        $seq = 0;
        foreach ($cases as [$status, $ordernum, $waybill, $recvName, $recvMobile, $prov, $city, $area, $addr, $goods, $daysAgo]) {
            $seq++;
            $ct = (int) round($this->now - 86400 * $daysAgo);
            Db::name('miandan_order')->insert([
                'aid'          => $this->aid,
                'orderid'      => 0,
                'ordertype'    => 'shop_order',
                'mid'          => 1,
                'add_source'   => 0,
                'order_id'     => $this->prefix . $ordernum,
                'openid'       => '',
                'biz_id'       => '',
                'delivery_id'  => 'SF',
                'delivery_name' => '顺丰现付',
                'service_type' => '0',
                'service_name' => '标准快递',
                'receiver_name'   => $recvName,
                'receiver_mobile' => $recvMobile,
                'receiver_province' => $prov,
                'receiver_city'   => $city,
                'receiver_area'   => $area,
                'receiver_address' => $addr,
                'sender_company'  => '鼎极商城',
                'sender_name'     => '鼎极商城',
                'sender_mobile'   => '17811111111',
                'sender_province' => '安徽省',
                'sender_city'     => '合肥市',
                'sender_area'     => '瑶海区',
                'sender_address'  => '西乡大道宝运达物流中心A2-102档',
                'cargo_count'     => 1,
                'cargo_weight'    => 1.00,
                'cargo_detail_list' => json_encode([['name' => $goods, 'count' => 1]], JSON_UNESCAPED_UNICODE),
                'shop_goods_name'   => $goods,
                'shop_goods_count'  => 1,
                'status'       => $status,
                'waybill_id'   => $waybill,
                'createtime'   => $ct,
            ]);
        }
    }

    /* ---------------- 物流账号 ---------------- */
    private function makeAccounts(): void
    {
        $accounts = [
            ['YTO', '圆通速递', $this->prefix . 'K07695779', 3, '2025-06-06 16:39', 83],
            ['ZTO', '中通快递', $this->prefix . 'ZT88001234', 0, null, 120],
            ['YD', '韵达速递', $this->prefix . 'YD55667788', 1, null, 0],
        ];
        foreach ($accounts as [$did, $dname, $biz, $statusCode, $bindTime, $quota]) {
            Db::name('miandan_account')->insert([
                'aid' => $this->aid,
                'biz_id' => $biz,
                'delivery_id' => $did,
                'delivery_name' => $dname,
                'status_code' => $statusCode,
                'password' => '',
                'remark_content' => '',
                'quota_num' => $quota,
                'create_time' => $bindTime ? strtotime($bindTime) : $this->now - 86400 * 6,
                'updatetime' => $this->now,
            ]);
        }
    }

    /* ---------------- 打印员 ---------------- */
    private function makePrinters(): void
    {
        $printers = [
            [$this->prefix . 'P1openidABC', '花有重开日，人无再少年'],
            [$this->prefix . 'P2openidDEF', '上善若水'],
            [$this->prefix . 'P3openidGHI', '一切安好'],
            [$this->prefix . 'P4openidJKL', 'A蚂蚁网络'],
        ];
        foreach ($printers as $i => [$openid, $nickname]) {
            Db::name('miandan_printer')->insert([
                'aid' => $this->aid,
                'openid' => $openid,
                'nickname' => $nickname,
                'headimg' => '',
                'createtime' => $this->now - 86400 * (10 - $i),
            ]);
        }
    }
}
