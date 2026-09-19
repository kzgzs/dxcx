<?php

// P13-1 客户端营销：优惠券后台补全
// 补建 coupon_set 表（基线缺失）：租户级优惠券过期提醒设置
// 对齐 canzhao coupon_set 口径：expire_status 过期提醒开关 / expire_rules 提前N天提醒（逗号分隔）

use think\migration\Migrator;

class CouponSetTable extends Migrator
{
    public function up()
    {
        $this->execute("CREATE TABLE IF NOT EXISTS `coupon_set` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) NOT NULL DEFAULT '0' COMMENT '账户ID',
  `bid` int(11) NOT NULL DEFAULT '0' COMMENT '商家ID 0表示平台',
  `expire_status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '过期提醒开关 0关闭 1开启',
  `expire_rules` varchar(255) NOT NULL DEFAULT '' COMMENT '过期提醒规则：到期前N天提醒（逗号分隔）',
  `createtime` int(11) DEFAULT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `aid_bid` (`aid`,`bid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='优惠券过期提醒设置'");
    }

    public function down()
    {
        $this->execute('DROP TABLE IF EXISTS `coupon_set`');
    }
}
