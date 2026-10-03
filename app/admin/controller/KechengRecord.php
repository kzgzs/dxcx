<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\KechengExamService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 知识付费·答题记录（对标 canzhao KechengRecord）
 *
 * 学员考试答卷列表 + 逐题明细；随课程嵌入（embeddedProid 按课程过滤）
 */
class KechengRecord extends BaseController
{
    public function __construct(\think\App $app, protected KechengExamService $examService)
    {
        parent::__construct($app);
    }

    /**
     * 答题记录列表
     * GET /admin/kechengRecord/list?kcid&mid&page&pagesize
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        $filters = [
            'kcid' => (int) $this->request->get('kcid', 0),
            'mid'  => (int) $this->request->get('mid', 0),
        ];

        return $this->success($this->examService->recordList($aid, $filters, $page, $pageSize));
    }

    /**
     * 答题详情（逐题）
     * GET /admin/kechengRecord/logs?rid&page&pagesize
     */
    public function logs()
    {
        $aid = ScopeService::aidOf($this->request);

        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success(
            $this->examService->recordLogs($aid, (int) $this->request->get('rid', 0), $page, $pageSize)
        );
    }

    /**
     * 答题记录删除（批量，连同明细）
     * POST /admin/kechengRecord/delete {ids}
     */
    public function delete()
    {
        $aid = ScopeService::aidOf($this->request);

        $ids = array_map('intval', (array) $this->request->post('ids', []));

        $this->examService->recordDelete($aid, $ids);

        return $this->success([], '删除成功');
    }
}
