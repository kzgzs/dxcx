<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\MemberAdminService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 会员管理（admin 应用，JWT role=admin，aid 走 ScopeService）
 */
class Member extends BaseController
{
    public function __construct(\think\App $app, protected MemberAdminService $memberService)
    {
        parent::__construct($app);
    }

    /**
     * 会员列表
     * GET /admin/member/list?page&pagesize&keyword&levelid&start_time&end_time
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        $keyword   = trim((string) $this->request->get('keyword', ''));
        $levelId   = (int) $this->request->get('levelid', 0);
        $startTime = trim((string) $this->request->get('start_time', ''));
        $endTime   = trim((string) $this->request->get('end_time', ''));
        $page      = max(1, (int) $this->request->get('page', 1));
        $pageSize  = (int) $this->request->get('pagesize', 10);
        $pageSize  = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->memberService->list($aid, $keyword, $levelId, $startTime, $endTime, $page, $pageSize));
    }

    /**
     * 会员详情（基本信息+各计数）
     * GET /admin/member/detail?id=
     */
    public function detail()
    {
        $aid = ScopeService::aidOf($this->request);
        $mid = (int) $this->request->get('id', 0);

        return $this->success($this->memberService->detail($aid, $mid));
    }

    /**
     * 编辑会员（昵称/手机/等级/等级到期/备注；等级变更写变动记录）
     * POST /admin/member/edit {id, nickname, tel, levelid, levelendtime, remark}
     */
    public function edit()
    {
        $aid = ScopeService::aidOf($this->request);
        $mid = (int) $this->request->post('id', 0);

        $this->memberService->edit($aid, $mid, $this->request->post());

        return $this->success([], '保存成功');
    }

    /**
     * 余额充值/扣费（money 正=充值 负=扣款；事务校验扣款后余额不为负）
     * POST /admin/member/recharge {id, money, remark}
     */
    public function recharge()
    {
        $aid = ScopeService::aidOf($this->request);

        $mid    = (int) $this->request->post('id', 0);
        $money  = (float) $this->request->post('money', 0);
        $remark = trim((string) $this->request->post('remark', ''));

        $result = $this->memberService->recharge($aid, $mid, $money, $remark, (int) ($this->request->authUid ?? 0));

        return $this->success($result, $money > 0 ? '充值成功' : '扣费成功');
    }

    /**
     * 积分调整（score 正负整数；事务写流水）
     * POST /admin/member/addscore {id, score, remark}
     */
    public function addscore()
    {
        $aid = ScopeService::aidOf($this->request);

        $mid    = (int) $this->request->post('id', 0);
        $score  = (int) $this->request->post('score', 0);
        $remark = trim((string) $this->request->post('remark', ''));

        $result = $this->memberService->addScore($aid, $mid, $score, $remark, (int) ($this->request->authUid ?? 0));

        return $this->success($result, '调整成功');
    }

    /**
     * 冻结/解冻（切换 isfreeze）
     * POST /admin/member/freeze {id}
     */
    public function freeze()
    {
        $aid = ScopeService::aidOf($this->request);
        $mid = (int) $this->request->post('id', 0);

        return $this->success($this->memberService->freeze($aid, $mid), '操作成功');
    }

    /**
     * 锁定/解锁（切换 islock）
     * POST /admin/member/lock {id}
     */
    public function lock()
    {
        $aid = ScopeService::aidOf($this->request);
        $mid = (int) $this->request->post('id', 0);

        return $this->success($this->memberService->lock($aid, $mid), '操作成功');
    }

    /**
     * 余额明细（member_moneylog）
     * GET /admin/member/moneylog?mid=&page=&pagesize=
     */
    public function moneylog()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->memberService->moneyLog(
            $aid,
            (int) $this->request->get('mid', 0),
            max(1, (int) $this->request->get('page', 1)),
            $this->pageSizeOf()
        ));
    }

    /**
     * 积分明细（member_scorelog）
     * GET /admin/member/scorelog?mid=&page=&pagesize=
     */
    public function scorelog()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->memberService->scoreLog(
            $aid,
            (int) $this->request->get('mid', 0),
            max(1, (int) $this->request->get('page', 1)),
            $this->pageSizeOf()
        ));
    }

    /**
     * 会员订单
     * GET /admin/member/orders?mid=&page=&pagesize=
     */
    public function orders()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->memberService->orders(
            $aid,
            (int) $this->request->get('mid', 0),
            max(1, (int) $this->request->get('page', 1)),
            $this->pageSizeOf()
        ));
    }

    /**
     * 收货地址
     * GET /admin/member/address?mid=&page=&pagesize=
     */
    public function address()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->memberService->address(
            $aid,
            (int) $this->request->get('mid', 0),
            max(1, (int) $this->request->get('page', 1)),
            $this->pageSizeOf()
        ));
    }

    /**
     * 等级列表
     * GET /admin/member/level/list
     */
    public function levelList()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->memberService->levelList($aid));
    }

    /**
     * 等级详情（编辑回读全字段：升级条件/分销/分红/赠送，P19-2）
     * GET /admin/member/level/detail?id=
     */
    public function levelDetail()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->memberService->levelDetail($aid, (int) $this->request->get('id', 0)));
    }

    /**
     * 等级新增/编辑
     * POST /admin/member/level/save
     */
    public function levelSave()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->memberService->levelSave($aid, $this->request->post());

        return $this->success([], '保存成功');
    }

    /**
     * 区域代理归属记录列表（member_level_record 按等级）
     * GET /admin/member/level/record/list?levelid=
     */
    public function levelRecordList()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->memberService->levelRecordList($aid, (int) $this->request->get('levelid', 0)));
    }

    /**
     * 区域代理归属保存（省市区 + 开关/比例）
     * POST /admin/member/level/record/save {id, areafenhong, areafenhongbl, areafenhong_province, areafenhong_city, areafenhong_area}
     */
    public function levelRecordSave()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->memberService->levelRecordSave($aid, $this->request->post());

        return $this->success([], '保存成功');
    }

    /**
     * 等级删除（有会员占用时拒绝）
     * POST /admin/member/level/delete {id}
     */
    public function levelDelete()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->memberService->levelDelete($aid, (int) $this->request->post('id', 0));

        return $this->success([], '删除成功');
    }

    /**
     * 分页大小（默认 10，上限 100）
     */
    protected function pageSizeOf(): int
    {
        $pageSize = (int) $this->request->get('pagesize', 10);

        return $pageSize > 0 ? min($pageSize, 100) : 10;
    }
}
