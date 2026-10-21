<?php
// 收银台设置：对齐点大 restaurant_cashdesk 字段，幂等补列
// 点大收银设置含「会员ID搜索 is_use_mid_search」「积分赠送 cashdesk_give_score_set」，
// 基线表缺这两列；已存在则跳过（不重复 ALTER），保证在任意环境可重复执行。

use think\migration\Migrator;

class CashierSetFields extends Migrator
{
    public function change()
    {
        $ensure = [
            'restaurant_cashdesk' => [
                'is_use_mid_search'        => "ADD COLUMN `is_use_mid_search` tinyint(1) NOT NULL DEFAULT '1' COMMENT '会员ID搜索 0关闭 1开启'",
                'cashdesk_give_score_set'  => "ADD COLUMN `cashdesk_give_score_set` tinyint(1) NOT NULL DEFAULT '1' COMMENT '积分赠送 0关闭 1按系统设置消费送积分'",
            ],
        ];

        foreach ($ensure as $table => $columns) {
            foreach ($columns as $column => $ddl) {
                $row = $this->fetchRow(
                    "SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = '{$table}' AND column_name = '{$column}' LIMIT 1"
                );
                if (!$row) {
                    $this->execute("ALTER TABLE `{$table}` {$ddl}");
                }
            }
        }
    }
}
