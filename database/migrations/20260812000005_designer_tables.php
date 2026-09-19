<?php
// GENERATED-BY sql2migration （一次性基线生成，勿手工逐行编辑）
// 基线迁移[5/14] 设计装修（designer/renovation）
// 共 14 张表：designer_login, designer_menu, designer_menu2, designer_menu_business, designer_mobile, designer_share, designer_shopdetail, designer_template, designerpage, designerpage_order, designerpage_rwvideoad, designerpage_rwvideoad_record, designerpage_tab, renovation_calculator
// 清洗：去 ddwx_ 前缀 / utf8mb4+utf8mb4_general_ci / 去 ZEROFILL / AUTO_INCREMENT 归 1 / 去 dump 条件注释

use think\migration\Migrator;

class DesignerTables extends Migrator
{
    public function change()
    {
        $this->execute('CREATE TABLE `designer_login` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) DEFAULT NULL,
  `data` text,
  `updatetime` int(11) NOT NULL DEFAULT \'0\',
  `type` tinyint(1) NOT NULL DEFAULT \'0\' COMMENT \'类型\',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `aid` (`aid`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');
        $this->execute('CREATE TABLE `designer_menu` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) DEFAULT NULL,
  `indexurl` varchar(255) DEFAULT \'/m/main/index\',
  `menucount` int(11) DEFAULT NULL,
  `menudata` text,
  `navigationBarBackgroundColor` varchar(255) DEFAULT NULL,
  `navigationBarTextStyle` varchar(255) DEFAULT NULL,
  `updatetime` int(11) DEFAULT NULL,
  `platform` varchar(11) DEFAULT \'mp\',
  `tongbu` tinyint(1) DEFAULT \'1\',
  PRIMARY KEY (`id`),
  KEY `aid` (`aid`) USING BTREE,
  KEY `platform` (`platform`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');
        $this->execute('CREATE TABLE `designer_menu2` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) DEFAULT NULL,
  `bid` int(11) DEFAULT \'0\',
  `indexurlname` varchar(255) DEFAULT NULL,
  `indexurl` varchar(255) DEFAULT NULL,
  `menudata` text,
  `createtime` int(11) DEFAULT NULL,
  `status` tinyint(1) DEFAULT \'1\',
  `platform` varchar(100) DEFAULT NULL,
  `backgroundColor` varchar(255) DEFAULT NULL,
  `menucount` int(11) DEFAULT \'4\',
  PRIMARY KEY (`id`),
  KEY `aid` (`aid`) USING BTREE,
  KEY `bid` (`bid`) USING BTREE,
  KEY `indexurl` (`indexurl`) USING BTREE,
  KEY `platform` (`platform`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');
        $this->execute('CREATE TABLE `designer_menu_business` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) DEFAULT NULL,
  `indexurl` varchar(255) DEFAULT \'/pages/business/index\',
  `menucount` int(11) DEFAULT NULL,
  `menudata` text,
  `updatetime` int(11) DEFAULT NULL,
  `platform` varchar(11) DEFAULT \'mp\',
  `tongbu` tinyint(1) DEFAULT \'1\',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `aid` (`aid`) USING BTREE,
  KEY `platform` (`platform`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');
        $this->execute('CREATE TABLE `designer_mobile` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) DEFAULT NULL,
  `data` text,
  `updatetime` int(11) NOT NULL DEFAULT \'0\',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `aid` (`aid`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC');
        $this->execute('CREATE TABLE `designer_share` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) DEFAULT NULL,
  `bid` int(11) DEFAULT \'0\',
  `indexurlname` varchar(255) DEFAULT NULL,
  `indexurl` varchar(255) DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `desc` varchar(255) DEFAULT NULL,
  `pic` varchar(255) DEFAULT NULL,
  `url` varchar(255) DEFAULT NULL,
  `createtime` int(11) DEFAULT NULL,
  `status` tinyint(1) DEFAULT \'1\',
  `platform` varchar(100) DEFAULT NULL,
  `is_rootpath` tinyint(1) DEFAULT \'0\' COMMENT \'是否过滤参数 1页面根路径忽略参数匹配\',
  PRIMARY KEY (`id`),
  KEY `aid` (`aid`) USING BTREE,
  KEY `bid` (`bid`) USING BTREE,
  KEY `status` (`status`) USING BTREE,
  KEY `indexurl` (`indexurl`) USING BTREE,
  KEY `platform` (`platform`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');
        $this->execute('CREATE TABLE `designer_shopdetail` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) DEFAULT NULL,
  `bid` int(11) NOT NULL DEFAULT \'0\',
  `indexurl` varchar(255) DEFAULT \'/pages/index/index\',
  `menucount` int(11) DEFAULT NULL,
  `menudata` text,
  `navigationBarBackgroundColor` varchar(255) DEFAULT NULL,
  `navigationBarTextStyle` varchar(255) DEFAULT NULL,
  `updatetime` int(11) DEFAULT NULL,
  `platform` varchar(11) DEFAULT \'mp\',
  `tongbu` tinyint(1) DEFAULT \'1\',
  PRIMARY KEY (`id`),
  KEY `aid` (`aid`) USING BTREE,
  KEY `platform` (`platform`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');
        $this->execute('CREATE TABLE `designer_template` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8 DEFAULT NULL,
  `type` tinyint(1) DEFAULT \'0\' COMMENT \'0：普通页面；1：首页；2：会员中心\',
  `pageinfo` text,
  `content` longtext,
  `createtime` int(11) DEFAULT NULL,
  `updatetime` int(11) DEFAULT NULL,
  `sort` int(11) DEFAULT \'0\',
  `remark` varchar(255) DEFAULT \'\' COMMENT \'页面备注\',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT=\'页面模板\'');
        $this->execute('CREATE TABLE `designerpage` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) DEFAULT NULL,
  `bid` int(11) DEFAULT \'0\',
  `name` varchar(255) DEFAULT NULL,
  `ishome` int(11) DEFAULT \'0\' COMMENT \'1首页 2会员中心\',
  `gid` int(11) DEFAULT \'0\',
  `pageinfo` text,
  `content` longtext,
  `createtime` int(11) DEFAULT NULL,
  `updatetime` int(11) DEFAULT NULL,
  `sort` int(11) DEFAULT \'0\',
  PRIMARY KEY (`id`),
  KEY `aid` (`aid`) USING BTREE,
  KEY `bid` (`bid`) USING BTREE,
  KEY `ishome` (`ishome`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');
        $this->execute('CREATE TABLE `designerpage_order` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) DEFAULT NULL,
  `bid` int(11) DEFAULT \'0\',
  `pageid` int(11) DEFAULT NULL,
  `mid` int(11) DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `price` decimal(11,2) DEFAULT NULL,
  `ordernum` varchar(100) DEFAULT NULL,
  `createtime` int(11) DEFAULT NULL,
  `status` tinyint(1) DEFAULT \'0\',
  `payorderid` int(11) DEFAULT NULL,
  `paytypeid` int(11) DEFAULT NULL,
  `paytype` varchar(255) DEFAULT NULL,
  `paynum` varchar(255) DEFAULT NULL,
  `paytime` int(11) DEFAULT NULL,
  `platform` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `aid` (`aid`) USING BTREE,
  KEY `bid` (`bid`) USING BTREE,
  KEY `pageid` (`pageid`) USING BTREE,
  KEY `mid` (`mid`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');
        $this->execute('CREATE TABLE `designerpage_rwvideoad` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) DEFAULT NULL,
  `unitid` varchar(100) DEFAULT NULL,
  `givescore` int(11) DEFAULT NULL,
  `givemoney` decimal(11,2) DEFAULT \'0.00\',
  `givemoneyparent` decimal(11,2) DEFAULT \'0.00\',
  `givetimes` int(11) DEFAULT \'0\',
  `givetimestotal` int(11) DEFAULT \'999\',
  `createtime` varchar(50) DEFAULT NULL,
  `rad_url` varchar(255) DEFAULT NULL,
  `type` tinyint(1) DEFAULT \'0\',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `aid` (`aid`) USING BTREE,
  KEY `unitid` (`unitid`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');
        $this->execute('CREATE TABLE `designerpage_rwvideoad_record` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) DEFAULT NULL,
  `mid` int(11) DEFAULT NULL,
  `hid` int(11) DEFAULT NULL,
  `givescore` int(11) DEFAULT NULL,
  `givemoney` decimal(11,2) DEFAULT \'0.00\',
  `givemoneyparent` decimal(11,2) DEFAULT \'0.00\',
  `givetimes` int(11) DEFAULT \'0\',
  `createdate` varchar(50) DEFAULT NULL,
  `createtime` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `aid` (`aid`) USING BTREE,
  KEY `mid` (`mid`) USING BTREE,
  KEY `hid` (`hid`) USING BTREE,
  KEY `createdate` (`createdate`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');
        $this->execute('CREATE TABLE `designerpage_tab` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) DEFAULT NULL,
  `tabid` varchar(100) DEFAULT \'0\',
  `tabindexid` varchar(100) DEFAULT NULL,
  `content` longtext,
  `createtime` int(11) DEFAULT NULL,
  `updatetime` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `aid` (`aid`) USING BTREE,
  KEY `tabid` (`tabid`) USING BTREE,
  KEY `tabindexid` (`tabindexid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');
        $this->execute('CREATE TABLE `renovation_calculator` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) DEFAULT NULL,
  `name` varchar(255) DEFAULT \'装修计算器\',
  `status` tinyint(1) DEFAULT \'1\',
  `type1_param` float(10,2) DEFAULT \'1.00\',
  `type2_param` float(10,2) DEFAULT \'1.50\',
  `type3_param` float(10,2) DEFAULT \'2.00\',
  `areadata` text,
  `guigedata` text,
  `bgcolor` varchar(255) DEFAULT \'#FD4A46\',
  `banner` varchar(255) DEFAULT NULL,
  `xystatus` tinyint(1) DEFAULT \'1\',
  `xieyi` longtext,
  `description` longtext,
  PRIMARY KEY (`id`),
  KEY `aid` (`aid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');
    }
}
