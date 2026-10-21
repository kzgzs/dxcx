<?php

declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;

/**
 * 租户后台「扩展·礼品卡」演示数据命令。
 *
 * 用法：
 *   php think demo:giftcard            生成分类3/礼品卡3[进行中·已结束·进行中]/兑换码若干[未用/已用/已售]
 *   php think demo:giftcard --reset    先清除 DEMO-GCARD- 数据再重建
 *   php think demo:giftcard --purge    仅清除全部 DEMO-GCARD- 演示数据
 *   php think demo:giftcard --aid=1    指定租户ID（默认 1）
 */
class DemoGiftcard extends Command
{
    private const PREFIX = 'DEMO-GCARD-';

    protected function configure(): void
    {
        $this->setName('demo:giftcard')
            ->setDescription('生成租户后台「扩展·礼品卡」演示数据（分类3/礼品卡3[进行中/已结束]/兑换码[未用/已用/已售]，DEMO-GCARD- 前缀，幂等，--purge 清除）')
            ->addOption('reset', null, Option::VALUE_NONE, '先删除已生成的 DEMO-GCARD- 数据再重建')
            ->addOption('purge', null, Option::VALUE_NONE, '仅删除全部 DEMO-GCARD- 演示数据，不生成')
            ->addOption('aid', null, Option::VALUE_OPTIONAL, '租户ID', '1');
    }

    protected function execute(Input $input, Output $output): void
    {
        $aid   = (int) $input->getOption('aid');
        $purge = (bool) $input->getOption('purge');
        $reset = (bool) $input->getOption('reset');

        if ($purge || $reset) {
            $this->purge($aid);
            if ($purge) {
                $output->writeln('<info>已清除全部 DEMO-GCARD- 礼品卡演示数据</info>');
                return;
            }
        }

        // 幂等：无 --reset 时若已存在演示数据则跳过，不翻倍
        $existLipin = Db::name('lipin')->where('aid', $aid)->whereLike('name', self::PREFIX . '%')->count();
        if ($existLipin > 0) {
            $output->writeln("<comment>已存在 {$existLipin} 条 DEMO-GCARD- 礼品卡演示数据（未传 --reset，跳过生成，不翻倍）</comment>");
            return;
        }

        $counts = $this->generate($aid);
        $output->writeln(sprintf(
            '<info>礼品卡演示数据生成完成（DEMO-GCARD- 前缀：分类%d / 礼品卡%d / 兑换码%d[未用%d·已用%d·已售%d]）</info>',
            $counts['category'],
            $counts['lipin'],
            $counts['code'],
            $counts['code0'],
            $counts['code1'],
            $counts['code2'],
        ));
    }

    /** 清除本命令生成的全部演示数据 */
    private function purge(int $aid): void
    {
        $catIds   = Db::name('lipin_category')->where('aid', $aid)->whereLike('name', self::PREFIX . '%')->column('id');
        $cardIds  = Db::name('lipin')->where('aid', $aid)->whereLike('name', self::PREFIX . '%')->column('id');

        if (!empty($cardIds)) {
            Db::name('lipin_codelist')->where('aid', $aid)->whereIn('hid', $cardIds)->delete();
        }
        Db::name('lipin')->where('aid', $aid)->whereLike('name', self::PREFIX . '%')->delete();
        if (!empty($catIds)) {
            Db::name('lipin_category')->where('aid', $aid)->whereIn('id', $catIds)->delete();
        }
    }

    /** 生成演示数据，返回各类计数 */
    private function generate(int $aid): array
    {
        $now = time();

        // 1) 分类（余额/积分/优惠券）
        $catNames = [self::PREFIX . '余额', self::PREFIX . '积分', self::PREFIX . '优惠券'];
        $catIds   = [];
        foreach ($catNames as $i => $name) {
            $catIds[] = Db::name('lipin_category')->insertGetId([
                'aid' => $aid, 'bid' => 0, 'pid' => 0, 'name' => $name,
                'pic' => '', 'status' => 1, 'sort' => 3 - $i, 'createtime' => $now,
            ]);
        }

        // 2) 礼品卡：进行中余额卡 / 已结束积分卡 / 进行中优惠券卡
        $cards = [
            ['name' => self::PREFIX . '余额100元', 'cid' => $catIds[0], 'type' => 0, 'money' => '100.00', 'score' => 0, 'coupon_ids' => '', 'start' => strtotime('-30 day'), 'end' => strtotime('+60 day')],
            ['name' => self::PREFIX . '积分10个', 'cid' => $catIds[1], 'type' => 2, 'money' => '0.00', 'score' => 10, 'coupon_ids' => '', 'start' => strtotime('-90 day'), 'end' => strtotime('-1 day')],
            ['name' => self::PREFIX . '优惠券兑换', 'cid' => $catIds[2], 'type' => 3, 'money' => '0.00', 'score' => 0, 'coupon_ids' => '1,2', 'start' => strtotime('-10 day'), 'end' => strtotime('+30 day')],
        ];
        $hidActive = 0;
        foreach ($cards as $c) {
            $id = Db::name('lipin')->insertGetId([
                'aid' => $aid, 'name' => $c['name'], 'cid' => $c['cid'], 'type' => $c['type'],
                'num_type' => 0, 'starttime' => (string) $c['start'], 'endtime' => (string) $c['end'],
                'money' => $c['money'], 'prodata' => '', 'prodata4' => '', 'num_type4' => 0,
                'createtime' => $now, 'status' => 1, 'score' => $c['score'], 'coupon_ids' => $c['coupon_ids'],
            ]);
            if ($c['type'] === 0) {
                $hidActive = $id;
            }
        }

        // 3) 兑换码挂到进行中的余额卡：未用20 / 已用5 / 已售5
        $codePool = '0123456789abcdefghjkmnpqrstuvwxyz';
        $batches = [
            ['status' => 0, 'n' => 20, 'use' => 0],
            ['status' => 1, 'n' => 5, 'use' => 1],
            ['status' => 2, 'n' => 5, 'use' => 0],
        ];
        $taken = [];
        $counts = ['category' => count($catIds), 'lipin' => count($cards), 'code' => 0, 'code0' => 0, 'code1' => 0, 'code2' => 0];

        foreach ($batches as $b) {
            for ($i = 0; $i < $b['n']; ++$i) {
                $code = '';
                for ($k = 0; $k < 8; ++$k) {
                    $code .= $codePool[random_int(0, strlen($codePool) - 1)];
                }
                do {
                    $cardno = '';
                    for ($k = 0; $k < 16; ++$k) {
                        $cardno .= random_int(0, 9);
                    }
                } while (isset($taken[$cardno]));
                $taken[$cardno] = true;

                Db::name('lipin_codelist')->insert([
                    'aid' => $aid, 'hid' => $hidActive, 'cardno' => $cardno, 'code' => $code,
                    'qr' => '', 'status' => $b['status'], 'createtime' => $now,
                    'saletime' => $b['status'] === 2 ? $now : 0,
                    'usetime' => $b['use'] ? $now : 0,
                    'mid' => $b['use'] ? 7632 : 0, 'headimg' => '',
                    'nickname' => $b['use'] ? 'hh' : '', 'remark' => $b['use'] ? '兑换余额100元' : '',
                    'sale_mdid' => 0, 'hexiao_mdid' => 0,
                ]);
                $counts['code']++;
                if ($b['status'] === 0) { $counts['code0']++; }
                elseif ($b['status'] === 1) { $counts['code1']++; }
                else { $counts['code2']++; }
            }
        }

        return $counts;
    }
}
