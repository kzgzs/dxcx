<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\LuntanService;
use app\common\service\PlogService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 用户论坛：版块管理（批次5-B 任务195）
 */
class LuntanCategory extends BaseController
{
    public function __construct(\think\App $app, protected LuntanService $service)
    {
        parent::__construct($app);
    }

    /**
     * 版块列表（树形）
     * GET /admin/luntanCategory/list
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->service->adminCategoryList($aid));
    }

    /**
     * 版块新增/编辑（含扩展配置：挂商品开关/发帖收积分/置顶套餐/发帖赠券）
     * POST /admin/luntanCategory/save
     */
    public function save()
    {
        $aid = ScopeService::aidOf($this->request);

        $id = $this->service->adminCategorySave($aid, $this->request->post());
        PlogService::write($aid, $this->uid(), '保存论坛版块' . $id);

        return $this->success(['id' => $id], '保存成功');
    }

    /**
     * 版块删除
     * POST /admin/luntanCategory/delete {ids[]}
     */
    public function delete()
    {
        $aid = ScopeService::aidOf($this->request);

        $ids = array_map('intval', (array) $this->request->post('ids', []));
        if (empty($ids)) {
            throw new BusinessException('请选择版块');
        }

        $this->service->adminCategoryDelete($aid, $ids);
        PlogService::write($aid, $this->uid(), '删除论坛版块' . implode(',', $ids));

        return $this->success([], '删除成功');
    }

    protected function uid(): int
    {
        return (int) ($this->request->authUid ?? 0);
    }
}
