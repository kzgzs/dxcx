<?php

declare(strict_types=1);

namespace app\api\controller;

use app\common\service\BargainService;

/**
 * 客户端 - 砍价（api 应用，JWT role=member，aid/mid 取自 token）
 */
class Kanjia extends Base
{
    public function __construct(\think\App $app, protected BargainService $bargainService)
    {
        parent::__construct($app);
    }

    /**
     * 砍价商品列表（上架）
     * GET /api/kanjia/product/list?page=&pagesize=&keyword=
     */
    public function productList()
    {
        [$aid] = $this->memberContext();

        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;
        $keyword  = trim((string) $this->request->get('keyword', ''));

        return $this->success($this->bargainService->apiProductList($aid, $page, $pageSize, $keyword));
    }

    /**
     * 砍价商品详情（含帮砍文案与本人进行中发起记录）
     * GET /api/kanjia/product/detail?proid=
     */
    public function productDetail()
    {
        [$aid, $mid] = $this->memberContext();

        $proid = (int) $this->request->get('proid', 0);

        return $this->success($this->bargainService->apiProductDetail($aid, $mid, $proid));
    }

    /**
     * 发起砍价
     * POST /api/kanjia/join/start {proid}
     */
    public function startJoin()
    {
        [$aid, $mid] = $this->memberContext();

        $proid = (int) $this->request->post('proid', 0);

        return $this->success($this->bargainService->startJoin($aid, $mid, $proid), '发起砍价成功');
    }

    /**
     * 帮砍（递减算法 + 帮砍奖励，服务层防重复）
     * POST /api/kanjia/join/help {joinid}
     */
    public function helpCut()
    {
        [$aid, $mid] = $this->memberContext();

        $joinId = (int) $this->request->post('joinid', 0);

        return $this->success($this->bargainService->helpCut($aid, $mid, $joinId), '帮砍成功');
    }

    /**
     * 我的砍价发起记录（join 列表，mid 过滤）
     * GET /api/kanjia/join/list?page=&pagesize=
     */
    public function joinList()
    {
        [$aid, $mid] = $this->memberContext();

        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->bargainService->joinList($aid, $page, $pageSize, ['mid' => $mid]));
    }

    /**
     * 某次砍价的帮砍明细
     * GET /api/kanjia/join/helpList?joinid=
     */
    public function helpList()
    {
        [$aid] = $this->memberContext();

        $joinId = (int) $this->request->get('joinid', 0);

        return $this->success($this->bargainService->helpList($aid, $joinId));
    }

    /**
     * 砍到底价下单
     * POST /api/kanjia/order/create {joinid,linkman,tel,area,address,message}
     */
    public function orderCreate()
    {
        [$aid, $mid] = $this->memberContext();

        $joinId = (int) $this->request->post('joinid', 0);

        $extra = [
            'linkman'       => trim((string) $this->request->post('linkman', '')),
            'tel'           => trim((string) $this->request->post('tel', '')),
            'area'          => trim((string) $this->request->post('area', '')),
            'address'       => trim((string) $this->request->post('address', '')),
            'message'       => trim((string) $this->request->post('message', '')),
            'freight_price' => (float) $this->request->post('freight_price', 0),
            'freight_text'  => trim((string) $this->request->post('freight_text', '')),
            'platform'      => trim((string) $this->request->post('platform', 'wx')),
        ];

        return $this->success($this->bargainService->createOrder($aid, $mid, $joinId, $extra), '下单成功');
    }

    /**
     * 我的砍价订单
     * GET /api/kanjia/order/list?status=&page=&pagesize=
     */
    public function myOrders()
    {
        [$aid, $mid] = $this->memberContext();

        $status   = trim((string) $this->request->get('status', ''));
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->bargainService->apiMyOrders($aid, $mid, $status, $page, $pageSize));
    }
}
