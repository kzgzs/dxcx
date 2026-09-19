<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\ScopeService;
use app\common\service\ShortvideoService;

/**
 * 租户后台 - 短视频分类管理
 *
 * 对照参考系统 app/controller/ShortvideoCategory.php
 */
class ShortvideoCategory extends BaseController
{
    public function __construct(\think\App $app, protected ShortvideoService $shortvideoService)
    {
        parent::__construct($app);
    }

    /**
     * 分类列表
     * GET /admin/shortvideo/category/list?page&pagesize&keyword&bid
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->shortvideoService->categoryList(
            $aid,
            trim((string) $this->request->get('bid', '')),
            trim((string) $this->request->get('keyword', '')),
            $page,
            $pageSize
        ));
    }

    /**
     * 启用分类下拉（视频编辑/筛选用；bid=-1 全部，其余按商户维度）
     * GET /admin/shortvideo/category/options?bid=
     */
    public function options()
    {
        $aid = ScopeService::aidOf($this->request);

        $bidRaw = trim((string) $this->request->get('bid', ''));
        $bid    = '' === $bidRaw ? -1 : (int) $bidRaw;

        return $this->success($this->shortvideoService->categoryOptions($aid, $bid));
    }

    /**
     * 分类新增/编辑
     * POST /admin/shortvideo/category/save {id,name,status,sort,bid}
     */
    public function save()
    {
        $aid = ScopeService::aidOf($this->request);

        $result = $this->shortvideoService->categorySave($aid, $this->request->post());

        return $this->success($result, '保存成功');
    }

    /**
     * 分类删除（分类下有视频拒绝）
     * POST /admin/shortvideo/category/delete {id}
     */
    public function delete()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->shortvideoService->categoryDelete($aid, (int) $this->request->post('id', 0));

        return $this->success([], '删除成功');
    }
}
