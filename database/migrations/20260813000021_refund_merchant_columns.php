<?php
// P4 退款 + 多商户门店基础（幂等）
// 退款：复用基线 shop_refund_order（金额元；refund_status：0驳回/1已退款/2申请中），补 deleted 软删列
// 商户：旧表 business 为分销商不复用，新建 merchant
// 门店：复用基线 mendian，补 merchant_id / contacts / deleted 列

use think\migration\Migrator;

class RefundMerchantColumns extends Migrator
{
    public function change()
    {
        // shop_refund_order 补软删列
        $row = $this->fetchRow(
            "SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'shop_refund_order' AND column_name = 'deleted' LIMIT 1"
        );
        if (!$row) {
            $this->execute("ALTER TABLE `shop_refund_order` ADD COLUMN `deleted` tinyint(1) NOT NULL DEFAULT '0' COMMENT '软删标记 0正常 1已删除'");
        }

        // 商户表（新系统概念；business 为旧分销商，不复用）
        $row = $this->fetchRow("SHOW TABLES LIKE 'merchant'");
        if (!$row) {
            $this->execute(
                "CREATE TABLE `merchant` (
                    `id` int NOT NULL AUTO_INCREMENT,
                    `aid` int DEFAULT NULL COMMENT '租户ID',
                    `name` varchar(255) NOT NULL DEFAULT '' COMMENT '商户名称',
                    `logo` varchar(255) NOT NULL DEFAULT '' COMMENT '商户LOGO',
                    `contacts` varchar(50) NOT NULL DEFAULT '' COMMENT '联系人',
                    `phone` varchar(30) NOT NULL DEFAULT '' COMMENT '联系电话',
                    `remark` varchar(255) NOT NULL DEFAULT '' COMMENT '备注',
                    `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态 1启用 0停用',
                    `deleted` tinyint(1) NOT NULL DEFAULT '0' COMMENT '软删标记 0正常 1已删除',
                    `createtime` int DEFAULT NULL COMMENT '创建时间',
                    PRIMARY KEY (`id`),
                    KEY `aid` (`aid`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='商户表'"
            );
        }

        // mendian 补列：所属商户 / 联系人 / 软删
        foreach ([
            'merchant_id' => "ADD COLUMN `merchant_id` int NOT NULL DEFAULT '0' COMMENT '所属商户ID'",
            'contacts'    => "ADD COLUMN `contacts` varchar(50) NOT NULL DEFAULT '' COMMENT '联系人'",
            'deleted'     => "ADD COLUMN `deleted` tinyint(1) NOT NULL DEFAULT '0' COMMENT '软删标记 0正常 1已删除'",
        ] as $column => $ddl) {
            $row = $this->fetchRow(
                "SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mendian' AND column_name = '{$column}' LIMIT 1"
            );
            if (!$row) {
                $this->execute("ALTER TABLE `mendian` {$ddl}");
            }
        }
    }
}
