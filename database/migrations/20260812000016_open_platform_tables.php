<?php
// 微信开放平台第三方平台：组件配置 / 授权方 / verify_ticket 流水
// open_component   单行配置表（含 token 兜底缓存列）
// open_authorizer  授权方（appid 唯一，auth_status 软标记）
// open_ticket_log  component_verify_ticket 接收流水
// token 缓存主存 Redis（键前缀 open:），open_component 扩展列做 DB 兜底

use think\migration\Migrator;

class OpenPlatformTables extends Migrator
{
    public function change()
    {
        $this->execute("CREATE TABLE IF NOT EXISTS `open_component` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '第三方平台开关 0关闭 1开启',
            `component_appid` varchar(64) NOT NULL DEFAULT '' COMMENT '第三方平台组件AppID',
            `component_appsecret` varchar(128) NOT NULL DEFAULT '' COMMENT '组件AppSecret',
            `token` varchar(128) NOT NULL DEFAULT '' COMMENT '消息校验Token',
            `encoding_aes_key` varchar(64) NOT NULL DEFAULT '' COMMENT '消息加解密Key（43位字母数字）',
            `ip_whitelist` varchar(500) NOT NULL DEFAULT '' COMMENT 'IP白名单（逗号分隔）',
            `auth_domain` varchar(255) NOT NULL DEFAULT '' COMMENT '授权发起页域名',
            `mock_mode` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Mock模式 0关 1开（无资质模拟闭环）',
            `component_access_token` varchar(512) NOT NULL DEFAULT '' COMMENT 'DB兜底缓存：component_access_token',
            `token_expire` int unsigned NOT NULL DEFAULT '0' COMMENT 'DB兜底token过期时间戳',
            `create_time` int unsigned NOT NULL DEFAULT '0' COMMENT '创建时间',
            `update_time` int unsigned NOT NULL DEFAULT '0' COMMENT '更新时间',
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='开放平台第三方组件配置（单行）'");

        $this->execute("CREATE TABLE IF NOT EXISTS `open_authorizer` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `aid` int unsigned DEFAULT NULL COMMENT '绑定租户(admin.id)，NULL未绑定',
            `authorizer_appid` varchar(64) NOT NULL DEFAULT '' COMMENT '授权方AppID',
            `refresh_token` varchar(512) NOT NULL DEFAULT '' COMMENT '最新authorizer_refresh_token',
            `refresh_token_backup` varchar(512) NOT NULL DEFAULT '' COMMENT 'refresh_token备份（上一份）',
            `nick_name` varchar(128) NOT NULL DEFAULT '' COMMENT '授权方昵称',
            `head_img` varchar(500) NOT NULL DEFAULT '' COMMENT '授权方头像',
            `user_name` varchar(64) NOT NULL DEFAULT '' COMMENT '原始ID（gh_开头）',
            `qrcode_url` varchar(500) NOT NULL DEFAULT '' COMMENT '二维码URL',
            `principal_name` varchar(128) NOT NULL DEFAULT '' COMMENT '主体名称',
            `service_type_id` tinyint NOT NULL DEFAULT '0' COMMENT '服务类型ID',
            `verify_type_id` tinyint NOT NULL DEFAULT '0' COMMENT '认证类型ID',
            `func_info` text COMMENT '权限集JSON',
            `account_status` tinyint NOT NULL DEFAULT '0' COMMENT '微信侧账号状态',
            `auth_status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '授权状态 1授权中 0已取消（软标记）',
            `auth_time` int unsigned NOT NULL DEFAULT '0' COMMENT '最近授权时间',
            `unauth_time` int unsigned NOT NULL DEFAULT '0' COMMENT '最近取消授权时间',
            `refresh_alert` varchar(255) NOT NULL DEFAULT '' COMMENT 'refresh_token最近失败告警信息',
            `create_time` int unsigned NOT NULL DEFAULT '0' COMMENT '创建时间',
            `update_time` int unsigned NOT NULL DEFAULT '0' COMMENT '更新时间',
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_open_authorizer_appid` (`authorizer_appid`),
            KEY `idx_open_authorizer_aid` (`aid`),
            KEY `idx_open_authorizer_auth_status` (`auth_status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='开放平台授权方'");

        $this->execute("CREATE TABLE IF NOT EXISTS `open_ticket_log` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `ticket` varchar(255) NOT NULL DEFAULT '' COMMENT 'component_verify_ticket',
            `ip` varchar(64) NOT NULL DEFAULT '' COMMENT '来源IP',
            `create_time` int unsigned NOT NULL DEFAULT '0' COMMENT '接收时间',
            PRIMARY KEY (`id`),
            KEY `idx_open_ticket_time` (`create_time`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='component_verify_ticket接收流水'");
    }
}
