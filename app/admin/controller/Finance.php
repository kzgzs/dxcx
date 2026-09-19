<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\CommissionSettleService;
use app\common\service\FinanceService;
use app\common\service\ScopeService;
use util\ExcelUtil;

/**
 * 租户后台 - 财务中心（admin 应用，JWT role=admin，aid 走 ScopeService；任务144）
 *
 * 八个页面：消费明细 / 余额明细 / 充值记录 / 余额提现 / 佣金（明细+提现） / 核销记录 / 发票管理 / 微信支付流水
 */
class Finance extends BaseController
{
    public function __construct(\think\App $app, protected FinanceService $financeService)
    {
        parent::__construct($app);
    }

    /* ================= 1. 消费明细（支付单） ================= */

    /**
     * 支付单列表（默认 status=1 已支付）+ 顶部统计（总收款/总退款/总消费）
     * GET /admin/finance/payorder/list
     */
    public function payorderList()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->financeService->payorderList($aid, [
            'status'         => $this->request->get('status', '1'),
            'paytypeid'      => $this->request->get('paytypeid', 0),
            'nickname'       => $this->request->get('nickname', ''),
            'mid'            => $this->request->get('mid', 0),
            'tel'            => $this->request->get('tel', ''),
            'ordernum'       => $this->request->get('ordernum', ''),
            'start_date'     => $this->request->get('start_date', ''),
            'end_date'       => $this->request->get('end_date', ''),
            'page'           => $this->request->get('page', 1),
            'pagesize'       => $this->request->get('pagesize', 10),
        ]));
    }

    /* ================= 2. 余额明细 ================= */

    /**
     * 余额流水列表 + 收支汇总
     * GET /admin/finance/moneylog/list
     */
    public function moneylogList()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->financeService->moneylogList($aid, [
            'nickname'   => $this->request->get('nickname', ''),
            'mid'        => $this->request->get('mid', 0),
            'inout'      => $this->request->get('inout', 0),
            'start_date' => $this->request->get('start_date', ''),
            'end_date'   => $this->request->get('end_date', ''),
            'page'       => $this->request->get('page', 1),
            'pagesize'   => $this->request->get('pagesize', 10),
        ]));
    }

    /* ================= 3. 充值记录 ================= */

    /**
     * 充值记录列表 + 统计
     * GET /admin/finance/recharge/list
     */
    public function rechargeList()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->financeService->rechargeList($aid, [
            'nickname'   => $this->request->get('nickname', ''),
            'mid'        => $this->request->get('mid', 0),
            'status'     => $this->request->get('status', ''),
            'ordernum'   => $this->request->get('ordernum', ''),
            'start_date' => $this->request->get('start_date', ''),
            'end_date'   => $this->request->get('end_date', ''),
            'page'       => $this->request->get('page', 1),
            'pagesize'   => $this->request->get('pagesize', 10),
        ]));
    }

    /**
     * 充值退款（事务：状态改退款 + 余额扣回 + 流水）
     * POST /admin/finance/recharge/refund {id}
     */
    public function rechargeRefund()
    {
        $aid = ScopeService::aidOf($this->request);
        $id  = (int) $this->request->post('id', 0);

        $result = $this->financeService->rechargeRefund($aid, $id, (int) ($this->request->authUid ?? 0));

        return $this->success($result, '已退款，充值金额已从会员余额扣回');
    }

    /* ================= 4. 余额提现 ================= */

    /**
     * 提现申请列表 + 状态统计
     * GET /admin/finance/withdraw/list
     */
    public function withdrawList()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->financeService->withdrawList($aid, [
            'nickname'   => $this->request->get('nickname', ''),
            'mid'        => $this->request->get('mid', 0),
            'status'     => $this->request->get('status', ''),
            'paytype'    => $this->request->get('paytype', ''),
            'start_date' => $this->request->get('start_date', ''),
            'end_date'   => $this->request->get('end_date', ''),
            'page'       => $this->request->get('page', 1),
            'pagesize'   => $this->request->get('pagesize', 10),
        ]));
    }

    /**
     * 提现审核（pass 1通过→待打款 / 2驳回→同事务返还 txmoney+流水）
     * POST /admin/finance/withdraw/audit {id, pass, reason}
     */
    public function withdrawAudit()
    {
        $aid = ScopeService::aidOf($this->request);

        $id     = (int) $this->request->post('id', 0);
        $pass   = (int) $this->request->post('pass', 0);
        $reason = trim((string) $this->request->post('reason', ''));

        if (2 === $pass && '' === $reason) {
            throw new BusinessException('驳回时请填写原因');
        }

        $this->financeService->withdrawAudit($aid, $id, $pass, $reason, (int) ($this->request->authUid ?? 0));

        return $this->success([], 1 === $pass ? '已通过，待打款' : '已驳回，金额已返还会员余额');
    }

    /**
     * 提现打款（mock 即时成功落 status=3 + 转账记录；真实模式预留 V3 转账桩）
     * POST /admin/finance/withdraw/pay {id}
     */
    public function withdrawPay()
    {
        $aid = ScopeService::aidOf($this->request);
        $id  = (int) $this->request->post('id', 0);

        $result = $this->financeService->withdrawPay($aid, $id, (int) ($this->request->authUid ?? 0));

        return $this->success($result, '打款成功');
    }

    /* ================= 5. 佣金（明细 + 提现） ================= */

    /**
     * 佣金明细列表 + 收支统计
     * GET /admin/finance/commission/list
     */
    public function commissionList()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->financeService->commissionList($aid, [
            'nickname'   => $this->request->get('nickname', ''),
            'mid'        => $this->request->get('mid', 0),
            'fhtype'     => $this->request->get('fhtype', ''),
            'start_date' => $this->request->get('start_date', ''),
            'end_date'   => $this->request->get('end_date', ''),
            'page'       => $this->request->get('page', 1),
            'pagesize'   => $this->request->get('pagesize', 10),
        ]));
    }

    /**
     * 佣金提现列表 + 状态统计
     * GET /admin/finance/commission/withdraw
     */
    public function commissionWithdrawList()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->financeService->withdrawList($aid, [
            'nickname'   => $this->request->get('nickname', ''),
            'mid'        => $this->request->get('mid', 0),
            'status'     => $this->request->get('status', ''),
            'paytype'    => $this->request->get('paytype', ''),
            'start_date' => $this->request->get('start_date', ''),
            'end_date'   => $this->request->get('end_date', ''),
            'page'       => $this->request->get('page', 1),
            'pagesize'   => $this->request->get('pagesize', 10),
        ], 'member_commission_withdrawlog'));
    }

    /**
     * 佣金提现审核（pass 1通过 / 2驳回→同事务 addcommission 返还+流水）
     * POST /admin/finance/commission/withdrawAudit {id, pass, reason}
     */
    public function commissionWithdrawAudit()
    {
        $aid = ScopeService::aidOf($this->request);

        $id     = (int) $this->request->post('id', 0);
        $pass   = (int) $this->request->post('pass', 0);
        $reason = trim((string) $this->request->post('reason', ''));

        if (2 === $pass && '' === $reason) {
            throw new BusinessException('驳回时请填写原因');
        }

        $this->financeService->withdrawAudit($aid, $id, $pass, $reason, (int) ($this->request->authUid ?? 0), 'member_commission_withdrawlog');

        return $this->success([], 1 === $pass ? '已通过，待打款' : '已驳回，佣金已返还会员');
    }

    /* ================= 6. 核销记录 ================= */

    /**
     * 核销记录（只读）
     * GET /admin/finance/hexiao/list
     */
    public function hexiaoList()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->financeService->hexiaoList($aid, [
            'keyword'    => $this->request->get('keyword', ''),
            'type'       => $this->request->get('type', ''),
            'start_date' => $this->request->get('start_date', ''),
            'end_date'   => $this->request->get('end_date', ''),
            'page'       => $this->request->get('page', 1),
            'pagesize'   => $this->request->get('pagesize', 10),
        ]));
    }

    /* ================= 7. 发票管理 ================= */

    /**
     * 发票列表 + 状态统计
     * GET /admin/finance/invoice/list
     */
    public function invoiceList()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->financeService->invoiceList($aid, [
            'status'     => $this->request->get('status', ''),
            'keyword'    => $this->request->get('keyword', ''),
            'start_date' => $this->request->get('start_date', ''),
            'end_date'   => $this->request->get('end_date', ''),
            'page'       => $this->request->get('page', 1),
            'pagesize'   => $this->request->get('pagesize', 10),
        ]));
    }

    /**
     * 发票批量审核（ids[] + pass 1通过 2驳回）
     * POST /admin/finance/invoice/audit
     */
    public function invoiceAudit()
    {
        $aid = ScopeService::aidOf($this->request);

        $ids    = (array) $this->request->post('ids', []);
        $pass   = (int) $this->request->post('pass', 0);
        $remark = trim((string) $this->request->post('remark', ''));

        $ids = array_values(array_filter(array_map('intval', $ids)));

        $result = $this->financeService->invoiceAudit($aid, $ids, $pass, $remark);

        return $this->success($result, 1 === $pass ? '已通过' : '已驳回');
    }

    /**
     * 发票回复（写审核备注 + 回复时间）
     * POST /admin/finance/invoice/reply {id, remark}
     */
    public function invoiceReply()
    {
        $aid = ScopeService::aidOf($this->request);

        $id     = (int) $this->request->post('id', 0);
        $remark = trim((string) $this->request->post('remark', ''));

        $this->financeService->invoiceReply($aid, $id, $remark);

        return $this->success([], '已回复');
    }

    /* ================= 8. 微信支付流水 ================= */

    /**
     * 微信支付流水（wxpay_log，只读）
     * GET /admin/finance/wxpaylog/list
     */
    public function wxpaylogList()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->financeService->wxpaylogList($aid, [
            'ordernum'       => $this->request->get('ordernum', ''),
            'transaction_id' => $this->request->get('transaction_id', ''),
            'isfenzhang'     => $this->request->get('isfenzhang', ''),
            'start_date'     => $this->request->get('start_date', ''),
            'end_date'       => $this->request->get('end_date', ''),
            'page'           => $this->request->get('page', 1),
            'pagesize'       => $this->request->get('pagesize', 10),
        ]));
    }

    /* ================= 9. 佣金记录（任务177） ================= */

    /**
     * 佣金记录列表 + 状态统计（member_commission_record，按 type 回查订单状态/单号）
     * GET /admin/finance/commissionRecord/list
     */
    public function commissionRecordList()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->financeService->commissionRecordList($aid, [
            'nickname'   => $this->request->get('nickname', ''),
            'mid'        => $this->request->get('mid', 0),
            'status'     => $this->request->get('status', ''),
            'orderid'    => $this->request->get('orderid', ''),
            'type'       => $this->request->get('type', ''),
            'start_date' => $this->request->get('start_date', ''),
            'end_date'   => $this->request->get('end_date', ''),
            'page'       => $this->request->get('page', 1),
            'pagesize'   => $this->request->get('pagesize', 10),
        ]));
    }

    /**
     * 佣金记录导出（筛选口径同列表）
     * GET /admin/finance/commissionRecord/export
     */
    public function commissionRecordExport()
    {
        $aid = ScopeService::aidOf($this->request);

        set_time_limit(0);
        ini_set('memory_limit', '1024M');

        [$titles, $rows] = $this->financeService->commissionRecordExport($aid, [
            'nickname'   => $this->request->get('nickname', ''),
            'mid'        => $this->request->get('mid', 0),
            'status'     => $this->request->get('status', ''),
            'orderid'    => $this->request->get('orderid', ''),
            'type'       => $this->request->get('type', ''),
            'start_date' => $this->request->get('start_date', ''),
            'end_date'   => $this->request->get('end_date', ''),
        ]);

        ExcelUtil::export($titles, $rows, '佣金记录_' . date('YmdHis'));
    }

    /**
     * 佣金记录手动结算（对照参考系统 jiesuan 手动按钮：跳过延迟/结算日条件，仍校验订单有效性）
     * POST /admin/finance/commissionRecord/settle {ids[]}
     */
    public function commissionRecordSettle()
    {
        $aid = ScopeService::aidOf($this->request);

        $ids = (array) $this->request->post('ids', []);
        $ids = array_values(array_filter(array_map('intval', $ids)));

        if (empty($ids)) {
            throw new BusinessException('请选择需要结算的佣金记录');
        }

        $result = app(CommissionSettleService::class)->manualSettle($aid, $ids);

        return $this->success($result, sprintf('结算完成：入账%d 作废%d 跳过%d', $result['settled'], $result['void'], $result['skipped']));
    }

    /* ================= 10. 分红记录（任务177） ================= */

    /**
     * 分红记录列表 + 状态统计（member_fenhonglog，按 module+ogids 回查订单状态/单号）
     * GET /admin/finance/fenhonglog/list
     */
    public function fenhonglogList()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->financeService->fenhonglogList($aid, [
            'nickname'    => $this->request->get('nickname', ''),
            'mid'         => $this->request->get('mid', 0),
            'status'      => $this->request->get('status', ''),
            'remark'      => $this->request->get('remark', ''),
            'ordernum'    => $this->request->get('ordernum', ''),
            'order_type'  => $this->request->get('order_type', ''),
            'start_date'  => $this->request->get('start_date', ''),
            'end_date'    => $this->request->get('end_date', ''),
            'page'        => $this->request->get('page', 1),
            'pagesize'    => $this->request->get('pagesize', 10),
        ]));
    }

    /**
     * 分红记录导出（筛选口径同列表）
     * GET /admin/finance/fenhonglog/export
     */
    public function fenhonglogExport()
    {
        $aid = ScopeService::aidOf($this->request);

        set_time_limit(0);
        ini_set('memory_limit', '1024M');

        [$titles, $rows] = $this->financeService->fenhonglogExport($aid, [
            'nickname'    => $this->request->get('nickname', ''),
            'mid'         => $this->request->get('mid', 0),
            'status'      => $this->request->get('status', ''),
            'remark'      => $this->request->get('remark', ''),
            'ordernum'    => $this->request->get('ordernum', ''),
            'order_type'  => $this->request->get('order_type', ''),
            'start_date'  => $this->request->get('start_date', ''),
            'end_date'    => $this->request->get('end_date', ''),
        ]);

        ExcelUtil::export($titles, $rows, '分红记录_' . date('YmdHis'));
    }

    /* ================= 11. 计次核销记录（任务177） ================= */

    /**
     * 计次核销记录列表 + 汇总统计（hexiao_shopproduct，按 bid 过滤商户维度）
     * GET /admin/finance/hexiaoShopproduct/list
     */
    public function hexiaoShopproductList()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->financeService->hexiaoShopproductList($aid, [
            'bid'        => $this->request->get('bid', ''),
            'nickname'   => $this->request->get('nickname', ''),
            'title'      => $this->request->get('title', ''),
            'mid'        => $this->request->get('mid', 0),
            'status'     => $this->request->get('status', ''),
            'start_date' => $this->request->get('start_date', ''),
            'end_date'   => $this->request->get('end_date', ''),
            'page'       => $this->request->get('page', 1),
            'pagesize'   => $this->request->get('pagesize', 10),
        ]));
    }

    /**
     * 计次核销记录导出（筛选口径同列表）
     * GET /admin/finance/hexiaoShopproduct/export
     */
    public function hexiaoShopproductExport()
    {
        $aid = ScopeService::aidOf($this->request);

        set_time_limit(0);
        ini_set('memory_limit', '1024M');

        [$titles, $rows] = $this->financeService->hexiaoShopproductExport($aid, [
            'bid'        => $this->request->get('bid', ''),
            'nickname'   => $this->request->get('nickname', ''),
            'title'      => $this->request->get('title', ''),
            'mid'        => $this->request->get('mid', 0),
            'status'     => $this->request->get('status', ''),
            'start_date' => $this->request->get('start_date', ''),
            'end_date'   => $this->request->get('end_date', ''),
        ]);

        ExcelUtil::export($titles, $rows, '计次核销记录_' . date('YmdHis'));
    }
}
