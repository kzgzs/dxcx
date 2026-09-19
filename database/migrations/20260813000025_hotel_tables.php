<?php
// P6 行业扩展第一期（二）：酒店（幂等）
//
// 调研结论：旧系统酒店纯前端无表 → 全新建表
//
// 状态定义：hotel_order.status 0待支付 1已预订 2已入住 3已完成 4已取消 5已退款
// 房态规则（写进 HotelService 注释）：
//   available = room_num − 同房型、日期区间重叠（checkin_date<查询checkout 且 checkout_date>查询checkin）、
//   状态 NOT IN(4,5) 的订单数
// 计价：amount = nights × room.price；押金 deposit_total = room.deposit（本期随单收，退款一并退）
// 券门槛按房费 amount，积分抵扣走既有管道，实付下限 0.01

use think\migration\Migrator;

class HotelTables extends Migrator
{
    public function change()
    {
        // 酒店
        $exists = $this->fetchRow(
            "SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'hotel' LIMIT 1"
        );
        if (!$exists) {
            $this->execute("CREATE TABLE `hotel` (
                `id` int NOT NULL AUTO_INCREMENT,
                `aid` int NOT NULL DEFAULT '0' COMMENT '租户ID',
                `name` varchar(255) NOT NULL DEFAULT '' COMMENT '酒店名称',
                `star` tinyint NOT NULL DEFAULT '0' COMMENT '星级',
                `province` varchar(50) NOT NULL DEFAULT '' COMMENT '省',
                `city` varchar(50) NOT NULL DEFAULT '' COMMENT '市',
                `district` varchar(50) NOT NULL DEFAULT '' COMMENT '区',
                `address` varchar(255) NOT NULL DEFAULT '' COMMENT '详细地址',
                `lng` decimal(10,6) NOT NULL DEFAULT '0.000000' COMMENT '经度',
                `lat` decimal(10,6) NOT NULL DEFAULT '0.000000' COMMENT '纬度',
                `pic` varchar(255) NOT NULL DEFAULT '' COMMENT '封面图',
                `contacts` varchar(50) NOT NULL DEFAULT '' COMMENT '联系人',
                `phone` varchar(20) NOT NULL DEFAULT '' COMMENT '联系电话',
                `description` text COMMENT '酒店描述（desc为保留字）',
                `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态 1营业 0停业',
                `deleted` tinyint(1) NOT NULL DEFAULT '0' COMMENT '软删标记',
                `createtime` int NOT NULL DEFAULT '0' COMMENT '创建时间',
                PRIMARY KEY (`id`),
                KEY `idx_aid` (`aid`,`deleted`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='酒店'");
        }

        // 房型
        $exists = $this->fetchRow(
            "SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'hotel_room' LIMIT 1"
        );
        if (!$exists) {
            $this->execute("CREATE TABLE `hotel_room` (
                `id` int NOT NULL AUTO_INCREMENT,
                `aid` int NOT NULL DEFAULT '0' COMMENT '租户ID',
                `hotel_id` int NOT NULL DEFAULT '0' COMMENT '酒店ID',
                `name` varchar(100) NOT NULL DEFAULT '' COMMENT '房型名称',
                `bed_type` varchar(50) NOT NULL DEFAULT '' COMMENT '床型',
                `area` int NOT NULL DEFAULT '0' COMMENT '面积（㎡）',
                `price` decimal(11,2) NOT NULL DEFAULT '0.00' COMMENT '每晚房价（元）',
                `deposit` decimal(11,2) NOT NULL DEFAULT '0.00' COMMENT '押金（元/单）',
                `room_num` int NOT NULL DEFAULT '0' COMMENT '房间数量',
                `pic` varchar(255) NOT NULL DEFAULT '' COMMENT '房型图',
                `description` text COMMENT '房型描述',
                `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态 1上架 0下架',
                `deleted` tinyint(1) NOT NULL DEFAULT '0' COMMENT '软删标记',
                PRIMARY KEY (`id`),
                KEY `idx_hotel` (`hotel_id`,`deleted`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='酒店房型'");
        }

        // 酒店订单
        $exists = $this->fetchRow(
            "SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'hotel_order' LIMIT 1"
        );
        if (!$exists) {
            $this->execute("CREATE TABLE `hotel_order` (
                `id` int NOT NULL AUTO_INCREMENT,
                `aid` int NOT NULL DEFAULT '0' COMMENT '租户ID',
                `mid` int NOT NULL DEFAULT '0' COMMENT '会员ID',
                `order_sn` varchar(64) NOT NULL DEFAULT '' COMMENT '订单号',
                `hotel_id` int NOT NULL DEFAULT '0' COMMENT '酒店ID',
                `hotel_name` varchar(255) NOT NULL DEFAULT '' COMMENT '酒店名称快照',
                `room_id` int NOT NULL DEFAULT '0' COMMENT '房型ID',
                `room_name` varchar(100) NOT NULL DEFAULT '' COMMENT '房型名称快照',
                `checkin_date` date DEFAULT NULL COMMENT '入住日期',
                `checkout_date` date DEFAULT NULL COMMENT '离店日期',
                `nights` int NOT NULL DEFAULT '0' COMMENT '入住晚数',
                `amount` decimal(11,2) NOT NULL DEFAULT '0.00' COMMENT '房费金额（元）',
                `deposit_total` decimal(11,2) NOT NULL DEFAULT '0.00' COMMENT '押金金额（元，随单收）',
                `coupon_money` decimal(11,2) NOT NULL DEFAULT '0.00' COMMENT '券优惠金额（元）',
                `score_money` decimal(11,2) NOT NULL DEFAULT '0.00' COMMENT '积分抵扣金额（元）',
                `pay_amount` decimal(11,2) NOT NULL DEFAULT '0.00' COMMENT '实付金额（元，含押金）',
                `status` tinyint NOT NULL DEFAULT '0' COMMENT '状态 0待支付 1已预订 2已入住 3已完成 4已取消 5已退款',
                `name` varchar(50) NOT NULL DEFAULT '' COMMENT '入住人姓名',
                `phone` varchar(20) NOT NULL DEFAULT '' COMMENT '联系电话',
                `pay_time` int NOT NULL DEFAULT '0' COMMENT '支付时间戳',
                `coupon_id` int NOT NULL DEFAULT '0' COMMENT '使用的优惠券ID',
                `coupon_rid` int NOT NULL DEFAULT '0' COMMENT '券领取记录ID',
                `use_score` int NOT NULL DEFAULT '0' COMMENT '抵扣使用积分数',
                `createtime` int NOT NULL DEFAULT '0' COMMENT '创建时间',
                `deleted` tinyint(1) NOT NULL DEFAULT '0' COMMENT '软删标记',
                PRIMARY KEY (`id`),
                KEY `idx_aid_mid` (`aid`,`mid`),
                KEY `idx_room_date` (`room_id`,`status`,`checkin_date`,`checkout_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='酒店预订订单'");
        }
    }
}
