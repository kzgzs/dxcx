<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;

/**
 * 客户端购物车（api 应用）
 *
 * 表复用：cart（迁移 20260813000028：aid/member_id/product_id/num/selected/create_time/update_time，
 * 唯一键 aid+member_id+product_id，同商品一行 num 累加）
 *
 * 约定：
 * - list 联表 shop_product 返回最新 名称/图/价/库存，商品 deleted=1 的行过滤
 * - add 校验商品存在、上架、库存（累加后不超 stock）
 * - 租户隔离：全部查询强制 aid 条件（ScopeService / where aid）
 */
class CartService
{
    /**
     * 购物车列表（联表 shop_product；商品已删除的行过滤）
     *
     * @return array{list: array<int, array<string, mixed>>}
     */
    public function apiList(int $aid, int $mid): array
    {
        $rows = Db::name('cart')
            ->alias('c')
            ->join('shop_product p', 'p.id = c.product_id')
            ->leftJoin('shop_guige g', 'g.id = c.ggid')
            ->where('c.aid', $aid)
            ->where('c.member_id', $mid)
            ->where('p.deleted', 0)
            ->field('c.id, c.product_id, c.ggid, p.name, p.pic, p.sell_price, p.stock, g.name as ggname, g.pic as ggpics, g.sell_price as gg_price, g.stock as gg_stock, c.num, c.selected')
            ->order('c.id', 'desc')
            ->select()
            ->toArray();

        $list = array_map(static function (array $row): array {
            $ggid = (int) ($row['ggid'] ?? 0);

            return [
                'id'         => (int) $row['id'],
                'product_id' => (int) $row['product_id'],
                'ggid'       => $ggid,
                'ggname'     => (string) ($row['ggname'] ?? ''),
                'name'       => (string) ($row['name'] ?? ''),
                'pic'        => $ggid > 0 && '' !== trim((string) ($row['ggpics'] ?? ''))
                    ? (string) $row['ggpics']
                    : (string) ($row['pic'] ?? ''),
                'price'      => $ggid > 0 ? (float) ($row['gg_price'] ?? 0) : (float) ($row['sell_price'] ?? 0),
                'stock'      => $ggid > 0 ? (int) ($row['gg_stock'] ?? 0) : (int) ($row['stock'] ?? 0),
                'num'        => (int) $row['num'],
                'selected'   => (int) $row['selected'],
            ];
        }, $rows);

        return ['list' => $list];
    }

    /**
     * 加入购物车（已存在则 num 累加；校验商品上架与库存；ggid>0 时按 SKU 行库存校验）
     *
     * @return array{id: int}
     */
    public function add(int $aid, int $mid, int $productId, int $num, int $ggid = 0): array
    {
        if ($productId <= 0) {
            throw new BusinessException('缺少商品ID');
        }

        $num = $num > 0 ? $num : 1;

        $product = $this->findOnSaleProduct($aid, $productId);

        // SKU 规格校验（同租户同商品下的规格行）
        if ($ggid > 0) {
            $sku = Db::name('shop_guige')
                ->where('aid', $aid)
                ->where('proid', $productId)
                ->where('id', $ggid)
                ->find();

            if (empty($sku)) {
                throw new BusinessException('商品规格不存在或已删除');
            }

            $stock = (int) ($sku['stock'] ?? 0);
        } else {
            $stock = (int) ($product['stock'] ?? 0);
        }

        $row = Db::name('cart')
            ->where('aid', $aid)
            ->where('member_id', $mid)
            ->where('product_id', $productId)
            ->where('ggid', $ggid)
            ->find();

        if (!empty($row)) {
            $newNum = (int) $row['num'] + $num;

            if ($newNum > $stock) {
                throw new BusinessException('库存不足');
            }

            Db::name('cart')
                ->where('id', (int) $row['id'])
                ->update(['num' => $newNum, 'update_time' => time()]);

            return ['id' => (int) $row['id']];
        }

        if ($num > $stock) {
            throw new BusinessException('库存不足');
        }

        $id = (int) Db::name('cart')->insertGetId([
            'aid'         => $aid,
            'member_id'   => $mid,
            'product_id'  => $productId,
            'ggid'        => $ggid,
            'num'         => $num,
            'selected'    => 1,
            'create_time' => time(),
            'update_time' => time(),
        ]);

        return ['id' => $id];
    }

    /**
     * 更新购物车行（num/selected 均可选，缺省不修改）
     */
    public function update(int $aid, int $mid, int $id, ?int $num, ?int $selected): void
    {
        $row = $this->findOwnRow($aid, $mid, $id);

        $update = [];

        if (null !== $num) {
            if ($num <= 0) {
                throw new BusinessException('数量必须大于0');
            }

            $ggid = (int) ($row['ggid'] ?? 0);

            if ($ggid > 0) {
                $stock = (int) Db::name('shop_guige')->where('id', $ggid)->value('stock');
            } else {
                $stock = (int) Db::name('shop_product')->where('id', (int) $row['product_id'])->value('stock');
            }

            if ($num > $stock) {
                throw new BusinessException('库存不足');
            }

            $update['num'] = $num;
        }

        if (null !== $selected) {
            if (!in_array($selected, [0, 1], true)) {
                throw new BusinessException('selected 取值仅 0/1');
            }

            $update['selected'] = $selected;
        }

        if (empty($update)) {
            throw new BusinessException('没有可更新的字段');
        }

        $update['update_time'] = time();

        Db::name('cart')->where('id', $id)->update($update);
    }

    /**
     * 批量删除购物车行（仅本人行生效）
     *
     * @param array<int, int> $ids
     * @return array{deleted: int}
     */
    public function delete(int $aid, int $mid, array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $v) => $v > 0));

        if (empty($ids)) {
            throw new BusinessException('缺少要删除的购物车ID');
        }

        $deleted = Db::name('cart')
            ->where('aid', $aid)
            ->where('member_id', $mid)
            ->whereIn('id', $ids)
            ->delete();

        return ['deleted' => (int) $deleted];
    }

    /**
     * 全选/全不选
     *
     * @return array{affected: int}
     */
    public function selectAll(int $aid, int $mid, int $selected): array
    {
        if (!in_array($selected, [0, 1], true)) {
            throw new BusinessException('selected 取值仅 0/1');
        }

        $affected = Db::name('cart')
            ->where('aid', $aid)
            ->where('member_id', $mid)
            ->update(['selected' => $selected, 'update_time' => time()]);

        return ['affected' => (int) $affected];
    }

    /* ================= 内部工具 ================= */

    /**
     * 校验商品存在且上架（deleted=0 status=1），返回原始行
     *
     * @return array<string, mixed>
     */
    protected function findOnSaleProduct(int $aid, int $productId): array
    {
        $product = ScopeService::query('shop_product', $aid)
            ->where('id', $productId)
            ->where('deleted', 0)
            ->find();

        if (empty($product)) {
            throw new BusinessException('商品不存在', 404, 404);
        }

        if (1 !== (int) ($product['status'] ?? 0)) {
            throw new BusinessException('商品已下架');
        }

        return (array) $product;
    }

    /**
     * 查找本人购物车行（不存在/非本人抛 404）
     *
     * @return array<string, mixed>
     */
    protected function findOwnRow(int $aid, int $mid, int $id): array
    {
        if ($id <= 0) {
            throw new BusinessException('缺少购物车ID');
        }

        $row = Db::name('cart')
            ->where('id', $id)
            ->where('aid', $aid)
            ->where('member_id', $mid)
            ->find();

        if (empty($row)) {
            throw new BusinessException('购物车记录不存在', 404, 404);
        }

        return (array) $row;
    }
}
