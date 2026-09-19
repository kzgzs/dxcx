<?php

// P19-6 奖金池模块（批次3-E 任务184）
// 对齐 canzhao 口径：
// - canzhao/upgrade_c2.php 3-54    prize_pool_set / prize_pool_log / prize_pool_send_log（利润池）
// - canzhao/upgrade_c2.php 25623-25687 areareach_prize_pool_set / order_log / send_log（区域/达标池）
// - shop_product 参与列对齐 upgrade_c2.php 25600-25619（area/reach_prizepool_set/gold）
//
// 说明：
// - prize_pool_set        利润池配置（pool_num 池余额；send_bili 发放比例；levelids 等级比例JSON）
// - prize_pool_log        利润池流水（入池为正、发放为负）
// - prize_pool_send_log   利润池发放记录（send_type 0手动 1自动）
// - areareach_prize_pool_set        区域(type=0)/达标(type=1)池配置（send_type 0周 1月）
// - areareach_prize_pool_order_log  区域/达标池入池订单日志
// - areareach_prize_pool_send_log   区域/达标池发放记录（周期幂等依据）

use think\migration\Migrator;

class PrizePoolTables extends Migrator
{
    public function up()
    {
        $this->execute("CREATE TABLE IF NOT EXISTS `prize_pool_set` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) NOT NULL DEFAULT '0' COMMENT '租户ID',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1开启 0关闭',
  `pool_num` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '奖金池当前金额',
  `pool_time` tinyint(1) NOT NULL DEFAULT '1' COMMENT '入池时机 0支付后 1收货后',
  `send_bili` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '奖金发放总比例(%)',
  `levelids` varchar(255) NOT NULL DEFAULT '' COMMENT '等级比例JSON {levelid:bili}',
  `send_time` int(10) NOT NULL DEFAULT '0' COMMENT '每日自动发放小时(0-23)',
  `show_pool` tinyint(1) NOT NULL DEFAULT '0' COMMENT '前台展示 0全部金额 1比例金额',
  `createtime` int(10) NOT NULL DEFAULT '0',
  `updatetime` int(10) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `aid` (`aid`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='利润奖金池配置'");

        $this->execute("CREATE TABLE IF NOT EXISTS `prize_pool_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) NOT NULL DEFAULT '0' COMMENT '租户ID',
  `mid` int(11) NOT NULL DEFAULT '0' COMMENT '订单会员/受益会员ID',
  `orderid` int(11) NOT NULL DEFAULT '0' COMMENT '订单ID（发放为0）',
  `type` varchar(50) NOT NULL DEFAULT '' COMMENT '订单类型',
  `num` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '金额（入池正/发放负）',
  `levelid` int(11) NOT NULL DEFAULT '0' COMMENT '发放等级ID',
  `createtime` int(10) NOT NULL DEFAULT '0',
  `remark` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `aid` (`aid`) USING BTREE,
  KEY `orderid` (`orderid`) USING BTREE,
  KEY `mid` (`mid`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='利润奖金池流水'");

        $this->execute("CREATE TABLE IF NOT EXISTS `prize_pool_send_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) NOT NULL DEFAULT '0' COMMENT '租户ID',
  `pool_num` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '发放时池金额',
  `send_bili` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '发放总比例',
  `prize_total` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '发放总额',
  `levelid` int(11) NOT NULL DEFAULT '0' COMMENT '等级ID',
  `level_bili` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '等级比例',
  `level_prize_total` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '等级发放总额',
  `member_count` int(11) NOT NULL DEFAULT '0' COMMENT '会员人数',
  `send_type` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0手动 1自动',
  `createtime` int(10) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `aid` (`aid`) USING BTREE,
  KEY `createtime` (`createtime`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='利润奖金池发放记录'");

        $this->execute("CREATE TABLE IF NOT EXISTS `areareach_prize_pool_set` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) NOT NULL DEFAULT '0' COMMENT '租户ID',
  `bid` int(11) NOT NULL DEFAULT '0',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1开启',
  `type` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0区域奖金池 1达标奖金池',
  `name` varchar(60) DEFAULT NULL COMMENT '名称',
  `province_id` int(11) NOT NULL DEFAULT '0' COMMENT '省份ID',
  `province_name` varchar(60) NOT NULL DEFAULT '' COMMENT '省份名（匹配订单收货省）',
  `money` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '奖金池金额',
  `mids` text COMMENT '奖励发放人（逗号分割会员ID，区域池用）',
  `money_orderids` text COMMENT '已入池订单ID（逗号分割，幂等）',
  `send_type` tinyint(1) NOT NULL DEFAULT '0' COMMENT '发放周期 0按周 1按月',
  `gettj` varchar(200) DEFAULT NULL COMMENT '参与等级（逗号分割，-1=全部）',
  `reach_money` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '达标金额',
  `remark` varchar(200) DEFAULT NULL,
  `createtime` int(11) NOT NULL DEFAULT '0',
  `updatetime` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `aid` (`aid`) USING BTREE,
  KEY `type` (`type`) USING BTREE,
  KEY `province_id` (`province_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='区域/达标奖金池配置'");

        $this->execute("CREATE TABLE IF NOT EXISTS `areareach_prize_pool_order_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) NOT NULL DEFAULT '0' COMMENT '租户ID',
  `bid` int(11) NOT NULL DEFAULT '0',
  `type` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0区域 1达标',
  `orderid` int(11) NOT NULL DEFAULT '0' COMMENT '订单ID（周期清空为0）',
  `ogid` int(11) NOT NULL DEFAULT '0' COMMENT '订单商品行ID',
  `prize_pool_id` int(11) NOT NULL DEFAULT '0' COMMENT '池配置ID',
  `money` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '增加金额',
  `before_money` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '增加前池金额',
  `remark` varchar(200) DEFAULT NULL,
  `createtime` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `aid` (`aid`) USING BTREE,
  KEY `orderid` (`orderid`) USING BTREE,
  KEY `prize_pool_id` (`prize_pool_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='区域/达标奖金池入池日志'");

        $this->execute("CREATE TABLE IF NOT EXISTS `areareach_prize_pool_send_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) NOT NULL DEFAULT '0' COMMENT '租户ID',
  `bid` int(11) NOT NULL DEFAULT '0',
  `mid` int(11) NOT NULL DEFAULT '0' COMMENT '受益会员ID',
  `type` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0区域 1达标',
  `name` varchar(60) DEFAULT NULL COMMENT '池名称',
  `prize_pool_id` int(11) NOT NULL DEFAULT '0' COMMENT '池配置ID',
  `prize_pool_money` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '发放时池金额',
  `send_money` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '发放金额',
  `send_start_time` int(11) NOT NULL DEFAULT '0' COMMENT '周期开始',
  `send_end_time` int(11) NOT NULL DEFAULT '0' COMMENT '周期结束',
  `remark` varchar(200) DEFAULT NULL,
  `createtime` int(11) NOT NULL DEFAULT '0',
  `prize_pool_set_json` text COMMENT '发放时池配置快照',
  PRIMARY KEY (`id`),
  KEY `aid` (`aid`) USING BTREE,
  KEY `mid` (`mid`) USING BTREE,
  KEY `prize_pool_id` (`prize_pool_id`) USING BTREE,
  KEY `send_start_time` (`send_start_time`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='区域/达标奖金池发放记录'");

        // shop_product 参与列（对标 upgrade_c2.php 25600-25619）
        $this->execute("ALTER TABLE `shop_product`
            ADD COLUMN `area_prizepool_set` tinyint(1) NOT NULL DEFAULT '0' COMMENT '区域奖金池 0不参与 1按比例 2按金额',
            ADD COLUMN `area_prizepool_gold` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '区域奖金池 比例(%)或金额',
            ADD COLUMN `reach_prizepool_set` tinyint(1) NOT NULL DEFAULT '0' COMMENT '达标奖金池 0不参与 1按比例 2按金额',
            ADD COLUMN `reach_prizepool_gold` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '达标奖金池 比例(%)或金额'");
    }

    public function down()
    {
        $this->execute("ALTER TABLE `shop_product`
            DROP COLUMN `reach_prizepool_gold`,
            DROP COLUMN `reach_prizepool_set`,
            DROP COLUMN `area_prizepool_gold`,
            DROP COLUMN `area_prizepool_set`");
        $this->execute('DROP TABLE IF EXISTS `areareach_prize_pool_send_log`');
        $this->execute('DROP TABLE IF EXISTS `areareach_prize_pool_order_log`');
        $this->execute('DROP TABLE IF EXISTS `areareach_prize_pool_set`');
        $this->execute('DROP TABLE IF EXISTS `prize_pool_send_log`');
        $this->execute('DROP TABLE IF EXISTS `prize_pool_log`');
        $this->execute('DROP TABLE IF EXISTS `prize_pool_set`');
    }
}
