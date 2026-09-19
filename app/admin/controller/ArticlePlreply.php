<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\ArticleService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 文章回评管理（列表/审核/删除）
 */
class ArticlePlreply extends BaseController
{
    public function __construct(\think\App $app, protected ArticleService $articleService)
    {
        parent::__construct($app);
    }

    /**
     * 回评列表（分页 + 筛选）
     * GET /admin/articleplreply/list?page&pagesize&keyword&status
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->articleService->replyList($aid, $page, $pageSize, [
            'content' => (string) $this->request->get('keyword', ''),
            'status'  => (string) $this->request->get('status', ''),
        ]));
    }

    /**
     * 批量审核回评（通过/驳回）
     * POST /admin/articleplreply/setst {ids:[..], st:0|1}
     */
    public function setst()
    {
        $aid = ScopeService::aidOf($this->request);

        $ids = $this->request->post('ids', []);
        $ids = is_array($ids) ? array_map('intval', $ids) : [];
        if (!$ids) {
            throw new BusinessException('缺少回评ID');
        }

        $this->articleService->replySetst($aid, $ids, (int) $this->request->post('st', 0));

        return $this->success([], '操作成功');
    }

    /**
     * 删除回评
     * POST /admin/articleplreply/delete {ids:[..]}
     */
    public function delete()
    {
        $aid = ScopeService::aidOf($this->request);

        $ids = $this->request->post('ids', []);
        $ids = is_array($ids) ? array_map('intval', $ids) : [];
        if (!$ids) {
            throw new BusinessException('缺少回评ID');
        }

        $this->articleService->replyDelete($aid, $ids);

        return $this->success([], '删除成功');
    }
}
