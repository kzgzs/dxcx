<?php

declare(strict_types=1);

use think\migration\Migrator;

/**
 * 模板/客服群发日志补断点续发游标列（批次7-4 任务212）
 *
 * last_page=最后完成页码（0=未开始），pagelimit=该批次每页大小；
 * 续发时服务端按 last_page+1 定位，保证中断续发不重发
 */
class MpSendlogCursorColumns extends Migrator
{
    public function change(): void
    {
        $this->execute("ALTER TABLE `mp_tmpl_sendlog`
            ADD COLUMN `last_page` int NOT NULL DEFAULT '0' COMMENT '断点续发游标：最后完成页码' AFTER `errorcount`,
            ADD COLUMN `pagelimit` int NOT NULL DEFAULT '0' COMMENT '该批次每页发送数量' AFTER `last_page`");

        $this->execute("ALTER TABLE `mp_kfmsg_sendlog`
            ADD COLUMN `last_page` int NOT NULL DEFAULT '0' COMMENT '断点续发游标：最后完成页码' AFTER `errorcount`,
            ADD COLUMN `pagelimit` int NOT NULL DEFAULT '0' COMMENT '该批次每页发送数量' AFTER `last_page`");
    }
}
