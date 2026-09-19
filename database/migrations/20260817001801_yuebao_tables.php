<?php

// P19-3 余额宝模块（批次3-F 任务181）
// 对齐 canzhao/upgrade_2.php 440-488 口径：
// - member 表补 yuebao_money（余额宝余额）/ yuebao_rate（个人收益率，-1=跟随租户全局）
// - member_yuebao_moneylog     余额宝流水（type 1收益增加 2提现减少 3转余额减少 4退款返回）
// - member_yuebao_withdrawlog  余额宝提现（status 0审核中 1已审核 2已驳回 3已打款）

use think\migration\Migrator;

class YuebaoTables extends Migrator
{
    public function up()
    {
        $this->execute("ALTER TABLE `member`
            ADD COLUMN `yuebao_money` decimal(11,2) NOT NULL DEFAULT '0.00' COMMENT '余额宝余额' AFTER `money`,
            ADD COLUMN `yuebao_rate` decimal(10,2) NOT NULL DEFAULT '-1.00' COMMENT '余额宝收益率（%；-1=跟随租户设置）' AFTER `yuebao_money`");

        $this->execute("CREATE TABLE IF NOT EXISTS `member_yuebao_moneylog` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) NOT NULL DEFAULT '0' COMMENT '租户ID',
  `mid` int(11) NOT NULL DEFAULT '0' COMMENT '会员ID',
  `money` decimal(11,2) NOT NULL DEFAULT '0.00' COMMENT '变动金额（带符号）',
  `after` decimal(11,2) NOT NULL DEFAULT '0.00' COMMENT '变动后余额宝余额',
  `type` tinyint(1) NOT NULL DEFAULT '0' COMMENT '类型：1收益增加 2提现减少 3转余额减少 4退款返回',
  `createtime` int(11) DEFAULT NULL COMMENT '创建时间',
  `remark` varchar(255) DEFAULT NULL COMMENT '备注',
  PRIMARY KEY (`id`),
  KEY `aid` (`aid`) USING BTREE,
  KEY `mid` (`mid`) USING BTREE,
  KEY `createtime` (`createtime`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='余额宝流水'");

        $this->execute("CREATE TABLE IF NOT EXISTS `member_yuebao_withdrawlog` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) NOT NULL DEFAULT '0' COMMENT '租户ID',
  `mid` int(11) NOT NULL DEFAULT '0' COMMENT '会员ID',
  `money` decimal(11,2) DEFAULT NULL COMMENT '实付金额（扣手续费后）',
  `txmoney` decimal(11,2) DEFAULT NULL COMMENT '申请扣减金额',
  `aliaccount` varchar(255) DEFAULT NULL COMMENT '支付宝账号',
  `aliaccountname` varchar(255) DEFAULT NULL COMMENT '支付宝姓名',
  `ordernum` varchar(255) DEFAULT NULL COMMENT '提现单号',
  `paytype` varchar(255) DEFAULT NULL COMMENT '提现方式',
  `status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0审核中 1已审核 2已驳回 3已打款',
  `createtime` int(11) DEFAULT NULL COMMENT '申请时间',
  `bankname` varchar(255) DEFAULT NULL COMMENT '银行名称',
  `bankcarduser` varchar(255) DEFAULT NULL COMMENT '银行卡持卡人',
  `bankcardnum` varchar(255) DEFAULT NULL COMMENT '银行卡号',
  `paytime` int(11) DEFAULT NULL COMMENT '打款时间',
  `paynum` varchar(255) DEFAULT NULL COMMENT '打款单号',
  `platform` varchar(255) DEFAULT 'wx' COMMENT '来源平台',
  `reason` varchar(255) DEFAULT NULL COMMENT '驳回原因',
  PRIMARY KEY (`id`),
  KEY `aid` (`aid`) USING BTREE,
  KEY `mid` (`mid`) USING BTREE,
  KEY `createtime` (`createtime`) USING BTREE,
  KEY `status` (`status`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='余额宝提现记录'");
    }

    public function down()
    {
        $this->execute('DROP TABLE IF EXISTS `member_yuebao_withdrawlog`');
        $this->execute('DROP TABLE IF EXISTS `member_yuebao_moneylog`');
        $this->execute("ALTER TABLE `member` DROP COLUMN `yuebao_rate`, DROP COLUMN `yuebao_money`");
    }
}
