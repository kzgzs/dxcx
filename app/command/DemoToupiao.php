<?php

declare(strict_types=1);

namespace app\command;

use app\common\service\DemoToupiaoGenerator;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * 投票活动演示数据命令：薄封装，真正逻辑在 {@see DemoToupiaoGenerator}。
 *
 * 用法：
 *   php think demo:toupiao            生成活动(进行中/已结束)+分组+选手(三态)+投票记录
 *   php think demo:toupiao --reset    先删除已生成的 DEMO-TP- 数据再重建
 *   php think demo:toupiao --purge    仅清除全部 DEMO-TP- 演示数据（不生成）
 *   php think demo:toupiao --aid=1    指定租户 ID（默认 1）
 */
class DemoToupiao extends Command
{
    protected function configure(): void
    {
        $this->setName('demo:toupiao')
            ->setDescription('生成租户后台「扩展·投票活动」演示数据（活动2[进行中/已结束]/分组2/选手6[三态]/投票记录7条，DEMO-TP- 前缀，幂等，--purge 清除）')
            ->addOption('reset', null, Option::VALUE_NONE, '先删除已生成的 DEMO-TP- 数据再重建')
            ->addOption('purge', null, Option::VALUE_NONE, '仅删除全部 DEMO-TP- 演示数据，不生成')
            ->addOption('aid', null, Option::VALUE_OPTIONAL, '租户ID', '1');
    }

    protected function execute(Input $input, Output $output): void
    {
        $purge = (bool) $input->getOption('purge');
        $reset = (bool) $input->getOption('reset');
        $generator = app(DemoToupiaoGenerator::class);
        $generator->setAid((int) $input->getOption('aid'));

        if ($purge) {
            $generator->purge();
            $output->writeln('<info>已清除全部 DEMO-TP- 投票活动演示数据</info>');
            return;
        }

        if ($reset) {
            $generator->purge();
        }
        $generator->generate();
        $output->writeln('<info>投票活动演示数据生成完成（DEMO-TP- 前缀：活动2/分组2/选手6[通过4/待审1/驳回1]/投票记录7条）</info>');
    }
}
