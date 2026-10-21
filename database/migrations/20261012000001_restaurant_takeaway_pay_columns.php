<?php

// 外卖订单补列：接入统一支付管道（PayService::payBiz 读 pay_amount）
//
// 背景（对照 canzhao 点大参考源码，只读）：
// - restaurant_shop_order / restaurant_booking_order / yuyue_order / kecheng_order
//   均已补 pay_amount/score_money（见 20260813000026、20260817004002、20260817003301），
//   唯独 restaurant_takeaway_order 漏补，导致外卖订单无法走 PayService::payBiz，
//   后台「外卖订单」页因无真实数据来源而始终为空。
// - 本补丁把外卖订单表对齐同一命名口径，使 C 端外卖下单 → 支付入账 → 后台订单管理闭环。
//
// 口径说明：
// - pay_amount  实付金额（元），下单时固化，与 totalprice 一致；改价时同步更新
// - score_money 积分抵扣金额（元），与 scoredk_money 同义，统一到 pay_amount 之后的命名位置
// - delete      软删标记：参考系统 del() 为物理删除，本项目统一走软删（列表 where delete=0）
//
// INFORMATION_SCHEMA 判重，幂等可重跑。

use think\migration\Migrator;

class RestaurantTakeawayPayColumns extends Migrator
{
    public function change()
    {
        // ---------- restaurant_takeaway_order 补列（PayService::payBiz 读 pay_amount） ----------
        $this->addColumnIfMissing('restaurant_takeaway_order', 'pay_amount', "ADD COLUMN `pay_amount` decimal(11,2) NOT NULL DEFAULT '0.00' COMMENT '实付金额（元）' AFTER `coupon_money`");
        $this->addColumnIfMissing('restaurant_takeaway_order', 'score_money', "ADD COLUMN `score_money` decimal(11,2) NOT NULL DEFAULT '0.00' COMMENT '积分抵扣金额（元）' AFTER `pay_amount`");

        // 存量订单回填：pay_amount 缺失时按 totalprice 补，避免历史单支付金额为 0 无法退款
        $this->execute("UPDATE `restaurant_takeaway_order` SET `pay_amount` = `totalprice` WHERE (`pay_amount` IS NULL OR `pay_amount` = 0) AND `totalprice` > 0");
        $this->execute("UPDATE `restaurant_takeaway_order` SET `score_money` = `scoredk_money` WHERE (`score_money` IS NULL OR `score_money` = 0) AND `scoredk_money` > 0");
    }

    /**
     * 列不存在时补列（幂等）
     */
    protected function addColumnIfMissing(string $table, string $column, string $alter): void
    {
        $exists = $this->fetchRow(
            "SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = '{$table}' AND column_name = '{$column}' LIMIT 1"
        );
        if ($exists) {
            return;
        }

        $this->execute("ALTER TABLE `{$table}` {$alter}");
    }
}
