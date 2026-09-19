<?php

declare(strict_types=1);

// 线下补助两表（P24-7 批次8 任务227）：
// - offline_subsidies_set           补助配置单行表（aid 唯一）
// - member_offline_subsidies_log    补助发放记录流水
// 口径参照 canzhao/app/common/Member.php offlineSubsidiesLog 生成逻辑：
// type：1通证分红(score) 2推荐奖励(commission) 3讲师奖励(commission) 4爱心基金(commission) 5升级奖励(commission)
// scene：shop 商城订单 / maidan 买单订单；cycle：0支付后 1确认收货后
// 幂等写法：information_schema 判存在再建表。

use think\migration\Migrator;

class OfflineSubsidiesTables extends Migrator
{
    public function change()
    {
        $exists = $this->fetchRow(
            "SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'offline_subsidies_set' LIMIT 1"
        );
        if (!$exists) {
            $this->execute('CREATE TABLE `offline_subsidies_set` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) NOT NULL DEFAULT \'0\' COMMENT \'租户ID\',
  `status` tinyint(1) NOT NULL DEFAULT \'0\' COMMENT \'启用开关 0关闭 1开启\',
  `effective_ratio` decimal(10,2) NOT NULL DEFAULT \'0.00\' COMMENT \'有效比例%\',
  `cycle` tinyint(1) NOT NULL DEFAULT \'0\' COMMENT \'计算周期 0支付后 1确认收货后\',
  `fwtype` tinyint(1) NOT NULL DEFAULT \'0\' COMMENT \'商品范围 0全部商品 1指定商品\',
  `base` tinyint(1) NOT NULL DEFAULT \'0\' COMMENT \'计算基数 0成交金额 1成交利润\',
  `productids` varchar(500) NOT NULL DEFAULT \'\' COMMENT \'指定商品ID 逗号分隔\',
  `scene` varchar(255) NOT NULL DEFAULT \'\' COMMENT \'奖励场景 逗号分隔 shop,maidan\',
  `upgrade_levelid` varchar(255) NOT NULL DEFAULT \'\' COMMENT \'升级奖励等级 -1全部\',
  `upgrade_ratio` decimal(10,2) NOT NULL DEFAULT \'0.00\' COMMENT \'升级奖励比例%\',
  `upgrade_text` varchar(50) NOT NULL DEFAULT \'\' COMMENT \'升级奖励文案\',
  `upgrade_num` int(11) NOT NULL DEFAULT \'0\' COMMENT \'升级奖励满N个后转爱心基金\',
  `upgrade_auto_uplevelid` int(11) NOT NULL DEFAULT \'0\' COMMENT \'满N个自动升级到等级ID 0关闭\',
  `pass_levelid` varchar(255) NOT NULL DEFAULT \'\' COMMENT \'通证分红等级 -1全部\',
  `pass_ratio` decimal(10,2) NOT NULL DEFAULT \'0.00\' COMMENT \'通证分红比例%\',
  `pass_text` varchar(50) NOT NULL DEFAULT \'\' COMMENT \'通证分红文案\',
  `recommend_levelid` varchar(255) NOT NULL DEFAULT \'\' COMMENT \'推荐奖励等级 -1全部\',
  `recommend_ratio` decimal(10,2) NOT NULL DEFAULT \'0.00\' COMMENT \'推荐奖励比例%\',
  `recommend_text` varchar(50) NOT NULL DEFAULT \'\' COMMENT \'推荐奖励文案\',
  `lecturer_levelid` varchar(255) NOT NULL DEFAULT \'\' COMMENT \'讲师奖励等级 -1全部\',
  `lecturer_ratio` decimal(10,2) NOT NULL DEFAULT \'0.00\' COMMENT \'讲师奖励比例%\',
  `lecturer_text` varchar(50) NOT NULL DEFAULT \'\' COMMENT \'讲师奖励文案\',
  `lovefund_levelid` varchar(255) NOT NULL DEFAULT \'\' COMMENT \'爱心基金等级 -1全部\',
  `lovefund_ratio` decimal(10,2) NOT NULL DEFAULT \'0.00\' COMMENT \'爱心基金比例%\',
  `lovefund_text` varchar(50) NOT NULL DEFAULT \'\' COMMENT \'爱心基金文案\',
  `updatetime` int(11) NOT NULL DEFAULT \'0\',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `aid` (`aid`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT=\'线下补助配置\'');
        }

        $exists = $this->fetchRow(
            "SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'member_offline_subsidies_log' LIMIT 1"
        );
        if (!$exists) {
            $this->execute('CREATE TABLE `member_offline_subsidies_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) NOT NULL DEFAULT \'0\' COMMENT \'租户ID\',
  `mid` int(11) NOT NULL DEFAULT \'0\' COMMENT \'获得会员ID（直推人）\',
  `frommid` int(11) NOT NULL DEFAULT \'0\' COMMENT \'来源会员ID（下单人）\',
  `orderid` int(11) NOT NULL DEFAULT \'0\' COMMENT \'关联订单ID\',
  `ordernum` varchar(50) NOT NULL DEFAULT \'\' COMMENT \'关联订单号\',
  `scene` varchar(20) NOT NULL DEFAULT \'\' COMMENT \'奖励场景 shop/maidan/levelup\',
  `type` tinyint(1) NOT NULL DEFAULT \'0\' COMMENT \'类型 1通证分红 2推荐奖励 3讲师奖励 4爱心基金 5升级奖励\',
  `score` decimal(11,2) NOT NULL DEFAULT \'0.00\' COMMENT \'发放积分（type=1 通证分红）\',
  `commission` decimal(11,2) NOT NULL DEFAULT \'0.00\' COMMENT \'发放佣金（type=2/3/4/5）\',
  `order_money` decimal(11,2) NOT NULL DEFAULT \'0.00\' COMMENT \'参与计算的订单金额\',
  `remark` varchar(500) NOT NULL DEFAULT \'\' COMMENT \'备注（计算口径说明）\',
  `offline_subsidies_set` text COMMENT \'发放时的配置快照JSON\',
  `status` tinyint(1) NOT NULL DEFAULT \'1\' COMMENT \'状态 1已发放\',
  `createtime` int(11) NOT NULL DEFAULT \'0\',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `aid` (`aid`) USING BTREE,
  KEY `mid` (`mid`) USING BTREE,
  KEY `ordernum` (`ordernum`) USING BTREE,
  KEY `type` (`type`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT=\'线下补助发放流水\'');
        }
    }
}
