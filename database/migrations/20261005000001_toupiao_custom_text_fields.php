<?php
// 投票活动：活动编辑第一部分新增两个自定义文字字段
// join_num_text 参与人数自定义文字（默认「参与人数」）
// join_name_text 选手名称自定义文字（默认「选手名称」）
// 幂等：已存在则跳过（列曾被手工应用过），保证在任意环境可重复执行。

use think\migration\Migrator;

class ToupiaoCustomTextFields extends Migrator
{
    public function change()
    {
        $ensure = [
            'join_num_text'  => "ADD COLUMN `join_num_text` varchar(50) NOT NULL DEFAULT '参与人数' COMMENT '参与人数自定义文字' AFTER `helptext`",
            'join_name_text' => "ADD COLUMN `join_name_text` varchar(50) NOT NULL DEFAULT '选手名称' COMMENT '选手名称自定义文字' AFTER `join_num_text`",
        ];

        foreach ($ensure as $column => $ddl) {
            $row = $this->fetchRow(
                "SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'toupiao' AND column_name = '{$column}' LIMIT 1"
            );
            if (!$row) {
                $this->execute("ALTER TABLE `toupiao` {$ddl}");
            }
        }
    }
}
