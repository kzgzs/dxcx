<?php
// GENERATED-BY sql2migration （一次性基线生成，勿手工逐行编辑）
// 基线迁移[1/14] 卡密核销（*_codelist/hexiao）
// 共 9 张表：collage_codelist, hexiao_order, kanjia_codelist, lipin_codelist, lucky_collage_codelist, scoreshop_codelist, seckill_codelist, shop_codelist, tuangou_codelist
// 清洗：去 ddwx_ 前缀 / utf8mb4+utf8mb4_general_ci / 去 ZEROFILL / AUTO_INCREMENT 归 1 / 去 dump 条件注释

use think\migration\Migrator;

class CardcodeHexiaoTables extends Migrator
{
    public function change()
    {
        $this->execute('CREATE TABLE `collage_codelist` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) DEFAULT NULL,
  `bid` int(11) DEFAULT \'0\',
  `proid` int(11) DEFAULT NULL,
  `content` text,
  `ordernum` varchar(100) DEFAULT NULL,
  `orderid` int(11) DEFAULT NULL,
  `headimg` varchar(255) DEFAULT NULL,
  `nickname` varchar(255) CHARACTER SET utf8mb4 DEFAULT NULL,
  `createtime` int(11) DEFAULT NULL,
  `buytime` int(11) DEFAULT NULL,
  `status` tinyint(1) DEFAULT \'0\',
  PRIMARY KEY (`id`),
  KEY `aid` (`aid`) USING BTREE,
  KEY `bid` (`bid`) USING BTREE,
  KEY `proid` (`proid`) USING BTREE,
  KEY `status` (`status`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');
        $this->execute('CREATE TABLE `hexiao_order` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) DEFAULT NULL,
  `bid` int(11) DEFAULT \'0\',
  `uid` int(11) DEFAULT NULL,
  `mid` varchar(100) DEFAULT NULL,
  `orderid` int(11) DEFAULT NULL,
  `ordernum` varchar(100) DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `createtime` int(11) DEFAULT NULL,
  `remark` varchar(255) DEFAULT NULL,
  `type` varchar(255) DEFAULT NULL,
  `mdid` int(11) DEFAULT \'0\',
  PRIMARY KEY (`id`),
  KEY `aid` (`aid`) USING BTREE,
  KEY `bid` (`bid`) USING BTREE,
  KEY `uid` (`uid`) USING BTREE,
  KEY `mid` (`mid`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');
        $this->execute('CREATE TABLE `kanjia_codelist` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) DEFAULT NULL,
  `bid` int(11) DEFAULT \'0\',
  `proid` int(11) DEFAULT NULL,
  `content` text,
  `ordernum` varchar(100) DEFAULT NULL,
  `orderid` int(11) DEFAULT NULL,
  `headimg` varchar(255) DEFAULT NULL,
  `nickname` varchar(255) CHARACTER SET utf8mb4 DEFAULT NULL,
  `createtime` int(11) DEFAULT NULL,
  `buytime` int(11) DEFAULT NULL,
  `status` tinyint(1) DEFAULT \'0\',
  PRIMARY KEY (`id`),
  KEY `aid` (`aid`) USING BTREE,
  KEY `bid` (`bid`) USING BTREE,
  KEY `status` (`status`) USING BTREE,
  KEY `proid` (`proid`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');
        $this->execute('CREATE TABLE `lipin_codelist` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) DEFAULT NULL,
  `hid` int(11) DEFAULT NULL,
  `cardno` varchar(30) DEFAULT NULL,
  `code` varchar(100) DEFAULT NULL,
  `qr` varchar(255) DEFAULT NULL,
  `status` tinyint(1) DEFAULT \'0\',
  `createtime` int(11) DEFAULT NULL,
  `saletime` int(11) DEFAULT NULL,
  `usetime` int(11) DEFAULT NULL,
  `mid` int(11) DEFAULT NULL,
  `headimg` varchar(255) DEFAULT NULL,
  `nickname` varchar(255) CHARACTER SET utf8mb4 DEFAULT NULL,
  `remark` varchar(255) DEFAULT NULL,
  `sale_mdid` int(11) DEFAULT NULL,
  `hexiao_mdid` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `hid_2` (`aid`,`cardno`),
  KEY `aid` (`aid`) USING BTREE,
  KEY `hid` (`hid`) USING BTREE,
  KEY `cardno` (`cardno`) USING BTREE,
  KEY `code` (`code`) USING BTREE,
  KEY `sale_mdid` (`sale_mdid`) USING BTREE,
  KEY `hexiao_mdid` (`hexiao_mdid`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');
        $this->execute('CREATE TABLE `lucky_collage_codelist` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) DEFAULT NULL,
  `bid` int(11) DEFAULT \'0\',
  `proid` int(11) DEFAULT NULL,
  `content` text,
  `ordernum` varchar(100) DEFAULT NULL,
  `orderid` int(11) DEFAULT NULL,
  `headimg` varchar(255) DEFAULT NULL,
  `nickname` varchar(255) CHARACTER SET utf8mb4 DEFAULT NULL,
  `createtime` int(11) DEFAULT NULL,
  `buytime` int(11) DEFAULT NULL,
  `status` tinyint(1) DEFAULT \'0\',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `aid` (`aid`) USING BTREE,
  KEY `bid` (`bid`) USING BTREE,
  KEY `proid` (`proid`) USING BTREE,
  KEY `status` (`status`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');
        $this->execute('CREATE TABLE `scoreshop_codelist` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) DEFAULT NULL,
  `proid` int(11) DEFAULT NULL,
  `content` text,
  `ordernum` varchar(100) DEFAULT NULL,
  `orderid` int(11) DEFAULT NULL,
  `headimg` varchar(255) DEFAULT NULL,
  `nickname` varchar(255) CHARACTER SET utf8mb4 DEFAULT NULL,
  `createtime` int(11) DEFAULT NULL,
  `buytime` int(11) DEFAULT NULL,
  `status` tinyint(1) DEFAULT \'0\',
  `bid` int(11) DEFAULT \'0\',
  PRIMARY KEY (`id`),
  KEY `aid` (`aid`) USING BTREE,
  KEY `proid` (`proid`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');
        $this->execute('CREATE TABLE `seckill_codelist` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) DEFAULT NULL,
  `bid` int(11) DEFAULT \'0\',
  `proid` int(11) DEFAULT NULL,
  `content` text,
  `ordernum` varchar(100) DEFAULT NULL,
  `orderid` int(11) DEFAULT NULL,
  `headimg` varchar(255) DEFAULT NULL,
  `nickname` varchar(255) CHARACTER SET utf8mb4 DEFAULT NULL,
  `createtime` int(11) DEFAULT NULL,
  `buytime` int(11) DEFAULT NULL,
  `status` tinyint(1) DEFAULT \'0\',
  PRIMARY KEY (`id`),
  KEY `aid` (`aid`) USING BTREE,
  KEY `bid` (`bid`) USING BTREE,
  KEY `proid` (`proid`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');
        $this->execute('CREATE TABLE `shop_codelist` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) DEFAULT NULL COMMENT \'账户ID\',
  `bid` int(11) DEFAULT \'0\' COMMENT \'商户ID 0代表平台的\',
  `proid` int(11) DEFAULT NULL COMMENT \'商品ID\',
  `content` text COMMENT \'卡密内容\',
  `ordernum` varchar(100) DEFAULT NULL COMMENT \'订单号\',
  `orderid` int(11) DEFAULT NULL COMMENT \'订单ID\',
  `headimg` varchar(255) DEFAULT NULL COMMENT \'用户头像\',
  `nickname` varchar(255) CHARACTER SET utf8mb4 DEFAULT NULL COMMENT \'用户昵称\',
  `createtime` int(11) DEFAULT NULL COMMENT \'创建时间\',
  `buytime` int(11) DEFAULT NULL COMMENT \'购买时间\',
  `status` tinyint(1) DEFAULT \'0\' COMMENT \'状态0未售出1已售出\',
  PRIMARY KEY (`id`),
  KEY `aid` (`aid`) USING BTREE,
  KEY `bid` (`bid`) USING BTREE,
  KEY `proid` (`proid`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT=\'卡密商品的卡密\'');
        $this->execute('CREATE TABLE `tuangou_codelist` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) DEFAULT NULL,
  `bid` int(11) DEFAULT \'0\',
  `proid` int(11) DEFAULT NULL,
  `content` text,
  `ordernum` varchar(100) DEFAULT NULL,
  `orderid` int(11) DEFAULT NULL,
  `headimg` varchar(255) DEFAULT NULL,
  `nickname` varchar(255) CHARACTER SET utf8mb4 DEFAULT NULL,
  `createtime` int(11) DEFAULT NULL,
  `buytime` int(11) DEFAULT NULL,
  `status` tinyint(1) DEFAULT \'0\',
  PRIMARY KEY (`id`),
  KEY `aid` (`aid`) USING BTREE,
  KEY `bid` (`bid`) USING BTREE,
  KEY `proid` (`proid`) USING BTREE,
  KEY `status` (`status`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');
    }
}
