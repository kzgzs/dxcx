<?php

declare(strict_types=1);

use think\migration\Migrator;

/**
 * 音乐库表（任务311）
 *
 * - admin_music：租户音乐库（控制台「音乐控制」维护，设计编辑器音乐播放消费）
 * IF NOT EXISTS 幂等，兼容已有手工建表环境。
 */
class Task311AdminMusicTable extends Migrator
{
    public function up(): void
    {
        $this->execute("CREATE TABLE IF NOT EXISTS `admin_music` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) NOT NULL DEFAULT '0' COMMENT '租户ID',
  `name` varchar(100) NOT NULL DEFAULT '' COMMENT '音乐名称',
  `url` varchar(500) NOT NULL DEFAULT '' COMMENT '音频地址（站点相对路径或外链）',
  `size` varchar(20) NOT NULL DEFAULT '' COMMENT '文件大小（展示值，如 1.2MB）',
  `duration` int(11) NOT NULL DEFAULT '0' COMMENT '时长（秒，0未知）',
  `createtime` int(11) NOT NULL DEFAULT '0' COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `aid` (`aid`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='音乐库'");
    }
}
