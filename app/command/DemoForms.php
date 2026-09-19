<?php

declare(strict_types=1);

namespace app\command;

use app\common\service\DemoFormGenerator;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * 演示自定义表单命令：薄封装，真正逻辑在 {@see DemoFormGenerator}（数据库种子迁移复用同一份）。
 *
 * 用法：
 *   php think demo:forms            生成商户「自定义表单」演示表单与提交数据（已存在的跳过，幂等，可重复执行）
 *   php think demo:forms --reset    先删除已生成的【示例】表单再重建
 *   php think demo:forms --purge    仅清除全部【示例】商户表单及其提交记录（不生成）
 *   php think demo:forms --aid=1    指定平台租户 ID（默认 1）
 */
class DemoForms extends Command
{
    protected function configure(): void
    {
        $this->setName('demo:forms')
            ->setDescription('生成商户「自定义表单」演示表单与提交数据（幂等，--purge 清除）')
            ->addOption('reset', null, Option::VALUE_NONE, '先删除已生成的【示例】表单再重建')
            ->addOption('purge', null, Option::VALUE_NONE, '仅删除全部【示例】商户表单及其提交记录，不生成')
            ->addOption('aid', null, Option::VALUE_OPTIONAL, '平台租户ID', '1');
    }

    protected function execute(Input $input, Output $output): void
    {
        $aid       = max(1, (int) $input->getOption('aid'));
        $generator = app(DemoFormGenerator::class);

        if ((bool) $input->getOption('purge')) {
            $res = $generator->purge($aid);
            $output->writeln('<info>已清理 ' . $res['forms'] . ' 个【示例】表单、' . $res['orders'] . ' 条提交记录。</info>');
            return;
        }

        $result = $generator->generate($aid, (bool) $input->getOption('reset'));

        foreach ($result['logs'] as $line) {
            $output->writeln($line);
        }
    }
}
