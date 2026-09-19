<?php

declare(strict_types=1);

use think\migration\Migrator;

/**
 * 公众号素材库 description 列扩容（批次7-4 任务212）
 *
 * description 存图文 articles JSON（多篇文章+正文HTML），varchar(255) 会截断报错，
 * 与 canzhao mp_material 口径一致扩为 longtext
 */
class MpMaterialDescriptionLongtext extends Migrator
{
    public function change(): void
    {
        $this->execute("ALTER TABLE `mp_material`
            MODIFY COLUMN `description` longtext COMMENT '图文素材 articles JSON'");
    }
}
