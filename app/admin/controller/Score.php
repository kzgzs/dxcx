<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\ScoreService;
use app\common\service\ScopeService;
use util\ExcelUtil;

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

        return $this->success($this->scoreService->adminScoreLogList($aid, [
            'mid'        => $mid,
            'nickname'   => '',
            'remark'     => '',
            'start_date' => '',
            'end_date'   => '',
            'page'       => $page,
            'pagesize'   => $pageSize,
        ]));
    }

    /**
     * 积分明细（后台完整列表：搜索 mid/nickname/时间/remark；对齐点大 score/scorelog.html）
     * GET /admin/score/logList?mid=&nickname=&remark=&start_date=&end_date=&page=&pagesize=
     */
    public function logList()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->scoreService->adminScoreLogList($aid, [
            'mid'        => $this->request->get('mid', 0),
            'nickname'   => $this->request->get('nickname', ''),
            'remark'     => $this->request->get('remark', ''),
            'start_date' => $this->request->get('start_date', ''),
            'end_date'   => $this->request->get('end_date', ''),
            'page'       => $this->request->get('page', 1),
            'pagesize'   => $this->request->get('pagesize', 10),
        ]));
    }

    /**
     * 积分明细批量删除（入参兼容单 id 与 ids[]）
     * POST /admin/score/logDelete {ids[] | id}
     */
    public function logDelete()
    {
        $aid = ScopeService::aidOf($this->request);
        $ids = (array) $this->request->post('ids', []);
        if (empty($ids)) {
            $single = (int) $this->request->post('id', 0);
            if ($single > 0) {
                $ids = [$single];
            }
        }
        $n = $this->scoreService->deleteScoreLog($aid, $ids);
        return $this->success(['deleted' => $n], '删除成功');
    }

    /**
     * 积分撤销（对齐点大 Score::cancel；未撤销才处理，反向冲正）
     * POST /admin/score/cancel {ids[] | id}
     */
    public function cancel()
    {
        $aid = ScopeService::aidOf($this->request);
        $ids = (array) $this->request->post('ids', []);
        if (empty($ids)) {
            $single = (int) $this->request->post('id', 0);
            if ($single > 0) {
                $ids = [$single];
            }
        }
        $n = $this->scoreService->cancelScoreLog($aid, $ids);
        return $this->success(['canceled' => $n], '撤销成功');
    }

    /**
     * 积分明细导出
     * GET /admin/score/logExport
     */
    public function logExport()
    {
        $aid = ScopeService::aidOf($this->request);
        set_time_limit(0);
        ini_set('memory_limit', '1024M');
        [$titles, $rows] = $this->scoreService->scoreLogExport($aid, [
            'mid' => $this->request->get('mid', 0), 'nickname' => $this->request->get('nickname', ''),
            'remark' => $this->request->get('remark', ''),
            'start_date' => $this->request->get('start_date', ''), 'end_date' => $this->request->get('end_date', ''),
        ]);
        ExcelUtil::export($titles, $rows, '积分明细_' . date('YmdHis'));
    }
}

