<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\CashierService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 通用收银台·收银订单（批次6-T6-5 一期）
 *
 * 列表筛选/详情/退款申请与审核（refund_status 流转）/删除
 */
class CashierOrder extends BaseController
{
    /**
     * 订单列表
     * GET /admin/cashier/order
     */
    public function index()
    {
        $aid = ScopeService::aidOf($this->request);

        $filters = [
            'status'        => $this->request->get('status', ''),
            'refund_status' => $this->request->get('refund_status', ''),
            'ordernum'      => (string) $this->request->get('ordernum', ''),
            'mid'           => (int) $this->request->get('mid', 0),
            'paytypeid'     => (int) $this->request->get('paytypeid', 0),
            'starttime'     => (string) $this->request->get('starttime', ''),
            'endtime'       => (string) $this->request->get('endtime', ''),
        ];
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 20);
        if ($pageSize < 1 || $pageSize > 100) {
            $pageSize = 20;
        }

        return $this->success(app(CashierService::class)->orderList($aid, $filters, $page, $pageSize));
    }

    /**
     * 订单详情（含商品行）
     * GET /admin/cashier/order/detail
     */
    public function detail()
    {
        $aid = ScopeService::aidOf($this->request);
        $id  = (int) $this->request->get('id', 0);

        return $this->success(app(CashierService::class)->orderDetail($aid, $id));
    }

    /**
     * 申请退款（refund_status → 1 审核中）
     * POST /admin/cashier/order/refundapply
     */
    public function refundApply()
    {
        $aid     = ScopeService::aidOf($this->request);
        $orderId = (int) $this->request->post('orderid', 0);
        $money   = (float) $this->request->post('refund_money', 0);
        $reason  = trim((string) $this->request->post('reason', ''));

        app(CashierService::class)->refundApply($aid, $orderId, $money, $reason);

        return $this->success([], '退款申请已提交');
    }

    /**
     * 退款审核（同意：退余额+回库存，status=10/refund_status=2；驳回：refund_status=3）
     * POST /admin/cashier/order/refundaudit
     */
    public function refundAudit()
    {
        $aid         = ScopeService::aidOf($this->request);
        $orderId     = (int) $this->request->post('orderid', 0);
        $agree       = (int) $this->request->post('agree', 0) === 1 ? 1 : 0;
        $checkRemark = trim((string) $this->request->post('check_remark', ''));

        app(CashierService::class)->refundAudit($aid, $orderId, $agree, $checkRemark);

        return $this->success([], 1 === $agree ? '已同意退款' : '已驳回退款');
    }

    /**
     * 删除订单
     * POST /admin/cashier/order/delete
     */
    public function delete()
    {
        $aid = ScopeService::aidOf($this->request);
        $ids = $this->request->post('ids', []);
        if (!is_array($ids)) {
            $ids = array_filter(explode(',', (string) $ids));
        }

        app(CashierService::class)->deleteOrders($aid, $ids);

        return $this->success([], '删除成功');
    }
}
