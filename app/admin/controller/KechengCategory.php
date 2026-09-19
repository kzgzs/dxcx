<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\KechengService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 知识付费·课程分类（批次5-D1 任务197）
 */
class KechengCategory extends BaseController
{
    public function __construct(\think\App $app, protected KechengService $kechengService)
    {
        parent::__construct($app);
    }

    /**
     * 分类列表
     * GET /admin/kechengCategory/list
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->kechengService->categoryList($aid));
    }

    /**
     * 分类新增/编辑
     * POST /admin/kechengCategory/save {id?,pid,name,pic,status,sort}
     */
    public function save()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->kechengService->categorySave($aid, $this->request->post()), '保存成功');
    }

    /**
     * 分类删除
     * POST /admin/kechengCategory/delete {id}
     */
    public function delete()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->kechengService->categoryDelete($aid, (int) $this->request->post('id', 0));

        return $this->success([], '删除成功');
    }
}
