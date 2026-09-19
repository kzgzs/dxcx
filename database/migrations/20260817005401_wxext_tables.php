<?php

declare(strict_types=1);

use think\migration\Migrator;

/**
 * 小程序扩展组本地表（批次7-5 任务213）
 *
 * - wx_keyword：小程序客服关键字回复（与公众号 mp_keyword 同构）
 * - wx_url：小程序外部链接（本地表 + 52进制混淆短码 code）
 * 类目（Wxleimu）与半屏（Wxembedded）为纯接口透传，无本地表。
 * IF NOT EXISTS 幂等，兼容已有手工建表环境。
 */
class WxextTables extends Migrator
{
    public function up(): void
    {
        $this->execute("CREATE TABLE IF NOT EXISTS `wx_keyword` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) NOT NULL DEFAULT '0' COMMENT '租户ID',
  `keyword` varchar(255) NOT NULL DEFAULT '' COMMENT '关键字',
  `ktype` int(11) NOT NULL DEFAULT '0' COMMENT '匹配方式 0全匹配 1半匹配 2关注回复',
  `msgtype` varchar(255) NOT NULL DEFAULT 'text' COMMENT '回复类型 text/image/link/miniprogrampage',
  `content` text COMMENT '回复内容（非text为JSON串）',
  `sort` int(11) NOT NULL DEFAULT '0' COMMENT '排序',
  `createtime` int(11) NOT NULL DEFAULT '0' COMMENT '创建时间',
  `status` int(11) NOT NULL DEFAULT '1' COMMENT '状态 1启用 0停用',
  PRIMARY KEY (`id`),
  KEY `aid` (`aid`) USING BTREE,
  KEY `keyword` (`keyword`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='小程序客服关键字回复'");

        $this->execute("CREATE TABLE IF NOT EXISTS `wx_url` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) NOT NULL DEFAULT '0' COMMENT '租户ID',
  `name` varchar(255) NOT NULL DEFAULT '' COMMENT '链接名称',
  `path` varchar(255) NOT NULL DEFAULT '' COMMENT '小程序路径',
  `url` varchar(255) NOT NULL DEFAULT '' COMMENT '外链地址',
  `createtime` int(11) NOT NULL DEFAULT '0' COMMENT '创建时间',
  `endtime` int(11) NOT NULL DEFAULT '0' COMMENT '有效期截止（createtime+days*86400）',
  `days` int(11) NOT NULL DEFAULT '30' COMMENT '有效期天数',
  `sort` int(11) NOT NULL DEFAULT '0' COMMENT '排序',
  `code` varchar(100) NOT NULL DEFAULT '' COMMENT '52进制混淆短码（按id生成，唯一）',
  PRIMARY KEY (`id`),
  KEY `aid` (`aid`) USING BTREE,
  KEY `code` (`code`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='小程序外部链接'");
    }
}
