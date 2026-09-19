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
     * GET /admin/kechengOrder/list?page&pagesize&keyword&status
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        $keyword  = trim((string) $this->request->get('keyword', ''));
        $status   = trim((string) $this->request->get('status', ''));
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->kechengService->orderList($aid, $keyword, $status, $page, $pageSize));
    }
}
