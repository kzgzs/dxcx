<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\ScoreService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 积分管理（admin 应用，JWT role=admin，aid 走 ScopeService）
 */
class Score extends BaseController
{
    public function __construct(\think\App $app, protected ScoreService $scoreService)
    {
        parent::__construct($app);
    }

    /**
     * 积分配置
     * GET /admin/score/config → {score_switch, score_ratio, pay_score}
     */
    public function config()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->scoreService->config($aid));
    }

    /**
     * 保存积分配置
     * POST /admin/score/config {score_switch, score_ratio, pay_score}
     */
    public function saveConfig()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->scoreService->saveConfig($aid, $this->request->post());

        return $this->success([], '保存成功');
    }

    /**
     * 会员积分列表（keyword 模糊匹配手机号）
     * GET /admin/score/memberlist?page&pagesize&keyword
     */
    public function memberlist()
    {
        $aid = ScopeService::aidOf($this->request);

        $keyword  = trim((string) $this->request->get('keyword', ''));
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->scoreService->memberList($aid, $keyword, $page, $pageSize));
    }

    /**
     * 调整会员积分（整数可负；余额不足扣负拒绝；流水 type=4）
     * POST /admin/score/adjust {mid, score, remark}
     */
    public function adjust()
    {
        $aid = ScopeService::aidOf($this->request);

        $mid    = (int) $this->request->post('mid', 0);
        $score  = (int) $this->request->post('score', 0);
        $remark = trim((string) $this->request->post('remark', ''));

        if ($mid <= 0) {
            throw new BusinessException('缺少会员ID');
        }

        $this->scoreService->adjust($aid, $mid, $score, $remark);

        return $this->success([], '调整成功');
    }

    /**
     * 积分流水（可选 mid 过滤）
     * GET /admin/score/log?mid=&page=&pagesize=
     */
    public function log()
    {
        $aid = ScopeService::aidOf($this->request);

        $mid      = (int) $this->request->get('mid', 0);
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->scoreService->adminLog($aid, $mid, $page, $pageSize));
    }
}
