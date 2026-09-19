<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\ScopeService;
use app\common\service\ShortvideoService;

/**
 * 租户后台 - 短视频评论管理（审核/回复/删除）
 *
 * 对照参考系统 app/controller/ShortvideoComment.php
 */
class ShortvideoComment extends BaseController
{
    public function __construct(\think\App $app, protected ShortvideoService $shortvideoService)
    {
        parent::__construct($app);
    }

    /**
     * 评论列表（status/vid/content 过滤，附视频名与回复数）
     * GET /admin/shortvideo/comment/list?page&pagesize&status&vid&content
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->shortvideoService->commentList(
            $aid,
            trim((string) $this->request->get('status', '')),
            (int) $this->request->get('vid', 0),
            trim((string) $this->request->get('content', '')),
            $page,
            $pageSize
        ));
    }

    /**
     * 评论批量审核（显示/隐藏）
     * POST /admin/shortvideo/comment/setst {ids[],status}
     */
    public function setst()
    {
        $aid = ScopeService::aidOf($this->request);

        $ids = (array) $this->request->post('ids/a', []);
        if (empty($ids)) {
            $ids = [(int) $this->request->post('id', 0)];
        }

        $this->shortvideoService->commentSetStatus($aid, array_map('intval', $ids), (int) $this->request->post('status', 1));

        return $this->success([], '操作成功');
    }

    /**
     * 平台回复评论（以管理员身份写回复表）
     * POST /admin/shortvideo/comment/reply {id,content}
     */
    public function reply()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->shortvideoService->commentReply(
            $aid,
            (int) $this->request->post('id', 0),
            trim((string) $this->request->post('content', ''))
        );

        return $this->success([], '回复成功');
    }

    /**
     * 评论批量删除（级联回复与评论点赞日志）
     * POST /admin/shortvideo/comment/delete {ids[]}
     */
    public function delete()
    {
        $aid = ScopeService::aidOf($this->request);

        $ids = (array) $this->request->post('ids/a', []);
        if (empty($ids)) {
            $ids = [(int) $this->request->post('id', 0)];
        }

        $this->shortvideoService->commentDelete($aid, array_map('intval', $ids));

        return $this->success([], '删除成功');
    }
}
