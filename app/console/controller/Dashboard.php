<?php

declare(strict_types=1);

namespace app\console\controller;

use app\BaseController;
use app\common\service\TenantService;

/**
 * 超管控制台 - 仪表盘
 */
class Dashboard extends BaseController
{
    public function __construct(\think\App $app, protected TenantService $tenantService)
    {
        parent::__construct($app);
    }

    /**
     * 仪表盘统计（跨租户聚合）
     * GET /console/dashboard
     */
    public function index()
    {
        return $this->success($this->tenantService->dashboard());
    }
}
