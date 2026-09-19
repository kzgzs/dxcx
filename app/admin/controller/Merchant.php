<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\MerchantService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 商户管理（admin 应用，JWT role=admin，aid 走 ScopeService）
 */
class Merchant extends BaseController
{
    public function __construct(\think\App $app, protected MerchantService $merchantService)
    {
        parent::__construct($app);
    }

    /**
     * 商户列表
     * GET /admin/merchant/list?page&pagesize&keyword&status
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        $keyword  = trim((string) $this->request->get('keyword', ''));
        $status   = trim((string) $this->request->get('status', ''));
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->merchantService->list($aid, $keyword, $status, $page, $pageSize));
    }

    /**
     * 新增商户
     * POST /admin/merchant/add {name,logo?,contacts,phone,remark,status}
     */
    public function add()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->merchantService->add($aid, $this->request->post()));
    }

    /**
     * 编辑商户
     * POST /admin/merchant/edit {id,...}
     */
    public function edit()
    {
        $aid = ScopeService::aidOf($this->request);

        $data = $this->request->post();
        $id   = (int) ($data['id'] ?? 0);

        if ($id <= 0) {
            throw new BusinessException('缺少商户ID');
        }

        return $this->success($this->merchantService->edit($aid, $id, $data));
    }

    /**
     * 删除商户（软删；名下有门店时拒绝）
     * POST /admin/merchant/delete {id}
     */
    public function delete()
    {
        $aid = ScopeService::aidOf($this->request);

        $id = (int) $this->request->post('id', 0);

        if ($id <= 0) {
            throw new BusinessException('缺少商户ID');
        }

        $this->merchantService->delete($aid, $id);

        return $this->success([], '删除成功');
    }
}
