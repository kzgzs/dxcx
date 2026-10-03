<?php

declare(strict_types=1);

namespace app\command;

use app\common\service\DemoScoreshopGenerator;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * 积分兑换演示订单命令：薄封装，真正逻辑在 {@see DemoScoreshopGenerator}。
 *
 * 用法：
 *   php think demo:scoreshop            生成演示商品+订单（已支付/未支付/已发货/已完成/已关闭，自提+快递）
 *   php think demo:scoreshop --reset    先删除已生成的 DEMO-SCORE- 订单再重建
 *   php think demo:scoreshop --purge    仅清除全部 DEMO-SCORE- 演示订单（不生成）
 *   php think demo:scoreshop --aid=1    指定平台租户 ID（默认 1）
 */
class DemoScoreshop extends Command
{
    protected function configure(): void
    {
        $this->setName('demo:scoreshop')
            ->setDescription('生成积分兑换订单管理演示数据（状态覆盖已支付/未支付/已发货/已完成/已关闭，自提+快递，幂等，--purge 清除）')
            ->addOption('reset', null, Option::VALUE_NONE, '先删除已生成的 DEMO-SCORE- 订单再重建')
            ->addOption('purge', null, Option::VALUE_NONE, '仅删除全部 DEMO-SCORE- 演示订单，不生成')
            ->addOption('aid', null, Option::VALUE_OPTIONAL, '平台租户ID', '1');
    }

    protected function execute(Input $input, Output $output): void
    {
        $purge = (bool) $input->getOption('purge');
        $reset = (bool) $input->getOption('reset');
        $generator = app(DemoScoreshopGenerator::class);

        if ($purge) {
            $generator->purge();
            $output->writeln('<info>已清除全部 DEMO-SCORE- 演示数据</info>');
            return;
        }

        if ($reset) {
            $generator->purge();
        }
        $generator->generate();
        $output->writeln('<info>积分兑换演示数据生成完成（DEMO-SCORE- 前缀，7笔订单覆盖5种状态）</info>');
    }
}
