<?php

declare(strict_types=1);

use think\migration\Migrator;

/**
 * admin_set 补充佣金结算配置列（任务182 批次3-B 佣金结算定时任务）
 *
 * 点大对照 canzhao/app/common/Order.php jiesuanCommission@4246 / jiesuanCommissionWeek@4703 / giveCommission@4733：
 * - fxjiesuantime_perweek：周结开关（1 时走周结分支，delaydays 语义为星期几）
 * - commission_send_wallet：结算发放钱包（0 到佣金 1 到余额）
 * - commission_send_wallet_levelids：发放余额的等级限制（逗号分隔等级ID，-1 不限制）
 */
class AdminSetFxSettleColumns extends Migrator
{
    public function change(): void
    {
        $this->execute("ALTER TABLE `admin_set`
            ADD COLUMN `fxjiesuantime_perweek` tinyint(1) NOT NULL DEFAULT '0' COMMENT '分销结算按周 0日结 1周结（delaydays=星期几）',
            ADD COLUMN `commission_send_wallet` tinyint(1) NOT NULL DEFAULT '0' COMMENT '佣金结算发放钱包 0到佣金 1到余额',
            ADD COLUMN `commission_send_wallet_levelids` varchar(255) NOT NULL DEFAULT '' COMMENT '发放余额等级限制（逗号分隔等级ID，-1不限制）'");
    }
}
