<?php

declare(strict_types=1);

namespace app\command;

use app\common\service\DemoEwaybillGenerator;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * 电子面单演示数据命令：薄封装，真正逻辑在 {@see DemoEwaybillGenerator}。
 *
 * 用法：
 *   php think demo:ewaybill            生成账号(圆通/优速)+面单记录(正常/已取消)
 *   php think demo:ewaybill --reset    先删除已生成的 DEMO-EB- 数据再重建
 *   php think demo:ewaybill --purge    仅清除全部 DEMO-EB- 演示数据（不生成）
 *   php think demo:ewaybill --aid=1    指定平台租户 ID（默认 1）
 */
class DemoEwaybill extends Command
{
    protected function configure(): void
    {
        $this->setName('demo:ewaybill')
            ->setDescription('生成租户后台「扩展·电子面单」演示数据（账号2条/面单记录4笔[正常3/已取消1]，DEMO-EB- 前缀，幂等，--purge 清除）')
            ->addOption('reset', null, Option::VALUE_NONE, '先删除已生成的 DEMO-EB- 数据再重建')
            ->addOption('purge', null, Option::VALUE_NONE, '仅删除全部 DEMO-EB- 演示数据，不生成')
            ->addOption('aid', null, Option::VALUE_OPTIONAL, '平台租户ID', '1');
    }

    protected function execute(Input $input, Output $output): void
    {
        $purge = (bool) $input->getOption('purge');
        $reset = (bool) $input->getOption('reset');
        $generator = app(DemoEwaybillGenerator::class);
        $generator->setAid((int) $input->getOption('aid'));

        if ($purge) {
            $generator->purge();
            $output->writeln('<info>已清除全部 DEMO-EB- 电子面单演示数据</info>');
            return;
        }

        if ($reset) {
            $generator->purge();
        }
        $generator->generate();
        $output->writeln('<info>电子面单演示数据生成完成（DEMO-EB- 前缀：账号2条[圆通YTO月结/优速UC现付]；面单记录4笔[正常3/已取消1]）</info>');
    }
}
