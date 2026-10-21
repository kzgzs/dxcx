<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;

/**
 * 支付服务（订单支付与回调）
 *
 * 模式：
 * - mock 模式（env PAY.MOCK=1，默认）：pay 接口内部直接触发支付成功流程（等价回调），
 *   回调端点同样走幂等入账；另提供 /api/pay/notify/mock 模拟第三方回调
 * - 真实模式（PAY.MOCK=0）：微信支付 V3 真实下单/回调验签/退款/分账/查单（见 WxpayService/WxpayV3Client）；
 *   支付宝仍为桩（TODO）
 *
 * 幂等约定：支付成功入账 handlePaySuccess() 仅在 status=0 时改 1，
 * 重复回调/重复触发一律返回「已处理」不重复入账。
 *
 * 多订单类型泛化（P6）：
 * - shop_order 支付链路（pay/handlePaySuccess/notifyByOrderSn）保持原样，零破坏
 * - 行业订单（carhailing/hotel/yuyue/restaurant）走 pay_order 统一支付单：
 *   payBiz() 创建支付单 → mock 直接 routeBizPay / 回调 notifyByOutTradeNo()
 *   按 out_trade_no 路由：命中 pay_order 走行业 onPaid，未命中回落 shop ordernum
 * - 行业退款走静态 refundBiz()（避免行业 Service 反向注入本类造成循环依赖）
 *
 * 状态机（shop_order.status）：0待支付 1已支付待发货 2已发货 3已完成 4已取消
 * 支付方式口径：paytype 存 'wechat'/'alipay'，paytypeid 沿用旧表 2=微信 3=支付宝
 */
class PayService
{
    /** 支付方式 → 旧表 paytypeid 映射 */
    public const PAY_TYPE_IDS = ['wechat' => 2, 'alipay' => 3];

    public function __construct(
        protected CouponService $couponService,
        protected ScoreService $scoreService,
        protected CarhailingService $carhailingService,
        protected HotelService $hotelService,
        protected YuyueService $yuyueService,
        protected RestaurantService $restaurantService,
        protected BookingService $bookingService,
        protected RestaurantTakeawayService $restaurantTakeawayService,
        protected FenxiaoService $fenxiaoService,
        protected UpLevelService $upLevelService,
        protected KechengService $kechengService,
        protected DesignerService $designerService,
        protected FormService $formService
    ) {
    }

    /** 行业订单类型 → 订单表 */
    public const BIZ_ORDER_TABLES = [
        'carhailing' => 'carhailing_order',
        'hotel'      => 'hotel_order',
        'yuyue'      => 'yuyue_order',
        'restaurant' => 'restaurant_shop_order',
        'restaurant_booking' => 'restaurant_booking_order',
        // 餐饮外卖（金额列 pay_amount 由 20261012000001 补列）
        'restaurant_takeaway' => 'restaurant_takeaway_order',
        'kecheng'    => 'kecheng_order',
        // 任务305：设计页付费查看购买单
        'pageview'   => 'designer_page_buy',
        // 自定义表单（租户设计表单开启在线支付后，C端提交走统一支付单）
        'form'       => 'form_order',
    ];

    /**
     * 是否 mock 模式（默认开启）
     *
     * 注：parse_ini_file 不去除行内 # 注释，值可能为 "1 # 注释"，此处取首段并容错判断
     */
    public static function isMock(): bool
    {
        $raw = (string) env('PAY.MOCK', '1');
        $value = trim(strtok($raw, '#'));

        return '' === $value || '1' === $value;
    }

    /**
     * 待支付订单超时秒数（默认 10 分钟）
     */
    public static function payTimeout(): int
    {
        $timeout = (int) trim(strtok((string) env('PAY.TIMEOUT', '600'), '#'));

        return $timeout > 0 ? $timeout : 600;
    }

    /**
     * 会员发起支付
     *
     * mock 模式：内部直接触发支付成功流程，返回 {mock:true, status:1}
     * 真实模式：返回微信 V3 prepay / 支付宝下单参数（本期桩，未实现真实签名）
     *
     * @return array<string, mixed>
     */
    public function pay(int $aid, int $mid, int $orderId, string $payType): array
    {
        if (!isset(self::PAY_TYPE_IDS[$payType])) {
            throw new BusinessException('不支持的支付方式');
        }

        $order = ScopeService::query('shop_order', $aid)
            ->where('id', $orderId)
            ->where('mid', $mid)
            ->find();

        if (empty($order)) {
            throw new BusinessException('订单不存在', 404, 404);
        }

        if (1 !== (int) $order['status'] && 0 !== (int) $order['status']) {
            throw new BusinessException('当前订单状态不可支付');
        }

        if (1 === (int) $order['status']) {
            // 已支付：幂等返回成功
            return ['mock' => self::isMock(), 'status' => 1];
        }

        if (!empty($order['pay_expire_time']) && (int) $order['pay_expire_time'] < time()) {
            throw new BusinessException('订单已超时关闭，请重新下单');
        }

        if (self::isMock()) {
            $this->handlePaySuccess((int) $order['id'], $payType, 'MOCK' . time() . random_int(1000, 9999));

            return ['mock' => true, 'status' => 1];
        }

        // 真实模式（本期仅桩）
        return 'wechat' === $payType
            ? $this->wechatPrepay($order)
            : $this->alipayTrade($order);
    }

    /**
     * 支付成功入账（幂等核心）
     *
     * 行锁 + status=0 条件更新，保证并发/重复回调只入账一次
     *
     * @return bool true=本次入账 false=已处理过（幂等）
     */
    public function handlePaySuccess(int $orderId, string $payType, string $payNum = ''): bool
    {
        return (bool) Db::transaction(function () use ($orderId, $payType, $payNum) {
            $order = Db::name('shop_order')->where('id', $orderId)->lock(true)->find();

            if (empty($order) || 0 !== (int) $order['status']) {
                return false; // 已支付/已取消等：幂等不重复处理
            }

            Db::name('shop_order')->where('id', $orderId)->where('status', 0)->update([
                'status'     => 1,
                'paytime'    => time(),
                'paytype'    => $payType,
                'paytypeid'  => self::PAY_TYPE_IDS[$payType] ?? 0,
                'paynum'     => $payNum,
            ]);

            // 同步订单商品行状态（0未付款→1已付款）
            Db::name('shop_order_goods')->where('orderid', $orderId)->where('status', 0)->update([
                'status' => 1,
            ]);

            // 优惠券核销（record status=1/use_time/order_id，幂等）
            $this->couponService->consumeByOrder((int) ($order['coupon_rid'] ?? 0), $orderId);

            // 积分赠送：pay_score × floor(实付)（流水 type=1；开关关/未配赠送时为 0 不落流水）
            $this->scoreService->grantForPay(
                (int) ($order['aid'] ?? 0),
                (int) ($order['mid'] ?? 0),
                (float) ($order['totalprice'] ?? 0),
                '支付赠送 订单' . (string) ($order['ordernum'] ?? '')
            );

            // 分销引擎（任务179 批次3-A）：佣金计算 + og 分销字段 + commission_record 冻结；升级链连锁检查
            $this->fenxiaoService->settleOrderCommission((int) ($order['aid'] ?? 0), $orderId);
            $this->upLevelService->checkUpChain((int) ($order['aid'] ?? 0), (int) ($order['mid'] ?? 0));

            return true;
        });
    }

    /**
     * 按订单号入账（回调场景）
     *
     * @return array{handled: bool, order_sn: string}
     */
    public function notifyByOrderSn(string $orderSn, string $payType, string $payNum = ''): array
    {
        $orderSn = trim($orderSn);

        if ('' === $orderSn) {
            throw new BusinessException('缺少订单号');
        }

        $order = Db::name('shop_order')->where('ordernum', $orderSn)->find();

        if (empty($order)) {
            throw new BusinessException('订单不存在', 404, 404);
        }

        $handled = $this->handlePaySuccess((int) $order['id'], $payType, $payNum);

        return ['handled' => $handled, 'order_sn' => $orderSn];
    }

    /**
     * 模拟第三方回调（仅 mock 模式生效，幂等；兼容 shop 订单号与行业 out_trade_no）
     *
     * @return array{mock: true, handled: bool, order_sn: string}
     */
    public function mockNotify(string $orderSn): array
    {
        if (!self::isMock()) {
            throw new BusinessException('mock 回调仅在 mock 模式下可用', 403, 403);
        }

        $result = $this->notifyByOutTradeNo($orderSn, 'wechat', 'MOCKNOTIFY' . time());

        return ['mock' => true] + $result;
    }

    /* ================= 行业订单（carhailing/hotel）支付泛化 ================= */

    /**
     * 行业订单发起支付（pay_order 统一支付单）
     *
     * mock 模式：内部直接触发支付成功流程（等价回调），返回 {mock:true,status:1}
     * 真实模式：本期桩（TODO）
     *
     * @param string $orderType carhailing|hotel|yuyue|restaurant|restaurant_booking|kecheng
     * @return array<string, mixed>
     */
    public function payBiz(int $aid, int $mid, string $orderType, int $bizOrderId, string $payType): array
    {
        if (!isset(self::PAY_TYPE_IDS[$payType])) {
            throw new BusinessException('不支持的支付方式');
        }
        if (!isset(self::BIZ_ORDER_TABLES[$orderType])) {
            throw new BusinessException('订单类型无效');
        }

        $order = ScopeService::query(self::BIZ_ORDER_TABLES[$orderType], $aid)
            ->where('id', $bizOrderId)
            ->where('mid', $mid)
            ->find();

        if (empty($order)) {
            throw new BusinessException('订单不存在', 404, 404);
        }

        // form_order 口径：金额列 money、支付状态列 paystatus（status 为审核状态，不参与支付判定）
        if ('form' === $orderType) {
            if (0 !== (int) $order['paystatus']) {
                return ['mock' => self::isMock(), 'status' => 1]; // 已支付：幂等
            }
            $payAmount = (float) $order['money'];
        } else {
            if (0 !== (int) $order['status']) {
                if (in_array((int) $order['status'], [1, 2], true)) {
                    return ['mock' => self::isMock(), 'status' => 1]; // 已支付：幂等
                }
                throw new BusinessException('当前订单状态不可支付');
            }
            $payAmount = (float) $order['pay_amount'];
        }

        // 支付单复用/创建（out_trade_no 全局唯一）
        $payOrder = Db::name('pay_order')
            ->where('order_type', $orderType)
            ->where('biz_order_id', $bizOrderId)
            ->where('status', 0)
            ->find();

        if (empty($payOrder)) {
            $outTradeNo = 'PT' . date('YmdHis') . strtoupper(substr($orderType, 0, 1))
                . str_pad((string) ($bizOrderId % 100000000), 8, '0', STR_PAD_LEFT);

            $payOrderId = (int) Db::name('pay_order')->insertGetId([
                'aid'          => $aid,
                'order_type'   => $orderType,
                'biz_order_id' => $bizOrderId,
                'out_trade_no' => $outTradeNo,
                'pay_amount'   => $payAmount,
                'pay_type'     => $payType,
                'status'       => 0,
                'pay_time'     => 0,
                'createtime'   => time(),
            ]);

            $payOrder = Db::name('pay_order')->where('id', $payOrderId)->find();
        }

        if (self::isMock()) {
            $this->handleBizPaySuccess((int) $payOrder['id'], $payType, 'MOCK' . time() . random_int(1000, 9999));

            return ['mock' => true, 'status' => 1, 'out_trade_no' => (string) $payOrder['out_trade_no']];
        }

        // 真实模式：支付宝留桩；微信走 V3 JSAPI 下单
        if ('wechat' !== $payType) {
            throw new BusinessException('支付宝支付接入开发中（TODO）', 501, 501);
        }

        $result = WxpayService::createOrder(
            $aid,
            $mid,
            (string) $payOrder['out_trade_no'],
            (float) $payOrder['pay_amount'],
            $orderType . '订单支付',
            $orderType
        );

        return $result + ['out_trade_no' => (string) $payOrder['out_trade_no']];
    }

    /**
     * 按 out_trade_no 入账（回调场景，智能路由）
     *
     * 命中 pay_order → 行业订单入账；未命中 → 回落 shop_order ordernum 路径（兼容旧链路）
     *
     * @return array{handled: bool, order_sn: string}
     */
    public function notifyByOutTradeNo(string $outTradeNo, string $payType, string $payNum = ''): array
    {
        $outTradeNo = trim($outTradeNo);

        if ('' === $outTradeNo) {
            throw new BusinessException('缺少订单号');
        }

        $payOrder = Db::name('pay_order')->where('out_trade_no', $outTradeNo)->find();

        if (!empty($payOrder)) {
            $handled = $this->handleBizPaySuccess((int) $payOrder['id'], $payType, $payNum);

            return ['handled' => $handled, 'order_sn' => $outTradeNo];
        }

        return $this->notifyByOrderSn($outTradeNo, $payType, $payNum);
    }

    /**
     * 行业订单支付成功入账（幂等核心：pay_order 行锁 + status=0 条件更新）
     *
     * @return bool true=本次入账 false=已处理过（幂等）
     */
    public function handleBizPaySuccess(int $payOrderId, string $payType, string $payNum = ''): bool
    {
        return (bool) Db::transaction(function () use ($payOrderId, $payType) {
            $payOrder = Db::name('pay_order')->where('id', $payOrderId)->lock(true)->find();

            if (empty($payOrder) || 0 !== (int) $payOrder['status']) {
                return false; // 已支付：幂等不重复处理
            }

            Db::name('pay_order')->where('id', $payOrderId)->where('status', 0)->update([
                'status'   => 1,
                'pay_time' => time(),
                'pay_type' => $payType,
            ]);

            $orderType = (string) ($payOrder['order_type'] ?? '');
            $bizId     = (int) ($payOrder['biz_order_id'] ?? 0);

            return match ($orderType) {
                'carhailing' => $this->carhailingService->onPaid($bizId, $payType, ''),
                'hotel'      => $this->hotelService->onPaid($bizId, $payType, ''),
                'yuyue'      => $this->yuyueService->onPaid($bizId, $payType, ''),
                'restaurant' => $this->restaurantService->onPaid($bizId, $payType, ''),
                'restaurant_booking' => $this->bookingService->onPaid($bizId, $payType, ''),
                'restaurant_takeaway' => $this->restaurantTakeawayService->onPaid($bizId, $payType, $payNum),
                'kecheng'    => $this->kechengService->onPaid($bizId, $payType, ''),
                'pageview'   => $this->designerService->onPageBuyPaid($bizId, $payType, ''),
                'form'       => $this->formService->onPaid($bizId, $payType, $payNum),
                default      => false,
            };
        });
    }

    /**
     * 支付查单补偿（对账，P8）：扫描超时未入账的行业支付单并捞回
     *
     * 判定：pay_order.status=0 且 createtime 早于 now-阈值（env PAY.RECONCILE_AFTER，默认 120 秒）
     * mock 模式：按「渠道已支付」口径直接 handleBizPaySuccess 捞回（幂等）
     * 真实模式：TODO 渠道查单（微信 V3 GET /v3/pay/transactions/out-trade-no/{out_trade_no}；
     *           支付宝 alipay.trade.query），确认已支付后再入账，本期不自动捞回
     *
     * @return array{scanned: int, rescued: int, mock: bool}
     */
    public function reconcileBiz(): array
    {
        $after = (int) env('PAY.RECONCILE_AFTER', 120);

        if ($after < 10) {
            $after = 10; // 阈值下限，避免误捞刚创建的正常待支付单
        }

        $rows = Db::name('pay_order')
            ->where('status', 0)
            ->where('createtime', '<', time() - $after)
            ->order('id', 'asc')
            ->limit(500)
            ->select()
            ->toArray();

        $scanned = count($rows);
        $rescued = 0;

        foreach ($rows as $row) {
            if (self::isMock()) {
                // mock 模式：渠道侧视为已支付，直接捞回入账（handleBizPaySuccess 内部幂等）
                if ($this->handleBizPaySuccess(
                    (int) $row['id'],
                    (string) ($row['pay_type'] ?: 'wechat'),
                    'RECONCILE' . time() . str_pad((string) ((int) $row['id'] % 10000), 4, '0', STR_PAD_LEFT)
                )) {
                    ++$rescued;
                }

                continue;
            }

            // 真实模式：微信 V3 查单补偿（渠道侧已支付且金额一致才捞回，内部幂等）
            if ('wechat' === (string) ($row['pay_type'] ?: 'wechat') && WxpayService::reconcileRow($row)) {
                ++$rescued;
            }
        }

        return ['scanned' => $scanned, 'rescued' => $rescued, 'mock' => self::isMock()];
    }

    /**
     * 行业订单退款（静态：避免行业 Service 构造注入本类造成循环依赖）
     *
     * mock 模式即时成功；真实模式本期桩（TODO）
     *
     * @return array{mock: bool, success: bool}
     */
    public static function refundBiz(string $orderType, int $bizOrderId, float $refundAmount, string $refundSn): array
    {
        if (self::isMock()) {
            return ['mock' => true, 'success' => true];
        }

        // 真实退款：按 pay_order 定位原支付渠道单号（微信 V3 原路退款）
        $payOrder = Db::name('pay_order')
            ->where('order_type', $orderType)
            ->where('biz_order_id', $bizOrderId)
            ->where('status', 1)
            ->order('id', 'desc')
            ->find();

        if (empty($payOrder)) {
            throw new BusinessException('未找到已支付的支付单，无法退款');
        }

        if ('wechat' !== (string) $payOrder['pay_type']) {
            throw new BusinessException('支付宝退款接入开发中（TODO）', 501, 501);
        }

        return WxpayService::refund((string) $payOrder['out_trade_no'], $refundAmount, $refundSn);
    }

    /**
     * 发起退款（审核通过后调用）
     *
     * mock 模式：即时成功；真实模式：微信 V3 退款/支付宝退款接口本期仅留桩（TODO）
     *
     * @param array<string, mixed> $order 原订单行（取 paytype/paynum/totalprice）
     * @return array{mock: bool, success: bool}
     */
    public function refund(array $order, float $refundAmount, string $refundSn): array
    {
        if (self::isMock()) {
            return ['mock' => true, 'success' => true];
        }

        // 真实退款：仅原路微信 V3 退款（POST /v3/refund/domestic/refunds）；支付宝留桩
        if ('wechat' !== (string) ($order['paytype'] ?? '')) {
            throw new BusinessException('支付宝退款接入开发中（TODO）', 501, 501);
        }

        return WxpayService::refund(
            (string) ($order['ordernum'] ?? ''),
            $refundAmount,
            $refundSn,
            (string) ($order['refund_reason'] ?? '')
        );
    }

    /**
     * 微信支付 V3 下单（真实模式：JSAPI 下单 + 小程序拉起参数）
     *
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    protected function wechatPrepay(array $order): array
    {
        return WxpayService::createOrder(
            (int) ($order['aid'] ?? 0),
            (int) ($order['mid'] ?? 0),
            (string) ($order['ordernum'] ?? ''),
            (float) ($order['totalprice'] ?? 0),
            '商城订单支付',
            'shop'
        );
    }

    /**
     * 支付宝下单（TODO 桩：真实签名接入后实现）
     *
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    protected function alipayTrade(array $order): array
    {
        // TODO: 调用 alipay.trade.create/precreate，需接入：应用私钥签名、支付宝公钥验签
        throw new BusinessException('支付宝支付接入开发中（TODO：alipay.trade.create）', 501, 501);
    }

    /**
     * 回调验签（mock 放行；真实微信验签在 WxpayService::handleNotify 内完成，此方法仅兜底）
     *
     * mock 模式直接放行；真实模式：
     * - 微信：真实验签已前置在 Pay 控制器 → WxpayService::handleNotify（平台证书 SHA256-RSA + AES-GCM 解密），
     *   走到这里说明未经过真实验签通道，一律拒绝，防止伪造回调
     * - 支付宝：RSA2 对 form 参数验签（本期未接入，拒绝）
     */
    public function verifyNotifySignature(string $channel, array $raw): bool
    {
        if (self::isMock()) {
            return true;
        }

        return false;
    }
}
