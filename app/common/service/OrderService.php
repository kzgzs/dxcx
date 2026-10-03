<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;
use think\facade\Log;

/**
 * 订单服务（api 会员侧 + admin 租户后台侧）
 *
 * 表口径（基线复用）：
 * - shop_order        订单主表：ordernum=订单号 totalprice=实付金额（元）
 *                     status：0待支付 1已支付待发货 2已发货 3已完成 4已取消（关闭）
 *                     地址快照：linkman/tel/area/area2(省,市,区)/address
 *                     支付：paytime/paytype/paytypeid/paynum；物流：express_com/express_no/send_time；收货：collect_time
 * - shop_order_goods  订单商品快照：name/pic/num/sell_price/totalprice
 * - shop_product      库存 stock（下单扣减、取消/超时回补）
 *
 * 幂等要点：取消/超时取消均先按 status=0 条件更新再回补库存，重复执行不会重复回补
 */
class OrderService
{
    public const STATUS_UNPAID    = 0; // 待支付
    public const STATUS_PAID      = 1; // 已支付待发货
    public const STATUS_SHIPPED   = 2; // 已发货
    public const STATUS_FINISHED  = 3; // 已完成（已收货）
    public const STATUS_CANCELLED = 4; // 已取消

    public const STATUS_TEXT = [
        self::STATUS_UNPAID    => '待支付',
        self::STATUS_PAID      => '已支付待发货',
        self::STATUS_SHIPPED   => '已发货',
        self::STATUS_FINISHED  => '已完成',
        self::STATUS_CANCELLED => '已取消',
    ];

    public function __construct(
        protected AddressService $addressService,
        protected PayService $payService,
        protected CouponService $couponService,
        protected PromotionService $promotionService,
        protected ScoreService $scoreService,
        protected CuxiaoService $cuxiaoService
    ) {
    }

    /* ================= api 会员侧 ================= */

    /**
     * 创建订单（事务：校验商品上架/库存 → 锁定扣减库存 → 生成订单与商品快照）
     *
     * 【计价叠加规则（P5）】商品金额 → 先满减促销（自动择优：所有生效活动中优惠额最大者）
     *   → 再商品促销 cuxiao（满减直接减；满赠追加赠品行；换购勾选时追加换购行，加价计入实付）
     *   → 再优惠券（券门槛按促销后金额判断）→ 再积分抵扣，实付下限 0.01
     *
     * 用券（可选 coupon_id）：校验归属/未使用/有效期内/门槛 → 计价（满减减 money、折扣乘 discount/10）
     *   → 订单记录 coupon_money/coupon_rid → 事务内行锁预锁定 record.order_id
     * 积分（可选 use_score）：开关/比例校验 → 抵扣额 floor(use_score/ratio*100)/100 且不超应付-0.01
     *   → 下单即预扣（防多笔未支付单并发双花），取消（手动+超时命令）原数返还
     *
     * @param int $huangou 是否参与换购（1=勾选换购，批次4-A cuxiao type3）
     *
     * @return array{id: int, order_sn: string, pay_amount: float, pay_expire_time: int, coupon_id: int, coupon_money: float, promotion_id: int, promotion_money: float, cuxiao_ids: string, cuxiao_money: float, use_score: int, score_money: float}
     */
    public function create(int $aid, int $mid, int $goodsId, int $num, int $addressId, int $couponId = 0, int $useScore = 0, int $ggid = 0, int $huangou = 0): array
    {
        if ($goodsId <= 0) {
            throw new BusinessException('请选择商品');
        }
        if ($num <= 0) {
            throw new BusinessException('购买数量必须大于0');
        }

        // 商品校验：上架 + 未删除 + 本租户
        $goods = ScopeService::query('shop_product', $aid)
            ->where('id', $goodsId)
            ->where('status', 1)
            ->where('deleted', 0)
            ->find();

        if (empty($goods)) {
            throw new BusinessException('商品不存在或已下架', 404, 404);
        }

        // SKU 规格校验（ggid>0 时价格/库存/名称均以 shop_guige 行为准）
        $sku = null;

        if ($ggid > 0) {
            $sku = Db::name('shop_guige')
                ->where('aid', $aid)
                ->where('proid', $goodsId)
                ->where('id', $ggid)
                ->find();

            if (empty($sku)) {
                throw new BusinessException('商品规格不存在或已删除', 404, 404);
            }
        }

        // 收货地址校验（本人名下有效地址）
        $address = $this->addressService->findOwned($aid, $mid, $addressId);

        $price       = null !== $sku ? (float) $sku['sell_price'] : (float) $goods['sell_price'];
        $productPrice = round($price * $num, 2);
        $now         = time();
        $expireTime  = $now + PayService::payTimeout();
        $name        = (string) ($goods['name'] ?? '');
        $ggName      = null !== $sku ? (string) ($sku['name'] ?? '') : '';

        // ===== 计价叠加管道：商品金额 → 满减促销（自动择优）→ 优惠券 → 积分抵扣，实付下限 0.01 =====

        // ① 满减促销：自动选择生效中（status=1、时间区间内、满足门槛）且优惠额最大的活动
        $promotionId    = 0;
        $promotionMoney = 0.0;
        $best           = $this->promotionService->pickBest($aid, $productPrice, $num);

        if (null !== $best) {
            $promotionId    = (int) $best['promotion_id'];
            $promotionMoney = (float) $best['promotion_money'];
        }

        $afterPromo = max(round($productPrice - $promotionMoney, 2), 0.0);

        // ①.5 商品促销 cuxiao（批次4-A 任务186）：满减直接减；满赠/换购产出行；与 promotion 满减表并行生效
        $memberRow = ScopeService::query('member', $aid)->where('id', $mid)->find();
        $cx        = $this->cuxiaoService->applyForOrder($aid, $goods, null !== $memberRow ? (array) $memberRow : null, $num, $productPrice, $huangou > 0);
        $cuxiaoMoney = round((float) $cx['cuxiao_money'], 2);
        $cuxiaoIds   = implode(',', $cx['cuxiao_ids']);

        $afterCuxiao = max(round($afterPromo - $cuxiaoMoney, 2), 0.0);

        // ② 用券校验与计价（门槛按促销后金额判断；事务外预校验，事务内再行锁抢占）
        $couponPick  = null;
        $couponMoney = 0.0;

        if ($couponId > 0) {
            $couponPick  = $this->couponService->resolveForOrder($aid, $mid, $couponId, $afterCuxiao);
            $couponMoney = (float) $couponPick['coupon_money'];
        }

        $afterCoupon = max(round($afterCuxiao - $couponMoney, 2), 0.0);

        // ③ 积分抵扣：抵扣额 = floor(use_score/ratio*100)/100，不超过应付-0.01
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

        $payBase = max(round($afterCoupon - $scoreMoney, 2), 0.01);

        // ④ 满赠/换购行解析（批次4-A）：赠品价格 0 不扣库存（商品失效则静默跳过）；换购加价计入实付
        $giftProduct = null;
        $giftSku     = null;

        if (null !== $cx['gift']) {
            $giftProduct = ScopeService::query('shop_product', $aid)
                ->where('id', (int) $cx['gift']['proid'])
                ->where('status', 1)
                ->where('deleted', 0)
                ->find();

            if (empty($giftProduct)) {
                $cx['gift'] = null; // 赠品失效：静默放弃
            } elseif ((int) $cx['gift']['ggid'] > 0) {
                $giftSku = Db::name('shop_guige')
                    ->where('aid', $aid)
                    ->where('proid', (int) $cx['gift']['proid'])
                    ->where('id', (int) $cx['gift']['ggid'])
                    ->find();

                if (empty($giftSku)) {
                    $giftSku = null;
                }
            }
        }

        $hgProduct = null;
        $hgSku     = null;
        $hgTotal   = 0.0;

        if (null !== $cx['huangou']) {
            $hgProduct = ScopeService::query('shop_product', $aid)
                ->where('id', (int) $cx['huangou']['proid'])
                ->where('status', 1)
                ->where('deleted', 0)
                ->find();

            if (empty($hgProduct)) {
                throw new BusinessException('换购商品已下架，无法参与换购');
            }

            if ((int) $cx['huangou']['ggid'] > 0) {
                $hgSku = Db::name('shop_guige')
                    ->where('aid', $aid)
                    ->where('proid', (int) $cx['huangou']['proid'])
                    ->where('id', (int) $cx['huangou']['ggid'])
                    ->find();

                if (empty($hgSku)) {
                    throw new BusinessException('换购商品规格不存在');
                }
            }

            $hgTotal = round((float) $cx['huangou']['price'], 2);
        }

        $totalPrice = round($payBase + $hgTotal, 2);

        return Db::transaction(function () use ($aid, $mid, $goodsId, $num, $goods, $sku, $ggid, $ggName, $address, $price, $productPrice, $totalPrice, $payBase, $now, $expireTime, $name, $couponPick, $couponMoney, $promotionId, $promotionMoney, $cuxiaoIds, $cuxiaoMoney, $useScore, $scoreMoney, $cx, $giftProduct, $giftSku, $hgProduct, $hgSku, $hgTotal) {
            // 锁定库存：条件更新保证并发下 stock>=num 才扣减成功
            $affected = Db::name('shop_product')
                ->where('id', $goodsId)
                ->where('aid', $aid)
                ->where('stock', '>=', $num)
                ->dec('stock', $num)
                ->update();

            if (1 !== $affected) {
                throw new BusinessException('库存不足，下单失败');
            }

            // SKU 行库存同步条件扣减（商品主表 stock 为 SKU 汇总值，两者保持一致）
            if (null !== $sku) {
                $skuAffected = Db::name('shop_guige')
                    ->where('id', (int) $sku['id'])
                    ->where('aid', $aid)
                    ->where('stock', '>=', $num)
                    ->dec('stock', $num)
                    ->update();

                if (1 !== $skuAffected) {
                    throw new BusinessException('所选规格库存不足，下单失败');
                }
            }

            // 订单主记录（ordernum 先占位，拿到自增 id 后生成订单号）
            $orderId = (int) Db::name('shop_order')->insertGetId([
                'aid'             => $aid,
                'bid'             => 0,
                'mid'             => $mid,
                'ordernum'        => '',
                'title'           => $name . ' x' . $num,
                'totalprice'      => $totalPrice,
                'product_price'   => $productPrice,
                'coupon_money'    => $couponMoney,
                'coupon_rid'      => null !== $couponPick ? (string) $couponPick['record_id'] : '',
                'promotion_id'    => $promotionId,
                'promotion_money' => $promotionMoney,
                'cuxiao_ids'      => $cuxiaoIds,
                'cuxiao_money'    => $cuxiaoMoney,
                'use_score'       => $useScore,
                'score_money'     => $scoreMoney,
                'freight_price'   => 0, // 运费本期 0，字段保留
                'createtime'      => $now,
                'status'          => self::STATUS_UNPAID,
                'linkman'         => (string) ($address['name'] ?? ''),
                'tel'             => (string) ($address['tel'] ?? ''),
                'area'            => trim(implode('', array_filter([
                    (string) ($address['province'] ?? ''),
                    (string) ($address['city'] ?? ''),
                    (string) ($address['district'] ?? ''),
                ]))),
                'area2'           => implode(',', [
                    (string) ($address['province'] ?? ''),
                    (string) ($address['city'] ?? ''),
                    (string) ($address['district'] ?? ''),
                ]),
                'address'         => (string) ($address['address'] ?? ''),
                'pay_expire_time' => $expireTime,
                'platform'        => 'api',
            ]);

            // 订单号：日期时间 + 租户(4位) + 序列(6位)
            $orderSn = date('YmdHis', $now)
                . str_pad((string) ($aid % 10000), 4, '0', STR_PAD_LEFT)
                . str_pad((string) ($orderId % 1000000), 6, '0', STR_PAD_LEFT);

            Db::name('shop_order')->where('id', $orderId)->update(['ordernum' => $orderSn]);

            // 用券预锁定（行锁抢占，并发下同一券只能绑定一个订单，失败回滚整单）
            if (null !== $couponPick) {
                $this->couponService->bindToOrder((int) $couponPick['record_id'], $orderId);
            }

            // 积分预扣（行锁+余额校验；随本事务回滚，取消时原数返还）
            if ($useScore > 0) {
                $this->scoreService->deductForOrder($aid, $mid, $useScore, '下单抵扣 订单' . $orderSn);
            }

            // 换购商品库存条件扣减（批次4-A cuxiao type3，失败整单回滚）
            if (null !== $hgProduct) {
                $hgAffected = Db::name('shop_product')
                    ->where('id', (int) $hgProduct['id'])
                    ->where('aid', $aid)
                    ->where('stock', '>=', 1)
                    ->dec('stock', 1)
                    ->update();

                if (1 !== $hgAffected) {
                    throw new BusinessException('换购商品库存不足，下单失败');
                }

                if (null !== $hgSku) {
                    $hgSkuAffected = Db::name('shop_guige')
                        ->where('id', (int) $hgSku['id'])
                        ->where('aid', $aid)
                        ->where('stock', '>=', 1)
                        ->dec('stock', 1)
                        ->update();

                    if (1 !== $hgSkuAffected) {
                        throw new BusinessException('换购商品规格库存不足，下单失败');
                    }
                }
            }

            // 商品快照（ggid/ggname 规格快照，pic 优先取 SKU 规格图）
            Db::name('shop_order_goods')->insert([
                'aid'             => $aid,
                'bid'             => 0,
                'mid'             => $mid,
                'orderid'         => $orderId,
                'ordernum'        => $orderSn,
                'proid'           => $goodsId,
                'name'            => $name,
                'pic'             => (null !== $sku && '' !== trim((string) ($sku['pic'] ?? '')))
                    ? (string) $sku['pic']
                    : GoodsService::firstPic((string) ($goods['pics'] ?? ''), (string) ($goods['pic'] ?? '')),
                'ggid'            => $ggid,
                'ggname'          => $ggName,
                'cid'             => (string) ($goods['cid'] ?? '0'),
                'num'             => $num,
                'sell_price'      => $price,
                'totalprice'      => $payBase,
                'real_totalprice' => $payBase,
                'gtype'           => 0,
                'status'          => 0,
                'createtime'      => $now,
            ]);

            // 满赠赠品行（批次4-A cuxiao type2）：价格 0、不扣库存，gtype=1
            if (null !== $giftProduct) {
                Db::name('shop_order_goods')->insert([
                    'aid'             => $aid,
                    'bid'             => 0,
                    'mid'             => $mid,
                    'orderid'         => $orderId,
                    'ordernum'        => $orderSn,
                    'proid'           => (int) $giftProduct['id'],
                    'name'            => (string) ($giftProduct['name'] ?? ''),
                    'pic'             => (null !== $giftSku && '' !== trim((string) ($giftSku['pic'] ?? '')))
                        ? (string) $giftSku['pic']
                        : GoodsService::firstPic((string) ($giftProduct['pics'] ?? ''), (string) ($giftProduct['pic'] ?? '')),
                    'ggid'            => null !== $giftSku ? (int) $giftSku['id'] : 0,
                    'ggname'          => null !== $giftSku ? (string) ($giftSku['name'] ?? '') : '',
                    'cid'             => (string) ($giftProduct['cid'] ?? '0'),
                    'num'             => 1,
                    'sell_price'      => 0,
                    'totalprice'      => 0,
                    'real_totalprice' => 0,
                    'gtype'           => 1,
                    'status'          => 0,
                    'createtime'      => $now,
                ]);
            }

            // 换购行（批次4-A cuxiao type3）：加价计入实付，gtype=2
            if (null !== $hgProduct) {
                Db::name('shop_order_goods')->insert([
                    'aid'             => $aid,
                    'bid'             => 0,
                    'mid'             => $mid,
                    'orderid'         => $orderId,
                    'ordernum'        => $orderSn,
                    'proid'           => (int) $hgProduct['id'],
                    'name'            => (string) ($hgProduct['name'] ?? ''),
                    'pic'             => (null !== $hgSku && '' !== trim((string) ($hgSku['pic'] ?? '')))
                        ? (string) $hgSku['pic']
                        : GoodsService::firstPic((string) ($hgProduct['pics'] ?? ''), (string) ($hgProduct['pic'] ?? '')),
                    'ggid'            => null !== $hgSku ? (int) $hgSku['id'] : 0,
                    'ggname'          => null !== $hgSku ? (string) ($hgSku['name'] ?? '') : '',
                    'cid'             => (string) ($hgProduct['cid'] ?? '0'),
                    'num'             => 1,
                    'sell_price'      => $hgTotal,
                    'totalprice'      => $hgTotal,
                    'real_totalprice' => $hgTotal,
                    'gtype'           => 2,
                    'status'          => 0,
                    'createtime'      => $now,
                ]);
            }

            return [
                'id'              => $orderId,
                'order_sn'        => $orderSn,
                'pay_amount'      => (float) $totalPrice,
                'pay_expire_time' => $expireTime,
                'coupon_id'       => null !== $couponPick ? (int) $couponPick['coupon_id'] : 0,
                'coupon_money'    => $couponMoney,
                'promotion_id'    => $promotionId,
                'promotion_money' => $promotionMoney,
                'cuxiao_ids'      => $cuxiaoIds,
                'cuxiao_money'    => $cuxiaoMoney,
                'use_score'       => $useScore,
                'score_money'     => $scoreMoney,
            ];
        });
    }

    /**
     * 我的订单列表（status 空=全部，含商品快照数组）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function apiList(int $aid, int $mid, string $status, int $page, int $pageSize): array
    {
        $query = ScopeService::query('shop_order', $aid)
            ->where('mid', $mid)
            ->where('delete', 0);

        if ('' !== $status) {
            $query->where('status', (int) $status);
        }

        $total = (clone $query)->count();

        $orders = $query->order('id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        return [
            'list'     => $this->attachGoods($orders),
            'total'    => $total,
            'page'     => $page,
            'pagesize' => $pageSize,
        ];
    }

    /**
     * 我的订单详情（仅本人订单）
     *
     * @return array<string, mixed>
     */
    public function apiDetail(int $aid, int $mid, int $orderId): array
    {
        $order = ScopeService::query('shop_order', $aid)
            ->where('id', $orderId)
            ->where('mid', $mid)
            ->where('delete', 0)
            ->find();

        if (empty($order)) {
            throw new BusinessException('订单不存在', 404, 404);
        }

        return $this->attachGoods([$order])[0];
    }

    /**
     * 取消订单（仅待支付可取消，回补库存，幂等）
     */
    public function cancel(int $aid, int $mid, int $orderId): void
    {
        $order = ScopeService::query('shop_order', $aid)
            ->where('id', $orderId)
            ->where('mid', $mid)
            ->find();

        if (empty($order)) {
            throw new BusinessException('订单不存在', 404, 404);
        }

        if (self::STATUS_UNPAID !== (int) $order['status']) {
            throw new BusinessException('仅待支付订单可取消');
        }

        $this->cancelInternal((int) $order['id']);
    }

    /**
     * 确认收货（仅已发货可确认 → 状态3）
     */
    public function confirm(int $aid, int $mid, int $orderId): void
    {
        $affected = ScopeService::query('shop_order', $aid)
            ->where('id', $orderId)
            ->where('mid', $mid)
            ->where('status', self::STATUS_SHIPPED)
            ->update(['status' => self::STATUS_FINISHED, 'collect_time' => time()]);

        if (1 !== $affected) {
            $order = ScopeService::query('shop_order', $aid)->where('id', $orderId)->where('mid', $mid)->find();

            throw new BusinessException(empty($order) ? '订单不存在' : '仅已发货订单可确认收货');
        }

        Db::name('shop_order_goods')
            ->where('orderid', $orderId)
            ->where('status', self::STATUS_SHIPPED)
            ->update(['status' => self::STATUS_FINISHED, 'endtime' => time()]);

        // 确认收货奖励（批次4-E 任务190）：按 order_collect_reward 设置发积分/券/余额/佣金，异常不阻断确认收货
        try {
            app(CollectRewardService::class)->onOrderFinished($aid, $orderId);
        } catch (\Throwable $e) {
            Log::error('collect reward fail order#' . $orderId . ': ' . $e->getMessage());
        }

        // 购物返现（批次4-A 任务186）：逐商品按规则比率返余额/佣金/积分，台账限额封顶，异常不阻断确认收货
        try {
            app(CashbackService::class)->dealCashback($aid, $orderId);
        } catch (\Throwable $e) {
            Log::error('cashback deal fail order#' . $orderId . ': ' . $e->getMessage());
        }
    }

    /* ================= admin 租户后台侧 ================= */

    /**
     * 订单列表（对照参考系统订单管理：字段类型搜索 + 时间类型区间 + 配送方式 + 金额区间 + 排序）
     *
     * @param array<string, mixed> $filter keyword_type 字段类型、time_type 时间类型、start/end、freight_id、price_min/max、sort_field/sort_order
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function adminList(int $aid, string $status, string $keyword, int $page, int $pageSize, string $bid = '', array $filter = []): array
    {
        $query = ScopeService::queryAs('shop_order', $aid, 'o')
            ->leftJoin('member m', 'm.id = o.mid')
            ->field('o.*, m.tel as member_phone, m.nickname as member_nickname, m.headimg as member_headimg')
            ->where('o.delete', 0);

        if ('' !== $status) {
            $query->where('o.status', (int) $status);
        }

        // 商户维度过滤（任务177 showtype-2：''/all 不过滤，0 自营，gt0 商户）
        ScopeService::applyBid($query, $bid, 'o.bid');

        if ('' !== $keyword) {
            $keywordType = (int) ($filter['keyword_type'] ?? 1);
            $like        = '%' . $keyword . '%';

            switch ($keywordType) {
                case 2: // 会员ID（精确）
                    $query->where('o.mid', (int) $keyword);
                    break;
                case 3: // 会员信息（昵称/会员手机号）
                    $query->where(function ($q) use ($like): void {
                        $q->whereOr('m.nickname', 'like', $like)->whereOr('m.tel', 'like', $like);
                    });
                    break;
                case 4: // 收货信息（收件人/电话/地址）
                    $query->where(function ($q) use ($like): void {
                        $q->whereOr('o.linkman', 'like', $like)
                            ->whereOr('o.tel', 'like', $like)
                            ->whereOr('o.area', 'like', $like)
                            ->whereOr('o.address', 'like', $like);
                    });
                    break;
                case 5: // 快递单号
                    $query->where('o.express_no', 'like', $like);
                    break;
                case 6: // 商品ID
                case 7: // 商品名称
                case 8: // 商品编码
                    $goodsQuery = Db::name('shop_order_goods')->where('aid', $aid);
                    if (6 === $keywordType) {
                        $goodsQuery->where('proid', (int) $keyword);
                    } else {
                        $goodsColumn = 8 === $keywordType ? 'procode' : 'name';
                        $goodsQuery->where($goodsColumn, 'like', $like);
                    }
                    $orderIds = $goodsQuery->column('orderid');
                    $query->whereIn('o.id', $orderIds ?: [0]);
                    break;
                case 1: // 订单号
                default:
                    $query->where(function ($q) use ($like): void {
                        $q->whereOr('o.ordernum', 'like', $like)
                            ->whereOr('o.linkman', 'like', $like)
                            ->whereOr('o.tel', 'like', $like);
                    });
                    break;
            }
        }

        // 时间类型 + 区间
        $start = trim((string) ($filter['start'] ?? ''));
        $end   = trim((string) ($filter['end'] ?? ''));

        if ('' !== $start || '' !== $end) {
            $timeColumn = match ((int) ($filter['time_type'] ?? 1)) {
                2       => 'paytime',
                3       => 'send_time',
                4       => 'collect_time',
                default => 'createtime',
            };

            if ('' !== $start) {
                $query->where('o.' . $timeColumn, '>=', strtotime($start . ' 00:00:00'));
            }
            if ('' !== $end) {
                $query->where('o.' . $timeColumn, '<=', strtotime($end . ' 23:59:59'));
            }
        }

        // 配送方式
        $freightId = (int) ($filter['freight_id'] ?? 0);
        if ($freightId > 0) {
            $query->where('o.freight_id', $freightId);
        }

        // 订单金额（实付）区间（空值/非数字不过滤，避免 (float)'' 被误判成 0）
        $priceMinRaw = trim((string) ($filter['price_min'] ?? ''));
        $priceMaxRaw = trim((string) ($filter['price_max'] ?? ''));
        if ('' !== $priceMinRaw && is_numeric($priceMinRaw)) {
            $query->where('o.totalprice', '>=', (float) $priceMinRaw);
        }
        if ('' !== $priceMaxRaw && is_numeric($priceMaxRaw)) {
            $query->where('o.totalprice', '<=', (float) $priceMaxRaw);
        }

        $total = (clone $query)->count();

        // 排序（ID/订单号，默认 ID 倒序）
        $sortField = 'ordernum' === ($filter['sort_field'] ?? '') ? 'ordernum' : 'id';
        $sortOrder = 'asc' === strtolower((string) ($filter['sort_order'] ?? 'desc')) ? 'asc' : 'desc';

        $orders = $query->order('o.' . $sortField, $sortOrder)
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        $list = $this->attachGoods($orders, true);
        // 所属商户名（对照参考系统 ShopOrder：bname 取商户名，bid=0 显示平台自营）
        $bids     = array_values(array_unique(array_map(static fn ($r) => (int) ($r['bid'] ?? 0), $list)));
        $bnameMap = $bids ? Db::name('business')->where('aid', $aid)->whereIn('id', $bids)->column('name', 'id') : [];
        foreach ($list as &$row) {
            $row['bname'] = (int) ($row['bid'] ?? 0) > 0 ? (string) ($bnameMap[$row['bid']] ?? '') : '平台自营';
        }
        unset($row);

        return [
            'list'     => $list,
            'total'    => $total,
            'page'     => $page,
            'pagesize' => $pageSize,
        ];
    }

    /**
     * 后台改价（仅未支付订单可修改实付金额）
     */
    public function adminChangePrice(int $aid, int $orderId, float $price): void
    {
        if ($price <= 0) {
            throw new BusinessException('金额必须大于0');
        }

        $order = ScopeService::query('shop_order', $aid)->where('id', $orderId)->find();

        if (empty($order)) {
            throw new BusinessException('订单不存在');
        }

        if (self::STATUS_UNPAID !== (int) $order['status']) {
            throw new BusinessException('仅未支付订单可修改价格');
        }

        ScopeService::query('shop_order', $aid)
            ->where('id', $orderId)
            ->where('status', self::STATUS_UNPAID)
            ->update(['totalprice' => round($price, 2)]);
    }

    /**
     * 后台关闭订单（仅未支付；复用取消逻辑回补库存/券/积分）
     */
    public function adminClose(int $aid, int $orderId): void
    {
        $order = ScopeService::query('shop_order', $aid)->where('id', $orderId)->find();

        if (empty($order)) {
            throw new BusinessException('订单不存在');
        }

        if (self::STATUS_UNPAID !== (int) $order['status']) {
            throw new BusinessException('仅未支付订单可关闭');
        }

        $this->cancelInternal($orderId);
    }

    /**
     * 订单详情（全量：商品快照、地址快照、支付/物流信息）
     *
     * @return array<string, mixed>
     */
    public function adminDetail(int $aid, int $orderId): array
    {
        $order = ScopeService::queryAs('shop_order', $aid, 'o')
            ->leftJoin('member m', 'm.id = o.mid')
            ->field('o.*, m.tel as member_phone')
            ->where('o.id', $orderId)
            ->find();

        if (empty($order)) {
            throw new BusinessException('订单不存在', 404, 404);
        }

        $detail = $this->attachGoods([$order], true)[0];

        $detail['pay_info'] = [
            'pay_type'   => (string) ($order['paytype'] ?? ''),
            'pay_num'    => (string) ($order['paynum'] ?? ''),
            'pay_time'   => $this->formatTime($order['paytime'] ?? null),
            'expire_time' => $this->formatTime($order['pay_expire_time'] ?? null),
        ];
        $detail['ship_info'] = [
            'ship_company' => (string) ($order['express_com'] ?? ''),
            'ship_sn'      => (string) ($order['express_no'] ?? ''),
            'ship_time'    => $this->formatTime($order['send_time'] ?? null),
        ];
        $detail['collect_time'] = $this->formatTime($order['collect_time'] ?? null);
        $detail['message']      = (string) ($order['message'] ?? '');
        $detail['remark']       = (string) ($order['remark'] ?? '');

        return $detail;
    }

    /**
     * 发货（仅状态1 已支付可发货 → 状态2）
     */
    public function ship(int $aid, int $orderId, string $shipCompany, string $shipSn): void
    {
        if ('' === $shipCompany) {
            throw new BusinessException('请填写快递公司');
        }
        if ('' === $shipSn) {
            throw new BusinessException('请填写快递单号');
        }

        $affected = ScopeService::query('shop_order', $aid)
            ->where('id', $orderId)
            ->where('status', self::STATUS_PAID)
            ->update([
                'status'      => self::STATUS_SHIPPED,
                'express_com' => $shipCompany,
                'express_no'  => $shipSn,
                'send_time'   => time(),
            ]);

        if (1 !== $affected) {
            $order = ScopeService::query('shop_order', $aid)->where('id', $orderId)->find();

            throw new BusinessException(empty($order) ? '订单不存在' : '仅已支付订单可发货');
        }

        Db::name('shop_order_goods')
            ->where('orderid', $orderId)
            ->where('status', self::STATUS_PAID)
            ->update(['status' => self::STATUS_SHIPPED]);
    }

    /**
     * 订单状态统计（订单页 tab 计数）
     *
     * @return array{wait_pay: int, wait_ship: int, shipped: int, done: int, cancelled: int, total: int}
     */
    public function stat(int $aid): array
    {
        $rows = ScopeService::query('shop_order', $aid)
            ->where('delete', 0)
            ->field('status, count(*) as cnt')
            ->group('status')
            ->select()
            ->toArray();

        $stat = ['wait_pay' => 0, 'wait_ship' => 0, 'shipped' => 0, 'done' => 0, 'cancelled' => 0, 'total' => 0];
        $map  = [
            self::STATUS_UNPAID    => 'wait_pay',
            self::STATUS_PAID      => 'wait_ship',
            self::STATUS_SHIPPED   => 'shipped',
            self::STATUS_FINISHED  => 'done',
            self::STATUS_CANCELLED => 'cancelled',
        ];

        foreach ($rows as $row) {
            $key = $map[(int) $row['status']] ?? null;

            if (null !== $key) {
                $stat[$key] += (int) $row['cnt'];
            }
            $stat['total'] += (int) $row['cnt'];
        }

        return $stat;
    }

    /* ================= admin 租户后台侧：批量操作（P17-2） ================= */

    /**
     * 批量发货（Excel 导入：订单号+运单号成对）
     *
     * 参考系统口径 plfh()：status∈{1,2} 可发货，express_content 写 JSON 数组支持多包裹，
     * 首个包裹落 express_com/express_no，status→2 + send_time + 商品快照同步
     *
     * @param array<int, array{0: string, 1: string}> $pairs [[订单号, 运单号], ...]
     * @return array{total: int, success: int, fail: int, items: array}
     */
    public function batchShip(int $aid, string $expressCompany, array $pairs): array
    {
        if ('' === $expressCompany) {
            throw new BusinessException('请填写快递公司');
        }

        $result = ['total' => count($pairs), 'success' => 0, 'fail' => 0, 'items' => []];

        foreach ($pairs as $pair) {
            $ordernum  = trim((string) ($pair[0] ?? ''));
            $expressNo = trim((string) ($pair[1] ?? ''));

            if ('' === $ordernum || '' === $expressNo) {
                ++$result['fail'];
                $result['items'][] = ['ordernum' => $ordernum, 'ok' => false, 'msg' => '订单号或运单号为空'];
                continue;
            }

            $order = ScopeService::query('shop_order', $aid)->where('ordernum', $ordernum)->find();

            if (empty($order)) {
                ++$result['fail'];
                $result['items'][] = ['ordernum' => $ordernum, 'ok' => false, 'msg' => '订单不存在'];
                continue;
            }

            [$ok, $msg] = $this->shipPackage($aid, $order, $expressCompany, $expressNo);

            if ($ok) {
                ++$result['success'];
            } else {
                ++$result['fail'];
            }
            $result['items'][] = ['ordernum' => $ordernum, 'ok' => $ok, 'msg' => $msg];
        }

        return $result;
    }

    /**
     * 选中项批量发货（同一快递+运单号发多单）
     *
     * @param array<int, int> $ids 订单ID列表
     * @return array{total: int, success: int, fail: int, items: array}
     */
    public function batchShipByIds(int $aid, array $ids, string $expressCompany, string $expressNo): array
    {
        if ('' === $expressCompany) {
            throw new BusinessException('请填写快递公司');
        }
        if ('' === $expressNo) {
            throw new BusinessException('请填写运单号');
        }

        $result = ['total' => count($ids), 'success' => 0, 'fail' => 0, 'items' => []];

        foreach ($ids as $orderId) {
            $order = ScopeService::query('shop_order', $aid)->where('id', (int) $orderId)->find();

            if (empty($order)) {
                ++$result['fail'];
                $result['items'][] = ['ordernum' => '', 'ok' => false, 'msg' => '订单不存在'];
                continue;
            }

            [$ok, $msg] = $this->shipPackage($aid, $order, $expressCompany, $expressNo);

            if ($ok) {
                ++$result['success'];
            } else {
                ++$result['fail'];
            }
            $result['items'][] = ['ordernum' => (string) ($order['ordernum'] ?? ''), 'ok' => $ok, 'msg' => $msg];
        }

        return $result;
    }

    /**
     * 单包裹发货核心（多包裹：express_content JSON 数组追加，重复运单跳过）
     *
     * @param array<string, mixed> $order
     * @return array{0: bool, 1: string}
     */
    protected function shipPackage(int $aid, array $order, string $expressCompany, string $expressNo): array
    {
        $status = (int) ($order['status'] ?? -1);

        if (!in_array($status, [self::STATUS_PAID, self::STATUS_SHIPPED], true)) {
            return [false, '仅已支付/已发货订单可发货'];
        }

        $expressContent = [];
        $old            = trim((string) ($order['express_content'] ?? ''));

        if ('' !== $old) {
            $decoded = json_decode($old, true);

            if (is_array($decoded) && !empty($decoded)) {
                $expressContent = $decoded;
            }
        }

        $update = [];

        if (!empty($expressContent)) {
            // 已有包裹：追加新包裹（重复运单跳过）
            foreach ($expressContent as $ev) {
                if ((string) ($ev['express_com'] ?? '') === $expressCompany
                    && (string) ($ev['express_no'] ?? '') === $expressNo) {
                    return [false, '运单号重复，已跳过'];
                }
            }
        } else {
            // 首个包裹：落 express_com/express_no
            $update['express_com'] = $expressCompany;
            $update['express_no']  = $expressNo;
        }

        $expressContent[]          = ['express_com' => $expressCompany, 'express_no' => $expressNo];
        $update['express_content'] = json_encode($expressContent, JSON_UNESCAPED_UNICODE);
        $update['status']          = self::STATUS_SHIPPED;
        $update['send_time']       = time();

        $affected = ScopeService::query('shop_order', $aid)
            ->where('id', (int) $order['id'])
            ->whereIn('status', [self::STATUS_PAID, self::STATUS_SHIPPED])
            ->update($update);

        if ($affected < 1) {
            return [false, '发货失败（订单状态已变化）'];
        }

        Db::name('shop_order_goods')
            ->where('orderid', (int) $order['id'])
            ->where('status', self::STATUS_PAID)
            ->update(['status' => self::STATUS_SHIPPED]);

        return [true, '发货成功'];
    }

    /**
     * 批量删除（参考系统口径 del()：先回滚未发货已支付订单的库存/券/积分，再级联物理删除）
     *
     * 级联删除：shop_order / shop_order_goods / shop_refund_order / shop_refund_order_goods / invoice
     *
     * @param array<int, int> $ids
     * @return array{total: int, success: int, fail: int, items: array}
     */
    public function batchDelete(int $aid, array $ids): array
    {
        $result = ['total' => count($ids), 'success' => 0, 'fail' => 0, 'items' => []];

        foreach ($ids as $orderId) {
            $orderId = (int) $orderId;
            $order   = ScopeService::query('shop_order', $aid)->where('id', $orderId)->find();

            if (empty($order)) {
                ++$result['fail'];
                $result['items'][] = ['id' => $orderId, 'ok' => false, 'msg' => '订单不存在'];
                continue;
            }

            try {
                Db::transaction(function () use ($aid, $order) {
                    $this->deleteOne($aid, $order);
                });
                ++$result['success'];
                $result['items'][] = ['id' => $orderId, 'ok' => true, 'msg' => '删除成功'];
            } catch (\Throwable $e) {
                ++$result['fail'];
                $result['items'][] = ['id' => $orderId, 'ok' => false, 'msg' => $e->getMessage()];
            }
        }

        return $result;
    }

    /**
     * 删除单订单（事务内调用）：回滚（已支付未发货回补库存+释放券+退积分）→ 级联物理删除
     *
     * @param array<string, mixed> $order
     */
    protected function deleteOne(int $aid, array $order): void
    {
        $orderId = (int) ($order['id'] ?? 0);

        // 回滚：已支付未发货（status=1）回补库存、释放优惠券、返还预扣积分
        if (self::STATUS_PAID === (int) ($order['status'] ?? -1)) {
            $goodsRows = Db::name('shop_order_goods')->where('orderid', $orderId)->select()->toArray();

            foreach ($goodsRows as $goodsRow) {
                $proid = (int) ($goodsRow['proid'] ?? 0);
                $num   = (int) ($goodsRow['num'] ?? 0);

                if ($proid > 0 && $num > 0) {
                    Db::name('shop_product')
                        ->where('id', $proid)
                        ->where('aid', $aid)
                        ->inc('stock', $num)
                        ->update();
                }
            }

            $this->couponService->releaseByOrder((int) ($order['coupon_rid'] ?? 0), $orderId);

            $useScore = (int) ($order['use_score'] ?? 0);

            if ($useScore > 0) {
                $this->scoreService->refundForCancel(
                    $aid,
                    (int) ($order['mid'] ?? 0),
                    $useScore,
                    '删除订单返还 订单' . (string) ($order['ordernum'] ?? '')
                );
            }
        }

        // 级联物理删除（order/goods/refund/refund_goods/invoice）
        Db::name('shop_order')->where('aid', $aid)->where('id', $orderId)->delete();
        Db::name('shop_order_goods')->where('aid', $aid)->where('orderid', $orderId)->delete();
        Db::name('shop_refund_order')->where('aid', $aid)->where('orderid', $orderId)->delete();
        Db::name('shop_refund_order_goods')->where('aid', $aid)->where('orderid', $orderId)->delete();
        Db::name('invoice')->where('aid', $aid)->where('order_type', 'shop')->where('orderid', $orderId)->delete();
    }

    /**
     * 设置商家备注
     */
    public function setRemark(int $aid, int $orderId, string $remark): void
    {
        $affected = ScopeService::query('shop_order', $aid)
            ->where('id', $orderId)
            ->update(['remark' => mb_substr($remark, 0, 500)]);

        if (1 !== $affected) {
            throw new BusinessException('订单不存在', 404, 404);
        }
    }

    /**
     * 导出数据组装（全筛选条件 + 选中 ids 二选一优先 ids）
     *
     * @param array<int, int> $ids 选中导出（非空时忽略筛选条件）
     * @return array{0: array<int, string>, 1: array<int, array<int, mixed>>} [标题行, 数据行]
     */
    public function exportData(int $aid, string $status, string $keyword, array $ids, int $limit = 20000, string $bid = ''): array
    {
        $query = ScopeService::queryAs('shop_order', $aid, 'o')
            ->leftJoin('member m', 'm.id = o.mid')
            ->field('o.*, m.tel as member_phone')
            ->where('o.delete', 0);
        // 商户维度过滤（商户订单视图导出：''/all 不过滤，0 自营，gt0 全部商户，数值指定）
        ScopeService::applyBid($query, $bid, 'o.bid');

        if (!empty($ids)) {
            $query->whereIn('o.id', $ids);
        } else {
            if ('' !== $status) {
                $query->where('o.status', (int) $status);
            }

            if ('' !== $keyword) {
                $like = '%' . $keyword . '%';
                $query->where(function ($q) use ($like) {
                    $q->whereOr('o.ordernum', 'like', $like)
                        ->whereOr('o.linkman', 'like', $like)
                        ->whereOr('o.tel', 'like', $like);
                });
            }
        }

        $orders = $query->order('o.id', 'desc')->limit($limit)->select()->toArray();

        $titles = [
            '订单号', '状态', '下单时间', '付款时间', '发货时间', '完成时间',
            '实付款', '商品金额', '运费', '满减优惠', '优惠券优惠', '积分抵扣',
            '商品明细', '买家留言', '商家备注',
            '收件人', '电话', '收货地址',
            '支付方式', '支付流水号', '快递公司', '运单号', '快递包裹', '会员账号',
        ];

        $rows = [];

        foreach ($orders as $order) {
            $orderId = (int) $order['id'];

            $goodsRows = Db::name('shop_order_goods')
                ->where('orderid', $orderId)
                ->order('id', 'asc')
                ->select()
                ->toArray();

            $goodsDetail = [];
            foreach ($goodsRows as $g) {
                $spec    = '' !== trim((string) ($g['ggname'] ?? '')) ? '[' . $g['ggname'] . ']' : '';
                $goodsDetail[] = $g['name'] . $spec . ' x' . $g['num'];
            }

            // 快递包裹：express_content JSON 优先，无则取 express_com/no
            $expressDetail = '';
            $contentArr    = json_decode((string) ($order['express_content'] ?? ''), true);

            if (is_array($contentArr) && !empty($contentArr)) {
                $parts = [];
                foreach ($contentArr as $ev) {
                    $parts[] = ($ev['express_com'] ?? '') . '(' . ($ev['express_no'] ?? '') . ')';
                }
                $expressDetail = implode("\n", $parts);
            } elseif ('' !== trim((string) ($order['express_com'] ?? ''))) {
                $expressDetail = $order['express_com'] . '(' . ($order['express_no'] ?? '') . ')';
            }

            $rows[] = [
                (string) ($order['ordernum'] ?? ''),
                self::STATUS_TEXT[(int) ($order['status'] ?? 0)] ?? '未知',
                $this->formatTime($order['createtime'] ?? null),
                $this->formatTime($order['paytime'] ?? null),
                $this->formatTime($order['send_time'] ?? null),
                $this->formatTime($order['collect_time'] ?? null),
                (float) ($order['totalprice'] ?? 0),
                (float) ($order['product_price'] ?? 0),
                (float) ($order['freight_price'] ?? 0),
                (float) ($order['promotion_money'] ?? 0),
                (float) ($order['coupon_money'] ?? 0),
                (float) ($order['score_money'] ?? 0),
                implode("\n", $goodsDetail),
                (string) ($order['message'] ?? ''),
                (string) ($order['remark'] ?? ''),
                (string) ($order['linkman'] ?? ''),
                (string) ($order['tel'] ?? ''),
                trim(((string) ($order['area'] ?? '')) . ' ' . ((string) ($order['address'] ?? ''))),
                (string) ($order['paytype'] ?? ''),
                (string) ($order['paynum'] ?? ''),
                (string) ($order['express_com'] ?? ''),
                (string) ($order['express_no'] ?? ''),
                $expressDetail,
                (string) ($order['member_phone'] ?? ''),
            ];
        }

        return [$titles, $rows];
    }

    /* ================= 录入订单（代客下单，P18-2） ================= */

    /**
     * 后台录入订单（代客下单，对标参考系统 ShopOrderlr::save）
     *
     * 事务：校验商品/规格库存 → 条件扣减库存 → 写 shop_order(platform=admin,status=1 已付款)
     * + shop_order_goods（parent1-4 占位 0，分销批次3再补）。分销佣金本批次不计算。
     *
     * @param int    $aid          租户ID
     * @param string $operatorName 操作员账号名（写入 remark）
     * @param array<string, mixed> $payload {
     *   mid:int（0=游客）,
     *   items: array<int, array{proid:int, ggid:int, num:int}>,
     *   linkman/tel/province/city/district/address: string,
     *   freight_id:int, freightprice:float, mdid:int,
     *   paytype:string, paytypeid:int, paytime:string(Y-m-d H:i:s 可空=当前),
     * }
     * @return array{id:int, ordernum:string, totalprice:float}
     */
    public function createAdminOrder(int $aid, string $operatorName, array $payload): array
    {
        $mid = (int) ($payload['mid'] ?? 0);
        $items = $payload['items'] ?? [];

        if (!is_array($items) || empty($items)) {
            throw new BusinessException('请至少添加一件商品');
        }

        // 会员（可空=游客；不存在时按游客处理，对标参考系统口径）
        $member = null;

        if ($mid > 0) {
            $member = ScopeService::query('member', $aid)->where('id', $mid)->find();

            if (empty($member)) {
                $mid = 0;
                $member = null;
            }
        }

        // ===== 配送方式（freight 表：pstype 0快递/1自提/5门店配送/2同城/3自动发货/4卡密） =====
        $freightId = (int) ($payload['freight_id'] ?? 0);
        $freightPrice = round((float) ($payload['freightprice'] ?? 0), 2);
        $mdid = 0;

        if ($freightId > 0) {
            $freight = ScopeService::query('freight', $aid)->where('id', $freightId)->find();

            if (empty($freight)) {
                throw new BusinessException('配送方式不存在');
            }

            $pstype = (int) ($freight['pstype'] ?? 0);
            $freightName = (string) ($freight['name'] ?? '');

            if (1 === $pstype || 5 === $pstype) { // 自提 / 门店配送：必选门店
                $mdid = (int) ($payload['mdid'] ?? 0);

                if ($mdid <= 0) {
                    throw new BusinessException('请选择门店');
                }

                $mendian = ScopeService::query('mendian', $aid)->where('id', $mdid)->find();

                if (empty($mendian)) {
                    throw new BusinessException('门店不存在');
                }

                $freightText = $freightName . '[' . (string) ($mendian['name'] ?? '') . ']';
            } elseif (3 === $pstype || 4 === $pstype) { // 自动发货 / 在线卡密
                $freightText = $freightName;
            } elseif (0 === $pstype || 2 === $pstype || 10 === $pstype || 11 === $pstype || 12 === $pstype) {
                $freightText = $freightName . '(' . $freightPrice . '元)';
            } else {
                $freightText = '包邮';
            }
        } else {
            $pstype = 0;
            $freightText = '商家配送';
        }

        // ===== 商品校验（规格库存 + 等级价） =====
        $prolist = [];
        $goodsPrice = 0.0;
        $title = '';
        $givescore = 0;   // 确认收货后赠送
        $givescore2 = 0;  // 付款后赠送

        foreach ($items as $item) {
            $proid = (int) ($item['proid'] ?? 0);
            $ggid  = (int) ($item['ggid'] ?? 0);
            $num   = (int) ($item['num'] ?? 0);

            if ($proid <= 0 || $num <= 0) {
                throw new BusinessException('商品行数据不完整');
            }

            $product = ScopeService::query('shop_product', $aid)
                ->where('id', $proid)
                ->where('deleted', 0)
                ->find();

            if (empty($product)) {
                throw new BusinessException('产品不存在或已下架');
            }

            $guige = null;

            if ($ggid > 0) {
                $guige = Db::name('shop_guige')
                    ->where('aid', $aid)
                    ->where('proid', $proid)
                    ->where('id', $ggid)
                    ->find();

                if (empty($guige)) {
                    throw new BusinessException('产品规格不存在或已下架');
                }

                if ((int) ($guige['stock'] ?? 0) < $num) {
                    throw new BusinessException((string) ($product['name'] ?? '') . (string) ($guige['name'] ?? '') . '库存不足');
                }
            } else {
                if ((int) ($product['stock'] ?? 0) < $num) {
                    throw new BusinessException((string) ($product['name'] ?? '') . '库存不足');
                }
            }

            $sellPrice = null !== $guige ? (float) ($guige['sell_price'] ?? 0) : (float) ($product['sell_price'] ?? 0);

            // 等级价：商品开启 lvprice 且已选会员时，取规格 lvprice_data[会员等级] 价格
            if (1 === (int) ($product['lvprice'] ?? 0) && null !== $member && null !== $guige) {
                $lvpriceData = json_decode((string) ($guige['lvprice_data'] ?? ''), true);
                $levelId = (int) ($member['levelid'] ?? 0);

                if (is_array($lvpriceData) && isset($lvpriceData[$levelId]) && (float) $lvpriceData[$levelId] > 0) {
                    $sellPrice = (float) $lvpriceData[$levelId];
                }
            }

            if ('' === $title) {
                $title = (string) ($product['name'] ?? '');
            }

            // 自动发货/卡密商品强制 pstype 并限制单独录入
            $freightType = (int) ($product['freighttype'] ?? 1);

            if (3 === $freightType) {
                $pstype = 3;
            } elseif (4 === $freightType) {
                $pstype = 4;
            }

            // 赠送积分（givescore_time：0=收货后 1=付款后）
            $givePerNum = null !== $guige ? (int) ($guige['givescore'] ?? 0) : 0;

            if (0 === (int) ($product['givescore_time'] ?? 0)) {
                $givescore += $givePerNum * $num;
            } else {
                $givescore2 += $givePerNum * $num;
            }

            $goodsPrice += $sellPrice * $num;
            $prolist[] = ['product' => $product, 'guige' => $guige, 'num' => $num, 'sell_price' => $sellPrice];
        }

        if ((3 === $pstype || 4 === $pstype) && count($prolist) > 1) {
            throw new BusinessException((3 === $pstype ? '自动发货' : '在线卡密') . '商品需要单独录入');
        }

        $goodsPrice = round($goodsPrice, 2);
        $totalPrice = round($goodsPrice + $freightPrice, 2);

        // 支付时间（可补录历史订单）
        $paytimeStr = trim((string) ($payload['paytime'] ?? ''));
        $payTime = '' !== $paytimeStr ? strtotime($paytimeStr) : time();

        if (false === $payTime || $payTime <= 0) {
            throw new BusinessException('支付时间格式不正确');
        }

        $paytype   = trim((string) ($payload['paytype'] ?? '余额支付'));
        $paytypeid = (int) ($payload['paytypeid'] ?? 1);

        $linkman  = trim((string) ($payload['linkman'] ?? ''));
        $tel      = trim((string) ($payload['tel'] ?? ''));
        $province = trim((string) ($payload['province'] ?? ''));
        $city     = trim((string) ($payload['city'] ?? ''));
        $district = trim((string) ($payload['district'] ?? ''));
        $address  = trim((string) ($payload['address'] ?? ''));

        if ('' === $linkman) {
            throw new BusinessException('请填写收货联系人');
        }
        if ('' === $tel) {
            throw new BusinessException('请填写联系电话');
        }

        $remark = '后台录入' . ('' !== $operatorName ? '，操作员：' . $operatorName : '');
        $now = time();

        return Db::transaction(function () use ($aid, $mid, $member, $prolist, $goodsPrice, $totalPrice, $freightId, $freightPrice, $freightText, $pstype, $mdid, $paytype, $paytypeid, $payTime, $linkman, $tel, $province, $city, $district, $address, $remark, $givescore, $givescore2, $title, $now) {
            // ① 条件扣减库存（规格行 + 商品行，防并发超卖）
            foreach ($prolist as $v) {
                $num = (int) $v['num'];

                if (null !== $v['guige']) {
                    $affected = Db::name('shop_guige')
                        ->where('id', (int) $v['guige']['id'])
                        ->where('aid', $aid)
                        ->where('stock', '>=', $num)
                        ->dec('stock', $num)
                        ->inc('sales', $num)
                        ->update();

                    if (1 !== $affected) {
                        throw new BusinessException((string) ($v['product']['name'] ?? '') . (string) ($v['guige']['name'] ?? '') . '库存不足');
                    }
                }

                $proAffected = Db::name('shop_product')
                    ->where('id', (int) $v['product']['id'])
                    ->where('aid', $aid)
                    ->where('stock', '>=', $num)
                    ->dec('stock', $num)
                    ->inc('sales', $num)
                    ->update();

                if (1 !== $proAffected) {
                    throw new BusinessException((string) ($v['product']['name'] ?? '') . '库存不足');
                }
            }

            // ② 会员新地址回存（同会员同收件人同省市区未存在时新增）
            if ($mid > 0 && '' !== $province) {
                $exists = Db::name('member_address')
                    ->where('aid', $aid)
                    ->where('mid', (string) $mid)
                    ->where('name', $linkman)
                    ->where('tel', $tel)
                    ->where('province', $province)
                    ->where('city', $city)
                    ->where('district', $district)
                    ->find();

                if (empty($exists)) {
                    Db::name('member_address')->insert([
                        'aid'        => $aid,
                        'mid'        => (string) $mid,
                        'name'       => $linkman,
                        'tel'        => $tel,
                        'province'   => $province,
                        'city'       => $city,
                        'district'   => $district,
                        'area'       => $province . $city . $district,
                        'address'    => $address,
                        'isdefault'  => 0,
                        'createtime' => $now,
                    ]);
                }
            }

            // ③ 订单主记录（platform=admin、status=1 已付款、核销码随机16位）
            $orderId = (int) Db::name('shop_order')->insertGetId([
                'aid'             => $aid,
                'bid'             => 0,
                'mid'             => $mid,
                'ordernum'        => '',
                'title'           => $title . (count($prolist) > 1 ? '等' : ''),
                'linkman'         => $linkman,
                'tel'             => $tel,
                'area'            => $province . $city . $district,
                'area2'           => '' !== $province ? $province . ',' . $city . ',' . $district : '',
                'address'         => $address,
                'totalprice'      => $totalPrice,
                'product_price'   => $goodsPrice,
                'leveldk_money'   => 0,
                'scoredk_money'   => 0,
                'scoredkscore'    => 0,
                'freight_price'   => $freightPrice,
                'message'         => '',
                'freight_text'    => $freightText,
                'freight_id'      => $freightId,
                'freight_type'    => $pstype,
                'mdid'            => $mdid,
                'platform'        => 'admin',
                'hexiao_code'     => bin2hex(random_bytes(8)),
                'status'          => self::STATUS_PAID,
                'paytype'         => $paytype,
                'paytypeid'       => $paytypeid,
                'paytime'         => $payTime,
                'createtime'      => $payTime,
                'remark'          => $remark,
                'givescore'       => $givescore,
                'givescore2'      => $givescore2,
            ]);

            $orderSn = date('YmdHis', $now)
                . str_pad((string) ($aid % 10000), 4, '0', STR_PAD_LEFT)
                . str_pad((string) ($orderId % 1000000), 6, '0', STR_PAD_LEFT);

            Db::name('shop_order')->where('id', $orderId)->update(['ordernum' => $orderSn]);

            // ④ 商品快照（parent1-4 佣金占位 0，批次3 分销再补）
            foreach ($prolist as $v) {
                $product = $v['product'];
                $guige   = $v['guige'];
                $num     = (int) $v['num'];
                $rowTotal = round((float) $v['sell_price'] * $num, 2);

                Db::name('shop_order_goods')->insert([
                    'aid'               => $aid,
                    'bid'               => (int) ($product['bid'] ?? 0),
                    'mid'               => $mid,
                    'orderid'           => $orderId,
                    'ordernum'          => $orderSn,
                    'proid'             => (int) $product['id'],
                    'name'              => (string) ($product['name'] ?? ''),
                    'pic'               => (null !== $guige && '' !== trim((string) ($guige['pic'] ?? '')))
                        ? (string) $guige['pic']
                        : GoodsService::firstPic((string) ($product['pics'] ?? ''), (string) ($product['pic'] ?? '')),
                    'procode'           => (string) ($product['procode'] ?? ''),
                    'barcode'           => (string) ($product['barcode'] ?? ''),
                    'ggid'              => null !== $guige ? (int) $guige['id'] : 0,
                    'ggname'            => null !== $guige ? (string) ($guige['name'] ?? '') : '',
                    'cid'               => (string) ($product['cid'] ?? '0'),
                    'num'               => $num,
                    'cost_price'        => null !== $guige ? (float) ($guige['cost_price'] ?? 0) : (float) ($product['cost_price'] ?? 0),
                    'sell_price'        => (float) $v['sell_price'],
                    'totalprice'        => $rowTotal,
                    'real_totalprice'   => $rowTotal,
                    'real_totalmoney'   => $rowTotal,
                    'status'            => 1,
                    'createtime'        => $payTime,
                    'parent1'           => 0,
                    'parent2'           => 0,
                    'parent3'           => 0,
                    'parent4'           => 0,
                    'parent1commission' => 0,
                    'parent2commission' => 0,
                    'parent3commission' => 0,
                    'parent4commission' => 0,
                    'isfenhong'         => 0 === (int) ($product['fenhongset'] ?? 1) ? 2 : 0,
                ]);
            }

            return ['id' => $orderId, 'ordernum' => $orderSn, 'totalprice' => $totalPrice];
        });
    }

    /* ================= 定时任务 ================= */

    /**
     * 超时未支付订单自动取消 + 回补库存（幂等，可重复执行）
     *
     * 判定：pay_expire_time 已过；历史数据无该列值时按 createtime+超时秒数兜底
     *
     * @return int 本次取消的订单数
     */
    public function cancelExpired(): int
    {
        $now     = time();
        $timeout = PayService::payTimeout();

        $orders = Db::name('shop_order')
            ->where('status', self::STATUS_UNPAID)
            ->where(function ($q) use ($now, $timeout) {
                $q->where('pay_expire_time', '>', 0)
                    ->where('pay_expire_time', '<', $now)
                    ->whereOr(function ($q2) use ($now, $timeout) {
                        $q2->where(function ($q3) use ($now, $timeout) {
                            $q3->whereNull('pay_expire_time')->whereOr('pay_expire_time', 0);
                        })->where('createtime', '<', $now - $timeout);
                    });
            })
            ->limit(500)
            ->column('id');

        foreach ($orders as $orderId) {
            $this->cancelInternal((int) $orderId);
        }

        return count($orders);
    }

    /* ================= 内部工具 ================= */

    /**
     * 取消入账（幂等核心）：status=0 条件更新为 4，成功后回补库存
     * 并发/重复调用时只有一个能更新成功，避免重复回补
     */
    protected function cancelInternal(int $orderId): void
    {
        Db::transaction(function () use ($orderId) {
            $order = Db::name('shop_order')->where('id', $orderId)->lock(true)->find();

            if (empty($order) || self::STATUS_UNPAID !== (int) ($order['status'] ?? -1)) {
                return; // 已被处理（幂等）
            }

            $affected = Db::name('shop_order')
                ->where('id', $orderId)
                ->where('status', self::STATUS_UNPAID)
                ->update(['status' => self::STATUS_CANCELLED]);

            if (1 !== $affected) {
                return;
            }

            $goodsRows = Db::name('shop_order_goods')->where('orderid', $orderId)->select()->toArray();

            foreach ($goodsRows as $goodsRow) {
                $proid = (int) ($goodsRow['proid'] ?? 0);
                $num   = (int) ($goodsRow['num'] ?? 0);
                $ggid  = (int) ($goodsRow['ggid'] ?? 0);

                if ($proid > 0 && $num > 0) {
                    Db::name('shop_product')
                        ->where('id', $proid)
                        ->where('aid', (int) ($goodsRow['aid'] ?? 0))
                        ->inc('stock', $num)
                        ->update();

                    // SKU 行库存同步回补（与下单扣减对称）
                    if ($ggid > 0) {
                        Db::name('shop_guige')
                            ->where('id', $ggid)
                            ->where('aid', (int) ($goodsRow['aid'] ?? 0))
                            ->inc('stock', $num)
                            ->update();
                    }
                }
            }

            // 券释放：清除预锁定（防御分支兼容已核销回退）
            $this->couponService->releaseByOrder((int) ($order['coupon_rid'] ?? 0), $orderId);

            // 积分返还：下单预扣的抵扣积分原数退回（幂等：仅取消成功一次才会走到这里）
            $useScore = (int) ($order['use_score'] ?? 0);

            if ($useScore > 0) {
                $this->scoreService->refundForCancel(
                    (int) ($order['aid'] ?? 0),
                    (int) ($order['mid'] ?? 0),
                    $useScore,
                    '取消订单返还 订单' . (string) ($order['ordernum'] ?? '')
                );
            }
        });
    }

    /**
     * 批量附加商品快照数组
     *
     * @param array<int, array<string, mixed>> $orders
     * @return array<int, array<string, mixed>>
     */
    protected function attachGoods(array $orders, bool $forAdmin = false): array
    {
        if (empty($orders)) {
            return [];
        }

        $ids = array_map(static fn (array $o) => (int) $o['id'], $orders);

        $goodsRows = Db::name('shop_order_goods')
            ->whereIn('orderid', $ids)
            ->order('id', 'asc')
            ->select()
            ->toArray();

        $grouped = [];

        foreach ($goodsRows as $row) {
            $grouped[(int) $row['orderid']][] = [
                'goods_id'   => (int) ($row['proid'] ?? 0),
                'name'       => (string) ($row['name'] ?? ''),
                'pic'        => (string) ($row['pic'] ?? ''),
                'num'        => (int) ($row['num'] ?? 0),
                'price'      => (float) ($row['sell_price'] ?? 0),
                'total'      => (float) ($row['totalprice'] ?? 0),
                'sku'        => (string) ($row['ggname'] ?? ''),
                'procode'    => (string) ($row['procode'] ?? ''),
                'refund_num' => (int) ($row['refund_num'] ?? 0),
            ];
        }

        // 售后单数（对照参考系统 refundCount）
        $refundCountMap = [];
        $refundRows = Db::name('shop_refund_order')
            ->whereIn('orderid', $ids)
            ->field('orderid, count(*) as cnt')
            ->group('orderid')
            ->select()
            ->toArray();
        foreach ($refundRows as $rr) {
            $refundCountMap[(int) $rr['orderid']] = (int) $rr['cnt'];
        }

        return array_map(function (array $order) use ($grouped, $refundCountMap, $forAdmin) {
            $oid  = (int) $order['id'];
            $base = [
                'id'              => $oid,
                'order_sn'        => (string) ($order['ordernum'] ?? ''),
                'mid'             => (int) ($order['mid'] ?? 0),
                'status'          => (int) ($order['status'] ?? 0),
                'status_text'     => self::STATUS_TEXT[(int) ($order['status'] ?? 0)] ?? '未知',
                'pay_amount'      => (float) ($order['totalprice'] ?? 0),
                'product_amount'  => (float) ($order['product_price'] ?? 0),
                'coupon_money'    => (float) ($order['coupon_money'] ?? 0),
                'promotion_id'    => (int) ($order['promotion_id'] ?? 0),
                'promotion_money' => (float) ($order['promotion_money'] ?? 0),
                'use_score'       => (int) ($order['use_score'] ?? 0),
                'score_money'     => (float) ($order['score_money'] ?? 0),
                'freight'         => (float) ($order['freight_price'] ?? 0),
                'pay_type'        => (string) ($order['paytype'] ?? ''),
                'platform'        => (string) ($order['platform'] ?? ''),
                'freight_text'    => (string) ($order['freight_text'] ?? ''),
                'refund_status'   => (int) ($order['refund_status'] ?? 0),
                'refund_money'    => (float) ($order['refund_money'] ?? 0),
                'refund_count'    => $refundCountMap[$oid] ?? 0,
                'buyer_message'   => (string) ($order['message'] ?? ''),
                'remark'          => (string) ($order['remark'] ?? ''),
                'express_company' => (string) ($order['express_com'] ?? ''),
                'express_no'      => (string) ($order['express_no'] ?? ''),
                'pay_time'        => $this->formatTime($order['paytime'] ?? null),
                'ship_time'       => $this->formatTime($order['send_time'] ?? null),
                'create_time'     => $this->formatTime($order['createtime'] ?? null),
                'goods'           => $grouped[$oid] ?? [],
                'address'         => $this->formatAddress($order),
            ];

            if ($forAdmin) {
                $base['bid']            = (int) ($order['bid'] ?? 0);
                $base['member_phone']    = (string) ($order['member_phone'] ?? '');
                $base['member_nickname'] = (string) ($order['member_nickname'] ?? '');
                $base['member_headimg']  = (string) ($order['member_headimg'] ?? '');
            } else {
                $base['pay_expire_time'] = $this->formatTime($order['pay_expire_time'] ?? null);
            }

            return $base;
        }, $orders);
    }

    /**
     * 地址快照格式化（detail = 省市区 + 详细地址）
     *
     * @param array<string, mixed> $order
     * @return array{name: string, phone: string, province: string, city: string, district: string, detail: string}
     */
    protected function formatAddress(array $order): array
    {
        $area2 = array_pad(explode(',', (string) ($order['area2'] ?? '')), 3, '');

        return [
            'name'     => (string) ($order['linkman'] ?? ''),
            'phone'    => (string) ($order['tel'] ?? ''),
            'province' => trim($area2[0]),
            'city'     => trim($area2[1]),
            'district' => trim($area2[2]),
            'detail'   => trim(((string) ($order['area'] ?? '')) . ' ' . ((string) ($order['address'] ?? ''))),
        ];
    }

    /**
     * 时间戳格式化（空值返回空串）
     */
    protected function formatTime(mixed $timestamp): string
    {
        $timestamp = (int) $timestamp;

        return $timestamp > 0 ? date('Y-m-d H:i:s', $timestamp) : '';
    }
}
