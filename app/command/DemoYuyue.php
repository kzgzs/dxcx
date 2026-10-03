<?php

declare(strict_types=1);

namespace app\command;

use app\common\service\DemoYuyueGenerator;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * 预约服务演示数据命令：薄封装，真正逻辑在 {@see DemoYuyueGenerator}。
 *
 * 用法：
 *   php think demo:yuyue            生成演示类目/商品/技师/全状态订单/派单/提成/提现/评价
 *   php think demo:yuyue --reset    先删除已生成的 DEMO-YY- 数据再重建
 *   php think demo:yuyue --purge    仅清除全部 DEMO-YY- 演示数据（不生成）
 *   php think demo:yuyue --aid=1    指定平台租户 ID（默认 1）
 */
class DemoYuyue extends Command
{
    protected function configure(): void
    {
        $this->setName('demo:yuyue')
            ->setDescription('生成租户后台「扩展·预约服务」演示数据（类目/商品/技师/订单全状态/派单/提成/提现/评价，DEMO-YY- 前缀，幂等，--purge 清除）')
            ->addOption('reset', null, Option::VALUE_NONE, '先删除已生成的 DEMO-YY- 数据再重建')
            ->addOption('purge', null, Option::VALUE_NONE, '仅删除全部 DEMO-YY- 演示数据，不生成')
            ->addOption('aid', null, Option::VALUE_OPTIONAL, '平台租户ID', '1');
    }

    protected function execute(Input $input, Output $output): void
    {
        $purge = (bool) $input->getOption('purge');
        $reset = (bool) $input->getOption('reset');
        $generator = app(DemoYuyueGenerator::class);

        if ($purge) {
            $generator->purge();
            $output->writeln('<info>已清除全部 DEMO-YY- 预约演示数据</info>');
            return;
        }

        if ($reset) {
            $generator->purge();
        }
        $generator->generate();
        $output->writeln('<info>预约服务演示数据生成完成（DEMO-YY- 前缀，7笔订单覆盖待支付/已支付待派单/服务中/已完成/已取消/已退款，含上门+到店）</info>');
    }
}
