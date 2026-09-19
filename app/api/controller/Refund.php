<?php

declare(strict_types=1);

namespace app\api\controller;

use app\common\service\RefundService;

/**
 * 客户端 - 我的退款单（api 应用，JWT role=member）
 */
class Refund extends Base
{
    public function __construct(\think\App $app, protected RefundService $refundService)
    {
        parent::__construct($app);
    }

    /**
     * 我的退款单列表
     * GET /api/refund/list?page=&pagesize=
     */
    public function list()
    {
        [$aid, $mid] = $this->memberContext();

        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->refundService->apiList($aid, $mid, $page, $pageSize));
    }
}
