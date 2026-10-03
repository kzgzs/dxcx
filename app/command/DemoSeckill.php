<?php

declare(strict_types=1);

namespace app\command;

use app\common\service\DemoSeckillGenerator;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * 秒杀演示订单命令：薄封装，真正逻辑在 {@see DemoSeckillGenerator}。
 *
 * 用法：
 *   php think demo:seckill            生成演示商品+5笔订单（已支付快递/已支付自提/已发货/已完成/已关闭）
 *   php think demo:seckill --reset    先删除已生成的 DEMO-SK- 订单再重建
 *   php think demo:seckill --purge    仅清除全部 DEMO-SK- 演示订单（不生成）
 *   php think demo:seckill --aid=1    指定平台租户 ID（默认 1）
 */
class DemoSeckill extends Command
{
    protected function configure(): void
    {
        $this->setName('demo:seckill')
            ->setDescription('生成秒杀活动订单管理演示数据（4种状态行操作差异可见，幂等，--purge 清除）')
            ->addOption('reset', null, Option::VALUE_NONE, '先删除已生成的 DEMO-SK- 订单再重建')
            ->addOption('purge', null, Option::VALUE_NONE, '仅删除全部 DEMO-SK- 演示订单，不生成')
            ->addOption('aid', null, Option::VALUE_OPTIONAL, '平台租户ID', '1');
    }

    protected function execute(Input $input, Output $output): void
    {
        $aid       = max(1, (int) $input->getOption('aid'));
        $generator = app(DemoSeckillGenerator::class);

        $generator->run([
            'reset' => (bool) $input->getOption('reset'),
            'purge' => (bool) $input->getOption('purge'),
            'aid'   => $aid,
        ]);
    }
}
