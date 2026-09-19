<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\ScopeService;
use app\common\service\TakeFoodService;

/**
 * 租户后台 - 餐饮取餐（批次6-T6-4）
 *
 * 状态口径：0制作中 1待取餐 2已取餐 -1过期（24小时未取由 schedule:run 置过期）
 * 流转：支付成功自动生成取餐号（TakeFoodService::generate）→ 出餐(0→1) → 叫号(置叫号时间/文案) → 完成取餐(→2)
 */
class RestaurantTakeFood extends BaseController
{
    public function __construct(\think\App $app, protected TakeFoodService $takeFoodService)
    {
        parent::__construct($app);
    }

    /**
     * 取餐列表（状态筛选：''全部 0制作中 1待取餐 2已取餐 -1过期；支持取餐号/订单号检索）
     * GET /admin/restaurant/takefood/list
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        $status       = trim((string) $this->request->get('status', ''));
        $pickupNumber = trim((string) $this->request->get('pickup_number', ''));
        $ordernum     = trim((string) $this->request->get('ordernum', ''));
        $page         = max(1, (int) $this->request->get('page', 1));
        $pageSize     = (int) $this->request->get('pagesize', 10);
        $pageSize     = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->takeFoodService->adminList($aid, $status, $pickupNumber, $ordernum, $page, $pageSize));
    }

    /**
     * 出餐（制作中 → 待取餐）
     * POST /admin/restaurant/takefood/out
     */
    public function out()
    {
        $aid = ScopeService::aidOf($this->request);
        $id  = (int) $this->request->post('id', 0);

        $this->takeFoodService->serve($aid, $id);

        return $this->success([], '已出餐');
    }

    /**
     * 叫号（置 call_time + call_text + need_play=1）
     * POST /admin/restaurant/takefood/call
     */
    public function call()
    {
        $aid = ScopeService::aidOf($this->request);

        $id       = (int) $this->request->post('id', 0);
        $callText = trim((string) $this->request->post('call_text', ''));

        $this->takeFoodService->callNumber($aid, $id, $callText);

        return $this->success([], '已叫号');
    }

    /**
     * 完成取餐（制作中/待取餐 → 已取餐）
     * POST /admin/restaurant/takefood/finish
     */
    public function finish()
    {
        $aid = ScopeService::aidOf($this->request);
        $id  = (int) $this->request->post('id', 0);

        $this->takeFoodService->finish($aid, $id);

        return $this->success([], '已完成取餐');
    }

    /**
     * 读取取餐设置（banner 轮播图/大屏图/轮播间隔）
     * GET /admin/restaurant/takefood/set
     */
    public function set()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->takeFoodService->getSet($aid));
    }

    /**
     * 保存取餐设置
     * POST /admin/restaurant/takefood/set
     */
    public function saveSet()
    {
        $aid = ScopeService::aidOf($this->request);

        $banner = $this->request->post('banner', []);
        if (!is_array($banner)) {
            $banner = [];
        }

        $this->takeFoodService->saveSet(
            $aid,
            array_map('strval', $banner),
            trim((string) $this->request->post('screen_pic', '')),
            (int) $this->request->post('banner_interval', 3)
        );

        return $this->success([], '保存成功');
    }
}
