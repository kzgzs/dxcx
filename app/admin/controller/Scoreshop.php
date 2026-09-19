<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\ScopeService;
use app\common\service\ScoreshopService;

/**
 * 租户后台 - 积分商城（admin 应用，JWT role=admin，aid 走 ScopeService）
 *
 * 覆盖：商城设置 / 分类 CRUD / 积分商品 CRUD（纯积分或积分+钱） / 兑换订单与关单
 * 超时自动关单挂 app\command\ScheduleRun（ScoreshopService::closeExpired）
 */
class Scoreshop extends BaseController
{
    public function __construct(\think\App $app, protected ScoreshopService $scoreshopService)
    {
        parent::__construct($app);
    }

    /**
     * 积分商城设置读取
     * GET /admin/scoreshop/set
     */
    public function set()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->scoreshopService->getSysset($aid));
    }

    /**
     * 积分商城设置保存（自动关单/自动收货/进入人群）
     * POST /admin/scoreshop/set {autoclose, autoshdays, gettj, gettjtip, ...}
     */
    public function saveSet()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->scoreshopService->saveSysset($aid, $this->request->post());

        return $this->success([], '保存成功');
    }

    /**
     * 分类列表
     * GET /admin/scoreshop/category/list?keyword
     */
    public function categoryList()
    {
        $aid = ScopeService::aidOf($this->request);

        $keyword = trim((string) $this->request->get('keyword', ''));

        return $this->success($this->scoreshopService->categoryList($aid, $keyword));
    }

    /**
     * 分类新增/编辑
     * POST /admin/scoreshop/category/save {id?, name, pic?, sort?, status?}
     */
    public function categorySave()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->scoreshopService->categorySave($aid, $this->request->post()), '保存成功');
    }

    /**
     * 分类删除（分类下有商品时拒绝）
     * POST /admin/scoreshop/category/delete {id}
     */
    public function categoryDelete()
    {
        $aid = ScopeService::aidOf($this->request);

        $id = (int) $this->request->post('id', 0);

        $this->scoreshopService->categoryDelete($aid, $id);

        return $this->success([], '删除成功');
    }

    /**
     * 积分商品列表（附规格行）
     * GET /admin/scoreshop/product/list?page&pagesize&keyword&status&cid
     */
    public function productList()
    {
        $aid = ScopeService::aidOf($this->request);

        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        $keyword = trim((string) $this->request->get('keyword', ''));
        $status  = trim((string) $this->request->get('status', ''));
        $cid     = (int) $this->request->get('cid', 0);

        return $this->success($this->scoreshopService->productList($aid, $page, $pageSize, $keyword, $status, $cid));
    }

    /**
     * 积分商品新增/编辑（含规格 upsert）
     * POST /admin/scoreshop/product/save {id?, cid, name, score_price, money_price, ...}
     */
    public function productSave()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->scoreshopService->productSave($aid, $this->request->post()), '保存成功');
    }

    /**
     * 积分商品删除（已有兑换订单时拒绝）
     * POST /admin/scoreshop/product/delete {id}
     */
    public function productDelete()
    {
        $aid = ScopeService::aidOf($this->request);

        $id = (int) $this->request->post('id', 0);

        $this->scoreshopService->productDelete($aid, $id);

        return $this->success([], '删除成功');
    }

    /**
     * 积分商品上下架
     * POST /admin/scoreshop/product/status {id,status}
     */
    public function productStatus()
    {
        $aid = ScopeService::aidOf($this->request);

        $id     = (int) $this->request->post('id', 0);
        $status = (int) $this->request->post('status', 0);

        $this->scoreshopService->productStatus($aid, $id, $status);

        return $this->success([], 1 === $status ? '已上架' : '已下架');
    }

    /**
     * 兑换订单列表（附订单商品行）
     * GET /admin/scoreshop/order/list?page&pagesize&status&ordernum&title&linkman&tel&start_date&end_date
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
            'title'      => trim((string) $this->request->get('title', '')),
            'linkman'    => trim((string) $this->request->get('linkman', '')),
            'tel'        => trim((string) $this->request->get('tel', '')),
            'start_date' => trim((string) $this->request->get('start_date', '')),
            'end_date'   => trim((string) $this->request->get('end_date', '')),
        ];

        return $this->success($this->scoreshopService->orderList($aid, $page, $pageSize, $filters));
    }

    /**
     * 手动关单（仅未支付；同事务回补库存/销量）
     * POST /admin/scoreshop/order/close {id}
     */
    public function orderClose()
    {
        $aid = ScopeService::aidOf($this->request);

        $id = (int) $this->request->post('id', 0);

        $this->scoreshopService->orderClose($aid, $id);

        return $this->success([], '订单已关闭');
    }
}
