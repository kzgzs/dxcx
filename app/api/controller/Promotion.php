<?php

declare(strict_types=1);

namespace app\api\controller;

use app\BaseController;
use app\common\service\PromotionService;

/**
 * 客户端 - 满减促销（api 应用）
 *
 * 匿名接口（AuthMiddleware 白名单放行），租户 aid 走 query 参数
 */
class Promotion extends BaseController
{
    public function __construct(\think\App $app, protected PromotionService $promotionService)
    {
        parent::__construct($app);
    }

    /**
     * 生效中的促销活动列表（匿名）
     * GET /api/promotion/list?aid=1
     */
    public function list()
    {
        return $this->success($this->promotionService->apiActiveList($this->aid()));
    }

    /**
     * 租户 ID：匿名接口取 query 参数（TenantMiddleware 已解析 X-Tenant-Id/aid）
     */
    protected function aid(): int
    {
        return (int) $this->request->aid;
    }
}
