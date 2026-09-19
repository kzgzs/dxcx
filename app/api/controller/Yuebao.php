<?php

declare(strict_types=1);

namespace app\api\controller;

use app\common\service\YuebaoService;

/**
 * 客户端 - 余额宝（api 应用，JWT role=member，aid/mid 取自 token；P19-3 任务181）
 */
class Yuebao extends Base
{
    public function __construct(\think\App $app, protected YuebaoService $yuebaoService)
    {
        parent::__construct($app);
    }

    /**
     * 余额宝页数据（余额/收益率/今日与累计收益/设置/收款账户）
     * GET /api/yuebao/info
     */
    public function info()
    {
        [$aid, $mid] = $this->memberContext();

        return $this->success($this->yuebaoService->info($aid, $mid));
    }

    /**
     * 我的余额宝记录（st=0 收益明细 / st=1 提现记录）
     * GET /api/yuebao/log?st=&page=&pagesize=
     */
    public function log()
    {
        [$aid, $mid] = $this->memberContext();

        $st       = (int) $this->request->get('st', 0);
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 20);

        return $this->success($this->yuebaoService->records($aid, $mid, $st, $page, $pageSize));
    }

    /**
     * 申请提现（事务：扣余额宝 + 流水 type=2 + 落提现单待审核）
     * POST /api/yuebao/withdraw {money, paytype}
     */
    public function withdraw()
    {
        [$aid, $mid] = $this->memberContext();

        $money   = (float) $this->request->post('money', 0);
        $paytype = trim((string) $this->request->post('paytype', ''));

        $result = $this->yuebaoService->applyWithdraw($aid, $mid, $money, $paytype);

        return $this->success($result, '提交成功,请等待审核打款');
    }

    /**
     * 余额宝收益转余额
     * POST /api/yuebao/turnMoney {money}
     */
    public function turnMoney()
    {
        [$aid, $mid] = $this->memberContext();

        $money = (float) $this->request->post('money', 0);

        $result = $this->yuebaoService->turnToMoney($aid, $mid, $money);

        return $this->success($result, '转入成功');
    }
}
