<?php

declare(strict_types=1);

use think\migration\Migrator;

/**
 * admin_user 增加 truename（姓名）/ mobile（手机）字段
 *
 * 管理员列表（批次2-C）要求维护姓名与手机号，原表无此两列
 */
class AdminUserTruenameMobileColumns extends Migrator
{
    public function change(): void
    {
        $this->execute("ALTER TABLE `admin_user`
            ADD COLUMN `truename` varchar(50) NOT NULL DEFAULT '' COMMENT '姓名' AFTER `un`,
            ADD COLUMN `mobile` varchar(20) NOT NULL DEFAULT '' COMMENT '手机号' AFTER `truename`");
    }
}
