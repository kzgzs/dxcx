<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\ArticleService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 文章评论管理（列表/审核/删除/后台回复）
 */
class ArticlePinglun extends BaseController
{
    public function __construct(\think\App $app, protected ArticleService $articleService)
    {
        parent::__construct($app);
    }

    /**
     * 评论列表（分页 + 筛选）
     * GET /admin/articlepinglun/list?page&pagesize&keyword&status&start_time&end_time
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->articleService->pinglunList($aid, $page, $pageSize, [
            'content'    => (string) $this->request->get('keyword', ''),
            'status'     => (string) $this->request->get('status', ''),
            'start_time' => (int) $this->request->get('start_time', 0),
            'end_time'   => (int) $this->request->get('end_time', 0),
        ]));
    }

    /**
     * 批量审核评论（通过/驳回）
     * POST /admin/articlepinglun/setst {ids:[..], st:0|1, score?}
     */
    public function setst()
    {
        $aid = ScopeService::aidOf($this->request);

        $ids = $this->request->post('ids', []);
        $ids = is_array($ids) ? array_map('intval', $ids) : [];
        if (!$ids) {
            throw new BusinessException('缺少评论ID');
        }

        $this->articleService->pinglunSetst(
            $aid,
            $ids,
            (int) $this->request->post('st', 0),
            (int) $this->request->post('score', 0)
        );

        return $this->success([], '操作成功');
    }

    /**
     * 删除评论（级联删除回评与点赞）
     * POST /admin/articlepinglun/delete {ids:[..]}
     */
    public function delete()
    {
        $aid = ScopeService::aidOf($this->request);

        $ids = $this->request->post('ids', []);
        $ids = is_array($ids) ? array_map('intval', $ids) : [];
        if (!$ids) {
            throw new BusinessException('缺少评论ID');
        }

        $this->articleService->pinglunDelete($aid, $ids);

        return $this->success([], '删除成功');
    }

    /**
     * 后台回复评论（以平台身份直显）
     * POST /admin/articlepinglun/reply {pid, content}
     */
    public function reply()
    {
        $aid = ScopeService::aidOf($this->request);

        $pid     = (int) $this->request->post('pid', 0);
        $content = trim((string) $this->request->post('content', ''));
        if ($pid <= 0) {
            throw new BusinessException('缺少评论ID');
        }
        if ('' === $content) {
            throw new BusinessException('回复内容不能为空');
        }

        return $this->success($this->articleService->pinglunReply($aid, $pid, $content), '回复成功');
    }
}
