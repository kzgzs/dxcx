<?php

declare(strict_types=1);

namespace app\common;

// 快递鸟 电子面单封装（对标 canzhao/app/common/Kdniao.php 结构照抄）
// 官方文档：https://www.yuque.com/kdnjishuzhichi/dfcrg1
//
// mock 语义（.env EWAYBILL.MOCK=1，默认）：
// - 创建面单(1007)：返回确定性 mock 面单号 + 占位打印模板 HTML，不调快递鸟接口
// - 取消面单(1147)/预约取件(1001)/取消预约(1004)：直接返回成功
// 真实模式（EWAYBILL.MOCK=0）：走 ewaybill_set 配置的账号，真实请求快递鸟 API

use think\facade\Db;
use think\facade\Log;

class Kdniao
{
    protected $accountId;     // 快递鸟账号 EBusinessID
    protected $accountSecret; // 快递鸟 API KEY
    protected $set;
    protected $aid;
    protected $bid;

    public function __construct(int $aid = 1, int $bid = 0)
    {
        $set = Db::name('ewaybill_set')->where('aid', $aid)->where('bid', $bid)->find();
        $this->accountId     = $set['account_id'] ?? '';
        $this->accountSecret = $set['account_secret'] ?? '';
        $this->set           = $set;
        $this->aid           = $aid;
        $this->bid           = $bid;
    }

    /** mock 开关：.env EWAYBILL.MOCK=1 启用（默认关闭，物流面单组共用） */
    public static function isMock(): bool
    {
        $raw   = (string) env('EWAYBILL.MOCK', '0');
        $value = trim(strtok($raw, '#'));

        return '1' === $value;
    }

    /**
     * 创建面单（电子面单 1007）
     * https://www.yuque.com/kdnjishuzhichi/dfcrg1/xxvu6s
     */
    public function addorder(array $order, ?array $expressInfo = null): array
    {
        $data = [
            'ShipperCode'           => $order['shipper_code'],
            'CustomerName'          => $order['customer_name'] ?: '',
            'CustomerPwd'           => $order['customer_pwd'] ?: '',
            'SendSite'              => $order['send_site'] ?: '',
            'SendStaff'             => $order['send_staff'] ?: '',
            'MonthCode'             => $order['month_code'] ?: '',
            'OrderCode'             => $order['order_code'] ?: '',
            'PayType'               => $order['pay_type'],
            'ExpType'               => $order['exp_type'],
            'Receiver'              => [
                'Name'         => $order['receiver_name'],
                'Tel'          => $order['receiver_tel'],
                'Mobile'       => $order['receiver_mobile'],
                'ProvinceName' => $order['receiver_province'],
                'CityName'     => $order['receiver_city'],
                'ExpAreaName'  => $order['receiver_area'],
                'Address'      => $order['receiver_address'],
                'PostCode'     => $order['receiver_post_code'] ?: '',
                'Company'      => $order['receiver_company'] ?: '',
            ],
            'Sender'                => [
                'Company'      => $order['sender_company'] ?: '',
                'Name'         => $order['sender_name'],
                'Tel'          => $order['sender_tel'],
                'Mobile'       => $order['sender_mobile'],
                'ProvinceName' => $order['sender_province'],
                'CityName'     => $order['sender_city'],
                'ExpAreaName'  => $order['sender_area'],
                'Address'      => $order['sender_address'],
                'PostCode'     => $order['sender_post_code'] ?: '',
            ],
            'Quantity'              => $order['quantity'],
            'Weight'                => $order['weight'],
            'Volume'                => $order['volume'],
            'Remark'                => $order['remark'],
            'IsNotice'              => $order['is_notice'] == 1 ? 0 : 1, // 通知上门取件 接口 0：通知 1：不通知
            'StartDate'             => $order['expect_start_time'] ? date('Y-m-d H:i:s', (int) $order['expect_start_time']) : '',
            'EndDate'               => $order['expect_end_time'] ? date('Y-m-d H:i:s', (int) $order['expect_end_time']) : '',
            'IsReturnPrintTemplate' => 1, // 是否返回电子面单模板 0：不需要 1：需要
            'IsSubscribe'           => 0, // 是否订阅轨迹推送 0：不订阅 1：订阅
        ];

        // 商品类型（订单表结构差异做防御，失败默认"商品"）
        $data['Commodity'] = $this->buildCommodity($order);

        // 保价
        $addService = [];
        if (!empty($order['insure'])) {
            $addService[] = ['Name' => 'INSURE', 'Value' => $order['insure_value']];
        }
        $express = self::expressData($order['shipper_code']);
        // 京东 客户参数
        if (isset($express['customerMap']) && $express['customerMap'] && $expressInfo) {
            $customerMap = [];
            if (in_array('Param1', $express['customerMap'])) {
                $customerMap['Param1'] = ($expressInfo['param1'] ?? '') ?: ($expressInfo['customer_name'] ?? '');
            }
            foreach (['Param2', 'Param3', 'Param4', 'Param5'] as $p) {
                if (in_array($p, $express['customerMap'])) {
                    $customerMap[$p] = $expressInfo[strtolower($p)] ?? '';
                }
            }
            $data['CustomerMap']        = $customerMap;
            $data['LogisticsRouteCode'] = 'OPEN'; // OPEN-京东开发平台 默认

            // 温层
            if (!empty($order['warm_layer'])) {
                $addService[] = ['Name' => 'mainProductAttrs', 'Value' => ['warmLayer' => $order['warm_layer']]];
            }
        }
        if ($addService) {
            $data['AddService'] = $addService;
        }
        // 模板
        if (isset($express['templateSize'])) {
            $templateSize = $express['templateSize'];
            if (isset($templateSize[$order['exp_type']])) {
                $data['TemplateSize'] = $templateSize[$order['exp_type']];
            }
        } else {
            $data['TemplateSize'] = 130; // 指定模板 大部分快递公司都存在130模板
        }

        $url = 'https://api.kdniao.com/api/EOrderService';

        return $this->request($url, $data, '1007');
    }

    /**
     * 取消面单（1147）
     * https://www.yuque.com/kdnjishuzhichi/dfcrg1/lq1g91
     */
    public function cancelorder(array $order): array
    {
        $data = [
            'ShipperCode'  => $order['shipper_code'],
            'OrderCode'    => $order['order_code'],
            'ExpNo'        => $order['express_no'],
            'CustomerName' => $order['customer_name'],
            'CustomerPwd'  => $order['customer_pwd'] ?: '',
            'MonthCode'    => $order['month_code'] ?: '',
        ];
        $url = 'https://api.kdniao.com/api/EOrderService';

        return $this->request($url, $data, '1147');
    }

    /**
     * 预约取件（1001）
     * https://www.yuque.com/kdnjishuzhichi/dfcrg1/syz70m
     */
    public function yuyueOrder(array $order): array
    {
        $data = [
            'OrderCode'   => $order['order_code'],
            'ShipperCode' => $order['shipper_code'],
            'PayType'     => $order['pay_type'],
            'ExpType'     => $order['exp_type'],
            'Receiver'    => [
                'Name'         => $order['receiver_name'],
                'Tel'          => $order['receiver_tel'],
                'Mobile'       => $order['receiver_mobile'],
                'ProvinceName' => $order['receiver_province'],
                'CityName'     => $order['receiver_city'],
                'ExpAreaName'  => $order['receiver_area'],
                'Address'      => $order['receiver_address'],
            ],
            'Sender'      => [
                'Name'         => $order['sender_name'],
                'Tel'          => $order['sender_tel'],
                'Mobile'       => $order['sender_mobile'],
                'ProvinceName' => $order['sender_province'],
                'CityName'     => $order['sender_city'],
                'ExpAreaName'  => $order['sender_area'],
                'Address'      => $order['sender_address'],
            ],
            'StartDate'   => date('Y-m-d H:i:s', strtotime('+5 minutes')), // 当前时间 + 5分钟
            'EndDate'     => date('Y-m-d 23:59:59'), // 今天 23:59:59
            'Quantity'    => $order['quantity'],
            'Commodity'   => $this->buildCommodity($order),
        ];
        $url = 'https://api.kdniao.com/api/OOrderService';

        return $this->request($url, $data, '1001');
    }

    /**
     * 取消预约取件（1004）
     * https://www.yuque.com/kdnjishuzhichi/dfcrg1/fu64qt
     */
    public function cancelYuyueOrder(array $order): array
    {
        $data = [
            'ShipperCode' => $order['shipper_code'],
            'OrderCode'   => $order['order_code'],
        ];
        $url = 'https://api.kdniao.com/api/OOrderService';

        return $this->request($url, $data, '1004');
    }

    /** 生成签名（RequestData+ApiKey 的 md5 再 base64） */
    public function sign(array $params): string
    {
        $requestData = json_encode($params, JSON_UNESCAPED_UNICODE);
        $signStr     = $requestData . $this->accountSecret;

        return base64_encode(md5($signStr));
    }

    /**
     * 快递公司数据字典（照抄参考系统口径）
     * isYuyue:是否支持预约取件 isBind:需要通知快递鸟技术后台绑定 cancel:是否支持取消电子面单
     */
    public static function expressData(string $code = ''): array
    {
        $data = [
            'ZTO'    => [
                'name'    => '中通快递',
                'code'    => 'ZTO',
                'field'   => ['CustomerName', 'CustomerPwd'],
                'expType' => [23 => '标准快递'],
                'isYuyue' => 1,
                'isBind'  => 1,
            ],
            'STO'    => [
                'name'    => '申通快递',
                'code'    => 'STO',
                'field'   => ['CustomerName', 'CustomerPwd', 'SendSite'],
                'expType' => [1 => '标准快递', 4 => '生鲜'],
                'isYuyue' => 1,
            ],
            'YTO'    => [
                'name'    => '圆通速递',
                'code'    => 'YTO',
                'field'   => ['CustomerName', 'MonthCode'],
                'expType' => [1 => '标准快递', 2 => '圆准达'],
                'isYuyue' => 1,
            ],
            'YD'     => [
                'name'    => '韵达速递',
                'code'    => 'YD',
                'field'   => ['CustomerName', 'CustomerPwd'],
                'isYuyue' => 1,
                'cancel'  => 1,
            ],
            'YDKY'   => [
                'name'    => '韵达快运',
                'code'    => 'YDKY',
                'field'   => ['CustomerName', 'CustomerPwd'],
                'expType' => [1 => '标准快运', 2 => '韵达智橙网'],
            ],
            'YZPY'   => [
                'name'    => '邮政快递包裹',
                'code'    => 'YZPY',
                'field'   => ['CustomerName'],
                'expType' => [1 => '标准快递'],
            ],
            'EMS'    => [
                'name'         => 'EMS',
                'code'         => 'EMS',
                'field'        => ['CustomerName'],
                'expType'      => [
                    1  => '特快专递',
                    8  => '代收到付',
                    9  => '快递包裹',
                    10 => '电商标快',
                    11 => '国内标快',
                ],
                'templateSize' => [10 => 130, 11 => 130],
            ],
            'SF'     => [
                'name'    => '顺丰速运',
                'code'    => 'SF',
                'field'   => ['MonthCode'],
                'expType' => [
                    1   => '顺丰特快',
                    2   => '顺丰标快',
                    6   => '顺丰即日',
                    59  => 'E顺递',
                    155 => '标准零担',
                    199 => '极速包裹',
                    201 => '冷运标快',
                    202 => '顺丰微小件',
                    231 => '陆运包裹',
                    242 => '丰网速运',
                    247 => '电商标快',
                    255 => '顺丰卡航',
                    374 => '生鲜专递',
                    381 => '大闸蟹专递',
                ],
                'isYuyue' => 1,
            ],
            'ZTOKY'  => [
                'name'   => '中通快运',
                'code'   => 'ZTOKY',
                'field'  => ['CustomerName'],
                'isBind' => 1,
            ],
            'ZTOZY'  => [
                'name'   => '中通智运',
                'code'   => 'ZTOZY',
                'field'  => ['CustomerName'],
                'isBind' => 1,
            ],
            'UC'     => [
                'name'    => '优速快递',
                'code'    => 'UC',
                'field'   => ['CustomerName', 'CustomerPwd'],
                'isYuyue' => 1,
                'cancel'  => 1,
            ],
            'DBL'    => [
                'name'    => '德邦速递',
                'code'    => 'DBL',
                'field'   => ['CustomerName'],
                'expType' => [
                    1  => '标准快递',
                    2  => '微小件特惠',
                    5  => '大件快递360',
                    6  => '精准泡货（原重包入户）',
                    10 => '特快次日（原航空大件次日达）',
                    11 => '航空大件隔日达',
                    12 => '特快专递',
                    20 => '重货特惠',
                    21 => '精准大票电商',
                    22 => '精准大票',
                ],
            ],
            'DBLKY'  => [
                'name'    => '德邦快运',
                'code'    => 'DBLKY',
                'field'   => ['CustomerName'],
                'expType' => [
                    1  => '精准城运/卡航',
                    21 => '整车配送',
                    22 => '精准拼车',
                    23 => '精准专车',
                ],
            ],
            'JTSD'   => [
                'name'    => '极兔速递',
                'code'    => 'JTSD',
                'field'   => ['CustomerName', 'CustomerPwd'],
                'expType' => [1 => '标准快递', 2 => '兔优达'],
            ],
            'CNSD'   => [
                'name'    => '菜鸟速递',
                'code'    => 'CNSD',
                'field'   => ['CustomerName'],
                'expType' => [
                    1 => '当日达',
                    2 => '次日达',
                    3 => '预约配送',
                    4 => '当日下午达',
                    5 => '当日夜间达',
                    6 => '次日上午达',
                ],
            ],
            'HTKYKY' => [
                'name'  => '百世快运',
                'code'  => 'HTKYKY',
                'field' => ['CustomerName', 'CustomerPwd'],
            ],
            'JD'     => [
                'name'        => '京东快递',
                'code'        => 'JD',
                'field'       => ['CustomerName'],
                'customerMap' => ['Param1', 'Param3', 'Param4', 'Param5'],
                'expType'     => [
                    1  => '京东标快',
                    2  => '京东特快',
                    4  => '生鲜标快（原生鲜特惠）',
                    5  => '生鲜特快',
                    8  => '特惠小件/京东特惠',
                    17 => '电商标快（原电商特惠）',
                    18 => '特惠专配',
                ],
                'cancel'      => 1,
                'warmLayer'   => [4, 5], // 温层
            ],
            'JDKY'   => [
                'name'        => '京东快运',
                'code'        => 'JDKY',
                'field'       => [],
                'customerMap' => ['Param2', 'Param3', 'Param4', 'Param5'],
                'expType'     => [
                    1  => '京东标快',
                    2  => '京东特快',
                    4  => '生鲜标快（原生鲜特惠）',
                    5  => '生鲜特快',
                    8  => '特惠小件/京东特惠',
                    17 => '电商标快（原电商特惠）',
                    18 => '特惠专配',
                ],
                'cancel'      => 1,
                'warmLayer'   => [4, 5], // 温层
            ],
            'YMDD'   => [
                'name'    => '壹米滴答',
                'code'    => 'YMDD',
                'field'   => ['CustomerName', 'CustomerPwd'],
                'expType' => [1 => '港到门', 2 => '港到港', 3 => '门到门', 4 => '门到港'],
            ],
            'KYSY'   => [
                'name'    => '跨越速运',
                'code'    => 'KYSY',
                'field'   => ['CustomerName'],
                'expType' => [
                    1  => '当天达',
                    2  => '次日达',
                    3  => '隔日达',
                    5  => '同城即日',
                    6  => '同城次日',
                    7  => '陆运件',
                    10 => '空运',
                    13 => '航空件',
                ],
                'cancel'  => 1,
            ],
            'JGSD'   => [
                'name'  => '京广速递',
                'code'  => 'JGSD',
                'field' => ['CustomerName'],
            ],
            'XFEX'   => [
                'name'  => '信丰物流',
                'code'  => 'XFEX',
                'field' => ['CustomerName', 'CustomerPwd', 'SendSite'],
            ],
            'LHT'    => [
                'name'    => '联昊通速递',
                'code'    => 'LHT',
                'field'   => ['MonthCode'],
                'expType' => [1 => '普通快件', 2 => '生鲜件', 3 => '电子商务件'],
            ],
            'LB'     => [
                'name'  => '龙邦快运',
                'code'  => 'LB',
                'field' => ['CustomerName', 'CustomerPwd'],
            ],
        ];
        if ($code) {
            return $data[$code] ?? [];
        }

        return $data;
    }

    /** 支付方式 */
    public static function paytype($code = ''): array|string
    {
        $data = [1 => '现付', 2 => '到付', 3 => '月结'];
        if ($code) {
            return $data[(int) $code] ?? '';
        }

        return $data;
    }

    /** 温层 */
    public static function warmLayer(string $key = ''): array|string
    {
        $data = [
            'usual'     => '生鲜常温',
            'alive'     => '生鲜鲜活',
            'control'   => '生鲜控温',
            'cold'      => '生鲜冷藏',
            'freezing'  => '生鲜冷冻',
            'deepCool'  => '生鲜深冷',
        ];
        if ($key) {
            return $data[$key] ?? '';
        }

        return $data;
    }

    /**
     * 商品类型构造（对标参考系统：按订单类型取商品分类名；表结构差异时兜底"商品"）
     */
    protected function buildCommodity(array $order): array
    {
        try {
            $ordertype = $order['ordertype'] ?? '';
            if ('shop' === $ordertype && !empty($order['orderid'])) {
                $commodity = [];
                $goods     = Db::name('shop_order_goods')
                    ->where('aid', $this->aid)
                    ->where('orderid', $order['orderid'])
                    ->select()
                    ->toArray();
                foreach ($goods as $val) {
                    $cid = Db::name('shop_product')->where('aid', $this->aid)->where('id', $val['proid'])->value('cid');
                    if (empty($cid)) {
                        $commodity[] = ['GoodsName' => '商品'];
                    } else {
                        $cids         = explode(',', (string) $cid);
                        $categoryName = Db::name('shop_category')->where('aid', $this->aid)->where('id', end($cids))->value('name');
                        $commodity[]  = ['GoodsName' => $categoryName ?: '商品'];
                    }
                }
                if ($commodity) {
                    return $commodity;
                }
            }
        } catch (\Throwable $e) {
            // 表结构差异兜底
        }

        return [['GoodsName' => '商品']];
    }

    /**
     * mock 响应（EWAYBILL.MOCK=1）：固定面单号 + 占位打印模板 HTML
     *
     * @param string $requestType 1007创建面单 1147取消面单 1001预约取件 1004取消预约
     */
    protected function mockResponse(string $requestType, array $params): array
    {
        if ('1007' === $requestType) {
            $orderCode  = (string) ($params['OrderCode'] ?? '');
            $logisticNo = 'MOCK' . strtoupper(substr(md5($orderCode ?: 'ewaybill'), 0, 12));

            return [
                'status' => 1,
                'msg'    => '操作成功',
                'data'   => [
                    'ResultCode'    => '100',
                    'Success'       => true,
                    'Order'         => ['LogisticCode' => $logisticNo, 'ShipperCode' => $params['ShipperCode'] ?? '', 'OrderCode' => $orderCode],
                    'PrintTemplate' => $this->mockPrintTemplate($params, $logisticNo),
                    'SubOrders'     => [],
                    'SubPrintTemplate' => '',
                ],
            ];
        }

        // 1147 取消 / 1001 预约取件 / 1004 取消预约：mock 一律成功
        return [
            'status' => 1,
            'msg'    => '操作成功',
            'data'   => ['ResultCode' => '100', 'Success' => true, 'Reason' => ''],
        ];
    }

    /** 占位打印模板 HTML（mock 模式，前端打印预览直接渲染） */
    protected function mockPrintTemplate(array $params, string $logisticNo): string
    {
        $receiver = $params['Receiver'] ?? [];
        $sender   = $params['Sender'] ?? [];
        $h        = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);

        return '<div style="width:700px;border:2px solid #333;padding:12px;font-family:微软雅黑,sans-serif;font-size:14px;color:#333;">'
            . '<div style="text-align:center;font-size:18px;font-weight:bold;border-bottom:2px solid #333;padding-bottom:8px;">电子面单（MOCK 演示模板）</div>'
            . '<table style="width:100%;border-collapse:collapse;margin-top:8px;">'
            . '<tr><td style="border:1px solid #999;padding:6px;width:90px;">运单号</td><td style="border:1px solid #999;padding:6px;font-size:20px;font-weight:bold;">' . $h($logisticNo) . '</td></tr>'
            . '<tr><td style="border:1px solid #999;padding:6px;">面单订单号</td><td style="border:1px solid #999;padding:6px;">' . $h($params['OrderCode'] ?? '') . '</td></tr>'
            . '<tr><td style="border:1px solid #999;padding:6px;">快递公司</td><td style="border:1px solid #999;padding:6px;">' . $h($params['ShipperCode'] ?? '') . '</td></tr>'
            . '<tr><td style="border:1px solid #999;padding:6px;">收件人</td><td style="border:1px solid #999;padding:6px;">'
            . $h(($receiver['Name'] ?? '') . ' ' . ($receiver['Mobile'] ?? '') . ' ' . ($receiver['ProvinceName'] ?? '') . ($receiver['CityName'] ?? '') . ($receiver['ExpAreaName'] ?? '') . ($receiver['Address'] ?? ''))
            . '</td></tr>'
            . '<tr><td style="border:1px solid #999;padding:6px;">寄件人</td><td style="border:1px solid #999;padding:6px;">'
            . $h(($sender['Name'] ?? '') . ' ' . ($sender['Mobile'] ?? '') . ' ' . ($sender['ProvinceName'] ?? '') . ($sender['CityName'] ?? '') . ($sender['ExpAreaName'] ?? '') . ($sender['Address'] ?? ''))
            . '</td></tr>'
            . '<tr><td style="border:1px solid #999;padding:6px;">备注</td><td style="border:1px solid #999;padding:6px;">' . $h($params['Remark'] ?? '') . '</td></tr>'
            . '</table>'
            . '<div style="text-align:center;margin-top:10px;color:#999;font-size:12px;">EWAYBILL.MOCK=1 占位模板 · 真实对接后由快递鸟返回标准模板</div>'
            . '</div>';
    }

    /**
     * 接口请求（真实模式：form 表单 + 签名，请求快递鸟网关）
     */
    public function request(string $url, array $params, string $requestType): array
    {
        if (self::isMock()) {
            return $this->mockResponse($requestType, $params);
        }

        if ('' === (string) $this->accountId || '' === (string) $this->accountSecret) {
            return ['status' => 0, 'msg' => '请先在面单设置中配置快递鸟账号（用户ID/API KEY）', 'data' => []];
        }

        $data = http_build_query([
            'EBusinessID' => $this->accountId,
            'RequestType' => $requestType,
            'RequestData' => json_encode($params, JSON_UNESCAPED_UNICODE),
            'DataSign'    => $this->sign($params),
            'DataType'    => '2',
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $data,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded; charset=UTF-8'],
        ]);
        $res = curl_exec($ch);
        curl_close($ch);

        if ($res === false || $res === null) {
            return ['status' => 0, 'msg' => '请求失败', 'data' => []];
        }

        $result = json_decode((string) $res, true);
        Log::write(['result' => $result, 'params' => $params, 'data' => $data], 'kdniao');
        if (!is_array($result) || ($result['ResultCode'] ?? '') != 100) {
            return ['status' => 0, 'msg' => ($result['Reason'] ?? '') ?: '操作失败', 'data' => is_array($result) ? $result : []];
        }

        return ['status' => 1, 'msg' => '操作成功', 'data' => $result];
    }
}
