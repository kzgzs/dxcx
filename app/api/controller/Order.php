<?php

declare(strict_types=1);

namespace app\api\controller;

use app\common\exception\BusinessException;
use app\common\service\OrderService;
use app\common\service\PayService;
use app\common\service\RefundService;

/**
 * 客户端 - 订单（api 应用，JWT role=member，mid/aid 取自 token）
 *
 * 状态机：0待支付 1已支付待发货 2已发货 3已完成 4已取消
 */
class Order extends Base
{
    public function __construct(
        \think\App $app,
        protected OrderService $orderService,
        protected PayService $payService,
        protected RefundService $refundService
    ) {
        parent::__construct($app);
    }

    /**
     * 创建订单（锁定扣减库存，10 分钟支付超时；可选 coupon_id 用券、use_score 积分抵扣、ggid SKU 规格、huangou 换购勾选）
     * POST /api/order/create {goods_id,num,address_id,coupon_id?,use_score?,ggid?,huangou?}
     */
    public function create()
    {
        [$aid, $mid] = $this->memberContext();

        $goodsId   = (int) $this->request->post('goods_id', 0);
        $num       = (int) $this->request->post('num', 0);
        $addressId = (int) $this->request->post('address_id', 0);
        $couponId  = (int) $this->request->post('coupon_id', 0);
        $useScore  = (int) $this->request->post('use_score', 0);
        $ggid      = (int) $this->request->post('ggid', 0);
        $huangou   = (int) $this->request->post('huangou', 0);

        if ($addressId <= 0) {
            throw new BusinessException('请选择收货地址');
        }

        return $this->success($this->orderService->create($aid, $mid, $goodsId, $num, $addressId, $couponId, $useScore, $ggid, $huangou));
    }

    /**
     * 我的订单列表（status 空=全部，含商品快照数组）
     * GET /api/order/list?status=&page=&pagesize=
     */
    public function list()
    {
        [$aid, $mid] = $this->memberContext();

        $status   = trim((string) $this->request->get('status', ''));
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->orderService->apiList($aid, $mid, $status, $page, $pageSize));
    }

    /**
     * 订单详情（仅本人订单）
     * GET /api/order/detail?id=
     */
    public function detail()
    {
        [$aid, $mid] = $this->memberContext();

        $id = (int) $this->request->get('id', 0);

        if ($id <= 0) {
            throw new BusinessException('缺少订单ID');
        }

        return $this->success($this->orderService->apiDetail($aid, $mid, $id));
    }

    /**
     * 取消订单（仅待支付可取消，回补库存）
     * POST /api/order/cancel {id}
     */
    public function cancel()
    {
        [$aid, $mid] = $this->memberContext();

        $id = (int) $this->request->post('id', 0);

        if ($id <= 0) {
            throw new BusinessException('缺少订单ID');
        }

        $this->orderService->cancel($aid, $mid, $id);

        return $this->success([], '订单已取消');
    }

    /**
     * 发起支付
     * POST /api/order/pay {order_id, pay_type: wechat|alipay}
     * mock 模式：内部直接触发支付成功流程，返回 {mock:true,status:1}
     * 真实模式：返回微信 V3 prepay / 支付宝下单参数（本期桩）
     */
    public function pay()
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

        return $this->success($this->payService->pay($aid, $mid, $orderId, $payType));
    }

    /**
     * 确认收货（仅已发货可确认）
     * POST /api/order/confirm {id}
     */
    public function confirm()
    {
        [$aid, $mid] = $this->memberContext();

        $id = (int) $this->request->post('id', 0);

        if ($id <= 0) {
            throw new BusinessException('缺少订单ID');
        }

        $this->orderService->confirm($aid, $mid, $id);

        return $this->success([], '确认收货成功');
    }

    /**
     * 申请退款（仅待发货/已发货且无进行中退款单）
     * POST /api/order/refund {order_id, reason}
     */
    public function refund()
    {
        [$aid, $mid] = $this->memberContext();

        $orderId = (int) $this->request->post('order_id', 0);
        $reason  = trim((string) $this->request->post('reason', ''));

        if ($orderId <= 0) {
            throw new BusinessException('缺少订单ID');
        }

        return $this->success($this->refundService->apply($aid, $mid, $orderId, $reason));
    }
}
