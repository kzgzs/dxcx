<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\MerchantService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 门店管理（admin 应用，JWT role=admin，aid 走 ScopeService）
 */
class Store extends BaseController
{
    public function __construct(\think\App $app, protected MerchantService $merchantService)
    {
        parent::__construct($app);
    }

    /**
     * 门店列表（带 merchant_name）
     * GET /admin/store/list?page&pagesize&keyword&merchant_id
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        $keyword    = trim((string) $this->request->get('keyword', ''));
        $merchantId = (int) $this->request->get('merchant_id', 0);
        $page       = max(1, (int) $this->request->get('page', 1));
        $pageSize   = (int) $this->request->get('pagesize', 10);
        $pageSize   = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->merchantService->storeList($aid, $keyword, $merchantId, $page, $pageSize));
    }

    /**
     * 新增门店
     * POST /admin/store/add {merchant_id,name,contacts,phone,province,city,district,address,lng,lat,status}
     */
    public function add()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->merchantService->storeAdd($aid, $this->request->post()));
    }

    /**
     * 编辑门店
     * POST /admin/store/edit {id,...}
     */
    public function edit()
    {
        $aid = ScopeService::aidOf($this->request);

        $data = $this->request->post();
        $id   = (int) ($data['id'] ?? 0);

        if ($id <= 0) {
            throw new BusinessException('缺少门店ID');
        }

        return $this->success($this->merchantService->storeEdit($aid, $id, $data));
    }

    /**
     * 删除门店（软删）
     * POST /admin/store/delete {id}
     */
    public function delete()
    {
        $aid = ScopeService::aidOf($this->request);

        $id = (int) $this->request->post('id', 0);

        if ($id <= 0) {
            throw new BusinessException('缺少门店ID');
        }

        $this->merchantService->storeDelete($aid, $id);

        return $this->success([], '删除成功');
    }
}
