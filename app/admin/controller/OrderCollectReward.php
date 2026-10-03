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
     * GET /admin/orderCollectReward/records?ordertype=&reward_type=&ordernum=&keyword=&mid=&ordertime_start=&ordertime_end=&page=&pagesize=
     */
    public function records()
    {
        $aid = ScopeService::aidOf($this->request);

        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        $filter = [
            'ordertype'       => trim((string) $this->request->get('ordertype', '')),
            'reward_type'     => (int) $this->request->get('reward_type', 0),
            'ordernum'        => trim((string) $this->request->get('ordernum', '')),
            'keyword'         => trim((string) $this->request->get('keyword', '')),
            'mid'             => (int) $this->request->get('mid', 0),
            'ordertime_start' => (int) $this->request->get('ordertime_start', 0),
            'ordertime_end'   => (int) $this->request->get('ordertime_end', 0),
        ];

        return $this->success($this->service->recordList($aid, $filter, $page, $pageSize));
    }

    /**
     * 删除奖励记录（兼容 id 单删 / ids[] 批量删）
     * POST /admin/orderCollectReward/del {id|ids[]}
     */
    public function del()
    {
        $aid = ScopeService::aidOf($this->request);

        $ids = $this->request->post('ids', []);

        if (!is_array($ids)) {
            $ids = $ids ? [(int) $ids] : [];
        }

        $singleId = (int) $this->request->post('id', 0);
        if ($singleId > 0) {
            $ids[] = $singleId;
        }

        $this->service->deleteRecords($aid, array_map('intval', $ids));

        return $this->success([], '删除成功');
    }
}
