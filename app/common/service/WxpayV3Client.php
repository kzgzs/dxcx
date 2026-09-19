<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

/**
 * 微信支付 V3 客户端（Guzzle + openssl 自签，无 SDK 依赖）
 *
 * 能力清单：
 * - JSAPI 下单（直连 /v3/pay/transactions/jsapi；服务商 /v3/pay/partner/transactions/jsapi）
 * - 小程序拉起支付参数组装（prepay_id → appId/timeStamp/nonceStr/package/signType/paySign）
 * - 查单补偿 GET /v3/pay/transactions/out-trade-no/{out_trade_no}
 * - 退款 POST /v3/refund/domestic/refunds（服务商带 sub_mchid）
 * - 分账 POST /v3/profitsharing/orders、分账回退 POST /v3/profitsharing/return-orders
 * - 回调验签（时间戳偏移 + 平台证书 SHA256-RSA）与 resource AES-256-GCM 解密
 *
 * 请求签名：SHA256withRSA（HTTP方法
URL
时间戳
随机串
正文
 + 商户API私钥）
 */
class WxpayV3Client
{
    private const BASE_URL = 'https://api.mch.weixin.qq.com';

    private Client $http;

    /**
     * @param array{
     *   mchid: string,               签名主体商户号（直连=自身商户号；服务商=服务商商户号 sp_mchid）
     *   serial_no: string,           商户API证书序列号
     *   private_key: string,         商户API私钥 PEM 内容
     *   apiv3_key: string,           APIv3 密钥（回调解密）
     *   appid?: string,              直连 appid
     *   mode?: string,               direct 直连 / partner 服务商
     *   sp_appid?: string,           服务商 appid
     *   sub_appid?: string,          服务商模式子商户 appid（可空）
     *   sub_mchid?: string,          服务商模式子商户号
     *   platform_cert?: string,      平台证书 PEM 内容（回调验签用，可空）
     * } $config
     */
    public function __construct(protected array $config)
    {
        $this->http = new Client([
            'base_uri' => self::BASE_URL,
            'timeout'  => 15,
            'http_errors' => true,
        ]);
    }

    /** 是否服务商模式 */
    public function isPartner(): bool
    {
        return 'partner' === ($this->config['mode'] ?? 'direct');
    }

    /* ================= 业务接口 ================= */

    /**
     * JSAPI 下单，返回 prepay_id
     *
     * @param array{out_trade_no: string, description: string, notify_url: string,
     *              total_fee: int, openid: string, profit_sharing?: bool} $params
     */
    public function jsapiOrder(array $params): string
    {
        $body = [
            'description'  => (string) $params['description'],
            'out_trade_no' => (string) $params['out_trade_no'],
            'notify_url'   => (string) $params['notify_url'],
            'amount'       => ['total' => (int) $params['total_fee'], 'currency' => 'CNY'],
        ];

        if (!empty($params['profit_sharing'])) {
            // 抽成订单：下单标记分账，资金冻结待分账
            $body['settle_info'] = ['profit_sharing' => true];
        }

        if ($this->isPartner()) {
            $body['sp_appid']  = (string) ($this->config['sp_appid'] ?? '');
            $body['sp_mchid']  = (string) $this->config['mchid'];
            $body['sub_mchid'] = (string) ($this->config['sub_mchid'] ?? '');
            if ('' !== (string) ($this->config['sub_appid'] ?? '')) {
                $body['sub_appid'] = (string) $this->config['sub_appid'];
            }
            $body['payer'] = ['sub_openid' => (string) $params['openid']];
            $url = '/v3/pay/partner/transactions/jsapi';
        } else {
            $body['appid']  = (string) ($this->config['appid'] ?? '');
            $body['mchid']  = (string) $this->config['mchid'];
            $body['payer']  = ['openid' => (string) $params['openid']];
            $url = '/v3/pay/transactions/jsapi';
        }

        $result = $this->request('POST', $url, $body);

        if (empty($result['prepay_id'])) {
            throw new BusinessException('微信支付下单失败：未返回 prepay_id');
        }

        return (string) $result['prepay_id'];
    }

    /**
     * 组装小程序 wx.requestPayment 拉起参数（paySign 为 RSA 签名）
     *
     * @return array{appId: string, timeStamp: string, nonceStr: string, package: string, signType: string, paySign: string}
     */
    public function buildLaunchParams(string $prepayId): array
    {
        $appId = $this->isPartner()
            ? ((string) ($this->config['sub_appid'] ?? '') ?: (string) ($this->config['sp_appid'] ?? ''))
            : (string) ($this->config['appid'] ?? '');

        $timeStamp = (string) time();
        $nonceStr  = self::nonce();
        $package   = 'prepay_id=' . $prepayId;

        $message = $appId . "\n" . $timeStamp . "\n" . $nonceStr . "\n" . $package . "\n";

        return [
            'appId'     => $appId,
            'timeStamp' => $timeStamp,
            'nonceStr'  => $nonceStr,
            'package'   => $package,
            'signType'  => 'RSA',
            'paySign'   => $this->sign($message),
        ];
    }

    /**
     * 商户订单号查单（查单补偿）
     *
     * @param string $subMchid 服务商模式下可指定子商户号（退款原路场景，缺省用当前配置）
     * @return array<string, mixed> 渠道订单信息（trade_state/transaction_id/amount...）
     */
    public function queryByOutTradeNo(string $outTradeNo, string $subMchid = ''): array
    {
        if ($this->isPartner()) {
            $query = http_build_query([
                'sp_mchid'  => (string) $this->config['mchid'],
                'sub_mchid' => $subMchid ?: (string) ($this->config['sub_mchid'] ?? ''),
            ]);
            $url = '/v3/pay/partner/transactions/out-trade-no/' . rawurlencode($outTradeNo) . '?' . $query;
        } else {
            $url = '/v3/pay/transactions/out-trade-no/' . rawurlencode($outTradeNo)
                . '?mchid=' . rawurlencode((string) $this->config['mchid']);
        }

        return $this->request('GET', $url);
    }

    /**
     * 申请退款 POST /v3/refund/domestic/refunds
     *
     * @param array{out_trade_no: string, out_refund_no: string, total_fee: int, refund_fee: int,
     *              reason?: string, notify_url?: string, sub_mchid?: string} $params
     * @return array<string, mixed> 渠道退款结果（status: SUCCESS/PROCESSING/CLOSED/ABNORMAL）
     */
    public function refund(array $params): array
    {
        $body = [
            'out_trade_no'  => (string) $params['out_trade_no'],
            'out_refund_no' => (string) $params['out_refund_no'],
            'amount'        => [
                'refund'   => (int) $params['refund_fee'],
                'total'    => (int) $params['total_fee'],
                'currency' => 'CNY',
            ],
        ];

        if (!empty($params['reason'])) {
            $body['reason'] = (string) $params['reason'];
        }
        if (!empty($params['notify_url'])) {
            $body['notify_url'] = (string) $params['notify_url'];
        }
        if ($this->isPartner()) {
            // sub_mchid 允许外部指定（从原支付 wxpay_log 取，保证原路退回）
            $body['sub_mchid'] = (string) ($params['sub_mchid'] ?? $this->config['sub_mchid'] ?? '');
        }

        return $this->request('POST', '/v3/refund/domestic/refunds', $body);
    }

    /**
     * 分账请求 POST /v3/profitsharing/orders（仅服务商模式）
     *
     * @param array{transaction_id: string, out_order_no: string, appid: string,
     *              receivers: array<int, array<string, mixed>>, unset_profit_sharing?: bool} $params
     */
    public function profitSharing(array $params): array
    {
        $body = [
            'appid'          => (string) $params['appid'],
            'transaction_id' => (string) $params['transaction_id'],
            'out_order_no'   => (string) $params['out_order_no'],
            'receivers'      => $params['receivers'],
        ];

        if (array_key_exists('unset_profit_sharing', $params)) {
            $body['unset_profit_sharing'] = (bool) $params['unset_profit_sharing'];
        }
        if ($this->isPartner()) {
            $body['sub_mchid'] = (string) ($params['sub_mchid'] ?? $this->config['sub_mchid'] ?? '');
        }

        return $this->request('POST', '/v3/profitsharing/orders', $body);
    }

    /**
     * 分账回退 POST /v3/profitsharing/return-orders（已分账订单退款前先回退）
     *
     * @param array{order_id?: string, out_order_no?: string, out_return_no: string,
     *              return_mchid: string, amount: int, total: int, description?: string, sub_mchid?: string} $params
     */
    public function profitSharingReturn(array $params): array
    {
        $body = [
            'out_return_no' => (string) $params['out_return_no'],
            'return_mchid'  => (string) $params['return_mchid'],
            'amount'        => (int) $params['amount'],
            'total'         => (int) $params['total'],
            'description'   => (string) ($params['description'] ?? '退款分账回退'),
        ];

        if (!empty($params['order_id'])) {
            $body['order_id'] = (string) $params['order_id'];
        } else {
            $body['out_order_no'] = (string) ($params['out_order_no'] ?? '');
        }
        if ($this->isPartner()) {
            $body['sub_mchid'] = (string) ($params['sub_mchid'] ?? $this->config['sub_mchid'] ?? '');
        }

        return $this->request('POST', '/v3/profitsharing/return-orders', $body);
    }

    /* ================= 回调验签 / 解密 ================= */

    /**
     * 回调验签：时间戳偏移 ≤300s + 平台证书 SHA256-RSA 验证
     *
     * 验签串：时间戳\n随机串\n原始报文\n，用平台证书公钥验证 Wechatpay-Signature
     */
    public static function verifyNotify(
        string $timestamp,
        string $nonce,
        string $body,
        string $signature,
        string $platformCertPem
    ): bool {
        if ('' === $timestamp || '' === $nonce || '' === $signature || '' === $platformCertPem) {
            return false;
        }

        // 时间偏移校验（允许 5 分钟）
        if (abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        $publicKey = openssl_pkey_get_public($platformCertPem);
        if (false === $publicKey) {
            return false;
        }

        $message = $timestamp . "\n" . $nonce . "\n" . $body . "\n";
        $decoded = base64_decode($signature, true);
        if (false === $decoded) {
            return false;
        }

        return 1 === openssl_verify($message, $decoded, $publicKey, OPENSSL_ALGO_SHA256);
    }

    /**
     * AES-256-GCM 解密回调 resource（密钥为 APIv3 密钥）
     *
     * @return string 解密后的明文 JSON；失败返回空串
     */
    public static function decryptResource(string $ciphertext, string $apiv3Key, string $nonce, string $aad): string
    {
        $raw = base64_decode($ciphertext, true);
        if (false === $raw || strlen($raw) <= 16 || strlen($apiv3Key) !== 32) {
            return '';
        }

        // GCM 认证标签为密文末尾 16 字节
        $tag  = substr($raw, -16);
        $data = substr($raw, 0, -16);

        $plain = openssl_decrypt($data, 'aes-256-gcm', $apiv3Key, OPENSSL_RAW_DATA, $nonce, $tag, $aad);

        return false === $plain ? '' : $plain;
    }

    /* ================= 底层请求 / 签名 ================= */

    /**
     * 发起带签名的 V3 请求
     *
     * @return array<string, mixed> 响应 JSON（204 无响应体返回空数组）
     */
    public function request(string $method, string $urlPath, ?array $body = null): array
    {
        $json  = null === $body ? '' : json_encode($body, JSON_UNESCAPED_UNICODE);
        $stamp = (string) time();
        $nonce = self::nonce();

        $message = strtoupper($method) . "\n" . $urlPath . "\n" . $stamp . "\n" . $nonce . "\n" . $json . "\n";

        $authorization = sprintf(
            'WECHATPAY2-SHA256-RSA2048 mchid="%s",nonce_str="%s",timestamp="%s",serial_no="%s",signature="%s"',
            (string) $this->config['mchid'],
            $nonce,
            $stamp,
            (string) ($this->config['serial_no'] ?? ''),
            $this->sign($message)
        );

        $options = [
            'headers' => [
                'Authorization' => $authorization,
                'Accept'        => 'application/json',
                'User-Agent'    => 'dxcx-wxpay-v3/1.0',
            ],
        ];
        if ('' !== $json) {
            $options['headers']['Content-Type'] = 'application/json';
            $options['body'] = $json;
        }

        try {
            $response = $this->http->request($method, $urlPath, $options);
        } catch (RequestException $e) {
            $detail  = '';
            $resp    = $e->getResponse();
            if (null !== $resp) {
                $decoded = json_decode((string) $resp->getBody(), true);
                $detail  = is_array($decoded) ? (string) ($decoded['message'] ?? '') : '';
            }
            throw new BusinessException('微信支付接口请求失败：' . ($detail ?: $e->getMessage()));
        }

        $content = (string) $response->getBody();

        return '' === $content ? [] : ((array) (json_decode($content, true) ?: []));
    }

    /**
     * SHA256withRSA 请求签名（商户API私钥）
     */
    private function sign(string $message): string
    {
        $privateKey = openssl_pkey_get_private((string) ($this->config['private_key'] ?? ''));
        if (false === $privateKey) {
            throw new BusinessException('微信支付商户私钥无效，请检查证书配置');
        }

        if (!openssl_sign($message, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new BusinessException('微信支付签名失败');
        }

        return base64_encode($signature);
    }

    /** 32 位随机串 */
    public static function nonce(): string
    {
        return strtoupper(bin2hex(random_bytes(16)));
    }
}
