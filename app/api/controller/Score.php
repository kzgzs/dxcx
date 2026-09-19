<?php

declare(strict_types=1);

namespace app\api\controller;

use app\common\service\ScoreService;

/**
 * 客户端 - 积分（api 应用，JWT role=member，aid/mid 取自 token）
 */
class Score extends Base
{
    public function __construct(\think\App $app, protected ScoreService $scoreService)
    {
        parent::__construct($app);
    }

    /**
     * 我的积分流水
     * GET /api/score/log?page=&pagesize=
     *
     * type：1支付赠送 2下单抵扣 3取消返还 4后台调整
     */
    public function log()
    {
        [$aid, $mid] = $this->memberContext();

        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->scoreService->myLog($aid, $mid, $page, $pageSize));
    }
}
