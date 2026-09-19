<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\RechargeService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 充值赠送设置（批次4-A 任务186）
 *
 * 配置 recharge_giveset 表（按 aid upsert）：
 * - 自定义金额开关 caninput
 * - 档位 givedata JSON 数组 [{money, give, give_score}]（动态行，金额唯一）
 */
class MoneyGiveset extends BaseController
{
    public function __construct(\think\App $app, protected RechargeService $rechargeService)
    {
        parent::__construct($app);
    }

    /**
     * 读取充值赠送配置
     * GET /admin/moneyGiveset/config
     */
    public function config()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->rechargeService->config($aid));
    }

    /**
     * 保存充值赠送配置
     * POST /admin/moneyGiveset/save {status,caninput,shuoming,givedata:[{money,give,give_score}]}
     */
    public function save()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->rechargeService->saveConfig($aid, $this->request->post());

        return $this->success([], '保存成功');
    }
}
