<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\ScopeService;
use app\common\service\ShortvideoService;

/**
 * 租户后台 - 短视频回复（楼中楼）管理
 *
 * 对照参考系统 app/controller/ShortvideoCommentReply.php
 */
class ShortvideoCommentReply extends BaseController
{
    public function __construct(\think\App $app, protected ShortvideoService $shortvideoService)
    {
        parent::__construct($app);
    }

    /**
     * 回复列表（status/pid/content 过滤，附父评论内容与视频名）
     * GET /admin/shortvideo/reply/list?page&pagesize&status&pid&content
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->shortvideoService->replyList(
            $aid,
            trim((string) $this->request->get('status', '')),
            (int) $this->request->get('pid', 0),
            trim((string) $this->request->get('content', '')),
            $page,
            $pageSize,
            trim((string) $this->request->get('createtime', '')),
            trim((string) $this->request->get('orderby', ''))
        ));
    }

    /**
     * 回复批量审核（显示/隐藏）
     * POST /admin/shortvideo/reply/setst {ids[],status}
     */
    public function setst()
    {
        $aid = ScopeService::aidOf($this->request);

        $ids = (array) $this->request->post('ids/a', []);
        if (empty($ids)) {
            $ids = [(int) $this->request->post('id', 0)];
        }

        $this->shortvideoService->replySetStatus($aid, array_map('intval', $ids), (int) $this->request->post('status', 1));

        return $this->success([], '操作成功');
    }

    /**
     * 回复批量删除
     * POST /admin/shortvideo/reply/delete {ids[]}
     */
    public function delete()
    {
        $aid = ScopeService::aidOf($this->request);

        $ids = (array) $this->request->post('ids/a', []);
        if (empty($ids)) {
            $ids = [(int) $this->request->post('id', 0)];
        }

        $this->shortvideoService->replyDelete($aid, array_map('intval', $ids));

        return $this->success([], '删除成功');
    }
}
