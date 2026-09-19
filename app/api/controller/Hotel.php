<?php

declare(strict_types=1);

namespace app\api\controller;

use app\common\exception\BusinessException;
use app\common\service\HotelService;
use app\common\service\PayService;

/**
 * 客户端 - 酒店（api 应用）
 *
 * 匿名接口：list/detail/room/available（AuthMiddleware 白名单，aid 走 query）
 * 会员接口：order/*（JWT role=member，mid/aid 取自 token）
 *
 * 计价规则见 HotelService：amount = nights × price；押金随单收，退款一并退
 */
class Hotel extends Base
{
    public function __construct(
        \think\App $app,
        protected HotelService $hotelService,
        protected PayService $payService
    ) {
        parent::__construct($app);
    }

    /**
     * 酒店列表（匿名；star/keyword 筛选，含最低房价 min_price）
     * GET /api/hotel/list?aid=&star=&keyword=
     */
    public function list()
    {
        $star    = (int) $this->request->get('star', 0);
        $keyword = trim((string) $this->request->get('keyword', ''));

        return $this->success($this->hotelService->apiList($this->aid(), $star, $keyword));
    }

    /**
     * 酒店详情（匿名；含房型列表）
     * GET /api/hotel/detail?aid=&id=
     */
    public function detail()
    {
        $id = (int) $this->request->get('id', 0);

        if ($id <= 0) {
            throw new BusinessException('缺少酒店ID');
        }

        return $this->success($this->hotelService->apiDetail($this->aid(), $id));
    }

    /**
     * 房态查询（匿名）
     * GET /api/hotel/room/available?aid=&room_id=&checkin=&checkout=
     * → {available(剩余间数), nights, amount, deposit}
     */
    public function roomAvailable()
    {
        $roomId   = (int) $this->request->get('room_id', 0);
        $checkin  = trim((string) $this->request->get('checkin', ''));
        $checkout = trim((string) $this->request->get('checkout', ''));

        if ($roomId <= 0) {
            throw new BusinessException('缺少房型ID');
        }

        return $this->success($this->hotelService->roomAvailable($this->aid(), $roomId, $checkin, $checkout));
    }

    /**
     * 创建预订订单（日期校验+并发房态行锁+券积分叠加）
     * POST /api/hotel/order/create {room_id,checkin_date,checkout_date,name,phone,coupon_id?,use_score?}
     */
    public function orderCreate()
    {
        [$aid, $mid] = $this->memberContext();

        $roomId       = (int) $this->request->post('room_id', 0);
        $checkinDate  = trim((string) $this->request->post('checkin_date', ''));
        $checkoutDate = trim((string) $this->request->post('checkout_date', ''));
        $name         = trim((string) $this->request->post('name', ''));
        $phone        = trim((string) $this->request->post('phone', ''));
        $couponId     = (int) $this->request->post('coupon_id', 0);
        $useScore     = (int) $this->request->post('use_score', 0);

        return $this->success($this->hotelService->createOrder($aid, $mid, $roomId, $checkinDate, $checkoutDate, $name, $phone, $couponId, $useScore));
    }

    /**
     * 我的酒店订单列表
     * GET /api/hotel/order/list?status=&page=&pagesize=
     */
    public function orderList()
    {
        [$aid, $mid] = $this->memberContext();

        $status   = trim((string) $this->request->get('status', ''));
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->hotelService->apiOrderList($aid, $mid, $status, $page, $pageSize));
    }

    /**
     * 发起支付（pay_order 统一支付单；mock 模式内部直接入账）
     * POST /api/hotel/order/pay {order_id, pay_type: wechat|alipay}
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

        return $this->success($this->payService->payBiz($aid, $mid, 'hotel', $orderId, $payType));
    }

    /**
     * 取消订单（仅待支付；释放券+返还积分）
     * POST /api/hotel/order/cancel {order_id}
     */
    public function orderCancel()
    {
        [$aid, $mid] = $this->memberContext();

        $orderId = (int) $this->request->post('order_id', 0);

        $this->hotelService->cancelOrder($aid, $mid, $orderId);

        return $this->success([], '订单已取消');
    }

    /**
     * 申请退款（仅已预订且入住日未到；退实付全额含押金）
     * POST /api/hotel/order/refund {order_id}
     */
    public function orderRefund()
    {
        [$aid, $mid] = $this->memberContext();

        $orderId = (int) $this->request->post('order_id', 0);

        return $this->success($this->hotelService->refundOrder($aid, $mid, $orderId));
    }

    /**
     * 租户 ID：匿名接口取 query 参数（TenantMiddleware 已解析 X-Tenant-Id/aid）
     */
    protected function aid(): int
    {
        return (int) $this->request->aid;
    }
}
