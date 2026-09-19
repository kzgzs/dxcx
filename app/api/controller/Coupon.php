<?php

declare(strict_types=1);

namespace app\api\controller;

use app\common\service\CouponService;

/**
 * 客户端 - 优惠券（api 应用，JWT role=member，aid/mid 取自 token）
 */
class Coupon extends Base
{
    public function __construct(\think\App $app, protected CouponService $couponService)
    {
        parent::__construct($app);
    }

    /**
     * 可领取券列表（启用、有效期内、有余量、未达本人限领）
     * GET /api/coupon/list
     */
    public function list()
    {
        [$aid, $mid] = $this->memberContext();

        return $this->success($this->couponService->apiList($aid, $mid));
    }

    /**
     * 优惠券详情
     */
    public function detail()
    {
        [$aid, $mid] = $this->memberContext();
        $couponId = (int) $this->request->get('id', 0);
        return $this->success($this->couponService->detail($aid, $mid, $couponId));
    }

    /**
     * 领取优惠券（并发安全：行锁 + 限领校验 + 条件更新防超发）
     * POST /api/coupon/receive {coupon_id}
     */
    public function receive()
    {
        [$aid, $mid] = $this->memberContext();

        $couponId = (int) $this->request->post('coupon_id', 0);

        return $this->success($this->couponService->receive($aid, $mid, $couponId), '领取成功');
    }

    /**
     * 我的优惠券（status 空=全部 0未使用 1已使用 2已过期，过期按 end_time 实时计算）
     * GET /api/coupon/mine?status=&page=&pagesize=
     */
    public function mine()
    {
        [$aid, $mid] = $this->memberContext();

        $status   = trim((string) $this->request->get('status', ''));
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->couponService->mine($aid, $mid, $status, $page, $pageSize));
    }
}
