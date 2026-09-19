<?php
// 批次4-E 任务190：微信红包三表（对照点大 hongbao/hongbao_record/hongbao_codelist）
// C 端口径见 canzhao ApiHongbao::index：行锁 + minmoney~maxmoney 随机 + totalmoney 预算
// + pernum 限次 + needcode 抽奖码校验；发放走 wx_transfer mock 通道，失败落 status=2

use think\migration\Migrator;

class HongbaoTables extends Migrator
{
    public function change()
    {
        $this->execute('CREATE TABLE `hongbao` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) DEFAULT NULL,
  `name` varchar(255) DEFAULT \'\' COMMENT \'活动名称\',
  `sendname` varchar(100) DEFAULT \'\' COMMENT \'发送者名称\',
  `wishing` varchar(255) DEFAULT \'\' COMMENT \'祝福语\',
  `minmoney` decimal(11,2) DEFAULT \'1.00\' COMMENT \'最小红包金额\',
  `maxmoney` decimal(11,2) DEFAULT \'10.00\' COMMENT \'最大红包金额\',
  `totalmoney` decimal(11,2) DEFAULT \'0.00\' COMMENT \'发放总预算\',
  `givenmoney` decimal(11,2) DEFAULT \'0.00\' COMMENT \'已发放金额\',
  `pernum` int(11) DEFAULT \'1\' COMMENT \'每人限领次数\',
  `starttime` int(11) DEFAULT \'0\',
  `endtime` int(11) DEFAULT \'0\',
  `status` tinyint(1) DEFAULT \'0\' COMMENT \'0关闭 1开启\',
  `sharepic` varchar(255) DEFAULT \'\' COMMENT \'分享图片\',
  `gettj` varchar(255) DEFAULT \'-1\' COMMENT \'参与条件 -1不限制\',
  `needcode` tinyint(1) DEFAULT \'0\' COMMENT \'是否需要抽奖码\',
  `createtime` int(11) DEFAULT NULL,
  `updatetime` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `aid` (`aid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT=\'微信红包活动\'');
        $this->execute('CREATE TABLE `hongbao_record` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) DEFAULT NULL,
  `hid` int(11) DEFAULT \'0\' COMMENT \'活动id\',
  `name` varchar(255) DEFAULT \'\' COMMENT \'活动名称快照\',
  `mid` int(11) DEFAULT NULL,
  `headimg` varchar(255) DEFAULT \'\',
  `nickname` varchar(255) DEFAULT \'\',
  `money` decimal(11,2) DEFAULT \'0.00\' COMMENT \'领取金额\',
  `createtime` int(11) DEFAULT NULL,
  `status` tinyint(1) DEFAULT \'0\' COMMENT \'0未发放 1发放成功 2发放失败\',
  `remark` varchar(255) DEFAULT \'\' COMMENT \'发放说明\',
  PRIMARY KEY (`id`),
  KEY `aid` (`aid`),
  KEY `hid` (`hid`),
  KEY `mid` (`mid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT=\'微信红包领取记录\'');
        $this->execute('CREATE TABLE `hongbao_codelist` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) DEFAULT NULL,
  `hid` int(11) DEFAULT \'0\' COMMENT \'活动id\',
  `code` varchar(100) DEFAULT \'\' COMMENT \'抽奖码\',
  `status` tinyint(1) DEFAULT \'0\' COMMENT \'0未使用 1已使用\',
  `mid` int(11) DEFAULT NULL COMMENT \'使用者\',
  `headimg` varchar(255) DEFAULT \'\',
  `nickname` varchar(255) DEFAULT \'\',
  `money` decimal(11,2) DEFAULT \'0.00\' COMMENT \'领取金额\',
  `usetime` int(11) DEFAULT NULL,
  `createtime` int(11) DEFAULT NULL,
  `remark` varchar(255) DEFAULT \'\',
  PRIMARY KEY (`id`),
  KEY `aid` (`aid`),
  KEY `hid` (`hid`),
  KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT=\'微信红包抽奖码\'');
    }
}
