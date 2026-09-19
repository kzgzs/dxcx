<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\CarhailingService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 网约车管理（admin 应用，JWT role=admin，aid 走 ScopeService）
 *
 * 车辆分类 / 车辆管理 / 用车订单
 */
class Carhailing extends BaseController
{
    public function __construct(\think\App $app, protected CarhailingService $carhailingService)
    {
        parent::__construct($app);
    }

    /* ================= 车辆分类 ================= */

    /**
     * 分类列表
     * GET /admin/carhailing/category/list
     */
    public function categoryList()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->carhailingService->categoryList($aid));
    }

    /**
     * 新建分类
     * POST /admin/carhailing/category/add {name,sort,status}
     */
    public function categoryAdd()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->carhailingService->categoryAdd($aid, $this->request->post()), '创建成功');
    }

    /**
     * 编辑分类
     * POST /admin/carhailing/category/edit {id,...}
     */
    public function categoryEdit()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->carhailingService->categoryEdit($aid, $this->request->post());

        return $this->success([], '保存成功');
    }

    /**
     * 删除分类（软删；有车辆拒绝）
     * POST /admin/carhailing/category/delete {id}
     */
    public function categoryDelete()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->carhailingService->categoryDelete($aid, (int) $this->request->post('id', 0));

        return $this->success([], '删除成功');
    }

    /* ================= 车辆管理 ================= */

    /**
     * 车辆列表（keyword/category_id/status 筛选）
     * GET /admin/carhailing/product/list?page&pagesize&keyword&category_id&status
     */
    public function productList()
    {
        $aid = ScopeService::aidOf($this->request);

        $keyword    = trim((string) $this->request->get('keyword', ''));
        $categoryId = (int) $this->request->get('category_id', 0);
        $status     = trim((string) $this->request->get('status', ''));
        $page       = max(1, (int) $this->request->get('page', 1));
        $pageSize   = (int) $this->request->get('pagesize', 10);
        $pageSize   = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->carhailingService->productList($aid, $keyword, $categoryId, $status, $page, $pageSize));
    }

    /**
     * 新建车辆
     * POST /admin/carhailing/product/add {name,category_id,pic,pics?,price_day,price_half,car_num,desc,status}
     */
    public function productAdd()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->carhailingService->productAdd($aid, $this->request->post()), '创建成功');
    }

    /**
     * 编辑车辆
     * POST /admin/carhailing/product/edit {id,...}
     */
    public function productEdit()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->carhailingService->productEdit($aid, $this->request->post());

        return $this->success([], '保存成功');
    }

    /**
     * 删除车辆（软删；有未完结订单拒绝）
     * POST /admin/carhailing/product/delete {id}
     */
    public function productDelete()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->carhailingService->productDelete($aid, (int) $this->request->post('id', 0));

        return $this->success([], '删除成功');
    }

    /**
     * 上架/下架
     * POST /admin/carhailing/product/status {id,status}
     */
    public function productStatus()
    {
        $aid = ScopeService::aidOf($this->request);

        $id     = (int) $this->request->post('id', 0);
        $status = (int) $this->request->post('status', -1);

        $this->carhailingService->productStatus($aid, $id, $status);

        return $this->success([], 1 === $status ? '已上架' : '已下架');
    }

    /* ================= 用车订单 ================= */

    /**
     * 订单列表（含会员手机；keyword 匹配订单号/车辆名/手机号）
     * GET /admin/carhailing/order/list?page&pagesize&status&keyword
     */
    public function orderList()
    {
        $aid = ScopeService::aidOf($this->request);

        $status   = trim((string) $this->request->get('status', ''));
        $keyword  = trim((string) $this->request->get('keyword', ''));
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->carhailingService->adminOrderList($aid, $status, $keyword, $page, $pageSize));
    }

    /**
     * 完成订单（已支付/使用中 → 已完成）
     * POST /admin/carhailing/order/finish {id}
     */
    public function orderFinish()
    {
        $aid = ScopeService::aidOf($this->request);

        $id = (int) $this->request->post('id', 0);

        if ($id <= 0) {
            throw new BusinessException('缺少订单ID');
        }

        $this->carhailingService->adminOrderFinish($aid, $id);

        return $this->success([], '订单已完成');
    }
}
