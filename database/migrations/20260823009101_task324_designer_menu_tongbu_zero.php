<?php

// 任务324：底部导航「同步到其他端」统一默认否 —— designer_menu 存量行 tongbu 一次性置 0。
// 幂等：仅命中 tongbu<>0 的行，重跑无副作用。

use think\facade\Db;
use think\migration\Migrator;

class Task324DesignerMenuTongbuZero extends Migrator
{
    public function up()
    {
        $exists = Db::query("SHOW TABLES LIKE 'designer_menu'");
        if (empty($exists)) {
            return;
        }
        Db::execute("UPDATE `designer_menu` SET `tongbu` = 0 WHERE `tongbu` <> 0");
    }

    public function down()
    {
        // 数据清零不可逆，无需回滚
    }
}
