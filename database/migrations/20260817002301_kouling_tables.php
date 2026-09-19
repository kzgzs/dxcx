<?php
// 批次4-E 任务190：口令/谜语游戏五表（对照点大 kouling/kouling_set/kouling_record/riddle/riddle_record）
// C 端口径见 canzhao ApiCoupon::kouling/riddle：name 精确匹配 + 时间段 + status=1 + gettj 人群
// + num/zjnum 总量 + perday/pertotal 每人限次 + give_coupon/coupon_ids 发券 + money/score 快照

use think\migration\Migrator;

class KoulingTables extends Migrator
{
    public function change()
    {
        $this->execute('CREATE TABLE `kouling_set` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) DEFAULT NULL,
  `bid` int(11) DEFAULT \'0\',
  `title` varchar(255) DEFAULT \'\' COMMENT \'页面标题\',
  `bgpic` varchar(255) DEFAULT \'\' COMMENT \'背景图\',
  `guize` text COMMENT \'活动规则\',
  `status` tinyint(1) DEFAULT \'0\' COMMENT \'0关闭 1开启\',
  `createtime` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `aid` (`aid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT=\'口令游戏页面设置\'');
        $this->execute('CREATE TABLE `kouling` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) DEFAULT NULL,
  `bid` int(11) DEFAULT \'0\',
  `name` varchar(255) DEFAULT NULL COMMENT \'口令（精确匹配）\',
  `starttime` int(11) DEFAULT \'0\',
  `endtime` int(11) DEFAULT \'0\',
  `status` tinyint(1) DEFAULT \'0\' COMMENT \'0关闭 1开启\',
  `gettj` varchar(255) DEFAULT \'-1\' COMMENT \'参与条件 -1不限制\',
  `num` int(11) DEFAULT \'0\' COMMENT \'奖品总量 0不限\',
  `zjnum` int(11) DEFAULT \'0\' COMMENT \'已领取数\',
  `perday` int(11) DEFAULT \'0\' COMMENT \'每人每日上限 0不限\',
  `pertotal` int(11) DEFAULT \'0\' COMMENT \'每人总上限 0不限\',
  `give_coupon` tinyint(1) DEFAULT \'0\' COMMENT \'是否赠券\',
  `coupon_ids` varchar(255) DEFAULT \'\' COMMENT \'赠券id逗号分隔\',
  `money` decimal(11,2) DEFAULT \'0.00\' COMMENT \'奖励余额快照\',
  `score` int(11) DEFAULT \'0\' COMMENT \'奖励积分快照\',
  `createtime` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `aid` (`aid`),
  KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT=\'口令活动\'');
        $this->execute('CREATE TABLE `kouling_record` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) DEFAULT NULL,
  `bid` int(11) DEFAULT \'0\',
  `objid` int(11) DEFAULT \'0\' COMMENT \'口令活动id\',
  `name` varchar(255) DEFAULT \'\' COMMENT \'口令快照\',
  `mid` int(11) DEFAULT NULL,
  `headimg` varchar(255) DEFAULT \'\',
  `nickname` varchar(255) DEFAULT \'\',
  `coupon_ids` varchar(255) DEFAULT \'\',
  `money` decimal(11,2) DEFAULT \'0.00\',
  `score` int(11) DEFAULT \'0\',
  `createtime` int(11) DEFAULT NULL,
  `createdate` varchar(20) DEFAULT NULL COMMENT \'Y-m-d 每日限次口径\',
  `status` tinyint(1) DEFAULT \'1\',
  PRIMARY KEY (`id`),
  KEY `aid` (`aid`),
  KEY `objid` (`objid`),
  KEY `mid` (`mid`),
  KEY `createdate` (`createdate`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT=\'口令兑换记录\'');
        $this->execute('CREATE TABLE `riddle` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) DEFAULT NULL,
  `bid` int(11) DEFAULT \'0\',
  `title` varchar(255) DEFAULT \'\' COMMENT \'谜面标题\',
  `alias` varchar(100) DEFAULT \'谜底\' COMMENT \'答案称谓\',
  `content` text COMMENT \'谜面内容\',
  `name` varchar(255) DEFAULT NULL COMMENT \'谜底（精确匹配）\',
  `starttime` int(11) DEFAULT \'0\',
  `endtime` int(11) DEFAULT \'0\',
  `status` tinyint(1) DEFAULT \'0\' COMMENT \'0关闭 1开启\',
  `gettj` varchar(255) DEFAULT \'-1\' COMMENT \'参与条件 -1不限制\',
  `num` int(11) DEFAULT \'0\' COMMENT \'奖品总量 0不限\',
  `zjnum` int(11) DEFAULT \'0\' COMMENT \'已领取数\',
  `perday` int(11) DEFAULT \'0\' COMMENT \'每人每日上限 0不限\',
  `pertotal` int(11) DEFAULT \'0\' COMMENT \'每人总上限 0不限\',
  `give_coupon` tinyint(1) DEFAULT \'0\' COMMENT \'是否赠券\',
  `coupon_ids` varchar(255) DEFAULT \'\' COMMENT \'赠券id逗号分隔\',
  `money` decimal(11,2) DEFAULT \'0.00\' COMMENT \'奖励余额快照\',
  `score` int(11) DEFAULT \'0\' COMMENT \'奖励积分快照\',
  `createtime` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `aid` (`aid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT=\'谜语活动\'');
        $this->execute('CREATE TABLE `riddle_record` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) DEFAULT NULL,
  `bid` int(11) DEFAULT \'0\',
  `objid` int(11) DEFAULT \'0\' COMMENT \'谜语活动id\',
  `name` varchar(255) DEFAULT \'\' COMMENT \'谜底快照\',
  `mid` int(11) DEFAULT NULL,
  `headimg` varchar(255) DEFAULT \'\',
  `nickname` varchar(255) DEFAULT \'\',
  `coupon_ids` varchar(255) DEFAULT \'\',
  `money` decimal(11,2) DEFAULT \'0.00\',
  `score` int(11) DEFAULT \'0\',
  `createtime` int(11) DEFAULT NULL,
  `createdate` varchar(20) DEFAULT NULL COMMENT \'Y-m-d 每日限次口径\',
  `status` tinyint(1) DEFAULT \'1\',
  PRIMARY KEY (`id`),
  KEY `aid` (`aid`),
  KEY `objid` (`objid`),
  KEY `mid` (`mid`),
  KEY `createdate` (`createdate`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT=\'谜语竞猜记录\'');
    }
}
