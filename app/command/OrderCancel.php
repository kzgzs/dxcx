<?php

declare(strict_types=1);

namespace app\command;

use app\common\service\OrderService;
use think\console\Command;
use think\console\Input;
use think\console\Output;

/**
 * 超时未支付订单自动取消 + 回补库存
 *
 * 用法：php think order:cancel（可重复执行，幂等）
 * 判定：status=0 且 pay_expire_time 已过（历史数据按 createtime+超时秒数兜底）
 */
class OrderCancel extends Command
{
    protected function configure()
    {
        $this->setName('order:cancel')
            ->setDescription('超时未支付订单自动取消并回补库存（幂等，可重复执行）');
    }

    protected function execute(Input $input, Output $output)
    {
        $count = app(OrderService::class)->cancelExpired();

        $output->writeln('订单超时取消完成：本次取消 ' . $count . ' 笔订单');
    }
}
