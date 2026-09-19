<?php

declare(strict_types=1);

namespace app\api\controller;

use app\common\service\TuangouService;

/**
 * 客户端 - 团购（api 应用，JWT role=member，aid/mid 取自 token）
 *
 * 覆盖：分类/商品列表详情（阶梯价）/下单/订单列表详情/申请退款/确认收货/删除/评价提交/核销码展示
 */
class Tuangou extends Base
{
    public function __construct(\think\App $app, protected TuangouService $tuangouService)
    {
        parent::__construct($app);
    }

    /* ================= 团购设置/分类 ================= */

    /**
     * 团购设置（轮播图等）
     * GET /api/tuangou/set
     */
    public function setGet()
    {
        [$aid] = $this->memberContext();

        return $this->success($this->tuangouService->sysset($aid));
    }

    /**
     * 团购分类（启用状态）
     * GET /api/tuangou/category/list
     */
    public function categoryList()
    {
        [$aid] = $this->memberContext();

        return $this->success($this->tuangouService->apiCategoryList($aid));
    }

    /* ================= 团购商品 ================= */

    /**
     * 商品列表（上架+审核通过+活动期内；附阶梯价进度）
     * GET /api/tuangou/product/list?page=&pagesize=&cid=&keyword=
     */
    public function productList()
    {
        [$aid] = $this->memberContext();

        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->tuangouService->apiProductList(
            $aid,
            (int) $this->request->get('cid', 0),
            trim((string) $this->request->get('keyword', '')),
            $page,
            $pageSize
        ));
    }

    /**
     * 商品详情（阶梯价按已购人数展示）
     * GET /api/tuangou/product/detail?id=
     */
    public function productDetail()
    {
        [$aid] = $this->memberContext();

        return $this->success($this->tuangouService->apiProductDetail($aid, (int) $this->request->get('id', 0)));
    }

    /* ================= 下单与订单 ================= */

    /**
     * 团购下单（事务内：限购/活动期校验+条件扣减+写订单/支付单；mock 即时入账）
     * POST /api/tuangou/order/create {proid,num,linkman,tel,area,address,message,freight_type,mdid,freight_price,freight_text}
     */
    public function orderCreate()
    {
        [$aid, $mid] = $this->memberContext();

        $proid = (int) $this->request->post('proid', 0);
        $num   = max(1, (int) $this->request->post('num', 1));

        $extra = [
            'linkman'       => trim((string) $this->request->post('linkman', '')),
            'tel'           => trim((string) $this->request->post('tel', '')),
            'area'          => trim((string) $this->request->post('area', '')),
            'address'       => trim((string) $this->request->post('address', '')),
            'message'       => trim((string) $this->request->post('message', '')),
            'freight_type'  => (int) $this->request->post('freight_type', 0),
            'mdid'          => (int) $this->request->post('mdid', 0),
            'freight_price' => (float) $this->request->post('freight_price', 0),
            'freight_text'  => trim((string) $this->request->post('freight_text', '')),
            'platform'      => trim((string) $this->request->post('platform', 'wx')),
        ];

        return $this->success($this->tuangouService->apiCreateOrder($aid, $mid, $proid, $num, $extra), '下单成功');
    }

    /**
     * 我的团购订单（st ''=全部 0-4 / r 退款相关）
     * GET /api/tuangou/order/list?st=&page=&pagesize=
     */
    public function myOrders()
    {
        [$aid, $mid] = $this->memberContext();

        $st       = trim((string) $this->request->get('st', ''));
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->tuangouService->apiMyOrders($aid, $mid, $st, $page, $pageSize));
    }

    /**
     * 订单详情（含核销码与已发卡密）
     * GET /api/tuangou/order/detail?id=
     */
    public function orderDetail()
    {
        [$aid, $mid] = $this->memberContext();

        return $this->success($this->tuangouService->apiOrderDetail($aid, $mid, (int) $this->request->get('id', 0)));
    }

    /**
     * 核销码展示（到店核销单；等价 order/detail 的 hexiao_code 字段）
     * GET /api/tuangou/order/hexiaoCode?id=
     */
    public function orderHexiaoCode()
    {
        [$aid, $mid] = $this->memberContext();

        $detail = $this->tuangouService->apiOrderDetail($aid, $mid, (int) $this->request->get('id', 0));

        return $this->success([
            'ordernum'    => (string) $detail['ordernum'],
            'hexiao_code' => (string) ($detail['hexiao_code'] ?? ''),
            'status'      => (int) $detail['status'],
        ]);
    }

    /**
     * 申请退款
     * POST /api/tuangou/order/refund {id,reason}
     */
    public function orderRefund()
    {
        [$aid, $mid] = $this->memberContext();

        $this->tuangouService->apiOrderRefund($aid, $mid, (int) $this->request->post('id', 0), (string) $this->request->post('reason', ''));

        return $this->success([], '退款申请已提交');
    }

    /**
     * 确认收货
     * POST /api/tuangou/order/collect {id}
     */
    public function orderCollect()
    {
        [$aid, $mid] = $this->memberContext();

        $this->tuangouService->apiOrderCollect($aid, $mid, (int) $this->request->post('id', 0));

        return $this->success([], '已确认收货');
    }

    /**
     * 删除订单（仅已完成/已关闭）
     * POST /api/tuangou/order/delete {id}
     */
    public function orderDelete()
    {
        [$aid, $mid] = $this->memberContext();

        $this->tuangouService->apiOrderDelete($aid, $mid, (int) $this->request->post('id', 0));

        return $this->success([], '删除成功');
    }

    /**
     * 提交评价（score 1-5；按设置决定进审核或直接显示）
     * POST /api/tuangou/comment/create {orderid,score,content,content_pic}
     */
    public function commentCreate()
    {
        [$aid, $mid] = $this->memberContext();

        return $this->success($this->tuangouService->apiCommentCreate(
            $aid,
            $mid,
            (int) $this->request->post('orderid', 0),
            (int) $this->request->post('score', 5),
            (string) $this->request->post('content', ''),
            (string) $this->request->post('content_pic', '')
        ), '评价成功');
    }
}
