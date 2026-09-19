<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\KechengExamService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 知识付费·讲师审核（批次5-D2 任务198）
 *
 * 讲师申请审核闭环：列表/审核通过驳回/启停用（C端申请见 api Kecheng::lecturerapply）
 */
class KechengLecturer extends BaseController
{
    public function __construct(\think\App $app, protected KechengExamService $examService)
    {
        parent::__construct($app);
    }

    /**
     * 讲师列表
     * GET /admin/kechengLecturer/list?page&pagesize&keyword&checkstatus
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->examService->lecturerList(
            $aid,
            trim((string) $this->request->get('keyword', '')),
            trim((string) $this->request->get('checkstatus', '')),
            $page,
            $pageSize
        ));
    }

    /**
     * 审核（通过/驳回）
     * POST /admin/kechengLecturer/check {id,checkstatus,reason}
     */
    public function check()
    {
        $aid = ScopeService::aidOf($this->request);

        $checkstatus = (int) $this->request->post('checkstatus', 0);

        $this->examService->lecturerCheck(
            $aid,
            (int) $this->request->post('id', 0),
            $checkstatus,
            trim((string) $this->request->post('reason', ''))
        );

        return $this->success([], 1 === $checkstatus ? '已通过审核' : '已驳回申请');
    }

    /**
     * 启停用
     * POST /admin/kechengLecturer/status {id,status}
     */
    public function status()
    {
        $aid = ScopeService::aidOf($this->request);

        $status = (int) $this->request->post('status', 0);

        $this->examService->lecturerStatus($aid, (int) $this->request->post('id', 0), $status);

        return $this->success([], 1 === $status ? '已启用' : '已停用');
    }
}
