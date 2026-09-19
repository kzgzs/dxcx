<?php
// P5 第二期：积分体系 + 满减促销（幂等）
//
// 基线调研结论：
// - member.score（int）已存在，直接复用
// - member_scorelog 等旧流水表 mid 为 varchar、type 语义不合契约，不复用 → 新建 score_log
// - admin_set 为超宽单行配置表且积分列语义不同（score2money 等）→ 新建 tenant_config 键值表
// - 无促销表 → 新建 promotion
//
// 订单表补列：promotion_id/promotion_money（满减促销）、use_score/score_money（积分抵扣）
//
// 计价叠加规则（全局约定）：
// 商品金额 → 先满减促销（自动择优：生效活动中优惠额最大者）
//          → 再优惠券（券门槛按促销后金额判断）
//          → 再积分抵扣，实付下限 0.01

use think\migration\Migrator;

class ScorePromotionTables extends Migrator
{
    public function change()
    {
        // 积分流水表
        $exists = $this->fetchRow(
            "SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'score_log' LIMIT 1"
        );
        if (!$exists) {
            $this->execute("CREATE TABLE `score_log` (
                `id` int NOT NULL AUTO_INCREMENT,
                `aid` int NOT NULL DEFAULT '0' COMMENT '租户ID',
                `mid` int NOT NULL DEFAULT '0' COMMENT '会员ID',
                `score` int NOT NULL DEFAULT '0' COMMENT '积分变动值（带符号：正=入账 负=扣减）',
                `remark` varchar(255) NOT NULL DEFAULT '' COMMENT '备注',
                `type` tinyint NOT NULL DEFAULT '4' COMMENT '类型 1支付赠送 2下单抵扣 3取消返还 4后台调整',
                `create_time` int NOT NULL DEFAULT '0' COMMENT '发生时间戳',
                PRIMARY KEY (`id`),
                KEY `idx_aid_mid` (`aid`,`mid`),
                KEY `idx_type` (`type`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='会员积分流水'");
        }

        // 满减促销表（时间 Unix 秒）
        $exists = $this->fetchRow(
            "SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'promotion' LIMIT 1"
        );
        if (!$exists) {
            $this->execute("CREATE TABLE `promotion` (
                `id` int NOT NULL AUTO_INCREMENT,
                `aid` int NOT NULL DEFAULT '0' COMMENT '租户ID',
                `name` varchar(255) NOT NULL DEFAULT '' COMMENT '活动名称',
                `type` tinyint NOT NULL DEFAULT '1' COMMENT '类型 1满额减 2满件折',
                `full_money` decimal(11,2) NOT NULL DEFAULT '0.00' COMMENT '满额门槛（元，type1）',
                `full_num` int NOT NULL DEFAULT '0' COMMENT '满件门槛（件，type2）',
                `cut_money` decimal(11,2) NOT NULL DEFAULT '0.00' COMMENT '立减金额（元，type1）',
                `discount` decimal(4,1) NOT NULL DEFAULT '10.0' COMMENT '折扣值 1~9.9（type2）',
                `start_time` int NOT NULL DEFAULT '0' COMMENT '开始时间（Unix秒）',
                `end_time` int NOT NULL DEFAULT '0' COMMENT '结束时间（Unix秒）',
                `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态 1启用 0停用',
                `deleted` tinyint(1) NOT NULL DEFAULT '0' COMMENT '软删标记 0正常 1已删除',
                `createtime` int NOT NULL DEFAULT '0' COMMENT '创建时间',
                PRIMARY KEY (`id`),
                KEY `idx_aid_status` (`aid`,`status`,`deleted`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='满减促销活动'");
        }

        // 租户级键值配置表
        $exists = $this->fetchRow(
            "SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'tenant_config' LIMIT 1"
        );
        if (!$exists) {
            $this->execute("CREATE TABLE `tenant_config` (
                `id` int NOT NULL AUTO_INCREMENT,
                `aid` int NOT NULL DEFAULT '0' COMMENT '租户ID',
                `key` varchar(60) NOT NULL DEFAULT '' COMMENT '配置键',
                `value` varchar(255) NOT NULL DEFAULT '' COMMENT '配置值',
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_aid_key` (`aid`,`key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='租户级键值配置'");
        }

        // 订单表补列
        foreach ([
            'promotion_id'     => "ADD COLUMN `promotion_id` int NOT NULL DEFAULT '0' COMMENT '应用的促销活动ID（0=无）'",
            'promotion_money'  => "ADD COLUMN `promotion_money` decimal(11,2) NOT NULL DEFAULT '0.00' COMMENT '促销优惠金额（元）'",
            'use_score'        => "ADD COLUMN `use_score` int NOT NULL DEFAULT '0' COMMENT '抵扣使用积分数'",
            'score_money'      => "ADD COLUMN `score_money` decimal(11,2) NOT NULL DEFAULT '0.00' COMMENT '积分抵扣金额（元）'",
        ] as $column => $ddl) {
            $row = $this->fetchRow(
                "SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'shop_order' AND column_name = '{$column}' LIMIT 1"
            );
            if (!$row) {
                $this->execute("ALTER TABLE `shop_order` {$ddl}");
            }
        }
    }
}
