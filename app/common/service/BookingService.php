<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;

/**
 * 餐饮预定订座服务（批次6 T6-1 预定订座模块）
 *
 * 表：restaurant_booking_order / restaurant_booking_order_goods / restaurant_booking_sysset
 *
 * 状态机（对标参考系统）：
 * - status：0未支付 → 1已支付 → 4关闭（驳回/取消/超时）
 * - check_status：0待审核 → 1通过（发短信通知） / -1驳回（已支付自动原路退款）
 *
 * 定金口径：booking_fee 优先桌台分类 table_category.booking_fee（>0），回退 sysset.pay_money；
 * sysset.pay=0 时免费；金额0直接 status=1。支付走 PayService::payBiz（order_type=restaurant_booking）。
 *
 * 时段口径：sysset.timedata JSON 数组 [{day:1今天~4大后天, hour, minute, hour2, minute2}]，
 * booking_time 存 "Y-m-d H:i" 或 "Y-m-d H:i~H:i"（起~止）。
 *
 * 注意：本类被 PayService 构造注入，禁止反向构造注入 PayService（循环依赖），
 * 需要时用 app(PayService::class) 惰性获取。
 */
class BookingService
{
    /** 订单状态 */
    public const STATUS_UNPAID = 0;
    public const STATUS_PAID   = 1;
    public const STATUS_CLOSED = 4;

    /** 审核状态 */
    public const CHECK_PENDING = 0;
    public const CHECK_PASS    = 1;
    public const CHECK_REFUSE  = -1;

    /** 未支付超时关闭默认分钟数（env RESTAURANT_BOOKING.EXPIRE_MINUTES 可调） */
    public const EXPIRE_MINUTES_DEFAULT = 30;

    /** 时段 day 口径：1今天 2明天 3后天 4大后天 */
    private const DAY_OFFSET = [1 => 0, 2 => 1, 3 => 2, 4 => 3];

    private const DAY_TEXT = [1 => '今天', 2 => '明天', 3 => '后天', 4 => '大后天'];

    /* ================= 设置（B端读写 + C端读取） ================= */

    /**
     * 读取预定设置（不存在则按参考系统默认初始化一行，bid=0）
     *
     * @return array<string, mixed>
     */
    public function getSysset(int $aid): array
    {
        $row = ScopeService::query('restaurant_booking_sysset', $aid)
            ->where('bid', 0)
            ->order('id', 'asc')
            ->find();

        if (!empty($row)) {
            return $row;
        }

        Db::name('restaurant_booking_sysset')->insert([
            'aid'       => $aid,
            'bid'       => 0,
            'prehour'   => 2,
            'pay'       => 0,
            'pay_money' => '0.00',
            'status'    => 0,
            'timedata'  => json_encode([
                ['day' => '1', 'hour' => '12', 'minute' => '0', 'hour2' => '12', 'minute2' => '30'],
                ['day' => '1', 'hour' => '18', 'minute' => '0', 'hour2' => '18', 'minute2' => '30'],
                ['day' => '2', 'hour' => '12', 'minute' => '0', 'hour2' => '12', 'minute2' => '30'],
                ['day' => '2', 'hour' => '18', 'minute' => '0', 'hour2' => '18', 'minute2' => '30'],
            ], JSON_UNESCAPED_UNICODE),
        ]);

        return (array) ScopeService::query('restaurant_booking_sysset', $aid)
            ->where('bid', 0)
            ->order('id', 'asc')
            ->find();
    }

    /**
     * 保存预定设置（B端；timedata 由控制器组装好传入）
     *
     * @param array<string, mixed> $data 白名单字段：status/pay/pay_money/prehour/timedata
     */
    public function saveSysset(int $aid, array $data): void
    {
        $set = $this->getSysset($aid);

        Db::name('restaurant_booking_sysset')
            ->where('id', (int) $set['id'])
            ->update($data);
    }

    /* ================= 时段解析 ================= */

    /**
     * 解析 timedata 为时段结构（含未来 4 天的具体起止时间）
     *
     * @return array<int, array{day:int, day_text:string, date:string, start:string, end:string, title:string, value:string, start_ts:int}>
     */
    public function timeSlots(array $set): array
    {
        $slots = [];
        $raw   = json_decode((string) ($set['timedata'] ?? ''), true);

        if (!is_array($raw)) {
            return [];
        }

        $prehour = max(0, (int) ($set['prehour'] ?? 0));

        foreach ($raw as $item) {
            $day = (int) ($item['day'] ?? 0);

            if (!isset(self::DAY_OFFSET[$day])) {
                continue;
            }

            $h1  = (int) ($item['hour'] ?? 0);
            $m1  = (int) ($item['minute'] ?? 0);
            $h2  = (int) ($item['hour2'] ?? $h1);
            $m2  = (int) ($item['minute2'] ?? $m1);
            $date = date('Y-m-d', time() + self::DAY_OFFSET[$day] * 86400);

            $start  = sprintf('%s %02d:%02d', $date, $h1, $m1);
            $end    = sprintf('%02d:%02d', $h2, $m2);
            $startTs = strtotime($start);

            // 结束时间需满足提前量（对标参考系统：strtotime(结束) > now + prehour）
            $endTs = strtotime($date . ' ' . $end);
            if ($endTs <= time() + $prehour * 3600) {
                continue;
            }

            $hasRange = ($h2 !== $h1 || $m2 !== $m1);
            $startTxt = sprintf('%02d:%02d', $h1, $m1);
            $title    = self::DAY_TEXT[$day] . '(' . date('m月d日', strtotime($date)) . ') ' . $startTxt
                . ($hasRange ? '-' . $end : '');
            $value    = $start . ($hasRange ? '~' . $end : '');

            $slots[] = [
                'day'      => $day,
                'day_text' => self::DAY_TEXT[$day],
                'date'     => $date,
                'start'    => $start,
                'end'      => $end,
                'title'    => $title,
                'value'    => $value,
                'start_ts' => $startTs,
            ];
        }

        return $slots;
    }

    /**
     * 全部可配置时段（不过滤提前量，供下单校验时段归属）
     *
     * @return array<int, array{day:int, start:string}>
     */
    public function allConfiguredStarts(array $set): array
    {
        $starts = [];
        $raw    = json_decode((string) ($set['timedata'] ?? ''), true);

        if (!is_array($raw)) {
            return [];
        }

        foreach ($raw as $item) {
            $day = (int) ($item['day'] ?? 0);

            if (!isset(self::DAY_OFFSET[$day])) {
                continue;
            }

            $date = date('Y-m-d', time() + self::DAY_OFFSET[$day] * 86400);
            $starts[] = [
                'day'   => $day,
                'start' => sprintf('%s %02d:%02d', $date, (int) ($item['hour'] ?? 0), (int) ($item['minute'] ?? 0)),
            ];
        }

        return $starts;
    }

    /* ================= C端下单 ================= */

    /**
     * 定金金额（桌台分类 booking_fee 优先 >0，回退 sysset.pay_money；pay=0 免费）
     *
     * @param array<string, mixed> $table
     * @param array<string, mixed> $set
     */
    public function bookingFee(int $aid, array $table, array $set): float
    {
        $fee = (float) ($set['pay_money'] ?? 0);

        $catFee = (float) ScopeService::query('restaurant_table_category', $aid)
            ->where('id', (int) ($table['cid'] ?? 0))
            ->where('bid', 0)
            ->value('booking_fee');

        if ($catFee > 0) {
            $fee = $catFee;
        }

        return 1 === (int) ($set['pay'] ?? 0) ? round($fee, 2) : 0.0;
    }

    /**
     * C端创建预定订单（对标参考系统 ApiRestaurantBooking::add）
     *
     * 校验：预定开启 / 桌台存在可预定 / 时段归属配置 / 提前量 prehour / 同桌同时段冲突
     * 复用：同会员同桌同时段的未支付单 → 更新复用（参考系统路数）
     *
     * @param array<string, mixed> $info tableId/time/renshu/tel/linkman/message
     * @return array<string, mixed>
     */
    public function createOrder(int $aid, int $mid, array $info): array
    {
        $set = $this->getSysset($aid);

        if (1 !== (int) ($set['status'] ?? 0)) {
            throw new BusinessException('未开启预定');
        }

        $tableId = (int) ($info['tableId'] ?? 0);
        $time    = trim((string) ($info['time'] ?? ''));
        $tel     = trim((string) ($info['tel'] ?? ''));
        $linkman = trim((string) ($info['linkman'] ?? ''));
        $seat    = (int) ($info['renshu'] ?? 0);
        $message = trim((string) ($info['message'] ?? ''));

        if ('' === $time) {
            throw new BusinessException('请选择预约时间');
        }
        if ('' === $tel || !preg_match('/^1[3-9]\d{9}$/', $tel)) {
            throw new BusinessException('请检查手机号格式');
        }
        if ($seat <= 0) {
            throw new BusinessException('请选择用餐人数');
        }

        $table = ScopeService::query('restaurant_table', $aid)->where('id', $tableId)->find();

        if (empty($table) || 1 !== (int) ($table['canbook'] ?? 0)) {
            throw new BusinessException('预定餐桌不存在');
        }

        // 时段归属 + 提前量校验
        $startText = explode('~', $time)[0];
        $startTs   = strtotime($startText);

        if (false === $startTs) {
            throw new BusinessException('预约时间格式不正确');
        }

        $prehour = max(0, (int) ($set['prehour'] ?? 0));
        if ($startTs < time() + $prehour * 3600) {
            throw new BusinessException('请重新选择预约时间');
        }

        $matched = false;
        foreach ($this->allConfiguredStarts($set) as $slot) {
            if ($slot['start'] === date('Y-m-d H:i', $startTs)) {
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            throw new BusinessException('该时段不可预定，请重新选择');
        }

        // 同桌同时段冲突（他人未支付/已支付且审核未驳回的单占用该时段）
        $conflict = ScopeService::query('restaurant_booking_order', $aid)
            ->where('tableid', $tableId)
            ->where('booking_time', $time)
            ->whereIn('status', [self::STATUS_UNPAID, self::STATUS_PAID])
            ->whereIn('check_status', [self::CHECK_PENDING, self::CHECK_PASS])
            ->where('mid', '<>', $mid)
            ->find();

        if (!empty($conflict)) {
            throw new BusinessException('该时段已被预定，请选择其他时段');
        }

        $bookingFee = $this->bookingFee($aid, $table, $set);
        $totalPrice = round($bookingFee, 2);

        // 同会员同桌同时段未支付单：复用更新（参考系统路数）
        $exist = ScopeService::query('restaurant_booking_order', $aid)
            ->where('mid', $mid)
            ->where('tableid', $tableId)
            ->where('booking_time', $time)
            ->where('status', self::STATUS_UNPAID)
            ->find();

        $update = [
            'seat'          => $seat,
            'tel'           => $tel,
            'linkman'       => $linkman,
            'message'       => $message,
            'booking_fee'   => $bookingFee,
            'product_price' => 0,
            'totalprice'    => $totalPrice,
            'pay_amount'    => $totalPrice,
            'check_status'  => self::CHECK_PENDING,
            'status'        => $totalPrice <= 0 ? self::STATUS_PAID : self::STATUS_UNPAID,
        ];

        if (!empty($exist)) {
            Db::name('restaurant_booking_order')->where('id', (int) $exist['id'])->update($update);
            $orderId  = (int) $exist['id'];
            $orderNum = (string) ($exist['ordernum'] ?? '');
        } else {
            $now     = time();
            $orderId = (int) Db::name('restaurant_booking_order')->insertGetId([
                'aid'           => $aid,
                'bid'           => 0,
                'mid'           => $mid,
                'ordernum'      => '',
                'tableid'       => $tableId,
                'table_cid'     => (int) ($table['cid'] ?? 0),
                'booking_time'  => $time,
                'booking_fee'   => $bookingFee,
                'product_price' => 0,
                'totalprice'    => $totalPrice,
                'pay_amount'    => $totalPrice,
                'seat'          => $seat,
                'tel'           => $tel,
                'linkman'       => $linkman,
                'message'       => $message,
                'platform'      => 'wx',
                'status'        => $totalPrice <= 0 ? self::STATUS_PAID : self::STATUS_UNPAID,
                'check_status'  => self::CHECK_PENDING,
                'createtime'    => $now,
            ]);

            $orderNum = 'RB' . date('YmdHis', $now)
                . str_pad((string) ($aid % 10000), 4, '0', STR_PAD_LEFT)
                . str_pad((string) ($orderId % 1000000), 6, '0', STR_PAD_LEFT);

            Db::name('restaurant_booking_order')->where('id', $orderId)->update(['ordernum' => $orderNum]);
        }

        return [
            'order_id'   => $orderId,
            'ordernum'   => $orderNum,
            'table_id'   => $tableId,
            'totalprice' => $totalPrice,
            'status'     => $update['status'],
            'need_pay'   => $totalPrice > 0 ? 1 : 0,
        ];
    }

    /**
     * C端发起预定定金支付（pay_order 统一支付单；mock 模式内部直接入账）
     *
     * @return array<string, mixed>
     */
    public function payOrder(int $aid, int $mid, int $orderId, string $payType): array
    {
        return app(PayService::class)->payBiz($aid, $mid, 'restaurant_booking', $orderId, $payType);
    }

    /* ================= C端查询 ================= */

    /**
     * C端可预定桌台列表（canbook=1；按分类筛选；指定时段排除已被预定的桌台；补预定费）
     *
     * @return array{list: array<int, array<string, mixed>>}
     */
    public function apiTableList(int $aid, int $cid, string $time): array
    {
        $query = ScopeService::query('restaurant_table', $aid)
            ->where('bid', 0)
            ->where('canbook', 1);

        if ($cid > 0) {
            $query->where('cid', $cid);
        }

        // 指定时段：排除该时段已有有效预定单的桌台（对标参考系统 tableList）
        if ('' !== $time) {
            $busyIds = ScopeService::query('restaurant_booking_order', $aid)
                ->where('booking_time', $time)
                ->whereIn('check_status', [self::CHECK_PENDING, self::CHECK_PASS])
                ->whereIn('status', [self::STATUS_UNPAID, self::STATUS_PAID])
                ->column('tableid');

            if (!empty($busyIds)) {
                $query->where('id', 'not in', $busyIds);
            }
        }

        $tables = $query->order('sort', 'desc')->order('id', 'desc')->select()->toArray();

        $set        = $this->getSysset($aid);
        $categories = ScopeService::query('restaurant_table_category', $aid)
            ->where('bid', 0)
            ->column('*', 'id');

        $list = [];

        foreach ($tables as $table) {
            $cat = $categories[(int) ($table['cid'] ?? 0)] ?? [];

            $fee = (float) ($set['pay_money'] ?? 0);
            if ((float) ($cat['booking_fee'] ?? 0) > 0) {
                $fee = (float) $cat['booking_fee'];
            }

            $list[] = [
                'id'              => (int) ($table['id'] ?? 0),
                'name'            => (string) ($table['name'] ?? ''),
                'cid'             => (int) ($table['cid'] ?? 0),
                'category_name'   => (string) ($cat['name'] ?? ''),
                'seat'            => (int) ($table['seat'] ?? 0),
                'pic'             => (string) ($table['pic'] ?? ''),
                'sort'            => (int) ($table['sort'] ?? 0),
                'limit_fee'       => (float) ($cat['limit_fee'] ?? 0),
                'service_fee'     => (float) ($cat['service_fee'] ?? 0),
                'booking_fee'     => 1 === (int) ($set['pay'] ?? 0) ? round($fee, 2) : 0.0,
            ];
        }

        return ['list' => $list];
    }

    /**
     * C端桌台分类列表（启用中；show_booking_fee=开启预定且收费）
     *
     * @return array{list: array<int, array<string, mixed>>, set: array{show_booking_fee: int}}
     */
    public function apiTableCategory(int $aid): array
    {
        $rows = ScopeService::query('restaurant_table_category', $aid)
            ->where('bid', 0)
            ->where('status', 1)
            ->order('sort', 'desc')
            ->order('id', 'desc')
            ->select()
            ->toArray();

        $list = array_map(static fn (array $row): array => [
            'id'          => (int) ($row['id'] ?? 0),
            'name'        => (string) ($row['name'] ?? ''),
            'pic'         => (string) ($row['pic'] ?? ''),
            'booking_fee' => (float) ($row['booking_fee'] ?? 0),
            'limit_fee'   => (float) ($row['limit_fee'] ?? 0),
            'service_fee' => (float) ($row['service_fee'] ?? 0),
            'sort'        => (int) ($row['sort'] ?? 0),
        ], $rows);

        $set = $this->getSysset($aid);

        return [
            'list' => $list,
            'set'  => [
                'show_booking_fee' => (1 === (int) ($set['status'] ?? 0) && 1 === (int) ($set['pay'] ?? 0)) ? 1 : 0,
                'status'           => (int) ($set['status'] ?? 0),
            ],
        ];
    }

    /**
     * C端我的预定订单列表（st：''全部 0待支付 1待审核 3已完成 4已关闭 10退款）
     *
     * @return array{list: array<int, array<string, mixed>>, total: int, page: int, pagesize: int}
     */
    public function apiOrderList(int $aid, int $mid, string $st, int $page, int $pageSize): array
    {
        $query = ScopeService::query('restaurant_booking_order', $aid)
            ->where('mid', $mid);

        switch ($st) {
            case '0':
                $query->where('status', self::STATUS_UNPAID);
                break;
            case '1': // 待审核：已支付且未审核
                $query->where('status', self::STATUS_PAID)->where('check_status', self::CHECK_PENDING);
                break;
            case '3': // 已完成：已支付且审核通过
                $query->where('status', self::STATUS_PAID)->where('check_status', self::CHECK_PASS);
                break;
            case '4':
                $query->where('status', self::STATUS_CLOSED);
                break;
            case '10':
                $query->where('refund_status', '>', 0);
                break;
            default:
                break;
        }

        $total = (clone $query)->count();

        $rows = $query->order('id', 'desc')->page($page, $pageSize)->select()->toArray();

        $list = array_map(fn (array $row): array => $this->formatApiRow($aid, $row), $rows);

        return ['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize];
    }

    /**
     * C端预定订单详情（仅本人）
     *
     * @return array<string, mixed>
     */
    public function apiDetail(int $aid, int $mid, int $orderId): array
    {
        $order = ScopeService::query('restaurant_booking_order', $aid)
            ->where('id', $orderId)
            ->where('mid', $mid)
            ->find();

        if (empty($order)) {
            throw new BusinessException('订单不存在', 404, 404);
        }

        $business = Db::name('admin_set')->where('aid', $aid)->field('name,logo,tel,address')->find();

        $data = $this->formatApiRow($aid, $order);

        $data['business'] = empty($business) ? null : [
            'name'    => (string) ($business['name'] ?? ''),
            'logo'    => (string) ($business['logo'] ?? ''),
            'tel'     => (string) ($business['tel'] ?? ''),
            'address' => (string) ($business['address'] ?? ''),
        ];

        return $data;
    }

    /**
     * C端取消预定（本人；已支付自动原路退款，置关闭）
     */
    public function cancelByMember(int $aid, int $mid, int $orderId): void
    {
        Db::transaction(function () use ($aid, $mid, $orderId) {
            $order = Db::name('restaurant_booking_order')
                ->where('aid', $aid)
                ->where('mid', $mid)
                ->where('id', $orderId)
                ->lock(true)
                ->find();

            if (empty($order)) {
                throw new BusinessException('订单不存在', 404, 404);
            }

            if (self::STATUS_CLOSED === (int) $order['status']) {
                throw new BusinessException('订单已关闭');
            }

            $update = ['status' => self::STATUS_CLOSED];

            // 已支付且未退款：原路退款
            if ((float) $order['totalprice'] > 0
                && self::STATUS_PAID === (int) $order['status']
                && 2 !== (int) ($order['refund_status'] ?? 0)) {
                $refundMoney = (float) $order['totalprice'];
                $refundSn    = 'BR' . date('YmdHis') . str_pad((string) ($orderId % 1000000), 6, '0', STR_PAD_LEFT);

                $result = PayService::refundBiz('restaurant_booking', $orderId, $refundMoney, $refundSn);

                if (empty($result['success'])) {
                    throw new BusinessException('退款渠道返回失败');
                }

                Db::name('wxrefund_log')->insert([
                    'aid'           => $aid,
                    'mch_id'        => '',
                    'ordernum'      => (string) ($order['ordernum'] ?? ''),
                    'out_refund_no' => $refundSn,
                    'totalprice'    => (float) $order['totalprice'],
                    'refundmoney'   => $refundMoney,
                    'createtime'    => (string) time(),
                    'status'        => 1,
                    'remark'        => '预定订单用户取消退款',
                    'errmsg'        => '',
                ]);

                $update['refund_status'] = 2;
                $update['refund_money']  = $refundMoney;
                $update['refund_reason'] = '用户取消退款';
                $update['refund_time']   = time();
            }

            Db::name('restaurant_booking_order')->where('id', $orderId)->update($update);
        });
    }

    /* ================= B端审核 ================= */

    /**
     * 后台审核（access 通过：check_status=1 + 短信通知；refuse 驳回：已支付自动退款 + status=4）
     */
    public function checkOrder(int $aid, int $orderId, string $type): void
    {
        if ('access' !== $type && 'refuse' !== $type) {
            throw new BusinessException('非法的审核操作');
        }

        $order = ScopeService::query('restaurant_booking_order', $aid)
            ->where('id', $orderId)
            ->find();

        if (empty($order)) {
            throw new BusinessException('订单不存在', 404, 404);
        }

        if (self::CHECK_PENDING !== (int) $order['check_status']) {
            throw new BusinessException('该订单已审核，请勿重复操作');
        }

        $tableName      = (string) ScopeService::query('restaurant_table', $aid)->where('id', (int) $order['tableid'])->value('name');
        $restaurantName = (string) Db::name('admin_set')->where('aid', $aid)->value('name');
        $member         = Db::name('member')->where('aid', $aid)->where('id', (int) $order['mid'])->find();
        $memberTel      = (string) ($member['tel'] ?? '');
        $tel            = '' !== $memberTel ? $memberTel : (string) ($order['tel'] ?? '');

        if ('access' === $type) {
            Db::name('restaurant_booking_order')->where('id', $orderId)->update([
                'check_status' => self::CHECK_PASS,
            ]);

            $this->notifyMember($aid, $tel, 'tmpl_restaurant_booking', [
                'restaurant_name' => $restaurantName,
                'table'           => $tableName,
                'time_range'      => (string) ($order['booking_time'] ?? ''),
            ]);

            return;
        }

        // 驳回：已关闭直接拒绝；已支付自动退款后关闭
        if (self::STATUS_CLOSED === (int) $order['status']) {
            throw new BusinessException('订单已关闭');
        }

        Db::transaction(function () use ($aid, $orderId, $order) {
            $locked = Db::name('restaurant_booking_order')
                ->where('aid', $aid)
                ->where('id', $orderId)
                ->lock(true)
                ->find();

            if (empty($locked) || self::CHECK_PENDING !== (int) $locked['check_status']) {
                throw new BusinessException('该订单已审核，请勿重复操作');
            }

            $update = [
                'check_status' => self::CHECK_REFUSE,
                'status'       => self::STATUS_CLOSED,
            ];

            if ((float) $locked['totalprice'] > 0
                && self::STATUS_PAID === (int) $locked['status']
                && 2 !== (int) ($locked['refund_status'] ?? 0)) {
                $refundMoney = (float) $locked['totalprice'];
                $refundSn    = 'BR' . date('YmdHis') . str_pad((string) ($orderId % 1000000), 6, '0', STR_PAD_LEFT);

                $result = PayService::refundBiz('restaurant_booking', $orderId, $refundMoney, $refundSn);

                if (empty($result['success'])) {
                    throw new BusinessException('退款渠道返回失败');
                }

                Db::name('wxrefund_log')->insert([
                    'aid'           => $aid,
                    'mch_id'        => '',
                    'ordernum'      => (string) ($locked['ordernum'] ?? ''),
                    'out_refund_no' => $refundSn,
                    'totalprice'    => (float) $locked['totalprice'],
                    'refundmoney'   => $refundMoney,
                    'createtime'    => (string) time(),
                    'status'        => 1,
                    'remark'        => '预定订单后台驳回退款',
                    'errmsg'        => '',
                ]);

                $update['refund_status'] = 2;
                $update['refund_money']  = $refundMoney;
                $update['refund_reason'] = '后台驳回退款';
                $update['refund_time']   = time();
            }

            Db::name('restaurant_booking_order')->where('id', $orderId)->update($update);
        });

        $this->notifyMember($aid, $tel, 'tmpl_restaurant_booking_fail', [
            'restaurant_name' => $restaurantName,
        ]);
    }

    /* ================= 定时任务 ================= */

    /**
     * 未支付超时关闭（对标参考系统 auto_perminute 定时段；条件更新幂等）
     *
     * @return int 关闭笔数
     */
    public function closeExpiredUnpaid(int $batch = 200): int
    {
        $minutes = (int) trim(strtok((string) env('RESTAURANT_BOOKING.EXPIRE_MINUTES', (string) self::EXPIRE_MINUTES_DEFAULT), '#'));

        if ($minutes <= 0) {
            $minutes = self::EXPIRE_MINUTES_DEFAULT;
        }

        $cutoff = time() - $minutes * 60;

        $ids = Db::name('restaurant_booking_order')
            ->where('status', self::STATUS_UNPAID)
            ->where('createtime', '>', 0)
            ->where('createtime', '<', $cutoff)
            ->order('id', 'asc')
            ->limit($batch)
            ->column('id');

        $closed = 0;

        foreach ($ids as $orderId) {
            $affected = Db::name('restaurant_booking_order')
                ->where('id', (int) $orderId)
                ->where('status', self::STATUS_UNPAID)
                ->update(['status' => self::STATUS_CLOSED]);

            if (1 === $affected) {
                ++$closed;
            }
        }

        return $closed;
    }

    /* ================= 支付回调 ================= */

    /**
     * 预定订单支付成功入账（幂等：行锁 + status=0 条件更新）
     *
     * @return bool true=本次入账 false=已处理过（幂等）
     */
    public function onPaid(int $orderId, string $payType, string $payNum): bool
    {
        return (bool) Db::transaction(function () use ($orderId, $payType, $payNum) {
            $order = Db::name('restaurant_booking_order')->where('id', $orderId)->lock(true)->find();

            if (empty($order) || self::STATUS_UNPAID !== (int) $order['status']) {
                return false; // 已支付/已取消等：幂等不重复处理
            }

            Db::name('restaurant_booking_order')
                ->where('id', $orderId)
                ->where('status', self::STATUS_UNPAID)
                ->update([
                    'status'     => self::STATUS_PAID,
                    'paytime'    => time(),
                    'paytype'    => $payType,
                    'paytypeid'  => PayService::PAY_TYPE_IDS[$payType] ?? 0,
                    'paynum'     => $payNum,
                ]);

            return true;
        });
    }

    /* ================= 内部工具 ================= */

    /**
     * 短信通知会员（失败仅记 smslog，不影响业务）
     *
     * @param array<string, mixed> $params
     */
    protected function notifyMember(int $aid, string $tel, string $tmplField, array $params): void
    {
        if ('' === trim($tel)) {
            return;
        }

        try {
            $smsService = app(SmsService::class);
            $config     = $smsService->getConfig($aid);
            $code       = (string) ($config[$tmplField] ?? '');

            $smsService->send($aid, $tel, $code, $params);
        } catch (\Throwable $e) {
            // 通知失败不影响主业务
        }
    }

    /**
     * C端订单行格式化
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function formatApiRow(int $aid, array $row): array
    {
        $status      = (int) ($row['status'] ?? 0);
        $checkStatus = (int) ($row['check_status'] ?? 0);

        // 兼容参考系统路数：审核通过后用户取消（status=4）对C端展示为待审核已取消口径
        if (self::CHECK_PASS === $checkStatus && self::STATUS_CLOSED === $status) {
            $checkStatus = self::CHECK_PENDING;
        }

        return [
            'id'             => (int) ($row['id'] ?? 0),
            'ordernum'       => (string) ($row['ordernum'] ?? ''),
            'table_id'       => (int) ($row['tableid'] ?? 0),
            'table_name'     => (string) ScopeService::query('restaurant_table', $aid)->where('id', (int) ($row['tableid'] ?? 0))->value('name'),
            'booking_time'   => (string) ($row['booking_time'] ?? ''),
            'seat'           => (int) ($row['seat'] ?? 0),
            'tel'            => (string) ($row['tel'] ?? ''),
            'linkman'        => (string) ($row['linkman'] ?? ''),
            'message'        => (string) ($row['message'] ?? ''),
            'booking_fee'    => (float) ($row['booking_fee'] ?? 0),
            'totalprice'     => (float) ($row['totalprice'] ?? 0),
            'status'         => $status,
            'status_text'    => match ($status) {
                0       => '待支付',
                1       => '已支付',
                4       => '已关闭',
                default => '未知',
            },
            'check_status'   => $checkStatus,
            'check_text'     => match ($checkStatus) {
                1       => '审核通过',
                -1      => '已驳回',
                default => '待审核',
            },
            'refund_status'  => (int) ($row['refund_status'] ?? 0),
            'refund_money'   => (float) ($row['refund_money'] ?? 0),
            'refund_reason'  => (string) ($row['refund_reason'] ?? ''),
            'paytype'        => (string) ($row['paytype'] ?? ''),
            'pay_time'       => (int) ($row['paytime'] ?? 0),
            'createtime'     => (int) ($row['createtime'] ?? 0),
        ];
    }
}
