<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\CashierService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 通用收银台·收银设置（批次6-T6-5 一期）
 *
 * restaurant_cashdesk 配置（支付开关/抹零位数）
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
     * 保存收银设置（对齐点大：info 基础配置 + pinfo 微信支付设置）
     * POST /admin/cashier/set
     */
    public function save()
    {
        $aid = ScopeService::aidOf($this->request);

        app(CashierService::class)->saveDeskConfig($aid, [
            'name'                    => (string) $this->request->post('name', '收银台'),
            'color1'                  => (string) $this->request->post('color1', '#1aad19'),
            'wxpay'                   => (int) $this->request->post('wxpay', 0),
            'cashpay'                 => (int) $this->request->post('cashpay', 0),
            'moneypay'                => (int) $this->request->post('moneypay', 0),
            'remove_zero_length'      => (int) $this->request->post('remove_zero_length', 0),
            'paypwd_use_status'       => (int) $this->request->post('paypwd_use_status', 0),
            'jiaoban_print_ids'       => $this->request->post('jiaoban_print_ids', ''),
            'is_use_mid_search'       => (int) $this->request->post('is_use_mid_search', 1),
            'cashdesk_give_score_set' => (int) $this->request->post('cashdesk_give_score_set', 1),
            'pinfo'                   => (array) $this->request->post('pinfo', []),
        ]);

        return $this->success([], '保存成功');
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
