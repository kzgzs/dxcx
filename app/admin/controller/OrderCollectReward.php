<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\CollectRewardService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 确认收货奖励记录（批次4-E 任务190 E2-5）
 *
 * 设置读写已由 SignReward 控制器承担（reward/collect），此处仅补奖励记录页
 */
class OrderCollectReward extends BaseController
{
    public function __construct(\think\App $app, protected CollectRewardService $service)
    {
        parent::__construct($app);
    }

    /**
     * 奖励记录列表
     * GET /admin/orderCollectReward/records?ordertype=&mid=&page=&pagesize=
     */
    public function records()
    {
        $aid = ScopeService::aidOf($this->request);

        $ordertype = trim((string) $this->request->get('ordertype', ''));
        $mid       = (int) $this->request->get('mid', 0);
        $page      = max(1, (int) $this->request->get('page', 1));
        $pageSize  = (int) $this->request->get('pagesize', 10);
        $pageSize  = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->service->recordList($aid, $ordertype, $mid, $page, $pageSize));
    }
}
