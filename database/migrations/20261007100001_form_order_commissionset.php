<?php
// 自定义表单引擎完善：form_order 补 commissionset 列（幂等补列）
// 对齐 canzhao：recordsetst 确认发放佣金时按订单自身 commissionset 判定（!=-1 才发放），
// 提交/支付时从 form 定义快照写入，避免表单后续修改影响历史订单结算口径。

use think\migration\Migrator;

class FormOrderCommissionset extends Migrator
{
    public function change()
    {
        $ensure = [
            'form_order' => [
                'commissionset' => "ADD COLUMN `commissionset` tinyint(1) NOT NULL DEFAULT '-1' COMMENT '分销设置快照 0按会员等级 1提成比例 2提成金额 -1不参与分销'",
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
