<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\RestaurantService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 餐饮管理（admin 应用，JWT role=admin，aid 走 ScopeService）
 *
 * 区域桌台 / 菜品分类 / 餐饮商品 / 堂食订单
 */
class Restaurant extends BaseController
{
    public function __construct(\think\App $app, protected RestaurantService $restaurantService)
    {
        parent::__construct($app);
    }

    /* ================= 区域与桌台 ================= */

    /**
     * 区域列表
     * GET /admin/restaurant/area/list
     */
    public function areaList()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->restaurantService->areaList($aid));
    }

    /**
     * 新建区域
     * POST /admin/restaurant/area/add {name,sort}
     */
    public function areaAdd()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->restaurantService->areaAdd($aid, $this->request->post()), '创建成功');
    }

    /**
     * 编辑区域
     * POST /admin/restaurant/area/edit {id,...}（空值视为不修改）
     */
    public function areaEdit()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->restaurantService->areaEdit($aid, $this->request->post());

        return $this->success([], '保存成功');
    }

    /**
     * 删除区域（有桌台拒绝）
     * POST /admin/restaurant/area/delete {id}
     */
    public function areaDelete()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->restaurantService->areaDelete($aid, (int) $this->request->post('id', 0));

        return $this->success([], '删除成功');
    }

    /**
     * 桌台列表（area_id 筛选；busy 由未完结订单推导）
     * GET /admin/restaurant/table/list?area_id=&page&pagesize
     */
    public function tableList()
    {
        $aid = ScopeService::aidOf($this->request);

        $areaId   = (int) $this->request->get('area_id', 0);
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->restaurantService->tableList($aid, $areaId, $page, $pageSize));
    }

    /**
     * 新建桌台
     * POST /admin/restaurant/table/add {area_id,name,persons,sort}
     */
    public function tableAdd()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->restaurantService->tableAdd($aid, $this->request->post()), '创建成功');
    }

    /**
     * 编辑桌台
     * POST /admin/restaurant/table/edit {id,...}（空值视为不修改）
     */
    public function tableEdit()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->restaurantService->tableEdit($aid, $this->request->post());

        return $this->success([], '保存成功');
    }

    /**
     * 删除桌台（有未完结订单拒绝）
     * POST /admin/restaurant/table/delete {id}
     */
    public function tableDelete()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->restaurantService->tableDelete($aid, (int) $this->request->post('id', 0));

        return $this->success([], '删除成功');
    }

    /* ================= 菜品分类 ================= */

    /**
     * 分类列表
     * GET /admin/restaurant/category/list
     */
    public function categoryList()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->restaurantService->categoryList($aid));
    }

    /**
     * 新建分类
     * POST /admin/restaurant/category/add {name,pic?,sort?,status?}
     */
    public function categoryAdd()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->restaurantService->categoryAdd($aid, $this->request->post()), '创建成功');
    }

    /**
     * 编辑分类
     * POST /admin/restaurant/category/edit {id,...}（空值视为不修改）
     */
    public function categoryEdit()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->restaurantService->categoryEdit($aid, $this->request->post());

        return $this->success([], '保存成功');
    }

    /**
     * 删除分类（有菜品拒绝）
     * POST /admin/restaurant/category/delete {id}
     */
    public function categoryDelete()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->restaurantService->categoryDelete($aid, (int) $this->request->post('id', 0));

        return $this->success([], '删除成功');
    }

    /* ================= 餐饮商品 ================= */

    /**
     * 菜品列表（keyword/category_id/status 筛选）
     * GET /admin/restaurant/product/list?page&pagesize&keyword&category_id&status
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

        return $this->success($this->restaurantService->productList($aid, $keyword, $categoryId, $status, $page, $pageSize));
    }

    /**
     * 新建菜品
     * POST /admin/restaurant/product/add {name,category_id,pic,price,desc,status}
     */
    public function productAdd()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->restaurantService->productAdd($aid, $this->request->post()), '创建成功');
    }

    /**
     * 编辑菜品
     * POST /admin/restaurant/product/edit {id,...}（空值视为不修改）
     */
    public function productEdit()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->restaurantService->productEdit($aid, $this->request->post());

        return $this->success([], '保存成功');
    }

    /**
     * 删除菜品（软删；有未完结订单拒绝）
     * POST /admin/restaurant/product/delete {id}
     */
    public function productDelete()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->restaurantService->productDelete($aid, (int) $this->request->post('id', 0));

        return $this->success([], '删除成功');
    }

    /**
     * 上架/下架
     * POST /admin/restaurant/product/status {id,status}
     */
    public function productStatus()
    {
        $aid = ScopeService::aidOf($this->request);

        $id     = (int) $this->request->post('id', 0);
        $status = (int) $this->request->post('status', -1);

        $this->restaurantService->productStatus($aid, $id, $status);

        return $this->success([], 1 === $status ? '已上架' : '已下架');
    }

    /* ================= 堂食订单 ================= */

    /**
     * 订单列表（含 goods 数组；keyword 匹配订单号/桌号）
     * GET /admin/restaurant/order/list?page&pagesize&status&keyword
     */
    public function orderList()
    {
        $aid = ScopeService::aidOf($this->request);

        $status   = trim((string) $this->request->get('status', ''));
        $keyword  = trim((string) $this->request->get('keyword', ''));
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->restaurantService->adminOrderList($aid, $status, $keyword, $page, $pageSize));
    }

    /**
     * 完成订单（已支付 → 已完成）
     * POST /admin/restaurant/order/finish {id}
     */
    public function orderFinish()
    {
        $aid = ScopeService::aidOf($this->request);

        $id = (int) $this->request->post('id', 0);

        if ($id <= 0) {
            throw new BusinessException('缺少订单ID');
        }

        $this->restaurantService->adminOrderFinish($aid, $id);

        return $this->success([], '订单已完成');
    }

    /**
     * 取消订单（仅待支付；释放桌台占用）
     * POST /admin/restaurant/order/cancel {id}
     */
    public function orderCancel()
    {
        $aid = ScopeService::aidOf($this->request);

        $id = (int) $this->request->post('id', 0);

        if ($id <= 0) {
            throw new BusinessException('缺少订单ID');
        }

        $this->restaurantService->adminOrderCancel($aid, $id);

        return $this->success([], '订单已取消');
    }
}
