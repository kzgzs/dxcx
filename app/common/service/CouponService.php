<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;

/**
 * 优惠券服务（P5 营销第一期）
 *
 * 表口径（基线复用 + 补列）：
 * - coupon        券主表：name/type(1满减 2折扣)/money(抵扣额)/discount(折扣值 1~9.9)/
 *                 minprice=门槛full_money/stock=发行总量total/getnum=已领received/
 *                 used=已使用/perlimit=每人限领per_limit/starttime,endtime(varchar Y-m-d H:i:s)/
 *                 status(1启用 0停用)/deleted(软删)/createtime(int)
 * - coupon_record 领取记录：couponid/couponname/money/discount/minprice/type 快照、
 *                 starttime/endtime(int 时间戳快照)/usetime/receive_time/createtime/
 *                 status(0未使用 1已使用，过期=按 endtime 实时计算显示 2)/order_id(下单预锁定)
 *
 * 订单联动：下单预锁定 record.order_id → 支付核销（status=1/usetime/coupon.used+1，幂等）
 *           → 取消/超时释放（回未使用或清 order_id，幂等）
 */
class CouponService
{
    /** 券类型 */
    public const TYPE_FULL_CUT = 1; // 满减券（参考系统口径=代金券）
    public const TYPE_DISCOUNT = 2; // 折扣券（参考系统口径 type=10 亦按折扣计价）

    /** 记录状态（展示口径：2=已过期，实时计算不落库） */
    public const RECORD_UNUSED  = 0;
    public const RECORD_USED    = 1;
    public const RECORD_EXPIRED = 2;

    /** received>0 后禁止修改的核心面值字段（契约键名 => 表列） */
    protected const CORE_FIELDS = [
        'name'       => 'name',
        'type'       => 'type',
        'money'      => 'money',
        'discount'   => 'discount',
        'full_money' => 'minprice',
    ];

    /* ================= admin 租户后台侧 ================= */

    /**
     * 券列表（status 空=全部；keyword 模糊匹配名称）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function adminList(int $aid, string $status, string $keyword, int $page, int $pageSize): array
    {
        $query = ScopeService::query('coupon', $aid)->where('deleted', 0);

        if ('' !== $status) {
            $query->where('status', (int) $status);
        }

        if ('' !== $keyword) {
            $query->where('name', 'like', '%' . $keyword . '%');
        }

        $total = (clone $query)->count();

        $rows = $query->order('id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        return [
            'list'     => array_map(fn (array $row) => $this->formatCoupon($row), $rows),
            'total'    => $total,
            'page'     => $page,
            'pagesize' => $pageSize,
        ];
    }

    /**
     * 新建优惠券（校验见任务契约：type 分支必填、end_time>start_time、金额两位小数）
     *
     * @param array<string, mixed> $data
     * @return array{id: int}
     */
    public function add(int $aid, array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));

        if ('' === $name) {
            throw new BusinessException('请填写优惠券名称');
        }

        $type = (int) ($data['type'] ?? 0);

        if (!in_array($type, [self::TYPE_FULL_CUT, self::TYPE_DISCOUNT], true)) {
            throw new BusinessException('优惠券类型无效');
        }

        $money     = $this->parseAmount($data['money'] ?? null, '抵扣金额');
        $discount  = $data['discount'] ?? null;
        $fullMoney = $this->parseAmount($data['full_money'] ?? null, '门槛金额');

        if (self::TYPE_FULL_CUT === $type) {
            if (null === $money || $money <= 0) {
                throw new BusinessException('满减券必须填写抵扣金额且大于0');
            }
            if (null === $fullMoney) {
                throw new BusinessException('满减券必须填写使用门槛金额');
            }
            $discount = 0.0;
        } else {
            if (!is_numeric($discount) || (float) $discount < 1 || (float) $discount > 9.9) {
                throw new BusinessException('折扣券折扣值须在 1~9.9 之间');
            }
            $discount = (float) $discount;
            $money    = 0.0;

            if (null === $fullMoney) {
                $fullMoney = 0.0;
            }
        }

        $total = (int) ($data['total'] ?? 0);

        if ($total <= 0) {
            throw new BusinessException('发行总量必须大于0');
        }

        $perLimit = (int) ($data['per_limit'] ?? 0);

        if ($perLimit <= 0) {
            throw new BusinessException('每人限领数量必须大于0');
        }

        [$startTime, $endTime] = $this->parseTimes($data['start_time'] ?? '', $data['end_time'] ?? '');

        $id = (int) Db::name('coupon')->insertGetId([
            'aid'        => $aid,
            'bid'        => 0,
            'name'       => $name,
            'type'       => $type,
            'money'      => $money,
            'discount'   => $discount,
            'minprice'   => $fullMoney,
            'stock'      => $total,
            'getnum'     => 0,
            'used'       => 0,
            'perlimit'   => $perLimit,
            'starttime'  => $startTime,
            'endtime'    => $endTime,
            'status'     => 1,
            'deleted'    => 0,
            'createtime' => time(),
        ]);

        return ['id' => $id];
    }

    /**
     * 编辑优惠券
     *
     * received>0 时核心面值字段（name/type/money/discount/full_money）禁止修改，
     * 仅可改 total/per_limit/时间/status；容忍前端全量原值提交（present 且不同才拒）
     *
     * @param array<string, mixed> $data
     */
    public function edit(int $aid, array $data): void
    {
        $coupon = $this->findCoupon($aid, (int) ($data['id'] ?? 0));

        $received   = (int) ($coupon['getnum'] ?? 0);
        $coreLocked = $received > 0;
        $update     = [];
        $coreDirty  = false;

        foreach (self::CORE_FIELDS as $key => $column) {
            if (!array_key_exists($key, $data)) {
                continue;
            }

            $current = $coupon[$column] ?? null;
            $changed = 'name' === $key
                ? trim((string) $data[$key]) !== (string) $current
                : abs((float) $data[$key] - (float) $current) > 0.0001;

            if (!$changed) {
                continue; // 原值回传：容忍
            }

            if ($coreLocked) {
                throw new BusinessException('该优惠券已被领取，禁止修改核心面值字段（名称/类型/金额/折扣/门槛）');
            }

            $update[$column] = 'name' === $key ? trim((string) $data[$key]) : (float) $data[$key];
            $coreDirty       = true;
        }

        // 可选字段：任何时候可改
        if (array_key_exists('total', $data)) {
            $total = (int) $data['total'];

            if ($total <= 0) {
                throw new BusinessException('发行总量必须大于0');
            }
            $update['stock'] = $total;
        }

        if (array_key_exists('per_limit', $data)) {
            $perLimit = (int) $data['per_limit'];

            if ($perLimit <= 0) {
                throw new BusinessException('每人限领数量必须大于0');
            }
            $update['perlimit'] = $perLimit;
        }

        if (array_key_exists('status', $data)) {
            $status = (int) $data['status'];

            if (!in_array($status, [0, 1], true)) {
                throw new BusinessException('状态值无效');
            }
            $update['status'] = $status;
        }

        $startTime = array_key_exists('start_time', $data) ? $data['start_time'] : ($coupon['starttime'] ?? '');
        $endTime   = array_key_exists('end_time', $data) ? $data['end_time'] : ($coupon['endtime'] ?? '');

        if (array_key_exists('start_time', $data) || array_key_exists('end_time', $data)) {
            [$startTime, $endTime] = $this->parseTimes($startTime, $endTime);
            $update['starttime']   = $startTime;
            $update['endtime']     = $endTime;
        }

        // 核心字段变动时按新类型复验面值参数
        if ($coreDirty) {
            $merged = array_merge($coupon, $update);
            $type   = (int) ($merged['type'] ?? 0);

            if (!in_array($type, [self::TYPE_FULL_CUT, self::TYPE_DISCOUNT], true)) {
                throw new BusinessException('优惠券类型无效');
            }

            if (self::TYPE_FULL_CUT === $type && (float) ($merged['money'] ?? 0) <= 0) {
                throw new BusinessException('满减券抵扣金额必须大于0');
            }

            if (self::TYPE_DISCOUNT === $type) {
                $discount = (float) ($merged['discount'] ?? 0);

                if ($discount < 1 || $discount > 9.9) {
                    throw new BusinessException('折扣券折扣值须在 1~9.9 之间');
                }
            }
        }

        if (empty($update)) {
            return;
        }

        Db::name('coupon')->where('id', (int) $coupon['id'])->update($update);
    }

    /**
     * 启用/停用
     */
    public function status(int $aid, int $id, int $status): void
    {
        if (!in_array($status, [0, 1], true)) {
            throw new BusinessException('状态值无效');
        }

        $coupon = $this->findCoupon($aid, $id);

        Db::name('coupon')->where('id', (int) $coupon['id'])->update(['status' => $status]);
    }

    /**
     * 软删除（received>0 拒绝）
     */
    public function delete(int $aid, int $id): void
    {
        $coupon = $this->findCoupon($aid, $id);

        if ((int) ($coupon['getnum'] ?? 0) > 0) {
            throw new BusinessException('该优惠券已被领取，不能删除');
        }

        Db::name('coupon')->where('id', (int) $coupon['id'])->update(['deleted' => 1]);
    }

    /**
     * 领取/使用记录（status 空=全部 0未使用 1已使用 2已过期；过期按 endtime 实时计算）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function adminRecords(int $aid, int $couponId, string $status, int $page, int $pageSize): array
    {
        $now   = time();
        $query = ScopeService::queryAs('coupon_record', $aid, 'r')
            ->leftJoin('member m', 'm.id = r.mid')
            ->leftJoin('shop_order o', 'o.id = r.order_id')
            ->leftJoin('carhailing_order c', 'c.id = r.order_id')
            ->leftJoin('hotel_order h', 'h.id = r.order_id')
            ->leftJoin('yuyue_order y', 'y.id = r.order_id')
            ->leftJoin('restaurant_shop_order rs', 'rs.id = r.order_id')
            ->field('r.*, m.tel as member_phone, COALESCE(o.ordernum, c.order_sn, h.order_sn, y.ordernum, rs.ordernum) as order_sn');

        if ($couponId > 0) {
            $query->where('r.couponid', $couponId);
        }

        $this->applyRecordStatusFilter($query, $status, $now, 'r');

        $total = (clone $query)->count();

        $rows = $query->order('r.id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        return [
            'list'     => array_map(fn (array $row) => $this->formatRecord($row, $now), $rows),
            'total'    => $total,
            'page'     => $page,
            'pagesize' => $pageSize,
        ];
    }

    /* ================= api 会员侧 ================= */

    /**
     * 可领取券列表（启用、有效期内、有余量、未达本人限领）
     *
     * @return array{list: array}
     */
    public function apiList(int $aid, int $mid): array
    {
        $now  = time();
        $rows = ScopeService::query('coupon', $aid)
            ->where('status', 1)
            ->where('deleted', 0)
            ->select()
            ->toArray();

        $available = [];

        foreach ($rows as $row) {
            $start = $this->stampOf($row['starttime'] ?? '');
            $end   = $this->stampOf($row['endtime'] ?? '');

            if ($start > 0 && $now < $start) {
                continue; // 未开始
            }
            if ($end > 0 && $now > $end) {
                continue; // 已结束
            }
            if ((int) ($row['getnum'] ?? 0) >= (int) ($row['stock'] ?? 0)) {
                continue; // 无余量
            }

            $available[] = $row;
        }

        if (empty($available)) {
            return ['list' => []];
        }

        // 本人已领数量（含过期未用：领取事实不回收）
        $ids    = array_map(static fn (array $r) => (int) $r['id'], $available);
        $counts = Db::name('coupon_record')
            ->where('aid', $aid)
            ->where('mid', $mid)
            ->whereIn('couponid', $ids)
            ->field('couponid, count(*) as cnt')
            ->group('couponid')
            ->select()
            ->toArray();

        $countMap = [];

        foreach ($counts as $count) {
            $countMap[(int) $count['couponid']] = (int) $count['cnt'];
        }

        $list = [];

        foreach ($available as $row) {
            $perLimit = (int) ($row['perlimit'] ?? 1);
            $mine     = $countMap[(int) $row['id']] ?? 0;

            if ($mine >= $perLimit) {
                continue; // 已达本人限领
            }

            $list[] = $this->formatCoupon($row);
        }

        return ['list' => $list];
    }

    /**
     * 优惠券详情（含当前用户领取记录）
     */
    public function detail(int $aid, int $mid, int $couponId): array
    {
        if ($couponId <= 0) {
            throw new BusinessException('缺少优惠券ID');
        }

        $coupon = ScopeService::query('coupon', $aid)
            ->where('id', $couponId)
            ->where('deleted', 0)
            ->find();

        if (!$coupon) {
            throw new BusinessException('优惠券不存在');
        }

        $coupon = $coupon->toArray();

        $typeMap = [1 => '代金券', 2 => '礼品券', 3 => '计次券', 4 => '运费抵扣券', 5 => '餐饮券', 10 => '折扣券', 11 => '兑换券', 20 => '券包'];
        $coupon['type_txt'] = $typeMap[(int)($coupon['type'] ?? 1)] ?? '优惠券';

        $record = Db::name('coupon_record')
            ->where('aid', $aid)
            ->where('mid', $mid)
            ->where('couponid', $couponId)
            ->order('id desc')
            ->find();

        $recordData = [];
        if ($record) {
            $record = $record->toArray();
            $now = time();
            $status = (int)($record['status'] ?? 0);
            $end = $this->stampOf($record['endtime'] ?? '');
            if ($status === 0 && $end > 0 && $now > $end) {
                $status = 2;
            }
            $recordData = [
                'id' => (int)($record['id'] ?? 0),
                'couponid' => (int)($record['couponid'] ?? 0),
                'mid' => (int)($record['mid'] ?? 0),
                'type' => (int)($coupon['type'] ?? 1),
                'type_txt' => $coupon['type_txt'],
                'name' => (string)($coupon['name'] ?? ''),
                'money' => (float)($coupon['money'] ?? 0),
                'discount' => (float)($coupon['discount'] ?? 0),
                'minprice' => (float)($coupon['minprice'] ?? 0),
                'limit_count' => (int)($coupon['limit_count'] ?? 0),
                'status' => $status,
                'createtime' => date('Y-m-d H:i:s', (int)($record['createtime'] ?? time())),
                'usetime' => $record['usetime'] ? date('Y-m-d H:i:s', (int)$record['usetime']) : '',
                'starttime' => $coupon['starttime'] ?? '',
                'endtime' => $coupon['endtime'] ?? '',
            ];
        }

        return ['coupon' => $coupon, 'record' => $recordData];
    }

    /**
     * 领取优惠券（并发安全：行锁 + per_limit 校验 + getnum<stock 条件更新防超发）
     *
     * @return array{id: int} 领取记录ID
     */
    public function receive(int $aid, int $mid, int $couponId): array
    {
        if ($couponId <= 0) {
            throw new BusinessException('缺少优惠券ID');
        }

        return Db::transaction(function () use ($aid, $mid, $couponId) {
            $coupon = Db::name('coupon')
                ->where('id', $couponId)
                ->where('aid', $aid)
                ->lock(true)
                ->find();

            if (empty($coupon) || 1 === (int) ($coupon['deleted'] ?? 0)) {
                throw new BusinessException('优惠券不存在', 404, 404);
            }
            if (1 !== (int) ($coupon['status'] ?? 0)) {
                throw new BusinessException('优惠券已停用');
            }

            $now   = time();
            $start = $this->stampOf($coupon['starttime'] ?? '');
            $end   = $this->stampOf($coupon['endtime'] ?? '');

            // 有效期模式：yxqtype=2 领取后 N 天有效（参考系统口径兼容）
            if (2 === (int) ($coupon['yxqtype'] ?? 0)) {
                $days = (int) ($coupon['yxqdate'] ?? 0);

                if ($days <= 0) {
                    throw new BusinessException('优惠券有效期配置无效');
                }

                $start = $now;
                $end   = $now + $days * 86400;
            }

            if ($start > 0 && $now < $start) {
                throw new BusinessException('优惠券领取尚未开始');
            }
            if ($end > 0 && $now > $end) {
                throw new BusinessException('优惠券领取已结束');
            }

            $mine = (int) Db::name('coupon_record')
                ->where('aid', $aid)
                ->where('mid', $mid)
                ->where('couponid', $couponId)
                ->count();

            if ($mine >= (int) ($coupon['perlimit'] ?? 1)) {
                throw new BusinessException('已达每人限领数量');
            }

            // 条件更新防超发：并发下只有一个请求能扣量成功
            $affected = Db::name('coupon')
                ->where('id', $couponId)
                ->whereRaw('getnum < stock')
                ->inc('getnum', 1)
                ->update();

            if (1 !== $affected) {
                throw new BusinessException('优惠券已被领完');
            }

            $recordId = (int) Db::name('coupon_record')->insertGetId([
                'aid'          => $aid,
                'bid'          => 0,
                'mid'          => $mid,
                'couponid'     => $couponId,
                'couponname'   => (string) ($coupon['name'] ?? ''),
                'money'        => (float) ($coupon['money'] ?? 0),
                'discount'     => (float) ($coupon['discount'] ?? 0),
                'minprice'     => (float) ($coupon['minprice'] ?? 0),
                'type'         => (int) ($coupon['type'] ?? 1),
                'starttime'    => $start,
                'endtime'      => $end,
                'status'       => self::RECORD_UNUSED,
                'order_id'     => 0,
                'usetime'      => 0,
                'receive_time' => $now,
                'createtime'   => $now,
            ]);

            return ['id' => $recordId];
        });
    }

    /**
     * 我的优惠券（status 空=全部 0未使用 1已使用 2已过期；过期按 endtime 实时计算）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function mine(int $aid, int $mid, string $status, int $page, int $pageSize): array
    {
        $now   = time();
        $query = ScopeService::query('coupon_record', $aid)->where('mid', $mid);

        $this->applyRecordStatusFilter($query, $status, $now);

        $total = (clone $query)->count();

        $rows = $query->order('id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        $list = [];

        foreach ($rows as $row) {
            $item = $this->formatRecord($row, $now);

            $item['coupon_id']  = (int) ($row['couponid'] ?? 0);
            $item['name']       = (string) ($row['couponname'] ?? '');
            $item['type']       = (int) ($row['type'] ?? 1);
            $item['money']      = (float) ($row['money'] ?? 0);
            $item['discount']   = (float) ($row['discount'] ?? 0);
            $item['full_money'] = (float) ($row['minprice'] ?? 0);
            $item['start_time'] = (int) ($row['starttime'] ?? 0);
            $item['end_time']   = (int) ($row['endtime'] ?? 0);

            $list[] = $item;
        }

        return [
            'list'     => $list,
            'total'    => $total,
            'page'     => $page,
            'pagesize' => $pageSize,
        ];
    }

    /* ================= 订单计价联动 ================= */

    /**
     * 下单选券校验与计价（事务外预校验，友好报错；事务内再由 bindToOrder 行锁抢占）
     *
     * 校验：归属本人、未使用、有效期内、未被未支付订单占用、满足门槛（以商品金额计）
     * 计价：type1/4 减 money；type2/10 乘 discount/10；优惠后金额不低于 0.01
     *
     * @return array{record_id: int, coupon_id: int, coupon_money: float, pay_amount: float}
     */
    public function resolveForOrder(int $aid, int $mid, int $couponId, float $productPrice): array
    {
        $coupon = Db::name('coupon')
            ->where('id', $couponId)
            ->where('aid', $aid)
            ->find();

        if (empty($coupon) || 1 === (int) ($coupon['deleted'] ?? 0)) {
            throw new BusinessException('优惠券不存在', 404, 404);
        }

        // 取本人该券最新可用记录（未使用、未过期、未被未支付订单占用）
        $records = ScopeService::query('coupon_record', $aid)
            ->where('mid', $mid)
            ->where('couponid', $couponId)
            ->where('status', self::RECORD_UNUSED)
            ->order('id', 'asc')
            ->select()
            ->toArray();

        $now  = time();
        $pick = null;

        foreach ($records as $record) {
            $end = (int) ($record['endtime'] ?? 0);

            if ($end > 0 && $now > $end) {
                continue; // 已过期
            }

            $boundOrderId = (int) ($record['order_id'] ?? 0);

            if ($boundOrderId > 0 && $this->orderOccupied((string) ($record['order_type'] ?? 'shop'), $boundOrderId)) {
                continue; // 被未支付订单占用
            }

            $pick = $record;
            break;
        }

        if (null === $pick) {
            throw new BusinessException('没有可用的该优惠券（未领取、已使用、已过期或被占用）');
        }

        // 门槛校验（以商品金额计）
        $minPrice = (float) ($pick['minprice'] ?? 0);

        if ($minPrice > 0 && $productPrice < $minPrice) {
            throw new BusinessException('订单商品金额未满足优惠券使用门槛（满 ' . rtrim(rtrim(number_format($minPrice, 2, '.', ''), '0'), '.') . ' 元可用）');
        }

        // 计价（参考系统口径折扣券 type=10 与本项目 type=2 同价）
        $type = (int) ($pick['type'] ?? 1);

        if (in_array($type, [self::TYPE_DISCOUNT, 10], true)) {
            $discount = (float) ($pick['discount'] ?? 10);
            $pay      = round($productPrice * $discount / 10, 2);
        } else {
            $pay = round($productPrice - (float) ($pick['money'] ?? 0), 2);
        }

        $pay         = max($pay, 0.01); // 优惠后金额不低于 0.01
        $couponMoney = round($productPrice - $pay, 2);

        return [
            'record_id'    => (int) $pick['id'],
            'coupon_id'    => $couponId,
            'coupon_money' => $couponMoney,
            'pay_amount'   => $pay,
        ];
    }

    /**
     * 下单预锁定（事务内调用）：行锁抢占 record，写 order_id/order_type
     *
     * 并发下同一券只能绑定一个订单，失败方抛异常触发下单事务回滚
     *
     * @param string $orderType 占用订单类型 shop/carhailing/hotel/yuyue/restaurant（五类订单 ID 空间独立）
     */
    public function bindToOrder(int $recordId, int $orderId, string $orderType = 'shop'): void
    {
        $record = Db::name('coupon_record')->where('id', $recordId)->lock(true)->find();

        if (empty($record) || self::RECORD_UNUSED !== (int) ($record['status'] ?? -1)) {
            throw new BusinessException('优惠券状态已变化，请重新选择');
        }

        $end = (int) ($record['endtime'] ?? 0);

        if ($end > 0 && time() > $end) {
            throw new BusinessException('优惠券已过期');
        }

        $boundOrderId = (int) ($record['order_id'] ?? 0);

        if ($boundOrderId > 0 && $boundOrderId !== $orderId
            && $this->orderOccupied((string) ($record['order_type'] ?? 'shop'), $boundOrderId)) {
            throw new BusinessException('该优惠券已被其他未支付订单占用');
        }

        Db::name('coupon_record')->where('id', $recordId)->update([
            'order_id'   => $orderId,
            'order_type' => $orderType,
        ]);
    }

    /**
     * 订单占用判定：按订单类型查对应订单表，status=0（待支付）视为占用
     */
    protected function orderOccupied(string $orderType, int $orderId): bool
    {
        $table = match ($orderType) {
            'carhailing' => 'carhailing_order',
            'hotel'      => 'hotel_order',
            'yuyue'      => 'yuyue_order',
            'restaurant' => 'restaurant_shop_order',
            default      => 'shop_order',
        };

        $status = Db::name($table)->where('id', $orderId)->value('status');

        return null !== $status && 0 === (int) $status;
    }

    /**
     * 支付成功核销（handlePaySuccess 事务内调用，幂等：仅 status=0 时核销）
     */
    public function consumeByOrder(int $recordId, int $orderId): void
    {
        if ($recordId <= 0) {
            return;
        }

        $record = Db::name('coupon_record')->where('id', $recordId)->lock(true)->find();

        if (empty($record) || self::RECORD_UNUSED !== (int) ($record['status'] ?? -1)) {
            return; // 已核销或不存在：幂等跳过
        }

        Db::name('coupon_record')->where('id', $recordId)->where('status', self::RECORD_UNUSED)->update([
            'status'   => self::RECORD_USED,
            'usetime'  => time(),
            'order_id' => $orderId,
        ]);

        Db::name('coupon')
            ->where('id', (int) ($record['couponid'] ?? 0))
            ->inc('used', 1)
            ->update();
    }

    /**
     * 订单取消释放券（cancelInternal 事务内调用，幂等）
     *
     * - 已核销（防御分支）：回退未使用并清 use_time/order_id，券 used-1
     * - 未使用且预锁定本单：仅清 order_id
     */
    public function releaseByOrder(int $recordId, int $orderId): void
    {
        if ($recordId <= 0) {
            return;
        }

        $record = Db::name('coupon_record')->where('id', $recordId)->lock(true)->find();

        if (empty($record)) {
            return;
        }

        if (self::RECORD_USED === (int) ($record['status'] ?? -1)) {
            Db::name('coupon_record')->where('id', $recordId)->update([
                'status'   => self::RECORD_UNUSED,
                'usetime'  => 0,
                'order_id' => 0,
            ]);

            Db::name('coupon')
                ->where('id', (int) ($record['couponid'] ?? 0))
                ->where('used', '>', 0)
                ->dec('used', 1)
                ->update();

            return;
        }

        if ((int) ($record['order_id'] ?? 0) === $orderId) {
            Db::name('coupon_record')->where('id', $recordId)->update(['order_id' => 0]);
        }
    }

    /* ================= 内部工具 ================= */

    /**
     * 查找本租户未删除的券（不存在抛 404）
     *
     * @return array<string, mixed>
     */
    protected function findCoupon(int $aid, int $id): array
    {
        if ($id <= 0) {
            throw new BusinessException('缺少优惠券ID');
        }

        $coupon = ScopeService::query('coupon', $aid)
            ->where('id', $id)
            ->where('deleted', 0)
            ->find();

        if (empty($coupon)) {
            throw new BusinessException('优惠券不存在', 404, 404);
        }

        return $coupon;
    }

    /**
     * 金额解析（两位小数格式校验）
     */
    protected function parseAmount(mixed $value, string $label): ?float
    {
        if (null === $value || '' === $value) {
            return null;
        }

        $str = (string) $value;

        if (!preg_match('/^\d+(\.\d{1,2})?$/', $str)) {
            throw new BusinessException($label . '格式无效（最多两位小数）');
        }

        return (float) $str;
    }

    /**
     * 起止时间解析（兼容 Unix 秒整数/数字字符串与 'Y-m-d H:i:s' 字符串；end 必须晚于 start）
     *
     * 落库口径不变：旧表 starttime/endtime 仍按 varchar 'Y-m-d H:i:s' 存储
     *
     * @return array{0: string, 1: string}
     */
    protected function parseTimes(mixed $startTime, mixed $endTime): array
    {
        $start = $this->parseOneTime($startTime);
        $end   = $this->parseOneTime($endTime);

        if ($start <= 0 || $end <= 0) {
            throw new BusinessException('起止时间格式无效（应为 Unix 秒或 Y-m-d H:i:s）');
        }

        if ($end <= $start) {
            throw new BusinessException('结束时间必须晚于开始时间');
        }

        return [date('Y-m-d H:i:s', $start), date('Y-m-d H:i:s', $end)];
    }

    /**
     * 单个时间值解析为 Unix 秒（无效返回 0）
     */
    protected function parseOneTime(mixed $value): int
    {
        $str = trim((string) $value);

        if ('' === $str) {
            return 0;
        }

        if (ctype_digit($str)) {
            return (int) $str; // Unix 秒整数（数字字符串直接取用）
        }

        $ts = strtotime($str);

        return false === $ts ? 0 : $ts;
    }

    /**
     * 旧表 varchar 时间列 → Unix 秒（数字串直接取用，其余 strtotime）
     */
    protected function stampOf(mixed $value): int
    {
        $str = trim((string) $value);

        if ('' === $str) {
            return 0;
        }

        if (ctype_digit($str)) {
            return (int) $str;
        }

        return strtotime($str) ?: 0;
    }

    /**
     * 记录状态筛选（0 未使用需排除已过期；2 过期=status0 且 endtime 已过）
     */
    protected function applyRecordStatusFilter($query, string $status, int $now, string $prefix = ''): void
    {
        $col = '' !== $prefix ? $prefix . '.' : '';

        if ('1' === $status) {
            $query->where($col . 'status', self::RECORD_USED);
        } elseif ('0' === $status) {
            $query->where($col . 'status', self::RECORD_UNUSED)
                ->where(function ($q) use ($col, $now) {
                    $q->where($col . 'endtime', 0)->whereOr($col . 'endtime', '>=', $now);
                });
        } elseif ('2' === $status) {
            $query->where($col . 'status', self::RECORD_UNUSED)
                ->where($col . 'endtime', '>', 0)
                ->where($col . 'endtime', '<', $now);
        }
    }

    /**
     * 券行格式化（契约字段）
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function formatCoupon(array $row): array
    {
        return [
            'id'          => (int) ($row['id'] ?? 0),
            'name'        => (string) ($row['name'] ?? ''),
            'type'        => (int) ($row['type'] ?? 1),
            'money'       => (float) ($row['money'] ?? 0),
            'discount'    => (float) ($row['discount'] ?? 0),
            'full_money'  => (float) ($row['minprice'] ?? 0),
            'total'       => (int) ($row['stock'] ?? 0),
            'received'    => (int) ($row['getnum'] ?? 0),
            'used'        => (int) ($row['used'] ?? 0),
            'per_limit'   => (int) ($row['perlimit'] ?? 1),
            'start_time'  => $this->stampOf($row['starttime'] ?? ''),
            'end_time'    => $this->stampOf($row['endtime'] ?? ''),
            'status'      => (int) ($row['status'] ?? 1),
            'create_time' => (int) ($row['createtime'] ?? 0),
        ];
    }

    /**
     * 记录行格式化（status 展示口径：过期实时计算为 2）
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function formatRecord(array $row, int $now): array
    {
        $status = (int) ($row['status'] ?? 0);

        if (self::RECORD_UNUSED === $status) {
            $end    = (int) ($row['endtime'] ?? 0);
            $status = ($end > 0 && $now > $end) ? self::RECORD_EXPIRED : self::RECORD_UNUSED;
        }

        return [
            'id'           => (int) ($row['id'] ?? 0),
            'mid'          => (int) ($row['mid'] ?? 0),
            'member_phone' => (string) ($row['member_phone'] ?? ''),
            'status'       => $status,
            'receive_time' => (int) ($row['receive_time'] ?? ($row['createtime'] ?? 0)),
            'use_time'     => (int) ($row['usetime'] ?? 0),
            'order_sn'     => (string) ($row['order_sn'] ?? ''),
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
