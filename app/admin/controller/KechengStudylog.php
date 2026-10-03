<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\KechengService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 知识付费·学习记录（批次5-D1 任务197）
 */
class KechengStudylog extends BaseController
{
    public function __construct(\think\App $app, protected KechengService $kechengService)
    {
        parent::__construct($app);
    }

    /**
     * 学习记录列表
     * GET /admin/kechengStudylog/list?page&pagesize
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->kechengService->studylogList($aid, $page, $pageSize));
    }

    /**
     * 删除学习记录
     * POST /admin/kechengStudylog/delete {id}
     */
    public function delete()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->kechengService->studylogDelete($aid, (int) $this->request->post('id', 0));

        return $this->success([], '删除成功');
    }
}
