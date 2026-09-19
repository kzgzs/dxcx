<?php
// 开放平台代小程序代码管理与发布
// open_template  模板库（第三方平台模板，同步自微信 gettemplatelist）
// open_wxalog    代小程序发布流水（commit/submit_audit/release/revert/…）
// open_component 追加 autofb 列（审核通过自动发布开关）

use think\migration\Migrator;

class OpenCodeTables extends Migrator
{
    public function change()
    {
        $this->execute("CREATE TABLE IF NOT EXISTS `open_template` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `template_id` bigint NOT NULL DEFAULT '0' COMMENT '微信模板ID',
            `draft_id` bigint NOT NULL DEFAULT '0' COMMENT '来源草稿ID',
            `user_version` varchar(64) NOT NULL DEFAULT '' COMMENT '模板版本号',
            `user_desc` varchar(255) NOT NULL DEFAULT '' COMMENT '模板描述',
            `source_miniprogram` varchar(64) NOT NULL DEFAULT '' COMMENT '来源小程序appid',
            `source_miniprogram_name` varchar(128) NOT NULL DEFAULT '' COMMENT '来源小程序名称',
            `wx_create_time` int unsigned NOT NULL DEFAULT '0' COMMENT '微信侧创建时间',
            `create_time` int unsigned NOT NULL DEFAULT '0' COMMENT '同步落库时间',
            `update_time` int unsigned NOT NULL DEFAULT '0' COMMENT '更新时间',
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_open_template_id` (`template_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='开放平台代小程序模板库'");

        $this->execute("CREATE TABLE IF NOT EXISTS `open_wxalog` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `authorizer_appid` varchar(64) NOT NULL DEFAULT '' COMMENT '授权方小程序AppID',
            `aid` int unsigned DEFAULT NULL COMMENT '租户(admin.id)',
            `action` varchar(32) NOT NULL DEFAULT '' COMMENT '动作 commit/submit_audit/release/revert/audit_success/audit_fail/undo_audit/speedup_audit/domain/…',
            `template_id` bigint NOT NULL DEFAULT '0' COMMENT '关联模板ID',
            `user_version` varchar(64) NOT NULL DEFAULT '' COMMENT '版本号',
            `user_desc` varchar(255) NOT NULL DEFAULT '' COMMENT '版本描述',
            `audit_id` bigint NOT NULL DEFAULT '0' COMMENT '审核单ID',
            `audit_status` tinyint NOT NULL DEFAULT '0' COMMENT '0已上传 1审核中 2审核通过 3已发布 4审核驳回',
            `audit_reason` varchar(500) NOT NULL DEFAULT '' COMMENT '驳回原因',
            `ext_json` text COMMENT 'ext_json/请求摘要',
            `operator` varchar(64) NOT NULL DEFAULT '' COMMENT '操作人',
            `create_time` int unsigned NOT NULL DEFAULT '0' COMMENT '操作时间',
            PRIMARY KEY (`id`),
            KEY `idx_open_wxalog_appid` (`authorizer_appid`),
            KEY `idx_open_wxalog_action` (`action`),
            KEY `idx_open_wxalog_time` (`create_time`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='开放平台代小程序发布流水'");

        $this->execute("ALTER TABLE `open_component`
            ADD COLUMN `autofb` tinyint(1) NOT NULL DEFAULT '0' COMMENT '审核通过自动发布 0否 1是' AFTER `mock_mode`");
    }
}
