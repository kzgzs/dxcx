<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\ArticleService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 文章分类管理（二级分类 CRUD）
 */
class ArticleCategory extends BaseController
{
    public function __construct(\think\App $app, protected ArticleService $articleService)
    {
        parent::__construct($app);
    }

    /**
     * 分类列表（平铺含 pid）
     * GET /admin/articlecategory/list
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->articleService->categoryList($aid));
    }

    /**
     * 新增/编辑分类（pid 必须为 0 或顶级分类）
     * POST /admin/articlecategory/save {id?, pid, name, sort?, pic?}
     */
    public function save()
    {
        $aid = ScopeService::aidOf($this->request);

        $post = is_array($this->request->post()) ? $this->request->post() : [];

        return $this->success(
            $this->articleService->categorySave($aid, $post),
            !empty($post['id']) ? '保存成功' : '新增成功'
        );
    }

    /**
     * 删除分类（级联删除子分类）
     * POST /admin/articlecategory/delete {ids:[..]}
     */
    public function delete()
    {
        $aid = ScopeService::aidOf($this->request);

        $ids = $this->request->post('ids', []);
        $ids = is_array($ids) ? array_map('intval', $ids) : [];
        if (!$ids) {
            throw new BusinessException('缺少分类ID');
        }

        $this->articleService->categoryDelete($aid, $ids);

        return $this->success([], '删除成功');
    }
}
