<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\CycleService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 周期购（admin 应用，JWT role=admin，aid 走 ScopeService）
 *
 * 覆盖：商品（含规格动态行：周期/次数/每期数量/价格）/ 分类 / 订单（状态机）
 *       / 按期发货（stage 分期计划）/ 退款审核 / 系统设置
 * 简化版口径：不做 stage 调度自动化，下单即生成全部分期计划行（CycleService 预留 C 端链路）
 */
class Cycle extends BaseController
{
    public function __construct(\think\App $app, protected CycleService $cycleService)
    {
        parent::__construct($app);
    }

    /* ================= 系统设置 ================= */

    /**
     * 周期购设置读取（自动收货天数/评价开关）
     * GET /admin/cycle/sysset
     */
    public function sysset()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->cycleService->getSysset($aid));
    }

    /**
     * 周期购设置保存
     * POST /admin/cycle/sysset {autoshdays, comment}
     */
    public function saveSysset()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->cycleService->saveSysset($aid, $this->request->post());

        return $this->success([], '保存成功');
    }

    /* ================= 商品分类 ================= */

    /**
     * 分类列表
     * GET /admin/cycle/category/list
     */
    public function categoryList()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->cycleService->categoryList($aid));
    }

    /**
     * 分类新增/编辑
     * POST /admin/cycle/category/save {id?, name, pic, pid, sort, status}
     */
    public function categorySave()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->cycleService->categorySave($aid, $this->request->post()), '保存成功');
    }

    /**
     * 分类删除（已有商品时拒绝）
     * POST /admin/cycle/category/del {id}
     */
    public function categoryDelete()
    {
        $aid = ScopeService::aidOf($this->request);

        $id = (int) $this->request->post('id', 0);

        $this->cycleService->categoryDelete($aid, $id);

        return $this->success([], '删除成功');
    }

    /* ================= 商品管理 ================= */

    /**
     * 商品列表（附分类名/规格行）
     * GET /admin/cycle/product/list?page&pagesize&keyword&status&cid
     */
    public function productList()
    {
        $aid = ScopeService::aidOf($this->request);

        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        $filters = [
            'keyword'    => trim((string) $this->request->get('keyword', '')),
            'status'     => trim((string) $this->request->get('status', '')),
            'cid'        => (int) $this->request->get('cid', 0),
            'bid'        => trim((string) $this->request->get('bid', '')),
            'checked'    => trim((string) $this->request->get('checked', '')),
            'orderField' => trim((string) $this->request->get('orderField', 'sort')),
            'orderType'  => trim((string) $this->request->get('orderType', 'desc')),
        ];

        return $this->success($this->cycleService->productList($aid, $page, $pageSize, $filters));
    }

    /**
     * 商品详情（编辑回显，含规格行）
     * GET /admin/cycle/product/detail?id
     */
    public function productDetail()
    {
        $aid = ScopeService::aidOf($this->request);

        $id = (int) $this->request->get('id', 0);

        return $this->success($this->cycleService->productDetail($aid, $id));
    }

    /**
     * 商品新增/编辑（规格动态行：周期/次数/每期数量/价格/库存）
     * POST /admin/cycle/product/save {id?, name, pic, cid, sort, status, detail,
     *     guiges:[{cycle_days, qsnum, each_num, sell_price, stock, name?}]}
     */
    public function productSave()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->cycleService->productSave($aid, $this->request->post()), '保存成功');
    }

    /**
     * 商品删除（已有订单时拒绝）
     * POST /admin/cycle/product/del {id}
     */
    public function productDelete()
    {
        $aid = ScopeService::aidOf($this->request);

        $ids = (array) $this->request->post('ids/a', []);
        if (empty($ids)) {
            $ids = [(int) $this->request->post('id', 0)];
        }

        $this->cycleService->productDelete($aid, array_map('intval', $ids));

        return $this->success([], '删除成功');
    }
    /**
     * 商品上下架
     * POST /admin/cycle/product/status {id, status}
     */
    public function productStatus()
    {
        $aid = ScopeService::aidOf($this->request);

        $ids = (array) $this->request->post('ids/a', []);
        if (empty($ids)) {
            $ids = [(int) $this->request->post('id', 0)];
        }
        $status = (int) $this->request->post('status', 0);

        $this->cycleService->productStatus($aid, array_map('intval', $ids), $status);

        return $this->success([], 1 === $status ? '已上架' : '已下架');
    }

    /**
     * 商品审核（ischecked 1通过 2驳回）
     * POST /admin/cycle/product/check {id, ischecked, reason}
     */
    public function productCheck()
    {
        $aid = ScopeService::aidOf($this->request);

        $id        = (int) $this->request->post('id', 0);
        $ischecked = (int) $this->request->post('ischecked', 0);
        $reason    = trim((string) $this->request->post('reason', ''));

        $this->cycleService->productCheck($aid, $id, $ischecked, $reason);

        return $this->success([], 1 === $ischecked ? '审核通过' : '已驳回');
    }

    /* ================= 周期购订单 ================= */

    /**
     * 订单列表（状态机 0未付/1已付/2已发货/3已收货/4关闭，refund_status 退款审核态）
     * GET /admin/cycle/order/list?page&pagesize&status&ordernum&proname&linkman&tel&start_date&end_date
     */
    public function orderList()
    {
        $aid = ScopeService::aidOf($this->request);

        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        $filters = [
            'status'     => trim((string) $this->request->get('status', '')),
            'ordernum'   => trim((string) $this->request->get('ordernum', '')),
            'proname'    => trim((string) $this->request->get('proname', '')),
            'linkman'    => trim((string) $this->request->get('linkman', '')),
            'tel'        => trim((string) $this->request->get('tel', '')),
            'start_date' => trim((string) $this->request->get('start_date', '')),
            'end_date'   => trim((string) $this->request->get('end_date', '')),
        ];

        return $this->success($this->cycleService->orderList($aid, $page, $pageSize, $filters));
    }

    /**
     * 订单详情（含 stage 分期计划展示 + 可退金额）
     * GET /admin/cycle/order/detail?id
     */
    public function orderDetail()
    {
        $aid = ScopeService::aidOf($this->request);

        $id = (int) $this->request->get('id', 0);

        return $this->success($this->cycleService->orderDetail($aid, $id));
    }

    /**
     * 按期发货（写当期 stage 状态 + 发货信息）
     * POST /admin/cycle/order/ship {stageid, express_com, express_no}
     */
    public function orderShip()
    {
        $aid = ScopeService::aidOf($this->request);

        $stageId    = (int) $this->request->post('stageid', 0);
        $expressCom = trim((string) $this->request->post('express_com', ''));
        $expressNo  = trim((string) $this->request->post('express_no', ''));

        $this->cycleService->orderShip($aid, $stageId, $expressCom, $expressNo);

        return $this->success([], '发货成功');
    }

    /**
     * 分期确认收货（当期完成；全部完成时总订单收口为已收货）
     * POST /admin/cycle/order/stageCollect {stageid}
     */
    public function orderStageCollect()
    {
        $aid = ScopeService::aidOf($this->request);

        $stageId = (int) $this->request->post('stageid', 0);

        return $this->success($this->cycleService->orderStageCollect($aid, $stageId), '当期已完成');
    }

    /**
     * 退款审核（pass=1 同意→PayService 原路退款+payorder 登记；pass=2 驳回）
     * POST /admin/cycle/order/refundCheck {id, pass, remark}
     */
    public function orderRefundCheck()
    {
        $aid = ScopeService::aidOf($this->request);

        $id     = (int) $this->request->post('id', 0);
        $pass   = (int) $this->request->post('pass', 0);
        $remark = trim((string) $this->request->post('remark', ''));

        $this->cycleService->orderRefundCheck($aid, $id, $pass, $remark);

        return $this->success([], 1 === $pass ? '已同意并退款' : '已驳回退款申请');
    }

    /**
     * 订单备注
     * POST /admin/cycle/order/remark {id, remark}
     */
    public function orderRemark()
    {
        $aid = ScopeService::aidOf($this->request);

        $id     = (int) $this->request->post('id', 0);
        $remark = trim((string) $this->request->post('remark', ''));

        $this->cycleService->orderRemark($aid, $id, $remark);

        return $this->success([], '备注已保存');
    }
}
