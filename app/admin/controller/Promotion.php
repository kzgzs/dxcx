<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\PromotionService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 满减促销管理（admin 应用，JWT role=admin，aid 走 ScopeService）
 */
class Promotion extends BaseController
{
    public function __construct(\think\App $app, protected PromotionService $promotionService)
    {
        parent::__construct($app);
    }

    /**
     * 活动列表（status 空=全部；keyword 匹配名称；时间 Unix 秒）
     * GET /admin/promotion/list?page&pagesize&keyword&status
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        $status   = trim((string) $this->request->get('status', ''));
        $keyword  = trim((string) $this->request->get('keyword', ''));
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->promotionService->adminList($aid, $status, $keyword, $page, $pageSize));
    }

    /**
     * 新建活动（type1 要 full_money+cut_money；type2 要 full_num+discount；时间 Unix 秒）
     * POST /admin/promotion/add {name,type,full_money,full_num,cut_money,discount,start_time,end_time}
     */
    public function add()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->promotionService->add($aid, $this->request->post()), '创建成功');
    }

    /**
     * 编辑活动（已被订单使用时核心字段禁改；present 且不同才拒）
     * POST /admin/promotion/edit {id,...}
     */
    public function edit()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->promotionService->edit($aid, $this->request->post());

        return $this->success([], '保存成功');
    }

    /**
     * 启用/停用
     * POST /admin/promotion/status {id,status}
     */
    public function status()
    {
        $aid = ScopeService::aidOf($this->request);

        $id     = (int) $this->request->post('id', 0);
        $status = (int) $this->request->post('status', -1);

        $this->promotionService->status($aid, $id, $status);

        return $this->success([], 1 === $status ? '已启用' : '已停用');
    }

    /**
     * 删除活动（软删；已被订单使用拒绝）
     * POST /admin/promotion/delete {id}
     */
    public function delete()
    {
        $aid = ScopeService::aidOf($this->request);

        $id = (int) $this->request->post('id', 0);

        if ($id <= 0) {
            throw new BusinessException('缺少活动ID');
        }

        $this->promotionService->delete($aid, $id);

        return $this->success([], '删除成功');
    }
}
