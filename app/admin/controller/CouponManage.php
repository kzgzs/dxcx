<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\CouponManageService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 优惠券管理（列表/编辑/发放/记录/核销/过期提醒设置）
 *
 * 说明：券类型沿用参照口径（1代金/2礼品/3计次/4运费抵/10折扣/11兑换），
 * 有效期支持定范围（varchar 'Y-m-d H:i:s'）与领取后 N 天两种模式。
 */
class CouponManage extends BaseController
{
    public function __construct(\think\App $app, protected CouponManageService $service)
    {
        parent::__construct($app);
    }

    /**
     * 优惠券列表（类型名/money/discount/minprice/库存/已领/有效期/状态）
     * GET /admin/couponManage/list?type&status&keyword&page&pagesize
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        $type     = trim((string) $this->request->get('type', ''));
        $status   = trim((string) $this->request->get('status', ''));
        $keyword  = trim((string) $this->request->get('keyword', ''));
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->service->manageList($aid, $type, $status, $keyword, $page, $pageSize));
    }

    /**
     * 优惠券详情（编辑回显）
     * GET /admin/couponManage/detail?id
     */
    public function detail()
    {
        $aid = ScopeService::aidOf($this->request);
        $id  = (int) $this->request->get('id', 0);

        if ($id <= 0) {
            throw new BusinessException('缺少优惠券ID');
        }

        return $this->success($this->service->detail($aid, $id));
    }

    /**
     * 新增/编辑优惠券
     * POST /admin/couponManage/save {type,money,discount,minprice,stock,getnum,perlimit,
     *   yxqtype,yxqdate,yxqtime,starttime,endtime,gettj,isgive,tolist,fwtype,categoryids,
     *   productids,price,score,...}
     */
    public function save()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->service->save($aid, $this->request->post()), '保存成功');
    }

    /**
     * 删除优惠券（已有领取记录时拒绝）
     * POST /admin/couponManage/delete {id}
     */
    public function delete()
    {
        $aid = ScopeService::aidOf($this->request);
        $id  = (int) $this->request->post('id', 0);

        if ($id <= 0) {
            throw new BusinessException('缺少优惠券ID');
        }

        $this->service->delete($aid, $id);

        return $this->success([], '删除成功');
    }

    /**
     * 启用/停用
     * POST /admin/couponManage/status {id,status}
     */
    public function status()
    {
        $aid = ScopeService::aidOf($this->request);

        $id     = (int) $this->request->post('id', 0);
        $status = (int) $this->request->post('status', -1);

        $this->service->status($aid, $id, $status);

        return $this->success([], 1 === $status ? '已启用' : '已停用');
    }

    /**
     * 复制优惠券（名称加前缀、计数清零、默认停用）
     * POST /admin/couponManage/copy {id}
     */
    public function copy()
    {
        $aid = ScopeService::aidOf($this->request);
        $id  = (int) $this->request->post('id', 0);

        if ($id <= 0) {
            throw new BusinessException('缺少优惠券ID');
        }

        return $this->success($this->service->copy($aid, $id), '复制成功');
    }

    /**
     * 领取记录（过期为查询时实时计算，不写库）
     * GET /admin/couponManage/records?coupon_id&mid&status&keyword&start_time&end_time&page&pagesize
     */
    public function records()
    {
        $aid = ScopeService::aidOf($this->request);

        $couponId  = (int) $this->request->get('coupon_id', 0);
        $mid       = (int) $this->request->get('mid', 0);
        $status    = trim((string) $this->request->get('status', ''));
        $keyword   = trim((string) $this->request->get('keyword', ''));
        $startTime = (int) $this->request->get('start_time', 0);
        $endTime   = (int) $this->request->get('end_time', 0);
        $page      = max(1, (int) $this->request->get('page', 1));
        $pageSize  = (int) $this->request->get('pagesize', 10);
        $pageSize  = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->service->records($aid, $couponId, $mid, $status, $keyword, $startTime, $endTime, $page, $pageSize));
    }

    /**
     * 批量发放（事务 + 行锁 + 条件更新防超发）
     * POST /admin/couponManage/send {coupon_id,send_type,mids[],condition{},per_num}
     */
    public function send()
    {
        $aid = ScopeService::aidOf($this->request);

        $couponId = (int) $this->request->post('coupon_id', 0);
        $sendType = (int) $this->request->post('send_type', 0);
        $mids     = (array) $this->request->post('mids', []);
        $condition = (array) $this->request->post('condition', []);
        $perNum   = (int) $this->request->post('per_num', 1);

        if ($couponId <= 0) {
            throw new BusinessException('缺少优惠券ID');
        }

        return $this->success($this->service->send($aid, $couponId, $sendType, $mids, $condition, $perNum), '发放完成');
    }

    /**
     * 手工核销记录（计次券禁止手工核销）
     * POST /admin/couponManage/recordStatus {ids[],st}
     */
    public function recordStatus()
    {
        $aid = ScopeService::aidOf($this->request);

        $ids = (array) $this->request->post('ids', []);
        $st  = (int) $this->request->post('st', 1);

        if (empty($ids)) {
            throw new BusinessException('请选择记录');
        }

        $this->service->recordStatus($aid, array_map('intval', $ids), $st);

        return $this->success([], '操作成功');
    }

    /**
     * 过期提醒设置（读取）
     * GET /admin/couponManage/set
     */
    public function set()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->service->getSet($aid));
    }

    /**
     * 过期提醒设置（保存）
     * POST /admin/couponManage/set {expire_status,expire_rules[]}
     */
    public function saveSet()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->service->saveSet($aid, $this->request->post());

        return $this->success([], '保存成功');
    }
}
