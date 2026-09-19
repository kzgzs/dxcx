<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\PrizePoolService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 奖金池（admin 应用，JWT role=admin，aid 走 ScopeService；P19-6 任务184）
 *
 * 三池：
 * - 利润奖金池（prize_pool_*）：配置/手动发放
 * - 区域奖金池（areareach_* type=0）：CRUD + 入池日志
 * - 达标奖金池（areareach_* type=1）：CRUD + 入池日志
 * - 发放记录：三池发放日志合并视图（pool_type 过滤）
 */
class PrizePool extends BaseController
{
    public function __construct(\think\App $app, protected PrizePoolService $prizePoolService)
    {
        parent::__construct($app);
    }

    /* ================= 1. 利润奖金池 ================= */

    /**
     * 利润池配置读取
     * GET /admin/prizepool/profit/config
     */
    public function profitConfig()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->prizePoolService->profitConfig($aid));
    }

    /**
     * 利润池配置保存
     * POST /admin/prizepool/profit/config
     */
    public function saveProfitConfig()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->prizePoolService->saveProfitConfig($aid, [
            'status'    => $this->request->post('status', 0),
            'pool_time' => $this->request->post('pool_time', 1),
            'send_bili' => $this->request->post('send_bili', 0),
            'levelids'  => $this->request->post('levelids', ''),
            'send_time' => $this->request->post('send_time', 0),
            'show_pool' => $this->request->post('show_pool', 0),
        ]);

        return $this->success([], '保存成功');
    }

    /**
     * 利润池手动发放（send_bili×池金额，按等级名单平分入账）
     * POST /admin/prizepool/profit/send
     */
    public function sendProfitNow()
    {
        $aid = ScopeService::aidOf($this->request);

        $result = $this->prizePoolService->sendProfitPool($aid, 0);

        if (1 !== (int) ($result['status'] ?? 0)) {
            return $this->error((string) ($result['msg'] ?? '发放失败'));
        }

        return $this->success(['sent' => (int) ($result['sent'] ?? 0)], '发放成功');
    }

    /**
     * 利润池流水（入池/发放明细）
     * GET /admin/prizepool/profit/log
     */
    public function profitLog()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->prizePoolService->profitLogList($aid, [
            'page'     => $this->request->get('page', 1),
            'pagesize' => $this->request->get('pagesize', 10),
        ]));
    }

    /* ================= 2. 区域/达标奖金池 ================= */

    /**
     * 池配置列表（type 0区域 1达标）
     * GET /admin/prizepool/pool/list?type=0
     */
    public function poolList()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->prizePoolService->poolList(
            $aid,
            (int) $this->request->get('type', 0),
            (int) $this->request->get('page', 1),
            (int) $this->request->get('pagesize', 20)
        ));
    }

    /**
     * 池配置保存（id>0 更新，否则新增）
     * POST /admin/prizepool/pool/save
     */
    public function savePool()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->prizePoolService->savePool($aid, [
            'id'            => $this->request->post('id', 0),
            'type'          => $this->request->post('type', 0),
            'name'          => $this->request->post('name', ''),
            'status'        => $this->request->post('status', 1),
            'province_name' => $this->request->post('province_name', ''),
            'mids'          => $this->request->post('mids', []),
            'send_type'     => $this->request->post('send_type', 0),
            'gettj'         => $this->request->post('gettj', '-1'),
            'reach_money'   => $this->request->post('reach_money', 0),
            'remark'        => $this->request->post('remark', ''),
        ]);

        return $this->success([], '保存成功');
    }

    /**
     * 池配置删除（有余额不允许删）
     * POST /admin/prizepool/pool/delete {id}
     */
    public function deletePool()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->prizePoolService->deletePool($aid, (int) $this->request->post('id', 0));

        return $this->success([], '删除成功');
    }

    /**
     * 入池订单日志（type 0区域 1达标；pool_id 可选）
     * GET /admin/prizepool/pool/orderlog
     */
    public function orderLog()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->prizePoolService->orderLogList($aid, [
            'type'     => $this->request->get('type', -1),
            'pool_id'  => $this->request->get('pool_id', 0),
            'page'     => $this->request->get('page', 1),
            'pagesize' => $this->request->get('pagesize', 10),
        ]));
    }

    /* ================= 3. 发放记录（三池合并） ================= */

    /**
     * 发放记录（pool_type profit/area/reach）
     * GET /admin/prizepool/sendlog/list
     */
    public function sendLogList()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->prizePoolService->sendLogList($aid, [
            'pool_type' => $this->request->get('pool_type', 'profit'),
            'page'      => $this->request->get('page', 1),
            'pagesize'  => $this->request->get('pagesize', 10),
        ]));
    }

    /* ================= 4. 基础数据 ================= */

    /**
     * 省份列表（区域池配置用）
     * GET /admin/prizepool/provinces
     */
    public function provinces()
    {
        $list = [];

        foreach (PrizePoolService::PROVINCES as $id => $name) {
            $list[] = ['id' => $id, 'name' => $name];
        }

        return $this->success(['list' => $list]);
    }
}
