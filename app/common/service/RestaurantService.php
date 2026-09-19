<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;

/**
 * 餐饮堂食服务（P6 行业扩展第二期 · 餐饮一期堂食）
 *
 * 表复用（旧表补列，不改既有列语义）：
 * - restaurant_area              区域：name/sort(补列)/status
 * - restaurant_table             桌台：cid=区域ID seat=人数 qrcode=二维码 status/sort
 * - restaurant_product_category  菜品分类
 * - restaurant_product           菜品：cid=分类ID(varchar) sell_price=价格 detail=描述 is_delete=软删(补列)
 * - restaurant_shop_order        堂食订单：ordernum=订单号 tableid=桌台 product_price=商品总额
 *                                 coupon_money/pay_amount/score_money(补列) scoredkscore=抵扣积分
 * - restaurant_shop_order_goods  订单商品行：proid/name/pic/num/sell_price/totalprice
 *
 * 订单状态机（代码层统一口径）：0待支付 1已支付(待出餐) 2已完成 4已取消 5已退款
 *
 * 桌台占用（查询推导，不落桌台状态列）：存在 status IN(0,1) 订单的桌台标 busy=1；
 * 取消/完成/退款后自动释放。
 *
 * 券/积分叠加：券门槛按商品总额，积分抵扣走既有管道，实付下限 0.01
 * 支付/回调：pay_order 统一支付单（PayService::payBiz/onPaid 联动，order_type=restaurant）
 */
class RestaurantService
{
    /** 订单状态 */
    public const STATUS_UNPAID    = 0;
    public const STATUS_PAID      = 1;
    public const STATUS_FINISHED  = 2;
    public const STATUS_CANCELLED = 4;
    public const STATUS_REFUNDED  = 5;

    public const STATUS_TEXT = [
        self::STATUS_UNPAID    => '待支付',
        self::STATUS_PAID      => '已支付',
        self::STATUS_FINISHED  => '已完成',
        self::STATUS_CANCELLED => '已取消',
        self::STATUS_REFUNDED  => '已退款',
    ];

    public function __construct(protected CouponService $couponService, protected ScoreService $scoreService)
    {
    }

    /* ================= admin：区域与桌台 ================= */

    /**
     * 区域列表（全量）
     *
     * @return array{list: array}
     */
    public function areaList(int $aid): array
    {
        $rows = ScopeService::query('restaurant_area', $aid)
            ->where('status', 1)
            ->order('sort', 'desc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        return ['list' => array_map(fn (array $row) => $this->formatArea($row), $rows)];
    }

    /**
     * 新建区域 {name,sort}
     *
     * @param array<string, mixed> $data
     * @return array{id: int}
     */
    public function areaAdd(int $aid, array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));

        if ('' === $name) {
            throw new BusinessException('请填写区域名称');
        }

        $id = (int) Db::name('restaurant_area')->insertGetId([
            'aid'        => $aid,
            'bid'        => 0,
            'name'       => $name,
            'sort'       => (int) ($data['sort'] ?? 0),
            'status'     => 1,
            'createtime' => time(),
        ]);

        return ['id' => $id];
    }

    /**
     * 编辑区域（空值视为不修改）
     *
     * @param array<string, mixed> $data
     */
    public function areaEdit(int $aid, array $data): void
    {
        $area = $this->findArea($aid, (int) ($data['id'] ?? 0));

        $update = [];

        if ($this->hasValue($data, 'name')) {
            $name = trim((string) $data['name']);

            if ('' === $name) {
                throw new BusinessException('请填写区域名称');
            }
            $update['name'] = $name;
        }

        if ($this->hasValue($data, 'sort')) {
            $update['sort'] = (int) $data['sort'];
        }

        if (!empty($update)) {
            Db::name('restaurant_area')->where('id', (int) $area['id'])->update($update);
        }
    }

    /**
     * 删除区域（区域下有桌台拒绝）
     */
    public function areaDelete(int $aid, int $id): void
    {
        $area = $this->findArea($aid, $id);

        $tableCount = ScopeService::query('restaurant_table', $aid)
            ->where('cid', $id)
            ->count();

        if ($tableCount > 0) {
            throw new BusinessException('该区域下有桌台，不能删除');
        }

        Db::name('restaurant_area')->where('id', (int) $area['id'])->delete();
    }

    /**
     * 桌台列表（area_id 筛选；busy 由未完结订单推导）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function tableList(int $aid, int $areaId, int $page, int $pageSize): array
    {
        $query = ScopeService::queryAs('restaurant_table', $aid, 't')
            ->leftJoin('restaurant_area a', 'a.id = t.cid')
            ->field('t.*, a.name as area_name');

        if ($areaId > 0) {
            $query->where('t.cid', $areaId);
        }

        $total = (clone $query)->count();

        $rows = $query->order('t.sort', 'desc')
            ->order('t.id', 'asc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        $busyIds = $this->busyTableIds($aid, array_map(static fn (array $r) => (int) $r['id'], $rows));

        return [
            'list'     => array_map(fn (array $row) => $this->formatTable($row, in_array((int) $row['id'], $busyIds, true)), $rows),
            'total'    => $total,
            'page'     => $page,
            'pagesize' => $pageSize,
        ];
    }

    /**
     * 新建桌台 {area_id,name,persons,sort}
     *
     * @param array<string, mixed> $data
     * @return array{id: int}
     */
    public function tableAdd(int $aid, array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));

        if ('' === $name) {
            throw new BusinessException('请填写桌号');
        }

        $areaId = (int) ($data['area_id'] ?? 0);
        $this->findArea($aid, $areaId);

        $persons = (int) ($data['persons'] ?? 0);

        if ($persons < 0) {
            throw new BusinessException('容纳人数不能为负数');
        }

        $id = (int) Db::name('restaurant_table')->insertGetId([
            'aid'         => $aid,
            'bid'         => 0,
            'cid'         => $areaId,
            'name'        => $name,
            'seat'        => $persons,
            'status'      => 0,
            'qrcode'      => '',
            'sort'        => (int) ($data['sort'] ?? 0),
            'orderid'     => 0,
            'create_time' => time(),
            'update_time' => time(),
        ]);

        return ['id' => $id];
    }

    /**
     * 编辑桌台（空值视为不修改）
     *
     * @param array<string, mixed> $data
     */
    public function tableEdit(int $aid, array $data): void
    {
        $table = $this->findTable($aid, (int) ($data['id'] ?? 0));

        $update = [];

        if ($this->hasValue($data, 'name')) {
            $name = trim((string) $data['name']);

            if ('' === $name) {
                throw new BusinessException('请填写桌号');
            }
            $update['name'] = $name;
        }

        if ($this->hasValue($data, 'area_id')) {
            $areaId = (int) $data['area_id'];
            $this->findArea($aid, $areaId);
            $update['cid'] = $areaId;
        }

        if ($this->hasValue($data, 'persons')) {
            $persons = (int) $data['persons'];

            if ($persons < 0) {
                throw new BusinessException('容纳人数不能为负数');
            }
            $update['seat'] = $persons;
        }

        if ($this->hasValue($data, 'sort')) {
            $update['sort'] = (int) $data['sort'];
        }

        if (!empty($update)) {
            $update['update_time'] = time();
            Db::name('restaurant_table')->where('id', (int) $table['id'])->update($update);
        }
    }

    /**
     * 删除桌台（有未完结订单拒绝）
     */
    public function tableDelete(int $aid, int $id): void
    {
        $table = $this->findTable($aid, $id);

        $openCount = Db::name('restaurant_shop_order')
            ->where('tableid', $id)
            ->whereIn('status', [self::STATUS_UNPAID, self::STATUS_PAID])
            ->count();

        if ($openCount > 0) {
            throw new BusinessException('该桌台有未完结订单，不能删除');
        }

        Db::name('restaurant_table')->where('id', (int) $table['id'])->delete();
    }

    /* ================= admin：菜品分类 ================= */

    /**
     * 分类列表（全量）
     *
     * @return array{list: array}
     */
    public function categoryList(int $aid): array
    {
        $rows = ScopeService::query('restaurant_product_category', $aid)
            ->order('sort', 'desc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        return ['list' => array_map(fn (array $row) => $this->formatCategory($row), $rows)];
    }

    /**
     * 新建分类
     *
     * @param array<string, mixed> $data
     * @return array{id: int}
     */
    public function categoryAdd(int $aid, array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));

        if ('' === $name) {
            throw new BusinessException('请填写分类名称');
        }

        $status = (int) ($data['status'] ?? 1);

        $id = (int) Db::name('restaurant_product_category')->insertGetId([
            'aid'         => $aid,
            'bid'         => 0,
            'pid'         => 0,
            'name'        => $name,
            'pic'         => trim((string) ($data['pic'] ?? '')),
            'sort'        => (int) ($data['sort'] ?? 1),
            'status'      => in_array($status, [0, 1], true) ? $status : 1,
            'create_time' => time(),
        ]);

        return ['id' => $id];
    }

    /**
     * 编辑分类（空值视为不修改）
     *
     * @param array<string, mixed> $data
     */
    public function categoryEdit(int $aid, array $data): void
    {
        $category = $this->findCategory($aid, (int) ($data['id'] ?? 0));

        $update = [];

        if ($this->hasValue($data, 'name')) {
            $name = trim((string) $data['name']);

            if ('' === $name) {
                throw new BusinessException('请填写分类名称');
            }
            $update['name'] = $name;
        }

        if ($this->hasValue($data, 'pic')) {
            $update['pic'] = trim((string) $data['pic']);
        }

        if ($this->hasValue($data, 'sort')) {
            $update['sort'] = (int) $data['sort'];
        }

        if ($this->hasValue($data, 'status')) {
            if (!in_array((int) $data['status'], [0, 1], true)) {
                throw new BusinessException('状态值无效');
            }
            $update['status'] = (int) $data['status'];
        }

        if (!empty($update)) {
            Db::name('restaurant_product_category')->where('id', (int) $category['id'])->update($update);
        }
    }

    /**
     * 删除分类（分类下有菜品拒绝）
     */
    public function categoryDelete(int $aid, int $id): void
    {
        $category = $this->findCategory($aid, $id);

        $productCount = ScopeService::query('restaurant_product', $aid)
            ->where('cid', (string) $id)
            ->where('is_delete', 0)
            ->count();

        if ($productCount > 0) {
            throw new BusinessException('该分类下有菜品，不能删除');
        }

        Db::name('restaurant_product_category')->where('id', (int) $category['id'])->delete();
    }

    /* ================= admin：餐饮商品 ================= */

    /**
     * 菜品列表（keyword 匹配名称；category_id/status 筛选）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function productList(int $aid, string $keyword, int $categoryId, string $status, int $page, int $pageSize): array
    {
        $query = ScopeService::query('restaurant_product', $aid)->where('is_delete', 0);

        if ('' !== $keyword) {
            $query->where('name', 'like', '%' . $keyword . '%');
        }
        if ($categoryId > 0) {
            $query->where('cid', (string) $categoryId);
        }
        if ('' !== $status) {
            $query->where('status', (int) $status);
        }

        $total = (clone $query)->count();

        $rows = $query->order('sort', 'desc')
            ->order('id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        return [
            'list'     => array_map(fn (array $row) => $this->formatProduct($row), $rows),
            'total'    => $total,
            'page'     => $page,
            'pagesize' => $pageSize,
        ];
    }

    /**
     * 新建菜品 {name,category_id,pic,price,desc,status}
     *
     * @param array<string, mixed> $data
     * @return array{id: int}
     */
    public function productAdd(int $aid, array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));

        if ('' === $name) {
            throw new BusinessException('请填写菜品名称');
        }

        $categoryId = (int) ($data['category_id'] ?? 0);
        $this->findCategory($aid, $categoryId);

        $price = $this->parsePrice($data['price'] ?? null, '价格');

        if ($price <= 0) {
            throw new BusinessException('请填写价格（大于0）');
        }

        $status = (int) ($data['status'] ?? 1);

        $id = (int) Db::name('restaurant_product')->insertGetId([
            'aid'         => $aid,
            'bid'         => 0,
            'name'        => $name,
            'cid'         => (string) $categoryId,
            'pic'         => trim((string) ($data['pic'] ?? '')),
            'sell_price'  => $price,
            'detail'      => trim((string) ($data['desc'] ?? '')),
            'status'      => in_array($status, [0, 1], true) ? $status : 1,
            'sort'        => 0,
            'create_time' => time(),
            'update_time' => time(),
            'is_delete'   => 0,
        ]);

        return ['id' => $id];
    }

    /**
     * 编辑菜品（空值视为不修改）
     *
     * @param array<string, mixed> $data
     */
    public function productEdit(int $aid, array $data): void
    {
        $product = $this->findProduct($aid, (int) ($data['id'] ?? 0));

        $update = [];

        if ($this->hasValue($data, 'name')) {
            $name = trim((string) $data['name']);

            if ('' === $name) {
                throw new BusinessException('请填写菜品名称');
            }
            $update['name'] = $name;
        }

        if ($this->hasValue($data, 'category_id')) {
            $categoryId = (int) $data['category_id'];
            $this->findCategory($aid, $categoryId);
            $update['cid'] = (string) $categoryId;
        }

        if ($this->hasValue($data, 'pic')) {
            $update['pic'] = trim((string) $data['pic']);
        }

        if ($this->hasValue($data, 'price')) {
            $price = $this->parsePrice($data['price'], '价格');

            if ($price <= 0) {
                throw new BusinessException('价格须大于0');
            }
            $update['sell_price'] = $price;
        }

        if ($this->hasValue($data, 'desc')) {
            $update['detail'] = trim((string) $data['desc']);
        }

        if ($this->hasValue($data, 'status')) {
            if (!in_array((int) $data['status'], [0, 1], true)) {
                throw new BusinessException('状态值无效');
            }
            $update['status'] = (int) $data['status'];
        }

        if (!empty($update)) {
            $update['update_time'] = time();
            Db::name('restaurant_product')->where('id', (int) $product['id'])->update($update);
        }
    }

    /**
     * 删除菜品（软删；有未完结订单拒绝）
     */
    public function productDelete(int $aid, int $id): void
    {
        $product = $this->findProduct($aid, $id);

        $openCount = Db::name('restaurant_shop_order_goods')
            ->alias('g')
            ->join('restaurant_shop_order o', 'o.id = g.orderid')
            ->where('g.proid', $id)
            ->whereIn('o.status', [self::STATUS_UNPAID, self::STATUS_PAID])
            ->count();

        if ($openCount > 0) {
            throw new BusinessException('该菜品有未完结订单，不能删除');
        }

        Db::name('restaurant_product')->where('id', (int) $product['id'])->update(['is_delete' => 1]);
    }

    /**
     * 上架/下架
     */
    public function productStatus(int $aid, int $id, int $status): void
    {
        if (!in_array($status, [0, 1], true)) {
            throw new BusinessException('状态值无效');
        }

        $product = $this->findProduct($aid, $id);

        Db::name('restaurant_product')->where('id', (int) $product['id'])->update(['status' => $status]);
    }

    /* ================= admin：堂食订单 ================= */

    /**
     * 订单列表（含 goods 数组；keyword 匹配订单号/桌号）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function adminOrderList(int $aid, string $status, string $keyword, int $page, int $pageSize): array
    {
        $query = ScopeService::queryAs('restaurant_shop_order', $aid, 'o')
            ->leftJoin('restaurant_table t', 't.id = o.tableid')
            ->field('o.*, t.name as table_name')
            ->where('o.delete', 0);

        if ('' !== $status) {
            $query->where('o.status', (int) $status);
        }

        if ('' !== $keyword) {
            $like = '%' . $keyword . '%';
            $query->where(function ($q) use ($like) {
                $q->where('o.ordernum', 'like', $like)
                    ->whereOr('t.name', 'like', $like);
            });
        }

        $total = (clone $query)->count();

        $rows = $query->order('o.id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        $goodsMap = $this->goodsMapOfOrders(array_map(static fn (array $r) => (int) $r['id'], $rows));

        return [
            'list'     => array_map(fn (array $row) => $this->formatOrder($row, $goodsMap[(int) $row['id']] ?? []), $rows),
            'total'    => $total,
            'page'     => $page,
            'pagesize' => $pageSize,
        ];
    }

    /**
     * 完成订单（已支付 → 已完成，释放桌台占用）
     */
    public function adminOrderFinish(int $aid, int $id): void
    {
        $order = $this->findOrder($aid, 0, $id);

        if (self::STATUS_PAID !== (int) $order['status']) {
            throw new BusinessException('仅已支付订单可完成');
        }

        Db::name('restaurant_shop_order')
            ->where('id', $id)
            ->where('status', self::STATUS_PAID)
            ->update(['status' => self::STATUS_FINISHED]);
    }

    /**
     * 后台取消订单（仅待支付；释放券 + 返还积分 + 释放桌台）
     */
    public function adminOrderCancel(int $aid, int $id): void
    {
        $order = $this->findOrder($aid, 0, $id);

        $this->cancelInternal($order);
    }

    /* ================= api 会员侧 ================= */

    /**
     * 店铺信息 + 区域桌台树（匿名；busy 由未完结订单推导）
     *
     * @return array{shop: array, areas: array}
     */
    public function apiInfo(int $aid): array
    {
        $areas = ScopeService::query('restaurant_area', $aid)
            ->where('status', 1)
            ->order('sort', 'desc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        $tables = ScopeService::query('restaurant_table', $aid)
            ->order('sort', 'desc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        $busyIds = $this->busyTableIds($aid, array_map(static fn (array $r) => (int) $r['id'], $tables));

        $tablesByArea = [];

        foreach ($tables as $table) {
            $tablesByArea[(int) $table['cid']][] = $this->formatTable($table, in_array((int) $table['id'], $busyIds, true));
        }

        $areaList = [];

        foreach ($areas as $area) {
            $areaList[] = [
                'id'     => (int) $area['id'],
                'name'   => (string) $area['name'],
                'tables' => $tablesByArea[(int) $area['id']] ?? [],
            ];
        }

        return [
            'shop'  => [
                'name' => TenantConfigService::get($aid, 'shop_name', '参考系统餐厅'),
            ],
            'areas' => $areaList,
        ];
    }

    /**
     * 菜品列表（匿名；分类筛选，仅上架）
     *
     * @return array{list: array}
     */
    public function apiProductList(int $aid, int $categoryId): array
    {
        $query = ScopeService::query('restaurant_product', $aid)
            ->where('status', 1)
            ->where('is_delete', 0);

        if ($categoryId > 0) {
            $query->where('cid', (string) $categoryId);
        }

        $rows = $query->order('sort', 'desc')->order('id', 'desc')->select()->toArray();

        return ['list' => array_map(fn (array $row) => $this->formatProduct($row), $rows)];
    }

    /**
     * 创建堂食订单（多商品计价 + 券积分叠加；无库存概念）
     *
     * @param array<int, array<string, mixed>> $goods [{product_id,num}]
     * @return array{id: int, order_sn: string, table_id: int, amount: float, coupon_money: float, score_money: float, pay_amount: float, use_score: int, coupon_id: int, goods: array}
     */
    public function createOrder(int $aid, int $mid, int $tableId, array $goods, int $couponId = 0, int $useScore = 0): array
    {
        if ($tableId <= 0) {
            throw new BusinessException('请选择桌台');
        }

        $table = $this->findTable($aid, $tableId);

        if (empty($goods)) {
            throw new BusinessException('请选择菜品');
        }

        // 归并同一菜品数量并校验
        $merged = [];

        foreach ($goods as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $num       = (int) ($item['num'] ?? 0);

            if ($productId <= 0) {
                throw new BusinessException('菜品ID无效');
            }
            if ($num <= 0) {
                throw new BusinessException('菜品数量须大于0');
            }

            $merged[$productId] = ($merged[$productId] ?? 0) + $num;
        }

        $productRows = [];
        $amount      = 0.0;

        foreach ($merged as $productId => $num) {
            $product = ScopeService::query('restaurant_product', $aid)
                ->where('id', $productId)
                ->where('status', 1)
                ->where('is_delete', 0)
                ->find();

            if (empty($product)) {
                throw new BusinessException('菜品「' . $productId . '」不存在或已下架');
            }

            $lineTotal = round((float) $product['sell_price'] * $num, 2);

            $productRows[] = ['product' => $product, 'num' => $num, 'total' => $lineTotal];
            $amount        = round($amount + $lineTotal, 2);
        }

        if ($amount <= 0) {
            throw new BusinessException('菜品价格未配置');
        }

        // ===== 券（门槛按商品总额）与积分（既有管道） =====
        $couponPick  = null;
        $couponMoney = 0.0;

        if ($couponId > 0) {
            $couponPick  = $this->couponService->resolveForOrder($aid, $mid, $couponId, $amount);
            $couponMoney = (float) $couponPick['coupon_money'];
        }

        $afterCoupon = max(round($amount - $couponMoney, 2), 0.0);

        $scoreMoney = 0.0;

        if ($useScore > 0) {
            if (!ScoreService::switchOn($aid)) {
                throw new BusinessException('积分抵扣未开启');
            }

            $ratio = ScoreService::ratioOf($aid);

            if ($ratio < 1) {
                throw new BusinessException('积分抵扣比例未配置');
            }

            $scoreMoney = floor($useScore / $ratio * 100) / 100;
            $maxDeduct  = round($afterCoupon - 0.01, 2);

            if ($scoreMoney > $maxDeduct) {
                $scoreMoney = max($maxDeduct, 0.0);
            }

            if ($scoreMoney <= 0) {
                throw new BusinessException('应付金额不足，无法使用积分抵扣');
            }
        }

        $payAmount = max(round($afterCoupon - $scoreMoney, 2), 0.01);
        $now       = time();

        return Db::transaction(function () use ($aid, $mid, $tableId, $table, $productRows, $amount, $couponPick, $couponMoney, $useScore, $scoreMoney, $payAmount, $now) {
            $orderId = (int) Db::name('restaurant_shop_order')->insertGetId([
                'aid'           => $aid,
                'bid'           => 0,
                'mid'           => $mid,
                'tableid'       => $tableId,
                'ordernum'      => '',
                'title'         => '堂食点餐',
                'renshu'        => (int) ($table['seat'] ?? 0),
                'totalprice'    => $payAmount,
                'product_price' => $amount,
                'coupon_money'  => $couponMoney,
                'pay_amount'    => $payAmount,
                'score_money'   => $scoreMoney,
                'scoredkscore'  => $useScore,
                'coupon_rid'    => null !== $couponPick ? (int) $couponPick['record_id'] : 0,
                'status'        => self::STATUS_UNPAID,
                'paytime'       => 0,
                'eattype'       => 0,
                'delete'        => 0,
                'createtime'    => $now,
            ]);

            $orderSn = 'RS' . date('YmdHis', $now)
                . str_pad((string) ($aid % 10000), 4, '0', STR_PAD_LEFT)
                . str_pad((string) ($orderId % 1000000), 6, '0', STR_PAD_LEFT);

            Db::name('restaurant_shop_order')->where('id', $orderId)->update(['ordernum' => $orderSn]);

            $goodsOut = [];

            foreach ($productRows as $row) {
                $product = $row['product'];

                Db::name('restaurant_shop_order_goods')->insert([
                    'aid'         => $aid,
                    'bid'         => 0,
                    'mid'         => $mid,
                    'orderid'     => $orderId,
                    'ordernum'    => $orderSn,
                    'proid'       => (int) $product['id'],
                    'name'        => (string) ($product['name'] ?? ''),
                    'pic'         => (string) ($product['pic'] ?? ''),
                    'num'         => (int) $row['num'],
                    'sell_price'  => (float) $product['sell_price'],
                    'totalprice'  => (float) $row['total'],
                    'status'      => 0,
                    'createtime'  => $now,
                ]);

                $goodsOut[] = [
                    'product_id' => (int) $product['id'],
                    'name'       => (string) ($product['name'] ?? ''),
                    'num'        => (int) $row['num'],
                    'price'      => (float) $product['sell_price'],
                ];
            }

            // 用券预锁定（行锁抢占，失败回滚整单）
            if (null !== $couponPick) {
                $this->couponService->bindToOrder((int) $couponPick['record_id'], $orderId, 'restaurant');
            }

            // 积分预扣（取消时原数返还）
            if ($useScore > 0) {
                $this->scoreService->deductForOrder($aid, $mid, $useScore, '下单抵扣 堂食订单' . $orderSn);
            }

            return [
                'id'           => $orderId,
                'order_sn'     => $orderSn,
                'table_id'     => $tableId,
                'amount'       => $amount,
                'coupon_id'    => null !== $couponPick ? (int) $couponPick['coupon_id'] : 0,
                'coupon_money' => $couponMoney,
                'use_score'    => $useScore,
                'score_money'  => $scoreMoney,
                'pay_amount'   => $payAmount,
                'goods'        => $goodsOut,
            ];
        });
    }

    /**
     * 我的堂食订单列表（含 goods 数组）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function apiOrderList(int $aid, int $mid, string $status, int $page, int $pageSize): array
    {
        $query = ScopeService::queryAs('restaurant_shop_order', $aid, 'o')
            ->leftJoin('restaurant_table t', 't.id = o.tableid')
            ->field('o.*, t.name as table_name')
            ->where('o.mid', $mid)
            ->where('o.delete', 0);

        if ('' !== $status) {
            $query->where('o.status', (int) $status);
        }

        $total = (clone $query)->count();

        $rows = $query->order('o.id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        $goodsMap = $this->goodsMapOfOrders(array_map(static fn (array $r) => (int) $r['id'], $rows));

        return [
            'list'     => array_map(fn (array $row) => $this->formatOrder($row, $goodsMap[(int) $row['id']] ?? []), $rows),
            'total'    => $total,
            'page'     => $page,
            'pagesize' => $pageSize,
        ];
    }

    /**
     * 取消订单（仅待支付；释放券 + 返还积分 + 释放桌台占用）
     */
    public function cancelOrder(int $aid, int $mid, int $orderId): void
    {
        $order = $this->findOrder($aid, $mid, $orderId);

        $this->cancelInternal($order);
    }

    /**
     * 完成订单（仅本人已支付单；status=1→2 释放桌台占用；幂等：已完成直接成功）
     *
     * 表无完成时间列，仅改状态
     */
    public function completeOrder(int $aid, int $mid, int $orderId): void
    {
        $order = $this->findOrder($aid, $mid, $orderId);
        $status = (int) $order['status'];

        if (self::STATUS_FINISHED === $status) {
            return; // 幂等：已完成直接成功
        }

        if (self::STATUS_PAID !== $status) {
            throw new BusinessException('仅已支付订单可完成');
        }

        Db::name('restaurant_shop_order')
            ->where('id', $orderId)
            ->where('status', self::STATUS_PAID)
            ->update(['status' => self::STATUS_FINISHED]);
    }

    /**
     * 支付成功入账（PayService 回调路由调用，幂等：仅 status=0 时生效）
     */
    public function onPaid(int $orderId, string $payType, string $payNum): bool
    {
        $paid = (bool) Db::transaction(function () use ($orderId, $payType, $payNum) {
            $order = Db::name('restaurant_shop_order')->where('id', $orderId)->lock(true)->find();

            if (empty($order) || self::STATUS_UNPAID !== (int) $order['status']) {
                return false; // 已支付/已取消等：幂等不重复处理
            }

            Db::name('restaurant_shop_order')
                ->where('id', $orderId)
                ->where('status', self::STATUS_UNPAID)
                ->update([
                    'status'    => self::STATUS_PAID,
                    'paytime'   => time(),
                    'paytype'   => $payType,
                    'paytypeid' => PayService::PAY_TYPE_IDS[$payType] ?? 0,
                    'paynum'    => $payNum,
                ]);

            // 同步商品行状态（0未付款→1已付款）
            Db::name('restaurant_shop_order_goods')
                ->where('orderid', $orderId)
                ->where('status', 0)
                ->update(['status' => 1]);

            // 券核销（幂等）
            $this->couponService->consumeByOrder((int) ($order['coupon_rid'] ?? 0), $orderId);

            // 积分赠送：pay_score × floor(实付)
            $this->scoreService->grantForPay(
                (int) ($order['aid'] ?? 0),
                (int) ($order['mid'] ?? 0),
                (float) ($order['pay_amount'] ?? 0),
                '支付赠送 堂食订单' . (string) ($order['ordernum'] ?? '')
            );

            return true;
        });

        // 批次6-T6-4：支付成功自动生成取餐号（TakeFoodService 内部幂等防重复；失败仅记日志不影响入账）
        if ($paid) {
            try {
                app(TakeFoodService::class)->generate($orderId);
            } catch (\Throwable $e) {
                \think\facade\Log::error('takefood generate fail order=' . $orderId . ': ' . $e->getMessage());
            }
        }

        return $paid;
    }

    /* ================= 内部工具 ================= */

    /**
     * 取消内部实现（仅待支付；条件更新幂等；释放券 + 返还积分）
     *
     * @param array<string, mixed> $order
     */
    protected function cancelInternal(array $order): void
    {
        $orderId = (int) $order['id'];

        if (self::STATUS_UNPAID !== (int) $order['status']) {
            throw new BusinessException('当前订单状态不可取消（仅待支付可取消）');
        }

        Db::transaction(function () use ($order, $orderId) {
            $affected = Db::name('restaurant_shop_order')
                ->where('id', $orderId)
                ->where('status', self::STATUS_UNPAID)
                ->update(['status' => self::STATUS_CANCELLED]);

            if (1 !== $affected) {
                return; // 并发已变更：幂等跳过
            }

            // 释放券预锁定
            $this->couponService->releaseByOrder((int) ($order['coupon_rid'] ?? 0), $orderId);

            // 返还抵扣积分（流水 type=3）
            if ((int) ($order['scoredkscore'] ?? 0) > 0) {
                $this->scoreService->refundForCancel(
                    (int) $order['aid'],
                    (int) $order['mid'],
                    (int) $order['scoredkscore'],
                    '取消订单返还 堂食订单' . (string) ($order['ordernum'] ?? '')
                );
            }
        });
    }

    /**
     * 占用桌台ID集合（存在 status IN(0,1) 订单的桌台）
     *
     * @param array<int, int> $tableIds
     * @return array<int, int>
     */
    protected function busyTableIds(int $aid, array $tableIds): array
    {
        $tableIds = array_values(array_filter($tableIds, static fn (int $v) => $v > 0));

        if (empty($tableIds)) {
            return [];
        }

        $rows = Db::name('restaurant_shop_order')
            ->where('aid', $aid)
            ->whereIn('tableid', $tableIds)
            ->whereIn('status', [self::STATUS_UNPAID, self::STATUS_PAID])
            ->field('tableid')
            ->group('tableid')
            ->select()
            ->toArray();

        return array_map(static fn (array $r) => (int) $r['tableid'], $rows);
    }

    /**
     * 批量取订单商品行（orderid => [{name,num,price,pic,product_id}]）
     *
     * @param array<int, int> $orderIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    protected function goodsMapOfOrders(array $orderIds): array
    {
        $orderIds = array_values(array_filter($orderIds, static fn (int $v) => $v > 0));

        if (empty($orderIds)) {
            return [];
        }

        $rows = Db::name('restaurant_shop_order_goods')
            ->whereIn('orderid', $orderIds)
            ->order('id', 'asc')
            ->select()
            ->toArray();

        $map = [];

        foreach ($rows as $row) {
            $map[(int) $row['orderid']][] = [
                'product_id' => (int) ($row['proid'] ?? 0),
                'name'       => (string) ($row['name'] ?? ''),
                'pic'        => (string) ($row['pic'] ?? ''),
                'num'        => (int) ($row['num'] ?? 0),
                'price'      => (float) ($row['sell_price'] ?? 0),
            ];
        }

        return $map;
    }

    /**
     * 字段是否有非空值（edit 空值视为不修改）
     *
     * @param array<string, mixed> $data
     */
    protected function hasValue(array $data, string $key): bool
    {
        return array_key_exists($key, $data) && null !== $data[$key] && '' !== trim((string) $data[$key]);
    }

    /**
     * 价格解析（数值、非负）
     */
    protected function parsePrice(mixed $value, string $label): float
    {
        if (null === $value || '' === $value) {
            return 0.0;
        }

        if (!is_numeric($value) || (float) $value < 0) {
            throw new BusinessException($label . '格式无效（须为非负数字）');
        }

        return round((float) $value, 2);
    }

    /**
     * 查找本租户区域（不存在抛 404）
     *
     * @return array<string, mixed>
     */
    protected function findArea(int $aid, int $id): array
    {
        if ($id <= 0) {
            throw new BusinessException('缺少区域ID');
        }

        $area = ScopeService::query('restaurant_area', $aid)
            ->where('id', $id)
            ->find();

        if (empty($area)) {
            throw new BusinessException('区域不存在', 404, 404);
        }

        return $area;
    }

    /**
     * 查找本租户桌台（不存在抛 404）
     *
     * @return array<string, mixed>
     */
    protected function findTable(int $aid, int $id): array
    {
        if ($id <= 0) {
            throw new BusinessException('缺少桌台ID');
        }

        $table = ScopeService::query('restaurant_table', $aid)
            ->where('id', $id)
            ->find();

        if (empty($table)) {
            throw new BusinessException('桌台不存在', 404, 404);
        }

        return $table;
    }

    /**
     * 查找本租户分类（不存在抛 404）
     *
     * @return array<string, mixed>
     */
    protected function findCategory(int $aid, int $id): array
    {
        if ($id <= 0) {
            throw new BusinessException('缺少分类ID');
        }

        $category = ScopeService::query('restaurant_product_category', $aid)
            ->where('id', $id)
            ->find();

        if (empty($category)) {
            throw new BusinessException('分类不存在', 404, 404);
        }

        return $category;
    }

    /**
     * 查找本租户未删除的菜品（不存在抛 404）
     *
     * @return array<string, mixed>
     */
    protected function findProduct(int $aid, int $id): array
    {
        if ($id <= 0) {
            throw new BusinessException('缺少菜品ID');
        }

        $product = ScopeService::query('restaurant_product', $aid)
            ->where('id', $id)
            ->where('is_delete', 0)
            ->find();

        if (empty($product)) {
            throw new BusinessException('菜品不存在', 404, 404);
        }

        return $product;
    }

    /**
     * 查找订单（mid>0 限本人；不存在抛 404）
     *
     * @return array<string, mixed>
     */
    protected function findOrder(int $aid, int $mid, int $orderId): array
    {
        if ($orderId <= 0) {
            throw new BusinessException('缺少订单ID');
        }

        $query = ScopeService::query('restaurant_shop_order', $aid)
            ->where('id', $orderId)
            ->where('delete', 0);

        if ($mid > 0) {
            $query->where('mid', $mid);
        }

        $order = $query->find();

        if (empty($order)) {
            throw new BusinessException('订单不存在', 404, 404);
        }

        return $order;
    }

    /**
     * 区域行格式化
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function formatArea(array $row): array
    {
        return [
            'id'          => (int) ($row['id'] ?? 0),
            'name'        => (string) ($row['name'] ?? ''),
            'sort'        => (int) ($row['sort'] ?? 0),
            'status'      => (int) ($row['status'] ?? 1),
            'create_time' => (int) ($row['createtime'] ?? 0),
        ];
    }

    /**
     * 桌台行格式化（契约键：area_id←cid persons←seat qrcode_url←qrcode busy 推导）
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function formatTable(array $row, bool $busy): array
    {
        return [
            'id'          => (int) ($row['id'] ?? 0),
            'area_id'     => (int) ($row['cid'] ?? 0),
            'area_name'   => (string) ($row['area_name'] ?? ''),
            'name'        => (string) ($row['name'] ?? ''),
            'persons'     => (int) ($row['seat'] ?? 0),
            'qrcode_url'  => (string) ($row['qrcode'] ?? ''),
            'status'      => (int) ($row['status'] ?? 0),
            'sort'        => (int) ($row['sort'] ?? 0),
            'busy'        => $busy ? 1 : 0,
            'create_time' => (int) ($row['create_time'] ?? 0),
        ];
    }

    /**
     * 分类行格式化
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function formatCategory(array $row): array
    {
        return [
            'id'          => (int) ($row['id'] ?? 0),
            'name'        => (string) ($row['name'] ?? ''),
            'pic'         => (string) ($row['pic'] ?? ''),
            'sort'        => (int) ($row['sort'] ?? 1),
            'status'      => (int) ($row['status'] ?? 1),
            'create_time' => (int) ($row['create_time'] ?? 0),
        ];
    }

    /**
     * 菜品行格式化（契约键：price←sell_price desc←detail category_id←cid）
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function formatProduct(array $row): array
    {
        return [
            'id'          => (int) ($row['id'] ?? 0),
            'category_id' => (int) ($row['cid'] ?? 0),
            'name'        => (string) ($row['name'] ?? ''),
            'pic'         => (string) ($row['pic'] ?? ''),
            'price'       => (float) ($row['sell_price'] ?? 0),
            'desc'        => (string) ($row['detail'] ?? ''),
            'status'      => (int) ($row['status'] ?? 1),
            'create_time' => (int) ($row['create_time'] ?? 0),
        ];
    }

    /**
     * 订单行格式化（order_sn←ordernum amount←product_price pay_amount←补列）
     *
     * @param array<string, mixed> $row
     * @param array<int, array<string, mixed>> $goods
     * @return array<string, mixed>
     */
    protected function formatOrder(array $row, array $goods): array
    {
        $status = (int) ($row['status'] ?? 0);

        return [
            'id'            => (int) ($row['id'] ?? 0),
            'order_sn'      => (string) ($row['ordernum'] ?? ''),
            'mid'           => (int) ($row['mid'] ?? 0),
            'table_id'      => (int) ($row['tableid'] ?? 0),
            'table_name'    => (string) ($row['table_name'] ?? ''),
            'goods'         => $goods,
            'amount'        => (float) ($row['product_price'] ?? 0),
            'coupon_money'  => (float) ($row['coupon_money'] ?? 0),
            'score_money'   => (float) ($row['score_money'] ?? 0),
            'pay_amount'    => (float) ($row['pay_amount'] ?? 0),
            'use_score'     => (int) ($row['scoredkscore'] ?? 0),
            'status'        => $status,
            'status_text'   => self::STATUS_TEXT[$status] ?? '未知',
            'pay_time'      => (int) ($row['paytime'] ?? 0),
            'pickup_number' => (string) ($row['pickup_number'] ?? ''),
            'create_time'   => (int) ($row['createtime'] ?? 0),
        ];
    }
}
