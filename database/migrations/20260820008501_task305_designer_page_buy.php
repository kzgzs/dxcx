<?php

declare(strict_types=1);

use think\migration\Migrator;

/**
 * 设计页付费查看购买记录表（任务305）
 *
 * - designer_page_buy：会员购买付费设计页记录（付费查看=是的页面，支付成功放行）
 *   走既有统一支付单链路：pay_order.order_type=pageview → PayService 入账 → status 0→1
 * IF NOT EXISTS 幂等，兼容已有手工建表环境。
 */
class Task305DesignerPageBuy extends Migrator
{
    public function up(): void
    {
        $this->execute("CREATE TABLE IF NOT EXISTS `designer_page_buy` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) NOT NULL DEFAULT '0' COMMENT '租户ID',
  `mid` int(11) NOT NULL DEFAULT '0' COMMENT '会员ID',
  `page_id` int(11) NOT NULL DEFAULT '0' COMMENT '设计页ID（designerpage.id）',
  `pay_amount` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '支付金额（元，下单时页面配置价固化）',
  `status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '状态 0待支付 1已支付',
  `pay_type` varchar(20) NOT NULL DEFAULT '' COMMENT '支付方式 wechat/alipay',
  `createtime` int(11) NOT NULL DEFAULT '0' COMMENT '下单时间',
  `paytime` int(11) NOT NULL DEFAULT '0' COMMENT '支付时间',
  PRIMARY KEY (`id`),
  KEY `aid_mid_page` (`aid`,`mid`,`page_id`) USING BTREE,
  KEY `page` (`page_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='设计页付费查看购买记录'");
    }
}
