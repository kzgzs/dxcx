<?php
// 微信开放平台设置页（对照点大 WebSystem/component）：
// open_component 追加 kfappid 列（开发小程序AppID，用于上传代码到小程序模板库）

use think\migration\Migrator;

class OpenComponentKfappid extends Migrator
{
    public function change()
    {
        $this->execute("ALTER TABLE `open_component`
            ADD COLUMN `kfappid` varchar(64) NOT NULL DEFAULT '' COMMENT '开发小程序AppID（上传代码到模板库用）' AFTER `autofb`");
    }
}
