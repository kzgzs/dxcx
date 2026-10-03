<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\ScopeService;
use app\common\service\SignRewardService;

/**
 * 租户后台 - 积分签到 + 赠送奖励设置（支付后赠 / 确认收货奖励）
 */
class SignReward extends BaseController
{
    public function __construct(\think\App $app, protected SignRewardService $service)
    {
        parent::__construct($app);
    }

    /**
     * 签到设置（读取：每日积分/连续签到规则/开关/排名显示）
     * GET /admin/sign/set
     */
    public function signSet()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->service->getSignSet($aid));
    }

    /**
     * 签到设置（保存）
     * POST /admin/sign/set {score,lxqdset,lxzsset,status,paiming,...}
     */
    public function saveSignSet()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->service->saveSignSet($aid, $this->request->post());

        return $this->success([], '保存成功');
    }

    /**
     * 签到记录列表
     * GET /admin/sign/records?mid&nickname&start_time&end_time&page&pagesize
     */
    public function signRecords()
    {
        $aid = ScopeService::aidOf($this->request);

        $mid       = (int) $this->request->get('mid', 0);
        $nickname  = trim((string) $this->request->get('nickname', ''));
        $startTime = (int) $this->request->get('start_time', 0);
        $endTime   = (int) $this->request->get('end_time', 0);
        $page      = max(1, (int) $this->request->get('page', 1));
        $pageSize  = (int) $this->request->get('pagesize', 10);
        $pageSize  = $pageSize > 0 ? min($pageSize, 100) : 10;
        $field     = trim((string) $this->request->get('field', ''));
        $order     = strtolower((string) $this->request->get('order', 'desc'));

        return $this->success($this->service->signRecords($aid, $mid, $nickname, $startTime, $endTime, $page, $pageSize, $field, $order));
    }

    /**
     * 导出签到记录 CSV（与列表同过滤条件）
     * GET /admin/sign/recordExport?mid&nickname&start_time&end_time
     */
    public function signRecordExport()
    {
        $aid       = ScopeService::aidOf($this->request);
        $mid       = (int) $this->request->get('mid', 0);
        $nickname  = trim((string) $this->request->get('nickname', ''));
        $startTime = (int) $this->request->get('start_time', 0);
        $endTime   = (int) $this->request->get('end_time', 0);

        $csv = $this->service->exportSignRecords($aid, $mid, $nickname, $startTime, $endTime);

        return \think\Response::create($csv, 'html', 200)
            ->header([
                'Content-Type'        => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="sign_record_' . date('Ymd_His') . '.csv"',
            ]);
    }

    /**
     * 删除签到记录
     * POST /admin/sign/recordDelete {ids[]}
     */
    public function signRecordDelete()
    {
        $aid = ScopeService::aidOf($this->request);

        $ids = (array) $this->request->post('ids', []);
        if (empty($ids)) {
            throw new BusinessException('请选择记录');
        }

        $this->service->deleteSignRecords($aid, array_map('intval', $ids));

        return $this->success([], '删除成功');
    }

    /**
     * 支付后赠设置（读取）
     * GET /admin/reward/payaftergive
     */
    public function payaftergive()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->service->getPayaftergive($aid));
    }

    /**
     * 支付后赠设置（保存）
     * POST /admin/reward/payaftergive {money,score,give_coupon,...}
     */
    public function savePayaftergive()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->service->savePayaftergive($aid, $this->request->post());

        return $this->success([], '保存成功');
    }

    /**
     * 确认收货奖励设置（读取）
     * GET /admin/reward/collect
     */
    public function collectReward()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->service->getCollectReward($aid));
    }

    /**
     * 确认收货奖励设置（保存）
     * POST /admin/reward/collect {reward_type,...}
     */
    public function saveCollectReward()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->service->saveCollectReward($aid, $this->request->post());

        return $this->success([], '保存成功');
    }
}
