<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\BookingService;
use app\common\service\PlogService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 餐饮预定设置（restaurant_booking_sysset，每租户一行，bid=0）
 *
 * 字段：timedata 时间段 JSON [{day:1今天~4大后天, hour, minute, hour2, minute2}] /
 *       prehour 提前小时 / pay 付费开关 / pay_money 默认定金 / status 预定开关
 */
class RestaurantBookingSet extends BaseController
{
    public function __construct(\think\App $app, private readonly BookingService $bookingService)
    {
        parent::__construct($app);
    }

    /**
     * 读取预定设置（含未来 4 天时段预览）
     * GET /admin/restaurant/booking/set
     */
    public function index()
    {
        $aid = ScopeService::aidOf($this->request);

        $set = $this->bookingService->getSysset($aid);

        $raw = json_decode((string) ($set['timedata'] ?? ''), true);

        $data = [
            'status'    => (int) ($set['status'] ?? 0),
            'pay'       => (int) ($set['pay'] ?? 0),
            'pay_money' => (float) ($set['pay_money'] ?? 0),
            'prehour'   => (int) ($set['prehour'] ?? 0),
            'timedata'  => is_array($raw) ? $raw : [],
            // 未来 4 天实际可选时段预览（已按提前量过滤）
            'time_slots' => $this->bookingService->timeSlots($set),
        ];

        return $this->success($data);
    }

    /**
     * 保存预定设置
     * POST /admin/restaurant/booking/set
     */
    public function save()
    {
        $aid = ScopeService::aidOf($this->request);

        $prehour = (int) $this->request->post('prehour', 0);
        if ($prehour < 0 || $prehour > 72) {
            throw new BusinessException('提前预定小时需在 0-72 之间');
        }

        $payMoney = (float) $this->request->post('pay_money', 0);
        if ($payMoney < 0) {
            throw new BusinessException('定金金额不能为负数');
        }
        if ($payMoney > 99999) {
            throw new BusinessException('定金金额过大');
        }

        $timedata = $this->request->post('timedata', []);
        if (is_string($timedata)) {
            $timedata = json_decode($timedata, true);
        }
        if (!is_array($timedata)) {
            $timedata = [];
        }

        $clean = [];
        foreach ($timedata as $i => $item) {
            if (!is_array($item)) {
                continue;
            }

            $day = (int) ($item['day'] ?? 0);
            if ($day < 1 || $day > 4) {
                throw new BusinessException('第 ' . ($i + 1) . ' 个时段的日期不正确（1今天~4大后天）');
            }

            $h1 = (int) ($item['hour'] ?? 0);
            $m1 = (int) ($item['minute'] ?? 0);
            $h2 = (int) ($item['hour2'] ?? $h1);
            $m2 = (int) ($item['minute2'] ?? $m1);

            if ($h1 < 0 || $h1 > 23 || $h2 < 0 || $h2 > 23) {
                throw new BusinessException('第 ' . ($i + 1) . ' 个时段的小时不正确');
            }
            if ($m1 < 0 || $m1 > 59 || $m2 < 0 || $m2 > 59) {
                throw new BusinessException('第 ' . ($i + 1) . ' 个时段的分钟不正确');
            }
            if (($h2 * 60 + $m2) <= ($h1 * 60 + $m1)) {
                throw new BusinessException('第 ' . ($i + 1) . ' 个时段的结束时间需晚于开始时间');
            }

            $clean[] = [
                'day'     => (string) $day,
                'hour'    => (string) $h1,
                'minute'  => (string) $m1,
                'hour2'   => (string) $h2,
                'minute2' => (string) $m2,
            ];
        }

        if (1 === (int) $this->request->post('status', 0) && empty($clean)) {
            throw new BusinessException('开启预定前请至少配置一个时间段');
        }

        $data = [
            'status'    => (int) $this->request->post('status', 0) === 1 ? 1 : 0,
            'pay'       => (int) $this->request->post('pay', 0) === 1 ? 1 : 0,
            'pay_money' => number_format($payMoney, 2, '.', ''),
            'prehour'   => $prehour,
            'timedata'  => json_encode($clean, JSON_UNESCAPED_UNICODE),
        ];

        $this->bookingService->saveSysset($aid, $data);

        PlogService::write($aid, (int) ($this->request->authUid ?? 0), '保存餐饮预定设置');

        return $this->success([], '保存成功');
    }
}
