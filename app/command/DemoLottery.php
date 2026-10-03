<?php

declare(strict_types=1);

namespace app\command;

use app\common\service\DemoLotteryGenerator;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * 抽奖活动演示数据命令：薄封装，真正逻辑在 {@see DemoLotteryGenerator}。
 *
 * 用法：
 *   php think demo:lottery            生成大转盘+刮刮卡活动各1个，记录覆盖未领取/已领取/未中奖
 *   php think demo:lottery --reset    先删除已生成的 【DEMO-LOTTERY】 数据再重建
 *   php think demo:lottery --purge    仅清除全部 【DEMO-LOTTERY】 演示数据（不生成）
 *   php think demo:lottery --aid=1    指定平台租户 ID（默认 1）
 */
class DemoLottery extends Command
{
    protected function configure(): void
    {
        $this->setName('demo:lottery')
            ->setDescription('生成抽奖活动（大转盘+刮刮卡）管理演示数据，记录覆盖未领取/已领取/未中奖，幂等，--purge 清除')
            ->addOption('reset', null, Option::VALUE_NONE, '先删除已生成的 【DEMO-LOTTERY】 数据再重建')
            ->addOption('purge', null, Option::VALUE_NONE, '仅删除全部 【DEMO-LOTTERY】 演示数据，不生成')
            ->addOption('aid', null, Option::VALUE_OPTIONAL, '平台租户ID', '1');
    }

    protected function execute(Input $input, Output $output): void
    {
        $purge = (bool) $input->getOption('purge');
        $reset = (bool) $input->getOption('reset');
        $generator = app(DemoLotteryGenerator::class);

        if ($purge) {
            $generator->purge();
            $output->writeln('<info>已清除全部 【DEMO-LOTTERY】 演示数据</info>');
            return;
        }

        if ($reset) {
            $generator->purge();
        }
        $generator->generate();
        $output->writeln('<info>抽奖活动演示数据生成完成（大转盘+刮刮卡各1个，记录覆盖未领取/已领取/未中奖）</info>');
    }
}
