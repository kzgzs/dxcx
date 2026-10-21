<?php

declare(strict_types=1);

namespace app\api\controller;

use app\common\exception\BusinessException;
use app\common\service\PayService;
use app\common\service\RestaurantService;

/**
 * 客户端 - 餐饮堂食（api 应用）
 *
 * 匿名接口：info、product/list（AuthMiddleware 白名单，aid 走 query）
 * 会员接口：order/*（JWT role=member，mid/aid 取自 token）
 *
 * 桌台占用见 RestaurantService：存在 status IN(0,1) 订单的桌台 busy=1（查询推导）
 */
class Restaurant extends Base
{
    public function __construct(
        \think\App $app,
        protected RestaurantService $restaurantService,
        protected PayService $payService
    ) {
        parent::__construct($app);
    }

    /* ================= 匿名浏览 ================= */

    /**
     * 店铺信息 + 区域桌台树（匿名）
     * GET /api/restaurant/info?aid=
     */
    public function info()
    {
        return $this->success($this->restaurantService->apiInfo($this->aid()));
    }

    /**
     * 菜品列表（匿名；分类筛选，仅上架）
     * GET /api/restaurant/product/list?aid=&category_id=
     */
    public function productList()
    {
        $categoryId = (int) $this->request->get('category_id', 0);

        return $this->success($this->restaurantService->apiProductList($this->aid(), $categoryId));
    }

    /* ================= 会员订单 ================= */

    /**
     * 创建堂食订单（多商品计价 + 券积分叠加；无库存概念）
     * POST /api/restaurant/order/create {table_id,goods:[{product_id,num}],coupon_id?,use_score?}
     */
    public function orderCreate()
    {
        [$aid, $mid] = $this->memberContext();

        $tableId  = (int) $this->request->post('table_id', 0);
        $goods    = $this->request->post('goods', []);
        $couponId = (int) $this->request->post('coupon_id', 0);
        $useScore = (int) $this->request->post('use_score', 0);

        if (!is_array($goods)) {
            $goods = [];
        }

        return $this->success($this->restaurantService->createOrder($aid, $mid, $tableId, $goods, $couponId, $useScore));
    }

    /**
     * 我的堂食订单列表（含 goods 数组）
     * GET /api/restaurant/order/list?status=&page=&pagesize=
     */
    public function orderList()
    {
        [$aid, $mid] = $this->memberContext();

        $status   = trim((string) $this->request->get('status', ''));
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->restaurantService->apiOrderList($aid, $mid, $status, $page, $pageSize));
    }

    /**
     * 发起支付（pay_order 统一支付单；mock 模式内部直接入账）
     * POST /api/restaurant/order/pay {order_id, pay_type: wechat|alipay}
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

        return $this->success($this->payService->payBiz($aid, $mid, 'restaurant', $orderId, $payType));
    }

    /**
     * 取消订单（仅待支付；释放桌台占用 + 券积分返还）
     * POST /api/restaurant/order/cancel {order_id}
     */
    public function orderCancel()
    {
        [$aid, $mid] = $this->memberContext();

        $orderId = (int) $this->request->post('order_id', 0);

        $this->restaurantService->cancelOrder($aid, $mid, $orderId);

        return $this->success([], '订单已取消');
    }

    /**
     * 完成订单（仅本人已支付单；status=1→2 释放桌台；幂等）
     * POST /api/restaurant/order/complete {order_id}
     */
    public function orderComplete()
    {
        [$aid, $mid] = $this->memberContext();

        $orderId = (int) $this->request->post('order_id', 0);

        if ($orderId <= 0) {
            throw new BusinessException('缺少订单ID');
        }

        $this->restaurantService->completeOrder($aid, $mid, $orderId);

        return $this->success([], '订单已完成');
    }

    /**
     * 租户 ID：匿名接口取 query 参数（TenantMiddleware 已解析 X-Tenant-Id/aid）
     */
    protected function aid(): int
    {
        return (int) $this->request->aid;
    }
}
