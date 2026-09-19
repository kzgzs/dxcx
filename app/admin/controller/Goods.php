<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\GoodsService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 商品管理（分类 + 商品）
 *
 * aid 一律取自 JWT（ScopeService::aidOf），杜绝越租户查询
 */
class Goods extends BaseController
{
    public function __construct(\think\App $app, protected GoodsService $goodsService)
    {
        parent::__construct($app);
    }

    // ---------- 商品 ----------

    /**
     * 商品列表（分页筛选）
     * GET /admin/goods/list?page&pagesize&keyword&category_id&status(0/1/空)&bid(''/all/0/gt0/n)
     */
    public function list()
    {
        $aid = $this->aid();

        return $this->success($this->goodsService->goodsList(
            $aid,
            max(1, (int) $this->request->param('page', 1)),
            max(1, min(100, (int) $this->request->param('pagesize', 20))),
            (string) $this->request->param('keyword', ''),
            (string) $this->request->param('category_id', ''),
            (string) $this->request->param('status', ''),
            (string) $this->request->param('bid', ''),
            (string) $this->request->param('gid', ''),
            (string) $this->request->param('sort_field', ''),
            (string) $this->request->param('sort_order', 'desc')
        ));
    }

    /**
     * 新增商品
     * POST /admin/goods/add body:{name,category_id,price,market_price,stock,image,images,detail,sort,status}
     */
    public function add()
    {
        $data = $this->body();

        $this->validate($data, [
            'name'        => 'require',
            'category_id' => 'require|integer|gt:0',
        ]);

        return $this->success($this->goodsService->goodsAdd($this->aid(), $data));
    }

    /**
     * 编辑商品
     * POST /admin/goods/edit body:{id,...同新增}
     */
    public function edit()
    {
        $data = $this->body();

        $this->validate($data, [
            'id'          => 'require|integer|gt:0',
            'name'        => 'require',
            'category_id' => 'require|integer|gt:0',
        ]);

        $this->goodsService->goodsEdit($this->aid(), $data);

        return $this->success();
    }

    /**
     * 商品详情（编辑回显：基础字段 + specs 规格组 + SKU 列表）
     * GET /admin/goods/detail?id=
     */
    public function detail()
    {
        $id = (int) $this->request->param('id', 0);

        if ($id <= 0) {
            return $this->error('缺少商品ID');
        }

        return $this->success($this->goodsService->goodsDetail($this->aid(), $id));
    }

    /**
     * 删除商品（软删，存在未完结订单时拒绝）
     * POST /admin/goods/delete body:{id}
     */
    public function delete()
    {
        $data = $this->body();

        // 支持单个 id 或批量 ids 数组，具体校验在 Service 层
        if (empty($data['id']) && empty($data['ids'])) {
            throw new \app\common\exception\BusinessException('请选择商品');
        }

        $this->goodsService->goodsDelete($this->aid(), $data);

        return $this->success();
    }

    /**
     * 商品上下架
     * POST /admin/goods/status body:{id,status}
     */
    public function status()
    {
        $data = $this->body();

        $this->validate($data, [
            'status' => 'require|in:0,1',
        ]);

        if (empty($data['id']) && empty($data['ids'])) {
            throw new \app\common\exception\BusinessException('请选择商品');
        }

        $this->goodsService->goodsSetStatus($this->aid(), $data);

        return $this->success();
    }

    /**
     * 复制商品
     * POST /admin/goods/copy body:{id}
     */
    public function copy()
    {
        $data = $this->body();
        $this->validate($data, ['id' => 'require|integer|gt:0']);

        return $this->success($this->goodsService->goodsCopy($this->aid(), (int) $data['id']));
    }

    /**
     * 修改价格
     * POST /admin/goods/changePrice body:{id,sell_price,market_price}
     */
    public function changePrice()
    {
        $data = $this->body();
        $this->validate($data, ['id' => 'require|integer|gt:0']);

        $this->goodsService->goodsChangePrice($this->aid(), $data);

        return $this->success();
    }

    /**
     * Excel 库存导入（对标参考系统 ShopKucundr：商品编码+规格名+库存）
     * POST /admin/goods/stockImport multipart 字段 file
     */
    public function stockImport()
    {
        $file = $this->request->file('file');

        if (empty($file)) {
            return $this->error('请上传 Excel 文件');
        }

        $extension = strtolower($file->getOriginalExtension());
        if (!in_array($extension, ['xlsx', 'xls', 'csv'], true)) {
            return $this->error('仅支持 xlsx/xls/csv 格式');
        }

        $result = $this->goodsService->stockImport($this->aid(), $file->getPathname());

        $msg = '成功更新' . $result['updated'] . '条数据';
        if ($result['missed'] > 0) {
            $msg .= '，未找到商品' . $result['missed'] . '条';
        }

        return $this->success($result, $msg);
    }

    // ---------- 分类 ----------

    /**
     * 分类列表（分页）
     * GET /admin/goods/category/list?page=1&pagesize=50&keyword
     */
    public function categoryList()
    {
        $aid = $this->aid();

        return $this->success($this->goodsService->categoryList(
            $aid,
            max(1, (int) $this->request->param('page', 1)),
            max(1, min(10000, (int) $this->request->param('pagesize', 50))),
            (string) $this->request->param('keyword', '')
        ));
    }

    /**
     * 新增分类
     * POST /admin/goods/category/add body:{name,pid=0,sort}
     */
    public function categoryAdd()
    {
        $data = $this->body();

        $this->validate($data, ['name' => 'require']);

        return $this->success($this->goodsService->categoryAdd($this->aid(), $data));
    }

    /**
     * 编辑分类
     * POST /admin/goods/category/edit body:{id,name,sort}
     */
    public function categoryEdit()
    {
        $data = $this->body();

        $this->validate($data, [
            'id'   => 'require|integer|gt:0',
            'name' => 'require',
        ]);

        $this->goodsService->categoryEdit($this->aid(), $data);

        return $this->success();
    }

    /**
     * 删除分类（有商品时拒绝）
     * POST /admin/goods/category/delete body:{id}
     */
    public function categoryDelete()
    {
        $data = $this->body();

        $hasIds = is_array($data['ids'] ?? null)
            && count(array_filter($data['ids'], static fn ($v) => (int) $v > 0)) > 0;
        if (!$hasIds) {
            $this->validate($data, ['id' => 'require|integer|gt:0']);
        }

        $this->goodsService->categoryDelete($this->aid(), $data);

        return $this->success();
    }

    // ---------- 工具 ----------

    /**
     * 当前租户 ID（JWT 注入，不可伪造）
     */
    protected function aid(): int
    {
        return ScopeService::aidOf($this->request);
    }

    /**
     * 请求体（兼容 JSON 与表单）
     */
    protected function body(): array
    {
        $data = $this->request->post();

        return is_array($data) ? $data : [];
    }
}
