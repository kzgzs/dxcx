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
 */
class KechengChapter extends BaseController
{
    public function __construct(\think\App $app, protected KechengService $kechengService)
    {
        parent::__construct($app);
    }

    /**
     * 章节列表（按课程）
     * GET /admin/kechengChapter/list?kcid&page&pagesize
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        $kcid     = (int) $this->request->get('kcid', 0);
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->kechengService->chapterList($aid, $kcid, $page, $pageSize));
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
     * 章节删除
     * POST /admin/kechengChapter/delete {id}
     */
    public function delete()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->kechengService->chapterDelete($aid, (int) $this->request->post('id', 0));

        return $this->success([], '删除成功');
    }
}
