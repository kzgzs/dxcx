<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\ScopeService;
use app\common\service\StatService;

/**
 * 租户后台 - 统计（首页面板 + 趋势 + 销售统计）
 * admin 应用，JWT role=admin，aid 走 ScopeService
 */
class Stat extends BaseController
{
    public function __construct(\think\App $app, protected StatService $statService)
    {
        parent::__construct($app);
    }

    /**
     * 首页面板汇总数据
     * GET /admin/dashboard
     */
    public function dashboard()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->statService->dashboard($aid));
    }

    /**
     * 近 N 天趋势（按日：订单金额/订单数/新增会员）
     * GET /admin/stat/trend?days=30
     */
    public function trend()
    {
        $aid = ScopeService::aidOf($this->request);

        $days = (int) $this->request->get('days', 30);

        return $this->success($this->statService->trend($aid, $days));
    }

    /**
     * 销售统计（年→月→日 三级钻取）
     * GET /admin/stat/sales?year&month&day&tjtype(1成交额/2成交量)
     */
    public function sales()
    {
        $aid = ScopeService::aidOf($this->request);

        $year   = (int) $this->request->get('year', 0);
        $month  = (int) $this->request->get('month', 0);
        $day    = (int) $this->request->get('day', 0);
        $tjtype = (int) $this->request->get('tjtype', 1);

        return $this->success($this->statService->sales($aid, $year, $month, $day, $tjtype));
    }

    /**
     * 商品销售统计（按商品 / 按规格，对齐参考系统订单-销售统计）
     * GET /admin/stat/goodsSales?type=&page=&limit=&field=&order=&mdid=&freight_id=&mid=&proname=
     *                                &ctime_start=&ctime_end=&paytime_start=&paytime_end=（时间为 Unix 秒）
     */
    public function goodsSales()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->statService->goodsSales($aid, (array) $this->request->get()));
    }
}
