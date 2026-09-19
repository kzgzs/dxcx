<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\BargainService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 砍价活动（admin 应用，JWT role=admin，aid 走 ScopeService）
 *
 * 覆盖：砍价商品 / 发起记录 / 砍价订单（发货、退款审核、关单）/ 核销码 / 活动设置
 * 砍价核心链路（发起/帮砍/下单）封装在 BargainService，C 端 api 预留
 */
class Kanjia extends BaseController
{
    public function __construct(\think\App $app, protected BargainService $bargainService)
    {
        parent::__construct($app);
    }

    /* ================= 活动设置 ================= */

    /**
     * 砍价设置读取（横幅/自动收货天数/进度显示/必须支付）
     * GET /admin/kanjia/sysset
     */
    public function sysset()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->bargainService->getSysset($aid));
    }

    /**
     * 砍价设置保存
     * POST /admin/kanjia/sysset {pic, autoshdays, showjd, mastpay}
     */
    public function saveSysset()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->bargainService->saveSysset($aid, $this->request->post());

        return $this->success([], '保存成功');
    }

    /* ================= 砍价商品 ================= */

    /**
     * 商品列表
     * GET /admin/kanjia/product/list?page&pagesize&keyword&status
     */
    public function productList()
    {
        $aid = ScopeService::aidOf($this->request);

        $page       = max(1, (int) $this->request->get('page', 1));
        $pageSize   = (int) $this->request->get('pagesize', 10);
        $pageSize   = $pageSize > 0 ? min($pageSize, 100) : 10;
        $keyword    = trim((string) $this->request->get('keyword', ''));
        $status     = trim((string) $this->request->get('status', ''));
        $bid        = trim((string) $this->request->get('bid', ''));
        $checked    = trim((string) $this->request->get('checked', ''));
        $orderField = trim((string) $this->request->get('orderField', ''));
        $orderType  = trim((string) $this->request->get('orderType', ''));

        return $this->success($this->bargainService->productList($aid, $page, $pageSize, $keyword, $status, $bid, $checked, $orderField, $orderType));
    }

    /**
     * 商品新增/编辑（名称/图/原价/底价/砍价人数/时限/库存/直接购买/分段砍价/帮砍奖励）
     * POST /admin/kanjia/product/save {id?, name, pic, sell_price, min_price, helpnum, limitHour, stock, ...}
     */
    public function productSave()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->bargainService->productSave($aid, $this->request->post()), '保存成功');
    }

    /**
     * 商品详情（含分段砍价区间、所属商户名）
     * GET /admin/kanjia/product/detail?id=
     */
    public function productDetail()
    {
        $aid = ScopeService::aidOf($this->request);

        $id = (int) $this->request->get('id', 0);

        return $this->success($this->bargainService->productDetail($aid, $id));
    }

    /**
     * 商品审核（ischecked 1通过 2驳回）
     * POST /admin/kanjia/product/check {id, ischecked, reason}
     */
    public function productCheck()
    {
        $aid = ScopeService::aidOf($this->request);

        $id        = (int) $this->request->post('id', 0);
        $ischecked = (int) $this->request->post('ischecked', 0);
        $reason    = trim((string) $this->request->post('reason', ''));

        $this->bargainService->productCheck($aid, $id, $ischecked, $reason);

        return $this->success([], 1 === $ischecked ? '审核通过' : '已驳回');
    }

    /**
     * 商品删除（已有发起记录/订单时拒绝）
     * POST /admin/kanjia/product/del {id}
     */
    public function productDelete()
    {
        $aid = ScopeService::aidOf($this->request);

        $ids = (array) $this->request->post('ids', []);
        if (empty($ids)) {
            $ids = [(int) $this->request->post('id', 0)];
        }

        $this->bargainService->productDeleteByIds($aid, $ids);

        return $this->success([], '删除成功');
    }

    /**
     * 商品上下架
     * POST /admin/kanjia/product/status {id, status}
     */
    public function productStatus()
    {
        $aid = ScopeService::aidOf($this->request);

        $ids = (array) $this->request->post('ids', []);
        if (empty($ids)) {
            $ids = [(int) $this->request->post('id', 0)];
        }
        $status = (int) $this->request->post('status', 0);

        $this->bargainService->productStatusByIds($aid, $ids, $status);

        return $this->success([], 1 === $status ? '已上架' : '已下架');
    }

    /* ================= 发起记录 ================= */

    /**
     * 发起记录列表（会员/商品/当前价/帮砍人数/状态/剩余时限）
     * GET /admin/kanjia/join/list?page&pagesize&keyword&status&proid
     */
    public function joinList()
    {
        $aid = ScopeService::aidOf($this->request);

        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        $filters = [
            'keyword' => trim((string) $this->request->get('keyword', '')),
            'status'  => trim((string) $this->request->get('status', '')),
            'proid'   => (int) $this->request->get('proid', 0),
        ];

        return $this->success($this->bargainService->joinList($aid, $page, $pageSize, $filters));
    }

    /**
     * 帮砍明细（某次发起的逐刀记录）
     * GET /admin/kanjia/join/help?joinid
     */
    public function joinHelp()
    {
        $aid = ScopeService::aidOf($this->request);

        $joinId = (int) $this->request->get('joinid', 0);

        return $this->success($this->bargainService->helpList($aid, $joinId));
    }

    /**
     * 发起记录删除（仅失败/已完成且未下单的记录）
     * POST /admin/kanjia/join/del {id}
     */
    public function joinDelete()
    {
        $aid = ScopeService::aidOf($this->request);

        $id = (int) $this->request->post('id', 0);

        $this->bargainService->joinDelete($aid, $id);

        return $this->success([], '删除成功');
    }

    /* ================= 砍价订单 ================= */

    /**
     * 订单列表（状态机 0未付/1已付/2已发货/3已收货/4关闭，refund_status 退款审核态）
     * GET /admin/kanjia/order/list?page&pagesize&status&refund_status&ordernum&proname&linkman&tel&start_date&end_date
     */
    public function orderList()
    {
        $aid = ScopeService::aidOf($this->request);

        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        $filters = [
            'status'        => trim((string) $this->request->get('status', '')),
            'refund_status' => trim((string) $this->request->get('refund_status', '')),
            'ordernum'      => trim((string) $this->request->get('ordernum', '')),
            'proname'       => trim((string) $this->request->get('proname', '')),
            'linkman'       => trim((string) $this->request->get('linkman', '')),
            'tel'           => trim((string) $this->request->get('tel', '')),
            'start_date'    => trim((string) $this->request->get('start_date', '')),
            'end_date'      => trim((string) $this->request->get('end_date', '')),
        ];

        return $this->success($this->bargainService->orderList($aid, $page, $pageSize, $filters));
    }

    /**
     * 订单发货
     * POST /admin/kanjia/order/ship {id, express_com, express_no}
     */
    public function orderShip()
    {
        $aid = ScopeService::aidOf($this->request);

        $id        = (int) $this->request->post('id', 0);
        $expressCom = trim((string) $this->request->post('express_com', ''));
        $expressNo  = trim((string) $this->request->post('express_no', ''));

        $this->bargainService->orderShip($aid, $id, $expressCom, $expressNo);

        return $this->success([], '发货成功');
    }

    /**
     * 退款审核（pass=1 同意→PayService 原路退款+payorder 登记+回补库存；pass=2 驳回）
     * POST /admin/kanjia/order/refundCheck {id, pass, remark}
     */
    public function orderRefundCheck()
    {
        $aid = ScopeService::aidOf($this->request);

        $id     = (int) $this->request->post('id', 0);
        $pass   = (int) $this->request->post('pass', 0);
        $remark = trim((string) $this->request->post('remark', ''));

        $this->bargainService->orderRefundCheck($aid, $id, $pass, $remark);

        return $this->success([], 1 === $pass ? '已同意并退款' : '已驳回退款申请');
    }

    /**
     * 手动关单（仅未支付）
     * POST /admin/kanjia/order/close {id}
     */
    public function orderClose()
    {
        $aid = ScopeService::aidOf($this->request);

        $id = (int) $this->request->post('id', 0);

        $this->bargainService->orderClose($aid, $id);

        return $this->success([], '订单已关闭');
    }

    /**
     * 订单备注
     * POST /admin/kanjia/order/remark {id, remark}
     */
    public function orderRemark()
    {
        $aid = ScopeService::aidOf($this->request);

        $id     = (int) $this->request->post('id', 0);
        $remark = trim((string) $this->request->post('remark', ''));

        $this->bargainService->orderRemark($aid, $id, $remark);

        return $this->success([], '备注已保存');
    }

    /* ================= 核销码 ================= */

    /**
     * 核销码列表
     * GET /admin/kanjia/codelist/list?page&pagesize&proid&status&keyword
     */
    public function codelistList()
    {
        $aid = ScopeService::aidOf($this->request);

        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        $filters = [
            'proid'   => (int) $this->request->get('proid', 0),
            'status'  => trim((string) $this->request->get('status', '')),
            'keyword' => trim((string) $this->request->get('keyword', '')),
        ];

        return $this->success($this->bargainService->codelistList($aid, $page, $pageSize, $filters));
    }

    /**
     * 核销（核销码/订单号 → 确认收货）
     * POST /admin/kanjia/codelist/hexiao {code}
     */
    public function codelistHexiao()
    {
        $aid = ScopeService::aidOf($this->request);

        $code = trim((string) $this->request->post('code', ''));

        return $this->success($this->bargainService->hexiao($aid, $code), '核销成功');
    }
}
