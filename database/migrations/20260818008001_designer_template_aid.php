<?php

// 任务250（P27-6 模板库深化）：designer_template 增加 aid 列（租户隔离）
// - aid=0：平台模板（现存两行种子保持默认值 0，不改动）
// - aid>0：租户自建模板（「我的页面」存为模板时写入当前租户 aid）
// 幂等：列已存在时跳过（information_schema 探测）

use think\migration\Migrator;

class DesignerTemplateAid extends Migrator
{
    public function change()
    {
        $exists = $this->fetchRow(
            "SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'designer_template' AND column_name = 'aid' LIMIT 1"
        );
        if ($exists) {
            return;
        }

        $this->execute("ALTER TABLE `designer_template` ADD COLUMN `aid` int(11) NOT NULL DEFAULT '0' COMMENT '租户ID 0平台模板' AFTER `id`, ADD KEY `aid` (`aid`) USING BTREE");
        // 存量种子行默认即 aid=0（平台模板），无需回填
    }
}
