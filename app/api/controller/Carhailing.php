<?php

declare(strict_types=1);

namespace app\api\controller;

use app\common\exception\BusinessException;
use app\common\service\CarhailingService;
use app\common\service\PayService;

/**
 * 客户端 - 网约车（api 应用）
 *
 * 匿名接口：list/detail（AuthMiddleware 白名单，aid 走 query）
 * 会员接口：order/*（JWT role=member，mid/aid 取自 token）
 *
 * 计价规则见 CarhailingService：<4h 拒绝；4h~24h 半天价；>=24h 按天 ceil×price_day
 */
class Carhailing extends Base
{
    public function __construct(
        \think\App $app,
        protected CarhailingService $carhailingService,
        protected PayService $payService
    ) {
        parent::__construct($app);
    }

    /**
     * 车辆列表（匿名；分类筛选）
     * GET /api/carhailing/list?aid=&category_id=
     */
    public function list()
    {
        $categoryId = (int) $this->request->get('category_id', 0);

        return $this->success($this->carhailingService->apiList($this->aid(), $categoryId));
    }

    /**
     * 车辆详情（匿名）
     * GET /api/carhailing/detail?aid=&id=
     */
    public function detail()
    {
        $id = (int) $this->request->get('id', 0);

        if ($id <= 0) {
            throw new BusinessException('缺少车辆ID');
        }

        return $this->success($this->carhailingService->apiDetail($this->aid(), $id));
    }

    /**
     * 创建租车订单（计价+车辆并发校验+券积分叠加）
     * POST /api/carhailing/order/create {product_id,start_time,end_time,coupon_id?,use_score?}
     */
    public function orderCreate()
    {
        [$aid, $mid] = $this->memberContext();

        $productId = (int) $this->request->post('product_id', 0);
        $startTime = (int) $this->request->post('start_time', 0);
        $endTime   = (int) $this->request->post('end_time', 0);
        $couponId  = (int) $this->request->post('coupon_id', 0);
        $useScore  = (int) $this->request->post('use_score', 0);

        return $this->success($this->carhailingService->createOrder($aid, $mid, $productId, $startTime, $endTime, $couponId, $useScore));
    }

    /**
     * 我的用车订单列表
     * GET /api/carhailing/order/list?status=&page=&pagesize=
     */
    public function orderList()
    {
        [$aid, $mid] = $this->memberContext();

        $status   = trim((string) $this->request->get('status', ''));
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->carhailingService->apiOrderList($aid, $mid, $status, $page, $pageSize));
    }

    /**
     * 发起支付（pay_order 统一支付单；mock 模式内部直接入账）
     * POST /api/carhailing/order/pay {order_id, pay_type: wechat|alipay}
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

        return $this->success($this->payService->payBiz($aid, $mid, 'carhailing', $orderId, $payType));
    }

    /**
     * 取消订单（仅待支付；释放券+返还积分）
     * POST /api/carhailing/order/cancel {order_id}
     */
    public function orderCancel()
    {
        [$aid, $mid] = $this->memberContext();

        $orderId = (int) $this->request->post('order_id', 0);

        $this->carhailingService->cancelOrder($aid, $mid, $orderId);

        return $this->success([], '订单已取消');
    }

    /**
     * 申请退款（仅已支付且用车未开始；走 PayService 退款 mock）
     * POST /api/carhailing/order/refund {order_id}
     */
    public function orderRefund()
    {
        [$aid, $mid] = $this->memberContext();

        $orderId = (int) $this->request->post('order_id', 0);

        return $this->success($this->carhailingService->refundOrder($aid, $mid, $orderId));
    }

    /**
     * 租户 ID：匿名接口取 query 参数（TenantMiddleware 已解析 X-Tenant-Id/aid）
     */
    protected function aid(): int
    {
        return (int) $this->request->aid;
    }
}
