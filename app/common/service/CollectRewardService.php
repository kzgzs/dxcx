<?php

declare(strict_types=1);

namespace app\common\service;

use think\facade\Db;
use think\facade\Log;

/**
 * 确认收货奖励发放服务（批次4-E 任务190 E2-5）
 *
 * 口径对照参考系统 Order::orderCollectReward：
 * - 设置：时间段 start_time~end_time + status=1 + 订单 createtime 在时段内
 * - 校验：order_type（订单范围）/ platform（平台）/ gettj（人群 -1 不限）
 *   / min_order_amount（totalprice-freight_price 满额）
 *   / max_daily_return、max_month_return（按记录表今日/本月计数，0 不限）
 * - 幂等：order_collect_reward_record（ordertype+orderid 唯一键）查重
 * - 发放：reward_type 1积分 / 2优惠券 / 3余额 / 4佣金，先落记录再发放
 *
 * 触发点：OrderService::confirm 独立 try/catch（不阻断确认收货主链路）
 */
class CollectRewardService
{
    public function __construct(
        protected ScoreService $scoreService,
        protected CouponService $couponService,
    ) {
    }

    /**
     * 商城订单确认收货触发入口（ordertype='shop'）
     *
     * 任何异常仅记日志不抛出（调用方已有 try/catch 双保险）
     */
    public function onOrderFinished(int $aid, int $orderId): void
    {
        try {
            $this->grant($aid, 'shop', $orderId);
        } catch (\Throwable $e) {
            Log::error('collect reward fail order#' . $orderId . ': ' . $e->getMessage());
        }
    }

    /**
     * 奖励记录列表（B 端，口径对齐 canzhao OrderCollectReward/index）
     *
     * @param array<string, mixed> $filter ordertype/keyword/ordernum/reward_type/mid/ordertime_start/ordertime_end
     * @return array{list: array<int, array<string, mixed>>, total: int}
     */
    public function recordList(int $aid, array $filter, int $page, int $pageSize): array
    {
        $query = Db::name('order_collect_reward_record')->alias('cr')
            ->leftJoin('member m', 'm.id = cr.mid')
            ->where('cr.aid', $aid);

        $ordertype = trim((string) ($filter['ordertype'] ?? ''));
        if ('' !== $ordertype) {
            $query->where('cr.ordertype', $ordertype);
        }

        $rewardType = (int) ($filter['reward_type'] ?? 0);
        if ($rewardType > 0) {
            $query->where('cr.reward_type', $rewardType);
        }

        $ordernum = trim((string) ($filter['ordernum'] ?? ''));
        if ('' !== $ordernum) {
            $query->where('cr.ordernum', '=', $ordernum);
        }

        $mid = (int) ($filter['mid'] ?? 0);
        if ($mid > 0) {
            $query->where('cr.mid', $mid);
        }

        $keyword = trim((string) ($filter['keyword'] ?? ''));
        if ('' !== $keyword) {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('m.nickname', '%' . $keyword . '%')
                    ->whereOr('m.tel', 'like', '%' . $keyword . '%');
            });
        }

        $otStart = (int) ($filter['ordertime_start'] ?? 0);
        $otEnd   = (int) ($filter['ordertime_end'] ?? 0);
        if ($otStart > 0) {
            $query->where('cr.ordertime', '>=', $otStart);
        }
        if ($otEnd > 0) {
            $query->where('cr.ordertime', '<', $otEnd + 86400);
        }

        $total = (clone $query)->count();

        $rows = $query->field('cr.*, m.nickname as member_nickname, m.headimg as member_headimg, m.tel as member_tel')
            ->order('cr.id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        // 商家名（businessshop 订单）
        $bids = array_values(array_unique(array_filter(array_map(static fn (array $r) => (int) ($r['bid'] ?? 0), $rows))));
        $bnameMap = $bids
            ? Db::name('business')->where('aid', $aid)->whereIn('id', $bids)->column('name', 'id')
            : [];

        // 券名（reward_type=2）
        $couponIds = array_values(array_unique(array_filter(array_map(
            static fn (array $r) => 2 === (int) $r['reward_type'] ? (int) $r['reward'] : 0,
            $rows
        ))));
        $couponNameMap = $couponIds
            ? Db::name('coupon')->where('aid', $aid)->whereIn('id', $couponIds)->column('name', 'id')
            : [];

        foreach ($rows as &$row) {
            $row['nickname']       = (string) ($row['member_nickname'] ?? '');
            $row['headimg']        = (string) ($row['member_headimg'] ?? '');
            $row['createtime_text'] = (int) $row['createtime'] > 0 ? date('Y-m-d H:i:s', (int) $row['createtime']) : '';
            $row['ordertime_text']  = (int) ($row['ordertime'] ?? 0) > 0 ? date('Y-m-d H:i:s', (int) $row['ordertime']) : '';
            $row['ordertype_text']  = self::ORDERTYPE_TEXT[(string) $row['ordertype']] ?? (string) $row['ordertype'];
            $row['reward_type_text'] = self::REWARD_TYPE_TEXT[(int) $row['reward_type']] ?? '';
            $row['business']        = $bnameMap[(int) ($row['bid'] ?? 0)] ?? '';
            $row['reward_text']     = $this->rewardText((int) $row['reward_type'], $row['reward'], $couponNameMap);
        }

        unset($row);

        return ['list' => $rows, 'total' => $total];
    }

    /**
     * 删除奖励记录（兼容 id 单删 / ids[] 批量删）
     *
     * @param array<int, int> $ids
     */
    public function deleteRecords(int $aid, array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));

        if (empty($ids)) {
            return;
        }

        Db::name('order_collect_reward_record')->where('aid', $aid)->whereIn('id', $ids)->delete();
    }

    /* ================= 内部实现 ================= */

    /** 订单类型文案（对齐 canzhao index） */
    private const ORDERTYPE_TEXT = [
        'shop'        => '商城订单',
        'collage'     => '多人拼团订单',
        'tuangou'     => '团购订单',
        'scoreshop'   => '积分兑换订单',
        'businessshop' => '商家订单',
    ];

    /** 奖励类型文案（对齐 canzhao index） */
    private const REWARD_TYPE_TEXT = [
        1 => '积分',
        2 => '优惠券',
        3 => '余额返现',
        4 => '佣金返现',
    ];

    /**
     * 单笔订单发放判定与执行
     */
    protected function grant(int $aid, string $ordertype, int $orderId): void
    {
        $set = ScopeService::query('order_collect_reward', $aid)->order('id', 'desc')->find();

        if (empty($set) || 1 !== (int) $set['status']) {
            return;
        }

        $now = time();

        if ((int) $set['start_time'] > $now || (int) $set['end_time'] < $now) {
            return;
        }

        $order = ScopeService::query('shop_order', $aid)->where('id', $orderId)->find();

        if (empty($order)) {
            return;
        }

        $order = (array) $order;

        // 订单下单时间须在活动时段内
        if ((int) $order['createtime'] < (int) $set['start_time'] || (int) $order['createtime'] > (int) $set['end_time']) {
            return;
        }

        $mid = (int) ($order['mid'] ?? 0);

        if ($mid <= 0) {
            return;
        }

        // 订单范围（order_type CSV 须包含当前订单类型）
        $orderTypes = array_filter(array_map('trim', explode(',', (string) ($set['order_type'] ?? ''))));

        if (!empty($orderTypes) && !in_array($ordertype, $orderTypes, true)) {
            return;
        }

        // 平台校验（-1/all 不限）
        $platforms = array_filter(array_map('trim', explode(',', (string) ($set['platform'] ?? ''))));

        if (!empty($platforms) && !in_array('-1', $platforms, true) && !in_array('all', $platforms, true)
            && !in_array((string) ($order['platform'] ?? ''), $platforms, true)) {
            return;
        }

        // 人群校验（-1 不限，其余为等级 id）
        $gettj = array_filter(array_map('trim', explode(',', (string) ($set['gettj'] ?? '-1'))));

        if (!in_array('-1', $gettj, true)) {
            $member = Db::name('member')->where('id', $mid)->where('aid', $aid)->find();

            if (empty($member) || !in_array((string) ($member['levelid'] ?? 0), $gettj, true)) {
                return;
            }
        }

        // 满额门槛（订单金额 = 总额 - 运费）
        $minAmount = (float) ($set['min_order_amount'] ?? 0);

        if ($minAmount > 0) {
            $orderAmount = (float) ($order['totalprice'] ?? 0) - (float) ($order['freight_price'] ?? 0);

            if ($orderAmount < $minAmount) {
                return;
            }
        }

        // 幂等（ordertype+orderid 唯一）
        $exists = Db::name('order_collect_reward_record')
            ->where('aid', $aid)->where('ordertype', $ordertype)->where('orderid', $orderId)->find();

        if (!empty($exists)) {
            return;
        }

        // 每日/每月次数上限
        if ((int) $set['max_daily_return'] > 0) {
            $dayCount = Db::name('order_collect_reward_record')
                ->where('aid', $aid)->where('mid', $mid)->where('ordertype', $ordertype)
                ->where('createtime', '>=', strtotime(date('Y-m-d')))
                ->count();

            if ($dayCount >= (int) $set['max_daily_return']) {
                return;
            }
        }

        if ((int) $set['max_month_return'] > 0) {
            $monthCount = Db::name('order_collect_reward_record')
                ->where('aid', $aid)->where('mid', $mid)->where('ordertype', $ordertype)
                ->where('createtime', '>=', strtotime(date('Y-m-01')))
                ->count();

            if ($monthCount >= (int) $set['max_month_return']) {
                return;
            }
        }

        $rewardType = (int) ($set['reward_type'] ?? 0);

        if (!in_array($rewardType, [1, 2, 3, 4], true)) {
            return;
        }

        $rewardValue = $this->rewardValue($aid, $rewardType, $set);

        if ($rewardValue <= 0) {
            return;
        }

        Db::transaction(function () use ($aid, $mid, $ordertype, $orderId, $order, $rewardType, $rewardValue) {
            // 先落记录（唯一键兜底并发）再发放
            Db::name('order_collect_reward_record')->insert([
                'aid'         => $aid,
                'bid'         => (int) ($order['bid'] ?? 0),
                'mid'         => $mid,
                'orderid'     => $orderId,
                'ordernum'    => (string) ($order['ordernum'] ?? ''),
                'ordertype'   => $ordertype,
                'reward_type' => $rewardType,
                'reward'      => $rewardValue,
                'ordertime'   => (int) ($order['createtime'] ?? 0),
                'createtime'  => time(),
            ]);

            $this->send($aid, $mid, $rewardType, $rewardValue, (int) ($order['ordernum'] ?? 0) ?: $orderId, (string) ($order['ordernum'] ?? ''));
        });
    }

    /**
     * 按 reward_type 计算奖励数值（券返回面额展示值，发放按 coupon_id）
     *
     * @param array<string, mixed> $set
     */
    protected function rewardValue(int $aid, int $rewardType, array $set): float
    {
        if (1 === $rewardType) {
            return (float) (int) ($set['score'] ?? 0);
        }

        if (2 === $rewardType) {
            $couponId = (int) ($set['coupon_id'] ?? 0);

            if ($couponId <= 0) {
                return 0.0;
            }

            $money = ScopeService::query('coupon', $aid)->where('id', $couponId)->value('money');

            return $money === null ? 0.0 : (float) $money;
        }

        if (3 === $rewardType) {
            return (float) ($set['money'] ?? 0);
        }

        return (float) ($set['commission'] ?? 0);
    }

    /**
     * 实际发放
     */
    protected function send(int $aid, int $mid, int $rewardType, float $value, int $orderId, string $ordernum): void
    {
        $remark = '确认收货奖励 订单' . $ordernum;

        // 1 积分
        if (1 === $rewardType) {
            $this->scoreService->adjust($aid, $mid, (int) $value, $remark);

            return;
        }

        // 2 优惠券（按设置 coupon_id 发券，value 仅展示）
        if (2 === $rewardType) {
            $set      = ScopeService::query('order_collect_reward', $aid)->order('id', 'desc')->find();
            $couponId = (int) ($set['coupon_id'] ?? 0);

            if ($couponId > 0) {
                $this->couponService->receive($aid, $mid, $couponId);
            }

            return;
        }

        $member = Db::name('member')->where('id', $mid)->where('aid', $aid)->find();

        if (empty($member)) {
            return;
        }

        // 3 余额
        if (3 === $rewardType) {
            $after = round((float) ($member['money'] ?? 0) + $value, 2);

            Db::name('member')->where('id', $mid)->where('aid', $aid)->update(['money' => $after]);

            Db::name('member_moneylog')->insert([
                'aid'        => $aid,
                'mid'        => $mid,
                'money'      => $value,
                'after'      => $after,
                'createtime' => time(),
                'remark'     => $remark,
                'paytype'    => 'collect_reward',
                'uid'        => 0,
            ]);

            return;
        }

        // 4 佣金
        $after = round((float) ($member['commission'] ?? 0) + $value, 2);

        Db::name('member')->where('id', $mid)->where('aid', $aid)->update([
            'commission'      => $after,
            'totalcommission' => round((float) ($member['totalcommission'] ?? 0) + $value, 2),
        ]);

        Db::name('member_commissionlog')->insert([
            'aid'        => $aid,
            'mid'        => $mid,
            'frommid'    => 0,
            'commission' => $value,
            'after'      => $after,
            'createtime' => time(),
            'remark'     => $remark,
            'fhtype'     => 'collect_reward',
            'uid'        => 0,
            'fhid'       => $orderId,
        ]);
    }

    /**
     * 奖励文案（对齐 canzhao：券显示券名，其余显示数值）
     *
     * @param array<int|string, string> $couponNameMap
     */
    protected function rewardText(int $rewardType, mixed $reward, array $couponNameMap = []): string
    {
        if (2 === $rewardType) {
            return (string) ($couponNameMap[(int) $reward] ?? ('优惠券（面额' . number_format((float) $reward, 2) . '元）'));
        }

        return match ($rewardType) {
            1 => (string) (int) $reward . '积分',
            3 => '余额' . number_format((float) $reward, 2) . '元',
            4 => '佣金' . number_format((float) $reward, 2) . '元',
            default => (string) $reward,
        };
    }
}
