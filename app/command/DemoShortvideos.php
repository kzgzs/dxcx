<?php

declare(strict_types=1);

namespace app\command;

use app\common\service\DemoShortvideoGenerator;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * 演示短视频命令：薄封装，真正逻辑在 {@see DemoShortvideoGenerator}（数据库种子迁移复用同一份）。
 *
 * 用法：
 *   php think demo:shortvideos            生成商户「短视频列表」演示视频与分类（已存在的跳过，幂等，可重复执行）
 *   php think demo:shortvideos --reset    先删除已生成的【示例】视频再重建
 *   php think demo:shortvideos --purge    仅清除全部【示例】商户视频与演示分类（不生成）
 *   php think demo:shortvideos --aid=1    指定平台租户 ID（默认 1）
 */
class DemoShortvideos extends Command
{
    protected function configure(): void
    {
        $this->setName('demo:shortvideos')
            ->setDescription('生成商户「短视频列表」演示短视频与分类（幂等，--purge 清除）')
            ->addOption('reset', null, Option::VALUE_NONE, '先删除已生成的【示例】视频再重建')
            ->addOption('purge', null, Option::VALUE_NONE, '仅删除全部【示例】商户视频与演示分类，不生成')
            ->addOption('aid', null, Option::VALUE_OPTIONAL, '平台租户ID', '1');
    }

    protected function execute(Input $input, Output $output): void
    {
        $aid       = max(1, (int) $input->getOption('aid'));
        $generator = app(DemoShortvideoGenerator::class);

        if ((bool) $input->getOption('purge')) {
            $res = $generator->purge($aid);
            $output->writeln('<info>已清理 ' . $res['videos'] . ' 条【示例】短视频、' . $res['categories'] . ' 个演示分类。</info>');
            return;
        }

        $result = $generator->generate($aid, (bool) $input->getOption('reset'));

        foreach ($result['logs'] as $line) {
            $output->writeln($line);
        }
    }
}
