<?php

declare(strict_types=1);

namespace app\api\controller;

use app\common\exception\BusinessException;
use app\common\service\PayService;
use app\common\service\YuyueService;

/**
 * 客户端 - 预约（api 应用）
 *
 * 匿名接口：product/list、product/detail、worker/list、slot（AuthMiddleware 白名单，aid 走 query）
 * 会员接口：order/*（JWT role=member，mid/aid 取自 token）
 *
 * 时段模型见 YuyueService：固定 09:00-21:00 每小时一档，容量取项目 yynum（默认1）
 */
class Yuyue extends Base
{
    public function __construct(
        \think\App $app,
        protected YuyueService $yuyueService,
        protected PayService $payService
    ) {
        parent::__construct($app);
    }

    /* ================= 匿名浏览 ================= */

    /**
     * 项目列表（匿名；分类筛选）
     * GET /api/yuyue/product/list?aid=&category_id=
     */
    public function productList()
    {
        $categoryId = (int) $this->request->get('category_id', 0);

        return $this->success($this->yuyueService->apiProductList($this->aid(), $categoryId));
    }

    /**
     * 项目详情（匿名）
     * GET /api/yuyue/product/detail?aid=&id=
     */
    public function productDetail()
    {
        $id = (int) $this->request->get('id', 0);

        if ($id <= 0) {
            throw new BusinessException('缺少项目ID');
        }

        return $this->success($this->yuyueService->apiProductDetail($this->aid(), $id));
    }

    /**
     * 技师列表（匿名）
     * GET /api/yuyue/worker/list?aid=
     */
    public function workerList()
    {
        return $this->success($this->yuyueService->apiWorkerList($this->aid()));
    }

    /**
     * 时段可约查询（匿名；固定 09:00-21:00 每小时一档）
     * GET /api/yuyue/slot?aid=&product_id=&worker_id=&date=
     */
    public function slot()
    {
        $productId = (int) $this->request->get('product_id', 0);
        $workerId  = (int) $this->request->get('worker_id', 0);
        $date      = trim((string) $this->request->get('date', ''));

        if ($productId <= 0) {
            throw new BusinessException('缺少项目ID');
        }
        if ('' === $date) {
            throw new BusinessException('请选择日期');
        }

        return $this->success($this->yuyueService->apiSlot($this->aid(), $productId, $workerId, $date));
    }

    /* ================= 会员订单 ================= */

    /**
     * 创建预约订单（时段行锁防双约 + 券积分叠加）
     * POST /api/yuyue/order/create {product_id,worker_id?,book_date,book_time,coupon_id?,use_score?}
     */
    public function orderCreate()
    {
        [$aid, $mid] = $this->memberContext();

        $productId = (int) $this->request->post('product_id', 0);
        $workerId  = (int) $this->request->post('worker_id', 0);
        $bookDate  = trim((string) $this->request->post('book_date', ''));
        $bookTime  = trim((string) $this->request->post('book_time', ''));
        $couponId  = (int) $this->request->post('coupon_id', 0);
        $useScore  = (int) $this->request->post('use_score', 0);

        return $this->success($this->yuyueService->createOrder($aid, $mid, $productId, $workerId, $bookDate, $bookTime, $couponId, $useScore));
    }

    /**
     * 我的预约订单列表
     * GET /api/yuyue/order/list?status=&page=&pagesize=
     */
    public function orderList()
    {
        [$aid, $mid] = $this->memberContext();

        $status   = trim((string) $this->request->get('status', ''));
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->yuyueService->apiOrderList($aid, $mid, $status, $page, $pageSize));
    }

    /**
     * 发起支付（pay_order 统一支付单；mock 模式内部直接入账）
     * POST /api/yuyue/order/pay {order_id, pay_type: wechat|alipay}
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

        return $this->success($this->payService->payBiz($aid, $mid, 'yuyue', $orderId, $payType));
    }

    /**
     * 申请退款（仅已支付未核销且预约日期未到；核销成功不退）
     * POST /api/yuyue/order/refund {order_id}
     */
    public function orderRefund()
    {
        [$aid, $mid] = $this->memberContext();

        $orderId = (int) $this->request->post('order_id', 0);

        return $this->success($this->yuyueService->refundOrder($aid, $mid, $orderId));
    }

    /**
     * 租户 ID：匿名接口取 query 参数（TenantMiddleware 已解析 X-Tenant-Id/aid）
     */
    protected function aid(): int
    {
        return (int) $this->request->aid;
    }
}
