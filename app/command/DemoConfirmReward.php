<?php

declare(strict_types=1);

namespace app\command;

use app\common\service\DemoConfirmRewardSeed;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * 租户后台「客户端·营销 → 确认收货奖励」演示数据命令（demo:confirmreward）。
 *
 * 用法：
 *   php think demo:confirmreward            生成活动设置 + 12 条奖励记录（幂等，可重复执行）
 *   php think demo:confirmreward --reset    先删除 DEMO-CR- 前缀演示数据再重建
 *   php think demo:confirmreward --purge    仅清除 DEMO-CR- 前缀演示数据，不生成
 *   php think demo:confirmreward --aid=1    指定平台租户 ID（默认 1）
 */
class DemoConfirmReward extends Command
{
    protected function configure(): void
    {
        $this->setName('demo:confirmreward')
            ->setDescription('生成租户后台「客户端·营销→确认收货奖励」演示数据（设置+记录，DEMO-CR- 前缀，--purge 清除）')
            ->addOption('reset', null, Option::VALUE_NONE, '先删除 DEMO-CR- 前缀演示数据再重建')
            ->addOption('purge', null, Option::VALUE_NONE, '仅删除 DEMO-CR- 前缀演示数据，不生成')
            ->addOption('aid', null, Option::VALUE_OPTIONAL, '平台租户ID', '1');
    }

    protected function execute(Input $input, Output $output): void
    {
        $aid = max(1, (int) $input->getOption('aid'));
        $seed = app(DemoConfirmRewardSeed::class);

        if ((bool) $input->getOption('purge')) {
            $res = $seed->purge($aid);
            $output->writeln('<info>已清理：设置 ' . $res['settings'] . '、记录 ' . $res['records'] . '。</info>');
            return;
        }

        $result = $seed->generate($aid, (bool) $input->getOption('reset'));
        foreach ($result['logs'] as $line) {
            $output->writeln($line);
        }
    }
}
