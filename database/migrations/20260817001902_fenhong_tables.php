<?php

// P19-5 分红体系（批次3-C 任务183）
// 对齐点大 canzhao/app/common/Fenhong.php 口径：
// - member_fenhonglog 已存在（core_tables 迁移）：分红待发放/已发放记录
// - member_fenhong_max_log 股东分红封顶记录：发放时超过等级 fenhong_max_money 上限的裁剪留痕

use think\migration\Migrator;

class FenhongTables extends Migrator
{
    public function up()
    {
        $this->execute("CREATE TABLE IF NOT EXISTS `member_fenhong_max_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) NOT NULL DEFAULT '0' COMMENT '租户ID',
  `mid` int(11) NOT NULL DEFAULT '0' COMMENT '会员ID',
  `levelid` int(11) NOT NULL DEFAULT '0' COMMENT '股东等级ID',
  `type` varchar(50) NOT NULL DEFAULT 'fenhong' COMMENT '分红类型',
  `max_money` decimal(11,2) NOT NULL DEFAULT '0.00' COMMENT '等级分红上限',
  `had_total` decimal(11,2) NOT NULL DEFAULT '0.00' COMMENT '本次发放前累计已获得',
  `send_money` decimal(11,2) NOT NULL DEFAULT '0.00' COMMENT '本次实际发放（裁剪后）',
  `logid` int(11) NOT NULL DEFAULT '0' COMMENT '对应 member_fenhonglog ID',
  `createtime` int(11) DEFAULT NULL COMMENT '创建时间',
  `remark` varchar(255) DEFAULT NULL COMMENT '备注',
  PRIMARY KEY (`id`),
  KEY `aid` (`aid`) USING BTREE,
  KEY `mid` (`mid`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='股东分红封顶记录'");
    }

    public function down()
    {
        $this->execute('DROP TABLE IF EXISTS `member_fenhong_max_log`');
    }
}
