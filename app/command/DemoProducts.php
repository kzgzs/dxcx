<?php

declare(strict_types=1);

namespace app\command;

use app\common\service\BargainService;
use app\common\service\CollageService;
use app\common\service\CycleService;
use app\common\service\LuckyCollageService;
use app\common\service\SeckillService;
use app\common\service\TuangouService;
use app\common\service\YuyueService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;

/**
 * 演示商品生成：为 7 个营销模块各生成 2 个「平台自营」示例商品。
 *
 * 设计原则：
 *  - 不手写 INSERT，统一调用各模块 Service 的商品保存方法，与后台手动「添加」走完全相同的入口，
 *    规格子表、价格汇总、审核状态、时间字段等均由业务逻辑落库，避免漏字段/瞎编字段。
 *  - 商品名统一以「【示例】」开头作为幂等键：默认已存在则跳过；加 --reset 先清理后重建。
 *  - 商品图由 GD 实时生成到 public/uploads/demo（.gitignore 已放行该目录，随仓提交，离线/断网也能显示）。
 *
 * 用法：
 *   php think demo:products            生成（已存在的示例商品跳过）
 *   php think demo:products --reset    先删除本命令生成的示例商品（含规格）再重建
 *   php think demo:products --aid=1    指定平台租户 ID（默认 1）
 */
class DemoProducts extends Command
{
    /** 示例商品名前缀（同时作为幂等与清理标识，请勿与真实商品重名） */
    private const DEMO_TAG = '【示例】';

    /** 示例图片 URL 前缀与磁盘目录（走 /uploads 代理，开发态/生产态均可访问） */
    private const IMG_URL = '/uploads/demo/';

    /** 商品主表 => 规格子表（无独立规格表的模块为 null） */
    private const GUIGE_MAP = [
        'collage_product'       => 'collage_guige',
        'lucky_collage_product' => 'lucky_collage_guige',
        'seckill_product'       => 'seckill_guige',
        'cycle_product'         => 'cycle_guige',
        'kanjia_product'        => null,
        'tuangou_product'       => null,
        'yuyue_product'         => null,
    ];

    private int $aid = 1;
    private bool $reset = false;
    private int $created = 0;
    private int $skipped = 0;

    protected function configure(): void
    {
        $this->setName('demo:products')
            ->setDescription('生成7个营销模块的平台自营示例商品（拼团/幸运拼团/砍价/秒杀/团购/周期购/服务预约，每模块2个，幂等）')
            ->addOption('reset', null, Option::VALUE_NONE, '先删除已生成的【示例】商品（含规格）再重建')
            ->addOption('aid', null, Option::VALUE_OPTIONAL, '平台租户ID', '1');
    }

    protected function execute(Input $input, Output $output): void
    {
        $this->aid   = max(1, (int) $input->getOption('aid'));
        $this->reset = (bool) $input->getOption('reset');

        $output->writeln('<info>==== 演示商品生成开始（aid=' . $this->aid . ($this->reset ? '，--reset 重建' : '') . '）====</info>');

        if (empty(Db::name('admin')->where('id', $this->aid)->find())) {
            $output->writeln('<error>平台租户 admin.id=' . $this->aid . ' 不存在，请先完成平台初始化（AdminSeeder）。</error>');
            return;
        }

        $this->ensureImages($output);

        $modules = [
            '拼团'     => ['collage_product',       $this->collageItems()],
            '幸运拼团' => ['lucky_collage_product', $this->luckyItems()],
            '砍价'     => ['kanjia_product',        $this->kanjiaItems()],
            '秒杀'     => ['seckill_product',       $this->seckillItems()],
            '团购'     => ['tuangou_product',       $this->tuangouItems()],
            '周期购'   => ['cycle_product',         $this->cycleItems()],
            '服务预约' => ['yuyue_product',         $this->yuyueItems()],
        ];

        foreach ($modules as $label => [$table, $items]) {
            $output->writeln('');
            $output->writeln('<comment>-- ' . $label . '（' . $table . '）--</comment>');
            foreach ($items as $item) {
                $this->upsert($output, $table, $item);
            }
        }

        $output->writeln('');
        $output->writeln('<info>==== 生成结束：新建 ' . $this->created . ' 个，跳过 ' . $this->skipped . ' 个 ====</info>');

        $this->report($output);
    }

    /**
     * 幂等落库：已存在则跳过（或 --reset 先删后建），再调用 Service 保存。
     *
     * @param array{service: class-string, method: string, data: array<string, mixed>} $item
     */
    private function upsert(Output $output, string $table, array $item): void
    {
        $data  = $item['data'];
        $name  = (string) $data['name'];

        $query = Db::name($table)->where('aid', $this->aid)->where('name', $name);
        if ('yuyue_product' === $table) {
            $query->where('is_delete', 0);
        }
        $existing = $query->find();

        if (!empty($existing)) {
            if (!$this->reset) {
                $output->writeln('  跳过（已存在）：' . $name);
                $this->skipped++;
                return;
            }
            $this->deleteDemo($table, (int) $existing['id']);
            $output->writeln('  重置（先删后建）：' . $name);
        }

        try {
            /** @var object{__call?: mixed} $service */
            $service = app($item['service']);
            $service->{$item['method']}($this->aid, $data);
            $this->created++;
            $output->writeln('  <info>已生成</info>：' . $name);
        } catch (\Throwable $e) {
            $output->writeln('  <error>失败：' . $name . ' => ' . $e->getMessage() . '</error>');
        }
    }

    /**
     * 删除示例商品主表行及其规格子表（仅用于 --reset 重建演示数据）。
     */
    private function deleteDemo(string $table, int $id): void
    {
        Db::name($table)->where('aid', $this->aid)->where('id', $id)->delete();

        $guige = self::GUIGE_MAP[$table] ?? null;
        if (null !== $guige) {
            Db::name($guige)->where('aid', $this->aid)->where('proid', $id)->delete();
        }
    }

    /* ====================== 各模块示例商品（字段严格对齐各自 Service::productSave） ====================== */

    /**
     * 拼团：teamnum 成团人数 / teamhour 成团时限(小时) / leadermoney 团长立减 / options 规格行。
     *
     * @return array<int, array{service: class-string, method: string, data: array<string, mixed>}>
     */
    private function collageItems(): array
    {
        return [
            [
                'service' => CollageService::class,
                'method'  => 'productSave',
                'data'    => [
                    'name'        => self::DEMO_TAG . '陕西洛川红富士苹果5斤装',
                    'pic'         => self::IMG_URL . 'collage_1.jpg',
                    'pics'        => self::IMG_URL . 'collage_1.jpg',
                    'cid'         => 0,
                    'sellpoint'   => '脆甜多汁，产地现摘直发',
                    'teamnum'     => 3,
                    'teamhour'    => 24,
                    'leadermoney' => 2,
                    'buymax'      => 2,
                    'sort'        => 100,
                    'status'      => 1,
                    'detail'      => '<p>陕西洛川高原红富士苹果，脆甜多汁、果香浓郁，5斤精品装，产地现摘直发。</p>',
                    'options'     => [
                        ['name' => '5斤精品装', 'sell_price' => 29.9, 'market_price' => 49.9, 'cost_price' => 22, 'stock' => 200],
                    ],
                ],
            ],
            [
                'service' => CollageService::class,
                'method'  => 'productSave',
                'data'    => [
                    'name'        => self::DEMO_TAG . '新疆库尔勒香梨8斤家庭装',
                    'pic'         => self::IMG_URL . 'collage_2.jpg',
                    'pics'        => self::IMG_URL . 'collage_2.jpg',
                    'cid'         => 0,
                    'sellpoint'   => '皮薄核小，清甜爽口',
                    'teamnum'     => 5,
                    'teamhour'    => 48,
                    'leadermoney' => 3,
                    'buymax'      => 1,
                    'sort'        => 90,
                    'status'      => 1,
                    'detail'      => '<p>新疆库尔勒香梨，皮薄肉细、汁多核小，8斤家庭装。</p>',
                    'options'     => [
                        ['name' => '8斤家庭装', 'sell_price' => 39.9, 'market_price' => 69.9, 'cost_price' => 30, 'stock' => 150],
                    ],
                ],
            ],
        ];
    }

    /**
     * 幸运拼团：在拼团基础上增加开奖设置。
     * gua_num 中奖名额(≤成团人数)；中奖奖励 zjjl_type 1余额/2积分/3佣金/4券；
     * 未中奖奖励 bzjl_type（1余额取 fy_money_val，2积分 bzj_score，3佣金 bzj_commission，4券）；
     * 未中奖返还 fy_type 1按比例(fy_money,%)/2固定(fy_money_val)；tklx/failtklx 退款路线。
     *
     * @return array<int, array{service: class-string, method: string, data: array<string, mixed>}>
     */
    private function luckyItems(): array
    {
        return [
            [
                'service' => LuckyCollageService::class,
                'method'  => 'productSave',
                'data'    => [
                    'name'          => self::DEMO_TAG . '云南文山三七粉250g罐装',
                    'pic'           => self::IMG_URL . 'lucky_1.jpg',
                    'pics'          => self::IMG_URL . 'lucky_1.jpg',
                    'cid'           => 0,
                    'sellpoint'     => '10人成团抽1人免单，未中奖返红包+积分',
                    'teamnum'       => 10,
                    'teamhour'      => 24,
                    'leadermoney'   => 0,
                    'buymax'        => 1,
                    'sort'          => 100,
                    'status'        => 1,
                    'detail'        => '<p>云南文山三七超细粉，250g/罐。10人成团抽1名中奖返等额余额，未中奖返10%红包并赠100积分。</p>',
                    'gua_num'       => 1,
                    'zjmax'         => 1,
                    'zjjl_type'     => 1,
                    'zj_money'      => 59.9,
                    'zjscore'       => 0,
                    'zjcommission'  => 0,
                    'zj_yhqids'     => '',
                    'bzjl_type'     => 2,
                    'bzj_score'     => 100,
                    'bzj_commission' => 0,
                    'bzj_yhqids'    => '',
                    'fy_type'       => 1,
                    'fy_money'      => 10,
                    'fy_money_val'  => 0,
                    'red_give_mode' => 1,
                    'tklx'          => 1,
                    'failtklx'      => 1,
                    'freighttype'   => 1,
                    'options'       => [
                        ['name' => '250g罐装', 'sell_price' => 59.9, 'market_price' => 99, 'cost_price' => 40, 'stock' => 100],
                    ],
                ],
            ],
            [
                'service' => LuckyCollageService::class,
                'method'  => 'productSave',
                'data'    => [
                    'name'          => self::DEMO_TAG . '泰国天然乳胶护颈枕',
                    'pic'           => self::IMG_URL . 'lucky_2.jpg',
                    'pics'          => self::IMG_URL . 'lucky_2.jpg',
                    'cid'           => 0,
                    'sellpoint'     => '10人团抽2人免单，人体工学护颈',
                    'teamnum'       => 10,
                    'teamhour'      => 48,
                    'leadermoney'   => 0,
                    'buymax'        => 1,
                    'sort'          => 90,
                    'status'        => 1,
                    'detail'        => '<p>泰国天然乳胶护颈枕，人体工学曲线设计。10人成团抽2名中奖返等额余额，未中奖返15%红包并赠200积分。</p>',
                    'gua_num'       => 2,
                    'zjmax'         => 2,
                    'zjjl_type'     => 1,
                    'zj_money'      => 89,
                    'zjscore'       => 0,
                    'zjcommission'  => 0,
                    'zj_yhqids'     => '',
                    'bzjl_type'     => 2,
                    'bzj_score'     => 200,
                    'bzj_commission' => 0,
                    'bzj_yhqids'    => '',
                    'fy_type'       => 1,
                    'fy_money'      => 15,
                    'fy_money_val'  => 0,
                    'red_give_mode' => 1,
                    'tklx'          => 1,
                    'failtklx'      => 1,
                    'freighttype'   => 1,
                    'options'       => [
                        ['name' => '经典款', 'sell_price' => 89, 'market_price' => 199, 'cost_price' => 60, 'stock' => 80],
                    ],
                ],
            ],
        ];
    }

    /**
     * 砍价：sell_price 原价 / min_price 底价 / helpnum 帮砍人数 / limitHour 砍价时限(小时) /
     * directbuy 是否可直接购买 / starttime~endtime 活动时间(时间戳)。
     *
     * @return array<int, array{service: class-string, method: string, data: array<string, mixed>}>
     */
    private function kanjiaItems(): array
    {
        $now = time();

        return [
            [
                'service' => BargainService::class,
                'method'  => 'productSave',
                'data'    => [
                    'name'            => self::DEMO_TAG . '无线蓝牙降噪耳机',
                    'pic'             => self::IMG_URL . 'kanjia_1.jpg',
                    'pics'            => self::IMG_URL . 'kanjia_1.jpg',

                    'sell_price'      => 129,
                    'min_price'       => 69,
                    'helpnum'         => 8,
                    'limitHour'       => 24,
                    'stock'           => 100,
                    'directbuy'       => 0,
                    'freighttype'     => 1,
                    'helpgive_type'   => 0,
                    'helpgive_percent' => 0,
                    'helpgive_ff'     => 0,
                    'kjdata'          => [],
                    'sort'            => 100,
                    'status'          => 1,
                    'detail'          => '<p>无线蓝牙降噪耳机，长续航、低延迟。邀请8位好友帮砍，24小时内砍到底价69元。</p>',
                    'starttime'       => $now - 86400,
                    'endtime'         => $now + 7 * 86400,
                ],
            ],
            [
                'service' => BargainService::class,
                'method'  => 'productSave',
                'data'    => [
                    'name'            => self::DEMO_TAG . '家用多功能电煮锅1.5L',
                    'pic'             => self::IMG_URL . 'kanjia_2.jpg',
                    'pics'            => self::IMG_URL . 'kanjia_2.jpg',

                    'sell_price'      => 199,
                    'min_price'       => 99,
                    'helpnum'         => 10,
                    'limitHour'       => 48,
                    'stock'           => 80,
                    'directbuy'       => 1,
                    'freighttype'     => 1,
                    'helpgive_type'   => 0,
                    'helpgive_percent' => 0,
                    'helpgive_ff'     => 0,
                    'kjdata'          => [],
                    'sort'            => 90,
                    'status'          => 1,
                    'detail'          => '<p>1.5L家用多功能电煮锅，煎炒烹煮一锅搞定。支持砍价，也可直接下单购买。</p>',
                    'starttime'       => $now - 86400,
                    'endtime'         => $now + 7 * 86400,
                ],
            ],
        ];
    }

    /**
     * 秒杀：sell_price 秒杀价 / market_price 原价 / seckill_date 日期(Y-m-d) / seckill_time 场次(0-23点)；
     * 不传 guige 时 Service 自动建「默认」规格并回写库存。
     *
     * @return array<int, array{service: class-string, method: string, data: array<string, mixed>}>
     */
    private function seckillItems(): array
    {
        $today = date('Y-m-d');

        return [
            [
                'service' => SeckillService::class,
                'method'  => 'productSave',
                'data'    => [
                    'name'         => self::DEMO_TAG . '东北五常珍珠大米10kg',
                    'pic'          => self::IMG_URL . 'seckill_1.jpg',
                    'pics'         => self::IMG_URL . 'seckill_1.jpg',
                    'sellpoint'    => '今日10点准时秒杀，限量100份',
                    'market_price' => 79.9,
                    'sell_price'   => 49.9,
                    'cost_price'   => 40,
                    'stock'        => 100,
                    'buymax'       => 2,
                    'perlimit'     => 2,
                    'weight'       => 10000,
                    'sort'         => 100,
                    'status'       => 1,
                    'seckill_date' => $today,
                    'seckill_time' => 10,
                    'starttime'    => 0,
                    'detail'       => '<p>东北五常珍珠大米10kg，颗粒饱满、米香浓郁，今日10点限量秒杀。</p>',
                ],
            ],
            [
                'service' => SeckillService::class,
                'method'  => 'productSave',
                'data'    => [
                    'name'         => self::DEMO_TAG . '蓝月亮深层洁净洗衣液4kg',
                    'pic'          => self::IMG_URL . 'seckill_2.jpg',
                    'pics'         => self::IMG_URL . 'seckill_2.jpg',
                    'sellpoint'    => '今晚8点秒杀，手慢无',
                    'market_price' => 59.9,
                    'sell_price'   => 39.9,
                    'cost_price'   => 32,
                    'stock'        => 150,
                    'buymax'       => 1,
                    'perlimit'     => 1,
                    'weight'       => 4000,
                    'sort'         => 90,
                    'status'       => 1,
                    'seckill_date' => $today,
                    'seckill_time' => 20,
                    'starttime'    => 0,
                    'detail'       => '<p>蓝月亮深层洁净洗衣液4kg，强效去渍，今晚8点限量秒杀。</p>',
                ],
            ],
        ];
    }

    /**
     * 团购：sell_price 起价 / pricedata 阶梯价 [{num 满N人次, money 该档价}]，人数递增、价格递减；
     * starttime~endtime 活动时间（时间戳）。
     *
     * @return array<int, array{service: class-string, method: string, data: array<string, mixed>}>
     */
    private function tuangouItems(): array
    {
        $now = time();

        return [
            [
                'service' => TuangouService::class,
                'method'  => 'productSave',
                'data'    => [
                    'name'         => self::DEMO_TAG . '现摘烟台美早樱桃3斤装',
                    'pic'          => self::IMG_URL . 'tuangou_1.jpg',
                    'pics'         => self::IMG_URL . 'tuangou_1.jpg',
                    'cid'          => 0,
                    'sellpoint'    => '人越多越便宜，满50人低至59元',
                    'sell_price'   => 89,
                    'market_price' => 129,
                    'cost_price'   => 65,
                    'stock'        => 500,
                    'weight'       => 1500,
                    'perlimit'     => 5,
                    'sort'         => 100,
                    'status'       => 1,
                    'detail'       => '<p>烟台美早樱桃，现摘现发、果肉厚实。阶梯拼团：满10人79元、满30人69元、满50人59元。</p>',
                    'pricedata'    => [
                        ['num' => 10, 'money' => 79],
                        ['num' => 30, 'money' => 69],
                        ['num' => 50, 'money' => 59],
                    ],
                    'starttime'    => $now - 86400,
                    'endtime'      => $now + 7 * 86400,
                    'freighttype'  => 1,
                ],
            ],
            [
                'service' => TuangouService::class,
                'method'  => 'productSave',
                'data'    => [
                    'name'         => self::DEMO_TAG . '内蒙古羔羊肉卷2斤',
                    'pic'          => self::IMG_URL . 'tuangou_2.jpg',
                    'pics'         => self::IMG_URL . 'tuangou_2.jpg',
                    'cid'          => 0,
                    'sellpoint'    => '草原羔羊，凑团更优惠',
                    'sell_price'   => 128,
                    'market_price' => 168,
                    'cost_price'   => 100,
                    'stock'        => 300,
                    'weight'       => 1000,
                    'perlimit'     => 3,
                    'sort'         => 90,
                    'status'       => 1,
                    'detail'       => '<p>内蒙古草原羔羊肉卷2斤，鲜嫩不膻。阶梯拼团：满10人118元、满30人108元。</p>',
                    'pricedata'    => [
                        ['num' => 10, 'money' => 118],
                        ['num' => 30, 'money' => 108],
                    ],
                    'starttime'    => $now - 86400,
                    'endtime'      => $now + 10 * 86400,
                    'freighttype'  => 1,
                ],
            ],
        ];
    }

    /**
     * 周期购：ps_cycle 配送周期(1每日2每周3每月)；guiges 规格行
     * {name, cycle_days 每隔N天, qsnum 期数, each_num 每期数量, sell_price, stock}。
     *
     * @return array<int, array{service: class-string, method: string, data: array<string, mixed>}>
     */
    private function cycleItems(): array
    {
        return [
            [
                'service' => CycleService::class,
                'method'  => 'productSave',
                'data'    => [
                    'name'      => self::DEMO_TAG . '有机纯牛奶250ml×12盒/期',
                    'pic'       => self::IMG_URL . 'cycle_1.jpg',
                    'pics'      => self::IMG_URL . 'cycle_1.jpg',
                    'cid'       => 0,
                    'sellpoint' => '每周新鲜直达，分期配送',
                    'ps_cycle'  => 2,
                    'sort'      => 100,
                    'status'    => 1,
                    'detail'    => '<p>有机纯牛奶，每期250ml×12盒，按周新鲜配送，可自选期数。</p>',
                    'guiges'    => [
                        ['name' => '每周1箱·共4期', 'cycle_days' => 7, 'qsnum' => 4, 'each_num' => 1, 'sell_price' => 39.9, 'stock' => 100],
                        ['name' => '每周1箱·共8期', 'cycle_days' => 7, 'qsnum' => 8, 'each_num' => 1, 'sell_price' => 36.9, 'stock' => 80],
                    ],
                ],
            ],
            [
                'service' => CycleService::class,
                'method'  => 'productSave',
                'data'    => [
                    'name'      => self::DEMO_TAG . '高山生态散养土鸡蛋30枚/期',
                    'pic'       => self::IMG_URL . 'cycle_2.jpg',
                    'pics'      => self::IMG_URL . 'cycle_2.jpg',
                    'cid'       => 0,
                    'sellpoint' => '散养土鸡蛋，定期配送到家',
                    'ps_cycle'  => 2,
                    'sort'      => 90,
                    'status'    => 1,
                    'detail'    => '<p>高山生态散养土鸡蛋，每期30枚，定期配送、期数越多越划算。</p>',
                    'guiges'    => [
                        ['name' => '每10天1盒·共3期', 'cycle_days' => 10, 'qsnum' => 3, 'each_num' => 1, 'sell_price' => 32.9, 'stock' => 60],
                        ['name' => '每10天1盒·共6期', 'cycle_days' => 10, 'qsnum' => 6, 'each_num' => 1, 'sell_price' => 29.9, 'stock' => 60],
                    ],
                ],
            ],
        ];
    }

    /**
     * 服务预约（单规格，无规格子表）：sell_price 服务价 / sales 虚拟销量 / cid 留 0 不挂分类。
     *
     * @return array<int, array{service: class-string, method: string, data: array<string, mixed>}>
     */
    private function yuyueItems(): array
    {
        return [
            [
                'service' => YuyueService::class,
                'method'  => 'productAdd',
                'data'    => [
                    'name'       => self::DEMO_TAG . '精致洗车（轿车/SUV）',
                    'cid'        => 0,
                    'pic'        => self::IMG_URL . 'yuyue_1.jpg',
                    'pics'      => [self::IMG_URL . 'yuyue_1.jpg'],
                    'sellpoint'  => '外观清洗+内饰除尘，约40分钟',
                    'sell_price' => 39.9,
                    'sales'      => 128,
                    'sort'       => 100,
                    'status'     => 1,
                    'detail'     => '<p>包含车身冲洗、泡沫清洁、轮毂清洁与内饰除尘，服务时长约40分钟，到店核销。</p>',
                ],
            ],
            [
                'service' => YuyueService::class,
                'method'  => 'productAdd',
                'data'    => [
                    'name'       => self::DEMO_TAG . '肩颈舒缓推拿60分钟',
                    'cid'        => 0,
                    'pic'        => self::IMG_URL . 'yuyue_2.jpg',
                    'pics'      => [self::IMG_URL . 'yuyue_2.jpg'],
                    'sellpoint'  => '专业技师，缓解肩颈疲劳',
                    'sell_price' => 98,
                    'sales'      => 86,
                    'sort'       => 90,
                    'status'     => 1,
                    'detail'     => '<p>60分钟肩颈舒缓推拿，专业技师服务，需提前在线预约到店。</p>',
                ],
            ],
        ];
    }

    /* ====================== 列表自检（走与后台页面相同的 productList 入口） ====================== */

    private function report(Output $output): void
    {
        $output->writeln('<info>-- 列表自检（平台自营视图，按「' . self::DEMO_TAG . '」过滤）--</info>');

        $checks = [
            '拼团'     => fn () => app(CollageService::class)->productList($this->aid, self::DEMO_TAG, '', '', 1, 50),
            '幸运拼团' => fn () => app(LuckyCollageService::class)->productList($this->aid, self::DEMO_TAG, '', '', 1, 50),
            '砍价'     => fn () => app(BargainService::class)->productList($this->aid, 1, 50, self::DEMO_TAG, ''),
            '秒杀'     => fn () => app(SeckillService::class)->productList($this->aid, 1, 50, self::DEMO_TAG, ''),
            '团购'     => fn () => app(TuangouService::class)->productList($this->aid, self::DEMO_TAG, '', '', 0, 1, 50),
            '周期购'   => fn () => app(CycleService::class)->productList($this->aid, 1, 50, ['keyword' => self::DEMO_TAG]),
            '服务预约' => fn () => app(YuyueService::class)->productList($this->aid, self::DEMO_TAG, '', '', '', 'sort', 'desc', 1, 50),
        ];

        foreach ($checks as $label => $fn) {
            try {
                $res  = $fn();
                $list = $res['list'] ?? [];
                $line = '  ' . $label . '：列表返回 ' . count($list) . ' 个示例商品';
                if ($list) {
                    $first = $list[0];
                    $line .= '，如「' . ($first['name'] ?? '') . '」 价格=' . ($first['sell_price'] ?? '?')
                        . ' 图片=' . ($first['pic'] ?? '?');
                }
                $output->writeln((count($list) >= 2 ? '<info>' : '<comment>') . $line . (count($list) >= 2 ? '</info>' : '</comment>'));
            } catch (\Throwable $e) {
                $output->writeln('  <error>' . $label . ' 列表自检异常：' . $e->getMessage() . '</error>');
            }
        }
    }

    /* ====================== 商品图（GD 生成，幂等） ====================== */

    private function ensureImages(Output $output): void
    {
        $dir = public_path() . 'uploads' . DIRECTORY_SEPARATOR . 'demo' . DIRECTORY_SEPARATOR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        foreach ($this->imageSpecs() as $spec) {
            $path = $dir . $spec['file'];
            if (is_file($path) && filesize($path) > 0) {
                continue;
            }
            $this->drawImage($path, $spec);
            $output->writeln('  生成图片：uploads/demo/' . $spec['file']);
        }
    }

    private function drawImage(string $path, array $spec): void
    {
        $w = 600;
        $h = 600;
        $im = imagecreatetruecolor($w, $h);

        // 竖向渐变背景
        [$r1, $g1, $b1] = $spec['from'];
        [$r2, $g2, $b2] = $spec['to'];
        for ($y = 0; $y < $h; $y++) {
            $t = $y / $h;
            $c = imagecolorallocate($im, (int) ($r1 + ($r2 - $r1) * $t), (int) ($g1 + ($g2 - $g1) * $t), (int) ($b1 + ($b2 - $b1) * $t));
            imagefilledrectangle($im, 0, $y, $w, $y + 1, $c);
        }

        $white = imagecolorallocate($im, 255, 255, 255);
        $dark  = imagecolorallocate($im, (int) $r2, (int) $g2, (int) $b2);

        // 中央白色圆衬底
        imagefilledellipse($im, 300, 278, 300, 300, $white);

        $font = $this->fontPath();

        // 顶部模块标签
        $this->ttfCenter($im, 34, $font, $white, 300, 92, $spec['tag']);
        // 圆内商品短名
        $this->ttfCenter($im, (int) $spec['size'], $font, $dark, 300, 298, $spec['title']);
        // 底部规格/说明
        $this->ttfCenter($im, 24, $font, $white, 300, 505, $spec['sub']);
        // 角标
        $this->ttfCenter($im, 22, $font, imagecolorallocatealpha($im, 255, 255, 255, 70), 300, 565, 'DEMO 示例商品');

        imagejpeg($im, $path, 88);
        imagedestroy($im);
    }

    /**
     * 水平居中绘制一行文字（无中文字体时回退位图字体，仅保证不报错）。
     */
    private function ttfCenter(\GdImage $im, int $size, ?string $font, int $color, int $cx, int $baseline, string $text): void
    {
        if (null !== $font) {
            $box = imagettfbbox($size, 0, $font, $text);
            $tw  = $box[2] - $box[0];
            imagettftext($im, $size, 0, (int) ($cx - $tw / 2), $baseline, $color, $font, $text);
            return;
        }

        $tw = imagefontwidth(5) * strlen($text);
        imagestring($im, 5, (int) ($cx - $tw / 2), (int) ($baseline - 18), $text, $color);
    }

    private function fontPath(): ?string
    {
        $candidates = [
            'C:/Windows/Fonts/simhei.ttf',
            'C:/Windows/Fonts/msyh.ttc',
            '/usr/share/fonts/truetype/wqy/wqy-zenhei.ttc',
            '/usr/share/fonts/opentype/noto/NotoSansCJK-Bold.ttc',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        ];
        foreach ($candidates as $f) {
            if (is_file($f)) {
                return $f;
            }
        }
        return null;
    }

    /**
     * 14 张示例图的画面定义。
     *
     * @return array<int, array{file: string, from: array{0:int,1:int,2:int}, to: array{0:int,1:int,2:int}, tag: string, title: string, sub: string, size: int}>
     */
    private function imageSpecs(): array
    {
        return [
            ['file' => 'collage_1.jpg', 'from' => [255, 120, 138], 'to' => [224, 53, 75],  'tag' => '拼团特惠', 'title' => '红富士苹果', 'sub' => '5斤装·产地直发', 'size' => 46],
            ['file' => 'collage_2.jpg', 'from' => [255, 120, 138], 'to' => [224, 53, 75],  'tag' => '拼团特惠', 'title' => '库尔勒香梨', 'sub' => '8斤家庭装', 'size' => 46],
            ['file' => 'lucky_1.jpg',   'from' => [255, 168, 77],  'to' => [245, 112, 10], 'tag' => '幸运拼团', 'title' => '文山三七粉', 'sub' => '250g罐装', 'size' => 46],
            ['file' => 'lucky_2.jpg',   'from' => [255, 168, 77],  'to' => [245, 112, 10], 'tag' => '幸运拼团', 'title' => '乳胶护颈枕', 'sub' => '人体工学款', 'size' => 46],
            ['file' => 'kanjia_1.jpg',  'from' => [155, 124, 250], 'to' => [106, 72, 224], 'tag' => '砍价免费拿', 'title' => '降噪蓝牙耳机', 'sub' => '邀好友砍到底价', 'size' => 38],
            ['file' => 'kanjia_2.jpg',  'from' => [155, 124, 250], 'to' => [106, 72, 224], 'tag' => '砍价免费拿', 'title' => '多功能电煮锅', 'sub' => '1.5L家用款', 'size' => 38],
            ['file' => 'seckill_1.jpg', 'from' => [255, 92, 92],   'to' => [200, 30, 30],  'tag' => '限时秒杀', 'title' => '珍珠大米10kg', 'sub' => '今日10点开抢', 'size' => 36],
            ['file' => 'seckill_2.jpg', 'from' => [255, 92, 92],   'to' => [200, 30, 30],  'tag' => '限时秒杀', 'title' => '洁净洗衣液', 'sub' => '4kg·今日20点', 'size' => 40],
            ['file' => 'tuangou_1.jpg', 'from' => [255, 140, 84],  'to' => [232, 84, 27],  'tag' => '拼团更优惠', 'title' => '烟台大樱桃', 'sub' => '3斤·人越多越便宜', 'size' => 42],
            ['file' => 'tuangou_2.jpg', 'from' => [255, 140, 84],  'to' => [232, 84, 27],  'tag' => '拼团更优惠', 'title' => '羔羊肉卷', 'sub' => '2斤·草原直供', 'size' => 46],
            ['file' => 'cycle_1.jpg',   'from' => [78, 205, 125],   'to' => [31, 158, 84],  'tag' => '周期购·定期送', 'title' => '有机纯牛奶', 'sub' => '250ml×12盒/期', 'size' => 46],
            ['file' => 'cycle_2.jpg',   'from' => [78, 205, 125],   'to' => [31, 158, 84],  'tag' => '周期购·定期送', 'title' => '散养土鸡蛋', 'sub' => '30枚/期', 'size' => 46],
            ['file' => 'yuyue_1.jpg',   'from' => [90, 150, 250],  'to' => [29, 95, 208],  'tag' => '本地生活服务', 'title' => '精致洗车', 'sub' => '约40分钟', 'size' => 46],
            ['file' => 'yuyue_2.jpg',   'from' => [90, 150, 250],  'to' => [29, 95, 208],  'tag' => '本地生活服务', 'title' => '肩颈推拿', 'sub' => '60分钟·需预约', 'size' => 46],
        ];
    }
}
