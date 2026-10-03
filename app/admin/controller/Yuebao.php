<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\ScopeService;
use app\common\service\YuebaoService;

/**
 * 租户后台 - 余额宝（admin 应用，JWT role=admin，aid 走 ScopeService；P19-3 任务181）
 *
 * 页面：余额宝明细（收益流水）/ 余额宝提现审核 / 余额宝设置（挂在系统设置段）
 */
class Yuebao extends BaseController
{
    public function __construct(\think\App $app, protected YuebaoService $yuebaoService)
    {
        parent::__construct($app);
    }

    /* ================= 1. 余额宝明细 ================= */

    /**
     * 余额宝收益流水列表 + 收支统计（member_yuebao_moneylog）
     * GET /admin/yuebao/moneylog/list
     */
    public function moneylogList()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->yuebaoService->adminMoneylogList($aid, [
            'nickname'   => $this->request->get('nickname', ''),
            'mid'        => $this->request->get('mid', 0),
            'type'       => $this->request->get('type', ''),
            'start_date' => $this->request->get('start_date', ''),
            'end_date'   => $this->request->get('end_date', ''),
            'page'       => $this->request->get('page', 1),
            'pagesize'   => $this->request->get('pagesize', 10),
        ]));
    }

    /* ================= 2. 余额宝提现 ================= */

    /**
     * 提现申请列表 + 状态统计（member_yuebao_withdrawlog）
     * GET /admin/yuebao/withdraw/list
     */
    public function withdrawList()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->yuebaoService->adminWithdrawList($aid, [
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
     * 提现审核（pass 1通过→待打款 / 2驳回→同事务返还余额宝+流水 type=4）
     * POST /admin/yuebao/withdraw/audit {id, pass, reason}
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

        $this->yuebaoService->withdrawAudit($aid, $id, $pass, $reason, (int) ($this->request->authUid ?? 0));

        return $this->success([], 1 === $pass ? '已通过，待打款' : '已驳回，金额已退回余额宝');
    }

    /**
     * 提现打款（mock 即时成功落 status=3 + 转账记录；真实模式预留 V3 转账桩）
     * POST /admin/yuebao/withdraw/pay {id}
     */
    public function withdrawPay()
    {
        $aid = ScopeService::aidOf($this->request);
        $id  = (int) $this->request->post('id', 0);

        $result = $this->yuebaoService->withdrawPay($aid, $id, (int) ($this->request->authUid ?? 0));

        return $this->success($result, '打款成功');
    }

    /* ================= 3. 余额宝设置（挂在系统设置段） ================= */

    /**
     * 余额宝设置读取（开关/收益率/提现下限/手续费/转余额开关）
     * GET /admin/yuebao/config
     */
    public function config()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->yuebaoService->config($aid));
    }

    /**
     * 余额宝设置保存（tenant_config sys_ 键）
     * POST /admin/yuebao/config {open_yuebao, yuebao_rate, yuebao_withdrawmin, yuebao_withdrawfee, yuebao_turn_yue}
     */
    public function saveConfig()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->yuebaoService->saveConfig($aid, [
            'open_yuebao'        => $this->request->post('open_yuebao', '0'),
            'yuebao_rate'        => $this->request->post('yuebao_rate', '0'),
            'yuebao_withdrawmin' => $this->request->post('yuebao_withdrawmin', '0'),
            'yuebao_withdrawfee' => $this->request->post('yuebao_withdrawfee', '0'),
            'yuebao_turn_yue'    => $this->request->post('yuebao_turn_yue', '0'),
        ]);

        return $this->success([], '保存成功');
    }

    /** POST /admin/yuebao/moneylog/delete */
    public function moneylogDelete()
    {
        $aid = ScopeService::aidOf($this->request);
        $ids = (array) $this->request->post('ids', []);
        if (empty($ids)) { $s = (int) $this->request->post('id', 0); if ($s > 0) $ids = [$s]; }
        $n = $this->yuebaoService->deleteMoneylog($aid, array_values(array_filter(array_map('intval', $ids))));
        return $this->success(['deleted' => $n], '删除成功');
    }

    /** POST /admin/yuebao/withdraw/delete */
    public function withdrawDelete()
    {
        $aid = ScopeService::aidOf($this->request);
        $ids = (array) $this->request->post('ids', []);
        if (empty($ids)) { $s = (int) $this->request->post('id', 0); if ($s > 0) $ids = [$s]; }
        $n = $this->yuebaoService->deleteWithdraw($aid, array_values(array_filter(array_map('intval', $ids))));
        return $this->success(['deleted' => $n], '删除成功');
    }

    /** GET /admin/yuebao/moneylog/export */
    public function moneylogExport()
    {
        $aid = ScopeService::aidOf($this->request);
        set_time_limit(0);
        ini_set('memory_limit', '1024M');
        [$titles, $rows] = $this->yuebaoService->moneylogExport($aid, [
            'nickname' => $this->request->get('nickname', ''), 'mid' => $this->request->get('mid', 0),
            'type'     => $this->request->get('type', ''),
            'start_date' => $this->request->get('start_date', ''), 'end_date' => $this->request->get('end_date', ''),
        ]);
        \util\ExcelUtil::export($titles, $rows, '余额宝明细_' . date('YmdHis'));
    }

    /** GET /admin/yuebao/withdraw/export */
    public function withdrawExport()
    {
        $aid = ScopeService::aidOf($this->request);
        set_time_limit(0);
        ini_set('memory_limit', '1024M');
        [$titles, $rows] = $this->yuebaoService->withdrawExport($aid, [
            'nickname' => $this->request->get('nickname', ''), 'mid' => $this->request->get('mid', 0),
            'status'   => $this->request->get('status', ''),
            'start_date' => $this->request->get('start_date', ''), 'end_date' => $this->request->get('end_date', ''),
        ]);
        \util\ExcelUtil::export($titles, $rows, '余额宝提现_' . date('YmdHis'));
    }
}
