<?php

declare(strict_types=1);

namespace app\command;

use app\common\service\DemoKanjiaGenerator;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * 砍价演示订单命令：薄封装，真正逻辑在 {@see DemoKanjiaGenerator}。
 *
 * 用法：
 *   php think demo:kanjia            生成5笔演示订单（已支付自提/已支付快递/已发货/已关闭/已完成）
 *   php think demo:kanjia --reset    先删除已生成的 DEMO-KJ- 订单再重建
 *   php think demo:kanjia --purge    仅清除全部 DEMO-KJ- 演示订单（不生成）
 *   php think demo:kanjia --aid=1    指定平台租户 ID（默认 1）
 */
class DemoKanjia extends Command
{
    protected function configure(): void
    {
        $this->setName('demo:kanjia')
            ->setDescription('生成砍价活动订单管理演示数据（4种状态行操作差异可见，幂等，--purge 清除）')
            ->addOption('reset', null, Option::VALUE_NONE, '先删除已生成的 DEMO-KJ- 订单再重建')
            ->addOption('purge', null, Option::VALUE_NONE, '仅删除全部 DEMO-KJ- 演示订单，不生成')
            ->addOption('aid', null, Option::VALUE_OPTIONAL, '平台租户ID', '1');
    }

    protected function execute(Input $input, Output $output): void
    {
        $aid       = max(1, (int) $input->getOption('aid'));
        $generator = app(DemoKanjiaGenerator::class);

        if ((bool) $input->getOption('purge')) {
            $res = $generator->purge($aid);
            $output->writeln('<info>已清理 ' . $res['orders'] . ' 笔 DEMO-KJ- 演示订单。</info>');
            return;
        }

        $result = $generator->generate($aid, (bool) $input->getOption('reset'));

        foreach ($result['logs'] as $line) {
            $output->writeln($line);
        }
    }
}
