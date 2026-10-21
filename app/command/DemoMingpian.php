<?php

declare(strict_types=1);

namespace app\command;

use app\common\service\DemoMingpianGenerator;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * 名片演示数据命令：薄封装，真正逻辑在 {@see DemoMingpianGenerator}。
 *
 * 用法：
 *   php think demo:mingpian            生成名片5张+浏览/收藏记录
 *   php think demo:mingpian --reset    先删除已生成的 DEMO-MP- 数据再重建
 *   php think demo:mingpian --purge    仅清除全部 DEMO-MP- 演示数据（不生成）
 *   php think demo:mingpian --aid=1    指定平台租户 ID（默认 1）
 */
class DemoMingpian extends Command
{
    protected function configure(): void
    {
        $this->setName('demo:mingpian')
            ->setDescription('生成租户后台「扩展·名片」演示数据（名片5张[头衔/联系信息/经纬度/简介]+浏览/收藏记录，DEMO-MP- 前缀，幂等，--purge 清除）')
            ->addOption('reset', null, Option::VALUE_NONE, '先删除已生成的 DEMO-MP- 数据再重建')
            ->addOption('purge', null, Option::VALUE_NONE, '仅删除全部 DEMO-MP- 演示数据，不生成')
            ->addOption('aid', null, Option::VALUE_OPTIONAL, '平台租户ID', '1');
    }

    protected function execute(Input $input, Output $output): void
    {
        $purge = (bool) $input->getOption('purge');
        $reset = (bool) $input->getOption('reset');
        $generator = app(DemoMingpianGenerator::class);
        $generator->setAid((int) $input->getOption('aid'));

        if ($purge) {
            $generator->purge();
            $output->writeln('<info>已清除全部 DEMO-MP- 名片演示数据</info>');
            return;
        }

        if ($reset) {
            $generator->purge();
        }
        $generator->generate();
        $output->writeln('<info>名片演示数据生成完成（DEMO-MP- 前缀：名片5张[含头衔/联系信息/经纬度/个人简介]+浏览记录3-5条/张+收藏记录1-3条/张）</info>');
    }
}
