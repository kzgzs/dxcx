<?php

// 批次6 T6-6（任务207）餐饮收银台二期：预留号段补列（幂等）
//
// restaurant_cashdesk 补 reverse_audit：反结账审核开关
//   0=免审核直接反结账  1=需审核（收银台发起申请，审核通过后才回滚）

use think\migration\Migrator;

class RestaurantCashdeskReverseAudit extends Migrator
{
    public function change()
    {
        $this->addColumnIfMissing('restaurant_cashdesk', 'reverse_audit', "ADD COLUMN `reverse_audit` tinyint(1) DEFAULT '0' COMMENT '反结账审核开关 0免审核 1需审核'");
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
