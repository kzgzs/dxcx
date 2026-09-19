<?php

// 任务255 系统设置新增"分销设置"Tab：admin_set 补平级奖结算方式列（INFORMATION_SCHEMA 判重，幂等）
//
// 口径对齐 canzhao（点大参考源码，只读）backstage/sysset.html 分销板块：
// - fxjiesuantype_pj ← L3069-3086 分销平级奖结算方式（0按上级奖励 1按商品价格 2按成交价格 3按销售利润）
//
// 其余分销字段基线已有：
// - fxjiesuantype / fxjiesuantime / fxjiesuantime_delaydays / fx_differential（20260812000011 核心表）
// - fxjiesuantime_perweek / commission_send_wallet / commission_send_wallet_levelids（20260817001901）

use think\migration\Migrator;

class AdminSetFxPjColumn extends Migrator
{
    public function change()
    {
        $this->addColumnIfMissing('admin_set', 'fxjiesuantype_pj', "ADD COLUMN `fxjiesuantype_pj` tinyint(1) DEFAULT '0' COMMENT '分销平级奖结算方式 0按上级奖励 1按商品价格 2按成交价格 3按销售利润'");
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
