<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\CashbackService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 购物返现管理（批次4-A 任务186）
 *
 * 规则 CRUD（back_ratio 比率 / back_type 1余额2佣金3积分 / receiver_type / goods_multiple_max 限额倍数）
 * + 参与记录页（cashback_member 台账 / cashback_member_log 流水）
 * 触发：确认收货（OrderService confirm 挂 dealCashback）
 */
class Cashback extends BaseController
{
    public function __construct(\think\App $app, protected CashbackService $cashbackService)
    {
        parent::__construct($app);
    }

    /**
     * 规则列表（keyword 名称模糊；status 空=全部）
     * GET /admin/cashback/list?page&pagesize&keyword&status
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        $keyword  = trim((string) $this->request->get('keyword', ''));
        $status   = trim((string) $this->request->get('status', ''));
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->cashbackService->adminList($aid, $keyword, $status, $page, $pageSize));
    }

    /**
     * 规则详情
     * GET /admin/cashback/detail?id=
     */
    public function detail()
    {
        $aid = ScopeService::aidOf($this->request);

        $id = (int) $this->request->get('id', 0);

        if ($id <= 0) {
            throw new BusinessException('缺少规则ID');
        }

        return $this->success($this->cashbackService->detail($aid, $id));
    }

    /**
     * 新建规则
     * POST /admin/cashback/add {name,back_ratio,back_type,receiver_type,goods_multiple_max,gettj,fwtype,categoryids,productids,minprice,starttime,endtime,sort}
     */
    public function add()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->cashbackService->add($aid, $this->request->post()), '创建成功');
    }

    /**
     * 编辑规则
     * POST /admin/cashback/edit {id,...}
     */
    public function edit()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->cashbackService->edit($aid, $this->request->post());

        return $this->success([], '保存成功');
    }

    /**
     * 启用/停用
     * POST /admin/cashback/status {id,status}
     */
    public function status()
    {
        $aid = ScopeService::aidOf($this->request);

        $id     = (int) $this->request->post('id', 0);
        $status = (int) $this->request->post('status', -1);

        $this->cashbackService->status($aid, $id, $status);

        return $this->success([], 1 === $status ? '已启用' : '已停用');
    }

    /**
     * 删除规则（已有参与台账时拒绝）
     * POST /admin/cashback/delete {id}
     */
    public function delete()
    {
        $aid = ScopeService::aidOf($this->request);

        $id = (int) $this->request->post('id', 0);

        if ($id <= 0) {
            throw new BusinessException('缺少规则ID');
        }

        $this->cashbackService->delete($aid, $id);

        return $this->success([], '删除成功');
    }

    /**
     * 参与记录-会员台账列表（cashback_member）
     * GET /admin/cashback/memberList?cashback_id=&page=&pagesize=
     */
    public function memberList()
    {
        $aid = ScopeService::aidOf($this->request);

        $cashbackId = (int) $this->request->get('cashback_id', 0);
        $page       = max(1, (int) $this->request->get('page', 1));
        $pageSize   = (int) $this->request->get('pagesize', 10);
        $pageSize   = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->cashbackService->memberList($aid, $cashbackId, $page, $pageSize));
    }

    /**
     * 参与记录-返现流水列表（cashback_member_log）
     * GET /admin/cashback/memberLogList?cashback_id=&page=&pagesize=
     */
    public function memberLogList()
    {
        $aid = ScopeService::aidOf($this->request);

        $cashbackId = (int) $this->request->get('cashback_id', 0);
        $page       = max(1, (int) $this->request->get('page', 1));
        $pageSize   = (int) $this->request->get('pagesize', 10);
        $pageSize   = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->cashbackService->memberLogList($aid, $cashbackId, $page, $pageSize));
    }
}
