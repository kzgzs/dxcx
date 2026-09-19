<?php

declare(strict_types=1);

namespace app\command;

use app\common\service\BookingService;
use app\common\service\CarhailingService;
use app\common\service\CollageService;
use app\common\service\CommissionSettleService;
use app\common\service\FenhongService;
use app\common\service\HotelService;
use app\common\service\LuckyCollageService;
use app\common\service\OrderService;
use app\common\service\PayService;
use app\common\service\PrizePoolService;
use app\common\service\RestaurantOrderService;
use app\common\service\ScoreshopService;
use app\common\service\SeckillService;
use app\common\service\TakeFoodService;
use app\common\service\WechatTmplService;
use app\common\service\WxpayService;
use app\common\service\YuebaoService;
use app\common\service\YuyueService;
use think\console\Command;
use think\console\Input;
use think\console\Output;

/**
 * 统一调度命令（P8 一期）
 *
 * 用法：php think schedule:run（由 Windows 计划任务每分钟触发）
 *
 * 设计：
 * - Redis SETNX 分布式锁（TTL 55 秒）防止多实例并发执行
 * - 子任务清单为类内可配置数组，逐项 try/catch 互不影响
 * - 每次执行输出摘要并追加写入 runtime/schedule.log
 * - 不引入常驻进程，Windows 友好（schtasks 每分钟调度）
 *
 * 子任务开关（.env，缺省全开）：
 * - SCHEDULE.ENABLE=1              总开关（0=整体跳过）
 * - HOTEL.EXPIRE_CANCEL=1          酒店未支付超时取消
 * - CARHAILING.EXPIRE_CANCEL=1     网约车未支付超时取消
 * - YUYUE.ACCEPT_TIMEOUT=60        预约已支付未接单超时退款（分钟，0=关闭）
 * - SECKILL.EXPIRE_CLOSE=1         秒杀未支付超时关单
 * - SCORESHOP.EXPIRE_CLOSE=1       积分商城未支付超时关单
 * - RESTAURANT.ORDER_SCHED=1       餐饮外卖超时关单与堂食自动完成
 * - RESTAURANT.TAKE_FOOD_EXPIRE=1  餐饮取餐号24小时未取过期
 * - PRIZEPOOL.SEND=1               奖金池发放（利润池每日/区域达标池周月）
 * - FENHONG.SETTLE=1               分红结算（股东/团队/区域三套，og.isfenhong 幂等）
 * - WECHAT.TMPL_SEND=1             微信模板/订阅消息统一发送（消费 wechat_send_tmpl_data）
 */
class ScheduleRun extends Command
{
    /** Redis 锁 key */
    protected const LOCK_KEY = 'dxcx:schedule:lock';

    /** 锁 TTL（秒）：小于调度间隔 60s，异常退出也能在下一轮前自动释放 */
    protected const LOCK_TTL = 55;

    protected function configure()
    {
        $this->setName('schedule:run')
            ->setDescription('统一调度：订单超时取消/支付对账/行业超时处理（Redis 锁防并发，幂等可重复执行）');
    }

    protected function execute(Input $input, Output $output)
    {
        if ('0' === (string) env('SCHEDULE.ENABLE', '1')) {
            $output->writeln('调度已关闭（SCHEDULE.ENABLE=0），跳过');

            return;
        }

        $redis = $this->redis();

        // SETNX + TTL：取不到锁说明上一轮还在跑（或异常未过期），直接退出
        if (!$redis->set(self::LOCK_KEY, (string) time(), ['nx', 'ex' => self::LOCK_TTL])) {
            $output->writeln('另一个调度实例正在运行（锁未释放），本次跳过');

            return;
        }

        $started = microtime(true);
        $results = [];

        try {
            foreach ($this->tasks() as $name => $task) {
                $begin = microtime(true);

                try {
                    $summary = $task();
                    $results[] = [$name, true, (string) $summary, (int) round((microtime(true) - $begin) * 1000)];
                } catch (\Throwable $e) {
                    $results[] = [$name, false, $e->getMessage(), (int) round((microtime(true) - $begin) * 1000)];
                }
            }
        } finally {
            $redis->del(self::LOCK_KEY);
        }

        // 输出摘要 + 写日志
        $lines = [];

        foreach ($results as [$name, $ok, $summary, $ms]) {
            $line = sprintf('[%s] %s | %s | %dms', $ok ? 'OK' : 'FAIL', $name, $summary, $ms);
            $output->writeln($line);
            $lines[] = $line;
        }

        $total = sprintf('总耗时 %dms', (int) round((microtime(true) - $started) * 1000));
        $output->writeln($total);

        $logFile = runtime_path() . 'schedule.log';
        file_put_contents(
            $logFile,
            date('Y-m-d H:i:s') . ' schedule:run' . PHP_EOL . implode(PHP_EOL, $lines) . PHP_EOL . $total . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }

    /**
     * 子任务清单（可配置数组：名称 => 执行闭包，返回本项摘要文本）
     *
     * @return array<string, callable(): string>
     */
    protected function tasks(): array
    {
        $tasks = [
            'order.cancel_expired' => function (): string {
                $count = app(OrderService::class)->cancelExpired();

                return '商城超时未支付取消 ' . $count . ' 笔';
            },
            'pay.reconcile' => function (): string {
                $result = app(PayService::class)->reconcileBiz();

                return sprintf('支付对账 扫描%d 捞回%d（%s）', $result['scanned'], $result['rescued'], $result['mock'] ? 'mock' : 'real');
            },
            // 微信 V3 分账（服务商抽成）：扫描已支付待分账记录；mock/无数据时空跑
            'wxpay.profit_sharing' => function (): string {
                if (PayService::isMock()) {
                    return '跳过（mock 模式）';
                }

                $result = WxpayService::profitSharingScan();

                return sprintf('分账 扫描%d 成功%d', $result['scanned'], $result['done']);
            },
        ];

        if ('0' !== (string) env('HOTEL.EXPIRE_CANCEL', '1')) {
            $tasks['hotel.cancel_expired'] = function (): string {
                $count = app(HotelService::class)->cancelExpiredUnpaid();

                return '酒店未支付超时取消 ' . $count . ' 笔';
            };
        }

        if ('0' !== (string) env('CARHAILING.EXPIRE_CANCEL', '1')) {
            $tasks['carhailing.cancel_expired'] = function (): string {
                $count = app(CarhailingService::class)->cancelExpiredUnpaid();

                return '网约车未支付超时取消 ' . $count . ' 笔';
            };
        }

        $tasks['yuyue.accept_timeout_refund'] = function (): string {
            $count = app(YuyueService::class)->refundAcceptTimeout();

            return '预约超时未接单退款 ' . $count . ' 笔';
        };

        $tasks['collage.team_timeout'] = function (): string {
            return $this->collageTimeoutSummary();
        };

        // 幸运拼团超时失败退款（P20-3 任务188）：team.status=1 且 createtime+teamhour*3600<now → 团置失败并按 failtklx 退款
        if ('0' !== (string) env('LUCKYCOLLAGE.TEAM_TIMEOUT', '1')) {
            $tasks['lucky_collage.team_timeout'] = function (): string {
                return $this->luckyCollageTimeoutSummary();
            };
        }

        // 秒杀超时未支付关单（P13-2）：status=0 超租户 autoclose 分钟 → 关单+库存回补+销量回退+退券，同事务幂等
        if ('0' !== (string) env('SECKILL.EXPIRE_CLOSE', '1')) {
            $tasks['seckill.close_expired'] = function (): string {
                $count = app(SeckillService::class)->closeExpired();

                return '秒杀超时未支付关单 ' . $count . ' 笔';
            };
        }

        // 积分商城超时未支付关单（P13-2）：status=0 超租户 autoclose 分钟 → 关单+库存回补+销量回退，同事务幂等
        if ('0' !== (string) env('SCORESHOP.EXPIRE_CLOSE', '1')) {
            $tasks['scoreshop.close_expired'] = function (): string {
                $count = app(ScoreshopService::class)->closeExpired();

                return '积分商城超时未支付关单 ' . $count . ' 笔';
            };
        }

        // 餐饮订单调度（P14-2）：外卖未支付超 takeaway_autoclose 分钟关单；堂食已支付超 autoshdays 天自动完成（status 1 → 3）
        if ('0' !== (string) env('RESTAURANT.ORDER_SCHED', '1')) {
            $tasks['restaurant.takeaway_close_expired'] = function (): string {
                $count = app(RestaurantOrderService::class)->closeExpiredTakeaway();

                return '外卖超时未支付关单 ' . $count . ' 笔';
            };

            $tasks['restaurant.shop_auto_finish'] = function (): string {
                $count = app(RestaurantOrderService::class)->autoFinishShop();

                return '堂食已支付自动完成 ' . $count . ' 笔';
            };
        }

        // 余额宝每日收益结算（P19-3 任务181）：扫描开启租户，收益=余额宝余额×日收益率%，Redis 日锁+当日流水双幂等
        if ('0' !== (string) env('YUEBAO.SETTLE', '1')) {
            $tasks['yuebao.settle_daily'] = function (): string {
                $result = app(YuebaoService::class)->settleDaily();

                return sprintf('余额宝每日收益 结算租户%d 入账会员%d', $result['tenants'], $result['members']);
            };
        }

        // 佣金结算（P19-4 任务182）：日结扫描冻结佣金记录，fxjiesuantime 收货后/付款后 + delaydays 延迟，事务行锁幂等
        if ('0' !== (string) env('COMMISSION.SETTLE', '1')) {
            $tasks['commission.settle_daily'] = function (): string {
                $result = app(CommissionSettleService::class)->settleDailyAll();

                return sprintf('佣金日结 扫描%d 入账%d 作废%d', $result['scanned'], $result['settled'], $result['void']);
            };

            $tasks['commission.settle_week'] = function (): string {
                $result = app(CommissionSettleService::class)->settleWeekAll();

                return sprintf('佣金周结 扫描%d 入账%d 作废%d', $result['scanned'], $result['settled'], $result['void']);
            };
        }

        // 奖金池三任务（P19-6 任务184）：
        // 利润池每日到 send_time 小时自动发放（当日幂等）；区域池周一结算上周、达标池每月1日结算上月（周期幂等）
        if ('0' !== (string) env('PRIZEPOOL.SEND', '1')) {
            $tasks['prizepool.send_profit'] = function (): string {
                $result = app(PrizePoolService::class)->scheduleProfitSend();

                return sprintf('利润奖金池发放 租户%d 入账%d', $result['tenants'], $result['sent']);
            };

            $tasks['prizepool.send_area'] = function (): string {
                $result = app(PrizePoolService::class)->scheduleAreaReachSend(0);

                return sprintf('区域奖金池发放 池%d 入账%d', $result['pools'], $result['sent']);
            };

            $tasks['prizepool.send_reach'] = function (): string {
                $result = app(PrizePoolService::class)->scheduleAreaReachSend(1);

                return sprintf('达标奖金池发放 池%d 入账%d', $result['pools'], $result['sent']);
            };
        }

        // 分红结算（P19-5 任务183）：每日扫描各租户未分红订单行（og.isfenhong=0），
        // 三套分红（股东/团队/区域）计算后写 member_fenhonglog status=0 待发放；isfenhong 标记幂等
        if ('0' !== (string) env('FENHONG.SETTLE', '1')) {
            $tasks['fenhong.settle_daily'] = function (): string {
                $result = app(FenhongService::class)->jiesuanAll();

                return sprintf('分红结算 租户%d 扫描行%d 生成记录%d 金额%.2f', $result['tenants'], $result['ogcount'], $result['logs'], $result['total']);
            };
        }

        // 餐饮取餐号过期（批次6-T6-4 任务205）：24小时未取自动 status=-1（制作中/待取餐 → 过期，条件更新幂等）
        if ('0' !== (string) env('RESTAURANT.TAKE_FOOD_EXPIRE', '1')) {
            $tasks['restaurant.take_food_expire'] = function (): string {
                $count = app(TakeFoodService::class)->expireExpired();

                return '餐饮取餐号24h未取过期 ' . $count . ' 笔';
            };
        }

        // 预定未支付超时关闭（批次6-T6-1 任务202，对标参考系统 auto_perminute）：
        // status=0 超 RESTAURANT_BOOKING.EXPIRE_MINUTES（默认30）分钟 → status=4，条件更新幂等，业务在 BookingService
        if ('0' !== (string) env('RESTAURANT_BOOKING.EXPIRE_CLOSE', '1')) {
            $tasks['restaurant.booking_close_expired'] = function (): string {
                $count = app(BookingService::class)->closeExpiredUnpaid();

                return '预定未支付超时关闭 ' . $count . ' 笔';
            };
        }

        // 微信模板/订阅消息统一发送（批次7-1 任务209）：消费 wechat_send_tmpl_data status=0 队列，
        // 行锁+status 条件更新幂等认领；成功写 mp_tmpl_sendlog，失败写 mp_tmpl_sendlog_errlog（单次上限防长时占用）
        if ('0' !== (string) env('WECHAT.TMPL_SEND', '1')) {
            $tasks['wechat.tmpl_send'] = function (): string {
                $result = app(WechatTmplService::class)->consumeQueue();

                return sprintf('模板消息发送 扫描%d 发送%d 失败%d 跳过%d', $result['scanned'], $result['sent'], $result['failed'], $result['skipped']);
            };
        }

        return $tasks;
    }

    /**
     * Redis 连接（单例；与 MemberService/OpenPlatformService 同一口径）
     */
    protected function redis(): \Redis
    {
        static $client = null;

        if (null === $client) {
            $client = new \Redis();
            $client->connect((string) env('REDIS.HOST', '127.0.0.1'), (int) env('REDIS.PORT', 6379), 3.0);

            $password = (string) env('REDIS.PASSWORD', '');
            if ('' !== $password) {
                $client->auth($password);
            }
        }

        return $client;
    }

    /**
     * 拼团超时解散（P13-3）：
     * team.status=1 且 createtime+teamhour*3600<now 的团 → 团置失败，
     * 团内已支付订单置关闭并原路退款+退积分+退券（每团一个事务，幂等可重跑）
     */
    protected function collageTimeoutSummary(): string
    {
        $teams  = app(CollageService::class)->dissolveExpiredTeams();

        return '拼团超时解散 ' . $teams . ' 团';
    }

    protected function luckyCollageTimeoutSummary(): string
    {
        $teams = app(LuckyCollageService::class)->dissolveExpiredTeams();

        return '幸运拼团超时失败 ' . $teams . ' 团';
    }
}
