<?php

declare(strict_types=1);

namespace app\common\service;

use think\facade\Db;

/**
 * 餐饮订单调度服务（P14-2 餐饮一期订单侧）
 *
 * 供 schedule:run 挂接的两项扫描任务（均幂等可重跑）：
 * - closeExpiredTakeaway() 外卖未支付超时关单：阈值读租户 restaurant_admin_set.takeaway_autoclose（分钟，默认 60）
 * - autoFinishShop()       堂食已支付超天自动完成：阈值读租户 restaurant_shop_sysset.autoshdays（天，默认 1，status 1 → 3）
 *
 * 状态口径（对齐参考系统）：
 * - restaurant_takeaway_order.status：0未支付 1已支付 12接单 2发货 3完成 4关闭
 * - restaurant_shop_order.status：0未支付 1已支付 3已完成 4关闭
 */
class RestaurantOrderService
{
    /** 外卖自动关单默认分钟数（租户未配置时的兜底） */
    public const TAKEAWAY_AUTOCLOSE_DEFAULT = 60;

    /** 堂食自动完成默认天数（租户未配置时的兜底） */
    public const SHOP_AUTOFINISH_DAYS_DEFAULT = 1;

    public function __construct(protected CouponService $couponService, protected ScoreService $scoreService)
    {
    }

    /**
     * 外卖未支付超时关单（按租户阈值逐租户扫描，单笔事务幂等）
     *
     * @return int 关单笔数
     */
    public function closeExpiredTakeaway(int $batch = 200): int
    {
        $sets = Db::name('restaurant_admin_set')
            ->field('aid, takeaway_autoclose')
            ->select()
            ->toArray();

        $closed = 0;

        foreach ($sets as $set) {
            $aid     = (int) ($set['aid'] ?? 0);
            $minutes = (int) ($set['takeaway_autoclose'] ?? 0);

            if ($aid <= 0 || $minutes <= 0) {
                $minutes = self::TAKEAWAY_AUTOCLOSE_DEFAULT;
            }

            if ($aid <= 0) {
                continue;
            }

            $cutoff = time() - $minutes * 60;

            $ids = Db::name('restaurant_takeaway_order')
                ->where('aid', $aid)
                ->where('status', 0)
                ->where('delete', 0)
                ->where('createtime', '<', $cutoff)
                ->where('createtime', '>', 0)
                ->order('id', 'asc')
                ->limit($batch)
                ->column('id');

            foreach ($ids as $orderId) {
                if ($this->closeTakeawayInternal((int) $orderId)) {
                    ++$closed;
                }
            }
        }

        return $closed;
    }

    /**
     * 堂食已支付超天自动完成（status=1 → 3；按租户 autoshdays 阈值）
     *
     * @return int 完成笔数
     */
    public function autoFinishShop(int $batch = 500): int
    {
        $syssets = Db::name('restaurant_shop_sysset')
            ->field('aid, autoshdays')
            ->select()
            ->toArray();

        // 租户阈值表（未配置的租户走默认天数）
        $daysOf = [];

        foreach ($syssets as $row) {
            $aid = (int) ($row['aid'] ?? 0);

            if ($aid > 0) {
                $days = (int) ($row['autoshdays'] ?? 0);
                $daysOf[$aid] = $days > 0 ? $days : self::SHOP_AUTOFINISH_DAYS_DEFAULT;
            }
        }

        $rows = Db::name('restaurant_shop_order')
            ->where('status', 1)
            ->where('delete', 0)
            ->where('paytime', '>', 0)
            ->field('id, aid, paytime')
            ->order('id', 'asc')
            ->limit($batch)
            ->select()
            ->toArray();

        $finished = 0;
        $now      = time();

        foreach ($rows as $row) {
            $aid  = (int) ($row['aid'] ?? 0);
            $days = $daysOf[$aid] ?? self::SHOP_AUTOFINISH_DAYS_DEFAULT;

            if ((int) $row['paytime'] + $days * 86400 > $now) {
                continue; // 未到自动完成时限
            }

            // 条件更新幂等：仅 status=1 时置 3
            $affected = Db::name('restaurant_shop_order')
                ->where('id', (int) $row['id'])
                ->where('status', 1)
                ->update(['status' => 3]);

            if (1 === $affected) {
                ++$finished;
            }
        }

        return $finished;
    }

    /**
     * 单笔外卖关单（事务：status=0 条件更新 → 释放券 + 返还抵扣积分）
     *
     * @return bool true=本次关单 false=状态已变化（幂等跳过）
     */
    protected function closeTakeawayInternal(int $orderId): bool
    {
        return (bool) Db::transaction(function () use ($orderId) {
            $order = Db::name('restaurant_takeaway_order')
                ->where('id', $orderId)
                ->lock(true)
                ->find();

            if (empty($order) || 0 !== (int) $order['status']) {
                return false; // 已支付/已关闭等：幂等跳过
            }

            $affected = Db::name('restaurant_takeaway_order')
                ->where('id', $orderId)
                ->where('status', 0)
                ->update(['status' => 4]);

            if (1 !== $affected) {
                return false;
            }

            // 释放券预锁定（未核销仅清占用）
            $this->couponService->releaseByOrder((int) ($order['coupon_rid'] ?? 0), $orderId);

            // 返还抵扣积分
            if ((int) ($order['scoredkscore'] ?? 0) > 0) {
                $this->scoreService->refundForCancel(
                    (int) ($order['aid'] ?? 0),
                    (int) ($order['mid'] ?? 0),
                    (int) $order['scoredkscore'],
                    '超时关单返还 外卖订单' . (string) ($order['ordernum'] ?? '')
                );
            }

            return true;
        });
    }
}
