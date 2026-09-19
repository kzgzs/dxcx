<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;
use think\facade\Log;

/**
 * 餐饮收银台二期服务（批次6-T6-6 任务207，最小闭环）
 *
 * canzhao（参考系统参考源码，只读）RestaurantCashdesk 控制器缺失，本服务依据基建表结构
 * （restaurant_cashdesk / restaurant_cashdesk_custom_pay / restaurant_product_jialiao）
 * 与菜单钩子功能清单自研；桌台操作参照 ApiAdminRestaurantTable，计价/支付参照 Cashier.php。
 *
 * 口径约定：
 * - 订单：restaurant_shop_order（is_bar_table_order=1 为收银台开台单）
 *   状态机沿用 RestaurantService：0未支付(进行中) 1已支付 2已完成 4已关闭 5已退款
 *   结账=落账即完成（status 0→1→2 一步完成，取餐号在 status=1 时生成）
 * - 商品行：restaurant_shop_order_goods
 *   行金额 = (sell_price + njlprice) × num；njlprice 为单份加料合计价，njltitle 加料名(逗号)
 *   is_gj=1 表示单品改价（sell_price 已置改后价）
 * - 订单金额：totalprice = product_price + service_money - moling_money
 *   service_money = 最低消费补足 + 桌台服务费；moling_money 抹零（CashierService::removeZero）
 * - 支付方式口径沿用一期 CashierService：1微信(mock) 3现金 4余额 100+自定义字典id
 * - 餐饮无库存概念（RestaurantService::createOrder 注释），反结账不涉及库存回退
 * - 收银设置与一期共用 restaurant_cashdesk 表；反结账审核开关 reverse_audit 为二期补列
 */
class RestaurantCashdeskService
{
    /** 桌台状态：-1禁用 0空闲 1预定 2入座(占用) 3清台 */
    public const TABLE_DISABLED = -1;
    public const TABLE_FREE     = 0;
    public const TABLE_BOOKED   = 1;
    public const TABLE_BUSY     = 2;
    public const TABLE_CLEAN    = 3;

    public const TABLE_STATUS_TEXT = [
        self::TABLE_DISABLED => '禁用',
        self::TABLE_FREE     => '空闲',
        self::TABLE_BOOKED   => '预定',
        self::TABLE_BUSY     => '就餐中',
        self::TABLE_CLEAN    => '待清台',
    ];

    /** 订单状态（沿用 RestaurantService） */
    public const ORDER_UNPAID   = 0;
    public const ORDER_PAID     = 1;
    public const ORDER_FINISHED = 2;
    public const ORDER_CLOSED   = 4;
    public const ORDER_REFUNDED = 5;

    public const ORDER_STATUS_TEXT = [
        self::ORDER_UNPAID   => '进行中',
        self::ORDER_PAID     => '已支付',
        self::ORDER_FINISHED => '已完成',
        self::ORDER_CLOSED   => '已关闭',
        self::ORDER_REFUNDED => '已退款',
    ];

    public function __construct(
        protected CashierService $cashierService,
        protected ScoreService $scoreService,
    ) {
    }

    /* ================= 桌台宫格与开台 ================= */

    /**
     * 桌台宫格（按区域分组，附当前订单摘要）
     *
     * @return array{areas: array, tables: array, summary: array}
     */
    public function desk(int $aid): array
    {
        $areas = ScopeService::query('restaurant_area', $aid)
            ->field('id,name')
            ->order('id', 'asc')
            ->select()->toArray();

        $rows = ScopeService::queryAs('restaurant_table', $aid, 't')
            ->leftJoin('restaurant_shop_order o', 'o.id = t.orderid')
            ->field('t.*, o.ordernum as order_ordernum, o.totalprice as order_totalprice, o.createtime as order_createtime, o.status as order_status')
            ->order('t.cid', 'asc')->order('t.sort', 'desc')->order('t.id', 'asc')
            ->select()->toArray();

        $tables = array_map(fn (array $v): array => [
            'id'           => (int) $v['id'],
            'cid'          => (int) ($v['cid'] ?? 0),
            'name'         => (string) ($v['name'] ?? ''),
            'seat'         => (int) ($v['seat'] ?? 0),
            'status'       => (int) ($v['status'] ?? 0),
            'status_text'  => self::TABLE_STATUS_TEXT[(int) ($v['status'] ?? 0)] ?? '未知',
            'orderid'      => (int) ($v['orderid'] ?? 0),
            'minprice'     => round((float) ($v['minprice'] ?? 0), 2),
            'order'        => (int) ($v['orderid'] ?? 0) > 0 ? [
                'id'         => (int) $v['orderid'],
                'ordernum'   => (string) ($v['order_ordernum'] ?? ''),
                'totalprice' => round((float) ($v['order_totalprice'] ?? 0), 2),
                'createtime' => (int) ($v['order_createtime'] ?? 0),
                'status'     => (int) ($v['order_status'] ?? 0),
            ] : null,
        ], $rows);

        $summary = ['free' => 0, 'busy' => 0, 'clean' => 0, 'booked' => 0];
        foreach ($tables as $t) {
            switch ($t['status']) {
                case self::TABLE_FREE:
                case self::TABLE_DISABLED:
                    ++$summary['free'];

                    break;
                case self::TABLE_BUSY:
                    ++$summary['busy'];

                    break;
                case self::TABLE_CLEAN:
                    ++$summary['clean'];

                    break;
                case self::TABLE_BOOKED:
                    ++$summary['booked'];

                    break;
            }
        }

        return ['areas' => $areas, 'tables' => $tables, 'summary' => $summary];
    }

    /**
     * 开台（生成堂食进行中订单，桌台置占用）
     *
     * 幂等：桌台已有进行中订单时直接返回该订单（前端转入订单面板继续点餐）。
     *
     * @return array{order_id: int, table_id: int, reopened: bool}
     */
    public function openTable(int $aid, int $uid, int $tableId, int $mid = 0): array
    {
        if ($tableId <= 0) {
            throw new BusinessException('请选择桌台');
        }

        return Db::transaction(function () use ($aid, $uid, $tableId, $mid) {
            $table = $this->findTable($aid, $tableId);

            if (self::TABLE_DISABLED === (int) $table['status']) {
                throw new BusinessException('该桌台已禁用');
            }

            // 已有进行中订单：幂等返回
            $existOrderId = (int) ($table['orderid'] ?? 0);

            if ($existOrderId > 0) {
                $exist = Db::name('restaurant_shop_order')->where('id', $existOrderId)->where('aid', $aid)->find();

                if (!empty($exist) && in_array((int) $exist['status'], [self::ORDER_UNPAID, self::ORDER_PAID], true)) {
                    return ['order_id' => $existOrderId, 'table_id' => $tableId, 'reopened' => true];
                }
            }

            if (self::TABLE_BUSY === (int) $table['status']) {
                throw new BusinessException('该桌台正在使用中');
            }

            $desk = $this->cashierService->deskRow($aid);
            $now  = time();

            $orderId = (int) Db::name('restaurant_shop_order')->insertGetId([
                'aid'                => $aid,
                'bid'                => 0,
                'mid'                => max(0, $mid),
                'tableid'            => $tableId,
                'ordernum'           => '',
                'title'              => '堂食点餐',
                'renshu'             => (int) ($table['seat'] ?? 0),
                'totalprice'         => 0,
                'product_price'      => 0,
                'coupon_money'       => 0,
                'pay_amount'         => 0,
                'score_money'        => 0,
                'status'             => self::ORDER_UNPAID,
                'paytime'            => 0,
                'eattype'            => 0,
                'delete'             => 0,
                'createtime'         => $now,
                'cashdesk_id'        => (int) ($desk['id'] ?? 0),
                'uid'                => $uid,
                'remove_zero'        => 0,
                'remove_zero_length' => (int) ($desk['remove_zero_length'] ?? 0),
                'moling_money'       => 0,
                'is_bar_table_order' => 1,
                'service_money'      => 0,
            ]);

            $orderSn = 'RS' . date('YmdHis', $now)
                . str_pad((string) ($aid % 10000), 4, '0', STR_PAD_LEFT)
                . str_pad((string) ($orderId % 1000000), 6, '0', STR_PAD_LEFT);

            Db::name('restaurant_shop_order')->where('id', $orderId)->update(['ordernum' => $orderSn]);

            // 桌台置占用（参照 canzhao ApiAdminRestaurantTable change 语义）
            Db::name('restaurant_table')->where('id', $tableId)->where('aid', $aid)->update([
                'status'      => self::TABLE_BUSY,
                'orderid'     => $orderId,
                'update_time' => $now,
            ]);

            return ['order_id' => $orderId, 'table_id' => $tableId, 'reopened' => false];
        });
    }

    /* ================= 菜品（含加料字典） ================= */

    /**
     * 点餐菜品列表（分类 + 加料字典）
     */
    public function categories(int $aid): array
    {
        $rows = ScopeService::query('restaurant_product_category', $aid)
            ->where('status', 1)
            ->field('id,name,sort')
            ->order('sort', 'desc')->order('id', 'asc')
            ->select()->toArray();

        return array_map(fn (array $v): array => [
            'id'   => (int) $v['id'],
            'name' => (string) ($v['name'] ?? ''),
        ], $rows);
    }

    /**
     * 菜品列表（含加料字典 restaurant_product_jialiao）
     */
    public function products(int $aid, string $keyword, int $categoryId): array
    {
        $query = ScopeService::query('restaurant_product', $aid)
            ->where('status', 1)
            ->where('is_delete', 0);

        if ('' !== $keyword) {
            $query->where('name', 'like', '%' . $keyword . '%');
        }
        if ($categoryId > 0) {
            $query->where('cid', $categoryId);
        }

        $rows = $query->field('id,name,pic,sell_price,cid')
            ->order('sort', 'desc')->order('id', 'asc')
            ->limit(200)
            ->select()->toArray();

        // 加料字典按菜品归组
        $productIds = array_map(static fn (array $v) => (int) $v['id'], $rows);
        $jialiaoMap = [];

        if (!empty($productIds)) {
            $jlRows = ScopeService::query('restaurant_product_jialiao', $aid)
                ->whereIn('proid', $productIds)
                ->field('id,proid,title,price')
                ->order('id', 'asc')
                ->select()->toArray();

            foreach ($jlRows as $jl) {
                $jialiaoMap[(int) $jl['proid']][] = [
                    'id'    => (int) $jl['id'],
                    'title' => (string) ($jl['title'] ?? ''),
                    'price' => round((float) ($jl['price'] ?? 0), 2),
                ];
            }
        }

        return array_map(fn (array $v): array => [
            'id'         => (int) $v['id'],
            'name'       => (string) ($v['name'] ?? ''),
            'pic'        => (string) ($v['pic'] ?? ''),
            'sell_price' => round((float) ($v['sell_price'] ?? 0), 2),
            'cid'        => (int) ($v['cid'] ?? 0),
            'jialiao'    => $jialiaoMap[(int) $v['id']] ?? [],
        ], $rows);
    }

    /* ================= 点餐/加菜/改数量/改价/抹零/退菜 ================= */

    /**
     * 点餐/加菜（向进行中订单追加菜品，含加料）
     *
     * items: [{product_id, num, jialiao_ids: [..]}]
     */
    public function addGoods(int $aid, int $orderId, array $items): array
    {
        if (empty($items)) {
            throw new BusinessException('请选择菜品');
        }

        Db::transaction(function () use ($aid, $orderId, $items) {
            $order = $this->lockOrder($aid, $orderId);

            if (self::ORDER_UNPAID !== (int) $order['status']) {
                throw new BusinessException('仅进行中订单可加菜');
            }

            $now = time();

            foreach ($items as $item) {
                $productId   = (int) ($item['product_id'] ?? 0);
                $num         = (int) ($item['num'] ?? 1);
                $jialiaoIds  = array_values(array_unique(array_map('intval', (array) ($item['jialiao_ids'] ?? []))));

                if ($productId <= 0) {
                    throw new BusinessException('菜品ID无效');
                }
                if ($num <= 0 || $num > 999) {
                    throw new BusinessException('菜品数量须为1-999');
                }

                $product = ScopeService::query('restaurant_product', $aid)
                    ->where('id', $productId)
                    ->where('status', 1)
                    ->where('is_delete', 0)
                    ->find();

                if (empty($product)) {
                    throw new BusinessException('菜品「' . $productId . '」不存在或已下架');
                }

                // 加料：字典校验 + 单份加料合计价 + 名称
                $jialiaoPrice = 0.0;
                $jialiaoNames = [];

                if (!empty($jialiaoIds)) {
                    $jlRows = ScopeService::query('restaurant_product_jialiao', $aid)
                        ->where('proid', $productId)
                        ->whereIn('id', $jialiaoIds)
                        ->select()->toArray();

                    if (count($jlRows) !== count($jialiaoIds)) {
                        throw new BusinessException('菜品「' . (string) ($product['name'] ?? '') . '」加料配置无效');
                    }

                    foreach ($jlRows as $jl) {
                        $jialiaoPrice = round($jialiaoPrice + (float) ($jl['price'] ?? 0), 2);
                        $jialiaoNames[] = (string) ($jl['title'] ?? '');
                    }
                }

                // 无加料且已有同菜品行：合并数量；否则新增行
                $merged = false;

                if (empty($jialiaoIds)) {
                    $existGoods = Db::name('restaurant_shop_order_goods')
                        ->where('orderid', $orderId)
                        ->where('proid', $productId)
                        ->where('status', 0)
                        ->where('njlprice', 0)
                        ->where('is_gj', 0)
                        ->lock(true)
                        ->find();

                    if (!empty($existGoods)) {
                        Db::name('restaurant_shop_order_goods')->where('id', (int) $existGoods['id'])->update([
                            'num'        => (int) $existGoods['num'] + $num,
                            'totalprice' => round(((float) $existGoods['sell_price'] + (float) $existGoods['njlprice']) * ((int) $existGoods['num'] + $num), 2),
                        ]);
                        $merged = true;
                    }
                }

                if (!$merged) {
                    Db::name('restaurant_shop_order_goods')->insert([
                        'aid'        => $aid,
                        'bid'        => 0,
                        'mid'        => (int) ($order['mid'] ?? 0),
                        'orderid'    => $orderId,
                        'ordernum'   => (string) ($order['ordernum'] ?? ''),
                        'proid'      => $productId,
                        'name'       => (string) ($product['name'] ?? ''),
                        'pic'        => (string) ($product['pic'] ?? ''),
                        'num'        => $num,
                        'sell_price' => round((float) ($product['sell_price'] ?? 0), 2),
                        'totalprice' => round(((float) ($product['sell_price'] ?? 0) + $jialiaoPrice) * $num, 2),
                        'status'     => 0,
                        'createtime' => $now,
                        'is_gj'      => 0,
                        'protype'    => 1,
                        'njlprice'   => $jialiaoPrice,
                        'njltitle'   => implode(',', $jialiaoNames),
                    ]);
                }
            }

            $this->recalcOrder($aid, $orderId);
        });

        return $this->orderDetail($aid, $orderId);
    }

    /**
     * 改数量（num<=0 等价整行退菜）
     */
    public function changeNum(int $aid, int $orderId, int $goodsId, int $num): array
    {
        if ($num < 0 || $num > 999) {
            throw new BusinessException('数量须为0-999');
        }

        Db::transaction(function () use ($aid, $orderId, $goodsId, $num) {
            $this->lockOrder($aid, $orderId, true);
            $goods = $this->findGoods($aid, $orderId, $goodsId);

            if (0 === $num) {
                Db::name('restaurant_shop_order_goods')->where('id', $goodsId)->delete();
            } else {
                Db::name('restaurant_shop_order_goods')->where('id', $goodsId)->update([
                    'num'        => $num,
                    'totalprice' => round(((float) $goods['sell_price'] + (float) $goods['njlprice']) * $num, 2),
                ]);
            }

            $this->recalcOrder($aid, $orderId);
        });

        return $this->orderDetail($aid, $orderId);
    }

    /**
     * 单品改价（is_gj=1，sell_price 置改后单价）
     */
    public function changePrice(int $aid, int $orderId, int $goodsId, float $price): array
    {
        if ($price < 0 || $price > 999999) {
            throw new BusinessException('请输入有效的价格');
        }

        $price = round($price, 2);

        Db::transaction(function () use ($aid, $orderId, $goodsId, $price) {
            $this->lockOrder($aid, $orderId, true);
            $goods = $this->findGoods($aid, $orderId, $goodsId);

            Db::name('restaurant_shop_order_goods')->where('id', $goodsId)->update([
                'sell_price' => $price,
                'is_gj'      => 1,
                'totalprice' => round(($price + (float) $goods['njlprice']) * (int) $goods['num'], 2),
            ]);

            $this->recalcOrder($aid, $orderId);
        });

        return $this->orderDetail($aid, $orderId);
    }

    /**
     * 整单抹零设置（length：0不抹 1抹分 2抹角 3抹元）
     */
    public function setRemoveZero(int $aid, int $orderId, int $length): array
    {
        if (!in_array($length, [0, 1, 2, 3], true)) {
            throw new BusinessException('抹零位数无效');
        }

        Db::transaction(function () use ($aid, $orderId, $length) {
            $this->lockOrder($aid, $orderId, true);

            Db::name('restaurant_shop_order')->where('id', $orderId)->update([
                'remove_zero'        => $length > 0 ? 1 : 0,
                'remove_zero_length' => $length,
            ]);

            $this->recalcOrder($aid, $orderId);
        });

        return $this->orderDetail($aid, $orderId);
    }

    /**
     * 退菜（数量扣减，扣完删行，金额重算）
     */
    public function refundGoods(int $aid, int $orderId, int $goodsId, int $num): array
    {
        if ($num <= 0) {
            throw new BusinessException('退菜数量须大于0');
        }

        Db::transaction(function () use ($aid, $orderId, $goodsId, $num) {
            $this->lockOrder($aid, $orderId, true);
            $goods = $this->findGoods($aid, $orderId, $goodsId);

            $left = (int) $goods['num'] - $num;

            if ($left <= 0) {
                Db::name('restaurant_shop_order_goods')->where('id', $goodsId)->delete();
            } else {
                Db::name('restaurant_shop_order_goods')->where('id', $goodsId)->update([
                    'num'        => $left,
                    'totalprice' => round(((float) $goods['sell_price'] + (float) $goods['njlprice']) * $left, 2),
                ]);
            }

            $this->recalcOrder($aid, $orderId);
        });

        return $this->orderDetail($aid, $orderId);
    }

    /* ================= 订单查询 ================= */

    /**
     * 订单详情（含商品行/桌台/金额构成）
     */
    public function orderDetail(int $aid, int $orderId): array
    {
        $order = $this->findOrder($aid, $orderId);

        $goods = Db::name('restaurant_shop_order_goods')
            ->where('orderid', $orderId)
            ->order('id', 'asc')
            ->select()->toArray();

        $table = (int) ($order['tableid'] ?? 0) > 0
            ? Db::name('restaurant_table')->where('id', (int) $order['tableid'])->where('aid', $aid)->find()
            : null;

        return [
            'id'                 => (int) $order['id'],
            'ordernum'           => (string) ($order['ordernum'] ?? ''),
            'tableid'            => (int) ($order['tableid'] ?? 0),
            'table_name'         => null !== $table ? (string) ($table['name'] ?? '') : '',
            'status'             => (int) $order['status'],
            'status_text'        => self::ORDER_STATUS_TEXT[(int) $order['status']] ?? '未知',
            'mid'                => (int) ($order['mid'] ?? 0),
            'uid'                => (int) ($order['uid'] ?? 0),
            'createtime'         => (int) ($order['createtime'] ?? 0),
            'paytime'            => (int) ($order['paytime'] ?? 0),
            'paytypeid'          => (int) ($order['paytypeid'] ?? 0),
            'paytype'            => (string) ($order['paytype'] ?? ''),
            'paynum'             => (string) ($order['paynum'] ?? ''),
            'pickup_number'      => (string) ($order['pickup_number'] ?? ''),
            'refund_status'      => (int) ($order['refund_status'] ?? 0),
            'product_price'      => round((float) ($order['product_price'] ?? 0), 2),
            'service_money'      => round((float) ($order['service_money'] ?? 0), 2),
            'moling_money'       => round((float) ($order['moling_money'] ?? 0), 2),
            'totalprice'         => round((float) ($order['totalprice'] ?? 0), 2),
            'pay_amount'         => round((float) ($order['pay_amount'] ?? 0), 2),
            'remove_zero'        => (int) ($order['remove_zero'] ?? 0),
            'remove_zero_length' => (int) ($order['remove_zero_length'] ?? 0),
            'goods'              => array_map(fn (array $g): array => [
                'id'         => (int) $g['id'],
                'proid'      => (int) ($g['proid'] ?? 0),
                'name'       => (string) ($g['name'] ?? ''),
                'pic'        => (string) ($g['pic'] ?? ''),
                'num'        => (int) ($g['num'] ?? 0),
                'sell_price' => round((float) ($g['sell_price'] ?? 0), 2),
                'njlprice'   => round((float) ($g['njlprice'] ?? 0), 2),
                'njltitle'   => (string) ($g['njltitle'] ?? ''),
                'totalprice' => round((float) ($g['totalprice'] ?? 0), 2),
                'is_gj'      => (int) ($g['is_gj'] ?? 0),
                'remark'     => (string) ($g['remark'] ?? ''),
            ], $goods),
        ];
    }

    /**
     * 收银订单列表（收银台开台单 + 时间/状态筛选）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function orderList(int $aid, string $status, string $startTime, string $endTime, int $page, int $pageSize): array
    {
        $query = ScopeService::queryAs('restaurant_shop_order', $aid, 'o')
            ->leftJoin('restaurant_table t', 't.id = o.tableid')
            ->field('o.*, t.name as table_name')
            ->where('o.delete', 0)
            ->where('o.is_bar_table_order', 1);

        if ('' !== $status) {
            $query->where('o.status', (int) $status);
        }

        $range = $this->timeRange($startTime, $endTime);

        if (null !== $range) {
            $query->where('o.createtime', '>=', $range[0])->where('o.createtime', '<=', $range[1]);
        }

        $total = (clone $query)->count();

        $rows = $query->order('o.id', 'desc')
            ->page($page, $pageSize)
            ->select()->toArray();

        $payMap = $this->cashierService->payTypeMap($aid);

        return [
            'list'     => array_map(fn (array $v): array => [
                'id'          => (int) $v['id'],
                'ordernum'    => (string) ($v['ordernum'] ?? ''),
                'table_name'  => (string) ($v['table_name'] ?? ''),
                'status'      => (int) $v['status'],
                'status_text' => self::ORDER_STATUS_TEXT[(int) $v['status']] ?? '未知',
                'refund_status' => (int) ($v['refund_status'] ?? 0),
                'totalprice'  => round((float) ($v['totalprice'] ?? 0), 2),
                'pay_amount'  => round((float) ($v['pay_amount'] ?? 0), 2),
                'moling_money' => round((float) ($v['moling_money'] ?? 0), 2),
                'paytypeid'   => (int) ($v['paytypeid'] ?? 0),
                'paytype_text' => $payMap[(int) ($v['paytypeid'] ?? 0)] ?? (string) ($v['paytype'] ?? ''),
                'pickup_number' => (string) ($v['pickup_number'] ?? ''),
                'createtime'  => (int) ($v['createtime'] ?? 0),
                'paytime'     => (int) ($v['paytime'] ?? 0),
                'uid'         => (int) ($v['uid'] ?? 0),
            ], $rows),
            'total'    => $total,
            'page'     => $page,
            'pagesize' => $pageSize,
        ];
    }

    /* ================= 结账 / 反结账 ================= */

    /**
     * 结账（按 restaurant_cashdesk 支付开关；落账完成 + 桌台清台）
     *
     * payType 口径：1微信(mock) 3现金 4余额 100+自定义字典id
     */
    public function pay(int $aid, int $uid, int $orderId, int $payType): array
    {
        $desk = $this->cashierService->deskRow($aid);

        $payName = $this->assertPayEnable($aid, $desk, $payType);

        $result = Db::transaction(function () use ($aid, $uid, $orderId, $payType, $payName) {
            $order = $this->lockOrder($aid, $orderId);

            if (self::ORDER_UNPAID !== (int) $order['status']) {
                throw new BusinessException('仅进行中订单可结账');
            }

            $goodsCount = Db::name('restaurant_shop_order_goods')->where('orderid', $orderId)->count();

            if ($goodsCount <= 0) {
                throw new BusinessException('订单无菜品，请先点餐');
            }

            $mid     = (int) ($order['mid'] ?? 0);
            $payAmount = round((float) ($order['totalprice'] ?? 0), 2);

            if ($payAmount <= 0) {
                throw new BusinessException('订单金额异常，无法结账');
            }

            // 余额支付：需绑定会员 + 行锁扣余额 + 流水
            if (CashierService::PAY_MONEY === $payType) {
                if ($mid <= 0) {
                    throw new BusinessException('余额支付需先绑定会员');
                }

                $member = Db::name('member')->where('aid', $aid)->where('id', $mid)->lock(true)->find();

                if (empty($member)) {
                    throw new BusinessException('会员不存在');
                }
                if (round((float) ($member['money'] ?? 0), 2) < $payAmount) {
                    throw new BusinessException('会员余额不足');
                }

                $after = round((float) ($member['money'] ?? 0) - $payAmount, 2);

                Db::name('member')->where('id', $mid)->where('aid', $aid)->update(['money' => $after]);
                Db::name('member_moneylog')->insert([
                    'aid'        => $aid,
                    'mid'        => $mid,
                    'money'      => -$payAmount,
                    'after'      => $after,
                    'createtime' => time(),
                    'remark'     => '餐饮收银结账 ' . (string) ($order['ordernum'] ?? ''),
                    'paytype'    => 'restaurant_cashdesk',
                ]);
            }

            $payNum = CashierService::PAY_WECHAT === $payType
                ? 'MOCK' . date('YmdHis') . random_int(1000, 9999)
                : '';

            // 0→1 已支付（取餐号生成依赖 status=1）
            $affected = Db::name('restaurant_shop_order')
                ->where('id', $orderId)
                ->where('status', self::ORDER_UNPAID)
                ->update([
                    'status'    => self::ORDER_PAID,
                    'paytime'   => time(),
                    'paytype'   => $payName,
                    'paytypeid' => $payType,
                    'paynum'    => $payNum,
                    'pay_amount' => $payAmount,
                ]);

            if (1 !== $affected) {
                throw new BusinessException('订单状态已变更，请刷新重试');
            }

            Db::name('restaurant_shop_order_goods')
                ->where('orderid', $orderId)
                ->where('status', 0)
                ->update(['status' => 1]);

            // 积分赠送（绑定会员时）
            if ($mid > 0) {
                $this->scoreService->grantForPay(
                    $aid,
                    $mid,
                    $payAmount,
                    '支付赠送 餐饮收银订单' . (string) ($order['ordernum'] ?? '')
                );
            }

            // 取餐号生成（generate 仅认 status=1，须在完成前触发；内部幂等，失败仅记日志）
            try {
                app(TakeFoodService::class)->generate($orderId);
            } catch (\Throwable $e) {
                Log::error('restaurant cashdesk takefood fail order=' . $orderId . ': ' . $e->getMessage());
            }

            // 1→2 完成（结账即完成）
            Db::name('restaurant_shop_order')
                ->where('id', $orderId)
                ->where('status', self::ORDER_PAID)
                ->update(['status' => self::ORDER_FINISHED]);

            // 桌台清台（参照 canzhao ApiAdminRestaurantTable clean 语义）
            Db::name('restaurant_table')
                ->where('aid', $aid)
                ->where('orderid', $orderId)
                ->update(['status' => self::TABLE_CLEAN, 'orderid' => 0, 'update_time' => time()]);

            return ['paynum' => $payNum, 'pay_amount' => $payAmount];
        });

        return $this->orderDetail($aid, $orderId);
    }

    /**
     * 反结账（已完成订单撤销回进行中）
     *
     * reverse_audit=1 时先置 refund_status=1 待审核（reverseAudit 通过后回滚）。
     * 回滚：余额原路退回/取餐号作废/桌台恢复占用（桌台未被新占用时）。
     * 餐饮无库存概念，不涉及库存回退。
     *
     * @return array{audited: bool}
     */
    public function reversePay(int $aid, int $orderId): array
    {
        $desk = $this->cashierService->deskRow($aid);

        $needAudit = 1 === (int) ($desk['reverse_audit'] ?? 0);

        Db::transaction(function () use ($aid, $orderId, $needAudit) {
            $order = $this->lockOrder($aid, $orderId);

            if (self::ORDER_FINISHED !== (int) $order['status']) {
                throw new BusinessException('仅已完成订单可反结账');
            }

            if ($needAudit) {
                if ((int) ($order['refund_status'] ?? 0) === 1) {
                    throw new BusinessException('反结账审核中，请勿重复提交');
                }

                Db::name('restaurant_shop_order')->where('id', $orderId)->update(['refund_status' => 1]);

                return;
            }

            $this->doReverse($aid, $order);
        });

        return ['audited' => $needAudit];
    }

    /**
     * 反结账审核（reverse_audit=1 时）：通过→回滚；驳回→refund_status=3
     */
    public function reverseAudit(int $aid, int $orderId, bool $pass): void
    {
        Db::transaction(function () use ($aid, $orderId, $pass) {
            $order = $this->lockOrder($aid, $orderId);

            if (1 !== (int) ($order['refund_status'] ?? 0) || self::ORDER_FINISHED !== (int) $order['status']) {
                throw new BusinessException('无待审核的反结账申请');
            }

            if (!$pass) {
                Db::name('restaurant_shop_order')->where('id', $orderId)->update(['refund_status' => 3]);

                return;
            }

            $this->doReverse($aid, $order);
        });
    }

    /**
     * 反结账实际回滚（事务内调用）
     *
     * @param array<string, mixed> $order
     */
    protected function doReverse(int $aid, array $order): void
    {
        $orderId = (int) $order['id'];

        // 余额原路退回
        if (CashierService::PAY_MONEY === (int) ($order['paytypeid'] ?? 0) && (int) ($order['mid'] ?? 0) > 0) {
            $back = round((float) ($order['pay_amount'] ?? 0), 2);

            if ($back > 0) {
                $member = Db::name('member')->where('aid', $aid)->where('id', (int) $order['mid'])->lock(true)->find();

                if (!empty($member)) {
                    $after = round((float) ($member['money'] ?? 0) + $back, 2);

                    Db::name('member')->where('id', (int) $order['mid'])->where('aid', $aid)->update(['money' => $after]);
                    Db::name('member_moneylog')->insert([
                        'aid'        => $aid,
                        'mid'        => (int) $order['mid'],
                        'money'      => $back,
                        'after'      => $after,
                        'createtime' => time(),
                        'remark'     => '餐饮收银反结账退回 ' . (string) ($order['ordernum'] ?? ''),
                        'paytype'    => 'restaurant_cashdesk',
                    ]);
                }
            }
        }

        // 订单回进行中
        Db::name('restaurant_shop_order')->where('id', $orderId)->update([
            'status'        => self::ORDER_UNPAID,
            'refund_status' => 0,
            'paytime'       => 0,
            'paytype'       => '',
            'paytypeid'     => 0,
            'paynum'        => '',
            'pay_amount'    => 0,
            'pickup_number' => '',
        ]);

        Db::name('restaurant_shop_order_goods')
            ->where('orderid', $orderId)
            ->where('status', 1)
            ->update(['status' => 0]);

        // 取餐记录作废（置过期）
        Db::name('restaurant_take_food')
            ->where('orderid', $orderId)
            ->whereIn('status', [TakeFoodService::STATUS_MAKING, TakeFoodService::STATUS_READY])
            ->update(['status' => TakeFoodService::STATUS_EXPIRED]);

        // 桌台恢复占用（桌台未被新订单占用时）
        $tableId = (int) ($order['tableid'] ?? 0);

        if ($tableId > 0) {
            $table = Db::name('restaurant_table')->where('id', $tableId)->where('aid', $aid)->lock(true)->find();

            if (!empty($table) && 0 === (int) ($table['orderid'] ?? 0)
                && in_array((int) $table['status'], [self::TABLE_FREE, self::TABLE_CLEAN], true)) {
                Db::name('restaurant_table')->where('id', $tableId)->update([
                    'status'      => self::TABLE_BUSY,
                    'orderid'     => $orderId,
                    'update_time' => time(),
                ]);
            }
        }
    }

    /* ================= 收银设置（共用一期 restaurant_cashdesk，增补反结账开关） ================= */

    /**
     * 餐饮收银设置详情（一期配置 + 反结账审核开关）
     */
    public function config(int $aid): array
    {
        $config = $this->cashierService->deskConfig($aid);
        $row    = $this->cashierService->deskRow($aid);

        $config['reverse_audit'] = (int) ($row['reverse_audit'] ?? 0) ? 1 : 0;

        return $config;
    }

    /**
     * 保存餐饮收银设置（支付开关/抹零位数/反结账审核开关）
     */
    public function saveConfig(int $aid, array $data): void
    {
        $row = $this->cashierService->deskRow($aid);

        $update = ['updatetime' => time()];

        foreach (['wxpay', 'cashpay', 'moneypay'] as $key) {
            if (array_key_exists($key, $data)) {
                $update[$key] = (int) $data[$key] === 1 ? 1 : 0;
            }
        }

        if (array_key_exists('remove_zero_length', $data)) {
            $len = (int) $data['remove_zero_length'];

            if (!in_array($len, [0, 1, 2, 3], true)) {
                throw new BusinessException('抹零位数无效');
            }
            $update['remove_zero_length'] = $len;
        }

        if (array_key_exists('reverse_audit', $data)) {
            $update['reverse_audit'] = (int) $data['reverse_audit'] === 1 ? 1 : 0;
        }

        Db::name('restaurant_cashdesk')->where('id', (int) $row['id'])->where('aid', $aid)->update($update);
    }

    /* ================= 报表统计（自定义时间范围） ================= */

    /**
     * 收银统计（单数/营业额/按支付方式/按桌台；自定义时间范围）
     *
     * @return array{order_count: int, total_amount: float, moling_amount: float,
     *               pay_types: array, tables: array, start_time: string, end_time: string}
     */
    public function stat(int $aid, string $startTime, string $endTime): array
    {
        $range = $this->timeRange($startTime, $endTime);

        if (null === $range) {
            // 默认今日
            $range = [strtotime(date('Y-m-d')), time()];
        }

        $base = fn () => ScopeService::query('restaurant_shop_order', $aid)
            ->where('delete', 0)
            ->where('is_bar_table_order', 1)
            ->whereIn('status', [self::ORDER_PAID, self::ORDER_FINISHED])
            ->where('paytime', '>', 0)
            ->where('paytime', '>=', $range[0])
            ->where('paytime', '<=', $range[1]);

        $orderCount   = $base()->count();
        $totalAmount  = round((float) $base()->sum('pay_amount'), 2);
        $molingAmount = round((float) $base()->sum('moling_money'), 2);

        // 按支付方式
        $payRows = $base()->field('paytypeid, count(*) as cnt, sum(pay_amount) as amount')
            ->group('paytypeid')->select()->toArray();

        $payMap = $this->cashierService->payTypeMap($aid);
        $payTypes = array_map(fn (array $v): array => [
            'paytypeid'  => (int) $v['paytypeid'],
            'paytype_text' => $payMap[(int) $v['paytypeid']] ?? '其他',
            'count'      => (int) $v['cnt'],
            'amount'     => round((float) $v['amount'], 2),
        ], $payRows);

        // 按桌台
        $tableRows = ScopeService::queryAs('restaurant_shop_order', $aid, 'o')
            ->leftJoin('restaurant_table t', 't.id = o.tableid')
            ->field('o.tableid, t.name as table_name, count(*) as cnt, sum(o.pay_amount) as amount')
            ->where('o.delete', 0)
            ->where('o.is_bar_table_order', 1)
            ->whereIn('o.status', [self::ORDER_PAID, self::ORDER_FINISHED])
            ->where('o.paytime', '>', 0)
            ->where('o.paytime', '>=', $range[0])
            ->where('o.paytime', '<=', $range[1])
            ->group('o.tableid, t.name')
            ->select()->toArray();

        $tables = array_map(fn (array $v): array => [
            'tableid'    => (int) $v['tableid'],
            'table_name' => (string) ($v['table_name'] ?? '吧台/未知'),
            'count'      => (int) $v['cnt'],
            'amount'     => round((float) $v['amount'], 2),
        ], $tableRows);

        return [
            'order_count'   => $orderCount,
            'total_amount'  => $totalAmount,
            'moling_amount' => $molingAmount,
            'pay_types'     => $payTypes,
            'tables'        => $tables,
            'start_time'    => date('Y-m-d H:i:s', $range[0]),
            'end_time'      => date('Y-m-d H:i:s', $range[1]),
        ];
    }

    /* ================= 内部工具 ================= */

    /**
     * 订单金额重算（商品合计 + 最低消费补足/服务费 + 抹零）
     */
    protected function recalcOrder(int $aid, int $orderId): void
    {
        $order = Db::name('restaurant_shop_order')->where('id', $orderId)->where('aid', $aid)->find();

        if (empty($order)) {
            return;
        }

        $goods = Db::name('restaurant_shop_order_goods')->where('orderid', $orderId)->select()->toArray();

        $productPrice = 0.0;

        foreach ($goods as $g) {
            $productPrice = round($productPrice + (float) ($g['totalprice'] ?? 0), 2);
        }

        // 桌台服务费 + 最低消费补足
        $serviceMoney = 0.0;
        $table        = (int) ($order['tableid'] ?? 0) > 0
            ? Db::name('restaurant_table')->where('id', (int) $order['tableid'])->where('aid', $aid)->find()
            : null;

        if (null !== $table && $productPrice > 0) {
            $feeType = (int) ($table['service_fee_type'] ?? 0);
            $fee     = (float) ($table['service_fee'] ?? 0);

            if ($fee > 0) {
                $serviceMoney = 1 === $feeType
                    ? round($productPrice * $fee / 100, 2)
                    : round($fee, 2);
            }

            $minprice = (float) ($table['minprice'] ?? 0);

            if ($minprice > 0 && $productPrice < $minprice) {
                $serviceMoney = round($serviceMoney + ($minprice - $productPrice), 2);
            }
        }

        // 抹零
        $zeroLength = (int) ($order['remove_zero_length'] ?? 0);
        $calc       = $this->cashierService->removeZero($productPrice + $serviceMoney, 1 === (int) ($order['remove_zero'] ?? 0) ? $zeroLength : 0);

        Db::name('restaurant_shop_order')->where('id', $orderId)->update([
            'product_price' => $productPrice,
            'service_money' => $serviceMoney,
            'moling_money'  => round((float) $calc['moling_money'], 2),
            'totalprice'    => round((float) $calc['totalprice'], 2),
            'pay_amount'    => round((float) $calc['totalprice'], 2),
        ]);
    }

    /**
     * 支付方式可用性校验（restaurant_cashdesk 开关 + 自定义字典启用）
     *
     * @param array<string, mixed> $desk
     * @return string 支付方式名称
     */
    protected function assertPayEnable(int $aid, array $desk, int $payType): string
    {
        switch ($payType) {
            case CashierService::PAY_WECHAT:
                if (1 !== (int) ($desk['wxpay'] ?? 0)) {
                    throw new BusinessException('微信支付未开启');
                }

                return '微信支付';
            case CashierService::PAY_CASH:
                if (1 !== (int) ($desk['cashpay'] ?? 0)) {
                    throw new BusinessException('现金支付未开启');
                }

                return '现金';
            case CashierService::PAY_MONEY:
                if (1 !== (int) ($desk['moneypay'] ?? 0)) {
                    throw new BusinessException('余额支付未开启');
                }

                return '余额';
        }

        if ($payType > CashierService::PAY_CUSTOM_BASE) {
            $customId = $payType - CashierService::PAY_CUSTOM_BASE;

            $custom = ScopeService::query('restaurant_cashdesk_custom_pay', $aid)
                ->where('id', $customId)
                ->where('status', 1)
                ->find();

            if (empty($custom)) {
                throw new BusinessException('自定义支付方式不存在或已停用');
            }

            return (string) ($custom['title'] ?? '');
        }

        throw new BusinessException('不支持的支付方式');
    }

    /**
     * 锁定订单行（租户隔离 + 行锁）
     *
     * @return array<string, mixed>
     */
    protected function lockOrder(int $aid, int $orderId, bool $onlyUnpaid = false): array
    {
        $order = Db::name('restaurant_shop_order')
            ->where('id', $orderId)
            ->where('aid', $aid)
            ->lock(true)
            ->find();

        if (empty($order)) {
            throw new BusinessException('订单不存在', 404, 404);
        }

        if ($onlyUnpaid && self::ORDER_UNPAID !== (int) $order['status']) {
            throw new BusinessException('仅进行中订单可操作');
        }

        return $order;
    }

    /**
     * 查找订单（只读）
     *
     * @return array<string, mixed>
     */
    protected function findOrder(int $aid, int $orderId): array
    {
        $order = Db::name('restaurant_shop_order')
            ->where('id', $orderId)
            ->where('aid', $aid)
            ->find();

        if (empty($order)) {
            throw new BusinessException('订单不存在', 404, 404);
        }

        return $order;
    }

    /**
     * 查找商品行（仅进行中可操作）
     *
     * @return array<string, mixed>
     */
    protected function findGoods(int $aid, int $orderId, int $goodsId): array
    {
        $goods = Db::name('restaurant_shop_order_goods')
            ->where('id', $goodsId)
            ->where('orderid', $orderId)
            ->where('aid', $aid)
            ->lock(true)
            ->find();

        if (empty($goods) || 0 !== (int) ($goods['status'] ?? 0)) {
            throw new BusinessException('菜品行不存在或不可操作');
        }

        return $goods;
    }

    /**
     * 查找桌台
     *
     * @return array<string, mixed>
     */
    protected function findTable(int $aid, int $tableId): array
    {
        $table = ScopeService::query('restaurant_table', $aid)
            ->where('id', $tableId)
            ->find();

        if (empty($table)) {
            throw new BusinessException('桌台不存在', 404, 404);
        }

        return $table;
    }

    /**
     * 时间范围解析（支持 Y-m-d / Y-m-d H:i:s；空则返回 null）
     *
     * @return array{0: int, 1: int}|null
     */
    protected function timeRange(string $startTime, string $endTime): ?array
    {
        $startTime = trim($startTime);
        $endTime   = trim($endTime);

        if ('' === $startTime && '' === $endTime) {
            return null;
        }

        $start = '' !== $startTime ? strtotime($startTime) : strtotime(date('Y-m-d'));
        $end   = '' !== $endTime ? strtotime($endTime) : time();

        if (false === $start || false === $end) {
            throw new BusinessException('时间格式无效');
        }

        // 仅日期（无时分秒）时结束时间取当日 23:59:59
        if ('' !== $endTime && !str_contains($endTime, ':')) {
            $end = strtotime(date('Y-m-d', $end) . ' 23:59:59');
        }

        if ($start > $end) {
            throw new BusinessException('开始时间不能晚于结束时间');
        }

        return [$start, $end];
    }
}
