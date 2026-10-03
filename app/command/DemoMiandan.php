<?php

declare(strict_types=1);

namespace app\command;

use app\common\service\DemoMiandanGenerator;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * 物流助手演示数据命令：薄封装，真正逻辑在 {@see DemoMiandanGenerator}。
 *
 * 用法：
 *   php think demo:miandan            生成运单(正常/已取消)+物流账号+打印员
 *   php think demo:miandan --reset    先删除已生成的 DEMO-WL- 数据再重建
 *   php think demo:miandan --purge    仅清除全部 DEMO-WL- 演示数据（不生成）
 *   php think demo:miandan --aid=1    指定平台租户 ID（默认 1）
 */
class DemoMiandan extends Command
{
    protected function configure(): void
    {
        $this->setName('demo:miandan')
            ->setDescription('生成租户后台「扩展·物流助手」演示数据（运单1正常/4已取消/物流账号三态/打印员4名，DEMO-WL- 前缀，幂等，--purge 清除）')
            ->addOption('reset', null, Option::VALUE_NONE, '先删除已生成的 DEMO-WL- 数据再重建')
            ->addOption('purge', null, Option::VALUE_NONE, '仅删除全部 DEMO-WL- 演示数据，不生成')
            ->addOption('aid', null, Option::VALUE_OPTIONAL, '平台租户ID', '1');
    }

    protected function execute(Input $input, Output $output): void
    {
        $purge = (bool) $input->getOption('purge');
        $reset = (bool) $input->getOption('reset');
        $generator = app(DemoMiandanGenerator::class);
        $generator->setAid((int) $input->getOption('aid'));

        if ($purge) {
            $generator->purge();
            $output->writeln('<info>已清除全部 DEMO-WL- 物流助手演示数据</info>');
            return;
        }

        if ($reset) {
            $generator->purge();
        }
        $generator->generate();
        $output->writeln('<info>物流助手演示数据生成完成（DEMO-WL- 前缀：运单5笔[正常3/已取消2]/物流账号3条[绑定成功/已解绑/审核中]/打印员4名）</info>');
    }
}
