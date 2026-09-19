<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;

/**
 * 商品模块服务（admin 后台 + api 客户端共用）
 *
 * 表复用旧系统基线：
 * - shop_category：商品分类（aid/pid/name/sort/status/createtime，本模块补 deleted 列）
 * - shop_product ：商品（cid 为逗号分隔分类ID；sell_price/market_price 单位「元」float；
 *   pic=主图 pics=图集，逗号分隔图片URL字符串，与旧表语义一致；本模块补 deleted/deletetime 列）
 *
 * 约定：所有查询强制经 ScopeService 携带 aid，软删统一 deleted=1（不物理删除）
 */
class GoodsService
{
    /** 商品状态：0 下架 1 上架 */
    public const STATUS_OFF = 0;
    public const STATUS_ON  = 1;

    // ================= admin 侧 =================

    /**
     * 分类分页列表（含各分类商品数）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function categoryList(int $aid, int $page, int $pagesize, string $keyword): array
    {
        $query = ScopeService::query('shop_category', $aid)->where('deleted', 0);

        $keyword = trim($keyword);
        if ('' !== $keyword) {
            $query->whereLike('name', '%' . $keyword . '%');
        }

        $total = (clone $query)->count();

        $rows = $query->order('sort', 'desc')
            ->order('id', 'asc')
            ->page($page, $pagesize)
            ->select()
            ->toArray();

        // 各分类下未删商品数（cid 为逗号分隔，PHP 侧展开计数）
        $counts = [];
        if (!empty($rows)) {
            $ids = array_map('intval', array_column($rows, 'id'));
            $cids = ScopeService::query('shop_product', $aid)
                ->where('deleted', 0)
                ->column('cid');
            foreach ($cids as $cid) {
                foreach (array_filter(explode(',', (string) $cid)) as $one) {
                    $cidInt = (int) $one;
                    if (in_array($cidInt, $ids, true)) {
                        $counts[$cidInt] = ($counts[$cidInt] ?? 0) + 1;
                    }
                }
            }
        }

        $list = array_map(static fn (array $row): array => [
            'id'          => (int) $row['id'],
            'name'        => (string) ($row['name'] ?? ''),
            'pid'         => (int) ($row['pid'] ?? 0),
            'sort'        => (int) ($row['sort'] ?? 0),
            'goods_count' => $counts[(int) $row['id']] ?? 0,
            'pic'         => (string) ($row['pic'] ?? ''),
            'status'      => (int) ($row['status'] ?? 1),
            'create_time' => (int) ($row['createtime'] ?? 0) > 0 ? date('Y-m-d H:i:s', (int) $row['createtime']) : '',
        ], $rows);

        return ['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pagesize];
    }

    /**
     * 新增分类
     *
     * @param array $data {name, pid=0, sort}
     * @return array{id: int}
     */
    public function categoryAdd(int $aid, array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ('' === $name) {
            throw new BusinessException('分类名称不能为空', 1);
        }

        $pid = (int) ($data['pid'] ?? 0);
        if ($pid > 0) {
            $parent = ScopeService::query('shop_category', $aid)->where('id', $pid)->where('deleted', 0)->find();
            if (empty($parent)) {
                throw new BusinessException('上级分类不存在', 1);
            }
        }

        $id = Db::name('shop_category')->insertGetId([
            'aid'        => $aid,
            'pid'        => $pid,
            'name'       => $name,
            'sort'       => (int) ($data['sort'] ?? 1),
            'pic'        => trim((string) ($data['pic'] ?? '')),
            'status'     => ((int) ($data['status'] ?? 1) === 0 ? 0 : 1),
            'createtime' => time(),
            'deleted'    => 0,
        ]);

        return ['id' => (int) $id];
    }

    /**
     * 编辑分类（仅名称与排序）
     *
     * @param array $data {id, name, sort}
     */
    public function categoryEdit(int $aid, array $data): void
    {
        $category = $this->findCategory($aid, (int) ($data['id'] ?? 0));

        $name = trim((string) ($data['name'] ?? ''));
        if ('' === $name) {
            throw new BusinessException('分类名称不能为空', 1);
        }

        $pid = (int) ($data['pid'] ?? (int) ($category['pid'] ?? 0));
        if ($pid > 0) {
            if ($pid === (int) $category['id']) {
                throw new BusinessException('上级分类不能选择自身', 1);
            }
            $parent = ScopeService::query('shop_category', $aid)->where('id', $pid)->where('deleted', 0)->find();
            if (empty($parent)) {
                throw new BusinessException('上级分类不存在', 1);
            }
            if ($this->isDescendantCategory($aid, (int) $category['id'], $pid)) {
                throw new BusinessException('上级分类不能选择自身的子分类', 1);
            }
        }

        Db::name('shop_category')->where('id', $category['id'])->update([
            'pid'    => $pid,
            'name' => $name,
            'sort' => (int) ($data['sort'] ?? $category['sort']),
            'pic' => array_key_exists('pic', $data) ? trim((string) $data['pic']) : (string) ($category['pic'] ?? ''),
            'status' => array_key_exists('status', $data) ? (((int) $data['status'] === 0) ? 0 : 1) : (int) ($category['status'] ?? 1),
        ]);
    }

    /**
     * 删除分类（软删；分类下存在商品时拒绝）
     *
     * @param array $data {id}
     */
    public function categoryDelete(int $aid, array $data): void
    {
        $rawIds = is_array($data['ids'] ?? null) ? $data['ids'] : [$data['id'] ?? 0];
        $idList = [];
        foreach ($rawIds as $raw) {
            $one = (int) $raw;
            if ($one > 0 && !in_array($one, $idList, true)) {
                $idList[] = $one;
            }
        }
        if (empty($idList)) {
            throw new BusinessException('请选择要删除的分类', 1);
        }

        foreach ($idList as $categoryId) {
            $category = $this->findCategory($aid, $categoryId);
            $goodsCount = (int) ScopeService::query('shop_product', $aid)
                ->where('deleted', 0)
                ->whereRaw("FIND_IN_SET({$categoryId}, cid)")
                ->count();
            if ($goodsCount > 0) {
                throw new BusinessException('分类「' . (string) $category['name'] . '」下存在商品，请先转移或删除该分类下的商品', 1);
            }
        }

        Db::name('shop_category')->where('aid', $aid)->whereIn('id', $idList)->update(['deleted' => 1]);
    }

    /**
     * 商品分页列表（支持关键字/分类/状态筛选）
     *
     * @param string $status ''=全部 0=下架 1=上架
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function goodsList(int $aid, int $page, int $pagesize, string $keyword, string $categoryId, string $status, string $bid = '', string $gid = '', string $sortField = '', string $sortOrder = 'desc'): array
    {
        $query = Db::name('shop_product')
            ->alias('p')
            ->leftJoin('shop_category c', 'c.id = p.cid')
            ->where('p.aid', $aid)
            ->where('p.deleted', 0)
            ->field('p.id,p.name,p.pic,p.sell_price,p.market_price,p.stock,p.cid,p.gid,p.status,p.sort,p.sales,p.createtime,c.name as category_name');

        $keyword = trim($keyword);
        if ('' !== $keyword) {
            $query->whereLike('p.name', '%' . $keyword . '%');
        }

        $categoryId = (int) $categoryId;
        if ($categoryId > 0) {
            $query->whereRaw("FIND_IN_SET({$categoryId}, p.cid)");
        }

        // 分组筛选（gid 为逗号分隔多值，FIND_IN_SET 匹配其一）
        $gid = trim($gid);
        if ('' !== $gid && (int) $gid > 0) {
            $query->whereRaw("FIND_IN_SET(" . (int) $gid . ", p.gid)");
        }

        if ('' !== $status) {
            $query->where('p.status', (int) $status);
        }

        // 商户维度过滤（任务177 showtype-2：''/all 不过滤，0 自营，gt0 商户）
        ScopeService::applyBid($query, $bid, 'p.bid');

        $total = (clone $query)->count();

        // 可排序字段白名单（对齐参考系统：id/stock/sales/sort/createtime）
        $sortable = ['id' => 'p.id', 'stock' => 'p.stock', 'sales' => 'p.sales', 'sort' => 'p.sort', 'createtime' => 'p.createtime'];
        $order = strtolower($sortOrder) === 'asc' ? 'asc' : 'desc';
        if (isset($sortable[$sortField])) {
            $query->order($sortable[$sortField], $order);
        }
        $rows = $query->order('p.sort', 'desc')
            ->order('p.id', 'desc')
            ->page($page, $pagesize)
            ->select()
            ->toArray();

        $list = array_map(static fn (array $row): array => [
            'id'            => (int) $row['id'],
            'name'          => (string) ($row['name'] ?? ''),
            'image'         => (string) ($row['pic'] ?? ''),
            'price'         => (float) ($row['sell_price'] ?? 0),
            'market_price'  => (float) ($row['market_price'] ?? 0),
            'stock'         => (int) ($row['stock'] ?? 0),
            'sales'         => (int) ($row['sales'] ?? 0),
            'realsalenum'   => (int) ($row['sales'] ?? 0),
            'gid'           => (string) ($row['gid'] ?? ''),
            'category_id'   => (int) ($row['cid'] ?? 0),
            'category_name' => (string) ($row['category_name'] ?? ''),
            'status'        => (int) ($row['status'] ?? 0),
            'sort'          => (int) ($row['sort'] ?? 0),
            'create_time'   => (int) ($row['createtime'] ?? 0) > 0 ? date('Y-m-d H:i:s', (int) $row['createtime']) : '',
        ], $rows);

        return ['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pagesize];
    }

    /**
     * 新增商品（价格单位「元」，两位小数）
     *
     * @return array{id: int}
     */
    public function goodsAdd(int $aid, array $data): array
    {
        $this->findCategory($aid, (int) ($data['category_id'] ?? 0));

        $insert = $this->buildGoodsData($data);
        $insert['aid']        = $aid;
        $insert['createtime'] = time();
        $insert['deleted']    = 0;

        $id = Db::name('shop_product')->insertGetId($insert);

        // 多规格 SKU：按(proid,ks) upsert shop_guige + 差集删旧行 + stock 汇总回写
        $this->saveGuige($aid, (int) $id, $data);

        return ['id' => (int) $id];
    }

    /**
     * 编辑商品
     *
     * @param array $data {id, ...同新增}
     */
    public function goodsEdit(int $aid, array $data): void
    {
        $goods = $this->findGoods($aid, (int) ($data['id'] ?? 0));

        $this->findCategory($aid, (int) ($data['category_id'] ?? 0));

        $update = $this->buildGoodsData($data);

        Db::name('shop_product')->where('id', $goods['id'])->update($update);

        // 多规格 SKU：按(proid,ks) upsert，改规格不丢已存 SKU（ks 匹配），差集删旧行
        $this->saveGuige($aid, (int) $goods['id'], $data);
    }

    /**
     * 商品详情（admin 编辑回显用：基础字段 + specs 规格组 + SKU 列表）
     *
     * @return array<string, mixed>
     */
    public function goodsDetail(int $aid, int $id): array
    {
        $goods = $this->findGoods($aid, $id);

        return [
            'id'           => (int) $goods['id'],
            'name'         => (string) ($goods['name'] ?? ''),
            'category_id'  => (int) ($goods['cid'] ?? 0),
            'price'        => (float) ($goods['sell_price'] ?? 0),
            'market_price' => (float) ($goods['market_price'] ?? 0),
            'cost_price'   => (float) ($goods['cost_price'] ?? 0),
            'stock'        => (int) ($goods['stock'] ?? 0),
            'image'        => (string) ($goods['pic'] ?? ''),
            'images'       => self::normalizePics((string) ($goods['pics'] ?? '')),
            'detail'       => (string) ($goods['detail'] ?? ''),
            'description'  => (string) ($goods['description'] ?? ''),
            'sort'         => (int) ($goods['sort'] ?? 0),
            'status'       => (int) ($goods['status'] ?? 0),
            'procode'      => (string) ($goods['procode'] ?? ''),
            'print_name'   => (string) ($goods['print_name'] ?? ''),
            'barcode'      => (string) ($goods['barcode'] ?? ''),
            'sellpoint'    => (string) ($goods['sellpoint'] ?? ''),
            'video'        => (string) ($goods['video'] ?? ''),
            'weight'       => (int) ($goods['weight'] ?? 0),
            'givescore'    => (int) ($goods['givescore'] ?? 0),
            'givescore_time' => (int) ($goods['givescore_time'] ?? 0),
            'cids'         => self::splitIntList((string) ($goods['cid'] ?? '')),
            'gids'         => self::splitIntList((string) ($goods['gid'] ?? '')),
            'fwids'        => self::splitIntList((string) ($goods['fwid'] ?? '')),
            'showtjs'      => self::splitStrList((string) ($goods['showtj'] ?? '-1')),
            'gettjs'       => self::splitStrList((string) ($goods['gettj'] ?? '-1')),
            'price_type'   => (int) ($goods['price_type'] ?? 0),
            'no_discount'  => (int) ($goods['no_discount'] ?? -1),
            'freighttype'  => (int) ($goods['freighttype'] ?? 1),
            'freightdata'  => (string) ($goods['freightdata'] ?? ''),
            'contact_require' => (int) ($goods['contact_require'] ?? 0),
            'freightcontent' => (string) ($goods['freightcontent'] ?? ''),
            'commissionset' => (int) ($goods['commissionset'] ?? 0),
            'commissiondata1' => self::parseParamData((string) ($goods['commissiondata1'] ?? '')),
            'commissiondata2' => self::parseParamData((string) ($goods['commissiondata2'] ?? '')),
            'commissiondata3' => self::parseParamData((string) ($goods['commissiondata3'] ?? '')),
            'scoredkmaxset' => (int) ($goods['scoredkmaxset'] ?? 0),
            'scoredkmaxval' => (float) ($goods['scoredkmaxval'] ?? 0),
            'feepercent'   => ($goods['feepercent'] ?? null) === null ? null : (float) $goods['feepercent'],
            'sales'        => (int) ($goods['sales'] ?? 0),
            'limit_start'  => (int) ($goods['limit_start'] ?? 0),
            'perlimit'     => (int) ($goods['perlimit'] ?? 0),
            'perlimitdan'  => (int) ($goods['perlimitdan'] ?? 0),
            'lvprice'      => (int) ($goods['lvprice'] ?? 0),
            'lvprice_data' => self::parseParamData((string) ($goods['lvprice_data'] ?? '')),
            'start_hours'  => (string) ($goods['start_hours'] ?? ''),
            'end_hours'    => (string) ($goods['end_hours'] ?? ''),
            'start_time'   => (string) ($goods['start_time'] ?? ''),
            'end_time'     => (string) ($goods['end_time'] ?? ''),
            'paramdata'    => self::parseParamData((string) ($goods['paramdata'] ?? '')),
            'sharetitle'   => (string) ($goods['sharetitle'] ?? ''),
            'sharepic'     => (string) ($goods['sharepic'] ?? ''),
            'sharedesc'    => (string) ($goods['sharedesc'] ?? ''),
            'specs'        => self::parseSpecs((string) ($goods['guigedata'] ?? '')),
            'skus'         => $this->skuRows($aid, (int) $goods['id']),
        ];
    }

    /**
     * SKU 行列表（shop_guige，按 id 升序）
     *
     * @return array<int, array<string, mixed>>
     */
    public function skuRows(int $aid, int $proid): array
    {
        $rows = Db::name('shop_guige')
            ->where('aid', $aid)
            ->where('proid', $proid)
            ->order('id', 'asc')
            ->select()
            ->toArray();

        return array_map(static fn (array $row): array => [
            'id'           => (int) $row['id'],
            'ks'           => (string) ($row['ks'] ?? ''),
            'name'         => (string) ($row['name'] ?? ''),
            'pic'          => (string) ($row['pic'] ?? ''),
            'market_price' => (float) ($row['market_price'] ?? 0),
            'cost_price'   => (float) ($row['cost_price'] ?? 0),
            'sell_price'   => (float) ($row['sell_price'] ?? 0),
            'weight'       => (int) ($row['weight'] ?? 0),
            'stock'        => (int) ($row['stock'] ?? 0),
            'procode'      => (string) ($row['procode'] ?? ''),
            'barcode'      => (string) ($row['barcode'] ?? ''),
            'sales'        => (int) ($row['sales'] ?? 0),
            'givescore'    => (int) ($row['givescore'] ?? 0),
            'limit_start'  => (int) ($row['limit_start'] ?? 0),
        ], $rows);
    }

    /**
     * Excel 库存导入（对标参考系统 ShopKucundr：商品编码 + 规格名 + 库存）
     *
     * 列定位：默认同参考系统模板 B=商品编码 E=规格名 F=库存；
     *           首行含「编码/规格/库存」关键字时按表头自动定位
     * 容错：规格名找不到对应 SKU 时回退第一条 SKU；商品无 SKU 行时直接改商品库存
     *
     * @return array{updated: int, missed: int}
     */
    public function stockImport(int $aid, string $filePath): array
    {
        $rows = ExcelHelper::readRows($filePath);

        if (empty($rows)) {
            throw new BusinessException('Excel 内容为空');
        }

        // 列定位（默认同参考系统口径）
        $procodeCol = 1;
        $ggnameCol  = 4;
        $stockCol   = 5;
        $startRow   = 0;

        $headerText = implode(',', array_map(static fn ($c) => (string) $c, $rows[0]));

        if (str_contains($headerText, '编码') || str_contains($headerText, '库存')) {
            foreach ($rows[0] as $ci => $cell) {
                $text = trim((string) $cell);

                if ('' === $text) {
                    continue;
                }

                if (str_contains($text, '编码') || false !== stripos($text, 'procode')) {
                    $procodeCol = $ci;
                } elseif (str_contains($text, '规格')) {
                    $ggnameCol = $ci;
                } elseif (str_contains($text, '库存')) {
                    $stockCol = $ci;
                }
            }

            $startRow = 1;
        }

        $updated = 0;
        $missed  = 0;

        foreach ($rows as $ri => $data) {
            if ($ri < $startRow) {
                continue;
            }

            $procode = trim((string) ($data[$procodeCol] ?? ''));
            if ('' === $procode) {
                continue;
            }

            $ggname  = trim((string) ($data[$ggnameCol] ?? ''));
            $ggstock = max(0, (int) trim((string) ($data[$stockCol] ?? '0')));

            $product = ScopeService::query('shop_product', $aid)
                ->where('procode', $procode)
                ->where('deleted', 0)
                ->find();

            if (empty($product)) {
                ++$missed;
                continue;
            }

            $proid = (int) $product['id'];

            // 定位 SKU：按规格名 → 找不到回退第一条 SKU → 无 SKU 行直接改商品库存
            $guige = null;

            if ('' !== $ggname) {
                $guige = Db::name('shop_guige')
                    ->where('aid', $aid)
                    ->where('proid', $proid)
                    ->where('name', $ggname)
                    ->find();
            }

            if (empty($guige)) {
                $guige = Db::name('shop_guige')
                    ->where('aid', $aid)
                    ->where('proid', $proid)
                    ->order('id', 'asc')
                    ->find();
            }

            if (empty($guige)) {
                // 单规格商品：无 SKU 行，直接更新商品库存
                Db::name('shop_product')->where('id', $proid)->update(['stock' => $ggstock]);
                ++$updated;
                continue;
            }

            Db::name('shop_guige')->where('id', (int) $guige['id'])->update(['stock' => $ggstock]);

            // 汇总回写商品主表库存
            $proStock = (int) Db::name('shop_guige')
                ->where('aid', $aid)
                ->where('proid', $proid)
                ->sum('stock');

            Db::name('shop_product')->where('id', $proid)->update(['stock' => $proStock]);

            ++$updated;
        }

        return ['updated' => $updated, 'missed' => $missed];
    }

    /**
     * 删除商品（软删；存在未完结订单时拒绝）。支持单个 id 或 ids 数组批量
     *
     * @param array $data {id}|{ids:[]}
     */
    public function goodsDelete(int $aid, array $data): void
    {
        $ids = $this->resolveIds($data);
        foreach ($ids as $id) {
            $goods = $this->findGoods($aid, $id);

            // 未完结订单：shop_order.status ∈ {0未支付,1已支付,2已发货}（3已收货/4关闭 视为完结）
            $unfinished = (int) Db::name('shop_order_goods')
                ->alias('og')
                ->join('shop_order o', 'o.id = og.orderid')
                ->where('og.aid', $aid)
                ->where('og.proid', $goods['id'])
                ->whereIn('o.status', [0, 1, 2])
                ->count();

            if ($unfinished > 0) {
                throw new BusinessException('商品「' . $goods['name'] . '」存在未完结订单，暂不能删除', 1);
            }

            Db::name('shop_product')->where('id', $goods['id'])->update([
                'deleted'    => 1,
                'deletetime' => time(),
            ]);
        }
    }

    /**
     * 商品上下架。支持单个 id 或 ids 数组批量
     *
     * @param array $data {id, status}|{ids:[], status}
     */
    public function goodsSetStatus(int $aid, array $data): void
    {
        $ids = $this->resolveIds($data);

        $status = (int) ($data['status'] ?? -1);
        if ($status !== self::STATUS_OFF && $status !== self::STATUS_ON) {
            throw new BusinessException('状态值只允许 0 或 1', 1);
        }

        Db::name('shop_product')
            ->where('aid', $aid)
            ->whereIn('id', $ids)
            ->update(['status' => $status]);
    }

    /**
     * 复制商品（复制基础信息，名称追加“_副本”，状态置为下架）
     *
     * @return array{id: int}
     */
    public function goodsCopy(int $aid, int $id): array
    {
        $goods = $this->findGoods($aid, $id);
        unset($goods['id'], $goods['createtime']);
        $goods['name'] = $goods['name'] . '_副本';
        $goods['status'] = self::STATUS_OFF;
        $goods['sales'] = 0;
        $newId = (int) Db::name('shop_product')->insertGetId($goods);

        return ['id' => $newId];
    }

    /**
     * 修改商品价格（销售价/市场价）
     */
    public function goodsChangePrice(int $aid, array $data): void
    {
        $goods = $this->findGoods($aid, (int) ($data['id'] ?? 0));
        $update = [];
        if (isset($data['sell_price'])) {
            $update['sell_price'] = round((float) $data['sell_price'], 2);
        }
        if (isset($data['market_price'])) {
            $update['market_price'] = round((float) $data['market_price'], 2);
        }
        if (empty($update)) {
            throw new BusinessException('未提交需要修改的价格', 1);
        }
        Db::name('shop_product')->where('id', $goods['id'])->update($update);
    }

    /**
     * 从请求数据中解析商品 ID 列表（兼容单个 id 与 ids 数组）
     *
     * @return int[]
     */
    protected function resolveIds(array $data): array
    {
        $ids = [];
        if (!empty($data['ids']) && is_array($data['ids'])) {
            foreach ($data['ids'] as $v) {
                $iv = (int) $v;
                if ($iv > 0) {
                    $ids[] = $iv;
                }
            }
        }
        if (empty($ids) && !empty($data['id'])) {
            $ids[] = (int) $data['id'];
        }
        $ids = array_values(array_unique($ids));
        if (empty($ids)) {
            throw new BusinessException('请选择商品', 1);
        }

        return $ids;
    }

    // ================= api 侧（客户端，仅上架未删） =================

    /**
     * 客户端分类列表：仅返回有上架商品的分类（含 goods_count）
     *
     * @return array{list: array}
     */
    public function apiCategoryList(int $aid): array
    {
        // 上架商品按 cid 计数
        $counts = [];
        $rows   = ScopeService::query('shop_product', $aid)
            ->where('deleted', 0)
            ->where('status', self::STATUS_ON)
            ->column('cid');

        foreach ($rows as $cid) {
            foreach (array_filter(explode(',', (string) $cid)) as $one) {
                $cidInt          = (int) $one;
                $counts[$cidInt] = ($counts[$cidInt] ?? 0) + 1;
            }
        }

        if (empty($counts)) {
            return ['list' => []];
        }

        $categories = ScopeService::query('shop_category', $aid)
            ->where('deleted', 0)
            ->whereIn('id', array_keys($counts))
            ->order('sort', 'desc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        $list = array_map(static fn (array $row): array => [
            'id'          => (int) $row['id'],
            'name'        => (string) ($row['name'] ?? ''),
            'pid'         => (int) ($row['pid'] ?? 0),
            'sort'        => (int) ($row['sort'] ?? 0),
            'goods_count' => $counts[(int) $row['id']] ?? 0,
        ], $categories);

        return ['list' => $list];
    }

    /**
     * 客户端商品列表（仅上架未删，sort/createtime 倒序）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function apiGoodsList(int $aid, int $page, int $pagesize, string $keyword, string $categoryId): array
    {
        $query = ScopeService::query('shop_product', $aid)
            ->where('deleted', 0)
            ->where('status', self::STATUS_ON);

        $keyword = trim($keyword);
        if ('' !== $keyword) {
            $query->whereLike('name', '%' . $keyword . '%');
        }

        $categoryId = (int) $categoryId;
        if ($categoryId > 0) {
            $query->whereRaw("FIND_IN_SET({$categoryId}, cid)");
        }

        $total = (clone $query)->count();

        $rows = $query->order('sort', 'desc')
            ->order('createtime', 'desc')
            ->order('id', 'desc')
            ->page($page, $pagesize)
            ->select()
            ->toArray();

        $list = array_map(static fn (array $row): array => [
            'id'           => (int) $row['id'],
            'name'         => (string) ($row['name'] ?? ''),
            'image'        => (string) ($row['pic'] ?? ''),
            'price'        => (float) ($row['sell_price'] ?? 0),
            'market_price' => (float) ($row['market_price'] ?? 0),
            'stock'        => (int) ($row['stock'] ?? 0),
            'sales'        => (int) ($row['sales'] ?? 0),
            'create_time'  => (int) ($row['createtime'] ?? 0) > 0 ? date('Y-m-d H:i:s', (int) $row['createtime']) : '',
        ], $rows);

        return ['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pagesize];
    }

    /**
     * 客户端商品详情（下架/删除返回业务错误）
     *
     * @return array{id, name, image, images, price, market_price, stock, category_name, detail}
     */
    public function apiGoodsDetail(int $aid, int $id): array
    {
        $goods = ScopeService::query('shop_product', $aid)
            ->where('id', $id)
            ->where('deleted', 0)
            ->find();

        if (empty($goods)) {
            throw new BusinessException('商品不存在', 404, 404);
        }

        if ((int) $goods['status'] !== self::STATUS_ON) {
            throw new BusinessException('商品已下架', 1);
        }

        $categoryName = '';
        $cid          = (int) ($goods['cid'] ?? 0);
        if ($cid > 0) {
            $categoryName = (string) (ScopeService::query('shop_category', $aid)
                ->where('id', $cid)
                ->where('deleted', 0)
                ->value('name') ?? '');
        }

        return [
            'id'            => (int) $goods['id'],
            'name'          => (string) ($goods['name'] ?? ''),
            'image'         => (string) ($goods['pic'] ?? ''),
            'images'        => self::picsToArray((string) ($goods['pics'] ?? ''), (string) ($goods['pic'] ?? '')),
            'price'         => (float) ($goods['sell_price'] ?? 0),
            'market_price'  => (float) ($goods['market_price'] ?? 0),
            'stock'         => (int) ($goods['stock'] ?? 0),
            'category_name' => $categoryName,
            'detail'        => (string) ($goods['detail'] ?? ''),
            'description'   => (string) ($goods['description'] ?? ''),
            'specs'         => self::parseSpecs((string) ($goods['guigedata'] ?? '')),
            'skus'          => $this->skuRows($aid, (int) $goods['id']),
        ];
    }

    // ================= 内部工具 =================

    /**
     * 组装商品写入数据（新增/编辑共用，校验在入口处）
     */
    protected function buildGoodsData(array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ('' === $name) {
            throw new BusinessException('商品名称不能为空', 1);
        }

        $price      = $this->normalizePrice($data['price'] ?? null, 'price');
        $marketPrice = $this->normalizePrice($data['market_price'] ?? $price, 'market_price');

        $stock = (int) ($data['stock'] ?? 0);
        if ($stock < 0) {
            throw new BusinessException('库存不能为负数', 1);
        }

                // 状态：1已上架 0未上架 2设置上架时间 3设置上架周期
        $status = (int) ($data['status'] ?? self::STATUS_OFF);
        if (!in_array($status, [0, 1, 2, 3], true)) {
            throw new BusinessException('状态值非法', 1);
        }

        // images：逗号分隔的图片URL字符串（与旧表语义一致）；数组入参容错转逗号分隔
        $images = $data['images'] ?? '';
        if (is_array($images)) {
            $images = implode(',', array_map('strval', $images));
        }

        // 分类：cid 多选优先，否则取主分类 category_id（统一逗号串）；分组/服务/显示购买条件同理
        $cid    = $this->joinList($data['cid'] ?? null, $data['category_id'] ?? 0);
        $gid    = $this->joinList($data['gid'] ?? null, '');
        $fwid   = $this->joinList($data['fwid'] ?? null, '');
        $showtj = $this->joinList($data['showtj'] ?? null, '-1');
        $gettj  = $this->joinList($data['gettj'] ?? null, '-1');

        // 商品参数 paramdata：数组/对象转 JSON，字符串原样保留
        $paramdata = $data['paramdata'] ?? '';
        if (is_array($paramdata)) {
            $paramdata = json_encode($paramdata, JSON_UNESCAPED_UNICODE);
        }

        // 抽成费率可空（留空=用商户统一费率）
        $feepercentRaw = trim((string) ($data['feepercent'] ?? ''));
        $feepercent    = '' === $feepercentRaw ? null : (float) $feepercentRaw;

        // 会员等级折扣：-1跟随系统 0参与 1不参与
        $noDiscount = (int) ($data['no_discount'] ?? -1);
        if (!in_array($noDiscount, [-1, 0, 1], true)) {
            $noDiscount = -1;
        }

        return [
            'name'           => $name,
            'print_name'     => trim((string) ($data['print_name'] ?? '')),
            'cid'            => $cid,
            'gid'            => $gid,
            'fwid'           => $fwid,
            'procode'        => trim((string) ($data['procode'] ?? '')),
            'barcode'        => trim((string) ($data['barcode'] ?? '')),
            'sellpoint'      => trim((string) ($data['sellpoint'] ?? '')),
            'video'          => trim((string) ($data['video'] ?? '')),
            'sell_price'     => $price,
            'market_price'   => $marketPrice,
            'cost_price'     => $this->softPrice($data['cost_price'] ?? 0),
            'weight'         => max(0, (int) ($data['weight'] ?? 0)),
            'givescore'      => max(0, (int) ($data['givescore'] ?? 0)),
            'givescore_time' => (int) ($data['givescore_time'] ?? 0) === 1 ? 1 : 0,
            'stock'          => $stock,
            'pic'            => trim((string) ($data['image'] ?? '')),
            'pics'           => trim((string) $images),
            'price_type'     => (int) ($data['price_type'] ?? 0) === 1 ? 1 : 0,
            'no_discount'    => $noDiscount,
            'freighttype'    => (int) ($data['freighttype'] ?? 1),
            'freightdata'    => trim((string) ($data['freightdata'] ?? '')),
            'contact_require' => (int) ($data['contact_require'] ?? 0) === 1 ? 1 : 0,
            'freightcontent' => trim((string) ($data['freightcontent'] ?? '')),
            'commissionset'  => (int) ($data['commissionset'] ?? 0),
            'commissiondata1' => is_array($data['commissiondata1'] ?? null) ? json_encode($data['commissiondata1'], JSON_UNESCAPED_UNICODE) : '',
            'commissiondata2' => is_array($data['commissiondata2'] ?? null) ? json_encode($data['commissiondata2'], JSON_UNESCAPED_UNICODE) : '',
            'commissiondata3' => is_array($data['commissiondata3'] ?? null) ? json_encode($data['commissiondata3'], JSON_UNESCAPED_UNICODE) : '',
            'scoredkmaxset'  => (int) ($data['scoredkmaxset'] ?? 0),
            'scoredkmaxval'  => $this->softPrice($data['scoredkmaxval'] ?? 0),
            'feepercent'     => $feepercent,
            'sales'          => max(0, (int) ($data['sales'] ?? 0)),
            'limit_start'    => max(0, (int) ($data['limit_start'] ?? 0)),
            'perlimit'       => max(0, (int) ($data['perlimit'] ?? 0)),
            'perlimitdan'    => max(0, (int) ($data['perlimitdan'] ?? 0)),
            'sort'           => (int) ($data['sort'] ?? 0),
            'showtj'         => $showtj,
            'gettj'          => $gettj,
            'status'         => $status,
            'start_hours'    => trim((string) ($data['start_hours'] ?? '')),
            'end_hours'      => trim((string) ($data['end_hours'] ?? '')),
            'start_time'     => trim((string) ($data['start_time'] ?? '')),
            'end_time'       => trim((string) ($data['end_time'] ?? '')),
            'lvprice'        => (int) ($data['lvprice'] ?? 0) === 1 ? 1 : 0,
            'lvprice_data'   => is_array($data['lvprice_data'] ?? null)
                ? json_encode($data['lvprice_data'], JSON_UNESCAPED_UNICODE)
                : trim((string) ($data['lvprice_data'] ?? '')),
            'paramdata'      => (string) $paramdata,
            'detail'         => (string) ($data['detail'] ?? ''),
            'description'    => (string) ($data['description'] ?? ''),
            'sharetitle'     => trim((string) ($data['sharetitle'] ?? '')),
            'sharepic'       => trim((string) ($data['sharepic'] ?? '')),
            'sharedesc'      => trim((string) ($data['sharedesc'] ?? '')),
        ];
    }

    /**
     * 数组 / 逗号串归一为逗号分隔字符串；结果为空时回退默认值
     */
    protected function joinList(mixed $value, mixed $default = ''): string
    {
        if (is_array($value)) {
            $list = array_filter(array_map(static fn ($v) => trim((string) $v), $value), static fn ($v) => $v !== '');
            return implode(',', $list);
        }
        $s = trim((string) ($value ?? ''));
        return '' === $s ? (string) $default : $s;
    }

    /**
     * 宽松金额：空值 / 非数字按 0，不抛错（用于可空的成本价、积分抵扣值等）
     */
    protected function softPrice(mixed $value): float
    {
        $s = trim((string) $value);
        return ('' === $s || !is_numeric($s)) ? 0.0 : (float) $s;
    }

    /**
     * 多规格 SKU 保存（对标参考系统 ShopProduct save 规格段）
     *
     * 入参：specs=[{title, items:[{title, ggpic_wholesale}]}]（组/值索引 k 由后端按顺序重编）
     *       options={ "0,1": {name,pic,market_price,cost_price,sell_price,stock,weight,procode,barcode,givescore,limit_start} }
     *
     * 逻辑：按(aid,proid,ks) upsert shop_guige → 差集删除旧行 → SKU stock 汇总回写 shop_product.stock
     *       specs 为空视为单规格：删除该商品全部 SKU 行，库存走商品表单值
     */
    protected function saveGuige(int $aid, int $proid, array $data): void
    {
        $specs = $data['specs'] ?? [];
        if (!is_array($specs)) {
            $specs = [];
        }

        // 规范化规格组：过滤空组/空值，重编 k 索引（与前端笛卡尔积 ks 口径一致）
        $groups = [];
        foreach ($specs as $group) {
            if (!is_array($group)) {
                continue;
            }

            $title = trim((string) ($group['title'] ?? ''));
            if ('' === $title) {
                continue;
            }

            $items = [];
            foreach (($group['items'] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $itemTitle = trim((string) ($item['title'] ?? ''));
                if ('' === $itemTitle) {
                    continue;
                }

                $items[] = [
                    'title'           => $itemTitle,
                    'k'               => (string) count($items),
                    'ggpic_wholesale' => trim((string) ($item['ggpic_wholesale'] ?? $item['pic'] ?? '')),
                ];
            }

            if (empty($items)) {
                continue;
            }

            $groups[] = [
                'title' => $title,
                'k'     => (string) count($groups),
                'items' => $items,
            ];
        }

        if (empty($groups)) {
            // 单规格：清空历史 SKU，guigedata 置空，库存以商品表单为准
            Db::name('shop_guige')->where('aid', $aid)->where('proid', $proid)->delete();
            Db::name('shop_product')->where('id', $proid)->update(['guigedata' => '']);

            return;
        }

        $options = $data['options'] ?? [];
        if (!is_array($options)) {
            $options = [];
        }

        $newIds     = [];
        $totalStock = 0;

        foreach ($options as $ks => $v) {
            if (!is_array($v)) {
                continue;
            }

            $ks    = (string) $ks;
            $stock = max(0, (int) ($v['stock'] ?? 0));

            $totalStock += $stock;

            $row = [
                'proid'        => $proid,
                'ks'           => $ks,
                'name'         => trim((string) ($v['name'] ?? '')),
                'pic'          => trim((string) ($v['pic'] ?? '')),
                'market_price' => $this->normalizePrice($v['market_price'] ?? '0', 'market_price'),
                'cost_price'   => $this->normalizePrice($v['cost_price'] ?? '0', 'cost_price'),
                'sell_price'   => $this->normalizePrice($v['sell_price'] ?? '0', 'sell_price'),
                'weight'       => max(0, (int) ($v['weight'] ?? 0)),
                'stock'        => $stock,
                'procode'      => trim((string) ($v['procode'] ?? '')),
                'barcode'      => trim((string) ($v['barcode'] ?? '')),
                'givescore'    => max(0, (int) ($v['givescore'] ?? 0)),
                'limit_start'  => max(0, (int) ($v['limit_start'] ?? 0)),
            ];

            $exist = Db::name('shop_guige')
                ->where('aid', $aid)
                ->where('proid', $proid)
                ->where('ks', $ks)
                ->find();

            if (!empty($exist)) {
                Db::name('shop_guige')->where('id', (int) $exist['id'])->update($row);
                $newIds[] = (int) $exist['id'];
            } else {
                $row['aid'] = $aid;
                $newIds[]   = (int) Db::name('shop_guige')->insertGetId($row);
            }
        }

        // 差集删除：本次 ks 集合之外的旧 SKU 行
        $query = Db::name('shop_guige')->where('aid', $aid)->where('proid', $proid);
        if (!empty($newIds)) {
            $query->where('id', 'not in', $newIds);
        }
        $query->delete();

        // stock 汇总回写商品主表 + guigedata 快照
        Db::name('shop_product')->where('id', $proid)->update([
            'guigedata' => json_encode($groups, JSON_UNESCAPED_UNICODE),
            'stock'     => $totalStock,
        ]);
    }

    /**
     * guigedata JSON 解析（容错：非法/空返回 []）
     *
     * @return array<int, array<string, mixed>>
     */
    public static function parseSpecs(string $guigedata): array
    {
        $guigedata = trim($guigedata);

        if ('' === $guigedata) {
            return [];
        }

        $decoded = json_decode($guigedata, true);

                return is_array($decoded) ? $decoded : [];
    }

    /**
     * 逗号分隔的 ID 串转 int 数组（过滤非正整数）
     *
     * @return int[]
     */
    public static function splitIntList(string $str): array
    {
        $str = trim($str);
        if ('' === $str) {
            return [];
        }
        $out = [];
        foreach (explode(',', $str) as $v) {
            $v = trim($v);
            if ('' !== $v && (int) $v > 0) {
                $out[] = (int) $v;
            }
        }
        return $out;
    }

    /**
     * 逗号分隔串转字符串数组（保留 0 / 负数，用于显示、购买条件）
     *
     * @return string[]
     */
    public static function splitStrList(string $str): array
    {
        $str = trim($str);
        if ('' === $str) {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $str)), static fn ($v) => $v !== ''));
    }

    /**
     * 商品参数 JSON 解析为关联数组（容错：非法 / 空返回 []）
     *
     * @return array<string, mixed>
     */
    public static function parseParamData(string $str): array
    {
        $str = trim($str);
        if ('' === $str) {
            return [];
        }
        $decoded = json_decode($str, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 价格规范化：单位「元」，必须为数字且至多两位小数
     */
    protected function normalizePrice(mixed $value, string $field): float
    {
        $str = trim((string) $value);

        if ('' === $str || !preg_match('/^\d+(\.\d{1,2})?$/', $str)) {
            throw new BusinessException($field . ' 必须为金额且至多两位小数', 1);
        }

        return (float) $str;
    }

    /**
     * 查找未删分类，不存在抛业务错误
     */
    /**
     * 判断 $candidateId 是否为 $nodeId 的子孙（沿 pid 向上遇到 nodeId 即是）
     */
    protected function isDescendantCategory(int $aid, int $nodeId, int $candidateId): bool
    {
        $cur = $candidateId;
        for ($i = 0; $i < 20 && $cur > 0; $i++) {
            if ($cur === $nodeId) {
                return true;
            }
            $row = ScopeService::query('shop_category', $aid)->where('id', $cur)->where('deleted', 0)->field('pid')->find();
            if (empty($row)) {
                return false;
            }
            $cur = (int) $row['pid'];
        }
        return false;
    }

    protected function findCategory(int $aid, int $id): array
    {
        if ($id <= 0) {
            throw new BusinessException('请选择商品分类', 1);
        }

        $category = ScopeService::query('shop_category', $aid)
            ->where('id', $id)
            ->where('deleted', 0)
            ->find();

        if (empty($category)) {
            throw new BusinessException('商品分类不存在', 404, 404);
        }

        return $category;
    }

    /**
     * 查找未删商品，不存在抛业务错误
     */
    protected function findGoods(int $aid, int $id): array
    {
        if ($id <= 0) {
            throw new BusinessException('缺少商品ID', 1);
        }

        $goods = ScopeService::query('shop_product', $aid)
            ->where('id', $id)
            ->where('deleted', 0)
            ->find();

        if (empty($goods)) {
            throw new BusinessException('商品不存在', 404, 404);
        }

        return $goods;
    }

    /**
     * 图集归一化：统一返回逗号分隔字符串；遇早期 JSON 数组格式做一次转换
     */
    public static function normalizePics(string $pics): string
    {
        $pics = trim($pics);

        if ('' === $pics) {
            return '';
        }

        if (str_starts_with($pics, '[')) {
            $decoded = json_decode($pics, true);

            if (is_array($decoded)) {
                return implode(',', array_map('strval', $decoded));
            }
        }

        return $pics;
    }

    /**
     * 取首图 URL（逗号分隔 pics 的第一张，为空时回退 pic 主图）
     */
    public static function firstPic(string $pics, string $fallback = ''): string
    {
        $normalized = self::normalizePics($pics);
        $first      = trim(explode(',', $normalized)[0] ?? '');

        return '' !== $first ? $first : trim($fallback);
    }

    /**
     * Convert stored album pictures (JSON array or comma string) into a URL array
     * for the client goods-detail API. Falls back to the main picture when empty.
     */
    public static function picsToArray(string $pics, string $fallback = ''): array
    {
        $normalized = self::normalizePics($pics);
        $arr = [];
        if ('' !== $normalized) {
            foreach (explode(',', $normalized) as $url) {
                $url = trim($url);
                if ('' !== $url) {
                    $arr[] = $url;
                }
            }
        }
        if (!$arr && '' !== trim($fallback)) {
            $arr[] = trim($fallback);
        }
        return array_values(array_unique($arr));
    }
}