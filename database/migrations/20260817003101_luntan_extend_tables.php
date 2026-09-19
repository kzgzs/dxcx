<?php
// 批次5-B 任务195：用户论坛扩展（对照点大 luntan_category 扩展字段 + luntan_give_couponlog）
// 扩展字段：goods_status 挂商品开关 / post_pay+post_score 发帖收积分 / top_options 付费置顶套餐
// coupon_ids+starttime+endtime+limitnum 发帖赠券（限时限量）；另建置顶支付订单表 luntan_order

use think\migration\Migrator;

class LuntanExtendTables extends Migrator
{
    public function change()
    {
        $this->execute("ALTER TABLE `luntan_category`
  ADD COLUMN `goods_status` tinyint(1) DEFAULT '0' COMMENT '挂载商品开关 0关闭 1开启',
  ADD COLUMN `post_pay` tinyint(1) DEFAULT '0' COMMENT '发帖收费开关 0关闭 1开启',
  ADD COLUMN `post_score` int(11) DEFAULT '0' COMMENT '发帖收取积分',
  ADD COLUMN `top_options` text COMMENT '付费置顶套餐 JSON [{hour,price}]',
  ADD COLUMN `coupon_ids` varchar(500) DEFAULT '' COMMENT '发帖赠券ID 逗号分隔',
  ADD COLUMN `starttime` int(11) DEFAULT '0' COMMENT '赠券开始时间',
  ADD COLUMN `endtime` int(11) DEFAULT '0' COMMENT '赠券结束时间',
  ADD COLUMN `limitnum` int(11) DEFAULT '0' COMMENT '每人赠券限次 0不限'");

        $this->execute('CREATE TABLE `luntan_give_couponlog` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) DEFAULT NULL,
  `mid` int(11) DEFAULT NULL,
  `cateid` int(11) DEFAULT \'0\' COMMENT \'论坛版块ID\',
  `couponid` varchar(500) DEFAULT \'\' COMMENT \'赠券ID快照\',
  `createtime` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `aid` (`aid`),
  KEY `mid` (`mid`),
  KEY `cateid` (`cateid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT=\'论坛发帖赠券记录\'');

        $this->execute('CREATE TABLE `luntan_order` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) DEFAULT NULL,
  `mid` int(11) DEFAULT NULL,
  `luntan_id` int(11) DEFAULT \'0\' COMMENT \'帖子ID\',
  `title` varchar(255) DEFAULT \'\' COMMENT \'订单标题\',
  `ordernum` varchar(64) DEFAULT \'\' COMMENT \'订单号\',
  `product_price` decimal(11,2) DEFAULT \'0.00\' COMMENT \'套餐单价\',
  `totalprice` decimal(11,2) DEFAULT \'0.00\' COMMENT \'应付金额\',
  `top_hour` int(11) DEFAULT \'0\' COMMENT \'置顶时长（小时）\',
  `top_expire_time` int(11) DEFAULT \'0\' COMMENT \'置顶到期时间\',
  `status` tinyint(1) DEFAULT \'0\' COMMENT \'0待支付 1已支付 2已取消\',
  `paytime` int(11) DEFAULT \'0\',
  `paytype` varchar(20) DEFAULT \'\',
  `createtime` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `aid` (`aid`),
  KEY `mid` (`mid`),
  KEY `luntan_id` (`luntan_id`),
  KEY `ordernum` (`ordernum`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT=\'论坛置顶支付订单\'');
    }
}
