<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\EwaybillService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 电子面单设置（对标参考系统 EwaybillSet + Ewaybill::expressAccount 组）
 *
 * 快递鸟账号（用户ID/API KEY）+ 打印内容开关；快递公司账号绑定/解绑（字典 Kdniao::expressData）
 */
class EwaybillSet extends BaseController
{
    public function __construct(\think\App $app, protected EwaybillService $ewaybillService)
    {
        parent::__construct($app);
    }

    /**
     * 面单设置读取（不存在自动初始化，print_shop_content 数组化）
     * GET /admin/ewaybillSet/index
     */
    public function index()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->ewaybillService->setOf($aid));
    }

    /**
     * 面单设置保存
     * POST /admin/ewaybillSet/save {account_id, account_secret, print_remarks, print_shop, print_shop_content[]}
     */
    public function save()
    {
        $aid = ScopeService::aidOf($this->request);
        $uid = (int) (($this->request->authPayload ?? [])['uid'] ?? 0);

        $data = $this->request->post();
        $data = is_array($data) ? $data : [];

        $this->ewaybillService->saveSet($aid, $uid, $data);

        return $this->success([], '保存成功');
    }

    /**
     * 快递公司字典（绑定弹窗候选：动态字段/产品类型/温层/是否支持取消）
     * GET /admin/ewaybillSet/expressDict
     */
    public function expressDict()
    {
        return $this->success($this->ewaybillService->expressDict());
    }

    /**
     * 已绑定快递公司账号列表
     * GET /admin/ewaybillSet/accountList?page&pagesize
     */
    public function accountList()
    {
        $aid = ScopeService::aidOf($this->request);

        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->ewaybillService->accountList($aid, $page, $pageSize));
    }

    /**
     * 绑定/编辑快递公司账号（按字典字段动态校验）
     * POST /admin/ewaybillSet/accountSave {id?, express_code, customer_name..., paytype}
     */
    public function accountSave()
    {
        $aid = ScopeService::aidOf($this->request);
        $uid = (int) (($this->request->authPayload ?? [])['uid'] ?? 0);

        $data = $this->request->post();
        $data = is_array($data) ? $data : [];

        $this->ewaybillService->accountSave($aid, $uid, $data);

        return $this->success([], '操作成功');
    }

    /**
     * 解绑快递公司账号
     * POST /admin/ewaybillSet/accountDel {ids}
     */
    public function accountDel()
    {
        $aid = ScopeService::aidOf($this->request);
        $uid = (int) (($this->request->authPayload ?? [])['uid'] ?? 0);

        $ids = $this->request->post('ids', []);
        $ids = is_array($ids) ? $ids : [];

        $this->ewaybillService->accountDelete($aid, $uid, $ids);

        return $this->success([], '删除成功');
    }
}
