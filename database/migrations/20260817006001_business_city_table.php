<?php

declare(strict_types=1);

use think\migration\Migrator;

/**
 * 城市商户表（批次8 任务221）
 *
 * 点大 Business.php businesscity 为空壳方法，按其 ApiBusiness.php 城市链接
 * 过滤口径（province/city/district/status）自研落地：
 * 一行 = 一个城市入口链接，按 district > city > province 逐级匹配商户。
 * IF NOT EXISTS 幂等，兼容已有手工建表环境。
 */
class BusinessCityTable extends Migrator
{
    public function up(): void
    {
        $this->execute("CREATE TABLE IF NOT EXISTS `business_city` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) NOT NULL DEFAULT '0' COMMENT '租户ID',
  `name` varchar(255) NOT NULL DEFAULT '' COMMENT '城市名称',
  `province` varchar(100) NOT NULL DEFAULT '' COMMENT '省份',
  `city` varchar(100) NOT NULL DEFAULT '' COMMENT '城市',
  `district` varchar(100) NOT NULL DEFAULT '' COMMENT '区县',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态 1启用 0停用',
  `sort` int(11) NOT NULL DEFAULT '0' COMMENT '排序',
  `createtime` int(11) NOT NULL DEFAULT '0' COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `aid` (`aid`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='城市商户'");
    }
}
