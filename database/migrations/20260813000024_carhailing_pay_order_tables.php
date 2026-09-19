<?php
// P6 行业扩展第一期（一）：网约车（租车预约）+ 支付泛化地基（幂等）
//
// 调研结论：旧系统网约车纯前端无表 → 全新建表
//
// 支付泛化方案（最小侵入）：
// - shop_order 现有支付链路（pay/handlePaySuccess/notifyByOrderSn）完全不动
// - 新增 pay_order 统一支付单服务 carhailing/hotel：out_trade_no 路由回调
// - coupon_record 补 order_type 列：券占用判定按订单类型查对应表（三类订单 ID 空间独立）
//
// 网约车计价规则（写进 CarhailingService 注释）：
// 时长=end_time-start_time（Unix 秒）：
//   <4h 拒绝；4h<=时长<24h 收 price_half（半天价）；>=24h 按天 ceil(时长/24h)×price_day
// 车辆并发校验：同期（日期区间重叠）未取消/未退款订单数 < car_num
// 券门槛按租金额（amount），积分抵扣走既有管道，实付下限 0.01

use think\migration\Migrator;

class CarhailingPayOrderTables extends Migrator
{
    public function change()
    {
        // 统一支付单（网约车/酒店；shop_order 沿用原链路不迁移）
        $exists = $this->fetchRow(
            "SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'pay_order' LIMIT 1"
        );
        if (!$exists) {
            $this->execute("CREATE TABLE `pay_order` (
                `id` int NOT NULL AUTO_INCREMENT,
                `aid` int NOT NULL DEFAULT '0' COMMENT '租户ID',
                `order_type` varchar(20) NOT NULL DEFAULT 'shop' COMMENT '业务订单类型 shop/carhailing/hotel',
                `biz_order_id` int NOT NULL DEFAULT '0' COMMENT '业务订单ID',
                `out_trade_no` varchar(64) NOT NULL DEFAULT '' COMMENT '商户支付单号',
                `pay_amount` decimal(11,2) NOT NULL DEFAULT '0.00' COMMENT '支付金额（元）',
                `pay_type` varchar(20) NOT NULL DEFAULT '' COMMENT '支付方式 wechat/alipay',
                `status` tinyint NOT NULL DEFAULT '0' COMMENT '状态 0待支付 1已支付',
                `pay_time` int NOT NULL DEFAULT '0' COMMENT '支付时间戳',
                `createtime` int NOT NULL DEFAULT '0' COMMENT '创建时间',
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_out_trade_no` (`out_trade_no`),
                KEY `idx_biz` (`order_type`,`biz_order_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='统一支付单（行业订单）'");
        }

        // 网约车车辆分类
        $exists = $this->fetchRow(
            "SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'carhailing_category' LIMIT 1"
        );
        if (!$exists) {
            $this->execute("CREATE TABLE `carhailing_category` (
                `id` int NOT NULL AUTO_INCREMENT,
                `aid` int NOT NULL DEFAULT '0' COMMENT '租户ID',
                `name` varchar(100) NOT NULL DEFAULT '' COMMENT '分类名称',
                `sort` int NOT NULL DEFAULT '0' COMMENT '排序（越大越靠前）',
                `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态 1启用 0停用',
                `deleted` tinyint(1) NOT NULL DEFAULT '0' COMMENT '软删标记',
                PRIMARY KEY (`id`),
                KEY `idx_aid` (`aid`,`deleted`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='网约车车辆分类'");
        }

        // 网约车车辆（商品）
        $exists = $this->fetchRow(
            "SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'carhailing_product' LIMIT 1"
        );
        if (!$exists) {
            $this->execute("CREATE TABLE `carhailing_product` (
                `id` int NOT NULL AUTO_INCREMENT,
                `aid` int NOT NULL DEFAULT '0' COMMENT '租户ID',
                `category_id` int NOT NULL DEFAULT '0' COMMENT '分类ID',
                `name` varchar(255) NOT NULL DEFAULT '' COMMENT '车辆名称',
                `pic` varchar(255) NOT NULL DEFAULT '' COMMENT '封面图',
                `pics` text COMMENT '图册（逗号分隔）',
                `price_day` decimal(11,2) NOT NULL DEFAULT '0.00' COMMENT '日租价（元/天）',
                `price_half` decimal(11,2) NOT NULL DEFAULT '0.00' COMMENT '半天价（元，4h~24h）',
                `car_num` int NOT NULL DEFAULT '0' COMMENT '车辆数（并发可租上限）',
                `description` text COMMENT '车辆描述',
                `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态 1上架 0下架',
                `deleted` tinyint(1) NOT NULL DEFAULT '0' COMMENT '软删标记',
                `createtime` int NOT NULL DEFAULT '0' COMMENT '创建时间',
                PRIMARY KEY (`id`),
                KEY `idx_aid_cat` (`aid`,`category_id`,`deleted`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='网约车车辆'");
        }

        // 网约车订单
        $exists = $this->fetchRow(
            "SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'carhailing_order' LIMIT 1"
        );
        if (!$exists) {
            $this->execute("CREATE TABLE `carhailing_order` (
                `id` int NOT NULL AUTO_INCREMENT,
                `aid` int NOT NULL DEFAULT '0' COMMENT '租户ID',
                `mid` int NOT NULL DEFAULT '0' COMMENT '会员ID',
                `order_sn` varchar(64) NOT NULL DEFAULT '' COMMENT '订单号',
                `product_id` int NOT NULL DEFAULT '0' COMMENT '车辆ID',
                `product_name` varchar(255) NOT NULL DEFAULT '' COMMENT '车辆名称快照',
                `pic` varchar(255) NOT NULL DEFAULT '' COMMENT '车辆图快照',
                `start_time` int NOT NULL DEFAULT '0' COMMENT '用车开始时间（Unix秒）',
                `end_time` int NOT NULL DEFAULT '0' COMMENT '用车结束时间（Unix秒）',
                `days` decimal(5,1) NOT NULL DEFAULT '0.0' COMMENT '计费单位数（1=一天 0.5=半天）',
                `amount` decimal(11,2) NOT NULL DEFAULT '0.00' COMMENT '租金金额（元）',
                `coupon_money` decimal(11,2) NOT NULL DEFAULT '0.00' COMMENT '券优惠金额（元）',
                `score_money` decimal(11,2) NOT NULL DEFAULT '0.00' COMMENT '积分抵扣金额（元）',
                `pay_amount` decimal(11,2) NOT NULL DEFAULT '0.00' COMMENT '实付金额（元）',
                `status` tinyint NOT NULL DEFAULT '0' COMMENT '状态 0待支付 1已支付 2使用中 3已完成 4已取消 5已退款',
                `pay_time` int NOT NULL DEFAULT '0' COMMENT '支付时间戳',
                `coupon_id` int NOT NULL DEFAULT '0' COMMENT '使用的优惠券ID',
                `coupon_rid` int NOT NULL DEFAULT '0' COMMENT '券领取记录ID',
                `use_score` int NOT NULL DEFAULT '0' COMMENT '抵扣使用积分数',
                `createtime` int NOT NULL DEFAULT '0' COMMENT '创建时间',
                `deleted` tinyint(1) NOT NULL DEFAULT '0' COMMENT '软删标记',
                PRIMARY KEY (`id`),
                KEY `idx_aid_mid` (`aid`,`mid`),
                KEY `idx_product` (`product_id`,`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='网约车租车订单'");
        }

        // coupon_record 补订单类型列（三类订单 ID 空间独立，占用判定需按类型查表）
        $row = $this->fetchRow(
            "SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'coupon_record' AND column_name = 'order_type' LIMIT 1"
        );
        if (!$row) {
            $this->execute("ALTER TABLE `coupon_record` ADD COLUMN `order_type` varchar(20) NOT NULL DEFAULT 'shop' COMMENT '占用订单类型 shop/carhailing/hotel' AFTER `order_id`");
        }
    }
}
