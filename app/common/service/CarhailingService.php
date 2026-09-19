<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;

/**
 * 网约车（租车预约）服务（P6 行业扩展第一期）
 *
 * 表：carhailing_category / carhailing_product / carhailing_order
 * 订单状态机：0待支付 1已支付 2使用中 3已完成 4已取消 5已退款
 *
 * 【计价规则】（时长 = end_time - start_time，Unix 秒）：
 * - 时长 < 4h              → 拒绝下单
 * - 4h <= 时长 < 24h       → 半天价：amount = price_half（days=0.5）
 * - 时长 >= 24h            → 按天计价：days = ceil(时长/86400)，amount = days × price_day
 *
 * 【车辆并发校验】同 product_id、状态 NOT IN(4,5)、日期区间重叠
 * （old.start < new.end 且 old.end > new.start）的订单数 < car_num 才可下单；
 * car_num=0 视为不限。
 *
 * 券/积分叠加：券门槛按租金额（amount），积分抵扣走既有管道，实付下限 0.01
 * 支付/回调：pay_order 统一支付单（PayService::payBiz/onPaid 联动）
 */
class CarhailingService
{
    /** 订单状态 */
    public const STATUS_UNPAID    = 0;
    public const STATUS_PAID      = 1;
    public const STATUS_IN_USE    = 2;
    public const STATUS_FINISHED  = 3;
    public const STATUS_CANCELLED = 4;
    public const STATUS_REFUNDED  = 5;

    public const STATUS_TEXT = [
        self::STATUS_UNPAID    => '待支付',
        self::STATUS_PAID      => '已支付',
        self::STATUS_IN_USE    => '使用中',
        self::STATUS_FINISHED  => '已完成',
        self::STATUS_CANCELLED => '已取消',
        self::STATUS_REFUNDED  => '已退款',
    ];

    /** 最短租车时长（4 小时） */
    protected const MIN_DURATION = 4 * 3600;
    /** 半天/全天分界（24 小时） */
    protected const DAY_DURATION = 24 * 3600;

    public function __construct(protected CouponService $couponService, protected ScoreService $scoreService)
    {
    }

    /* ================= admin：车辆分类 ================= */

    /**
     * 分类列表（全量，sort 大者靠前）
     *
     * @return array{list: array}
     */
    public function categoryList(int $aid): array
    {
        $rows = ScopeService::query('carhailing_category', $aid)
            ->where('deleted', 0)
            ->order('sort', 'desc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        return [
            'list'  => array_map(fn (array $row) => $this->formatCategory($row), $rows),
            'total' => count($rows),
        ];
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

        $id = (int) Db::name('carhailing_category')->insertGetId([
            'aid'         => $aid,
            'name'        => $name,
            'sort'        => (int) ($data['sort'] ?? 0),
            'status'      => in_array($status, [0, 1], true) ? $status : 1,
            'deleted'     => 0,
            'create_time' => time(),
        ]);

        return ['id' => $id];
    }

    /**
     * 编辑分类
     *
     * @param array<string, mixed> $data
     */
    public function categoryEdit(int $aid, array $data): void
    {
        $category = $this->findCategory($aid, (int) ($data['id'] ?? 0));

        $update = [];

        if (array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);

            if ('' === $name) {
                throw new BusinessException('请填写分类名称');
            }
            $update['name'] = $name;
        }

        if (array_key_exists('sort', $data)) {
            $update['sort'] = (int) $data['sort'];
        }

        if (array_key_exists('status', $data)) {
            if (!in_array((int) $data['status'], [0, 1], true)) {
                throw new BusinessException('状态值无效');
            }
            $update['status'] = (int) $data['status'];
        }

        if (!empty($update)) {
            Db::name('carhailing_category')->where('id', (int) $category['id'])->update($update);
        }
    }

    /**
     * 删除分类（软删；分类下有车辆拒绝）
     */
    public function categoryDelete(int $aid, int $id): void
    {
        $category = $this->findCategory($aid, $id);

        $productCount = ScopeService::query('carhailing_product', $aid)
            ->where('category_id', $id)
            ->where('deleted', 0)
            ->count();

        if ($productCount > 0) {
            throw new BusinessException('该分类下有车辆，不能删除');
        }

        Db::name('carhailing_category')->where('id', (int) $category['id'])->update(['deleted' => 1]);
    }

    /* ================= admin：车辆管理 ================= */

    /**
     * 车辆列表（keyword 匹配名称；category_id/status 筛选）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function productList(int $aid, string $keyword, int $categoryId, string $status, int $page, int $pageSize): array
    {
        $query = ScopeService::query('carhailing_product', $aid)->where('deleted', 0);

        if ('' !== $keyword) {
            $query->where('name', 'like', '%' . $keyword . '%');
        }
        if ($categoryId > 0) {
            $query->where('category_id', $categoryId);
        }
        if ('' !== $status) {
            $query->where('status', (int) $status);
        }

        $total = (clone $query)->count();

        $rows = $query->order('id', 'desc')
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
     * 新建车辆
     *
     * @param array<string, mixed> $data
     * @return array{id: int}
     */
    public function productAdd(int $aid, array $data): array
    {
        [$name, $categoryId, $priceDay, $priceHalf, $carNum, $status] = $this->validateProductData($aid, $data, true);

        $id = (int) Db::name('carhailing_product')->insertGetId([
            'aid'         => $aid,
            'category_id' => $categoryId,
            'name'        => $name,
            'pic'         => trim((string) ($data['pic'] ?? '')),
            'pics'        => trim((string) ($data['pics'] ?? '')),
            'price_day'   => $priceDay,
            'price_half'  => $priceHalf,
            'car_num'     => $carNum,
            'description' => trim((string) ($data['desc'] ?? '')),
            'status'      => $status,
            'deleted'     => 0,
            'createtime'  => time(),
        ]);

        return ['id' => $id];
    }

    /**
     * 编辑车辆
     *
     * @param array<string, mixed> $data
     */
    public function productEdit(int $aid, array $data): void
    {
        $product = $this->findProduct($aid, (int) ($data['id'] ?? 0));

        $update = [];

        if (array_key_exists('name', $data) || array_key_exists('category_id', $data)
            || array_key_exists('price_day', $data) || array_key_exists('price_half', $data)
            || array_key_exists('car_num', $data) || array_key_exists('status', $data)) {
            [$name, $categoryId, $priceDay, $priceHalf, $carNum, $status] = $this->validateProductData($aid, array_merge($product, $data), false);

            $update['name']        = $name;
            $update['category_id'] = $categoryId;
            $update['price_day']   = $priceDay;
            $update['price_half']  = $priceHalf;
            $update['car_num']     = $carNum;
            $update['status']      = $status;
        }

        if (array_key_exists('pic', $data)) {
            $update['pic'] = trim((string) $data['pic']);
        }
        if (array_key_exists('pics', $data)) {
            $update['pics'] = trim((string) $data['pics']);
        }
        if (array_key_exists('desc', $data)) {
            $update['description'] = trim((string) $data['desc']);
        }

        if (!empty($update)) {
            Db::name('carhailing_product')->where('id', (int) $product['id'])->update($update);
        }
    }

    /**
     * 删除车辆（软删；有未完结订单拒绝）
     */
    public function productDelete(int $aid, int $id): void
    {
        $product = $this->findProduct($aid, $id);

        $openCount = Db::name('carhailing_order')
            ->where('product_id', $id)
            ->whereIn('status', [self::STATUS_UNPAID, self::STATUS_PAID, self::STATUS_IN_USE])
            ->count();

        if ($openCount > 0) {
            throw new BusinessException('该车辆有未完结订单，不能删除');
        }

        Db::name('carhailing_product')->where('id', (int) $product['id'])->update(['deleted' => 1]);
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

        Db::name('carhailing_product')->where('id', (int) $product['id'])->update(['status' => $status]);
    }

    /* ================= admin：用车订单 ================= */

    /**
     * 订单列表（含会员手机；keyword 匹配订单号/车辆名/手机号）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function adminOrderList(int $aid, string $status, string $keyword, int $page, int $pageSize): array
    {
        $query = ScopeService::queryAs('carhailing_order', $aid, 'o')
            ->leftJoin('member m', 'm.id = o.mid')
            ->field('o.*, m.tel as member_phone');

        if ('' !== $status) {
            $query->where('o.status', (int) $status);
        }

        if ('' !== $keyword) {
            $like = '%' . $keyword . '%';
            $query->where(function ($q) use ($like) {
                $q->where('o.order_sn', 'like', $like)
                    ->whereOr('o.product_name', 'like', $like)
                    ->whereOr('m.tel', 'like', $like);
            });
        }

        $total = (clone $query)->count();

        $rows = $query->order('o.id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        return [
            'list'     => array_map(fn (array $row) => $this->formatOrder($row), $rows),
            'total'    => $total,
            'page'     => $page,
            'pagesize' => $pageSize,
        ];
    }

    /**
     * 完成订单（已支付/使用中 → 已完成）
     */
    public function adminOrderFinish(int $aid, int $id): void
    {
        $order = $this->findOrder($aid, 0, $id);

        if (!in_array((int) $order['status'], [self::STATUS_PAID, self::STATUS_IN_USE], true)) {
            throw new BusinessException('当前订单状态不可完成（须已支付或使用中）');
        }

        Db::name('carhailing_order')->where('id', $id)->update(['status' => self::STATUS_FINISHED]);
    }

    /* ================= api 会员侧 ================= */

    /**
     * 车辆列表（匿名；分类筛选，仅上架）
     *
     * @return array{list: array}
     */
    public function apiList(int $aid, int $categoryId): array
    {
        $query = ScopeService::query('carhailing_product', $aid)
            ->where('status', 1)
            ->where('deleted', 0);

        if ($categoryId > 0) {
            $query->where('category_id', $categoryId);
        }

        $rows = $query->order('id', 'desc')->select()->toArray();

        return ['list' => array_map(fn (array $row) => $this->formatProduct($row), $rows)];
    }

    /**
     * 车辆详情（匿名）
     *
     * @return array<string, mixed>
     */
    public function apiDetail(int $aid, int $id): array
    {
        $product = ScopeService::query('carhailing_product', $aid)
            ->where('id', $id)
            ->where('status', 1)
            ->where('deleted', 0)
            ->find();

        if (empty($product)) {
            throw new BusinessException('车辆不存在或已下架', 404, 404);
        }

        return $this->formatProduct($product);
    }

    /**
     * 创建租车订单
     *
     * 【计价】时长<4h 拒绝；4h~24h 半天价；>=24h 按天 ceil×price_day
     * 【并发】事务内行锁车辆 + 复检同期未取消订单数 < car_num
     * 【叠加】券（门槛按租金额）→ 积分抵扣 → 实付下限 0.01
     *
     * @return array{id: int, order_sn: string, amount: float, coupon_money: float, score_money: float, pay_amount: float, days: float, use_score: int, coupon_id: int}
     */
    public function createOrder(int $aid, int $mid, int $productId, int $startTime, int $endTime, int $couponId = 0, int $useScore = 0): array
    {
        if ($productId <= 0) {
            throw new BusinessException('请选择车辆');
        }
        if ($startTime <= 0 || $endTime <= 0) {
            throw new BusinessException('请选择用车时间');
        }
        if ($endTime <= $startTime) {
            throw new BusinessException('还车时间必须晚于取车时间');
        }
        if ($startTime < time()) {
            throw new BusinessException('取车时间不能早于当前时间');
        }

        $product = ScopeService::query('carhailing_product', $aid)
            ->where('id', $productId)
            ->where('status', 1)
            ->where('deleted', 0)
            ->find();

        if (empty($product)) {
            throw new BusinessException('车辆不存在或已下架', 404, 404);
        }

        // ===== 计价 =====
        $duration = $endTime - $startTime;

        if ($duration < self::MIN_DURATION) {
            throw new BusinessException('租车时长不能少于4小时');
        }

        if ($duration < self::DAY_DURATION) {
            $days   = 0.5;
            $amount = round((float) $product['price_half'], 2);
        } else {
            $days   = (float) (int) ceil($duration / self::DAY_DURATION);
            $amount = round($days * (float) $product['price_day'], 2);
        }

        if ($amount <= 0) {
            throw new BusinessException('车辆价格未配置');
        }

        // ===== 券（门槛按租金额）与积分（既有管道） =====
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

        return Db::transaction(function () use ($aid, $mid, $productId, $startTime, $endTime, $product, $days, $amount, $couponPick, $couponMoney, $useScore, $scoreMoney, $payAmount, $now) {
            // 车辆并发校验：行锁车辆 + 同期（区间重叠）未取消/未退款订单数 < car_num
            Db::name('carhailing_product')->where('id', $productId)->lock(true)->find();

            $carNum = (int) $product['car_num'];

            if ($carNum > 0) {
                $busy = Db::name('carhailing_order')
                    ->where('product_id', $productId)
                    ->whereNotIn('status', [self::STATUS_CANCELLED, self::STATUS_REFUNDED])
                    ->where('start_time', '<', $endTime)
                    ->where('end_time', '>', $startTime)
                    ->count();

                if ($busy >= $carNum) {
                    throw new BusinessException('该时段车辆已被预订，请选择其他时间');
                }
            }

            $orderId = (int) Db::name('carhailing_order')->insertGetId([
                'aid'          => $aid,
                'mid'          => $mid,
                'order_sn'     => '',
                'product_id'   => $productId,
                'product_name' => (string) ($product['name'] ?? ''),
                'pic'          => (string) ($product['pic'] ?? ''),
                'start_time'   => $startTime,
                'end_time'     => $endTime,
                'days'         => $days,
                'amount'       => $amount,
                'coupon_money' => $couponMoney,
                'score_money'  => $scoreMoney,
                'pay_amount'   => $payAmount,
                'status'       => self::STATUS_UNPAID,
                'pay_time'     => 0,
                'coupon_id'    => null !== $couponPick ? (int) $couponPick['coupon_id'] : 0,
                'coupon_rid'   => null !== $couponPick ? (int) $couponPick['record_id'] : 0,
                'use_score'    => $useScore,
                'createtime'   => $now,
                'deleted'      => 0,
            ]);

            $orderSn = 'CH' . date('YmdHis', $now)
                . str_pad((string) ($aid % 10000), 4, '0', STR_PAD_LEFT)
                . str_pad((string) ($orderId % 1000000), 6, '0', STR_PAD_LEFT);

            Db::name('carhailing_order')->where('id', $orderId)->update(['order_sn' => $orderSn]);

            // 用券预锁定（行锁抢占，失败回滚整单）
            if (null !== $couponPick) {
                $this->couponService->bindToOrder((int) $couponPick['record_id'], $orderId, 'carhailing');
            }

            // 积分预扣（取消时原数返还）
            if ($useScore > 0) {
                $this->scoreService->deductForOrder($aid, $mid, $useScore, '下单抵扣 用车订单' . $orderSn);
            }

            return [
                'id'           => $orderId,
                'order_sn'     => $orderSn,
                'days'         => $days,
                'amount'       => $amount,
                'coupon_id'    => null !== $couponPick ? (int) $couponPick['coupon_id'] : 0,
                'coupon_money' => $couponMoney,
                'use_score'    => $useScore,
                'score_money'  => $scoreMoney,
                'pay_amount'   => $payAmount,
            ];
        });
    }

    /**
     * 我的用车订单列表
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function apiOrderList(int $aid, int $mid, string $status, int $page, int $pageSize): array
    {
        $query = ScopeService::query('carhailing_order', $aid)
            ->where('mid', $mid)
            ->where('deleted', 0);

        if ('' !== $status) {
            $query->where('status', (int) $status);
        }

        $total = (clone $query)->count();

        $rows = $query->order('id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        return [
            'list'     => array_map(fn (array $row) => $this->formatOrder($row), $rows),
            'total'    => $total,
            'page'     => $page,
            'pagesize' => $pageSize,
        ];
    }

    /**
     * 取消订单（仅待支付可取消；释放券 + 返还积分，同商城规则，幂等）
     */
    public function cancelOrder(int $aid, int $mid, int $orderId): void
    {
        $order = $this->findOrder($aid, $mid, $orderId);

        if (self::STATUS_UNPAID !== (int) $order['status']) {
            throw new BusinessException('当前订单状态不可取消（仅待支付可取消）');
        }

        $this->doCancel($orderId, $order);
    }

    /**
     * 未支付超时自动取消（P8 定时任务；复用取消口径：释放券+返还积分，幂等）
     *
     * 判定：status=0 且 createtime 早于 now-支付超时秒数（PAY.TIMEOUT，默认 600）
     *
     * @return int 本次取消的订单数
     */
    public function cancelExpiredUnpaid(): int
    {
        $ids = Db::name('carhailing_order')
            ->where('status', self::STATUS_UNPAID)
            ->where('createtime', '<', time() - PayService::payTimeout())
            ->where('createtime', '>', 0)
            ->order('id', 'asc')
            ->limit(500)
            ->column('id');

        $count = 0;

        foreach ($ids as $id) {
            $order = Db::name('carhailing_order')->where('id', (int) $id)->find();

            if (!empty($order) && self::STATUS_UNPAID === (int) $order['status'] && $this->doCancel((int) $id, $order)) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * 取消入账（幂等核心）：status=0 条件更新为已取消，成功后释放券+返还积分
     *
     * @param array<string, mixed> $order
     */
    protected function doCancel(int $orderId, array $order): bool
    {
        return (bool) Db::transaction(function () use ($orderId, $order) {
            $affected = Db::name('carhailing_order')
                ->where('id', $orderId)
                ->where('status', self::STATUS_UNPAID)
                ->update(['status' => self::STATUS_CANCELLED]);

            if (1 !== $affected) {
                return false; // 并发已变更：幂等跳过
            }

            // 释放券预锁定
            $this->couponService->releaseByOrder((int) ($order['coupon_rid'] ?? 0), $orderId);

            // 返还抵扣积分（流水 type=3）
            if ((int) ($order['use_score'] ?? 0) > 0) {
                $this->scoreService->refundForCancel(
                    (int) ($order['aid'] ?? 0),
                    (int) ($order['mid'] ?? 0),
                    (int) $order['use_score'],
                    '取消订单返还 用车订单' . (string) ($order['order_sn'] ?? '')
                );
            }

            return true;
        });
    }

    /**
     * 申请退款（仅已支付且用车未开始；走 PayService 退款 mock，券/积分不回收）
     *
     * @return array{mock: bool, success: bool}
     */
    public function refundOrder(int $aid, int $mid, int $orderId): array
    {
        $order = $this->findOrder($aid, $mid, $orderId);

        if (self::STATUS_PAID !== (int) $order['status']) {
            throw new BusinessException('仅已支付订单可退款');
        }

        if ((int) ($order['start_time'] ?? 0) <= time()) {
            throw new BusinessException('用车已开始，不可退款');
        }

        $result = PayService::refundBiz(
            'carhailing',
            $orderId,
            (float) $order['pay_amount'],
            'RF' . date('YmdHis') . str_pad((string) ($orderId % 1000000), 6, '0', STR_PAD_LEFT)
        );

        Db::name('carhailing_order')
            ->where('id', $orderId)
            ->where('status', self::STATUS_PAID)
            ->update(['status' => self::STATUS_REFUNDED]);

        return $result;
    }

    /**
     * 支付成功入账（PayService 回调路由调用，幂等：仅 status=0 时生效）
     */
    public function onPaid(int $orderId, string $payType, string $payNum): bool
    {
        return (bool) Db::transaction(function () use ($orderId, $payType) {
            $order = Db::name('carhailing_order')->where('id', $orderId)->lock(true)->find();

            if (empty($order) || self::STATUS_UNPAID !== (int) $order['status']) {
                return false; // 已支付/已取消等：幂等不重复处理
            }

            Db::name('carhailing_order')
                ->where('id', $orderId)
                ->where('status', self::STATUS_UNPAID)
                ->update(['status' => self::STATUS_PAID, 'pay_time' => time()]);

            // 券核销（幂等）
            $this->couponService->consumeByOrder((int) ($order['coupon_rid'] ?? 0), $orderId);

            // 积分赠送：pay_score × floor(实付)
            $this->scoreService->grantForPay(
                (int) ($order['aid'] ?? 0),
                (int) ($order['mid'] ?? 0),
                (float) ($order['pay_amount'] ?? 0),
                '支付赠送 用车订单' . (string) ($order['order_sn'] ?? '')
            );

            return true;
        });
    }

    /* ================= 内部工具 ================= */

    /**
     * 车辆数据校验（新增必填；编辑合并原值）
     *
     * @param array<string, mixed> $data
     * @return array{0: string, 1: int, 2: float, 3: float, 4: int, 5: int}
     */
    protected function validateProductData(int $aid, array $data, bool $isAdd): array
    {
        $name = trim((string) ($data['name'] ?? ''));

        if ('' === $name) {
            throw new BusinessException('请填写车辆名称');
        }

        $categoryId = (int) ($data['category_id'] ?? 0);

        $this->findCategory($aid, $categoryId);

        $priceDay = $this->parsePrice($data['price_day'] ?? null, '日租价');
        $priceHalf = $this->parsePrice($data['price_half'] ?? null, '半天价');

        if ($isAdd && ($priceDay <= 0 || $priceHalf <= 0)) {
            throw new BusinessException('请填写日租价与半天价（大于0）');
        }

        $carNum = (int) ($data['car_num'] ?? 0);

        if ($carNum < 0) {
            throw new BusinessException('车辆数不能为负数（0=不限）');
        }

        $status = (int) ($data['status'] ?? 1);

        if (!in_array($status, [0, 1], true)) {
            throw new BusinessException('状态值无效');
        }

        return [$name, $categoryId, $priceDay, $priceHalf, $carNum, $status];
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
     * 查找本租户未删除的分类（不存在抛 404）
     *
     * @return array<string, mixed>
     */
    protected function findCategory(int $aid, int $id): array
    {
        if ($id <= 0) {
            throw new BusinessException('缺少分类ID');
        }

        $category = ScopeService::query('carhailing_category', $aid)
            ->where('id', $id)
            ->where('deleted', 0)
            ->find();

        if (empty($category)) {
            throw new BusinessException('分类不存在', 404, 404);
        }

        return $category;
    }

    /**
     * 查找本租户未删除的车辆（不存在抛 404）
     *
     * @return array<string, mixed>
     */
    protected function findProduct(int $aid, int $id): array
    {
        if ($id <= 0) {
            throw new BusinessException('缺少车辆ID');
        }

        $product = ScopeService::query('carhailing_product', $aid)
            ->where('id', $id)
            ->where('deleted', 0)
            ->find();

        if (empty($product)) {
            throw new BusinessException('车辆不存在', 404, 404);
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

        $query = ScopeService::query('carhailing_order', $aid)
            ->where('id', $orderId)
            ->where('deleted', 0);

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
            'sort'        => (int) ($row['sort'] ?? 0),
            'status'      => (int) ($row['status'] ?? 1),
            'create_time' => (int) ($row['create_time'] ?? 0),
        ];
    }

    /**
     * 车辆行格式化（契约键 desc ← 列 description）
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function formatProduct(array $row): array
    {
        return [
            'id'          => (int) ($row['id'] ?? 0),
            'category_id' => (int) ($row['category_id'] ?? 0),
            'name'        => (string) ($row['name'] ?? ''),
            'pic'         => (string) ($row['pic'] ?? ''),
            'pics'        => (string) ($row['pics'] ?? ''),
            'price_day'   => (float) ($row['price_day'] ?? 0),
            'price_half'  => (float) ($row['price_half'] ?? 0),
            'car_num'     => (int) ($row['car_num'] ?? 0),
            'desc'        => (string) ($row['description'] ?? ''),
            'status'      => (int) ($row['status'] ?? 1),
            'create_time' => (int) ($row['createtime'] ?? 0),
        ];
    }

    /**
     * 订单行格式化
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function formatOrder(array $row): array
    {
        $status = (int) ($row['status'] ?? 0);

        return [
            'id'           => (int) ($row['id'] ?? 0),
            'order_sn'     => (string) ($row['order_sn'] ?? ''),
            'mid'          => (int) ($row['mid'] ?? 0),
            'member_phone' => (string) ($row['member_phone'] ?? ''),
            'product_id'   => (int) ($row['product_id'] ?? 0),
            'product_name' => (string) ($row['product_name'] ?? ''),
            'pic'          => (string) ($row['pic'] ?? ''),
            'start_time'   => (int) ($row['start_time'] ?? 0),
            'end_time'     => (int) ($row['end_time'] ?? 0),
            'days'         => (float) ($row['days'] ?? 0),
            'amount'       => (float) ($row['amount'] ?? 0),
            'coupon_money' => (float) ($row['coupon_money'] ?? 0),
            'score_money'  => (float) ($row['score_money'] ?? 0),
            'pay_amount'   => (float) ($row['pay_amount'] ?? 0),
            'status'       => $status,
            'status_text'  => self::STATUS_TEXT[$status] ?? '未知',
            'pay_time'     => (int) ($row['pay_time'] ?? 0),
            'use_score'    => (int) ($row['use_score'] ?? 0),
            'create_time'  => (int) ($row['createtime'] ?? 0),
        ];
    }
}
