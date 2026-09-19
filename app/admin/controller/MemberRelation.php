<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\MemberRelationService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 会员关系图（admin 应用，JWT role=admin，aid 走 ScopeService）
 *
 * 对照参考系统 Member::charts/chartsMore：按 member.pid 递归直推树（深度上限 10），
 * 前端 ECharts tree 渲染，chartsMore 支持节点懒加载。
 */
class MemberRelation extends BaseController
{
    public function __construct(\think\App $app, protected MemberRelationService $relationService)
    {
        parent::__construct($app);
    }

    /**
     * 关系图主接口
     * GET /admin/memberRelation/charts?mid=&keyword=&depth=
     */
    public function charts()
    {
        $aid = ScopeService::aidOf($this->request);

        $mid     = (int) $this->request->get('mid', 0);
        $keyword = trim((string) $this->request->get('keyword', ''));
        $depth   = (int) $this->request->get('depth', 0);

        return $this->success($this->relationService->chart($aid, $mid, $keyword, $depth));
    }

    /**
     * 懒加载：指定会员的下级子树
     * GET /admin/memberRelation/chartsMore?mid=
     */
    public function chartsMore()
    {
        $aid = ScopeService::aidOf($this->request);

        $mid = (int) $this->request->get('mid', 0);

        return $this->success($this->relationService->chartsMore($aid, $mid));
    }
}
