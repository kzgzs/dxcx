<?php
// 周期购 片C（评价管理 + 分享海报 + 系统设置）：设置表补「会员等级折扣」开关
// 基线 cycle_sysset 已含 autoshdays(自动收货天数) / comment(商品评价) / comment_check(评价审核)；
// 本迁移仅在缺列时补 vipdiscount（是否开启会员等级折扣，对齐参考系统系统设置截图），幂等可重复执行。
// 说明：评价/海报表（cycle_comment / admin_set_poster type=cycle）字段已齐，本迁移不动它们。

use think\migration\Migrator;

class CycleMiscSyssetVipdiscount extends Migrator
{
    public function change()
    {
        $ensure = [
            'cycle_sysset' => [
                'vipdiscount' => "ADD COLUMN `vipdiscount` tinyint(1) NOT NULL DEFAULT '1' COMMENT '会员等级折扣 1开启 0关闭'",
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
