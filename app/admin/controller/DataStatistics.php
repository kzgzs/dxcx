<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\MarketingStatService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 营销数据统计（批次4-E 任务190 E2-6）
 *
 * 六模块聚合：shop/collage/kanjia/seckill/tuangou/yuyue（表不存在的跳过）
 * range：today / 7days / 30days / month
 */
class DataStatistics extends BaseController
{
    public function __construct(\think\App $app, protected MarketingStatService $service)
    {
        parent::__construct($app);
    }

    /**
     * 各模块销售汇总（订单额/订单数/占比/客单价）
     * GET /admin/dataStatistics/summary?range=
     */
    public function summary()
    {
        $aid = ScopeService::aidOf($this->request);

        $range = trim((string) $this->request->get('range', 'today'));

        return $this->success($this->service->summary($aid, $range));
    }

    /**
     * 金额分布档位
     * GET /admin/dataStatistics/moneyDist?range=
     */
    public function moneyDist()
    {
        $aid = ScopeService::aidOf($this->request);

        $range = trim((string) $this->request->get('range', 'today'));

        return $this->success($this->service->moneyDist($aid, $range));
    }

    /**
     * 时间趋势（today 按小时；其余按天）
     * GET /admin/dataStatistics/trend?range=
     */
    public function trend()
    {
        $aid = ScopeService::aidOf($this->request);

        $range = trim((string) $this->request->get('range', 'today'));

        return $this->success($this->service->trend($aid, $range));
    }
}
