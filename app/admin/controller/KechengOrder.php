<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\KechengService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 知识付费·课程订单（批次5-D1 任务197）
 */
class KechengOrder extends BaseController
{
    public function __construct(\think\App $app, protected KechengService $kechengService)
    {
        parent::__construct($app);
    }

    /**
     * 订单列表
     * GET /admin/kechengOrder/list?page&pagesize&ordernum&ctime&status
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        $ordernum = trim((string) $this->request->get('ordernum', ''));
        $ctime    = trim((string) $this->request->get('ctime', ''));
        $status   = trim((string) $this->request->get('status', ''));
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->kechengService->orderList($aid, $ordernum, $ctime, $status, $page, $pageSize));
    }

    /**
     * 订单详情
     * GET /admin/kechengOrder/detail?id
     */
    public function detail()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->kechengService->orderDetail($aid, (int) $this->request->get('id', 0)));
    }

    /**
     * 关闭订单（仅未支付）
     * POST /admin/kechengOrder/close {id}
     */
    public function close()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->kechengService->orderClose($aid, (int) $this->request->post('id', 0));

        return $this->success([], '关闭成功');
    }

    /**
     * 删除订单
     * POST /admin/kechengOrder/delete {id}
     */
    public function delete()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->kechengService->orderDelete($aid, (int) $this->request->post('id', 0));

        return $this->success([], '删除成功');
    }
}
