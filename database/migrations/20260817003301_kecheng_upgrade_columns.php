<?php

// 知识付费升级字段补丁（批次5-D1 任务197，号段 20260817003301 起）：
// 对标点大 canzhao ApiKecheng.php 的升级增量字段：
// 1. kecheng_list   补 orderlearn（顺序学习）/learnhg（章节考试合格解锁）/lecturerid/fenhongset
// 2. kecheng_chapter 补 parent_id（章节子级）/give_score（学完送积分）
// 3. kecheng_sysset  补 send_score_num/give_score_num（答题送积分次数口径，D2 消费）
// 4. kecheng_record/recordlog/tiku 补 mlid 等章节维度字段（D2 题库考试地基，本期不实现考试接口）
// 5. kecheng_order  补 pay_amount（统一支付单 pay_order 取金额字段）
// 6. 新建 kecheng_give_scorelog（学习送积分去重流水）

use think\migration\Migrator;

class KechengUpgradeColumns extends Migrator
{
    public function up()
    {
        $this->execute("ALTER TABLE `kecheng_list`
  ADD COLUMN `orderlearn` tinyint(1) NOT NULL DEFAULT '0' COMMENT '顺序学习 1开启' AFTER `showtj`,
  ADD COLUMN `learnhg` tinyint(1) NOT NULL DEFAULT '0' COMMENT '章节考试合格才解锁下一章 1开启' AFTER `orderlearn`,
  ADD COLUMN `lecturerid` int(11) NOT NULL DEFAULT '0' COMMENT '关联讲师id' AFTER `learnhg`,
  ADD COLUMN `fenhongset` tinyint(1) NOT NULL DEFAULT '1' COMMENT '分红设置 1参与 0不参与' AFTER `lecturerid`");

        $this->execute("ALTER TABLE `kecheng_chapter`
  ADD COLUMN `parent_id` int(11) NOT NULL DEFAULT '0' COMMENT '父章节id 0顶级' AFTER `jumpurl`,
  ADD COLUMN `give_score` int(11) NOT NULL DEFAULT '0' COMMENT '学完本章赠送积分' AFTER `parent_id`");

        $this->execute("ALTER TABLE `kecheng_sysset`
  ADD COLUMN `send_score_num` tinyint(1) NOT NULL DEFAULT '0' COMMENT '答题积分仅首次发放 1仅首次 0每次' AFTER `ios_canbuy`,
  ADD COLUMN `give_score_num` tinyint(1) NOT NULL DEFAULT '0' COMMENT '答题送积分仅首次 0仅首次 1每次' AFTER `send_score_num`");

        $this->execute("ALTER TABLE `kecheng_record`
  ADD COLUMN `mlid` int(11) NOT NULL DEFAULT '0' COMMENT '章节id（顺序学习按章节考试）' AFTER `kcid`");

        $this->execute("ALTER TABLE `kecheng_recordlog`
  ADD COLUMN `mlid` int(11) NOT NULL DEFAULT '0' COMMENT '章节id' AFTER `kcid`,
  ADD COLUMN `give_score` int(11) NOT NULL DEFAULT '0' COMMENT '本题赠送积分' AFTER `score`");

        $this->execute("ALTER TABLE `kecheng_tiku`
  ADD COLUMN `mlid` int(11) NOT NULL DEFAULT '0' COMMENT '章节id（按章节出题）' AFTER `kcid`,
  ADD COLUMN `give_score` int(11) NOT NULL DEFAULT '0' COMMENT '答对赠送积分' AFTER `jiexi`");

        $this->execute("ALTER TABLE `kecheng_order`
  ADD COLUMN `pay_amount` decimal(11,2) NOT NULL DEFAULT '0.00' COMMENT '统一支付单金额（与 totalprice 一致）' AFTER `totalprice`");

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
    }

    public function down()
    {
        $this->execute('ALTER TABLE `kecheng_list` DROP COLUMN `orderlearn`, DROP COLUMN `learnhg`, DROP COLUMN `lecturerid`, DROP COLUMN `fenhongset`');
        $this->execute('ALTER TABLE `kecheng_chapter` DROP COLUMN `parent_id`, DROP COLUMN `give_score`');
        $this->execute('ALTER TABLE `kecheng_sysset` DROP COLUMN `send_score_num`, DROP COLUMN `give_score_num`');
        $this->execute('ALTER TABLE `kecheng_record` DROP COLUMN `mlid`');
        $this->execute('ALTER TABLE `kecheng_recordlog` DROP COLUMN `mlid`, DROP COLUMN `give_score`');
        $this->execute('ALTER TABLE `kecheng_tiku` DROP COLUMN `mlid`, DROP COLUMN `give_score`');
        $this->execute('ALTER TABLE `kecheng_order` DROP COLUMN `pay_amount`');
        $this->execute('DROP TABLE IF EXISTS `kecheng_give_scorelog`');
    }
}
