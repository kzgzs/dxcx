<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\GiveService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 注册赠送设置（批次4-A 任务186）
 *
 * 配置 register_giveset 表（按 aid upsert）：
 * - 注册送余额 money / 送积分 score / 完善资料送积分 wanshan_score
 * - 赠送券开关 give_coupon + 券ID多选 coupon_ids
 * - 活动时间段 starttime/endtime（datetime）+ 总开关 status
 */
class RegisterGive extends BaseController
{
    public function __construct(\think\App $app, protected GiveService $giveService)
    {
        parent::__construct($app);
    }

    /**
     * 读取注册赠送配置
     * GET /admin/registerGive/config
     */
    public function config()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->giveService->config($aid));
    }

    /**
     * 保存注册赠送配置
     * POST /admin/registerGive/save {status,money,score,wanshan_score,give_coupon,coupon_ids,starttime,endtime}
     */
    public function save()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->giveService->saveConfig($aid, $this->request->post());

        return $this->success([], '保存成功');
    }
}
