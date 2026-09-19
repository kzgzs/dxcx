<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\CashierService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 通用收银台·收银设置（批次6-T6-5 一期）
 *
 * restaurant_cashdesk 配置（支付开关/抹零位数）+ 自定义支付方式字典 CRUD
 */
class CashierSet extends BaseController
{
    /**
     * 收银设置详情
     * GET /admin/cashier/set
     */
    public function index()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success(app(CashierService::class)->deskConfig($aid));
    }

    /**
     * 保存收银设置
     * POST /admin/cashier/set
     */
    public function save()
    {
        $aid = ScopeService::aidOf($this->request);

        app(CashierService::class)->saveDeskConfig($aid, [
            'name'               => (string) $this->request->post('name', '收银台'),
            'wxpay'              => (int) $this->request->post('wxpay', 0),
            'cashpay'            => (int) $this->request->post('cashpay', 0),
            'moneypay'           => (int) $this->request->post('moneypay', 0),
            'remove_zero_length' => (int) $this->request->post('remove_zero_length', 0),
            'paypwd_use_status'  => (int) $this->request->post('paypwd_use_status', 0),
            'jiaoban_print_ids'  => (string) $this->request->post('jiaoban_print_ids', ''),
        ]);

        return $this->success([], '保存成功');
    }

    /**
     * 自定义支付方式列表（含停用）
     * GET /admin/cashier/set/customlist
     */
    public function customList()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success(['list' => app(CashierService::class)->customPayList($aid, false)]);
    }

    /**
     * 新增/编辑自定义支付方式
     * POST /admin/cashier/set/customsave
     */
    public function customSave()
    {
        $aid = ScopeService::aidOf($this->request);

        $data = app(CashierService::class)->customPaySave($aid, [
            'id'     => (int) $this->request->post('id', 0),
            'title'  => (string) $this->request->post('title', ''),
            'sort'   => (int) $this->request->post('sort', 0),
            'status' => (int) $this->request->post('status', 1),
        ]);

        return $this->success($data, '保存成功');
    }

    /**
     * 删除自定义支付方式
     * POST /admin/cashier/set/customdelete
     */
    public function customDelete()
    {
        $aid = ScopeService::aidOf($this->request);
        $id  = (int) $this->request->post('id', 0);

        app(CashierService::class)->customPayDelete($aid, $id);

        return $this->success([], '删除成功');
    }

    /**
     * 收银台可用支付方式（结算弹窗用）
     * GET /admin/cashier/set/paylist
     */
    public function payList()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success(['list' => app(CashierService::class)->payList($aid)]);
    }
}
