<?php

// 批次6 T6-0 基建：餐饮深水区字段补丁（INFORMATION_SCHEMA 判重，幂等）
//
// 补丁口径对齐 canzhao（点大参考源码，只读）：
// - restaurant_shop_order        ← upgrade_c1.php:5087（cashdesk_id/remove_zero/remove_zero_length/moling_money/uid）
//                                   +10507（is_bar_table_order）+10510（pickup_number）+11249（service_money）
// - restaurant_shop_order_goods  ← upgrade_c1.php:5095（is_gj/protype）+9956（njlprice）+9959（njltitle）
//                                   + upgrade_c2.php:2057（remark，新系统基线已有，判重跳过）
// - restaurant_shop_sysset       ← upgrade_c1.php:10504（start_pickup_number）+ upgrade_c2.php:646（take_food_number_prefix 默认 D）
// - restaurant_table             ← upgrade_c1.php:11242（minprice/service_fee_type/service_fee）
// - restaurant_deposit_order     ← upgrade_c2.php:11054（position_id/position_name/number_id/number_name/tableid）
// - restaurant_booking_order     ← pay_amount/score_money（接 PayService::payBiz 必需，
//                                   写法参照 20260813000026 对 shop_order 的同类补丁）

use think\migration\Migrator;

class RestaurantBatch6Columns extends Migrator
{
    public function change()
    {
        // ---------- restaurant_shop_order 补列（收银台/吧台/取餐号/服务费） ----------
        $this->addColumnIfMissing('restaurant_shop_order', 'cashdesk_id', "ADD COLUMN `cashdesk_id` int(11) DEFAULT '0' COMMENT '收银台ID'");
        $this->addColumnIfMissing('restaurant_shop_order', 'uid', "ADD COLUMN `uid` int(11) DEFAULT '0' COMMENT '收银员id'");
        $this->addColumnIfMissing('restaurant_shop_order', 'remove_zero', "ADD COLUMN `remove_zero` tinyint(1) DEFAULT '0' COMMENT '抹零'");
        $this->addColumnIfMissing('restaurant_shop_order', 'remove_zero_length', "ADD COLUMN `remove_zero_length` tinyint(4) DEFAULT '0' COMMENT '抹零位数'");
        $this->addColumnIfMissing('restaurant_shop_order', 'moling_money', "ADD COLUMN `moling_money` decimal(10,2) DEFAULT NULL COMMENT '抹零钱数'");
        $this->addColumnIfMissing('restaurant_shop_order', 'is_bar_table_order', "ADD COLUMN `is_bar_table_order` tinyint(1) DEFAULT '0' COMMENT '是否吧台订单'");
        $this->addColumnIfMissing('restaurant_shop_order', 'pickup_number', "ADD COLUMN `pickup_number` varchar(255) DEFAULT NULL COMMENT '取餐号'");
        $this->addColumnIfMissing('restaurant_shop_order', 'service_money', "ADD COLUMN `service_money` decimal(11,2) DEFAULT '0.00' COMMENT '不满金额服务费'");

        // ---------- restaurant_shop_order_goods 补列（改价/直接收款/加料/备注） ----------
        $this->addColumnIfMissing('restaurant_shop_order_goods', 'is_gj', "ADD COLUMN `is_gj` tinyint(1) DEFAULT '0' COMMENT '0：未改价 1：改价'");
        $this->addColumnIfMissing('restaurant_shop_order_goods', 'protype', "ADD COLUMN `protype` tinyint(2) DEFAULT '1' COMMENT '1商品 2直接收款（proid=-99)'");
        $this->addColumnIfMissing('restaurant_shop_order_goods', 'njlprice', "ADD COLUMN `njlprice` decimal(10,2) DEFAULT '0.00' COMMENT '加料金额'");
        $this->addColumnIfMissing('restaurant_shop_order_goods', 'njltitle', "ADD COLUMN `njltitle` varchar(255) DEFAULT NULL COMMENT '加料名称'");
        $this->addColumnIfMissing('restaurant_shop_order_goods', 'remark', "ADD COLUMN `remark` varchar(100) DEFAULT NULL COMMENT '备注'");

        // ---------- restaurant_shop_sysset 补列（取餐号起始/前缀） ----------
        $this->addColumnIfMissing('restaurant_shop_sysset', 'start_pickup_number', "ADD COLUMN `start_pickup_number` int(11) DEFAULT '0' COMMENT '取餐号开始号'");
        $this->addColumnIfMissing('restaurant_shop_sysset', 'take_food_number_prefix', "ADD COLUMN `take_food_number_prefix` varchar(10) DEFAULT 'D' COMMENT '取餐号前缀'");

        // ---------- restaurant_table 补列（最低消费/服务费） ----------
        $this->addColumnIfMissing('restaurant_table', 'minprice', "ADD COLUMN `minprice` decimal(11,2) DEFAULT '0.00' COMMENT '最低消费'");
        $this->addColumnIfMissing('restaurant_table', 'service_fee_type', "ADD COLUMN `service_fee_type` tinyint(1) DEFAULT '0' COMMENT '服务费类型 0固定金额 1：应付金额百分比'");
        $this->addColumnIfMissing('restaurant_table', 'service_fee', "ADD COLUMN `service_fee` decimal(10,2) DEFAULT '0.00' COMMENT '服务费或比例'");

        // ---------- restaurant_deposit_order 补列（寄存位置/编号/桌台） ----------
        $this->addColumnIfMissing('restaurant_deposit_order', 'position_id', "ADD COLUMN `position_id` int(11) DEFAULT '0' COMMENT '位置id'");
        $this->addColumnIfMissing('restaurant_deposit_order', 'position_name', "ADD COLUMN `position_name` varchar(255) DEFAULT NULL COMMENT '位置名称'");
        $this->addColumnIfMissing('restaurant_deposit_order', 'number_id', "ADD COLUMN `number_id` int(11) DEFAULT '0' COMMENT '编号id'");
        $this->addColumnIfMissing('restaurant_deposit_order', 'number_name', "ADD COLUMN `number_name` varchar(255) DEFAULT NULL COMMENT '编号'");
        $this->addColumnIfMissing('restaurant_deposit_order', 'tableid', "ADD COLUMN `tableid` int(11) DEFAULT '0' COMMENT '桌台id,0吧台'");

        // ---------- restaurant_booking_order 补列（PayService::payBiz 读 pay_amount） ----------
        $this->addColumnIfMissing('restaurant_booking_order', 'pay_amount', "ADD COLUMN `pay_amount` decimal(11,2) NOT NULL DEFAULT '0.00' COMMENT '实付金额（元）' AFTER `coupon_money`");
        $this->addColumnIfMissing('restaurant_booking_order', 'score_money', "ADD COLUMN `score_money` decimal(11,2) NOT NULL DEFAULT '0.00' COMMENT '积分抵扣金额（元）' AFTER `pay_amount`");
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
