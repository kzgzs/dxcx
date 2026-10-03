<?php

declare(strict_types=1);

namespace app\common\service;

use think\facade\Db;

/**
 * 租户后台「客户端·营销 → 确认收货奖励」演示数据种子（demo:confirmreward）。
 *
 * 名称/订单号统一以 DEMO-CR- 开头（同时作为幂等与清理标识）。
 *
 * 覆盖：
 *  - order_collect_reward：一条活动设置（reward_type=3 余额返现，开启）
 *  - order_collect_reward_record：12 条奖励记录，覆盖 reward_type 1/2/3/4 与
 *    ordertype shop/collage/tuangou/scoreshop/businessshop，含 goods_title 商品信息快照。
 */
class DemoConfirmRewardSeed
{
    public const DEMO_TAG = 'DEMO-CR-';

    private int $aid = 1;

    /** @var array<int, string> */
    private array $logs = [];

    /**
     * @return array{logs:array<int,string>, settings:int, records:int}
     */
    public function generate(int $aid, bool $reset = false): array
    {
        $this->aid = max(1, $aid);
        $this->logs = [];

        $this->log('==== 确认收货奖励演示数据生成开始（aid=' . $this->aid . ($reset ? '，--reset 重建' : '') . '）====');

        if (empty(Db::name('admin')->where('id', $this->aid)->find())) {
            $this->log('<error>平台租户 admin.id=' . $this->aid . ' 不存在。</error>');
            return ['logs' => $this->logs, 'settings' => 0, 'records' => 0];
        }

        if ($reset) {
            $p = $this->purge($this->aid);
            $this->log('  --reset 先清理：设置 ' . $p['settings'] . '、记录 ' . $p['records']);
        }

        $settingCount = $this->ensureSetting();
        $recordCount = $this->ensureRecords();

        $this->log('==== 生成结束：设置 ' . $settingCount . '、记录 ' . $recordCount . ' ====');

        return ['logs' => $this->logs, 'settings' => $settingCount, 'records' => $recordCount];
    }

    /**
     * 仅清理 DEMO-CR- 前缀演示数据。
     *
     * @return array{settings:int, records:int}
     */
    public function purge(int $aid): array
    {
        $aid = max(1, $aid);

        $recordCount = Db::name('order_collect_reward_record')
            ->where('aid', $aid)
            ->whereLike('ordernum', self::DEMO_TAG . '%')
            ->delete();

        // 设置行：通过 createtime 标记（DEMO 生成时打 mark）。这里清掉本命令 upsert 的那一条
        // （仅当它带 DEMO 标识 remark 不可行——表无 remark 列），改为按 demo 写入的固定 createtime 段清理。
        $settingCount = Db::name('order_collect_reward')
            ->where('aid', $aid)
            ->where('createtime', '>=', $this->demoMark())
            ->delete();

        return ['settings' => (int) $settingCount, 'records' => (int) $recordCount];
    }

    /* ================= 内部 ================= */

    /**
     * DEMO 写入的 createtime 起点（2026-01-01 00:00:00），用于识别本命令 upsert 的设置行。
     */
    private function demoMark(): int
    {
        return 1767225600;
    }

    private function ensureSetting(): int
    {
        // 活动时间：最近 30 天 ~ 未来 30 天
        $start = time() - 30 * 86400;
        $end   = time() + 30 * 86400;

        $row = Db::name('order_collect_reward')->where('aid', $this->aid)->order('id', 'desc')->find();

        $data = [
            'start_time'       => $start,
            'end_time'         => $end,
            'reward_type'      => 3,
            'score'            => 0,
            'coupon_id'        => 0,
            'money'            => 0.50,
            'commission'       => 0.00,
            'order_type'       => 'shop,collage,tuangou,scoreshop,businessshop',
            'platform'         => 'wx,h5,app',
            'gettj'            => '-1',
            'min_order_amount' => 20.00,
            'max_daily_return' => 3,
            'max_month_return' => 10,
            'prompt'           => '确认收货得0.5元余额返现，赶紧领取！',
            'bgcolor'          => '#EF9B39',
            'fontcolor'        => '#FFFFFF',
            'status'           => 1,
        ];

        if ($row) {
            Db::name('order_collect_reward')->where('id', (int) $row['id'])->update($data);
            $this->log('  更新活动设置 #' . $row['id'] . '（余额返现 0.50 元，已开启）');
        } else {
            $data['aid']        = $this->aid;
            $data['bid']        = 0;
            $data['createtime'] = $this->demoMark();
            Db::name('order_collect_reward')->insert($data);
            $this->log('  新建活动设置（余额返现 0.50 元，已开启）');
        }

        return 1;
    }

    private function ensureRecords(): int
    {
        // 取 aid=1 下真实会员（避免外键悬挂）
        $mids = Db::name('member')->where('aid', $this->aid)->order('id')->column('id');
        if (empty($mids)) {
            $this->log('<error>无可用会员，跳过记录生成。</error>');
            return 0;
        }

        // 取一张真实券（reward_type=2 展示券名）
        $coupon = Db::name('coupon')->where('aid', $this->aid)->where('deleted', 0)->order('id')->find();
        $couponId = $coupon ? (int) $coupon['id'] : 0;

        // 取一个真实商家（businessshop）
        $business = Db::name('business')->where('aid', $this->aid)->order('id')->find();
        $bid = $business ? (int) $business['id'] : 0;

        $plans = [
            ['ordertype' => 'shop',         'reward_type' => 1, 'reward' => 20,    'goods' => '北欧实木书桌 1.2m × 1', 'bid' => 0],
            ['ordertype' => 'shop',         'reward_type' => 3, 'reward' => 0.50,  'goods' => '人体工学办公椅 × 1',    'bid' => 0],
            ['ordertype' => 'collage',      'reward_type' => 2, 'reward' => $couponId, 'goods' => '网红零食大礼包（拼团） × 2', 'bid' => 0],
            ['ordertype' => 'tuangou',      'reward_type' => 3, 'reward' => 1.00,  'goods' => '双人下午茶套餐（到店） × 1', 'bid' => 0],
            ['ordertype' => 'scoreshop',    'reward_type' => 1, 'reward' => 10,    'goods' => '积分兑换马克杯 × 1',     'bid' => 0],
            ['ordertype' => 'businessshop', 'reward_type' => 4, 'reward' => 0.80,  'goods' => '商家自营帆布包 × 1',     'bid' => $bid],
            ['ordertype' => 'shop',         'reward_type' => 3, 'reward' => 0.50,  'goods' => '机械键盘 87键 × 1',      'bid' => 0],
            ['ordertype' => 'shop',         'reward_type' => 1, 'reward' => 30,    'goods' => '显示器支架 × 1',         'bid' => 0],
            ['ordertype' => 'collage',      'reward_type' => 3, 'reward' => 0.50,  'goods' => '纯棉T恤拼团 × 3',        'bid' => 0],
            ['ordertype' => 'tuangou',      'reward_type' => 2, 'reward' => $couponId, 'goods' => '亲子双人餐团购 × 1', 'bid' => 0],
            ['ordertype' => 'scoreshop',    'reward_type' => 3, 'reward' => 0.20,  'goods' => '积分兑换数据线 × 1',     'bid' => 0],
            ['ordertype' => 'businessshop', 'reward_type' => 3, 'reward' => 0.60,  'goods' => '商家自营笔记本 × 2',     'bid' => $bid],
        ];

        $now = time();
        $count = 0;
        foreach ($plans as $i => $p) {
            // 幂等：同 ordernum 已存在则跳过
            $ordernum = self::DEMO_TAG . str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT);
            $exists = Db::name('order_collect_reward_record')
                ->where('aid', $this->aid)->where('ordernum', $ordernum)->find();
            if ($exists) {
                $count++;
                continue;
            }

            $mid = (int) $mids[array_rand($mids)];
            $ordertime = $now - rand(1, 20) * 86400;
            $createtime = $ordertime + rand(1, 6) * 86400;

            Db::name('order_collect_reward_record')->insert([
                'aid'         => $this->aid,
                'bid'         => (int) $p['bid'],
                'mid'         => $mid,
                'orderid'     => 900000 + $i + 1,
                'ordernum'    => $ordernum,
                'ordertype'   => (string) $p['ordertype'],
                'goods_title' => (string) $p['goods'],
                'reward_type' => (int) $p['reward_type'],
                'reward'      => (float) $p['reward'],
                'ordertime'   => $ordertime,
                'createtime'  => $createtime,
            ]);
            $count++;
        }

        $this->log('  生成奖励记录 ' . $count . ' 条（覆盖 reward_type 1/2/3/4 与五种订单类型）');

        return $count;
    }

    private function log(string $line): void
    {
        $this->logs[] = $line;
    }
}
