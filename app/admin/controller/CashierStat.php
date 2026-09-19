<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\CashierService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 通用收银台·订单统计与交接班（批次6-T6-5 一期）
 *
 * 交接班为统计视图（无独立表）：统计当前 token 签发时间（iat）~当前的
 * 本收银员单数/营业额/按支付方式拆分，对标 canzhao CashierLogin::tongji
 */
class CashierStat extends BaseController
{
    /**
     * 订单统计（时间范围，默认今日；口径=已支付订单）
     * GET /admin/cashier/stat
     */
    public function index()
    {
        $aid = ScopeService::aidOf($this->request);

        $data = app(CashierService::class)->stat(
            $aid,
            (string) $this->request->get('starttime', ''),
            (string) $this->request->get('endtime', '')
        );

        return $this->success($data);
    }

    /**
     * 交接班统计（登录时刻~当前）
     * GET /admin/cashier/jiaoban
     */
    public function jiaoban()
    {
        $aid = ScopeService::aidOf($this->request);
        $uid = (int) ($this->request->authUid ?? 0);
        $iat = (int) (($this->request->authPayload ?? [])['iat'] ?? 0);

        return $this->success(app(CashierService::class)->jiaoban($aid, $uid, $iat));
    }
}
