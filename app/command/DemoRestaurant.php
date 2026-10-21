<?php
declare(strict_types=1);

namespace app\command;

use app\common\service\DemoRestaurantGenerator;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * 餐饮演示数据命令
 * 用法：php think demo:restaurant [--reset|--purge] [--aid=1]
 */
class DemoRestaurant extends Command
{
    protected function configure(): void
    {
        $this->setName('demo:restaurant')
            ->setDescription('生成租户后台「餐饮」演示数据（区域2/餐桌分类3/餐桌8/菜品分类4/菜品12含规格/堂食订单5全状态/外卖订单4全状态/排队3/预定2/寄存2/优惠券2/促销2/配送方式1/备注4，DEMO-RT- 前缀，幂等，--purge 清除）')
            ->addOption('reset', null, Option::VALUE_NONE, '先清除已生成的 DEMO-RT- 数据再重建')
            ->addOption('purge', null, Option::VALUE_NONE, '仅清除全部 DEMO-RT- 演示数据')
            ->addOption('aid', null, Option::VALUE_OPTIONAL, '租户ID', '1');
    }

    protected function execute(Input $input, Output $output): void
    {
        $purge = (bool) $input->getOption('purge');
        $reset = (bool) $input->getOption('reset');
        $gen = app(DemoRestaurantGenerator::class);
        $gen->setAid((int) $input->getOption('aid'));

        if ($purge) {
            $gen->purge();
            $output->writeln('<info>已清除全部 DEMO-RT- 餐饮演示数据</info>');
            return;
        }
        if ($reset) $gen->purge();
        $gen->generate();
        $output->writeln('<info>餐饮演示数据生成完成（DEMO-RT- 前缀）</info>');
    }
}
