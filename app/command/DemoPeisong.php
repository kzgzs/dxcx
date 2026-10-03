<?php

declare(strict_types=1);

namespace app\command;

use app\common\service\DemoPeisongGenerator;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * 同城配送演示数据命令：薄封装，真正逻辑在 {@see DemoPeisongGenerator}。
 *
 * 用法：
 *   php think demo:peisong            生成演示配送员(三态)/配送单(全状态)/评价/提成流水/提现(四态)
 *   php think demo:peisong --reset    先删除已生成的 DEMO-PS- 数据再重建
 *   php think demo:peisong --purge    仅清除全部 DEMO-PS- 演示数据（不生成）
 *   php think demo:peisong --aid=1    指定平台租户 ID（默认 1）
 */
class DemoPeisong extends Command
{
    protected function configure(): void
    {
        $this->setName('demo:peisong')
            ->setDescription('生成租户后台「扩展·同城配送」演示数据（配送员三态/配送单0待接单1已接单2已到店3已取货4已完成10已取消/评价/提成/提现四态，DEMO-PS- 前缀，幂等，--purge 清除）')
            ->addOption('reset', null, Option::VALUE_NONE, '先删除已生成的 DEMO-PS- 数据再重建')
            ->addOption('purge', null, Option::VALUE_NONE, '仅删除全部 DEMO-PS- 演示数据，不生成')
            ->addOption('aid', null, Option::VALUE_OPTIONAL, '平台租户ID', '1');
    }

    protected function execute(Input $input, Output $output): void
    {
        $purge = (bool) $input->getOption('purge');
        $reset = (bool) $input->getOption('reset');
        $generator = app(DemoPeisongGenerator::class);
        $generator->setAid((int) $input->getOption('aid'));

        if ($purge) {
            $generator->purge();
            $output->writeln('<info>已清除全部 DEMO-PS- 同城配送演示数据</info>');
            return;
        }

        if ($reset) {
            $generator->purge();
        }
        $generator->generate();
        $output->writeln('<info>同城配送演示数据生成完成（DEMO-PS- 前缀：配送员3名(启用2/停用1)/配送单7笔覆盖待接单/已接单/已到店/已取货/已完成/已取消/评价2/提成2/提现4态）</info>');
    }
}
