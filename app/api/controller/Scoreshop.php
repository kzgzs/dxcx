<?php

declare(strict_types=1);

namespace app\api\controller;

use app\common\service\ScoreshopService;

/**
 * 客户端 - 积分商城（api 应用，JWT role=member，aid/mid 取自 token）
 */
class Scoreshop extends Base
{
    public function __construct(\think\App $app, protected ScoreshopService $scoreshopService)
    {
        parent::__construct($app);
    }

    /**
     * 积分商城商品列表（上架）
     * GET /api/scoreshop/product/list?page=&pagesize=&keyword=&cid=
     */
    public function productList()
    {
        [$aid] = $this->memberContext();

        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;
        $keyword  = trim((string) $this->request->get('keyword', ''));
        $cid      = (int) $this->request->get('cid', 0);

        return $this->success($this->scoreshopService->apiProductList($aid, $page, $pageSize, $keyword, $cid));
    }

    /**
     * 积分商城商品详情（含规格）
     * GET /api/scoreshop/product/detail?proid=
     */
    public function productDetail()
    {
        [$aid] = $this->memberContext();

        $proid = (int) $this->request->get('proid', 0);

        return $this->success($this->scoreshopService->apiProductDetail($aid, $proid));
    }

    /**
     * 积分兑换下单（纯积分同事务直扣即已付；积分+钱走支付单，mock 自动支付）
     * POST /api/scoreshop/order/create {proid,ggid,num,linkman,tel,area,address,message}
     */
    public function orderCreate()
    {
        [$aid, $mid] = $this->memberContext();

        $proid = (int) $this->request->post('proid', 0);
        $ggid  = (int) $this->request->post('ggid', 0);
        $num   = max(1, (int) $this->request->post('num', 1));

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

        return $this->success($this->scoreshopService->apiCreateOrder($aid, $mid, $proid, $ggid, $num, $extra), '兑换成功');
    }

    /**
     * 我的积分商城订单
     * GET /api/scoreshop/order/list?status=&page=&pagesize=
     */
    public function myOrders()
    {
        [$aid, $mid] = $this->memberContext();

        $status   = trim((string) $this->request->get('status', ''));
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->scoreshopService->apiMyOrders($aid, $mid, $status, $page, $pageSize));
    }
}
