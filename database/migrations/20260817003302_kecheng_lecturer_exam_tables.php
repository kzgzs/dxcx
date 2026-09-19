<?php

// 知识付费·题库与考试（批次5-D2 任务198，号段 20260817003302 起）：
// 1. 新建 kecheng_lecturer 讲师表（讲师申请/审核闭环）
// 2. kecheng_sysset 补 lecturer_open（讲师体系开关，对标点大 getcustom('kecheng_lecturer')）
// 3. 幂等兜底：kecheng_give_scorelog / kecheng_tiku.mlid / kecheng_tiku.give_score
//    （D1 迁移 20260817003301 已建，此处 INFORMATION_SCHEMA 判重，防漏）

use think\migration\Migrator;

class KechengLecturerExamTables extends Migrator
{
    public function up()
    {
        $this->execute('CREATE TABLE IF NOT EXISTS `kecheng_lecturer` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) NOT NULL DEFAULT \'0\',
  `mid` int(11) NOT NULL DEFAULT \'0\' COMMENT \'申请会员id\',
  `headimg` varchar(500) NOT NULL DEFAULT \'\' COMMENT \'头像\',
  `nickname` varchar(100) NOT NULL DEFAULT \'\' COMMENT \'昵称\',
  `realname` varchar(100) NOT NULL DEFAULT \'\' COMMENT \'姓名\',
  `tel` varchar(30) NOT NULL DEFAULT \'\' COMMENT \'手机号\',
  `shortdesc` varchar(500) NOT NULL DEFAULT \'\' COMMENT \'简介\',
  `checkstatus` tinyint(2) NOT NULL DEFAULT \'0\' COMMENT \'审核状态 0待审核 1通过 -1驳回\',
  `check_reason` varchar(255) NOT NULL DEFAULT \'\' COMMENT \'驳回原因\',
  `status` tinyint(1) NOT NULL DEFAULT \'1\' COMMENT \'启用状态 1启用 0停用\',
  `sort` int(11) NOT NULL DEFAULT \'0\',
  `createtime` int(11) DEFAULT NULL,
  `updatetime` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `aid` (`aid`) USING BTREE,
  KEY `mid` (`mid`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT=\'课程讲师\'');

        $has = $this->query("SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kecheng_sysset' AND COLUMN_NAME = 'lecturer_open'")->fetch();
        if (0 === (int) $has['c']) {
            $this->execute("ALTER TABLE `kecheng_sysset`
  ADD COLUMN `lecturer_open` tinyint(1) NOT NULL DEFAULT '0' COMMENT '讲师体系开关 1开启 0关闭' AFTER `give_score_num`");
        }

        $this->execute('CREATE TABLE IF NOT EXISTS `kecheng_give_scorelog` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) DEFAULT NULL,
  `bid` int(11) NOT NULL DEFAULT \'0\',
  `mid` int(11) DEFAULT NULL,
  `kccid` int(11) NOT NULL DEFAULT \'0\' COMMENT \'章节id kecheng_chapter.id\',
  `score` int(11) NOT NULL DEFAULT \'0\' COMMENT \'赠送积分\',
  `createtime` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `aid` (`aid`) USING BTREE,
  KEY `mid` (`mid`) USING BTREE,
  KEY `kccid` (`kccid`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT=\'课程学习送积分流水\'');

        // kecheng_tiku 补列（D1 已加则跳过）
        $has = $this->query("SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kecheng_tiku' AND COLUMN_NAME = 'mlid'")->fetch();
        if (0 === (int) $has['c']) {
            $this->execute("ALTER TABLE `kecheng_tiku` ADD COLUMN `mlid` int(11) NOT NULL DEFAULT '0' COMMENT '章节id（按章节出题）' AFTER `kcid`");
        }
        $has = $this->query("SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kecheng_tiku' AND COLUMN_NAME = 'give_score'")->fetch();
        if (0 === (int) $has['c']) {
            $this->execute("ALTER TABLE `kecheng_tiku` ADD COLUMN `give_score` int(11) NOT NULL DEFAULT '0' COMMENT '答对赠送积分' AFTER `jiexi`");
        }
    }

    public function down()
    {
        $this->execute('DROP TABLE IF EXISTS `kecheng_lecturer`');
        $has = $this->query("SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kecheng_sysset' AND COLUMN_NAME = 'lecturer_open'")->fetch();
        if ((int) $has['c'] > 0) {
            $this->execute('ALTER TABLE `kecheng_sysset` DROP COLUMN `lecturer_open`');
        }
    }
}
