<?php
// 批次4-E 任务190：fifa 赛事表补租户字段
// 基线 fifa 表为点大外部 API 拉取的全局表（无 aid）；点大外部 API 已过时，
// 本系统改为租户手工录入，需按 aid 隔离，补 aid/createtime 列

use think\migration\Migrator;

class FifaAidColumn extends Migrator
{
    public function change()
    {
        $this->execute('ALTER TABLE `fifa` ADD COLUMN `aid` int(11) DEFAULT \'0\' COMMENT \'租户id（手工录入口径）\' AFTER `id`');
        $this->execute('ALTER TABLE `fifa` ADD COLUMN `createtime` int(11) DEFAULT NULL COMMENT \'录入时间\' AFTER `startTimestamp`');
        $this->execute('ALTER TABLE `fifa` ADD INDEX `aid` (`aid`)');
    }
}
