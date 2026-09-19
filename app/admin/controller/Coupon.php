<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\CouponService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 优惠券管理（admin 应用，JWT role=admin，aid 走 ScopeService）
 */
class Coupon extends BaseController
{
    public function __construct(\think\App $app, protected CouponService $couponService)
    {
        parent::__construct($app);
    }

    /**
     * 优惠券列表（status 空=全部；keyword 匹配名称）
     * GET /admin/coupon/list?page&pagesize&keyword&status
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        $status   = trim((string) $this->request->get('status', ''));
        $keyword  = trim((string) $this->request->get('keyword', ''));
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->couponService->adminList($aid, $status, $keyword, $page, $pageSize));
    }

    /**
     * 新建优惠券
     * POST /admin/coupon/add {name,type,money,discount,full_money,total,per_limit,start_time,end_time}
     */
    public function add()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->couponService->add($aid, $this->request->post()), '创建成功');
    }

    /**
     * 编辑优惠券（received>0 禁止改核心面值字段）
     * POST /admin/coupon/edit {id,...}
     */
    public function edit()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->couponService->edit($aid, $this->request->post());

        return $this->success([], '保存成功');
    }

    /**
     * 启用/停用
     * POST /admin/coupon/status {id,status}
     */
    public function status()
    {
        $aid = ScopeService::aidOf($this->request);

        $id     = (int) $this->request->post('id', 0);
        $status = (int) $this->request->post('status', -1);

        $this->couponService->status($aid, $id, $status);

        return $this->success([], 1 === $status ? '已启用' : '已停用');
    }

    /**
     * 删除优惠券（软删；received>0 拒绝）
     * POST /admin/coupon/delete {id}
     */
    public function delete()
    {
        $aid = ScopeService::aidOf($this->request);

        $id = (int) $this->request->post('id', 0);

        if ($id <= 0) {
            throw new BusinessException('缺少优惠券ID');
        }

        $this->couponService->delete($aid, $id);

        return $this->success([], '删除成功');
    }

    /**
     * 领取/使用记录（status 空=全部 0未使用 1已使用 2已过期）
     * GET /admin/coupon/records?coupon_id=&status=&page=
     */
    public function records()
    {
        $aid = ScopeService::aidOf($this->request);

        $couponId = (int) $this->request->get('coupon_id', 0);
        $status   = trim((string) $this->request->get('status', ''));
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->couponService->adminRecords($aid, $couponId, $status, $page, $pageSize));
    }
}
