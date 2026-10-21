<?php

declare(strict_types=1);

namespace app\command;

use app\common\service\DemoCycleGenerator;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * 周期购演示数据命令：薄封装，真正逻辑在 {@see DemoCycleGenerator}。
 *
 * 用法：
 *   php think demo:cycle            生成分类/商品(上下架)/规格/订单(五状态)/分期(待发/已发/已核销)/评价(四态)
 *   php think demo:cycle --reset    先删除已生成的 DEMO-CYC- 数据再重建
 *   php think demo:cycle --purge    仅清除全部 DEMO-CYC- 演示数据（不生成）
 *   php think demo:cycle --aid=1    指定租户 ID（默认 1）
 */
class DemoCycle extends Command
{
    protected function configure(): void
    {
        $this->setName('demo:cycle')
            ->setDescription('生成租户后台「扩展·周期购」演示数据（分类4/商品5[上下架混合]/规格/订单6[五状态]/分期配送[待发/已发/已核销]/评价5[待回复/已回复/已审核/驳回]，DEMO-CYC- 前缀，幂等，--purge 清除）')
            ->addOption('reset', null, Option::VALUE_NONE, '先删除已生成的 DEMO-CYC- 数据再重建')
            ->addOption('purge', null, Option::VALUE_NONE, '仅删除全部 DEMO-CYC- 演示数据，不生成')
            ->addOption('aid', null, Option::VALUE_OPTIONAL, '租户ID', '1');
    }

    protected function execute(Input $input, Output $output): void
    {
        $purge = (bool) $input->getOption('purge');
        $reset = (bool) $input->getOption('reset');
        $generator = app(DemoCycleGenerator::class);
        $generator->setAid((int) $input->getOption('aid'));

        if ($purge) {
            $generator->purge();
            $output->writeln('<info>已清除全部 DEMO-CYC- 周期购演示数据</info>');
            return;
        }

        if ($reset) {
            $generator->purge();
        }
        $generator->generate();
        $output->writeln('<info>周期购演示数据生成完成（DEMO-CYC- 前缀：分类4/商品5[上架3下架2]/规格5/订单6[未支付/已支付/已发货/已收货/已关闭]/分期配送[待发/已发/已完成]/评价5[待回复/已回复/已审核/驳回]）</info>');
    }
}
