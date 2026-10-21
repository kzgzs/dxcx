<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\CycleService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 周期购 商品分类（片A）
 *
 * 对齐 canzhao 拆控制器：分类列表 / 新增编辑 / 删除（已有商品时拒绝）。
 * 字段：name 名称 / pic 分类图标(400x400) / sort 序号(越大越靠前) / status 1显示 0隐藏。
 */
class CycleCategory extends BaseController
{
    public function __construct(\think\App $app, protected CycleService $cycleService)
    {
        parent::__construct($app);
    }

    /**
     * 分类列表（平铺，附商品数）
     * GET /admin/cycle/category/list
     */
    public function categoryList()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->cycleService->categoryList($aid));
    }

    /**
     * 分类新增/编辑
     * POST /admin/cycle/category/save {id?, name, pic, sort, status}
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
}
