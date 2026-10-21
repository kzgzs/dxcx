<?php

declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;

/**
 * 租户后台「扩展·小程序直播」演示数据命令。
 *
 * 用法：
 *   php think demo:live            生成商品库4个（审核中/通过/驳回三态）+ 直播间3个（已结束×2/未开始）
 *   php think demo:live --reset    先清除 DEMO-LIVE- 数据再重建
 *   php think demo:live --purge    仅清除全部 DEMO-LIVE- 演示数据
 *   php think demo:live --aid=1    指定租户ID（默认 1）
 */
class DemoLive extends Command
{
    private const PREFIX = 'DEMO-LIVE-';

    protected function configure(): void
    {
        $this->setName('demo:live')
            ->setDescription('生成租户后台「扩展·小程序直播」演示数据（商品库三态4个+直播间3个，DEMO-LIVE- 前缀，幂等，--purge 清除）')
            ->addOption('reset', null, Option::VALUE_NONE, '先删除已生成的 DEMO-LIVE- 数据再重建')
            ->addOption('purge', null, Option::VALUE_NONE, '仅删除全部 DEMO-LIVE- 演示数据，不生成')
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
                $output->writeln('<info>已清除全部 DEMO-LIVE- 小程序直播演示数据</info>');
                return;
            }
        }

        $exist = Db::name('live_room')->where('aid', $aid)->whereLike('name', self::PREFIX . '%')->count();
        if ($exist > 0) {
            $output->writeln("<comment>已存在 {$exist} 个 DEMO-LIVE- 直播间（未传 --reset，跳过生成，不翻倍）</comment>");
            return;
        }

        $counts = $this->generate($aid);
        $output->writeln(sprintf(
            '<info>小程序直播演示数据生成完成（商品库%d[审核中%d·通过%d·驳回%d] / 直播间%d）</info>',
            $counts['goods'],
            $counts['s1'],
            $counts['s2'],
            $counts['s3'],
            $counts['rooms']
        ));
    }

    private function purge(int $aid): void
    {
        $rooms = Db::name('live_room')->where('aid', $aid)->whereLike('name', self::PREFIX . '%')->column('id');
        if ($rooms) {
            Db::name('live_room')->whereIn('id', $rooms)->delete();
        }
        Db::name('live_goods')->where('aid', $aid)->whereLike('name', self::PREFIX . '%')->delete();
    }

    private function generate(int $aid): array
    {
        $now = time();
        // 商品库：[名称, 图片, 链接, priceType, price元, price2元, 审核状态]
        $goodsDef = [
            [self::PREFIX . '橱柜吊柜',     '/uploads/demo/seckill_1.jpg',  'pagesM/goods/detail?id=1', 1, 1,   0,   1],
            [self::PREFIX . '测试测算123',  '/uploads/demo/tuangou_1.jpg',  'pagesM/goods/detail?id=2', 3, 1,   88,  2],
            [self::PREFIX . '区间示例商品', '/uploads/demo/seckill_2.jpg',  'pagesM/goods/detail?id=3', 2, 50,  199, 2],
            [self::PREFIX . '驳回示例商品', '/uploads/demo/scoreshop.png',  'pagesM/goods/detail?id=4', 1, 9.9, 0,   3],
        ];
        $goodsMap = [];
        $c = [1 => 0, 2 => 0, 3 => 0];
        foreach ($goodsDef as $g) {
            [$name, $pic, $url, $pt, $p1, $p2, $st] = $g;
            $id = (int) Db::name('live_goods')->insertGetId([
                'aid'          => $aid,
                'goodsId'      => 0,
                'name'         => $name,
                'coverImgUrl'  => $pic,
                'url'          => $url,
                'priceType'    => $pt,
                'price'        => (int) round($p1 * 100),
                'price2'       => (int) round($p2 * 100),
                'audit_status' => $st,
                'createtime'   => $now,
            ]);
            Db::name('live_goods')->where('id', $id)->update(['goodsId' => $id]);
            $goodsMap[$name] = [
                'goods_id'   => $id,
                'name'       => $name,
                'cover_img'  => $pic,
                'url'        => $url,
                'price_type' => $pt,
                'price'      => (int) round($p1 * 100),
                'price2'     => (int) round($p2 * 100),
            ];
            $c[$st]++;
        }

        // 直播间：[名称, roomId, 封面, 开始, 结束, 主播, live_status, 显示状态, 商品名数组]
        $roomDef = [
            [self::PREFIX . '点大商城直播', 4, '/uploads/demo/tuangou_1.jpg', '2026-09-15 00:00:00', '2026-09-16 00:00:00', '汉字',   '103', 1,
                [self::PREFIX . '橱柜吊柜', self::PREFIX . '测试测算123']],
            [self::PREFIX . '商城系统直播23', 3, '/uploads/demo/tuangou_2.jpg', '2022-05-09 11:50:37', '2022-05-10 00:00:00', '猪刚鬣', '103', 1, []],
            [self::PREFIX . '新品预告直播', 5, '/uploads/demo/seckill_2.jpg', date('Y-m-d H:i:s', $now + 3600), date('Y-m-d H:i:s', $now + 3600 + 7200), '汉字', '102', 0, []],
        ];
        $roomCount = 0;
        foreach ($roomDef as $r) {
            [$name, $roomId, $cover, $start, $end, $anchor, $liveStatus, $status, $goodsNames] = $r;
            $goods = [];
            foreach ($goodsNames as $gn) {
                if (isset($goodsMap[$gn])) {
                    $goods[] = $goodsMap[$gn];
                }
            }
            Db::name('live_room')->insert([
                'aid'             => $aid,
                'roomId'          => $roomId,
                'name'            => $name,
                'coverImg'        => $cover,
                'shareImg'        => $cover,
                'feedsImg'        => $cover,
                'startTime'       => (int) strtotime($start),
                'endTime'         => (int) strtotime($end),
                'anchorName'      => $anchor,
                'anchorWechat'    => 'demo_wx',
                'isFeedsPublic'   => 1,
                'type'            => 0,
                'screenType'      => 0,
                'closeLike'       => 0,
                'closeGoods'      => 0,
                'closeComment'    => 0,
                'closeReplay'     => 0,
                'closeShare'      => 0,
                'closeKf'         => 0,
                'live_status'     => $liveStatus,
                'goods'           => json_encode($goods, JSON_UNESCAPED_UNICODE),
                'status'          => $status,
                'sort'            => 0,
                'createtime'      => $now,
            ]);
            $roomCount++;
        }

        return ['goods' => count($goodsDef), 's1' => $c[1], 's2' => $c[2], 's3' => $c[3], 'rooms' => $roomCount];
    }
}
