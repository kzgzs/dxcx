<?php

declare(strict_types=1);

namespace app\api\controller;

use app\common\exception\BusinessException;
use app\common\service\RechargeService;

/**
 * 客户端 - 充值（批次4-A 任务186）
 *
 * 充值档位读取 / 下单 / 支付 / 我的充值记录；支付成功回调匹配最高档发放赠送
 */
class Recharge extends Base
{
    public function __construct(\think\App $app, protected RechargeService $rechargeService)
    {
        parent::__construct($app);
    }

    /**
     * 充值档位（未启用返回空档位）
     * GET /api/recharge/giveset
     */
    public function giveset()
    {
        [$aid] = $this->memberContext();

        return $this->success($this->rechargeService->apiConfig($aid));
    }

    /**
     * 创建充值单
     * POST /api/recharge/create {money}
     */
    public function create()
    {
        [$aid, $mid] = $this->memberContext();

        $money = (float) $this->request->post('money', 0);

        if ($money <= 0) {
            throw new BusinessException('请填写充值金额');
        }

        return $this->success($this->rechargeService->create($aid, $mid, $money));
    }

    /**
     * 充值支付（mock 模式直接入账；真实模式返回微信 prepay 参数）
     * POST /api/recharge/pay {order_id, pay_type: wechat|alipay}
     */
    public function pay()
    {
        [$aid, $mid] = $this->memberContext();

        $orderId = (int) $this->request->post('order_id', 0);
        $payType = trim((string) $this->request->post('pay_type', ''));

        if ($orderId <= 0) {
            throw new BusinessException('缺少充值单ID');
        }
        if ('' === $payType) {
            throw new BusinessException('请选择支付方式');
        }

        return $this->success($this->rechargeService->pay($aid, $mid, $orderId, $payType));
    }

    /**
     * 我的充值记录
     * GET /api/recharge/orders?page=&pagesize=
     */
    public function orders()
    {
        [$aid, $mid] = $this->memberContext();

        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->rechargeService->myOrders($aid, $mid, $page, $pageSize));
    }
}
