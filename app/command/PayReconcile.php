<?php

declare(strict_types=1);

namespace app\command;

use app\common\service\PayService;
use think\console\Command;
use think\console\Input;
use think\console\Output;

/**
 * 支付查单补偿（对账）命令（P8 一期）
 *
 * 用法：php think pay:reconcile（由 schedule:run 统一调度，也可独立运行，幂等）
 *
 * 场景：渠道回调丢失/延迟导致 pay_order 停留 status=0，
 *       mock 模式直接按「渠道已支付」口径捞回入账；真实模式预留渠道查单位置（TODO）。
 */
class PayReconcile extends Command
{
    protected function configure()
    {
        $this->setName('pay:reconcile')
            ->setDescription('支付查单补偿：扫描超时未入账的行业支付单并捞回（幂等，可重复执行）');
    }

    protected function execute(Input $input, Output $output)
    {
        $result = app(PayService::class)->reconcileBiz();

        $output->writeln(sprintf(
            '支付对账完成：扫描 %d 笔，捞回 %d 笔（%s 模式）',
            $result['scanned'],
            $result['rescued'],
            $result['mock'] ? 'mock' : 'real'
        ));
    }
}
