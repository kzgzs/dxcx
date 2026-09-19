<?php

// 拼团补丁迁移（P13-3）：
// 1. 新建 collage_jiqilist（拼团机器人表，参照基线 lucky_collage_jiqilist 结构），
//    供拼团机器人补位使用（collage_order.isjiqiren/jiqirenid 关联预留）
// 2. collage_sysset 增加默认成团参数列：teamnum 默认成团人数 / teamhour 成团时限（小时）
//    / leadermoney 团长优惠金额（基线表仅有 pics/autoshdays/comment 等展示类字段）

use think\migration\Migrator;

class CollageJiqilistPatch extends Migrator
{
    public function up()
    {
        $this->execute('CREATE TABLE IF NOT EXISTS `collage_jiqilist` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) DEFAULT NULL,
  `nickname` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `headimg` varchar(255) DEFAULT NULL,
  `status` int(1) DEFAULT \'1\',
  `sort` int(11) DEFAULT \'1\',
  `createtime` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `aid` (`aid`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');

        $this->execute("ALTER TABLE `collage_sysset`
  ADD COLUMN `teamnum` int(11) NOT NULL DEFAULT '3' COMMENT '默认成团人数' AFTER `team_refund`,
  ADD COLUMN `teamhour` int(11) NOT NULL DEFAULT '24' COMMENT '默认成团时限（小时）' AFTER `teamnum`,
  ADD COLUMN `leadermoney` decimal(11,2) NOT NULL DEFAULT '0.00' COMMENT '默认团长优惠金额' AFTER `teamhour`");
    }

    public function down()
    {
        $this->execute('DROP TABLE IF EXISTS `collage_jiqilist`');
        $this->execute('ALTER TABLE `collage_sysset`
  DROP COLUMN `teamnum`,
  DROP COLUMN `teamhour`,
  DROP COLUMN `leadermoney`');
    }
}
