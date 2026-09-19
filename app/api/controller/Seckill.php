<?php

declare(strict_types=1);

namespace app\api\controller;

use app\common\service\SeckillService;

/**
 * 客户端 - 秒杀（api 应用，JWT role=member，aid/mid 取自 token）
 */
class Seckill extends Base
{
    public function __construct(\think\App $app, protected SeckillService $seckillService)
    {
        parent::__construct($app);
    }

    /**
     * 秒杀商品列表（场次内：date+seckill_time）
     * GET /api/seckill/product/list?date=&seckill_time=&page=&pagesize=
     */
    public function productList()
    {
        [$aid] = $this->memberContext();

        $date        = trim((string) $this->request->get('date', ''));
        $seckillTime = (int) $this->request->get('seckill_time', 0);
        $page        = max(1, (int) $this->request->get('page', 1));
        $pageSize    = (int) $this->request->get('pagesize', 10);
        $pageSize    = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->seckillService->apiProductList($aid, $date, $seckillTime, $page, $pageSize));
    }

    /**
     * 秒杀商品详情（规格 + 场次行含开始/结束时间）
     * GET /api/seckill/product/detail?proid=&date=&seckill_time=
     */
    public function productDetail()
    {
        [$aid] = $this->memberContext();

        $proid       = (int) $this->request->get('proid', 0);
        $date        = trim((string) $this->request->get('date', ''));
        $seckillTime = (int) $this->request->get('seckill_time', 0);

        return $this->success($this->seckillService->apiProductDetail($aid, $proid, $date, $seckillTime));
    }

    /**
     * 秒杀下单（场次窗口+双重限购+条件扣减防超卖，事务内完成）
     * POST /api/seckill/order/create {proid,prodata_id,ggid,num,linkman,tel,area,address,message}
     */
    public function orderCreate()
    {
        [$aid, $mid] = $this->memberContext();

        $proid     = (int) $this->request->post('proid', 0);
        $prodataId = (int) $this->request->post('prodata_id', 0);
        $ggid      = (int) $this->request->post('ggid', 0);
        $num       = max(1, (int) $this->request->post('num', 1));

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

        return $this->success($this->seckillService->apiCreateOrder($aid, $mid, $proid, $prodataId, $ggid, $num, $extra), '下单成功');
    }

    /**
     * 我的秒杀订单
     * GET /api/seckill/order/list?status=&page=&pagesize=
     */
    public function myOrders()
    {
        [$aid, $mid] = $this->memberContext();

        $status   = trim((string) $this->request->get('status', ''));
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->seckillService->apiMyOrders($aid, $mid, $status, $page, $pageSize));
    }
}
