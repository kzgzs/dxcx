<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\ScopeService;

/**
 * 租户后台 - 餐饮统计（参考系统 tongji 空壳，本方实现日期区间按天聚合）
 *
 * 口径：已支付订单 status in 1,2,3（paytime 落天），退款 refund_status=2
 */
class RestaurantTongji extends BaseController
{
    private const PAID_STATUS = '1,2,3';

    /**
     * 按天统计（堂食/外卖/合计）
     * GET /admin/restaurant/tongji/list?stime=&etime=&scope=shop|takeaway|all
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        $stime = trim((string) $this->request->get('stime', date('Y-m-01')));
        $etime = trim((string) $this->request->get('etime', date('Y-m-d')));
        $start = strtotime($stime);
        $end   = strtotime($etime);
        if (false === $start || false === $end || $end < $start) {
            throw new BusinessException('日期区间不正确');
        }
        if (($end - $start) / 86400 > 366) {
            throw new BusinessException('查询区间不能超过366天');
        }

        $scope = (string) $this->request->get('scope', 'all');
        if (!in_array($scope, ['shop', 'takeaway', 'all'], true)) {
            $scope = 'all';
        }
        $tables = [];
        if ('shop' === $scope || 'all' === $scope) {
            $tables[] = 'restaurant_shop_order';
        }
        if ('takeaway' === $scope || 'all' === $scope) {
            $tables[] = 'restaurant_takeaway_order';
        }

        $rows   = [];
        $totals = ['orderCount' => 0, 'orderMoney' => 0.0, 'refundCount' => 0, 'refundMoney' => 0.0];

        for ($day = $start; $day <= $end; $day += 86400) {
            $dayStart = strtotime(date('Y-m-d', $day));
            $dayEnd   = $dayStart + 86400;

            $orderCount = 0;
            $orderMoney = 0.0;
            $refundCount = 0;
            $refundMoney = 0.0;

            foreach ($tables as $table) {
                $paid = ScopeService::query($table, $aid)
                    ->where('bid', 0)
                    ->whereIn('status', explode(',', self::PAID_STATUS))
                    ->where('paytime', '>=', $dayStart)
                    ->where('paytime', '<', $dayEnd);
                $orderCount += (int) (clone $paid)->count();
                $orderMoney += (float) (clone $paid)->sum('totalprice');

                $refund = ScopeService::query($table, $aid)
                    ->where('bid', 0)
                    ->where('refund_status', 2)
                    ->where('createtime', '>=', $dayStart)
                    ->where('createtime', '<', $dayEnd);
                $refundCount += (int) (clone $refund)->count();
                $refundMoney += (float) (clone $refund)->sum('refund_money');
            }

            $rows[] = [
                'date'        => date('Y-m-d', $dayStart),
                'orderCount'  => $orderCount,
                'orderMoney'  => round($orderMoney, 2),
                'refundCount' => $refundCount,
                'refundMoney' => round($refundMoney, 2),
                'netMoney'    => round($orderMoney - $refundMoney, 2),
            ];

            $totals['orderCount']  += $orderCount;
            $totals['orderMoney']  += $orderMoney;
            $totals['refundCount'] += $refundCount;
            $totals['refundMoney'] += $refundMoney;
        }

        $totals['orderMoney']  = round($totals['orderMoney'], 2);
        $totals['refundMoney'] = round($totals['refundMoney'], 2);
        $totals['netMoney']    = round($totals['orderMoney'] - $totals['refundMoney'], 2);

        return $this->success(['list' => $rows, 'totals' => $totals]);
    }

    /**
     * 订单构成统计（区间内堂食/外卖对比卡）
     * GET /admin/restaurant/tongji/overview?stime=&etime=
     */
    public function overview()
    {
        $aid = ScopeService::aidOf($this->request);

        $stime = trim((string) $this->request->get('stime', date('Y-m-01')));
        $etime = trim((string) $this->request->get('etime', date('Y-m-d')));
        $start = strtotime($stime);
        $end   = strtotime($etime);
        if (false === $start || false === $end || $end < $start) {
            throw new BusinessException('日期区间不正确');
        }
        $dayStart = strtotime(date('Y-m-d', $start));
        $dayEnd   = strtotime(date('Y-m-d', $end)) + 86400;

        $data = [];
        foreach (['shop' => 'restaurant_shop_order', 'takeaway' => 'restaurant_takeaway_order'] as $key => $table) {
            $paid = ScopeService::query($table, $aid)
                ->where('bid', 0)
                ->whereIn('status', explode(',', self::PAID_STATUS))
                ->where('paytime', '>=', $dayStart)
                ->where('paytime', '<', $dayEnd);
            $refund = ScopeService::query($table, $aid)
                ->where('bid', 0)
                ->where('refund_status', 2)
                ->where('createtime', '>=', $dayStart)
                ->where('createtime', '<', $dayEnd);

            $money = round((float) (clone $paid)->sum('totalprice'), 2);
            $refundMoney = round((float) (clone $refund)->sum('refund_money'), 2);
            $data[$key] = [
                'orderCount'  => (int) (clone $paid)->count(),
                'orderMoney'  => $money,
                'refundCount' => (int) (clone $refund)->count(),
                'refundMoney' => $refundMoney,
                'netMoney'    => round($money - $refundMoney, 2),
            ];
        }

        return $this->success($data);
    }
}
