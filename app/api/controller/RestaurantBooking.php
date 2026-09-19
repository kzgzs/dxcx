<?php

declare(strict_types=1);

namespace app\api\controller;

use app\common\service\BookingService;

/**
 * 客户端 - 餐饮预定订座（api 应用，批次6 T6-1）
 *
 * 接口（对标参考系统 ApiRestaurantBooking）：
 * - add：GET 返回时段/设置，POST 创建预定单（同会员同桌同时段未支付单复用更新）
 * - tableList / tableCategory / orderlist / detail / del / pay
 *
 * aid/mid 均取自 JWT（memberContext），不可伪造
 */
class RestaurantBooking extends Base
{
    public function __construct(
        \think\App $app,
        protected BookingService $bookingService
    ) {
        parent::__construct($app);
    }

    /**
     * 预定下单（GET 返回时段列表与设置；POST 创建预定单）
     * GET  /api/restaurant/booking/add?aid=
     * POST /api/restaurant/booking/add {tableId,time,renshu,tel,linkman,message}
     */
    public function add()
    {
        if ($this->request->isGet()) {
            // 匿名可查时段（对标参考系统 add GET 分支返回 timeArr）
            $aid = \app\common\service\ScopeService::aidOf($this->request);
            $set = $this->bookingService->getSysset($aid);

            return $this->success([
                'status'    => (int) ($set['status'] ?? 0),
                'pay'       => (int) ($set['pay'] ?? 0),
                'pay_money' => (float) ($set['pay_money'] ?? 0),
                'prehour'   => (int) ($set['prehour'] ?? 0),
                'timeArr'   => $this->bookingService->timeSlots($set),
            ]);
        }

        [$aid, $mid] = $this->memberContext();

        $info = [
            'tableId' => (int) $this->request->post('tableId', 0),
            'time'    => (string) $this->request->post('time', ''),
            'renshu'  => (int) $this->request->post('renshu', 0),
            'tel'     => (string) $this->request->post('tel', ''),
            'linkman' => (string) $this->request->post('linkman', ''),
            'message' => (string) $this->request->post('message', ''),
        ];

        return $this->success($this->bookingService->createOrder($aid, $mid, $info));
    }

    /**
     * 可预定桌台列表（指定时段时排除已被预定桌台）
     * GET /api/restaurant/booking/tableList?aid=&cid=&time=
     */
    public function tableList()
    {
        $aid = \app\common\service\ScopeService::aidOf($this->request);

        $cid  = (int) $this->request->get('cid', 0);
        $time = trim((string) $this->request->get('time', ''));

        return $this->success($this->bookingService->apiTableList($aid, $cid, $time));
    }

    /**
     * 桌台分类列表
     * GET /api/restaurant/booking/tableCategory?aid=
     */
    public function tableCategory()
    {
        $aid = \app\common\service\ScopeService::aidOf($this->request);

        return $this->success($this->bookingService->apiTableCategory($aid));
    }

    /**
     * 我的预定订单列表
     * GET /api/restaurant/booking/orderlist?st=&page=&pagesize=
     */
    public function orderlist()
    {
        [$aid, $mid] = $this->memberContext();

        $st       = (string) $this->request->get('st', '');
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = min(50, max(1, (int) $this->request->get('pagesize', 10)));

        return $this->success($this->bookingService->apiOrderList($aid, $mid, $st, $page, $pageSize));
    }

    /**
     * 预定订单详情
     * GET /api/restaurant/booking/detail?id=
     */
    public function detail()
    {
        [$aid, $mid] = $this->memberContext();

        $orderId = (int) $this->request->get('id', 0);

        return $this->success($this->bookingService->apiDetail($aid, $mid, $orderId));
    }

    /**
     * 取消预定（未支付直接关闭；已支付自动原路退款后关闭）
     * POST /api/restaurant/booking/del {id}
     */
    public function del()
    {
        [$aid, $mid] = $this->memberContext();

        $orderId = (int) $this->request->post('id', 0);

        $this->bookingService->cancelByMember($aid, $mid, $orderId);

        return $this->success([], '取消成功');
    }

    /**
     * 支付预定定金（order_type=restaurant_booking 走统一支付单）
     * POST /api/restaurant/booking/pay {id,paytype}
     */
    public function pay()
    {
        [$aid, $mid] = $this->memberContext();

        $orderId = (int) $this->request->post('id', 0);
        $payType = (string) $this->request->post('paytype', 'wechat');

        return $this->success($this->bookingService->payOrder($aid, $mid, $orderId, $payType));
    }
}
