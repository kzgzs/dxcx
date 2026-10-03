<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\LuntanService;
use app\common\service\PlogService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 用户论坛：回评管理（批次5-B 任务195）
 */
class LuntanPlreply extends BaseController
{
    public function __construct(\think\App $app, protected LuntanService $service)
    {
        parent::__construct($app);
    }

    /**
     * 回评列表
     * GET /admin/luntanPlreply/list?status=&keyword=&page=&pagesize=
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->service->adminPlreplyList($aid, [
            'status'    => (string) $this->request->get('status', ''),
            'keyword'   => trim((string) $this->request->get('keyword', '')),
            'starttime' => trim((string) $this->request->get('starttime', '')),
            'endtime'   => trim((string) $this->request->get('endtime', '')),
            'page'      => max(1, (int) $this->request->get('page', 1)),
            'pagesize'  => (int) $this->request->get('pagesize', 10),
        ]));
    }

    /**
     * 回评审核（显示/隐藏）
     * POST /admin/luntanPlreply/audit {ids[], status: 1显示|0隐藏}
     */
    public function audit()
    {
        $aid = ScopeService::aidOf($this->request);

        $ids    = array_map('intval', (array) $this->request->post('ids', []));
        $status = (int) $this->request->post('status', 1);

        if (empty($ids)) {
            throw new BusinessException('请选择回评');
        }

        $this->service->adminPlreplyAudit($aid, $ids, $status);
        PlogService::write($aid, $this->uid(), '论坛回评审核' . (1 === $status ? '显示' : '隐藏') . implode(',', $ids));

        return $this->success([], '操作成功');
    }

    /**
     * 回评删除
     * POST /admin/luntanPlreply/delete {ids[]}
     */
    public function delete()
    {
        $aid = ScopeService::aidOf($this->request);

        $ids = array_map('intval', (array) $this->request->post('ids', []));
        if (empty($ids)) {
            throw new BusinessException('请选择回评');
        }

        $this->service->adminPlreplyDelete($aid, $ids);
        PlogService::write($aid, $this->uid(), '删除论坛回评' . implode(',', $ids));

        return $this->success([], '删除成功');
    }

    protected function uid(): int
    {
        return (int) ($this->request->authUid ?? 0);
    }
}
