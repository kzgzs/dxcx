<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\KechengService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 知识付费·课程管理（批次5-D1 任务197）
 *
 * 含分销配置（commissionset 四模式/commissiondata1-3）、答题参数（sxdate/isdt/dtnum/hgscore）、
 * 会员价（lvprice/lvprice_data）、购买权限（gettj）、顺序学习/考试解锁（orderlearn/learnhg）
 */
class KechengList extends BaseController
{
    public function __construct(\think\App $app, protected KechengService $kechengService)
    {
        parent::__construct($app);
    }

    /**
     * 课程列表
     * GET /admin/kechengList/list?page&pagesize&keyword&status&cid
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        $keyword  = trim((string) $this->request->get('keyword', ''));
        $status   = trim((string) $this->request->get('status', ''));
        $cid      = (int) $this->request->get('cid', 0);
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->kechengService->courseList($aid, $keyword, $status, $cid, $page, $pageSize));
    }

    /**
     * 课程详情（编辑回显）
     * GET /admin/kechengList/detail?id
     */
    public function detail()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->kechengService->courseDetail($aid, (int) $this->request->get('id', 0)));
    }

    /**
     * 课程新增/编辑
     * POST /admin/kechengList/save
     */
    public function save()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->kechengService->courseSave($aid, $this->request->post()), '保存成功');
    }

    /**
     * 课程删除
     * POST /admin/kechengList/delete {id}
     */
    public function delete()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->kechengService->courseDelete($aid, (int) $this->request->post('id', 0));

        return $this->success([], '删除成功');
    }

    /**
     * 上下架
     * POST /admin/kechengList/status {id,status}
     */
    public function status()
    {
        $aid = ScopeService::aidOf($this->request);

        $id     = (int) $this->request->post('id', 0);
        $status = (int) $this->request->post('status', -1);

        $this->kechengService->courseStatus($aid, $id, $status);

        return $this->success([], 1 === $status ? '已上架' : '已下架');
    }
}
