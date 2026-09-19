<?php
// 超管控制台-租户管理：admin（租户表）补充业务字段
// username=租户账号(唯一) / company=公司名 / contacts=联系人 / phone=联系电话 / deleted=软删标记
// 说明：endtime、status、remark 沿用现有列，不重复建

use think\migration\Migrator;

class AdminTenantColumns extends Migrator
{
    public function change()
    {
        $this->execute("ALTER TABLE `admin` ADD COLUMN `username` varchar(50) NOT NULL DEFAULT '' COMMENT '租户账号（登录/展示用，全局唯一）' AFTER `id`");
        $this->execute('ALTER TABLE `admin` ADD UNIQUE KEY `uk_admin_username` (`username`)');
        $this->execute("ALTER TABLE `admin` ADD COLUMN `company` varchar(255) NOT NULL DEFAULT '' COMMENT '公司/店铺名称' AFTER `remark`");
        $this->execute("ALTER TABLE `admin` ADD COLUMN `contacts` varchar(60) NOT NULL DEFAULT '' COMMENT '联系人' AFTER `company`");
        $this->execute("ALTER TABLE `admin` ADD COLUMN `phone` varchar(60) NOT NULL DEFAULT '' COMMENT '联系电话' AFTER `contacts`");
        $this->execute("ALTER TABLE `admin` ADD COLUMN `deleted` tinyint(1) NOT NULL DEFAULT '0' COMMENT '软删标记 0正常 1已删除' AFTER `phone`");
        $this->execute('ALTER TABLE `admin` ADD INDEX `idx_admin_deleted` (`deleted`)');
    }
}
