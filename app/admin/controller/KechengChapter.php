<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\KechengService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 知识付费·课程章节（批次5-D1 任务197）
 *
 * kctype 1图文 2音频 3视频；免费 ismianfei / 禁快进 isjinzhi / 学完送积分 give_score
 * 独立菜单与课程列表嵌入共用（embeddedProid 由前端按课程过滤）
 */
class KechengChapter extends BaseController
{
    public function __construct(\think\App $app, protected KechengService $kechengService)
    {
        parent::__construct($app);
    }

    /**
     * 章节列表
     * GET /admin/kechengChapter/list?kcid&name&status&page&pagesize
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        $filters = [
            'kcid'   => (int) $this->request->get('kcid', 0),
            'name'   => trim((string) $this->request->get('name', '')),
            'status' => (string) $this->request->get('status', ''),
        ];

        return $this->success($this->kechengService->chapterList($aid, $filters, $page, $pageSize));
    }

    /**
     * 章节详情（编辑回填）
     * GET /admin/kechengChapter/detail?id
     */
    public function detail()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->kechengService->chapterDetail($aid, (int) $this->request->get('id', 0)));
    }

    /**
     * 章节新增/编辑
     * POST /admin/kechengChapter/save
     */
    public function save()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->kechengService->chapterSave($aid, $this->request->post()), '保存成功');
    }

    /**
     * 章节删除（支持单条 id 或批量 ids）
     * POST /admin/kechengChapter/delete {id|ids}
     */
    public function delete()
    {
        $aid = ScopeService::aidOf($this->request);

        $ids = (array) $this->request->post('ids', []);
        if (empty($ids)) {
            $ids = [(int) $this->request->post('id', 0)];
        }

        $this->kechengService->chapterDelete($aid, array_map('intval', $ids));

        return $this->success([], '删除成功');
    }

    /**
     * 章节批量上下架
     * POST /admin/kechengChapter/status {ids,status}
     */
    public function status()
    {
        $aid = ScopeService::aidOf($this->request);

        $ids = array_map('intval', (array) $this->request->post('ids', []));

        $this->kechengService->chapterStatus($aid, $ids, (int) $this->request->post('status', 0));

        return $this->success([], '操作成功');
    }
}
