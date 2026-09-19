<?php
// 批次5-A 任务194：文章管理整组缺口补丁
// 基线 20260812000006_content_tables.php 已建 article 组 8 表，此处补齐：
// 1) article_reward_order 打赏订单表（对照点大 ApiArticle::reward）
// 2) article 表缺列：fujian 附件 / mid 打赏收款会员
// 3) article_set 缺列：reward_status / money_data / score_data（打赏配置）
// 4) article_resource 会员附件获取记录表（对照点大 ApiArticle::getResourceSave/getResourceList/getFujian）

use think\migration\Migrator;

class ArticleRewardFujianPatch extends Migrator
{
    public function change()
    {
        $this->execute('CREATE TABLE `article_reward_order` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) DEFAULT NULL,
  `bid` int(11) DEFAULT \'0\',
  `ordernum` varchar(64) DEFAULT NULL,
  `mid` int(11) DEFAULT NULL COMMENT \'打赏会员\',
  `send_mid` int(11) DEFAULT \'0\' COMMENT \'收款会员（文章绑定）\',
  `artid` int(11) DEFAULT NULL,
  `type` tinyint(1) DEFAULT \'2\' COMMENT \'1现金 2积分\',
  `num` decimal(11,2) DEFAULT \'0.00\' COMMENT \'打赏数额\',
  `status` tinyint(1) DEFAULT \'0\' COMMENT \'0待支付 1已完成\',
  `paytime` int(11) DEFAULT NULL,
  `createtime` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `aid` (`aid`),
  KEY `bid` (`bid`),
  KEY `artid` (`artid`),
  KEY `mid` (`mid`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT=\'文章打赏订单\'');

        $this->execute('CREATE TABLE `article_resource` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) DEFAULT NULL,
  `bid` int(11) DEFAULT \'0\',
  `mid` int(11) DEFAULT NULL,
  `artid` int(11) DEFAULT NULL,
  `createtime` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `aid` (`aid`),
  KEY `mid` (`mid`),
  KEY `artid` (`artid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT=\'文章附件资源获取记录\'');

        $this->execute("ALTER TABLE `article` ADD COLUMN `fujian` varchar(1000) DEFAULT '' COMMENT '附件url逗号分隔' AFTER `pcid`");
        $this->execute("ALTER TABLE `article` ADD COLUMN `mid` int(11) DEFAULT '0' COMMENT '打赏收款会员 0未开启' AFTER `fujian`");
        $this->execute("ALTER TABLE `article_set` ADD COLUMN `reward_status` tinyint(1) DEFAULT '0' COMMENT '打赏开关'");
        $this->execute("ALTER TABLE `article_set` ADD COLUMN `money_data` varchar(255) DEFAULT '' COMMENT '现金打赏档位逗号分隔'");
        $this->execute("ALTER TABLE `article_set` ADD COLUMN `score_data` varchar(255) DEFAULT '' COMMENT '积分打赏档位逗号分隔'");
    }
}
