<?php

declare(strict_types=1);

namespace app\command;

use app\common\service\DemoTuangouGenerator;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * 团购活动演示订单命令：薄封装，真正逻辑在 {@see DemoTuangouGenerator}。
 *
 * 用法：
 *   php think demo:tuangou            生成演示商品+订单（已支付快递/已支付自提/已发货/已完成/已关闭）
 *   php think demo:tuangou --reset    先删除已生成的 DEMO-TG- 订单再重建
 *   php think demo:tuangou --purge    仅清除全部 DEMO-TG- 演示订单（不生成）
 *   php think demo:tuangou --aid=1    指定平台租户 ID（默认 1）
 */
class DemoTuangou extends Command
{
    protected function configure(): void
    {
        $this->setName('demo:tuangou')
            ->setDescription('生成团购活动订单管理演示数据（状态覆盖已支付/已发货/已完成/已关闭，自提+快递，幂等，--purge 清除）')
            ->addOption('reset', null, Option::VALUE_NONE, '先删除已生成的 DEMO-TG- 订单再重建')
            ->addOption('purge', null, Option::VALUE_NONE, '仅删除全部 DEMO-TG- 演示订单，不生成')
            ->addOption('aid', null, Option::VALUE_OPTIONAL, '平台租户ID', '1');
    }

    protected function execute(Input $input, Output $output): void
    {
        $purge = (bool) $input->getOption('purge');
        $reset = (bool) $input->getOption('reset');
        $generator = app(DemoTuangouGenerator::class);

        if ($purge) {
            $generator->purge();
            $output->writeln('<info>已清除全部 DEMO-TG- 演示数据</info>');
            return;
        }

        if ($reset) {
            $generator->purge();
        }
        $generator->generate();
        $output->writeln('<info>团购演示数据生成完成（DEMO-TG- 前缀，6笔订单覆盖4种状态）</info>');
    }
}
