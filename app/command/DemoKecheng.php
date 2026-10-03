<?php

declare(strict_types=1);

namespace app\command;

use app\common\service\DemoKechengGenerator;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * 知识付费演示数据命令：薄封装，真正逻辑在 {@see DemoKechengGenerator}。
 *
 * 用法：
 *   php think demo:kecheng            生成演示分类/课程(图文音频视频综合)/章节/题库/答题/订单全状态/学习记录/讲师三态
 *   php think demo:kecheng --reset    先删除已生成的 DEMO-KC- 数据再重建
 *   php think demo:kecheng --purge    仅清除全部 DEMO-KC- 演示数据（不生成）
 *   php think demo:kecheng --aid=1    指定平台租户 ID（默认 1）
 */
class DemoKecheng extends Command
{
    protected function configure(): void
    {
        $this->setName('demo:kecheng')
            ->setDescription('生成租户后台「扩展·知识付费」演示数据（分类/课程四类型/章节/题库/答题/订单全状态/学习记录/讲师三态，DEMO-KC- 前缀，幂等，--purge 清除）')
            ->addOption('reset', null, Option::VALUE_NONE, '先删除已生成的 DEMO-KC- 数据再重建')
            ->addOption('purge', null, Option::VALUE_NONE, '仅删除全部 DEMO-KC- 演示数据，不生成')
            ->addOption('aid', null, Option::VALUE_OPTIONAL, '平台租户ID', '1');
    }

    protected function execute(Input $input, Output $output): void
    {
        $purge = (bool) $input->getOption('purge');
        $reset = (bool) $input->getOption('reset');
        $generator = app(DemoKechengGenerator::class);

        if ($purge) {
            $generator->purge();
            $output->writeln('<info>已清除全部 DEMO-KC- 知识付费演示数据</info>');
            return;
        }

        if ($reset) {
            $generator->purge();
        }
        $generator->generate();
        $output->writeln('<info>知识付费演示数据生成完成（DEMO-KC- 前缀：分类2级/课程图文音频视频综合/章节11/题库3/答题3卷/订单未付已付已关闭/学习2）</info>');
    }
}
