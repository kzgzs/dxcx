<?php
// 通知公告表 - 对照点大 ddwx_admin_notice
use think\migration\Migrator;

class CreateAdminNoticeTable extends Migrator
{
    public function change()
    {
        $this->execute('CREATE TABLE `admin_notice` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) DEFAULT NULL,
  `bid` int(11) DEFAULT \'0\',
  `uid` int(11) DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `content` longtext,
  `createtime` int(11) DEFAULT NULL,
  `isread` tinyint(1) DEFAULT \'0\',
  `readtime` int(11) DEFAULT NULL,
  `type` tinyint(1) DEFAULT \'0\' COMMENT \'0 系统发送 1 总后台发送 2 管理员发送\',
  PRIMARY KEY (`id`),
  KEY `aid` (`aid`) USING BTREE,
  KEY `uid` (`uid`) USING BTREE,
  KEY `bid` (`bid`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT=\'通知公告表\'');
    }
}
