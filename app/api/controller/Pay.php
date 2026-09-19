<?php

declare(strict_types=1);

namespace app\api\controller;

use app\common\exception\BusinessException;
use app\common\service\PayService;
use app\common\service\WxpayService;
use app\BaseController;

/**
 * 客户端 - 支付回调端点（api 应用，白名单免 JWT）
 *
 * - POST /api/pay/notify/wechat       微信支付回调（mock：明文 JSON；真实：V3 验签+解密，返回 {code:SUCCESS}）
 * - POST /api/pay/notify/wechatRefund 微信退款回调（真实模式，回写 wxrefund_log + payorder.refund_money）
 * - POST /api/pay/notify/alipay       支付宝回调（返回 success）
 * - POST /api/pay/notify/mock         模拟第三方回调（仅 mock 模式，{order_sn}）
 *
 * 路由：out_trade_no 命中 pay_order 走行业订单入账，未命中回落 shop_order ordernum
 */
class Pay extends BaseController
{
    public function __construct(\think\App $app, protected PayService $payService)
    {
        parent::__construct($app);
    }

    /**
     * 微信支付回调
     * - mock 模式：明文 JSON body，out_trade_no 直接幂等入账
     * - 真实模式：V3 回调（时间戳偏移≤300s + 平台证书 SHA256-RSA 验签 + AES-256-GCM 解密），
     *   验签失败直接 403 不入库；金额比对不符拒付
     */
    public function notifyWechat()
    {
        $rawBody = (string) $this->request->getContent();

        if (!PayService::isMock()) {
            $result = WxpayService::handleNotify(
                $this->notifyHeaders(),
                $rawBody,
                (int) $this->request->get('aid', 0)
            );

            return json($result['body'], $result['http_code']);
        }

        $body = json_decode($rawBody, true);
        $body = is_array($body) ? $body : [];

        if (!$this->payService->verifyNotifySignature('wechat', $body)) {
            return json(['code' => 'FAIL', 'message' => '验签失败'], 401);
        }

        $orderSn = trim((string) ($body['out_trade_no'] ?? ''));

        try {
            $this->payService->notifyByOutTradeNo($orderSn, 'wechat', (string) ($body['transaction_id'] ?? ''));
        } catch (BusinessException) {
            return json(['code' => 'FAIL', 'message' => '订单不存在']);
        }

        return json(['code' => 'SUCCESS', 'message' => '成功']);
    }

    /**
     * 微信退款回调（真实模式：REFUND.SUCCESS/ABNORMAL/CLOSED）
     * 回写 wxrefund_log 状态 + payorder.refund_money，返回 {code:SUCCESS}
     */
    public function notifyWechatRefund()
    {
        if (PayService::isMock()) {
            return json(['code' => 'SUCCESS', 'message' => 'mock 模式无真实退款回调']);
        }

        $result = WxpayService::handleRefundNotify(
            $this->notifyHeaders(),
            (string) $this->request->getContent(),
            (int) $this->request->get('aid', 0)
        );

        return json($result['body'], $result['http_code']);
    }

    /**
     * V3 回调验签所需请求头（统一小写键）
     * @return array<string, string>
     */
    private function notifyHeaders(): array
    {
        $headers = $this->request->header();
        $result  = [];

        foreach ($headers as $key => $value) {
            $result[strtolower((string) $key)] = is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
        }

        return $result;
    }

    /**
     * 支付宝回调（form 参数，out_trade_no=商户支付单号/订单号）
     * 幂等：已支付订单重复回调直接返回 success
     */
    public function notifyAlipay()
    {
        $params = $this->request->post();

        if (!$this->payService->verifyNotifySignature('alipay', $params)) {
            return response('fail');
        }

        $orderSn = trim((string) ($params['out_trade_no'] ?? ''));

        try {
            $this->payService->notifyByOutTradeNo($orderSn, 'alipay', (string) ($params['trade_no'] ?? ''));
        } catch (BusinessException) {
            return response('fail');
        }

        return response('success');
    }

    /**
     * 模拟第三方回调（仅 mock 模式生效，幂等）
     * POST /api/pay/notify/mock {order_sn}
     */
    public function mock()
    {
        $orderSn = trim((string) $this->request->post('order_sn', ''));

        if ('' === $orderSn) {
            throw new BusinessException('缺少订单号');
        }

        return $this->success($this->payService->mockNotify($orderSn));
    }
}
