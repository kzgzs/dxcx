<?php

declare(strict_types=1);

namespace app\api\controller;

use app\common\service\SignRewardService;

/**
 * 客户端 - 签到（api 应用，JWT role=member，aid/mid 取自 token）
 */
class Sign extends Base
{
    public function __construct(\think\App $app, protected SignRewardService $signRewardService)
    {
        parent::__construct($app);
    }

    /**
     * 签到动作（防当日重复 + 连签奖励 + 送券，事务内完成）
     * POST /api/sign/signin
     */
    public function signin()
    {
        [$aid, $mid] = $this->memberContext();

        return $this->success($this->signRewardService->sign($aid, $mid), '签到成功');
    }

    /**
     * 签到页数据（签到设置 + 今日是否已签 + 日历记录 + 连签排行）
     * GET /api/sign/page
     */
    public function page()
    {
        [$aid, $mid] = $this->memberContext();

        return $this->success($this->signRewardService->signPage($aid, $mid));
    }

    /**
     * 我的签到记录
     * GET /api/sign/records?page=&pagesize=
     */
    public function records()
    {
        [$aid, $mid] = $this->memberContext();

        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->signRewardService->apiRecords($aid, $mid, $page, $pageSize));
    }
}
