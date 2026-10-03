<?php

declare(strict_types=1);

namespace app\common;

use app\common\exception\BusinessException;
use think\facade\Db;

/**
 * 抖音（头条）小程序支付通道
 *
 * 从参考系统 canzhao\app\common\Ttpay.php 移植：
 * - build     创建订单（下单支付）
 * - refund    退款
 * - settle    分账（在途资金 → 可提现）
 * - pushorder 订单同步/核销（推送到抖音订单中心）
 * - access_token 抖音小程序 access_token（access_token 表缓存 7000s）
 * - getSign   ECPay 签名
 *
 * 配置读取：admin_setapp_toutiao（按 aid 取 appid/appsecret/pay_token/pay_mchid/pay_salt）；
 * appid 或 pay_salt 缺失时抛 BusinessException，不允许 500。
 */
class Ttpay
{
    /**
     * 读取抖音小程序支付配置（按 aid 隔离）
     *
     * @return array<string, mixed>
     */
    protected static function config(int $aid): array
    {
        $row = Db::name('admin_setapp_toutiao')->where('aid', $aid)->find() ?: [];
        $appid     = (string) ($row['appid'] ?? '');
        $paySalt   = (string) ($row['pay_salt'] ?? '');
        if ('' === $appid || '' === $paySalt) {
            throw new BusinessException('未配置抖音小程序支付参数（appid/支付salt）');
        }
        return $row;
    }

    /**
     * 统一 HTTP POST（JSON body），返回解码后的数组
     *
     * @param array<int, string> $headers 自定义请求头
     * @return array<string, mixed>
     */
    protected static function httpPost(string $url, array $data, array $headers = []): array
    {
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (false === $body) {
            $body = '{}';
        }
        if (empty($headers)) {
            $headers = ['Content-type: application/json;charset=utf-8'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $body,
        ]);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);

        if (false === $resp) {
            return ['err_no' => -1, 'err_tips' => 'curl error: ' . $err];
        }
        $decoded = json_decode((string) $resp, true);
        return is_array($decoded) ? $decoded : ['err_no' => -1, 'err_tips' => 'invalid response', 'raw' => (string) $resp];
    }

    // 头条小程序支付（创建订单）
    public static function build($aid, $mid, $title, $ordernum, $price, $tablename, $notify_url = '')
    {
        $toutiaoapp = self::config((int) $aid);

        $url = 'https://developer.toutiao.com/api/apps/ecpay/v1/create_order';

        $data = [];
        $data['app_id']      = $toutiaoapp['appid'];
        $data['out_order_no'] = $ordernum;
        $data['total_amount'] = intval(bcmul((string) $price, '100', 2));
        $data['subject']     = $title;
        $data['body']        = $title;
        $data['valid_time']  = 86400;
        $data['cp_extra']    = json_encode(['param' => $aid . ':' . $tablename, 'total_amount' => $data['total_amount']]);
        $data['notify_url']  = $notify_url;
        $data['sign']        = self::getSign($data, (string) $toutiaoapp['pay_salt']);
        $rs = self::httpPost($url, $data);
        if (($rs['err_no'] ?? -1) == 0) {
            return ['status' => 1, 'orderInfo' => $rs['data'] ?? []];
        }
        return ['status' => 0, 'msg' => $rs['err_tips'] ?? '创建订单失败'];
    }

    /**
     * 退款
     *
     * @return array<string, mixed>
     */
    public static function refund($aid, $ordernum, $totalprice, $refund_money, $reason)
    {
        $toutiaoapp = self::config((int) $aid);
        $url = 'https://developer.toutiao.com/api/apps/ecpay/v1/create_refund';
        $data = [];
        $data['app_id']       = $toutiaoapp['appid'];
        $data['out_order_no'] = $ordernum;
        $data['out_refund_no'] = date('YmdHis');
        $data['refund_amount'] = intval(bcmul((string) $refund_money, '100', 2));
        $data['reason']       = $reason;
        $data['sign']         = self::getSign($data, (string) $toutiaoapp['pay_salt']);
        $rs = self::httpPost($url, $data);
        if (($rs['err_no'] ?? -1) == 0) {
            return ['status' => 1, 'msg' => '退款发起成功', 'refund_no' => $rs['refund_no'] ?? ''];
        }
        return ['status' => 0, 'msg' => $rs['err_tips'] ?? '退款失败'];
    }

    // 头条小程序分账
    public static function settle($aid, $ordernum, $out_settle_no = '', $settle_desc = '分账')
    {
        $out_settle_no = $out_settle_no ? $out_settle_no : date('YmdHis') . rand(100000, 999999);

        $toutiaoapp = self::config((int) $aid);

        $url = 'https://developer.toutiao.com/api/apps/ecpay/v1/settle';

        $data = [];
        $data['app_id']       = $toutiaoapp['appid'];
        $data['out_order_no'] = $ordernum;
        $data['out_settle_no'] = $out_settle_no;
        $data['settle_desc']  = $settle_desc;
        $data['sign']         = self::getSign($data, (string) $toutiaoapp['pay_salt']);
        $rs = self::httpPost($url, $data);
        if (($rs['err_no'] ?? -1) == 0) {
            return ['status' => 1, 'orderInfo' => $rs['data'] ?? []];
        }
        return ['status' => 0, 'msg' => $rs['err_tips'] ?? '分账失败'];
    }

    // 订单同步 0未支付 1已支付 2已取消 4已核销 5退款中 6已退款 8退款失败
    public static function pushorder($aid, $ordernum, $order_status = 4)
    {
        $aid = (int) $aid;
        $payorder = Db::name('payorder')->where('aid', $aid)->where('ordernum', $ordernum)->find();
        if (!$payorder) {
            return ['status' => 0, 'msg' => '未找到支付订单'];
        }
        $member = Db::name('member')->where('id', $payorder['mid'])->find();

        $toutiaoapp = self::config($aid);

        $data = [];
        $data['app_id']      = $toutiaoapp['appid'];
        $data['access_token'] = self::access_token($aid);
        $data['app_name']    = 'douyin';
        $data['open_id']     = $member['toutiaoopenid'] ?? '';
        $data['order_status'] = $order_status;
        $data['order_type']  = 0;
        $data['update_time'] = intval(microtime(true) * 1000);
        $order_detail = [];
        $order_detail['order_id']   = $ordernum;
        $order_detail['create_time'] = intval(($payorder['paytime'] ?? 0) . '000');
        if (0 == $order_status) {
            $order_detail['status'] = '待支付';
        } elseif (1 == $order_status) {
            $order_detail['status'] = '已支付';
        } elseif (2 == $order_status) {
            $order_detail['status'] = '已取消';
        } elseif (4 == $order_status) {
            $order_detail['status'] = '已核销';
        } elseif (5 == $order_status) {
            $order_detail['status'] = '退款中';
        } elseif (6 == $order_status) {
            $order_detail['status'] = '已退款';
        } elseif (8 == $order_status) {
            $order_detail['status'] = '退款失败';
        }
        $order_detail['total_price'] = intval(bcmul((string) ($payorder['money'] ?? '0'), '100', 2));
        if ('shop' === $payorder['type']) {
            $order_detail['detail_url'] = 'pagesM/shop/orderdetail?id=' . $payorder['orderid'];
            $oglist = Db::name('shop_order_goods')->where('orderid', $payorder['orderid'])->select()->toArray();
            $item_list = [];
            $amount = 0;
            foreach ($oglist as $og) {
                $amount += $og['num'];
                $item_list[] = ['item_code' => '' . $og['proid'], 'img' => $og['pic'], 'title' => $og['name'], 'amount' => intval($og['num']), 'price' => intval(bcmul((string) $og['totalprice'], '100', 2))];
            }
            $order_detail['amount'] = $amount;
            $order_detail['item_list'] = $item_list;
        } elseif ('scoreshop' === $payorder['type']) {
            $order_detail['detail_url'] = 'activity/scoreshop/orderdetail?id=' . $payorder['orderid'];
            $oglist = Db::name('scoreshop_order_goods')->where('orderid', $payorder['orderid'])->select()->toArray();
            $item_list = [];
            $amount = 0;
            foreach ($oglist as $og) {
                $amount += $og['num'];
                $item_list[] = ['item_code' => '' . $og['proid'], 'img' => $og['pic'], 'title' => $og['name'], 'amount' => intval($og['num']), 'price' => intval(bcmul((string) $og['totalmoney'], '100', 2))];
            }
            $order_detail['amount'] = $amount;
            $order_detail['item_list'] = $item_list;
        } elseif ('collage' === $payorder['type']) {
            $order_detail['detail_url'] = 'activity/collage/orderdetail?id=' . $payorder['orderid'];
            $orderinfo = Db::name('collage_order')->where('id', $payorder['orderid'])->find();
            $order_detail['amount'] = $orderinfo['num'] ?? 0;
            $order_detail['item_list'] = [['item_code' => '' . ($orderinfo['proid'] ?? ''), 'img' => $orderinfo['propic'] ?? '', 'title' => $orderinfo['proname'] ?? '', 'amount' => intval($orderinfo['num'] ?? 0), 'price' => intval(bcmul((string) ($orderinfo['totalprice'] ?? '0'), '100', 2))]];
        } elseif ('kanjia' === $payorder['type']) {
            $order_detail['detail_url'] = 'activity/kanjia/orderdetail?id=' . $payorder['orderid'];
            $orderinfo = Db::name('kanjia_order')->where('id', $payorder['orderid'])->find();
            $order_detail['amount'] = $orderinfo['num'] ?? 0;
            $order_detail['item_list'] = [['item_code' => '' . ($orderinfo['proid'] ?? ''), 'img' => $orderinfo['propic'] ?? '', 'title' => $orderinfo['proname'] ?? '', 'amount' => intval($orderinfo['num'] ?? 0), 'price' => intval(bcmul((string) ($orderinfo['totalprice'] ?? '0'), '100', 2))]];
        } elseif ('seckill' === $payorder['type']) {
            $order_detail['detail_url'] = 'activity/seckill/orderdetail?id=' . $payorder['orderid'];
            $orderinfo = Db::name('seckill_order')->where('id', $payorder['orderid'])->find();
            $order_detail['amount'] = $orderinfo['num'] ?? 0;
            $order_detail['item_list'] = [['item_code' => '' . ($orderinfo['proid'] ?? ''), 'img' => $orderinfo['propic'] ?? '', 'title' => $orderinfo['proname'] ?? '', 'amount' => intval($orderinfo['num'] ?? 0), 'price' => intval(bcmul((string) ($orderinfo['totalprice'] ?? '0'), '100', 2))]];
        } elseif ('tuangou' === $payorder['type']) {
            $order_detail['detail_url'] = 'activity/tuangou/orderdetail?id=' . $payorder['orderid'];
            $orderinfo = Db::name('tuangou_order')->where('id', $payorder['orderid'])->find();
            $order_detail['amount'] = intval($orderinfo['num'] ?? 0);
            $order_detail['item_list'] = [['item_code' => '' . ($orderinfo['proid'] ?? ''), 'img' => $orderinfo['propic'] ?? '', 'title' => $orderinfo['proname'] ?? '', 'amount' => intval($orderinfo['num'] ?? 0), 'price' => intval(bcmul((string) ($orderinfo['totalprice'] ?? '0'), '100', 2))]];
        } elseif ('kecheng' === $payorder['type']) {
            $order_detail['detail_url'] = 'activity/kecheng/orderlist';
            $orderinfo = Db::name('kecheng_order')->where('id', $payorder['orderid'])->find();
            $order_detail['amount'] = 1;
            $order_detail['item_list'] = [['item_code' => '' . ($orderinfo['kcid'] ?? ''), 'img' => $orderinfo['pic'] ?? '', 'title' => $orderinfo['title'] ?? '', 'amount' => 1, 'price' => intval(bcmul((string) ($orderinfo['totalprice'] ?? '0'), '100', 2))]];
        } else {
            $adminset = Db::name('admin_set')->where('aid', $aid)->find();
            $order_detail['detail_url'] = 'pages/my/usercenter';
            $order_detail['amount'] = 1;
            $order_detail['item_list'] = [['item_code' => '' . $payorder['orderid'], 'img' => $adminset['logo'] ?? '', 'title' => '支付订单', 'amount' => 1, 'price' => intval(bcmul((string) ($payorder['money'] ?? '0'), '100', 2))]];
        }
        $data['order_detail'] = json_encode($order_detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $url = 'https://developer.toutiao.com/api/apps/order/v2/push';
        $headers = ["Content-type: application/json;charset='utf-8'"];
        $rs = self::httpPost($url, $data, $headers);
        if (($rs['err_code'] ?? -1) == 0) {
            return ['status' => 1, 'data' => $rs];
        }
        return ['status' => 0, 'msg' => $rs['err_msg'] ?? '推送失败'];
    }

    /**
     * 抖音小程序 access_token（access_token 表缓存 7000s）
     *
     * @return string
     */
    public static function access_token($aid, $iscache = true)
    {
        $aid = (int) $aid;
        $toutiaoapp = self::config($aid);
        $appid     = (string) ($toutiaoapp['appid'] ?? '');
        $appsecret = (string) ($toutiaoapp['appsecret'] ?? '');
        if ('' === $appid) {
            return '';
        }
        $tokendata = Db::name('access_token')->where('appid', $appid)->find();
        if ($iscache && $tokendata && $tokendata['access_token'] && (int) $tokendata['expires_time'] > time()) {
            return (string) $tokendata['access_token'];
        }
        if ('' === $appsecret) {
            throw new BusinessException('未配置抖音小程序 AppSecret，无法获取 access_token');
        }
        $url = 'https://developer.toutiao.com/api/apps/v2/token';
        $data = [];
        $data['appid']      = $appid;
        $data['secret']     = $appsecret;
        $data['grant_type'] = 'client_credential';

        $headers = ["Content-type: application/json;charset='utf-8'"];
        $rs = self::httpPost($url, $data, $headers);
        if (($rs['err_no'] ?? -1) == 0) {
            $access_token = (string) ($rs['data']['access_token'] ?? '');
            if ($tokendata) {
                Db::name('access_token')->where('appid', $appid)->update(['access_token' => $access_token, 'expires_time' => time() + 7000]);
            } else {
                Db::name('access_token')->insert(['appid' => $appid, 'access_token' => $access_token, 'expires_time' => time() + 7000]);
            }
            return $access_token;
        }
        throw new BusinessException('获取抖音 access_token 失败：' . ($rs['err_tips'] ?? 'unknown'));
    }

    /**
     * ECPay 签名
     *
     * @param array<string, mixed> $params
     * @return string
     */
    protected static function getSign($params, $paysecret)
    {
        unset($params['sign'], $params['app_id'], $params['thirdparty_id']);
        $paramArray = [];
        foreach ($params as $param) {
            $paramArray[] = trim((string) $param);
        }
        $paramArray[] = trim((string) $paysecret);
        sort($paramArray, SORT_STRING);
        $signStr = trim(implode('&', $paramArray));
        return md5($signStr);
    }
}
