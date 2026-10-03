<?php

declare(strict_types=1);

namespace app\command;

use app\common\service\DemoShortvideoSeed;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * 平台「营销→短视频」演示数据命令（demo:shortvideo）。
 *
 * 用法：
 *   php think demo:shortvideo            生成平台短视频分类/视频/评论/回评（幂等，可重复执行）
 *   php think demo:shortvideo --reset    先删除 DEMO-SV- 前缀演示数据再重建
 *   php think demo:shortvideo --purge    仅清除 DEMO-SV- 前缀演示数据，不生成
 *   php think demo:shortvideo --aid=1    指定平台租户 ID（默认 1）
 */
class DemoShortvideo extends Command
{
    protected function configure(): void
    {
        $this->setName('demo:shortvideo')
            ->setDescription('生成平台「营销→短视频」演示数据（分类/视频三态/评论/回评，幂等，--purge 清除）')
            ->addOption('reset', null, Option::VALUE_NONE, '先删除 DEMO-SV- 前缀演示数据再重建')
            ->addOption('purge', null, Option::VALUE_NONE, '仅删除 DEMO-SV- 前缀演示数据，不生成')
            ->addOption('aid', null, Option::VALUE_OPTIONAL, '平台租户ID', '1');
    }

    protected function execute(Input $input, Output $output): void
    {
        $aid = max(1, (int) $input->getOption('aid'));
        $seed = app(DemoShortvideoSeed::class);

        if ((bool) $input->getOption('purge')) {
            $res = $seed->purge($aid);
            $output->writeln('<info>已清理：分类 ' . $res['categories'] . '、视频 ' . $res['videos'] . '、评论 ' . $res['comments'] . '、回评 ' . $res['replies'] . '。</info>');
            return;
        }

        $result = $seed->generate($aid, (bool) $input->getOption('reset'));
        foreach ($result['logs'] as $line) {
            $output->writeln($line);
        }
    }
}
