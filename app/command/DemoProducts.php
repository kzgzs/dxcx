<?php

declare(strict_types=1);

namespace app\command;

use app\common\service\DemoProductGenerator;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * 演示商品命令：薄封装，真正逻辑在 {@see DemoProductGenerator}（数据库种子迁移复用同一份）。
 *
 * 用法：
 *   php think demo:products            生成（已存在的示例商品跳过，幂等，可重复执行）
 *   php think demo:products --reset    先删除已生成的【示例】商品（含规格）再重建
 *   php think demo:products --purge    仅清除全部【示例】商品及自动创建的示例商户（不生成）
 *   php think demo:products --aid=1    指定平台租户 ID（默认 1）
 */
class DemoProducts extends Command
{
    protected function configure(): void
    {
        $this->setName('demo:products')
            ->setDescription('生成7个营销模块的示例商品：平台自营+商户各2个（拼团/幸运拼团/砍价/秒杀/团购/周期购/服务预约，幂等）')
            ->addOption('reset', null, Option::VALUE_NONE, '先删除已生成的【示例】商品（含规格）再重建')
            ->addOption('purge', null, Option::VALUE_NONE, '仅删除全部【示例】商品及自动创建的示例商户，不生成')
            ->addOption('aid', null, Option::VALUE_OPTIONAL, '平台租户ID', '1');
    }

    protected function execute(Input $input, Output $output): void
    {
        $aid       = max(1, (int) $input->getOption('aid'));
        $generator = app(DemoProductGenerator::class);

        if ((bool) $input->getOption('purge')) {
            $res = $generator->purge($aid);
            $tail = $res['merchantDeleted'] ? '，并删除自动创建的示例商户' : '（保留真实商户及其数据）';
            $output->writeln('<info>已清理 ' . $res['products'] . ' 个【示例】商品' . $tail . '。</info>');
            return;
        }

        $result = $generator->generate($aid, (bool) $input->getOption('reset'));

        foreach ($result['logs'] as $line) {
            $output->writeln($line);
        }
    }
}
