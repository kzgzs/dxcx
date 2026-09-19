<?php

declare(strict_types=1);

namespace app\api\controller;

use app\common\service\CartService;

/**
 * 客户端 - 购物车（api 应用，鉴权 role=member，aid/mid 取自 token）
 */
class Cart extends Base
{
    public function __construct(\think\App $app, protected CartService $cartService)
    {
        parent::__construct($app);
    }

    /**
     * 购物车列表（联表 shop_product）
     * GET /api/cart/list
     */
    public function list()
    {
        [$aid, $mid] = $this->memberContext();

        return $this->success($this->cartService->apiList($aid, $mid));
    }

    /**
     * 加入购物车（已存在则 num 累加；ggid>0 时按 SKU 规格行加购）
     * POST /api/cart/add {product_id, num=1, ggid=0}
     */
    public function add()
    {
        [$aid, $mid] = $this->memberContext();

        $productId = (int) $this->request->post('product_id', 0);
        $num       = (int) $this->request->post('num', 1);
        $ggid      = (int) $this->request->post('ggid', 0);

        return $this->success($this->cartService->add($aid, $mid, $productId, $num, $ggid));
    }

    /**
     * 更新购物车行（num/selected 缺省不修改）
     * POST /api/cart/update {id, num?, selected?}
     */
    public function update()
    {
        [$aid, $mid] = $this->memberContext();

        $id       = (int) $this->request->post('id', 0);
        $num      = $this->request->post('num');
        $selected = $this->request->post('selected');

        $this->cartService->update(
            $aid,
            $mid,
            $id,
            null === $num || '' === $num ? null : (int) $num,
            null === $selected || '' === $selected ? null : (int) $selected
        );

        return $this->success([], '更新成功');
    }

    /**
     * 批量删除购物车行
     * POST /api/cart/delete {ids:[...]}
     */
    public function delete()
    {
        [$aid, $mid] = $this->memberContext();

        $ids = $this->request->post('ids', []);

        if (!is_array($ids)) {
            $ids = [];
        }

        return $this->success($this->cartService->delete($aid, $mid, $ids));
    }

    /**
     * 全选/全不选
     * POST /api/cart/selectAll {selected:0|1}
     */
    public function selectAll()
    {
        [$aid, $mid] = $this->memberContext();

        $selected = (int) $this->request->post('selected', 1);

        return $this->success($this->cartService->selectAll($aid, $mid, $selected));
    }
}
