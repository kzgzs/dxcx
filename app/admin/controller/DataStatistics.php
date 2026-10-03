<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\MarketingStatService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 营销数据统计（客户端·营销-数据统计）
 *
 * 对齐参考系统 yingxiao/DataStatistics：
 * 一次返回卡片/商品类型占比/销售趋势对比/会员消费/详细数据列表；
 * 支持时间范围（本日/近7天/近30天/本月/自定义）、商家、配送方式、趋势指标切换。
 */
class DataStatistics extends BaseController
{
    public function __construct(\think\App $app, protected MarketingStatService $service)
    {
        parent::__construct($app);
    }

    /**
     * 数据统计总览
     * GET /admin/dataStatistics/index?range=&custom_start=&custom_end=&bid=&freight_id=&qus=
     */
    public function index()
    {
        $aid = ScopeService::aidOf($this->request);

        $range = trim((string) $this->request->get('range', 'today'));

        // 自定义日期（前端传 Y-m-d）
        $customStart = $this->request->get('custom_start');
        $customEnd   = $this->request->get('custom_end');
        $customStart = $customStart ? strtotime((string) $customStart) : null;
        $customEnd   = $customEnd ? strtotime((string) $customEnd) : null;

        $bid       = (string) $this->request->get('bid', '-1');
        $freightId = (string) $this->request->get('freight_id', '');
        $qus       = (string) $this->request->get('qus', 'sales');

        return $this->success($this->service->index($aid, $range, $customStart, $customEnd, $bid, $freightId, $qus));
    }

    /**
     * 筛选项：商家 + 配送方式
     * GET /admin/dataStatistics/options
     */
    public function options()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->service->options($aid));
    }
}
