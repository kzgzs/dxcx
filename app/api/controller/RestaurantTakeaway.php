<?php

declare(strict_types=1);

namespace app\api\controller;

use app\common\exception\BusinessException;
use app\common\service\PayService;
use app\common\service\RestaurantTakeawayService;

/**
 * 客户端 - 餐饮外卖（api 应用）
 *
 * 匿名接口：info（外卖设置 + 配送方式与运费预估），AuthMiddleware 白名单
 * 会员接口：order/*（JWT role=member，mid/aid 取自 token，不可伪造）
 *
 * 计价口径见 RestaurantTakeawayService：菜品金额 → 券 → 积分 → 加运费打包费，
 * pay_amount 与 totalprice 一致，走 pay_order 统一支付单（order_type=restaurant_takeaway）。
 */
class RestaurantTakeaway extends Base
{
    public function __construct(
        \think\App $app,
        protected RestaurantTakeawayService $takeawayService,
        protected PayService $payService
    ) {
        parent::__construct($app);
    }

    /* ================= 匿名浏览 ================= */

    /**
     * 外卖首页信息（匿名）：外卖开关/起送价/打包费/banner + 配送方式与各自运费预估
     * GET /api/restaurantTakeaway/info?aid=&address_id=
     *
     * address_id 可选：传了则按该会员地址算真实运费与配送范围（需登录态）；
     * 不传则同城配送按起步费预估（isoutjuli=2）
     */
    public function info()
    {
        $aid = (int) $this->request->aid;

        $address  = [];
        $addressId = (int) $this->request->get('address_id', 0);

        if ($addressId > 0) {
            // 匿名白名单下尽力解析可选 token（无 token/无效 token 则跳过地址解析）
            $payload = (array) ($this->request->authPayload ?? []);

            if ([] === $payload) {
                $authorization = (string) $this->request->header('authorization', '');

                if (str_starts_with($authorization, 'Bearer ')) {
                    $decoded = \app\common\service\JwtService::decode(trim(substr($authorization, 7)));
                    $payload = null !== $decoded ? (array) $decoded : [];
                }
            }

            $mid = (int) ($payload['mid'] ?? ($payload['uid'] ?? 0));

            if ($mid > 0) {
                try {
                    $row = app(\app\common\service\AddressService::class)->findOwned($aid, $mid, $addressId);

                    $address = [
                        'province'  => (string) ($row['province'] ?? ''),
                        'city'      => (string) ($row['city'] ?? ''),
                        'district'  => (string) ($row['district'] ?? ''),
                        'address'   => (string) ($row['address'] ?? ''),
                        'longitude' => (string) ($row['longitude'] ?? ''),
                        'latitude'  => (string) ($row['latitude'] ?? ''),
                    ];
                } catch (\Throwable $e) {
                    $address = []; // 地址不属于该会员：按无地址预估
                }
            }
        }

        return $this->success($this->takeawayService->apiInfo($aid, $address));
    }

    /* ================= 会员订单 ================= */

    /**
     * 创建外卖订单（多商品 + 规格；运费打包费券积分叠加；事务内扣库存）
     * POST /api/restaurantTakeaway/order/create
     * {goods:[{product_id,ggid?,num}], address_id, freight_id, store_id?, freight_time?,
     *  message?, coupon_id?, use_score?, field1-5?}
     */
    public function orderCreate()
    {
        [$aid, $mid] = $this->memberContext();

        $goods = $this->request->post('goods', []);

        if (!is_array($goods)) {
            $goods = [];
        }

        $extra = [
            'address_id'   => (int) $this->request->post('address_id', 0),
            'freight_id'   => (int) $this->request->post('freight_id', 0),
            'store_id'     => (int) $this->request->post('store_id', 0),
            'freight_time' => (string) $this->request->post('freight_time', ''),
            'message'      => (string) $this->request->post('message', ''),
            'coupon_id'    => (int) $this->request->post('coupon_id', 0),
            'use_score'    => (int) $this->request->post('use_score', 0),
            'field1'       => (string) $this->request->post('field1', ''),
            'field2'       => (string) $this->request->post('field2', ''),
            'field3'       => (string) $this->request->post('field3', ''),
            'field4'       => (string) $this->request->post('field4', ''),
            'field5'       => (string) $this->request->post('field5', ''),
        ];

        return $this->success($this->takeawayService->createOrder($aid, $mid, $goods, $extra));
    }

    /**
     * 我的外卖订单列表（含 goods 数组）
     * GET /api/restaurantTakeaway/order/list?status=&page=&pagesize=
     */
    public function orderList()
    {
        [$aid, $mid] = $this->memberContext();

        $status   = trim((string) $this->request->get('status', ''));
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->takeawayService->apiOrderList($aid, $mid, $status, $page, $pageSize));
    }

    /**
     * 订单详情（仅本人订单）
     * GET /api/restaurantTakeaway/order/detail?order_id=
     */
    public function orderDetail()
    {
        [$aid, $mid] = $this->memberContext();

        $orderId = (int) $this->request->get('order_id', 0);

        return $this->success($this->takeawayService->apiDetail($aid, $mid, $orderId));
    }

    /**
     * 发起支付（pay_order 统一支付单；mock 模式内部直接入账）
     * POST /api/restaurantTakeaway/order/pay {order_id, pay_type: wechat|alipay}
     */
    public function orderPay()
    {
        [$aid, $mid] = $this->memberContext();

        $orderId = (int) $this->request->post('order_id', 0);
        $payType = trim((string) $this->request->post('pay_type', ''));

        if ($orderId <= 0) {
            throw new BusinessException('缺少订单ID');
        }
        if ('' === $payType) {
            throw new BusinessException('请选择支付方式');
        }

        return $this->success($this->payService->payBiz($aid, $mid, 'restaurant_takeaway', $orderId, $payType));
    }

    /**
     * 取消订单（仅本人待支付单；回补库存 + 释放券 + 返还积分）
     * POST /api/restaurantTakeaway/order/cancel {order_id}
     */
    public function orderCancel()
    {
        [$aid, $mid] = $this->memberContext();

        $orderId = (int) $this->request->post('order_id', 0);

        $this->takeawayService->cancelOrder($aid, $mid, $orderId);

        return $this->success([], '订单已取消');
    }

    /**
     * 确认收货（仅本人已接单/已发货单；幂等）
     * POST /api/restaurantTakeaway/order/complete {order_id}
     */
    public function orderComplete()
    {
        [$aid, $mid] = $this->memberContext();

        $orderId = (int) $this->request->post('order_id', 0);

        if ($orderId <= 0) {
            throw new BusinessException('缺少订单ID');
        }

        $this->takeawayService->confirmOrder($aid, $mid, $orderId);

        return $this->success([], '确认收货成功');
    }

    /**
     * 申请退款（已支付/已接单/已发货可申请；置退款审核中，由商家在后台审核）
     * POST /api/restaurantTakeaway/order/refund {order_id, reason}
     */
    public function orderRefund()
    {
        [$aid, $mid] = $this->memberContext();

        $orderId = (int) $this->request->post('order_id', 0);
        $reason  = trim((string) $this->request->post('reason', ''));

        $this->takeawayService->applyRefund($aid, $mid, $orderId, $reason);

        return $this->success([], '退款申请已提交');
    }
}
