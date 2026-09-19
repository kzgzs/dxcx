<?php

declare(strict_types=1);

namespace app\console\controller;

use app\BaseController;
use app\common\service\OrderService;
use app\common\service\TenantConfigService;
use think\facade\Db;

/**
 * 超管控制台 - 计划任务访问入口（对照参考系统 ApiAuto）
 *
 * GET /ApiAuto/index/key/{key}
 * 供宝塔面板「计划任务 - 访问URL」每分钟触发，免登录但必须携带正确的 cron_key。
 * 执行内容：
 * - 订单超时取消（未支付超时订单取消并回补库存，复用 OrderService::cancelExpired）
 * - 订单自动收货（已发货超过 N 天的订单自动确认收货）
 */
class ApiAuto extends BaseController
{
    /** 平台级配置统一用 aid=0（与 System 控制器同口径） */
    private const PLATFORM_AID = 0;

    /** 自动收货：已发货超过该天数自动确认收货 */
    private const AUTO_RECEIVE_DAYS = 7;

    /** 单次处理上限，防止一次请求处理过多订单超时 */
    private const BATCH_LIMIT = 500;

    public function index()
    {
        $key    = (string) $this->request->param('key', '');
        $expect = TenantConfigService::get(self::PLATFORM_AID, 'sys_system_cron_key');

        if ('' === $expect) {
            return $this->error('计划任务密钥未生成，请先打开控制台-系统设置页面一次');
        }

        if (!hash_equals($expect, $key)) {
            return $this->error('计划任务密钥不正确');
        }

        $summary = [];

        // 一、订单超时取消（幂等，复用既有服务）
        try {
            $cancelled      = app(OrderService::class)->cancelExpired();
            $summary['cancel_expired'] = $cancelled;
        } catch (\Throwable $e) {
            $summary['cancel_expired_error'] = $e->getMessage();
        }

        // 二、订单自动收货（已发货超期自动确认）
        try {
            $summary['auto_received'] = $this->autoReceive();
        } catch (\Throwable $e) {
            $summary['auto_received_error'] = $e->getMessage();
        }

        return $this->success($summary, '计划任务执行完成');
    }

    /**
     * 订单自动收货：status=已发货 且 send_time 早于 N 天前 → 已完成
     * 同步更新订单商品表状态（与 OrderService::confirm 同口径）
     */
    private function autoReceive(): int
    {
        $deadline = time() - self::AUTO_RECEIVE_DAYS * 86400;

        $ids = Db::name('shop_order')
            ->where('status', OrderService::STATUS_SHIPPED)
            ->where('send_time', '>', 0)
            ->where('send_time', '<', $deadline)
            ->limit(self::BATCH_LIMIT)
            ->column('id');

        if (empty($ids)) {
            return 0;
        }

        $now = time();

        // 条件更新保证幂等：仅仍处于已发货状态的订单会被确认收货
        Db::name('shop_order')
            ->whereIn('id', $ids)
            ->where('status', OrderService::STATUS_SHIPPED)
            ->update(['status' => OrderService::STATUS_FINISHED, 'collect_time' => $now]);

        Db::name('shop_order_goods')
            ->whereIn('orderid', $ids)
            ->where('status', OrderService::STATUS_SHIPPED)
            ->update(['status' => OrderService::STATUS_FINISHED, 'endtime' => $now]);

        return count($ids);
    }
}
