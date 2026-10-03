<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\LuntanService;
use app\common\service\PlogService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 用户论坛：评论管理（批次5-B 任务195）
 */
class LuntanPinglun extends BaseController
{
    public function __construct(\think\App $app, protected LuntanService $service)
    {
        parent::__construct($app);
    }

    /**
     * 评论列表
     * GET /admin/luntanPinglun/list?status=&sid=&keyword=&page=&pagesize=
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->service->adminPinglunList($aid, [
            'status'   => (string) $this->request->get('status', ''),
            'sid'      => (int) $this->request->get('sid', 0),
            'keyword'   => trim((string) $this->request->get('keyword', '')),
            'starttime' => trim((string) $this->request->get('starttime', '')),
            'endtime'   => trim((string) $this->request->get('endtime', '')),
            'page'      => max(1, (int) $this->request->get('page', 1)),
            'pagesize'  => (int) $this->request->get('pagesize', 10),
        ]));
    }

    /**
     * 评论审核（显示/隐藏）
     * POST /admin/luntanPinglun/audit {ids[], status: 1显示|0隐藏}
     */
    public function audit()
    {
        $aid = ScopeService::aidOf($this->request);

        $ids    = array_map('intval', (array) $this->request->post('ids', []));
        $status = (int) $this->request->post('status', 1);

        if (empty($ids)) {
            throw new BusinessException('请选择评论');
        }

        $this->service->adminPinglunAudit($aid, $ids, $status);
        PlogService::write($aid, $this->uid(), '论坛评论审核' . (1 === $status ? '显示' : '隐藏') . implode(',', $ids));

        return $this->success([], '操作成功');
    }

    /**
     * 评论删除（连带回评）
     * POST /admin/luntanPinglun/delete {ids[]}
     */
    public function delete()
    {
        $aid = ScopeService::aidOf($this->request);

        $ids = array_map('intval', (array) $this->request->post('ids', []));
        if (empty($ids)) {
            throw new BusinessException('请选择评论');
        }

        $this->service->adminPinglunDelete($aid, $ids);
        PlogService::write($aid, $this->uid(), '删除论坛评论' . implode(',', $ids));

        return $this->success([], '删除成功');
    }

    protected function uid(): int
    {
        return (int) ($this->request->authUid ?? 0);
    }
}
