<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;

/**
 * 餐饮取餐（叫号）服务（批次6-T6-4）
 *
 * 表：restaurant_take_food（取餐队列）/ restaurant_take_food_sysset（大屏轮播设置）
 * 取餐号规则（对齐参考系统）：restaurant_shop_sysset.take_food_number_prefix（默认D）
 *   + 当日自增（start_pickup_number + 当日订单数，3位补零），支付成功时生成。
 *
 * 状态口径：0制作中 1待取餐 2已取餐 -1过期（24小时未取由 schedule:run 置过期）
 */
class TakeFoodService
{
    /** 取餐状态 */
    public const STATUS_MAKING  = 0;
    public const STATUS_READY   = 1;
    public const STATUS_DONE    = 2;
    public const STATUS_EXPIRED = -1;

    public const STATUS_TEXT = [
        self::STATUS_MAKING  => '制作中',
        self::STATUS_READY   => '待取餐',
        self::STATUS_DONE    => '已取餐',
        self::STATUS_EXPIRED => '已过期',
    ];

    /** 未取餐过期时长（秒）：24小时 */
    public const EXPIRE_SECONDS = 86400;

    /**
     * 支付成功生成取餐号（RestaurantService::onPaid 末尾调用）
     *
     * 幂等：订单已有取餐记录或已回填 pickup_number 时直接跳过，重复支付不重复生成。
     * 流程：取租户前缀/起始号 → 当日订单数自增 → 写 take_food → 回填订单 pickup_number。
     *
     * @return bool true=本次生成 false=幂等跳过/订单状态不满足
     */
    public function generate(int $orderId): bool
    {
        return (bool) Db::transaction(function () use ($orderId) {
            $order = Db::name('restaurant_shop_order')->where('id', $orderId)->lock(true)->find();

            if (empty($order) || 1 !== (int) $order['status']) {
                return false; // 仅已支付订单生成
            }

            // 幂等防重复：已有取餐记录或订单已回填取餐号
            $exists = Db::name('restaurant_take_food')->where('orderid', $orderId)->find();

            if (!empty($exists) || '' !== trim((string) ($order['pickup_number'] ?? ''))) {
                return false;
            }

            $aid = (int) ($order['aid'] ?? 0);
            $bid = (int) ($order['bid'] ?? 0);

            if ($aid <= 0) {
                return false;
            }

            $sysset = Db::name('restaurant_shop_sysset')->where('aid', $aid)->order('id', 'asc')->find();

            $prefix = trim((string) ($sysset['take_food_number_prefix'] ?? 'D'));
            if ('' === $prefix) {
                $prefix = 'D';
            }
            $start = max(0, (int) ($sysset['start_pickup_number'] ?? 0));

            // 当日自增：起始号 + 当日订单数（含本单，对齐参考系统口径）
            $dayStart = strtotime(date('Y-m-d'));
            $count    = Db::name('restaurant_shop_order')
                ->where('aid', $aid)
                ->where('bid', $bid)
                ->where('createtime', '>=', $dayStart)
                ->count();

            $num = $start + $count;
            if ($num < 1) {
                $num = 1;
            }

            // 防并发同号：若该取餐号已被占用则顺延
            while (null !== Db::name('restaurant_take_food')
                ->where('aid', $aid)
                ->where('pickup_number', $prefix . str_pad((string) $num, 3, '0', STR_PAD_LEFT))
                ->find()) {
                ++$num;
            }

            $pickupNumber = $prefix . str_pad((string) $num, 3, '0', STR_PAD_LEFT);
            $callText     = '请' . $pickupNumber . '号到取餐处取餐。';

            Db::name('restaurant_take_food')->insert([
                'aid'            => $aid,
                'bid'            => $bid,
                'mid'            => (int) ($order['mid'] ?? 0),
                'orderid'        => $orderId,
                'pickup_number'  => $pickupNumber,
                'create_time'    => time(),
                'status'         => self::STATUS_MAKING,
                'call_text'      => $callText,
                'platform'       => 'wx',
                'call_voice_url' => '',
                'need_play'      => 0,
            ]);

            Db::name('restaurant_shop_order')
                ->where('id', $orderId)
                ->update(['pickup_number' => $pickupNumber]);

            return true;
        });
    }

    /**
     * 后台取餐列表（筛选状态/取餐号/订单号；关联订单号、桌台、会员）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function adminList(int $aid, string $status, string $pickupNumber, string $ordernum, int $page, int $pageSize): array
    {
        $query = ScopeService::queryAs('restaurant_take_food', $aid, 'tf')
            ->leftJoin('restaurant_shop_order o', 'o.id = tf.orderid')
            ->leftJoin('restaurant_table t', 't.id = o.tableid')
            ->leftJoin('member m', 'm.id = tf.mid')
            ->field('tf.*, o.ordernum, o.status as order_status, t.name as table_name, m.nickname as member_nickname');

        if ('' !== $status) {
            $query->where('tf.status', (int) $status);
        }
        if ('' !== $pickupNumber) {
            $query->where('tf.pickup_number', 'like', '%' . $pickupNumber . '%');
        }
        if ('' !== $ordernum) {
            $query->where('o.ordernum', 'like', '%' . $ordernum . '%');
        }

        $total = (clone $query)->count();

        $rows = $query->order('tf.id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        return [
            'list'     => array_map(fn (array $row) => $this->formatRow($row), $rows),
            'total'    => $total,
            'page'     => $page,
            'pagesize' => $pageSize,
        ];
    }

    /**
     * 出餐（制作中 → 待取餐；条件更新幂等）
     */
    public function serve(int $aid, int $id): void
    {
        $row = ScopeService::query('restaurant_take_food', $aid)->where('id', $id)->find();

        if (empty($row)) {
            throw new BusinessException('取餐记录不存在');
        }

        $affected = Db::name('restaurant_take_food')
            ->where('id', $id)
            ->where('aid', $aid)
            ->where('status', self::STATUS_MAKING)
            ->update(['status' => self::STATUS_READY]);

        if (1 !== $affected) {
            throw new BusinessException('仅制作中的取餐单可出餐');
        }
    }

    /**
     * 叫号（置 call_time + call_text + need_play=1；仅制作中/待取餐可叫号）
     */
    public function callNumber(int $aid, int $id, string $callText = ''): void
    {
        $row = ScopeService::query('restaurant_take_food', $aid)->where('id', $id)->find();

        if (empty($row)) {
            throw new BusinessException('取餐记录不存在');
        }

        $callText = trim($callText);
        if ('' === $callText) {
            $callText = '请' . (string) $row['pickup_number'] . '号到取餐处取餐。';
        }

        $affected = Db::name('restaurant_take_food')
            ->where('id', $id)
            ->where('aid', $aid)
            ->whereIn('status', [self::STATUS_MAKING, self::STATUS_READY])
            ->update([
                'call_time' => time(),
                'call_text' => $callText,
                'need_play' => 1,
            ]);

        if (1 !== $affected) {
            throw new BusinessException('当前状态不可叫号（仅制作中/待取餐可叫号）');
        }
    }

    /**
     * 完成取餐（制作中/待取餐 → 已取餐）
     */
    public function finish(int $aid, int $id): void
    {
        $row = ScopeService::query('restaurant_take_food', $aid)->where('id', $id)->find();

        if (empty($row)) {
            throw new BusinessException('取餐记录不存在');
        }

        $affected = Db::name('restaurant_take_food')
            ->where('id', $id)
            ->where('aid', $aid)
            ->whereIn('status', [self::STATUS_MAKING, self::STATUS_READY])
            ->update(['status' => self::STATUS_DONE, 'need_play' => 0]);

        if (1 !== $affected) {
            throw new BusinessException('当前状态不可完成取餐');
        }
    }

    /**
     * 24小时未取自动过期（schedule:run 挂接；status IN(0,1) → -1，条件更新幂等）
     *
     * @return int 过期笔数
     */
    public function expireExpired(): int
    {
        return (int) Db::name('restaurant_take_food')
            ->whereIn('status', [self::STATUS_MAKING, self::STATUS_READY])
            ->where('create_time', '>', 0)
            ->where('create_time', '<', time() - self::EXPIRE_SECONDS)
            ->update(['status' => self::STATUS_EXPIRED, 'need_play' => 0]);
    }

    /**
     * 读取取餐设置（banner 多图 JSON / 大屏图 / 轮播间隔）
     *
     * @return array{banner: array<int,string>, screen_pic: string, banner_interval: int}
     */
    public function getSet(int $aid): array
    {
        $row = ScopeService::query('restaurant_take_food_sysset', $aid)->where('bid', 0)->order('id', 'asc')->find();

        return [
            'banner'          => $this->decodeBanner((string) ($row['banner'] ?? '')),
            'screen_pic'      => (string) ($row['screen_pic'] ?? ''),
            'banner_interval' => max(1, (int) ($row['banner_interval'] ?? 3)),
        ];
    }

    /**
     * 保存取餐设置（不存在则插入，存在则更新；bid=0 一期）
     *
     * @param array<int,string> $banner
     */
    public function saveSet(int $aid, array $banner, string $screenPic, int $bannerInterval): void
    {
        $banner = array_values(array_filter(array_map('trim', $banner), static fn (string $v) => '' !== $v));

        if (count($banner) > 10) {
            throw new BusinessException('轮播图最多上传 10 张');
        }
        if ($bannerInterval < 1 || $bannerInterval > 60) {
            throw new BusinessException('轮播间隔需在 1-60 秒之间');
        }

        $data = [
            'banner'          => json_encode($banner, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'screen_pic'      => trim($screenPic),
            'banner_interval' => $bannerInterval,
        ];

        $row = ScopeService::query('restaurant_take_food_sysset', $aid)->where('bid', 0)->order('id', 'asc')->find();

        if (!empty($row)) {
            Db::name('restaurant_take_food_sysset')->where('id', $row['id'])->update($data);
        } else {
            $data['aid'] = $aid;
            $data['bid'] = 0;
            Db::name('restaurant_take_food_sysset')->insert($data);
        }
    }

    /**
     * 取餐大屏轮询数据（匿名）：设置 + 待取餐队列 + 最近叫号
     *
     * @return array{set: array, queue: array, last_call: array|null}
     */
    public function screen(int $aid): array
    {
        $set = $this->getSet($aid);

        // 待取餐队列（叫号时间倒序，未叫号的按创建时间正序排后）
        $rows = ScopeService::query('restaurant_take_food', $aid)
            ->where('bid', 0)
            ->where('status', self::STATUS_READY)
            ->where('create_time', '>', time() - self::EXPIRE_SECONDS)
            ->field('id, pickup_number, call_time, call_text, create_time')
            ->order('call_time', 'desc')
            ->order('id', 'asc')
            ->limit(50)
            ->select()
            ->toArray();

        $queue = array_map(static fn (array $row) => [
            'pickup_number' => (string) $row['pickup_number'],
            'call_time'     => (int) ($row['call_time'] ?? 0),
            'call_text'     => (string) ($row['call_text'] ?? ''),
            'create_time'   => (int) ($row['create_time'] ?? 0),
        ], $rows);

        // 最近一次叫号（5分钟内，供大屏语音播放 need_play 判定）
        $last = ScopeService::query('restaurant_take_food', $aid)
            ->where('bid', 0)
            ->where('call_time', '>', time() - 300)
            ->field('pickup_number, call_time, call_text, call_voice_url, need_play')
            ->order('call_time', 'desc')
            ->find();

        return [
            'set'       => $set,
            'queue'     => $queue,
            'last_call' => empty($last) ? null : [
                'pickup_number'  => (string) $last['pickup_number'],
                'call_time'      => (int) ($last['call_time'] ?? 0),
                'call_text'      => (string) ($last['call_text'] ?? ''),
                'call_voice_url' => (string) ($last['call_voice_url'] ?? ''),
                'need_play'      => (int) ($last['need_play'] ?? 0),
            ],
        ];
    }

    /**
     * 后台列表行格式化
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function formatRow(array $row): array
    {
        $status = (int) ($row['status'] ?? 0);

        return [
            'id'              => (int) ($row['id'] ?? 0),
            'pickup_number'   => (string) ($row['pickup_number'] ?? ''),
            'status'          => $status,
            'status_text'     => self::STATUS_TEXT[$status] ?? '未知',
            'orderid'         => (int) ($row['orderid'] ?? 0),
            'ordernum'        => (string) ($row['ordernum'] ?? ''),
            'table_name'      => (string) ($row['table_name'] ?? ''),
            'member_nickname' => (string) ($row['member_nickname'] ?? ''),
            'call_text'       => (string) ($row['call_text'] ?? ''),
            'call_time'       => (int) ($row['call_time'] ?? 0),
            'need_play'       => (int) ($row['need_play'] ?? 0),
            'create_time'     => (int) ($row['create_time'] ?? 0),
        ];
    }

    /**
     * banner 存储解码（JSON 数组字符串 → 数组；兼容逗号串历史格式）
     *
     * @return array<int, string>
     */
    protected function decodeBanner(string $raw): array
    {
        $raw = trim($raw);

        if ('' === $raw) {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (is_array($decoded)) {
            return array_values(array_filter(array_map('strval', $decoded)));
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }
}
