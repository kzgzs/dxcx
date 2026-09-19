<?php
// 系统升级日志表 - 对照点大升级日志（版本号/版本说明/升级时间）
use think\migration\Migrator;

class CreateUpgradeLogTable extends Migrator
{
    public function change()
    {
        $this->execute('CREATE TABLE `upgrade_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `version` varchar(50) DEFAULT NULL COMMENT \'版本号\',
  `remark` text COMMENT \'版本说明\',
  `createtime` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT=\'系统升级日志\'');
    }
}
