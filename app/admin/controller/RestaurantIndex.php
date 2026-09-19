<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\ScopeService;
use think\facade\Db;

/**
 * 租户后台 - 餐饮首页（经营概览，对照参考系统 Restaurant/index）
 *
 * 统计卡：菜品/餐桌/堂食订单(数/金额/退款)/外卖订单(数/金额/退款)
 * 曲线：最近 30 天，type 1客户总数 2新增客户 3收款金额 4订单金额 5订单数
 */
class RestaurantIndex extends BaseController
{
    /** 已支付订单状态集合（口径同参考系统 status in 1,2,3） */
    private const PAID_STATUS = '1,2,3';

    /**
     * 经营概览统计卡
     * GET /admin/restaurant/index/overview
     */
    public function overview()
    {
        $aid = ScopeService::aidOf($this->request);

        $lastDayStart   = strtotime(date('Y-m-d', time() - 86400));
        $lastDayEnd     = $lastDayStart + 86400;
        $thisMonthStart = strtotime(date('Y-m-1'));
        $now            = time();

        $data = [];

        // 菜品数量
        $pro = ScopeService::query('restaurant_product', $aid)->where('bid', 0)->where('is_delete', 0);
        $data['productCount']  = (clone $pro)->count();
        $data['productCount0'] = (clone $pro)->where('status', 0)->count();
        $data['productCount1'] = (clone $pro)->where('status', 1)->count();

        // 餐桌数量
        $table = ScopeService::query('restaurant_table', $aid)->where('bid', 0);
        $data['tableCount']  = (clone $table)->count();
        $data['tableCount0'] = (clone $table)->where('status', 0)->count();
        $data['tableCount2'] = (clone $table)->where('status', 2)->count();

        // 堂食订单
        $data['shopOrderCount']             = $this->orderCount('restaurant_shop_order', $aid);
        $data['shopOrderLastDayCount']      = $this->orderCount('restaurant_shop_order', $aid, $lastDayStart, $lastDayEnd);
        $data['shopOrderThisMonthCount']    = $this->orderCount('restaurant_shop_order', $aid, $thisMonthStart, $now);
        $data['shopOrderMoney']             = $this->orderMoney('restaurant_shop_order', $aid);
        $data['shopOrderMoneyLastDayCount'] = $this->orderMoney('restaurant_shop_order', $aid, $lastDayStart, $lastDayEnd);
        $data['shopOrderMoneyThisMonthCount'] = $this->orderMoney('restaurant_shop_order', $aid, $thisMonthStart, $now);
        $data['refundMoney']             = $this->refundMoney('restaurant_shop_order', $aid);
        $data['refundMoneyLastDay']      = $this->refundMoney('restaurant_shop_order', $aid, $lastDayStart, $lastDayEnd);
        $data['refundMoneyThisMonth']    = $this->refundMoney('restaurant_shop_order', $aid, $thisMonthStart, $now);

        // 外卖订单
        $data['takeawayOrderCount']             = $this->orderCount('restaurant_takeaway_order', $aid);
        $data['takeawayOrderLastDayCount']      = $this->orderCount('restaurant_takeaway_order', $aid, $lastDayStart, $lastDayEnd);
        $data['takeawayOrderThisMonthCount']    = $this->orderCount('restaurant_takeaway_order', $aid, $thisMonthStart, $now);
        $data['takeawayOrderMoney']             = $this->orderMoney('restaurant_takeaway_order', $aid);
        $data['takeawayOrderMoneyLastDayCount'] = $this->orderMoney('restaurant_takeaway_order', $aid, $lastDayStart, $lastDayEnd);
        $data['takeawayOrderMoneyThisMonthCount'] = $this->orderMoney('restaurant_takeaway_order', $aid, $thisMonthStart, $now);
        $data['takeawayRefundMoney']             = $this->refundMoney('restaurant_takeaway_order', $aid);
        $data['takeawayRefundMoneyLastDay']      = $this->refundMoney('restaurant_takeaway_order', $aid, $lastDayStart, $lastDayEnd);
        $data['takeawayRefundMoneyThisMonth']    = $this->refundMoney('restaurant_takeaway_order', $aid, $thisMonthStart, $now);

        foreach ($data as $k => $v) {
            $data[$k] = is_float($v) ? round($v, 2) : (int) $v;
        }

        return $this->success($data);
    }

    /**
     * 最近 30 天曲线数据
     * GET /admin/restaurant/index/chart?type=1~5
     */
    public function chart()
    {
        $aid  = ScopeService::aidOf($this->request);
        $type = (int) $this->request->get('type', 4);
        if ($type < 1 || $type > 5) {
            $type = 4;
        }

        $monthEnd   = strtotime(date('Y-m-d', time() - 86400));
        $monthStart = $monthEnd - 86400 * 29;

        $dateArr = [];
        $dataArr = [];
        for ($i = 0; $i < 30; $i++) {
            $dayStart  = $monthStart + $i * 86400;
            $dayEnd    = $dayStart + 86400;
            $dateArr[] = date('m-d', $dayStart);

            if (1 === $type) {
                // 客户总数（累计）
                $v = ScopeService::query('member', $aid)->where('createtime', '<', $dayEnd)->count();
            } elseif (2 === $type) {
                // 新增客户数
                $v = ScopeService::query('member', $aid)
                    ->where('createtime', '>=', $dayStart)
                    ->where('createtime', '<', $dayEnd)
                    ->count();
            } elseif (3 === $type) {
                // 收款金额（餐饮类支付单）
                $v = ScopeService::query('payorder', $aid)
                    ->where('createtime', '>=', $dayStart)
                    ->where('createtime', '<', $dayEnd)
                    ->where('status', 1)
                    ->where('type', 'like', 'restaurant%')
                    ->sum('money');
            } elseif (4 === $type) {
                // 订单金额（堂食+外卖）
                $v = $this->orderMoney('restaurant_shop_order', $aid, $dayStart, $dayEnd)
                    + $this->orderMoney('restaurant_takeaway_order', $aid, $dayStart, $dayEnd);
            } else {
                // 订单数（堂食+外卖）
                $v = $this->orderCount('restaurant_shop_order', $aid, $dayStart, $dayEnd)
                    + $this->orderCount('restaurant_takeaway_order', $aid, $dayStart, $dayEnd);
            }

            $dataArr[] = round((float) $v, 2);
        }

        return $this->success(['dateArr' => $dateArr, 'dataArr' => $dataArr]);
    }

    // ---------- 内部统计 ----------

    private function orderCount(string $table, int $aid, ?int $start = null, ?int $end = null): int
    {
        $q = ScopeService::query($table, $aid)
            ->where('bid', 0)
            ->whereIn('status', explode(',', self::PAID_STATUS));
        if (null !== $start) {
            $q->where('paytime', '>=', $start)->where('paytime', '<', $end);
        }
        return (int) $q->count();
    }

    private function orderMoney(string $table, int $aid, ?int $start = null, ?int $end = null): float
    {
        $q = ScopeService::query($table, $aid)
            ->where('bid', 0)
            ->whereIn('status', explode(',', self::PAID_STATUS));
        if (null !== $start) {
            $q->where('paytime', '>=', $start)->where('paytime', '<', $end);
        }
        return (float) $q->sum('totalprice');
    }

    private function refundMoney(string $table, int $aid, ?int $start = null, ?int $end = null): float
    {
        $q = ScopeService::query($table, $aid)->where('bid', 0)->where('refund_status', 2);
        if (null !== $start) {
            $q->where('createtime', '>=', $start)->where('createtime', '<', $end);
        }
        return (float) $q->sum('refund_money');
    }
}
