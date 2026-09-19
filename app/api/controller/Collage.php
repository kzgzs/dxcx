<?php

declare(strict_types=1);

namespace app\api\controller;

use app\common\service\CollageService;

/**
 * 客户端 - 拼团（api 应用，JWT role=member，aid/mid 取自 token）
 */
class Collage extends Base
{
    public function __construct(\think\App $app, protected CollageService $collageService)
    {
        parent::__construct($app);
    }

    /**
     * 拼团商品列表（上架+审核通过）
     * GET /api/collage/product/list?page=&pagesize=&keyword=
     */
    public function productList()
    {
        [$aid] = $this->memberContext();

        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;
        $keyword  = trim((string) $this->request->get('keyword', ''));

        return $this->success($this->collageService->apiProductList($aid, $page, $pageSize, $keyword));
    }

    /**
     * 拼团商品详情（含进行中可参与团）
     * GET /api/collage/product/detail?id=
     */
    public function productDetail()
    {
        [$aid] = $this->memberContext();

        $id = (int) $this->request->get('id', 0);

        return $this->success($this->collageService->apiProductDetail($aid, $id));
    }

    /**
     * 某商品进行中的团（可参与）
     * GET /api/collage/team/list?proid=&limit=
     */
    public function teamList()
    {
        [$aid] = $this->memberContext();

        $proid = (int) $this->request->get('proid', 0);
        $limit = (int) $this->request->get('limit', 10);
        $limit = $limit > 0 ? min($limit, 50) : 10;

        return $this->success($this->collageService->apiOngoingTeams($aid, $proid, $limit));
    }

    /**
     * 团详情（成员/剩余时间）
     * GET /api/collage/team/detail?teamid=
     */
    public function teamDetail()
    {
        [$aid] = $this->memberContext();

        $teamId = (int) $this->request->get('teamid', 0);

        return $this->success($this->collageService->apiTeamDetail($aid, $teamId));
    }

    /**
     * 拼团下单（buytype 1=直购 2=开团 3=参团；条件扣减防超卖+限购，事务内完成）
     * POST /api/collage/order/create {proid,buytype,teamid,ggid,num,linkman,tel,area,address,message}
     */
    public function orderCreate()
    {
        [$aid, $mid] = $this->memberContext();

        $proid   = (int) $this->request->post('proid', 0);
        $buytype = (int) $this->request->post('buytype', 1);
        $teamId  = (int) $this->request->post('teamid', 0);
        $ggid    = (int) $this->request->post('ggid', 0);
        $num     = max(1, (int) $this->request->post('num', 1));

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

        return $this->success($this->collageService->apiCreateOrder($aid, $mid, $proid, $buytype, $teamId, $ggid, $num, $extra), '下单成功');
    }

    /**
     * 我的拼团订单（status 空=全部 0待支付 1进行中/已支付 ...）
     * GET /api/collage/order/list?status=&page=&pagesize=
     */
    public function myOrders()
    {
        [$aid, $mid] = $this->memberContext();

        $status   = trim((string) $this->request->get('status', ''));
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->collageService->apiMyOrders($aid, $mid, $status, $page, $pageSize));
    }
}
