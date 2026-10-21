<?php
// +----------------------------------------------------------------------
// | 电子面单示例数据生成器：快递公司账号 + 面单记录(正常/已取消)
// | 幂等：客户编码/订单号/面单订单号统一前缀 DEMO-EB-；purge 仅按前缀删本生成器数据。
// +----------------------------------------------------------------------
namespace app\common\service;

use think\facade\Db;

class DemoEwaybillGenerator
{
    private string $prefix = 'DEMO-EB-';
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

    /** 仅删除本生成器数据（按前缀），不动手工数据 */
    public function purge(): void
    {
        $like = $this->prefix . '%';
        Db::name('ewaybill_order')->where('ordernum', 'like', $like)->delete();
        Db::name('ewaybill_express_account')->where('customer_name', 'like', $like)->delete();
    }

    public function generate(): void
    {
        $this->purge();
        $accountIds = $this->makeAccounts();
        $this->makeOrders($accountIds);
    }

    /** 生成快递公司账号，返回 [code => id] */
    private function makeAccounts(): array
    {
        $rows = [
            ['express' => '圆通速递', 'express_code' => 'YTO', 'customer_name' => $this->prefix . 'YTO-K53949592', 'customer_pwd' => 'demo_ytokey', 'month_code' => $this->prefix . 'YTO-M001', 'send_site' => '', 'send_staff' => '', 'paytype' => 3],
            ['express' => '优速快递', 'express_code' => 'UC', 'customer_name' => $this->prefix . 'UC-88001234', 'customer_pwd' => 'demo_uckey', 'month_code' => '', 'send_site' => '杭州上城网点', 'send_staff' => '张师傅', 'paytype' => 1],
        ];

        $map = [];
        foreach ($rows as $r) {
            $r['aid']        = $this->aid;
            $r['bid']        = 0;
            $r['createtime'] = $this->now;
            $id              = Db::name('ewaybill_express_account')->insertGetId($r);
            $map[$r['express_code']] = $id;
        }

        return $map;
    }

    /** 生成面单记录 */
    private function makeOrders(array $accountIds): void
    {
        // [express_code, status, ordernum后缀, 收件人, 手机, 省/市/区/地址, 商品, 多少天前]
        $cases = [
            ['YTO', 1, '260915100001', '王小明', '13800001111', '浙江省', '杭州市', '西湖区', '文三路100号', '鼎极商城系统授权版 × 1', 0],
            ['YTO', 1, '260914100002', '李红', '13900002222', '江苏省', '南京市', '鼓楼区', '中山北路200号', '加厚洗碗巾(10片装) × 3', 1],
            ['UC', 1, '260913100003', '赵强', '13700003333', '广东省', '深圳市', '南山区', '科技园南区', '无线蓝牙耳机 × 2', 2],
            ['UC', 2, '260910100004', '陈丽', '13600004444', '北京市', '北京市', '朝阳区', '建国路88号', '纯棉四件套 × 1', 4],
        ];

        foreach ($cases as $i => [$code, $status, $ordernum, $recvName, $recvMobile, $prov, $city, $area, $addr, $goods, $daysAgo]) {
            $accountId = $accountIds[$code] ?? reset($accountIds);
            $ct        = (int) round($this->now - 86400 * $daysAgo);
            $expressNo = $this->prefix . $code . str_pad((string) ($i + 1), 8, '0', STR_PAD_LEFT);
            $account   = Db::name('ewaybill_express_account')->where('id', $accountId)->find();

            Db::name('ewaybill_order')->insert([
                'aid'           => $this->aid,
                'bid'           => 0,
                'mid'           => 1,
                'orderid'       => 0,
                'ordertype'     => 'shop',
                'ordernum'      => $this->prefix . $ordernum,
                'order_code'    => $this->prefix . $ordernum . 'D' . str_pad((string) ($i + 1), 6, '0', STR_PAD_LEFT),
                'express_id'    => $accountId,
                'shipper_code'   => $code,
                'customer_name'  => (string) ($account['customer_name'] ?? ''),
                'customer_pwd'   => (string) ($account['customer_pwd'] ?? ''),
                'send_site'      => (string) ($account['send_site'] ?? ''),
                'send_staff'     => (string) ($account['send_staff'] ?? ''),
                'month_code'     => (string) ($account['month_code'] ?? ''),
                'pay_type'       => (int) ($account['paytype'] ?? 1),
                'exp_type'       => 1,
                'expect_start_time' => $ct,
                'expect_end_time'   => $ct + 3600 * 8,
                'is_notice'      => 0,
                'quantity'       => 1,
                'weight'         => 1.2,
                'volume'         => 0.01,
                'insure'         => 0,
                'insure_value'   => 0,
                'remark'         => '无',
                'receiver_name'      => $recvName,
                'receiver_mobile'   => $recvMobile,
                'receiver_province' => $prov,
                'receiver_city'     => $city,
                'receiver_area'     => $area,
                'receiver_address'   => $addr,
                'sender_name'      => '鼎极商城',
                'sender_mobile'   => '17811111111',
                'sender_province' => '安徽省',
                'sender_city'      => '合肥市',
                'sender_area'     => '瑶海区',
                'sender_address'  => '西乡大道宝运达物流中心A2-102档',
                'waybill'        => $this->mockTemplate($expressNo, (string) ($account['express'] ?? $code), $recvName),
                'goods_detail'    => $goods,
                'express_com'    => (string) ($account['express'] ?? $code),
                'express_no'     => $expressNo,
                'status'         => $status,
                'createtime'     => $ct,
            ]);
        }
    }

    protected function mockTemplate(string $no, string $com, string $name): string
    {
        return '<div style="border:2px solid #333;padding:12px;width:600px;font-family:微软雅黑;">'
            . '<div style="text-align:center;font-size:18px;font-weight:bold;">电子面单（DEMO-EB）</div>'
            . '<p>运单号：' . htmlspecialchars($no) . '</p>'
            . '<p>快递公司：' . htmlspecialchars($com) . '</p>'
            . '<p>收件人：' . htmlspecialchars($name) . '</p></div>';
    }
}
