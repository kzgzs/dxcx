<?php

// P14-2 餐饮一期订单侧
// 补建 restaurant_remark 表（基线缺失）：餐饮预置常用备注（订单备注下拉候选）
// 对齐 canzhao restaurant_remark 口径：aid/bid 租户与商家隔离，content 备注内容，sort 排序

use think\migration\Migrator;

class RestaurantRemarkTable extends Migrator
{
    public function up()
    {
        $this->execute("CREATE TABLE IF NOT EXISTS `restaurant_remark` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) NOT NULL DEFAULT '0' COMMENT '账户ID',
  `bid` int(11) NOT NULL DEFAULT '0' COMMENT '商家ID 0表示平台',
  `content` varchar(255) NOT NULL DEFAULT '' COMMENT '备注内容',
  `sort` int(11) NOT NULL DEFAULT '0' COMMENT '排序 越大越靠前',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态 0停用 1启用',
  `createtime` int(11) DEFAULT NULL COMMENT '创建时间',
  `updatetime` int(11) DEFAULT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `aid` (`aid`),
  KEY `bid` (`bid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='餐饮预置常用备注'");
    }

    public function down()
    {
        $this->execute('DROP TABLE IF EXISTS `restaurant_remark`');
    }
}
