<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;

/**
 * 酒店服务（P6 行业扩展第一期）
 *
 * 表：hotel / hotel_room / hotel_order
 * 订单状态机：0待支付 1已预订 2已入住 3已完成 4已取消 5已退款
 *
 * 【房态规则】available = room_num − 同房型、日期区间重叠
 * （checkin_date < 查询离店 且 checkout_date > 查询入住）、状态 NOT IN(4,5) 的订单数
 *
 * 【计价】amount = nights × room.price；押金 deposit_total = room.deposit
 * （本期押金随单收：pay_amount = 房费优惠后金额 + deposit_total，退款时一并退）
 *
 * 【日期校验】checkout_date > checkin_date、checkin_date 不早于今天
 * 券/积分叠加：券门槛按房费金额，积分抵扣走既有管道，房费实付下限 0.01
 * 支付/回调：pay_order 统一支付单（PayService::payBiz/onPaid 联动）
 */
class HotelService
{
    /** 订单状态 */
    public const STATUS_UNPAID    = 0;
    public const STATUS_BOOKED    = 1;
    public const STATUS_CHECKED   = 2;
    public const STATUS_FINISHED  = 3;
    public const STATUS_CANCELLED = 4;
    public const STATUS_REFUNDED  = 5;

    public const STATUS_TEXT = [
        self::STATUS_UNPAID    => '待支付',
        self::STATUS_BOOKED    => '已预订',
        self::STATUS_CHECKED   => '已入住',
        self::STATUS_FINISHED  => '已完成',
        self::STATUS_CANCELLED => '已取消',
        self::STATUS_REFUNDED  => '已退款',
    ];

    public function __construct(protected CouponService $couponService, protected ScoreService $scoreService)
    {
    }

    /* ================= admin：酒店管理 ================= */

    /**
     * 酒店列表（keyword 匹配名称；status 筛选）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function hotelList(int $aid, string $keyword, string $status, int $page, int $pageSize): array
    {
        $query = ScopeService::query('hotel', $aid)->where('deleted', 0);

        if ('' !== $keyword) {
            $query->where('name', 'like', '%' . $keyword . '%');
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
            'list'     => array_map(fn (array $row) => $this->formatHotel($row), $rows),
            'total'    => $total,
            'page'     => $page,
            'pagesize' => $pageSize,
        ];
    }

    /**
     * 新建酒店
     *
     * @param array<string, mixed> $data
     * @return array{id: int}
     */
    public function hotelAdd(int $aid, array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));

        if ('' === $name) {
            throw new BusinessException('请填写酒店名称');
        }

        $status = (int) ($data['status'] ?? 1);

        $id = (int) Db::name('hotel')->insertGetId([
            'aid'         => $aid,
            'name'        => $name,
            'star'        => $this->parseStar($data['star'] ?? 0),
            'province'    => trim((string) ($data['province'] ?? '')),
            'city'        => trim((string) ($data['city'] ?? '')),
            'district'    => trim((string) ($data['district'] ?? '')),
            'address'     => trim((string) ($data['address'] ?? '')),
            'lng'         => (float) ($data['lng'] ?? 0),
            'lat'         => (float) ($data['lat'] ?? 0),
            'pic'         => trim((string) ($data['pic'] ?? '')),
            'contacts'    => trim((string) ($data['contacts'] ?? '')),
            'phone'       => trim((string) ($data['phone'] ?? '')),
            'description' => trim((string) ($data['desc'] ?? '')),
            'status'      => in_array($status, [0, 1], true) ? $status : 1,
            'deleted'     => 0,
            'createtime'  => time(),
        ]);

        return ['id' => $id];
    }

    /**
     * 编辑酒店
     *
     * @param array<string, mixed> $data
     */
    public function hotelEdit(int $aid, array $data): void
    {
        $hotel  = $this->findHotel($aid, (int) ($data['id'] ?? 0));
        $update = [];

        if (array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);

            if ('' === $name) {
                throw new BusinessException('请填写酒店名称');
            }
            $update['name'] = $name;
        }

        if (array_key_exists('star', $data)) {
            $update['star'] = $this->parseStar($data['star']);
        }

        foreach (['province', 'city', 'district', 'address', 'pic', 'contacts', 'phone'] as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = trim((string) $data[$field]);
            }
        }

        foreach (['lng', 'lat'] as $field) {
            if (array_key_exists($field, $data)) {
                if (!is_numeric($data[$field])) {
                    throw new BusinessException('经纬度格式无效');
                }
                $update[$field] = (float) $data[$field];
            }
        }

        if (array_key_exists('desc', $data)) {
            $update['description'] = trim((string) $data['desc']);
        }

        if (array_key_exists('status', $data)) {
            if (!in_array((int) $data['status'], [0, 1], true)) {
                throw new BusinessException('状态值无效');
            }
            $update['status'] = (int) $data['status'];
        }

        if (!empty($update)) {
            Db::name('hotel')->where('id', (int) $hotel['id'])->update($update);
        }
    }

    /**
     * 删除酒店（软删；有房型拒绝）
     */
    public function hotelDelete(int $aid, int $id): void
    {
        $hotel = $this->findHotel($aid, $id);

        $roomCount = ScopeService::query('hotel_room', $aid)
            ->where('hotel_id', $id)
            ->where('deleted', 0)
            ->count();

        if ($roomCount > 0) {
            throw new BusinessException('该酒店下有房型，不能删除');
        }

        Db::name('hotel')->where('id', (int) $hotel['id'])->update(['deleted' => 1]);
    }

    /**
     * 营业/停业
     */
    public function hotelStatus(int $aid, int $id, int $status): void
    {
        if (!in_array($status, [0, 1], true)) {
            throw new BusinessException('状态值无效');
        }

        $hotel = $this->findHotel($aid, $id);

        Db::name('hotel')->where('id', (int) $hotel['id'])->update(['status' => $status]);
    }

    /* ================= admin：房型管理 ================= */

    /**
     * 房型列表（按酒店）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function roomList(int $aid, int $hotelId, int $page, int $pageSize): array
    {
        if ($hotelId <= 0) {
            throw new BusinessException('缺少酒店ID');
        }

        $this->findHotel($aid, $hotelId);

        $query = ScopeService::query('hotel_room', $aid)
            ->where('hotel_id', $hotelId)
            ->where('deleted', 0);

        $total = (clone $query)->count();

        $rows = $query->order('id', 'asc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        return [
            'list'     => array_map(fn (array $row) => $this->formatRoom($row), $rows),
            'total'    => $total,
            'page'     => $page,
            'pagesize' => $pageSize,
        ];
    }

    /**
     * 新建房型
     *
     * @param array<string, mixed> $data
     * @return array{id: int}
     */
    public function roomAdd(int $aid, array $data): array
    {
        [$hotelId, $name, $price, $deposit, $roomNum, $status] = $this->validateRoomData($aid, $data, true);

        $id = (int) Db::name('hotel_room')->insertGetId([
            'aid'         => $aid,
            'hotel_id'    => $hotelId,
            'name'        => $name,
            'bed_type'    => trim((string) ($data['bed_type'] ?? '')),
            'area'        => (int) ($data['area'] ?? 0),
            'price'       => $price,
            'deposit'     => $deposit,
            'room_num'    => $roomNum,
            'pic'         => trim((string) ($data['pic'] ?? '')),
            'description' => trim((string) ($data['desc'] ?? '')),
            'status'      => $status,
            'deleted'     => 0,
            'create_time' => time(),
        ]);

        return ['id' => $id];
    }

    /**
     * 编辑房型
     *
     * @param array<string, mixed> $data
     */
    public function roomEdit(int $aid, array $data): void
    {
        $room   = $this->findRoom($aid, (int) ($data['id'] ?? 0));
        $update = [];

        if (array_key_exists('hotel_id', $data) || array_key_exists('name', $data)
            || array_key_exists('price', $data) || array_key_exists('deposit', $data)
            || array_key_exists('room_num', $data) || array_key_exists('status', $data)) {
            [$hotelId, $name, $price, $deposit, $roomNum, $status] = $this->validateRoomData($aid, array_merge($room, $data), false);

            $update['hotel_id'] = $hotelId;
            $update['name']     = $name;
            $update['price']    = $price;
            $update['deposit']  = $deposit;
            $update['room_num'] = $roomNum;
            $update['status']   = $status;
        }

        foreach (['bed_type', 'pic'] as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = trim((string) $data[$field]);
            }
        }

        if (array_key_exists('area', $data)) {
            $update['area'] = (int) $data['area'];
        }

        if (array_key_exists('desc', $data)) {
            $update['description'] = trim((string) $data['desc']);
        }

        if (!empty($update)) {
            Db::name('hotel_room')->where('id', (int) $room['id'])->update($update);
        }
    }

    /**
     * 删除房型（软删；有未完结订单拒绝）
     */
    public function roomDelete(int $aid, int $id): void
    {
        $room = $this->findRoom($aid, $id);

        $openCount = Db::name('hotel_order')
            ->where('room_id', $id)
            ->whereIn('status', [self::STATUS_UNPAID, self::STATUS_BOOKED, self::STATUS_CHECKED])
            ->count();

        if ($openCount > 0) {
            throw new BusinessException('该房型有未完结订单，不能删除');
        }

        Db::name('hotel_room')->where('id', (int) $room['id'])->update(['deleted' => 1]);
    }

    /**
     * 上架/下架
     */
    public function roomStatus(int $aid, int $id, int $status): void
    {
        if (!in_array($status, [0, 1], true)) {
            throw new BusinessException('状态值无效');
        }

        $room = $this->findRoom($aid, $id);

        Db::name('hotel_room')->where('id', (int) $room['id'])->update(['status' => $status]);
    }

    /* ================= admin：酒店订单 ================= */

    /**
     * 订单列表（keyword 匹配订单号/入住人/手机号）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function adminOrderList(int $aid, string $status, string $keyword, int $page, int $pageSize): array
    {
        $query = ScopeService::queryAs('hotel_order', $aid, 'o')
            ->leftJoin('member m', 'm.id = o.mid')
            ->field('o.*, m.tel as member_phone');

        if ('' !== $status) {
            $query->where('o.status', (int) $status);
        }

        if ('' !== $keyword) {
            $like = '%' . $keyword . '%';
            $query->where(function ($q) use ($like) {
                $q->where('o.order_sn', 'like', $like)
                    ->whereOr('o.name', 'like', $like)
                    ->whereOr('o.phone', 'like', $like);
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
     * 办理入住（已预订 → 已入住）
     */
    public function adminOrderCheckin(int $aid, int $id): void
    {
        $order = $this->findOrder($aid, 0, $id);

        if (self::STATUS_BOOKED !== (int) $order['status']) {
            throw new BusinessException('当前订单状态不可办理入住（须已预订）');
        }

        Db::name('hotel_order')->where('id', $id)->update(['status' => self::STATUS_CHECKED]);
    }

    /**
     * 完成订单（已预订/已入住 → 已完成）
     */
    public function adminOrderFinish(int $aid, int $id): void
    {
        $order = $this->findOrder($aid, 0, $id);

        if (!in_array((int) $order['status'], [self::STATUS_BOOKED, self::STATUS_CHECKED], true)) {
            throw new BusinessException('当前订单状态不可完成（须已预订或已入住）');
        }

        Db::name('hotel_order')->where('id', $id)->update(['status' => self::STATUS_FINISHED]);
    }

    /* ================= api 会员侧 ================= */

    /**
     * 酒店列表（匿名；star/keyword 筛选，含最低房价 min_price=生效房型最低每晚价）
     *
     * @return array{list: array}
     */
    public function apiList(int $aid, int $star, string $keyword): array
    {
        $query = ScopeService::query('hotel', $aid)
            ->where('status', 1)
            ->where('deleted', 0);

        if ($star > 0) {
            $query->where('star', $star);
        }
        if ('' !== $keyword) {
            $query->where('name', 'like', '%' . $keyword . '%');
        }

        $rows = $query->order('id', 'desc')->select()->toArray();

        if (empty($rows)) {
            return ['list' => []];
        }

        // 各酒店生效房型最低价
        $hotelIds = array_map(static fn (array $r) => (int) $r['id'], $rows);

        $mins = Db::name('hotel_room')
            ->where('aid', $aid)
            ->whereIn('hotel_id', $hotelIds)
            ->where('status', 1)
            ->where('deleted', 0)
            ->field('hotel_id, MIN(price) as min_price')
            ->group('hotel_id')
            ->select()
            ->toArray();

        $minMap = [];

        foreach ($mins as $min) {
            $minMap[(int) $min['hotel_id']] = (float) $min['min_price'];
        }

        $list = [];

        foreach ($rows as $row) {
            $item               = $this->formatHotel($row);
            $item['min_price']  = $minMap[(int) $row['id']] ?? 0.0;
            $list[]             = $item;
        }

        return ['list' => $list];
    }

    /**
     * 酒店详情（匿名；含房型列表）
     *
     * @return array<string, mixed>
     */
    public function apiDetail(int $aid, int $id): array
    {
        $hotel = ScopeService::query('hotel', $aid)
            ->where('id', $id)
            ->where('status', 1)
            ->where('deleted', 0)
            ->find();

        if (empty($hotel)) {
            throw new BusinessException('酒店不存在或已停业', 404, 404);
        }

        $rooms = ScopeService::query('hotel_room', $aid)
            ->where('hotel_id', $id)
            ->where('status', 1)
            ->where('deleted', 0)
            ->order('price', 'asc')
            ->select()
            ->toArray();

        $item        = $this->formatHotel($hotel);
        $item['rooms'] = array_map(fn (array $row) => $this->formatRoom($row), $rooms);

        return $item;
    }

    /**
     * 房态查询（匿名）
     *
     * 房态 = room_num − 日期区间重叠的未取消/未退款订单数
     *
     * @return array{available: int, nights: int, amount: float, deposit: float}
     */
    public function roomAvailable(int $aid, int $roomId, string $checkin, string $checkout): array
    {
        $room = ScopeService::query('hotel_room', $aid)
            ->where('id', $roomId)
            ->where('status', 1)
            ->where('deleted', 0)
            ->find();

        if (empty($room)) {
            throw new BusinessException('房型不存在或已下架', 404, 404);
        }

        [$checkin, $checkout, $nights] = $this->validateDates($checkin, $checkout);

        $busy = Db::name('hotel_order')
            ->where('room_id', $roomId)
            ->whereNotIn('status', [self::STATUS_CANCELLED, self::STATUS_REFUNDED])
            ->where('checkin_date', '<', $checkout)
            ->where('checkout_date', '>', $checkin)
            ->count();

        return [
            'available' => max((int) $room['room_num'] - $busy, 0),
            'nights'    => $nights,
            'amount'    => round($nights * (float) $room['price'], 2),
            'deposit'   => (float) $room['deposit'],
        ];
    }

    /**
     * 创建预订订单
     *
     * 【校验】日期合法 + 事务内行锁房型复检房态（并发安全）
     * 【计价】amount = nights × price；押金随单收 pay_amount = 房费优惠后 + deposit_total
     * 【叠加】券（门槛按房费）→ 积分 → 房费实付下限 0.01
     *
     * @return array{id: int, order_sn: string, nights: int, amount: float, deposit_total: float, coupon_money: float, score_money: float, pay_amount: float, coupon_id: int, use_score: int}
     */
    public function createOrder(int $aid, int $mid, int $roomId, string $checkinDate, string $checkoutDate, string $name, string $phone, int $couponId = 0, int $useScore = 0): array
    {
        if ($roomId <= 0) {
            throw new BusinessException('请选择房型');
        }
        if ('' === trim($name)) {
            throw new BusinessException('请填写入住人姓名');
        }
        if ('' === trim($phone)) {
            throw new BusinessException('请填写联系电话');
        }

        $room = ScopeService::query('hotel_room', $aid)
            ->where('id', $roomId)
            ->where('status', 1)
            ->where('deleted', 0)
            ->find();

        if (empty($room)) {
            throw new BusinessException('房型不存在或已下架', 404, 404);
        }

        $hotel = ScopeService::query('hotel', $aid)
            ->where('id', (int) $room['hotel_id'])
            ->where('status', 1)
            ->where('deleted', 0)
            ->find();

        if (empty($hotel)) {
            throw new BusinessException('酒店已停业，无法预订', 404, 404);
        }

        [$checkinDate, $checkoutDate, $nights] = $this->validateDates($checkinDate, $checkoutDate);

        $amount       = round($nights * (float) $room['price'], 2);
        $depositTotal = (float) $room['deposit'];

        if ($amount <= 0) {
            throw new BusinessException('房型价格未配置');
        }

        // ===== 券（门槛按房费）与积分（既有管道） =====
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

        // 实付 = 房费优惠后（下限 0.01）+ 押金（随单收，退款一并退）
        $payAmount = max(round($afterCoupon - $scoreMoney, 2), 0.01) + $depositTotal;
        $now       = time();

        return Db::transaction(function () use ($aid, $mid, $roomId, $checkinDate, $checkoutDate, $name, $phone, $room, $hotel, $nights, $amount, $depositTotal, $couponPick, $couponMoney, $useScore, $scoreMoney, $payAmount, $now) {
            // 并发房态：行锁房型行 + 事务内复检
            Db::name('hotel_room')->where('id', $roomId)->lock(true)->find();

            $busy = Db::name('hotel_order')
                ->where('room_id', $roomId)
                ->whereNotIn('status', [self::STATUS_CANCELLED, self::STATUS_REFUNDED])
                ->where('checkin_date', '<', $checkoutDate)
                ->where('checkout_date', '>', $checkinDate)
                ->count();

            if ($busy >= (int) $room['room_num']) {
                throw new BusinessException('该日期段房间已满，请选择其他日期');
            }

            $orderId = (int) Db::name('hotel_order')->insertGetId([
                'aid'           => $aid,
                'mid'           => $mid,
                'order_sn'      => '',
                'hotel_id'      => (int) $hotel['id'],
                'hotel_name'    => (string) ($hotel['name'] ?? ''),
                'room_id'       => $roomId,
                'room_name'     => (string) ($room['name'] ?? ''),
                'checkin_date'  => $checkinDate,
                'checkout_date' => $checkoutDate,
                'nights'        => $nights,
                'amount'        => $amount,
                'deposit_total' => $depositTotal,
                'coupon_money'  => $couponMoney,
                'score_money'   => $scoreMoney,
                'pay_amount'    => $payAmount,
                'status'        => self::STATUS_UNPAID,
                'name'          => trim($name),
                'phone'         => trim($phone),
                'pay_time'      => 0,
                'coupon_id'     => null !== $couponPick ? (int) $couponPick['coupon_id'] : 0,
                'coupon_rid'    => null !== $couponPick ? (int) $couponPick['record_id'] : 0,
                'use_score'     => $useScore,
                'createtime'    => $now,
                'deleted'       => 0,
            ]);

            $orderSn = 'HT' . date('YmdHis', $now)
                . str_pad((string) ($aid % 10000), 4, '0', STR_PAD_LEFT)
                . str_pad((string) ($orderId % 1000000), 6, '0', STR_PAD_LEFT);

            Db::name('hotel_order')->where('id', $orderId)->update(['order_sn' => $orderSn]);

            // 用券预锁定（行锁抢占，失败回滚整单）
            if (null !== $couponPick) {
                $this->couponService->bindToOrder((int) $couponPick['record_id'], $orderId, 'hotel');
            }

            // 积分预扣（取消时原数返还）
            if ($useScore > 0) {
                $this->scoreService->deductForOrder($aid, $mid, $useScore, '下单抵扣 酒店订单' . $orderSn);
            }

            return [
                'id'            => $orderId,
                'order_sn'      => $orderSn,
                'nights'        => $nights,
                'amount'        => $amount,
                'deposit_total' => $depositTotal,
                'coupon_id'     => null !== $couponPick ? (int) $couponPick['coupon_id'] : 0,
                'coupon_money'  => $couponMoney,
                'use_score'     => $useScore,
                'score_money'   => $scoreMoney,
                'pay_amount'    => $payAmount,
            ];
        });
    }

    /**
     * 我的酒店订单列表
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function apiOrderList(int $aid, int $mid, string $status, int $page, int $pageSize): array
    {
        $query = ScopeService::query('hotel_order', $aid)
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
        $ids = Db::name('hotel_order')
            ->where('status', self::STATUS_UNPAID)
            ->where('createtime', '<', time() - PayService::payTimeout())
            ->where('createtime', '>', 0)
            ->order('id', 'asc')
            ->limit(500)
            ->column('id');

        $count = 0;

        foreach ($ids as $id) {
            $order = Db::name('hotel_order')->where('id', (int) $id)->find();

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
            $affected = Db::name('hotel_order')
                ->where('id', $orderId)
                ->where('status', self::STATUS_UNPAID)
                ->update(['status' => self::STATUS_CANCELLED]);

            if (1 !== $affected) {
                return false; // 并发已变更：幂等跳过
            }

            $this->couponService->releaseByOrder((int) ($order['coupon_rid'] ?? 0), $orderId);

            if ((int) ($order['use_score'] ?? 0) > 0) {
                $this->scoreService->refundForCancel(
                    (int) ($order['aid'] ?? 0),
                    (int) ($order['mid'] ?? 0),
                    (int) $order['use_score'],
                    '取消订单返还 酒店订单' . (string) ($order['order_sn'] ?? '')
                );
            }

            return true;
        });
    }

    /**
     * 申请退款（仅已预订且入住日未到；退实付全额 pay_amount，含押金；券/积分不回收）
     *
     * @return array{mock: bool, success: bool}
     */
    public function refundOrder(int $aid, int $mid, int $orderId): array
    {
        $order = $this->findOrder($aid, $mid, $orderId);

        if (self::STATUS_BOOKED !== (int) $order['status']) {
            throw new BusinessException('仅已预订订单可退款');
        }

        $today = date('Y-m-d');

        if ((string) ($order['checkin_date'] ?? '') <= $today) {
            throw new BusinessException('入住日已到，不可退款');
        }

        // 退款金额 = 实付全额（pay_amount 已含随单收押金 deposit_total）
        $result = PayService::refundBiz(
            'hotel',
            $orderId,
            (float) $order['pay_amount'],
            'RF' . date('YmdHis') . str_pad((string) ($orderId % 1000000), 6, '0', STR_PAD_LEFT)
        );

        Db::name('hotel_order')
            ->where('id', $orderId)
            ->where('status', self::STATUS_BOOKED)
            ->update(['status' => self::STATUS_REFUNDED]);

        return $result;
    }

    /**
     * 支付成功入账（PayService 回调路由调用，幂等：仅 status=0 时生效）
     */
    public function onPaid(int $orderId, string $payType, string $payNum): bool
    {
        return (bool) Db::transaction(function () use ($orderId, $payType) {
            $order = Db::name('hotel_order')->where('id', $orderId)->lock(true)->find();

            if (empty($order) || self::STATUS_UNPAID !== (int) $order['status']) {
                return false; // 已支付/已取消等：幂等不重复处理
            }

            Db::name('hotel_order')
                ->where('id', $orderId)
                ->where('status', self::STATUS_UNPAID)
                ->update(['status' => self::STATUS_BOOKED, 'pay_time' => time()]);

            // 券核销（幂等）
            $this->couponService->consumeByOrder((int) ($order['coupon_rid'] ?? 0), $orderId);

            // 积分赠送：pay_score × floor(实付)
            $this->scoreService->grantForPay(
                (int) ($order['aid'] ?? 0),
                (int) ($order['mid'] ?? 0),
                (float) ($order['pay_amount'] ?? 0),
                '支付赠送 酒店订单' . (string) ($order['order_sn'] ?? '')
            );

            return true;
        });
    }

    /* ================= 内部工具 ================= */

    /**
     * 日期校验与晚数计算
     *
     * 格式 Y-m-d；checkout > checkin；checkin 不早于今天
     *
     * @return array{0: string, 1: string, 2: int}
     */
    protected function validateDates(string $checkin, string $checkout): array
    {
        $checkin  = trim($checkin);
        $checkout = trim($checkout);

        $inTs  = $this->parseDate($checkin, '入住日期');
        $outTs = $this->parseDate($checkout, '离店日期');

        if ($outTs <= $inTs) {
            throw new BusinessException('离店日期必须晚于入住日期');
        }

        $todayTs = strtotime(date('Y-m-d'));

        if ($inTs < $todayTs) {
            throw new BusinessException('入住日期不能早于今天');
        }

        return [$checkin, $checkout, (int) (($outTs - $inTs) / 86400)];
    }

    /**
     * 解析 Y-m-d 日期为当日 0 点时间戳（无效抛异常）
     */
    protected function parseDate(string $date, string $label): int
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new BusinessException($label . '格式无效（须为 Y-m-d）');
        }

        $ts = strtotime($date);

        if (false === $ts) {
            throw new BusinessException($label . '无效');
        }

        return $ts;
    }

    /**
     * 星级解析（0~5 整数）
     */
    protected function parseStar(mixed $star): int
    {
        $star = (int) $star;

        if ($star < 0 || $star > 5) {
            throw new BusinessException('星级须在 0~5 之间');
        }

        return $star;
    }

    /**
     * 房型数据校验（新增必填；编辑合并原值）
     *
     * @param array<string, mixed> $data
     * @return array{0: int, 1: string, 2: float, 3: float, 4: int, 5: int}
     */
    protected function validateRoomData(int $aid, array $data, bool $isAdd): array
    {
        $hotelId = (int) ($data['hotel_id'] ?? 0);

        $this->findHotel($aid, $hotelId);

        $name = trim((string) ($data['name'] ?? ''));

        if ('' === $name) {
            throw new BusinessException('请填写房型名称');
        }

        $price = $this->parsePrice($data['price'] ?? null, '每晚房价');

        if ($isAdd && $price <= 0) {
            throw new BusinessException('请填写每晚房价（大于0）');
        }

        $deposit = $this->parsePrice($data['deposit'] ?? null, '押金');

        $roomNum = (int) ($data['room_num'] ?? 0);

        if ($roomNum <= 0) {
            throw new BusinessException('房间数量必须大于0');
        }

        $status = (int) ($data['status'] ?? 1);

        if (!in_array($status, [0, 1], true)) {
            throw new BusinessException('状态值无效');
        }

        return [$hotelId, $name, $price, $deposit, $roomNum, $status];
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
     * 查找本租户未删除的酒店（不存在抛 404）
     *
     * @return array<string, mixed>
     */
    protected function findHotel(int $aid, int $id): array
    {
        if ($id <= 0) {
            throw new BusinessException('缺少酒店ID');
        }

        $hotel = ScopeService::query('hotel', $aid)
            ->where('id', $id)
            ->where('deleted', 0)
            ->find();

        if (empty($hotel)) {
            throw new BusinessException('酒店不存在', 404, 404);
        }

        return $hotel;
    }

    /**
     * 查找本租户未删除的房型（不存在抛 404）
     *
     * @return array<string, mixed>
     */
    protected function findRoom(int $aid, int $id): array
    {
        if ($id <= 0) {
            throw new BusinessException('缺少房型ID');
        }

        $room = ScopeService::query('hotel_room', $aid)
            ->where('id', $id)
            ->where('deleted', 0)
            ->find();

        if (empty($room)) {
            throw new BusinessException('房型不存在', 404, 404);
        }

        return $room;
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

        $query = ScopeService::query('hotel_order', $aid)
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
     * 酒店行格式化（契约键 desc ← 列 description）
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function formatHotel(array $row): array
    {
        return [
            'id'          => (int) ($row['id'] ?? 0),
            'name'        => (string) ($row['name'] ?? ''),
            'star'        => (int) ($row['star'] ?? 0),
            'province'    => (string) ($row['province'] ?? ''),
            'city'        => (string) ($row['city'] ?? ''),
            'district'    => (string) ($row['district'] ?? ''),
            'address'     => (string) ($row['address'] ?? ''),
            'lng'         => (float) ($row['lng'] ?? 0),
            'lat'         => (float) ($row['lat'] ?? 0),
            'pic'         => (string) ($row['pic'] ?? ''),
            'contacts'    => (string) ($row['contacts'] ?? ''),
            'phone'       => (string) ($row['phone'] ?? ''),
            'desc'        => (string) ($row['description'] ?? ''),
            'status'      => (int) ($row['status'] ?? 1),
            'create_time' => (int) ($row['createtime'] ?? 0),
        ];
    }

    /**
     * 房型行格式化
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function formatRoom(array $row): array
    {
        return [
            'id'       => (int) ($row['id'] ?? 0),
            'hotel_id' => (int) ($row['hotel_id'] ?? 0),
            'name'     => (string) ($row['name'] ?? ''),
            'bed_type' => (string) ($row['bed_type'] ?? ''),
            'area'     => (int) ($row['area'] ?? 0),
            'price'    => (float) ($row['price'] ?? 0),
            'deposit'  => (float) ($row['deposit'] ?? 0),
            'room_num' => (int) ($row['room_num'] ?? 0),
            'pic'      => (string) ($row['pic'] ?? ''),
            'desc'     => (string) ($row['description'] ?? ''),
            'status'   => (int) ($row['status'] ?? 1),
            'create_time' => (int) ($row['create_time'] ?? 0),
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
            'id'            => (int) ($row['id'] ?? 0),
            'order_sn'      => (string) ($row['order_sn'] ?? ''),
            'mid'           => (int) ($row['mid'] ?? 0),
            'member_phone'  => (string) ($row['member_phone'] ?? ''),
            'hotel_id'      => (int) ($row['hotel_id'] ?? 0),
            'hotel_name'    => (string) ($row['hotel_name'] ?? ''),
            'room_id'       => (int) ($row['room_id'] ?? 0),
            'room_name'     => (string) ($row['room_name'] ?? ''),
            'checkin_date'  => (string) ($row['checkin_date'] ?? ''),
            'checkout_date' => (string) ($row['checkout_date'] ?? ''),
            'nights'        => (int) ($row['nights'] ?? 0),
            'amount'        => (float) ($row['amount'] ?? 0),
            'deposit_total' => (float) ($row['deposit_total'] ?? 0),
            'coupon_money'  => (float) ($row['coupon_money'] ?? 0),
            'score_money'   => (float) ($row['score_money'] ?? 0),
            'pay_amount'    => (float) ($row['pay_amount'] ?? 0),
            'status'        => $status,
            'status_text'   => self::STATUS_TEXT[$status] ?? '未知',
            'name'          => (string) ($row['name'] ?? ''),
            'phone'         => (string) ($row['phone'] ?? ''),
            'pay_time'      => (int) ($row['pay_time'] ?? 0),
            'use_score'     => (int) ($row['use_score'] ?? 0),
            'create_time'   => (int) ($row['createtime'] ?? 0),
        ];
    }
}
