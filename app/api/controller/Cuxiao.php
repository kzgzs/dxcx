<?php

declare(strict_types=1);

namespace app\api\controller;

use app\BaseController;
use app\common\service\CuxiaoService;

/**
 * 客户端 - 商品促销（批次4-A 任务186）
 *
 * 匿名接口（AuthMiddleware 白名单放行），租户 aid 走 TenantMiddleware 解析
 */
class Cuxiao extends BaseController
{
    public function __construct(\think\App $app, protected CuxiaoService $cuxiaoService)
    {
        parent::__construct($app);
    }

    /**
     * 生效中的促销活动列表（匿名；proid>0 时仅返回命中该商品范围的活动）
     * GET /api/cuxiao/list?proid=
     */
    public function list()
    {
        $aid   = (int) $this->request->aid;
        $proid = (int) $this->request->get('proid', 0);

        return $this->success($this->cuxiaoService->apiActiveList($aid, $proid));
    }
}
