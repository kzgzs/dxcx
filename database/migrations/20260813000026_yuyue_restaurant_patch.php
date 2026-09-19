<?php
// P6 行业扩展第二期：预约一期 + 餐饮堂食一期（旧表补列，幂等）
//
// 复用旧表（不改既有列语义，仅补列）：
// - 预约：yuyue_category / yuyue_product（cid=分类 fwlong=时长 yynum=时段容量）/
//         yuyue_worker（realname/tel/headimg/cid/`desc`）/ yuyue_order
// - 餐饮：restaurant_area / restaurant_table（cid=区域 seat=人数）/
//         restaurant_product_category / restaurant_product（cid=分类）/
//         restaurant_shop_order（tableid）/ restaurant_shop_order_goods
//
// 状态语义对齐（代码层统一口径，不改列默认值）：
//   yuyue_order.status / restaurant_shop_order.status
//   0待支付 1已支付(待核销/待出餐) 2已完成 4已取消 5已退款
//
// 支付走 pay_order 统一支付单：order_type 扩展 yuyue / restaurant（varchar(20) 够用）
// 补列统一命名 pay_amount/score_money/use_score/coupon_id 与网约车/酒店订单对齐（PayService::payBiz 读 pay_amount）

use think\migration\Migrator;

class YuyueRestaurantPatch extends Migrator
{
    public function change()
    {
        // ---------- yuyue_order 补列 ----------
        $this->addColumnIfMissing('yuyue_order', 'book_date', "ADD COLUMN `book_date` date DEFAULT NULL COMMENT '预约日期' AFTER `yy_time`");
        $this->addColumnIfMissing('yuyue_order', 'book_time', "ADD COLUMN `book_time` varchar(10) NOT NULL DEFAULT '' COMMENT '预约时段(如09:00)' AFTER `book_date`");
        $this->addColumnIfMissing('yuyue_order', 'pay_amount', "ADD COLUMN `pay_amount` decimal(11,2) NOT NULL DEFAULT '0.00' COMMENT '实付金额（元）' AFTER `coupon_money`");
        $this->addColumnIfMissing('yuyue_order', 'score_money', "ADD COLUMN `score_money` decimal(11,2) NOT NULL DEFAULT '0.00' COMMENT '积分抵扣金额（元）' AFTER `pay_amount`");
        $this->addColumnIfMissing('yuyue_order', 'use_score', "ADD COLUMN `use_score` int NOT NULL DEFAULT '0' COMMENT '抵扣使用积分数' AFTER `score_money`");
        $this->addColumnIfMissing('yuyue_order', 'coupon_id', "ADD COLUMN `coupon_id` int NOT NULL DEFAULT '0' COMMENT '使用的优惠券ID' AFTER `use_score`");
        $this->addIndexIfMissing('yuyue_order', 'idx_slot', "ADD INDEX `idx_slot` (`aid`,`proid`,`worker_id`,`book_date`,`book_time`)");

        // ---------- yuyue_product 补软删 ----------
        $this->addColumnIfMissing('yuyue_product', 'is_delete', "ADD COLUMN `is_delete` tinyint(1) NOT NULL DEFAULT '0' COMMENT '软删标记'");

        // ---------- restaurant_product 补软删 ----------
        $this->addColumnIfMissing('restaurant_product', 'is_delete', "ADD COLUMN `is_delete` tinyint(1) NOT NULL DEFAULT '0' COMMENT '软删标记'");

        // ---------- restaurant_area 补排序 ----------
        $this->addColumnIfMissing('restaurant_area', 'sort', "ADD COLUMN `sort` int NOT NULL DEFAULT '0' COMMENT '排序'");

        // ---------- restaurant_shop_order 补列 ----------
        $this->addColumnIfMissing('restaurant_shop_order', 'pay_amount', "ADD COLUMN `pay_amount` decimal(11,2) NOT NULL DEFAULT '0.00' COMMENT '实付金额（元）' AFTER `coupon_money`");
        $this->addColumnIfMissing('restaurant_shop_order', 'score_money', "ADD COLUMN `score_money` decimal(11,2) NOT NULL DEFAULT '0.00' COMMENT '积分抵扣金额（元）' AFTER `pay_amount`");
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

    /**
     * 索引不存在时补索引（幂等）
     */
    protected function addIndexIfMissing(string $table, string $index, string $alter): void
    {
        $exists = $this->fetchRow(
            "SELECT INDEX_NAME FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = '{$table}' AND index_name = '{$index}' LIMIT 1"
        );
        if ($exists) {
            return;
        }

        $this->execute("ALTER TABLE `{$table}` {$alter}");
    }
}
