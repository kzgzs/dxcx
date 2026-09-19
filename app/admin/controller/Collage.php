<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\CollageService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 拼团管理（admin 应用，JWT role=admin，aid 走 ScopeService）
 *
 * 覆盖：拼团设置 / 拼团商品 / 团管理 / 拼团订单
 * （超时解散团由 schedule:run 挂接 CollageService::dissolveExpiredTeams）
 */
class Collage extends BaseController
{
    public function __construct(\think\App $app, protected CollageService $collageService)
    {
        parent::__construct($app);
    }

    /* ================= 拼团设置 ================= */

    /**
     * 读取拼团设置
     * GET /admin/collage/set
     */
    public function setGet()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->collageService->sysset($aid));
    }

    /**
     * 保存拼团设置（teamnum/teamhour/leadermoney/autoshdays/comment_check/timeset/team_refund）
     * POST /admin/collage/set
     */
    public function setSave()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->collageService->saveSysset($aid, $this->request->post());

        return $this->success([], '保存成功');
    }

    /* ================= 拼团商品 ================= */

    /**
     * 商品列表（keyword/status/checked 过滤）
     * GET /admin/collage/product/list?page&pagesize&keyword&status&checked
     */
    public function productList()
    {
        $aid = ScopeService::aidOf($this->request);

        $keyword  = trim((string) $this->request->get('keyword', ''));
        $status   = trim((string) $this->request->get('status', ''));
        $checked  = trim((string) $this->request->get('checked', ''));
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;
        $bid      = trim((string) $this->request->get('bid', ''));
        $cid      = (int) $this->request->get('cid', 0);
        $orderField = trim((string) $this->request->get('orderField', ''));
        $orderType  = trim((string) $this->request->get('orderType', ''));

        return $this->success($this->collageService->productList($aid, $keyword, $status, $checked, $page, $pageSize, $bid, $cid, $orderField, $orderType));
    }

    /**
     * 商品详情（含规格行）
     * GET /admin/collage/product/detail?id=
     */
    public function productDetail()
    {
        $aid = ScopeService::aidOf($this->request);

        $id = (int) $this->request->get('id', 0);

        return $this->success($this->collageService->productDetail($aid, $id));
    }

    /**
     * 新增/编辑拼团商品（id>0 编辑；含规格行 options）
     * POST /admin/collage/product/save
     */
    public function productSave()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->collageService->productSave($aid, $this->request->post()), '保存成功');
    }

    /**
     * 商品上架/下架
     * POST /admin/collage/product/status {id,status}
     */
    public function productStatus()
    {
        $aid = ScopeService::aidOf($this->request);

        $id     = (int) $this->request->post('id', 0);
        $status = (int) $this->request->post('status', -1);

        $this->collageService->productBatchStatus($aid, $this->resolveProductIds(), $status);

        return $this->success([], 1 === $status ? '已上架' : '已下架');
    }

    /**
     * 商品审核（ischecked 1通过 2驳回）
     * POST /admin/collage/product/check {id,ischecked,reason}
     */
    public function productCheck()
    {
        $aid = ScopeService::aidOf($this->request);

        $id        = (int) $this->request->post('id', 0);
        $ischecked = (int) $this->request->post('ischecked', 0);
        $reason    = trim((string) $this->request->post('reason', ''));

        $this->collageService->productCheck($aid, $id, $ischecked, $reason);

        return $this->success([], 1 === $ischecked ? '审核通过' : '已驳回');
    }

    /**
     * 删除商品
     * POST /admin/collage/product/delete {id}
     */
    public function productDelete()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->collageService->productBatchDelete($aid, $this->resolveProductIds());

        return $this->success([], '删除成功');
    }

    /**
     * 归一化商品 ids 入参：兼容批量 ids[] 与单个 id
     *
     * @return int[]
     */
    private function resolveProductIds(): array
    {
        $ids = $this->request->post('ids');

        if (is_array($ids)) {
            return array_values(array_filter(array_map('intval', $ids)));
        }

        $id = (int) $this->request->post('id', 0);

        return $id > 0 ? [$id] : [];
    }

    /* ================= 团管理 ================= */

    /**
     * 团列表（status 空=全部 1进行中 2成功 3失败）
     * GET /admin/collage/team/list?page&pagesize&status
     */
    public function teamList()
    {
        $aid = ScopeService::aidOf($this->request);

        $status   = trim((string) $this->request->get('status', ''));
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->collageService->teamList($aid, $status, $page, $pageSize));
    }

    /**
     * 删除团（进行中的团禁止删除）
     * POST /admin/collage/team/delete {id}
     */
    public function teamDelete()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->collageService->teamDelete($aid, (int) $this->request->post('id', 0));

        return $this->success([], '删除成功');
    }

    /* ================= 拼团订单 ================= */

    /**
     * 订单列表（status 0-4 / r1-r3 退款态；buytype 1单买 2发团 3参团）
     * GET /admin/collage/order/list?page&pagesize&status&buytype&keyword&teamid
     */
    public function orderList()
    {
        $aid = ScopeService::aidOf($this->request);

        $status   = trim((string) $this->request->get('status', ''));
        $buytype  = trim((string) $this->request->get('buytype', ''));
        $keyword  = trim((string) $this->request->get('keyword', ''));
        $teamId   = (int) $this->request->get('teamid', 0);
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->collageService->orderList($aid, $status, $buytype, $keyword, $teamId, $page, $pageSize));
    }

    /**
     * 订单改价（仅未支付）
     * POST /admin/collage/order/changePrice {id,price}
     */
    public function orderChangePrice()
    {
        $aid = ScopeService::aidOf($this->request);

        $id    = (int) $this->request->post('id', 0);
        $price = (float) $this->request->post('price', 0);

        $this->collageService->orderChangePrice($aid, $id, $price);

        return $this->success([], '改价成功');
    }

    /**
     * 关闭订单（仅未支付；回补库存+释放券）
     * POST /admin/collage/order/close {id}
     */
    public function orderClose()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->collageService->orderClose($aid, (int) $this->request->post('id', 0));

        return $this->success([], '订单已关闭');
    }

    /**
     * 退款审核（pass=1 同意→原路退款+退积分+退券同事务；pass=2 驳回）
     * POST /admin/collage/order/refundCheck {id,pass,remark}
     */
    public function orderRefundCheck()
    {
        $aid = ScopeService::aidOf($this->request);

        $id     = (int) $this->request->post('id', 0);
        $pass   = (int) $this->request->post('pass', 0);
        $remark = trim((string) $this->request->post('remark', ''));

        $this->collageService->orderRefundCheck($aid, $id, $pass, $remark);

        return $this->success([], 1 === $pass ? '已同意并退款' : '已驳回');
    }

    /**
     * 发货（已支付→已发货；已发货时更新物流）
     * POST /admin/collage/order/sendExpress {id,express_com,express_no}
     */
    public function orderSendExpress()
    {
        $aid = ScopeService::aidOf($this->request);

        $id         = (int) $this->request->post('id', 0);
        $expressCom = trim((string) $this->request->post('express_com', ''));
        $expressNo  = trim((string) $this->request->post('express_no', ''));

        $this->collageService->orderSendExpress($aid, $id, $expressCom, $expressNo);

        return $this->success([], '发货成功');
    }
}
