<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\DepositService;
use app\common\service\ScopeService;
use think\facade\Db;

/**
 * 租户后台 - 餐饮寄存设置（批次6-T6-3 任务204）
 *
 * 表：restaurant_deposit_sysset（time 时限说明 + status 寄存开关）
 * 对标 canzhao RestaurantDepositSet（index/save）；status=0 时 C端 add 拦截
 */
class RestaurantDepositSet extends BaseController
{
    public function __construct(\think\App $app, protected DepositService $depositService)
    {
        parent::__construct($app);
    }

    /**
     * 读取寄存设置（不存在自动初始化）
     * GET /admin/restaurant/deposit/set/config
     */
    public function config()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->depositService->syssetRow($aid));
    }

    /**
     * 保存寄存设置
     * POST /admin/restaurant/deposit/set/save {time?, status?}
     */
    public function save()
    {
        $aid    = ScopeService::aidOf($this->request);
        $time   = trim((string) $this->request->post('time', ''));
        $status = (int) $this->request->post('status', 0);
        $status = in_array($status, [0, 1], true) ? $status : 0;

        if (mb_strlen($time) > 100) {
            $time = mb_substr($time, 0, 100);
        }

        $row = $this->depositService->syssetRow($aid);

        Db::name('restaurant_deposit_sysset')->where('id', $row['id'])->update([
            'time'   => $time,
            'status' => $status,
        ]);

        return $this->success([], '保存成功');
    }
}
