<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\HotelService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 酒店管理（admin 应用，JWT role=admin，aid 走 ScopeService）
 *
 * 酒店管理 / 房型管理 / 酒店订单
 */
class Hotel extends BaseController
{
    public function __construct(\think\App $app, protected HotelService $hotelService)
    {
        parent::__construct($app);
    }

    /* ================= 酒店管理 ================= */

    /**
     * 酒店列表（keyword/status 筛选）
     * GET /admin/hotel/list?page&pagesize&keyword&status
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        $keyword  = trim((string) $this->request->get('keyword', ''));
        $status   = trim((string) $this->request->get('status', ''));
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->hotelService->hotelList($aid, $keyword, $status, $page, $pageSize));
    }

    /**
     * 新建酒店
     * POST /admin/hotel/add {name,star,province,city,district,address,lng,lat,pic,contacts,phone,desc,status}
     */
    public function add()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->hotelService->hotelAdd($aid, $this->request->post()), '创建成功');
    }

    /**
     * 编辑酒店
     * POST /admin/hotel/edit {id,...}
     */
    public function edit()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->hotelService->hotelEdit($aid, $this->request->post());

        return $this->success([], '保存成功');
    }

    /**
     * 删除酒店（软删；有房型拒绝）
     * POST /admin/hotel/delete {id}
     */
    public function delete()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->hotelService->hotelDelete($aid, (int) $this->request->post('id', 0));

        return $this->success([], '删除成功');
    }

    /**
     * 营业/停业
     * POST /admin/hotel/status {id,status}
     */
    public function status()
    {
        $aid = ScopeService::aidOf($this->request);

        $id     = (int) $this->request->post('id', 0);
        $status = (int) $this->request->post('status', -1);

        $this->hotelService->hotelStatus($aid, $id, $status);

        return $this->success([], 1 === $status ? '已营业' : '已停业');
    }

    /* ================= 房型管理 ================= */

    /**
     * 房型列表（按酒店）
     * GET /admin/hotel/room/list?hotel_id=&page&pagesize
     */
    public function roomList()
    {
        $aid = ScopeService::aidOf($this->request);

        $hotelId  = (int) $this->request->get('hotel_id', 0);
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->hotelService->roomList($aid, $hotelId, $page, $pageSize));
    }

    /**
     * 新建房型
     * POST /admin/hotel/room/add {hotel_id,name,bed_type,area,price,deposit,room_num,pic,desc,status}
     */
    public function roomAdd()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->hotelService->roomAdd($aid, $this->request->post()), '创建成功');
    }

    /**
     * 编辑房型
     * POST /admin/hotel/room/edit {id,...}
     */
    public function roomEdit()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->hotelService->roomEdit($aid, $this->request->post());

        return $this->success([], '保存成功');
    }

    /**
     * 删除房型（软删；有未完结订单拒绝）
     * POST /admin/hotel/room/delete {id}
     */
    public function roomDelete()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->hotelService->roomDelete($aid, (int) $this->request->post('id', 0));

        return $this->success([], '删除成功');
    }

    /**
     * 上架/下架
     * POST /admin/hotel/room/status {id,status}
     */
    public function roomStatus()
    {
        $aid = ScopeService::aidOf($this->request);

        $id     = (int) $this->request->post('id', 0);
        $status = (int) $this->request->post('status', -1);

        $this->hotelService->roomStatus($aid, $id, $status);

        return $this->success([], 1 === $status ? '已上架' : '已下架');
    }

    /* ================= 酒店订单 ================= */

    /**
     * 订单列表（keyword 匹配订单号/入住人/手机号）
     * GET /admin/hotel/order/list?page&pagesize&status&keyword
     */
    public function orderList()
    {
        $aid = ScopeService::aidOf($this->request);

        $status   = trim((string) $this->request->get('status', ''));
        $keyword  = trim((string) $this->request->get('keyword', ''));
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->hotelService->adminOrderList($aid, $status, $keyword, $page, $pageSize));
    }

    /**
     * 办理入住（已预订 → 已入住）
     * POST /admin/hotel/order/checkin {id}
     */
    public function orderCheckin()
    {
        $aid = ScopeService::aidOf($this->request);

        $id = (int) $this->request->post('id', 0);

        if ($id <= 0) {
            throw new BusinessException('缺少订单ID');
        }

        $this->hotelService->adminOrderCheckin($aid, $id);

        return $this->success([], '已办理入住');
    }

    /**
     * 完成订单（已预订/已入住 → 已完成）
     * POST /admin/hotel/order/finish {id}
     */
    public function orderFinish()
    {
        $aid = ScopeService::aidOf($this->request);

        $id = (int) $this->request->post('id', 0);

        if ($id <= 0) {
            throw new BusinessException('缺少订单ID');
        }

        $this->hotelService->adminOrderFinish($aid, $id);

        return $this->success([], '订单已完成');
    }
}
