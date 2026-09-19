<?php

// tenant_config.value 扩为 TEXT：
// 随行付服务商配置的公钥/私钥为去回车单行字符串（RSA 私钥约 1600+ 字符），
// 原 varchar(255) 无法容纳，故扩容为 TEXT（唯一键仅 aid+key，不受影响）。

use think\migration\Migrator;

class TenantConfigValueText extends Migrator
{
    public function up()
    {
        $this->execute("ALTER TABLE `tenant_config` MODIFY COLUMN `value` text NOT NULL COMMENT '配置值'");
    }

    public function down()
    {
        $this->execute("ALTER TABLE `tenant_config` MODIFY COLUMN `value` varchar(255) NOT NULL DEFAULT '' COMMENT '配置值'");
    }
}
