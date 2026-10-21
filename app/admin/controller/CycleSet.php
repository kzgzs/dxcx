<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\CycleService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 周期购 系统设置（片C，对齐参考系统 CycleSet）
 *
 * 对应「扩展--周期购--系统设置」截图，逐项落地 cycle_sysset：
 * - autoshdays   自动收货天数（默认7，单位天，最小值为1）
 * - comment      商品评价（开启/关闭）
 * - comment_check 评价审核（开启：评价需后台审核；关闭：评价直接展示）
 * - vipdiscount 会员等级折扣（开启/关闭）
 * 真实落库 + 回读一致；C 端 ApiCycle 取 shopset 时按 comment/comment_check 口径执行。
 */
class CycleSet extends BaseController
{
    public function __construct(\think\App $app, protected CycleService $cycleService)
    {
        parent::__construct($app);
    }

    /**
     * 读取周期购系统设置
     * GET /admin/cycleSet/get
     */
    public function get()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->cycleService->getSysset($aid));
    }

    /**
     * 保存周期购系统设置
     * POST /admin/cycleSet/save {autoshdays, comment, comment_check, vipdiscount}
     */
    public function save()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->cycleService->saveSysset($aid, $this->request->post());

        return $this->success([], '提交成功');
    }
}
