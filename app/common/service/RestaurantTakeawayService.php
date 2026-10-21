<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;
use think\facade\Log;

/**
 * 餐饮外卖服务（C 端下单/订单 + 支付入账 + 后台状态流转）
 *
 * 表口径（对齐参考系统 canzhao，只读比对）：
 * - restaurant_takeaway_order       外卖订单主表
 *     ordernum=订单号 title=菜品摘要 totalprice=实付金额 product_price=菜品金额
 *     pack_fee=打包费（整单 + 菜品级） freight_price=运费 freight_text=配送方式文案
 *     freight_id/freight_type=配送方式ID/类型(0快递 1自提 2同城 3卡密 5门店) mdid=自提/门店ID
 *     coupon_money/coupon_rid=券抵扣与券记录 scoredk_money/score_money/scoredkscore=积分抵扣
 *     leveldk_money=会员折扣 manjian_money=满减 givescore=赠送积分
 *     pay_amount=统一支付单金额（20261012000001 补列，PayService::payBiz 读此列）
 *     status：0未支付 1已支付 12已接单 2已发货 3已完成 4已关闭
 *     refund_status：0无 1审核中 2已退款 3已驳回
 *     linkman/tel/area/area2(省,市,区)/address/longitude/latitude=收货地址快照
 *     message=客户留言 remark=商家后台备注 field1-5=自定义字段 hexiao_code=核销唯一码
 * - restaurant_takeaway_order_goods 订单菜品快照
 *     proid/name/pic/procode/ggid/ggname/num/cost_price/sell_price/totalprice
 *     real_totalprice=扣除各类优惠后的实际销售额 status 随主单流转
 * - restaurant_product          菜品：stock/sales 下单扣减、取消退款回补
 * - restaurant_product_guige    菜品规格：独立 stock/sales/sell_price（无规格菜品走 product.sell_price）
 * - restaurant_takeaway_sysset  外卖设置：status=外卖开关 min_price=起送价 pack_fee=整单打包费
 * - restaurant_takeaway_freight 配送方式：pstype/pricedata/freeset/free_price/fwprice/peisong_* 范围与阶梯费
 *
 * 计价口径（照参考系统 ApiRestaurantTakeaway::createOrder 1887-1943 行）：
 *   菜品金额 product_price = Σ(规格价 × 数量)
 *   打包费   pack_fee      = 整单打包费 + Σ(菜品打包费 × 数量)
 *   运费     freight_price = 按配送方式计算（见 freightPrice()）
 *   实付     totalprice    = 菜品金额 - 券 + 促销 - 会员折扣 + 运费 + 打包费 - 积分抵扣
 *   pay_amount 与 totalprice 一致，下限 0.01
 *
 * 幂等要点：入账 onPaid() 行锁 + status=0 条件更新；关单/退款回补库存同样条件更新，
 * 重复执行不会重复回补。
 */
class RestaurantTakeawayService
{
    /** 订单状态 */
    public const STATUS_UNPAID    = 0;  // 未支付
    public const STATUS_PAID      = 1;  // 已支付
    public const STATUS_ACCEPTED  = 12; // 已接单
    public const STATUS_SHIPPED   = 2;  // 已发货
    public const STATUS_FINISHED  = 3;  // 已完成（已收货）
    public const STATUS_CLOSED    = 4;  // 已关闭

    /** 订单状态文案（与后台控制器同口径） */
    public const STATUS_TEXT = [
        self::STATUS_UNPAID   => '未支付',
        self::STATUS_PAID     => '已支付',
        self::STATUS_ACCEPTED => '已接单',
        self::STATUS_SHIPPED  => '已发货',
        self::STATUS_FINISHED => '已完成',
        self::STATUS_CLOSED   => '已关闭',
    ];

    /** 退款状态 */
    public const REFUND_NONE      = 0;
    public const REFUND_CHECKING  = 1; // 审核中
    public const REFUND_DONE      = 2; // 已退款
    public const REFUND_REJECT    = 3; // 已驳回

    /** 配送方式类型（对齐参考系统 pstype） */
    public const PSTYPE_EXPRESS   = 0; // 快递
    public const PSTYPE_SELF      = 1; // 到店自提
    public const PSTYPE_SAMECITY  = 2; // 同城配送
    public const PSTYPE_CARD      = 3; // 自动发货/在线卡密
    public const PSTYPE_STORE     = 5; // 门店配送

    public function __construct(
        protected CouponService $couponService,
        protected ScoreService $scoreService,
        protected AddressService $addressService
    ) {
    }

    /* ================= C 端：浏览 ================= */

    /**
     * 外卖首页信息（匿名）：外卖开关/起送价/banner + 配送方式列表（含各自运费预估）
     *
     * @param array<string, mixed> $address 收货地址（可空；空时同城配送按起步费预估）
     * @return array{set: array, freight: array}
     */
    public function apiInfo(int $aid, array $address = []): array
    {
        $set = $this->syssetOf($aid);

        $freightRows = ScopeService::query('restaurant_takeaway_freight', $aid)
            ->where('bid', 0)
            ->where('status', 1)
            ->order('sort', 'desc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        // 参考系统 getList()：无配送方式时兜底「包邮」，保证 C 端仍可下单
        if (empty($freightRows)) {
            $freightRows = [[
                'id'       => 0,
                'name'     => '包邮',
                'pstype'   => self::PSTYPE_EXPRESS,
                'type'     => 0,
                'freeset'  => 0,
                'pricedata' => '[{"region":"全国(默认运费)","fristweight":"1000","fristprice":"0","secondweight":"1000","secondprice":"0"}]',
            ]];
        }

        $freight = [];

        foreach ($freightRows as $row) {
            $rs = $this->freightPrice($row, $address, 0.0, 0, 0.0);

            $freight[] = [
                'id'              => (int) ($row['id'] ?? 0),
                'name'            => (string) ($row['name'] ?? ''),
                'pstype'          => (int) ($row['pstype'] ?? 0),
                'freight_price'   => round((float) ($rs['freight_price'] ?? 0), 2),
                'isoutjuli'       => (int) ($rs['isoutjuli'] ?? 0),
                'msg'             => (string) ($rs['msg'] ?? ''),
                // 配送费文案：快递=运费，自提=服务费，同城=配送费（对齐参考系统 formatFreightList）
                'freight_price_txt' => match ((int) ($row['pstype'] ?? 0)) {
                    self::PSTYPE_SELF     => '服务费',
                    self::PSTYPE_SAMECITY => '配送费',
                    default               => '运费',
                },
                'need_store'      => in_array((int) ($row['pstype'] ?? 0), [self::PSTYPE_SELF, self::PSTYPE_STORE], true),
                'stores'          => in_array((int) ($row['pstype'] ?? 0), [self::PSTYPE_SELF, self::PSTYPE_STORE], true)
                    ? $this->storeList($aid, $row, $address)
                    : [],
                'times'           => $this->freightTimes($row),
            ];
        }

        return [
            'set' => [
                'status'    => (int) ($set['status'] ?? 0),
                'min_price' => (float) ($set['min_price'] ?? 0),
                'pack_fee'  => (float) ($set['pack_fee'] ?? 0),
                'banner'    => (string) ($set['banner'] ?? ''),
                'banner_show' => (int) ($set['banner_show'] ?? 0),
                'start_hours' => (string) ($set['start_hours'] ?? ''),
                'end_hours'   => (string) ($set['end_hours'] ?? ''),
                'takeaway_show' => (int) ($set['takeaway_show'] ?? 1),
                'takeaway_name' => (string) ($set['takeaway_name'] ?? '点外卖'),
                'comment_show'  => (int) ($set['comment_show'] ?? 1),
                'business_info_show' => (int) ($set['business_info_show'] ?? 1),
            ],
            'freight' => $freight,
        ];
    }

    /* ================= C 端：下单 ================= */

    /**
     * 创建外卖订单（事务：校验开关/起送价/菜品/库存 → 计运费打包费 → 券积分叠加 → 扣库存落单）
     *
     * @param array<int, array<string, mixed>> $goods [{product_id,ggid?,num}]
     * @param array<string, mixed>             $extra {address_id, freight_id, store_id?, freight_time?, message?, field1-5?}
     * @return array{id: int, order_sn: string, product_price: float, pack_fee: float, freight_price: float,
     *               coupon_money: float, score_money: float, pay_amount: float, totalprice: float,
     *               freight_text: string, goods: array}
     */
    public function createOrder(int $aid, int $mid, array $goods, array $extra): array
    {
        if (empty($goods)) {
            throw new BusinessException('请选择菜品');
        }

        $set = $this->syssetOf($aid);

        if (1 !== (int) ($set['status'] ?? 0)) {
            throw new BusinessException('外卖已打烊，暂停接单');
        }

        // ===== 收货地址（快递/同城必填；自提/门店按配送方式决定） =====
        $addressId  = (int) ($extra['address_id'] ?? 0);
        $freightId  = (int) ($extra['freight_id'] ?? 0);
        $storeId    = (int) ($extra['store_id'] ?? 0);
        $freightRow = $this->freightOf($aid, $freightId);
        $pstype     = (int) ($freightRow['pstype'] ?? self::PSTYPE_EXPRESS);

        // 自提/门店配送：不要求收货地址，但要求选定门店
        $needAddress = !in_array($pstype, [self::PSTYPE_SELF, self::PSTYPE_STORE], true);

        $address = [];

        if ($needAddress) {
            if ($addressId <= 0) {
                throw new BusinessException('请选择收货地址');
            }

            $row = $this->addressService->findOwned($aid, $mid, $addressId);

            $address = [
                'name'      => (string) ($row['name'] ?? ''),
                'tel'       => (string) ($row['tel'] ?? ''),
                'province'  => (string) ($row['province'] ?? ''),
                'city'      => (string) ($row['city'] ?? ''),
                'district'  => (string) ($row['district'] ?? ''),
                'area'      => (string) ($row['area'] ?? ''),
                'address'   => (string) ($row['address'] ?? ''),
                'longitude' => (string) ($row['longitude'] ?? ''),
                'latitude'  => (string) ($row['latitude'] ?? ''),
            ];
        } elseif ($storeId > 0) {
            $store = ScopeService::query('mendian', $aid)->where('id', $storeId)->find();

            if (empty($store)) {
                throw new BusinessException('所选门店不存在');
            }
        }

        // ===== 菜品归并校验（同菜品同规格合并数量） =====
        $merged = [];

        foreach ($goods as $item) {
            $productId = (int) ($item['product_id'] ?? ($item['proid'] ?? 0));
            $ggid      = (int) ($item['ggid'] ?? 0);
            $num       = (int) ($item['num'] ?? 0);

            if ($productId <= 0) {
                throw new BusinessException('菜品ID无效');
            }
            if ($num <= 0) {
                throw new BusinessException('菜品数量须大于0');
            }

            $key            = $productId . '_' . $ggid;
            $merged[$key]   = ($merged[$key] ?? 0) + $num;
            $lineOf[$key]   = ['proid' => $productId, 'ggid' => $ggid];
        }

        $productRows = [];
        $productPrice = 0.0; // 菜品金额
        $goodsPackFee = 0.0; // 菜品级打包费合计
        $totalNum     = 0;
        $totalWeight  = 0.0;

        foreach ($merged as $key => $num) {
            $productId = (int) $lineOf[$key]['proid'];
            $ggid      = (int) $lineOf[$key]['ggid'];

            $product = ScopeService::query('restaurant_product', $aid)
                ->where('id', $productId)
                ->where('status', 1)
                ->where('is_delete', 0)
                ->find();

            if (empty($product)) {
                throw new BusinessException('菜品不存在或已下架');
            }

            // 外卖限购（limit_takeaway>0 时单笔上限；0=不限）
            $limitTakeaway = (int) ($product['limit_takeaway'] ?? 0);

            if ($limitTakeaway > 0 && $num > $limitTakeaway) {
                throw new BusinessException('菜品「' . $product['name'] . '」每单限购 ' . $limitTakeaway . ' 份');
            }

            // 规格解析：指定 ggid 走规格价与规格库存，否则走菜品主价与主库存
            $guige = null;

            if ($ggid > 0) {
                $guige = ScopeService::query('restaurant_product_guige', $aid)
                    ->where('id', $ggid)
                    ->where('product_id', $productId)
                    ->find();

                if (empty($guige)) {
                    throw new BusinessException('菜品「' . $product['name'] . '」规格不存在');
                }
            }

            $sellPrice = null !== $guige ? (float) $guige['sell_price'] : (float) $product['sell_price'];
            $stock     = null !== $guige ? (int) $guige['stock'] : (int) $product['stock'];

            if ($stock < $num) {
                throw new BusinessException('菜品「' . $product['name'] . '」库存不足');
            }

            $lineTotal = round($sellPrice * $num, 2);
            $packOne   = round((float) ($product['pack_fee'] ?? 0) * $num, 2);

            $productRows[] = [
                'product'   => $product,
                'guige'     => $guige,
                'num'       => $num,
                'sell_price' => $sellPrice,
                'total'     => $lineTotal,
                'pack_fee'  => $packOne,
            ];

            $productPrice = round($productPrice + $lineTotal, 2);
            $goodsPackFee = round($goodsPackFee + $packOne, 2);
            $totalNum    += $num;
            // 重量按件数近似（表无重量列，快递按重量计价时以件数×1000克折算首重单位）
            $totalWeight += $num * 1000;
        }

        if ($productPrice <= 0) {
            throw new BusinessException('菜品价格未配置');
        }

        // ===== 起送价（按菜品金额判断，对齐参考系统 min_price） =====
        $minPrice = (float) ($set['min_price'] ?? 0);

        if ($minPrice > 0 && $productPrice < $minPrice) {
            throw new BusinessException('未满起送价 ￥' . number_format($minPrice, 2) . '，还差 ￥' . number_format($minPrice - $productPrice, 2));
        }

        // ===== 运费（含配送范围校验） =====
        $freightRs = $this->freightPrice($freightRow, $address, $productPrice, $totalNum, $totalWeight);

        if (0 === (int) ($freightRs['status'] ?? 0)) {
            throw new BusinessException((string) ($freightRs['msg'] ?? '配送方式不可用'));
        }

        $freightPrice = round((float) $freightRs['freight_price'], 2);

        // ===== 打包费 = 整单打包费 + 菜品级打包费（参考系统 1936 行） =====
        $packFee = round((float) ($set['pack_fee'] ?? 0) + $goodsPackFee, 2);

        // ===== 券（门槛按菜品金额） =====
        $couponId    = (int) ($extra['coupon_id'] ?? 0);
        $couponPick  = null;
        $couponMoney = 0.0;

        if ($couponId > 0) {
            $couponPick  = $this->couponService->resolveForOrder($aid, $mid, $couponId, $productPrice);
            $couponMoney = (float) $couponPick['coupon_money'];
        }

        // ===== 积分抵扣（口径同堂食：抵扣额不超应付-0.01） =====
        $useScore   = (int) ($extra['use_score'] ?? 0);
        $scoreMoney = 0.0;

        $afterCoupon = max(round($productPrice + $packFee + $freightPrice - $couponMoney, 2), 0.0);

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

        // ===== 配送方式文案（参考系统 1948-1973 行） =====
        [$freightText, $freightType, $mdid] = $this->freightTextOf($aid, $freightRow, $freightPrice, $storeId);

        $now      = time();
        $message  = trim((string) ($extra['message'] ?? ''));
        $title    = (string) ($productRows[0]['product']['name'] ?? '外卖订单');

        if (count($productRows) > 1) {
            $title .= '等';
        }

        return Db::transaction(function () use (
            $aid, $mid, $productRows, $title, $payAmount, $productPrice, $packFee, $freightPrice,
            $couponMoney, $couponPick, $useScore, $scoreMoney, $address, $needAddress, $message,
            $freightRow, $freightText, $freightType, $mdid, $extra, $now
        ) {
            $orderId = (int) Db::name('restaurant_takeaway_order')->insertGetId([
                'aid'            => $aid,
                'bid'            => 0,
                'mid'            => $mid,
                'ordernum'       => '',
                'title'          => $title,
                'totalprice'     => $payAmount,
                'pay_amount'     => $payAmount,
                'product_price'  => $productPrice,
                'pack_fee'       => $packFee,
                'freight_price'  => $freightPrice,
                'coupon_money'   => $couponMoney,
                'score_money'    => $scoreMoney,
                'scoredk_money'  => $scoreMoney,
                'leveldk_money'  => 0,
                'manjian_money'  => 0,
                'scoredkscore'   => $useScore,
                'coupon_rid'     => null !== $couponPick ? (int) $couponPick['record_id'] : 0,
                'givescore'      => 0,
                'status'         => self::STATUS_UNPAID,
                'linkman'        => $needAddress ? (string) $address['name'] : '',
                'tel'            => $needAddress ? (string) $address['tel'] : '',
                'area'           => $needAddress ? (string) $address['area'] : '',
                'area2'          => $needAddress ? ($address['province'] . ',' . $address['city'] . ',' . $address['district']) : '',
                'address'        => $needAddress ? (string) $address['address'] : '',
                'longitude'      => $needAddress ? (string) $address['longitude'] : '',
                'latitude'       => $needAddress ? (string) $address['latitude'] : '',
                'message'        => $message,
                'freight_id'     => (int) ($freightRow['id'] ?? 0),
                'freight_text'   => $freightText,
                'freight_type'   => $freightType,
                'freight_time'   => (string) ($extra['freight_time'] ?? ''),
                'mdid'           => $mdid,
                'paytime'        => 0,
                'platform'       => 'wx',
                'hexiao_code'    => $this->uniqueHexiaoCode(),
                'field1'         => (string) ($extra['field1'] ?? ''),
                'field2'         => (string) ($extra['field2'] ?? ''),
                'field3'         => (string) ($extra['field3'] ?? ''),
                'field4'         => (string) ($extra['field4'] ?? ''),
                'field5'         => (string) ($extra['field5'] ?? ''),
                'delete'         => 0,
                'createtime'     => $now,
            ]);

            // 订单号：RT + 时间 + 租户 + 订单ID（与堂食 RS 前缀区分）
            $orderSn = 'RT' . date('YmdHis', $now)
                . str_pad((string) ($aid % 10000), 4, '0', STR_PAD_LEFT)
                . str_pad((string) ($orderId % 1000000), 6, '0', STR_PAD_LEFT);

            Db::name('restaurant_takeaway_order')->where('id', $orderId)->update(['ordernum' => $orderSn]);

            $goodsOut = [];

            foreach ($productRows as $row) {
                $product = $row['product'];
                $guige   = $row['guige'];
                $num     = (int) $row['num'];

                $pic = (null !== $guige && '' !== (string) ($guige['pic'] ?? ''))
                    ? (string) $guige['pic']
                    : (string) ($product['pic'] ?? '');

                Db::name('restaurant_takeaway_order_goods')->insert([
                    'aid'             => $aid,
                    'bid'             => (int) ($product['bid'] ?? 0),
                    'mid'             => $mid,
                    'orderid'         => $orderId,
                    'ordernum'        => $orderSn,
                    'proid'           => (int) $product['id'],
                    'name'            => (string) ($product['name'] ?? ''),
                    'pic'             => $pic,
                    'procode'         => (string) ($product['procode'] ?? ''),
                    'ggid'            => null !== $guige ? (int) $guige['id'] : 0,
                    'ggname'          => null !== $guige ? (string) ($guige['name'] ?? '') : '',
                    'cid'             => 0,
                    'num'             => $num,
                    'cost_price'      => null !== $guige ? (float) ($guige['cost_price'] ?? 0) : (float) ($product['cost_price'] ?? 0),
                    'sell_price'      => (float) $row['sell_price'],
                    'totalprice'      => (float) $row['total'],
                    'real_totalprice' => (float) $row['total'],
                    'status'          => 0,
                    'createtime'      => $now,
                ]);

                // 扣库存 + 加销量（规格行与菜品主行同步，对齐参考系统 closeOrder 的回补口径）
                if (null !== $guige) {
                    Db::name('restaurant_product_guige')->where('id', (int) $guige['id'])
                        ->update(['stock' => Db::raw('stock-' . $num), 'sales' => Db::raw('sales+' . $num)]);
                }

                Db::name('restaurant_product')->where('id', (int) $product['id'])
                    ->update(['stock' => Db::raw('stock-' . $num), 'sales' => Db::raw('sales+' . $num)]);

                $goodsOut[] = [
                    'product_id' => (int) $product['id'],
                    'name'       => (string) ($product['name'] ?? ''),
                    'ggname'     => null !== $guige ? (string) ($guige['name'] ?? '') : '',
                    'num'        => $num,
                    'price'      => (float) $row['sell_price'],
                ];
            }

            // 用券预锁定（行锁抢占，失败回滚整单）
            if (null !== $couponPick) {
                $this->couponService->bindToOrder((int) $couponPick['record_id'], $orderId, 'restaurant_takeaway');
            }

            // 积分预扣（取消/退款原数返还）
            if ($useScore > 0) {
                $this->scoreService->deductForOrder($aid, $mid, $useScore, '下单抵扣 外卖订单' . $orderSn);
            }

            return [
                'id'            => $orderId,
                'order_sn'      => $orderSn,
                'product_price' => $productPrice,
                'pack_fee'      => $packFee,
                'freight_price' => $freightPrice,
                'freight_text'  => $freightText,
                'coupon_id'     => null !== $couponPick ? (int) $couponPick['coupon_id'] : 0,
                'coupon_money'  => $couponMoney,
                'use_score'     => $useScore,
                'score_money'   => $scoreMoney,
                'totalprice'    => $payAmount,
                'pay_amount'    => $payAmount,
                'goods'         => $goodsOut,
            ];
        });
    }

    /* ================= C 端：订单 ================= */

    /**
     * 我的外卖订单列表（含 goods 数组）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function apiOrderList(int $aid, int $mid, string $status, int $page, int $pageSize): array
    {
        $query = ScopeService::query('restaurant_takeaway_order', $aid)
            ->where('mid', $mid)
            ->where('delete', 0);

        if ('' !== $status) {
            $query->where('status', (int) $status);
        }

        $total = (clone $query)->count();

        $rows = $query->order('id', 'desc')->page($page, $pageSize)->select()->toArray();

        $goodsMap = $this->goodsMapOfOrders(array_map(static fn (array $r) => (int) $r['id'], $rows));

        return [
            'list'     => array_map(fn (array $row) => $this->formatApiRow($row, $goodsMap[(int) $row['id']] ?? []), $rows),
            'total'    => $total,
            'page'     => $page,
            'pagesize' => $pageSize,
        ];
    }

    /**
     * 订单详情（C 端）
     *
     * @return array<string, mixed>
     */
    public function apiDetail(int $aid, int $mid, int $orderId): array
    {
        $order = $this->findOwned($aid, $mid, $orderId);

        $goods = Db::name('restaurant_takeaway_order_goods')
            ->where('orderid', $orderId)->order('id', 'asc')->select()->toArray();

        return $this->formatApiRow($order, $goods) + ['refund_checkremark' => (string) ($order['refund_checkremark'] ?? '')];
    }

    /**
     * 取消订单（仅待支付；释放券 + 返还积分 + 回补库存）
     */
    public function cancelOrder(int $aid, int $mid, int $orderId): void
    {
        $order = $this->findOwned($aid, $mid, $orderId);

        $this->closeInternal($order, '用户取消');
    }

    /**
     * 申请退款（已支付/已接单/已发货可申请；置 refund_status=1 待商家审核）
     */
    public function applyRefund(int $aid, int $mid, int $orderId, string $reason): void
    {
        $order = $this->findOwned($aid, $mid, $orderId);

        $status = (int) $order['status'];

        if (!in_array($status, [self::STATUS_PAID, self::STATUS_ACCEPTED, self::STATUS_SHIPPED], true)) {
            throw new BusinessException('当前订单状态不可申请退款');
        }

        if (self::REFUND_CHECKING === (int) $order['refund_status']) {
            throw new BusinessException('退款申请审核中，请耐心等待');
        }

        if (self::REFUND_DONE === (int) $order['refund_status']) {
            throw new BusinessException('该订单已退款');
        }

        $reason = trim($reason);

        if ('' === $reason) {
            throw new BusinessException('请填写退款原因');
        }

        Db::name('restaurant_takeaway_order')->where('id', $orderId)->update([
            'refund_status' => self::REFUND_CHECKING,
            'refund_reason' => mb_substr($reason, 0, 200),
            'refund_money'  => (float) ($order['pay_amount'] > 0 ? $order['pay_amount'] : $order['totalprice']),
            'refund_time'   => time(),
        ]);
    }

    /**
     * 确认收货（已发货/已接单 → 已完成；幂等）
     */
    public function confirmOrder(int $aid, int $mid, int $orderId): void
    {
        $order = $this->findOwned($aid, $mid, $orderId);

        $status = (int) $order['status'];

        if (self::STATUS_FINISHED === $status) {
            return; // 幂等：已完成直接成功
        }

        if (!in_array($status, [self::STATUS_ACCEPTED, self::STATUS_SHIPPED], true)) {
            throw new BusinessException('当前订单状态不可确认收货');
        }

        $this->finishInternal($orderId);
    }

    /* ================= 支付入账 ================= */

    /**
     * 支付成功入账（PayService 回调路由调用，幂等：行锁 + status=0 条件更新）
     *
     * 入账动作：订单置已支付 + 商品行置1 + 券核销 + 赠送积分
     *
     * @return bool true=本次入账 false=已处理过（幂等）
     */
    public function onPaid(int $orderId, string $payType, string $payNum): bool
    {
        return (bool) Db::transaction(function () use ($orderId, $payType, $payNum) {
            $order = Db::name('restaurant_takeaway_order')->where('id', $orderId)->lock(true)->find();

            if (empty($order) || self::STATUS_UNPAID !== (int) $order['status']) {
                return false; // 已支付/已关闭等：幂等不重复处理
            }

            Db::name('restaurant_takeaway_order')
                ->where('id', $orderId)
                ->where('status', self::STATUS_UNPAID)
                ->update([
                    'status'    => self::STATUS_PAID,
                    'paytime'   => time(),
                    'paytype'   => $payType,
                    'paytypeid' => PayService::PAY_TYPE_IDS[$payType] ?? 0,
                    'paynum'    => $payNum,
                ]);

            // 同步商品行状态（0未付款 → 1已付款）
            Db::name('restaurant_takeaway_order_goods')
                ->where('orderid', $orderId)
                ->where('status', 0)
                ->update(['status' => 1]);

            // 券核销（幂等）
            $this->couponService->consumeByOrder((int) ($order['coupon_rid'] ?? 0), $orderId);

            // 积分赠送：pay_score × floor(实付)
            $payAmount = (float) ($order['pay_amount'] ?? 0) > 0
                ? (float) $order['pay_amount']
                : (float) ($order['totalprice'] ?? 0);

            $this->scoreService->grantForPay(
                (int) ($order['aid'] ?? 0),
                (int) ($order['mid'] ?? 0),
                $payAmount,
                '支付赠送 外卖订单' . (string) ($order['ordernum'] ?? '')
            );

            return true;
        });
    }

    /* ================= 内部工具 ================= */

    /**
     * 外卖设置（租户级，缺失时兜底默认，不自动写库）
     *
     * @return array<string, mixed>
     */
    protected function syssetOf(int $aid): array
    {
        $row = ScopeService::query('restaurant_takeaway_sysset', $aid)->where('bid', 0)->find();

        return empty($row) ? ['status' => 0, 'min_price' => 0, 'pack_fee' => 0] : $row;
    }

    /**
     * 取配送方式（id=0 时兜底「包邮」，对齐参考系统 getList）
     *
     * @return array<string, mixed>
     */
    protected function freightOf(int $aid, int $freightId): array
    {
        if ($freightId > 0) {
            $row = ScopeService::query('restaurant_takeaway_freight', $aid)
                ->where('bid', 0)->where('id', $freightId)->find();

            if (empty($row)) {
                throw new BusinessException('配送方式不存在');
            }

            if (1 !== (int) ($row['status'] ?? 0)) {
                throw new BusinessException('该配送方式已停用');
            }

            return $row;
        }

        // 未指定：取第一个启用的；都没有则兜底包邮
        $row = ScopeService::query('restaurant_takeaway_freight', $aid)
            ->where('bid', 0)->where('status', 1)
            ->order('sort', 'desc')->order('id', 'asc')->find();

        return empty($row) ? [
            'id' => 0, 'name' => '包邮', 'pstype' => self::PSTYPE_EXPRESS, 'type' => 0, 'freeset' => 0,
            'pricedata' => '[{"region":"全国(默认运费)","fristweight":"1000","fristprice":"0","secondweight":"1000","secondprice":"0"}]',
        ] : $row;
    }

    /**
     * 运费计算（口径照参考系统 RestaurantTakeawayFreight::getFreightPrice）
     *
     * 快递(0)：按 pricedata 区域匹配 → 满额包邮判断 → type=1 按重量首重/续重，否则按件数首件/续件
     * 自提(1)/门店(5)：固定 fwprice
     * 同城(2)：配送范围校验（多边形或圆形半径）→ 满额包邮判断 → 起步费 + 超出距离阶梯加价
     *
     * 说明：参考系统同城配送会调用腾讯地图取骑行距离（MapQQ），本项目未接入地图服务，
     * 此处按球面直线距离计算（getDistance），口径保守且无需外部依赖。
     *
     * @param array<string, mixed> $freight
     * @param array<string, mixed> $address
     * @return array{status: int, freight_price: float, isoutjuli: int, msg: string}
     */
    public function freightPrice(array $freight, array $address, float $productPrice, int $totalNum, float $totalWeight): array
    {
        $ok  = ['status' => 1, 'freight_price' => 0.0, 'isoutjuli' => 0, 'msg' => ''];
        $pstype = (int) ($freight['pstype'] ?? self::PSTYPE_EXPRESS);

        // 满额包邮（freeset=1 且菜品金额达到 free_price）
        $freeHit = 1 === (int) ($freight['freeset'] ?? 0)
            && (float) ($freight['free_price'] ?? 0) > 0
            && (float) ($freight['free_price'] ?? 0) <= $productPrice;

        if (self::PSTYPE_EXPRESS === $pstype) {
            $pricedata = json_decode((string) ($freight['pricedata'] ?? ''), true);

            if (!is_array($pricedata)) {
                $pricedata = [];
            }

            $price = 0.0;

            foreach ($pricedata as $thisdata) {
                // region 形如「省[市1,市2];省2[全部地区]」，以 ]; 分组
                foreach (explode('];', (string) ($thisdata['region'] ?? '')) as $regiondata) {
                    $parts = explode('[', $regiondata);
                    $province = trim($parts[0] ?? '');
                    $cities   = trim($parts[1] ?? '');

                    $hit = '全国(默认运费)' === $province
                        || ($province !== '' && $province === (string) ($address['province'] ?? '')
                            && ('全部地区' === $cities || in_array((string) ($address['city'] ?? ''), array_filter(explode(',', $cities)), true)));

                    if (!$hit) {
                        continue;
                    }

                    if ($freeHit) {
                        $price = 0.0;
                        continue;
                    }

                    $fristWeight  = (float) ($thisdata['fristweight'] ?? 0);
                    $fristPrice   = (float) ($thisdata['fristprice'] ?? 0);
                    $secondWeight = (float) ($thisdata['secondweight'] ?? 0);
                    $secondPrice  = (float) ($thisdata['secondprice'] ?? 0);

                    // type=1 按重量计价，否则按件数计价
                    $unit = 1 === (int) ($freight['type'] ?? 0) ? $totalWeight : (float) $totalNum;

                    $price = $fristPrice;

                    if ($secondWeight > 0 && ($unit - $fristWeight) > 0) {
                        $price += ceil(($unit - $fristWeight) / $secondWeight) * $secondPrice;
                    }
                }
            }

            $ok['freight_price'] = round($price, 2);

            return $ok;
        }

        if (in_array($pstype, [self::PSTYPE_SELF, self::PSTYPE_STORE], true)) {
            $ok['freight_price'] = $freeHit ? 0.0 : round((float) ($freight['fwprice'] ?? 0), 2);

            return $ok;
        }

        if (self::PSTYPE_SAMECITY === $pstype) {
            if (empty($address['longitude']) || empty($address['latitude'])) {
                // 未选地址：按起步费预估（对齐参考系统 formatFreightList 128-130 行）
                $ok['freight_price'] = round((float) ($freight['peisong_fee1'] ?? 0), 2);
                $ok['isoutjuli']     = 2;

                return $ok;
            }

            // 配送范围校验：rangetype=1 多边形围栏，否则圆形半径
            if (1 === (int) ($freight['peisong_rangetype'] ?? 0)) {
                $points = [];

                foreach (explode(';', (string) ($freight['peisong_rangepath'] ?? '')) as $p) {
                    $xy = explode(',', $p);

                    if (2 === count($xy)) {
                        $points[] = ['lng' => (float) $xy[0], 'lat' => (float) $xy[1]];
                    }
                }

                if (!empty($points) && !$this->pointInPolygon(
                    (float) $address['latitude'],
                    (float) $address['longitude'],
                    $points
                )) {
                    return ['status' => 0, 'freight_price' => 0.0, 'isoutjuli' => 1, 'msg' => '您的收货地址超出配送范围'];
                }

                $shopLng = (float) ($freight['peisong_lng2'] ?? 0);
                $shopLat = (float) ($freight['peisong_lat2'] ?? 0);
            } else {
                $shopLng = (float) ($freight['peisong_lng'] ?? 0);
                $shopLat = (float) ($freight['peisong_lat'] ?? 0);
                $rangeKm = (float) ($freight['peisong_range'] ?? 0) / 1000;

                if ($shopLng > 0 && $shopLat > 0 && $rangeKm > 0) {
                    $juli = $this->getDistance((float) $address['longitude'], (float) $address['latitude'], $shopLng, $shopLat);

                    if ($juli > $rangeKm) {
                        return ['status' => 0, 'freight_price' => 0.0, 'isoutjuli' => 1, 'msg' => '您的收货地址超出配送范围'];
                    }
                }
            }

            if ($freeHit) {
                $ok['freight_price'] = 0.0;

                return $ok;
            }

            // 起步费 + 超出距离阶梯加价
            $juli  = ($shopLng > 0 && $shopLat > 0)
                ? $this->getDistance((float) $address['longitude'], (float) $address['latitude'], $shopLng, $shopLat)
                : 0.0;
            $price = (float) ($freight['peisong_fee1'] ?? 0);
            $juli1 = (float) ($freight['peisong_juli1'] ?? 0);
            $juli2 = (float) ($freight['peisong_juli2'] ?? 0);
            $fee2  = (float) ($freight['peisong_fee2'] ?? 0);

            if ($juli2 > 0 && ($juli - $juli1) > 0) {
                $price += ceil(($juli - $juli1) / $juli2) * $fee2;
            }

            $ok['freight_price'] = round($price, 2);

            return $ok;
        }

        // 卡密等其它类型：不收运费
        return $ok;
    }

    /**
     * 球面直线距离（千米，Haversine）
     */
    protected function getDistance(float $lng1, float $lat1, float $lng2, float $lat2): float
    {
        $radLat1 = deg2rad($lat1);
        $radLat2 = deg2rad($lat2);
        $a       = $radLat1 - $radLat2;
        $b       = deg2rad($lng1) - deg2rad($lng2);

        $s = 2 * asin(sqrt(pow(sin($a / 2), 2) + cos($radLat1) * cos($radLat2) * pow(sin($b / 2), 2)));

        return round($s * 6378.137, 3);
    }

    /**
     * 点是否在多边形内（射线法）
     *
     * @param array<int, array{lng: float, lat: float}> $points
     */
    protected function pointInPolygon(float $lat, float $lng, array $points): bool
    {
        $n     = count($points);
        $inside = false;

        if ($n < 3) {
            return false;
        }

        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $xi = $points[$i]['lng'];
            $yi = $points[$i]['lat'];
            $xj = $points[$j]['lng'];
            $yj = $points[$j]['lat'];

            if ((($yi > $lat) !== ($yj > $lat))
                && ($lng < ($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: 1e-12) + $xi)) {
                $inside = !$inside;
            }
        }

        return $inside;
    }

    /**
     * 配送方式文案与类型（参考系统 1948-1973 行口径）
     *
     * @return array{0: string, 1: int, 2: int} [freight_text, freight_type, mdid]
     */
    protected function freightTextOf(int $aid, array $freight, float $freightPrice, int $storeId): array
    {
        $name   = (string) ($freight['name'] ?? '');
        $pstype = (int) ($freight['pstype'] ?? self::PSTYPE_EXPRESS);

        if (self::PSTYPE_SELF === $pstype || self::PSTYPE_STORE === $pstype) {
            $storeName = $storeId > 0
                ? (string) ScopeService::query('mendian', $aid)->where('id', $storeId)->value('name')
                : '';

            return [$name . ('' !== $storeName ? '[' . $storeName . ']' : ''), $pstype, $storeId];
        }

        if (in_array($pstype, [self::PSTYPE_EXPRESS, self::PSTYPE_SAMECITY], true)) {
            return [$name . '(' . number_format($freightPrice, 2, '.', '') . '元)', $pstype, 0];
        }

        if (self::PSTYPE_CARD === $pstype) {
            return [$name, $pstype, 0];
        }

        return ['包邮', 0, 0];
    }

    /**
     * 自提/门店配送的可选门店列表
     *
     * @param array<string, mixed> $freight
     * @param array<string, mixed> $address
     * @return array<int, array<string, mixed>>
     */
    protected function storeList(int $aid, array $freight, array $address): array
    {
        $query = ScopeService::query('mendian', $aid)->where('status', 1);

        // storetype=1 时限定指定门店
        if (1 === (int) ($freight['storetype'] ?? 0)) {
            $ids = array_filter(array_map('intval', explode(',', (string) ($freight['storeids'] ?? ''))));

            if (empty($ids)) {
                return [];
            }

            $query->whereIn('id', $ids);
        }

        $rows = $query->field('id,name,pic,longitude,latitude,address,tel')
            ->order('sort', 'desc')->order('id', 'asc')->limit(50)->select()->toArray();

        $lng = (float) ($address['longitude'] ?? 0);
        $lat = (float) ($address['latitude'] ?? 0);

        foreach ($rows as $k => $v) {
            $rows[$k]['juli'] = ($lng > 0 && $lat > 0 && !empty($v['longitude']) && !empty($v['latitude']))
                ? '距离' . $this->getDistance($lng, $lat, (float) $v['longitude'], (float) $v['latitude']) . '千米'
                : '';
        }

        return $rows;
    }

    /**
     * 配送时段可选项（pstimeset=1 时用 pstimedata 生成，否则按 psprehour 生成整点时段）
     *
     * @param array<string, mixed> $freight
     * @return array<int, string>
     */
    protected function freightTimes(array $freight): array
    {
        $times = [];

        if (1 === (int) ($freight['pstimeset'] ?? 0)) {
            $data = json_decode((string) ($freight['pstimedata'] ?? ''), true);

            if (is_array($data)) {
                $weekText = [1 => '周一', 2 => '周二', 3 => '周三', 4 => '周四', 5 => '周五', 6 => '周六', 7 => '周日'];

                foreach ($data as $item) {
                    $day = (int) ($item['day'] ?? 0);
                    $times[] = ($weekText[$day] ?? '') . ' '
                        . str_pad((string) ($item['hour'] ?? 0), 2, '0', STR_PAD_LEFT) . ':' . str_pad((string) ($item['minute'] ?? 0), 2, '0', STR_PAD_LEFT)
                        . '~'
                        . str_pad((string) ($item['hour2'] ?? 0), 2, '0', STR_PAD_LEFT) . ':' . str_pad((string) ($item['minute2'] ?? 0), 2, '0', STR_PAD_LEFT);
                }
            }

            return $times;
        }

        // 未配置时段：按提前小时数生成今明两天的整点时段
        $preHour = (int) ($freight['psprehour'] ?? 4);
        $preHour = $preHour > 0 ? $preHour : 4;

        for ($d = 0; $d < 2; ++$d) {
            $date = date('Y-m-d', strtotime('+' . $d . ' day'));

            for ($h = 9; $h <= 20; ++$h) {
                $start = strtotime($date . ' ' . str_pad((string) $h, 2, '0', STR_PAD_LEFT) . ':00:00');

                if ($start - time() < $preHour * 3600) {
                    continue; // 不足提前量
                }

                $times[] = $date . ' ' . str_pad((string) $h, 2, '0', STR_PAD_LEFT) . ':00~'
                    . str_pad((string) ($h + 1), 2, '0', STR_PAD_LEFT) . ':00';
            }
        }

        return $times;
    }

    /**
     * 核销唯一码（hexiao_code 有唯一索引，冲突时重试）
     */
    protected function uniqueHexiaoCode(): string
    {
        for ($i = 0; $i < 10; ++$i) {
            $code = strtoupper(bin2hex(random_bytes(8)));

            $exists = Db::name('restaurant_takeaway_order')->where('hexiao_code', $code)->find();

            if (empty($exists)) {
                return $code;
            }
        }

        // 兜底：加时间戳保证唯一
        return strtoupper(bin2hex(random_bytes(4))) . time();
    }

    /**
     * 查本会员订单（不存在抛 404）
     *
     * @return array<string, mixed>
     */
    protected function findOwned(int $aid, int $mid, int $orderId): array
    {
        if ($orderId <= 0) {
            throw new BusinessException('缺少订单ID');
        }

        $order = ScopeService::query('restaurant_takeaway_order', $aid)
            ->where('id', $orderId)
            ->where('mid', $mid)
            ->where('delete', 0)
            ->find();

        if (empty($order)) {
            throw new BusinessException('订单不存在', 404, 404);
        }

        return $order;
    }

    /**
     * 关单内部实现（仅待支付；条件更新幂等 → 回补库存 + 释放券 + 返还积分）
     *
     * @param array<string, mixed> $order
     */
    public function closeInternal(array $order, string $scene): bool
    {
        $orderId = (int) $order['id'];

        if (self::STATUS_UNPAID !== (int) $order['status']) {
            throw new BusinessException('当前订单状态不可关闭（仅未支付可关闭）');
        }

        return (bool) Db::transaction(function () use ($order, $orderId, $scene) {
            $affected = Db::name('restaurant_takeaway_order')
                ->where('id', $orderId)
                ->where('status', self::STATUS_UNPAID)
                ->update(['status' => self::STATUS_CLOSED]);

            if (1 !== $affected) {
                return false; // 并发已变更：幂等跳过
            }

            Db::name('restaurant_takeaway_order_goods')
                ->where('orderid', $orderId)->where('status', 0)
                ->update(['status' => self::STATUS_CLOSED]);

            $this->restoreStock($orderId);

            // 释放券预锁定
            $this->couponService->releaseByOrder((int) ($order['coupon_rid'] ?? 0), $orderId);

            // 返还抵扣积分
            if ((int) ($order['scoredkscore'] ?? 0) > 0) {
                $this->scoreService->refundForCancel(
                    (int) ($order['aid'] ?? 0),
                    (int) ($order['mid'] ?? 0),
                    (int) $order['scoredkscore'],
                    $scene . '返还 外卖订单' . (string) ($order['ordernum'] ?? '')
                );
            }

            return true;
        });
    }

    /**
     * 完成订单内部实现（条件更新幂等）
     */
    public function finishInternal(int $orderId): bool
    {
        $affected = Db::name('restaurant_takeaway_order')
            ->where('id', $orderId)
            ->whereIn('status', [self::STATUS_ACCEPTED, self::STATUS_SHIPPED])
            ->update(['status' => self::STATUS_FINISHED, 'collect_time' => time()]);

        if ($affected > 0) {
            Db::name('restaurant_takeaway_order_goods')
                ->where('orderid', $orderId)
                ->whereIn('status', [1, self::STATUS_ACCEPTED, self::STATUS_SHIPPED])
                ->update(['status' => self::STATUS_FINISHED, 'endtime' => time()]);
        }

        return $affected > 0;
    }

    /**
     * 回补库存与销量（按商品行；幂等由调用方的条件更新保证）
     */
    protected function restoreStock(int $orderId): void
    {
        $rows = Db::name('restaurant_takeaway_order_goods')
            ->where('orderid', $orderId)->select()->toArray();

        foreach ($rows as $row) {
            $num   = (int) ($row['num'] ?? 0);
            $ggid  = (int) ($row['ggid'] ?? 0);
            $proid = (int) ($row['proid'] ?? 0);

            if ($num <= 0) {
                continue;
            }

            if ($ggid > 0) {
                Db::name('restaurant_product_guige')->where('id', $ggid)
                    ->update(['stock' => Db::raw('stock+' . $num), 'sales' => Db::raw('sales-' . $num)]);
            }

            if ($proid > 0) {
                Db::name('restaurant_product')->where('id', $proid)
                    ->update(['stock' => Db::raw('stock+' . $num), 'sales' => Db::raw('sales-' . $num)]);
            }
        }
    }

    /**
     * 批量取订单商品行（按 orderid 分组，避免 N+1）
     *
     * @param array<int, int> $orderIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    protected function goodsMapOfOrders(array $orderIds): array
    {
        if (empty($orderIds)) {
            return [];
        }

        $rows = Db::name('restaurant_takeaway_order_goods')
            ->whereIn('orderid', $orderIds)->order('id', 'asc')->select()->toArray();

        $map = [];

        foreach ($rows as $row) {
            $map[(int) $row['orderid']][] = $row;
        }

        return $map;
    }

    /**
     * C 端订单行格式化
     *
     * @param array<string, mixed>              $row
     * @param array<int, array<string, mixed>> $goods
     * @return array<string, mixed>
     */
    protected function formatApiRow(array $row, array $goods): array
    {
        $status = (int) ($row['status'] ?? 0);

        return [
            'id'             => (int) ($row['id'] ?? 0),
            'ordernum'       => (string) ($row['ordernum'] ?? ''),
            'title'          => (string) ($row['title'] ?? ''),
            'status'         => $status,
            'status_text'    => self::STATUS_TEXT[$status] ?? '未知',
            'refund_status'  => (int) ($row['refund_status'] ?? 0),
            'product_price'  => (float) ($row['product_price'] ?? 0),
            'pack_fee'       => (float) ($row['pack_fee'] ?? 0),
            'freight_price'  => (float) ($row['freight_price'] ?? 0),
            'coupon_money'   => (float) ($row['coupon_money'] ?? 0),
            'score_money'    => (float) ($row['score_money'] ?? ($row['scoredk_money'] ?? 0)),
            'totalprice'     => (float) ($row['totalprice'] ?? 0),
            'pay_amount'     => (float) ($row['pay_amount'] ?? $row['totalprice'] ?? 0),
            'freight_text'   => (string) ($row['freight_text'] ?? ''),
            'freight_time'   => (string) ($row['freight_time'] ?? ''),
            'linkman'        => (string) ($row['linkman'] ?? ''),
            'tel'            => (string) ($row['tel'] ?? ''),
            'address'        => trim(((string) ($row['area'] ?? '')) . ((string) ($row['address'] ?? ''))),
            'message'        => (string) ($row['message'] ?? ''),
            'remark'         => (string) ($row['remark'] ?? ''),
            'express_com'    => (string) ($row['express_com'] ?? ''),
            'express_no'     => (string) ($row['express_no'] ?? ''),
            'paytype'        => (string) ($row['paytype'] ?? ''),
            'pay_time'       => (int) ($row['paytime'] ?? 0),
            'createtime'     => (int) ($row['createtime'] ?? 0),
            'hexiao_code'    => (string) ($row['hexiao_code'] ?? ''),
            'goods'          => array_map(static fn (array $g) => [
                'id'         => (int) ($g['id'] ?? 0),
                'product_id' => (int) ($g['proid'] ?? 0),
                'name'       => (string) ($g['name'] ?? ''),
                'pic'        => (string) ($g['pic'] ?? ''),
                'ggname'     => (string) ($g['ggname'] ?? ''),
                'num'        => (int) ($g['num'] ?? 0),
                'price'      => (float) ($g['sell_price'] ?? 0),
                'totalprice' => (float) ($g['totalprice'] ?? 0),
            ], $goods),
        ];
    }
}
