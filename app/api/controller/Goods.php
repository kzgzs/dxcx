<?php

declare(strict_types=1);

namespace app\api\controller;

use app\BaseController;
use app\common\service\GoodsService;

/**
 * 客户端 - 商品（api 应用）
 *
 * 本期不鉴权（AuthMiddleware 白名单放行），租户 aid 走 query 参数
 */
class Goods extends BaseController
{
    public function __construct(\think\App $app, protected GoodsService $goodsService)
    {
        parent::__construct($app);
    }

    /**
     * 分类列表（仅有上架商品的分类）
     * GET /api/goods/category/list?aid=1
     */
    public function categoryList()
    {
        return $this->success($this->goodsService->apiCategoryList($this->aid()));
    }

    /**
     * 商品列表（仅上架未删）
     * GET /api/goods/list?aid=1&page&pagesize&keyword&category_id
     */
    public function list()
    {
        return $this->success($this->goodsService->apiGoodsList(
            $this->aid(),
            max(1, (int) $this->request->param('page', 1)),
            max(1, min(100, (int) $this->request->param('pagesize', 20))),
            (string) $this->request->param('keyword', ''),
            (string) $this->request->param('category_id', '')
        ));
    }

    /**
     * 商品详情（下架/删除返回业务错误）
     * GET /api/goods/detail?aid=1&id=
     */
    public function detail()
    {
        $id = (int) $this->request->param('id', 0);

        return $this->success($this->goodsService->apiGoodsDetail($this->aid(), $id));
    }

    /**
     * 租户 ID：本期客户端未登录，取 query 参数（TenantMiddleware 已解析 X-Tenant-Id/aid）
     */
    protected function aid(): int
    {
        return (int) $this->request->aid;
    }
}
