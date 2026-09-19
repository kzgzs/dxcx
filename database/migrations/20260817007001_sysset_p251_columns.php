<?php

// P25-1 系统设置缺口补齐：admin_set 补列（INFORMATION_SCHEMA 判重，幂等）
//
// 口径对齐 canzhao（点大参考源码，只读）sysset.html：
// - main_business       ← L186 主营业务（商品列表价格下展示，建议20字以内）
// - recharge_minimum    ← L771-780 充值门槛（最低充值余额，0为不限制）
// - score_expire_status ← L2544-2552 积分过期开关（开启后达到过期天数的积分会过期）
// - score_expire_days   ← L2553-2561 积分过期天数（0为不过期）
//
// 注册协议走独立表 admin_set_xieyi（基线 20260812000011 已建，无需迁移）。

use think\migration\Migrator;

class SyssetP251Columns extends Migrator
{
    public function change()
    {
        $this->addColumnIfMissing('admin_set', 'main_business', "ADD COLUMN `main_business` varchar(255) DEFAULT '' COMMENT '主营业务（商品列表价格下展示）'");
        $this->addColumnIfMissing('admin_set', 'recharge_minimum', "ADD COLUMN `recharge_minimum` decimal(11,2) DEFAULT '0.00' COMMENT '最低充值金额（0为不限制）'");
        $this->addColumnIfMissing('admin_set', 'score_expire_status', "ADD COLUMN `score_expire_status` tinyint(1) DEFAULT '0' COMMENT '积分过期开关 0关 1开'");
        $this->addColumnIfMissing('admin_set', 'score_expire_days', "ADD COLUMN `score_expire_days` int(11) DEFAULT '0' COMMENT '积分过期天数（0为不过期）'");
    }

    /**
     * 列不存在时补列（幂等）
     */
    protected function addColumnIfMissing(string $table, string $column, string $alter): void
    {
        $exists = $this->fetchRow(
            "SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = '{$table}' AND column_name = '{$column}' LIMIT 1"
        );
        if ($exists) {
            return;
        }

        $this->execute("ALTER TABLE `{$table}` {$alter}");
    }
}
