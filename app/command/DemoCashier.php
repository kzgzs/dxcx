<?php

declare(strict_types=1);

namespace app\command;

use app\common\service\DemoCashierGenerator;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * 收银台演示数据命令：薄封装，真正逻辑在 {@see DemoCashierGenerator}。
 *
 * 用法：
 *   php think demo:cashier            生成收银订单8条(全状态)+交班打印机1台
 *   php think demo:cashier --reset    先删除已生成的 DEMO-CS- 数据再重建
 *   php think demo:cashier --purge    仅清除全部 DEMO-CS- 演示数据（不生成）
 *   php think demo:cashier --aid=1    指定平台租户 ID（默认 1）
 */
class DemoCashier extends Command
{
    protected function configure(): void
    {
        $this->setName('demo:cashier')
            ->setDescription('生成租户后台「扩展·收银台」演示数据（收银订单8条[已支付现金/微信/余额/挂单/已退款/未支付/已关闭]+交班打印机1台，DEMO-CS- 前缀，幂等，--purge 清除）')
            ->addOption('reset', null, Option::VALUE_NONE, '先删除已生成的 DEMO-CS- 数据再重建')
            ->addOption('purge', null, Option::VALUE_NONE, '仅删除全部 DEMO-CS- 演示数据，不生成')
            ->addOption('aid', null, Option::VALUE_OPTIONAL, '平台租户ID', '1');
    }

    protected function execute(Input $input, Output $output): void
    {
        $purge = (bool) $input->getOption('purge');
        $reset = (bool) $input->getOption('reset');
        $generator = app(DemoCashierGenerator::class);
        $generator->setAid((int) $input->getOption('aid'));

        if ($purge) {
            $generator->purge();
            $output->writeln('<info>已清除全部 DEMO-CS- 收银台演示数据</info>');
            return;
        }

        if ($reset) {
            $generator->purge();
        }
        $generator->generate();
        $output->writeln('<info>收银台演示数据生成完成（DEMO-CS- 前缀：收银订单8条全状态+交班打印机1台）</info>');
    }
}
