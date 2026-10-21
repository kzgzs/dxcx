<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\CycleService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 周期购 商品管理（片A）
 *
 * 对齐 canzhao 拆控制器：商品列表/详情/新增编辑/删除/上下架/审核。
 * 视图字段（基本信息两部分 + 规格项目表 + 商品详情装修）统一由 CycleService 落库。
 */
class CycleProduct extends BaseController
{
    public function __construct(\think\App $app, protected CycleService $cycleService)
    {
        parent::__construct($app);
    }

    /**
     * 商品列表（附分类名/规格行）
     * GET /admin/cycle/product/list?page&pagesize&keyword&status&cid&bid&checked&orderField&orderType
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
     * 商品新增/编辑（基本信息两部分 + 规格项目表 + 详情装修）
     * POST /admin/cycle/product/save
     */
    public function productSave()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->cycleService->productSave($aid, $this->request->post()), '保存成功');
    }

    /**
     * 商品删除（已有订单时拒绝）
     * POST /admin/cycle/product/del {ids[]}
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
     * POST /admin/cycle/product/status {ids[], status}
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
}
