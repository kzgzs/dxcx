<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;

/**
 * 自定义表单引擎（租户设计表单 → 设计器组件调用 → C端填写提交 → 后台数据审核）
 *
 * 口径：
 * - 支付成功入账：分销佣金计算写 parent1-3
 * - 确认发放佣金 / 驳回退款扣回佣金
 * - C端提交：时间/数量/每人次数/必填校验
 *
 * 支付口径：只做支付接口对接（终端用户购买），商户结算/商户号配置由租户在支付配置页自理。
 * 分销时序：提交时快照 commissionset；支付成功 onPaid 计算 parent1-3 佣金；
 * 后台确认（st=1）才实际发放（is_send_commission 0→1）；驳回退款扣回（→2）。
 */
class FormService
{
    public function __construct(protected MemberAdminService $memberAdminService)
    {
    }

    /**
     * 表单支付成功入账（幂等核心：行锁 + paystatus=0 条件更新）
     *
     * @return bool true=本次入账 false=已处理过（幂等）
     */
    public function onPaid(int $orderId, string $payType, string $payNum = ''): bool
    {
        return (bool) Db::transaction(function () use ($orderId, $payType, $payNum) {
            $order = Db::name('form_order')->where('id', $orderId)->lock(true)->find();

            if (empty($order) || 0 !== (int) $order['paystatus']) {
                return false; // 已支付：幂等不重复处理
            }

            Db::name('form_order')->where('id', $orderId)->where('paystatus', 0)->update([
                'paystatus' => 1,
                'paytime'   => time(),
                'paytype'   => $payType,
                'paytypeid' => PayService::PAY_TYPE_IDS[$payType] ?? 0,
                'paynum'    => $payNum,
            ]);

            // ---------- 分销提成（沿 pid 向上找 parent1-3，逐级校验 can_agent） ----------
            $form = Db::name('form')->where('id', (int) $order['formid'])->find();

            if (!empty($form) && (int) $form['commissionset'] !== -1) {
                $ogdata = $this->calcCommission((int) $order['aid'], (array) $form, (array) $order);

                if (!empty($ogdata)) {
                    Db::name('form_order')->where('id', $orderId)->update($ogdata);
                }
            }

            return true;
        });
    }

    /**
     * 分销佣金计算
     *
     * commissionset=1 按单独设置比例：commissiondata1[等级ID][commissionN] × 支付金额 × 0.01
     * commissionset=2 按单独设置金额：commissiondata2[等级ID][commissionN] × 1
     * commissionset=0 按会员等级：等级表 commissionN × 支付金额 × 0.01
     *
     * @param array<string, mixed> $form
     * @param array<string, mixed> $order
     * @return array<string, mixed> parent1-3/parent1commission-3
     */
    public function calcCommission(int $aid, array $form, array $order): array
    {
        $ogdata = [];

        $member = Db::name('member')->where('aid', $aid)->where('id', (int) $order['mid'])->find();
        if (empty($member)) {
            return $ogdata;
        }

        $parents = [];      // 1..3 => member 行
        $aglevels = [];     // 1..3 => member_level 行
        $cursor = (int) ($member['pid'] ?? 0);

        for ($dai = 1; $dai <= 3; ++$dai) {
            if ($cursor <= 0) {
                break;
            }
            $row = Db::name('member')->where('aid', $aid)->where('id', $cursor)->find();
            if (empty($row)) {
                break;
            }
            $lv = Db::name('member_level')->where('aid', $aid)->where('id', (int) ($row['levelid'] ?? 0))->find();
            // 逐级 can_agent 校验：一级≠0 二级>1 三级>2
            $canAgent = (int) ($lv['can_agent'] ?? 0);
            $ok = 1 === $dai ? $canAgent !== 0 : (2 === $dai ? $canAgent > 1 : $canAgent > 2);
            if ($ok) {
                $parents[$dai]  = (array) $row;
                $aglevels[$dai] = (array) ($lv ?: []);
                $ogdata['parent' . $dai] = (int) $row['id'];
            }
            $cursor = (int) ($row['pid'] ?? 0);
        }

        $money = (float) ($order['money'] ?? 0);
        $set   = (int) $form['commissionset'];

        foreach ([1, 2, 3] as $dai) {
            if (empty($parents[$dai])) {
                continue;
            }
            $levelId = (int) ($parents[$dai]['levelid'] ?? 0);
            $commission = 0.0;

            if (1 === $set) {
                $data = json_decode((string) ($form['commissiondata1'] ?? ''), true) ?: [];
                $commission = (float) ($data[$levelId]['commission' . $dai] ?? 0) * $money * 0.01;
            } elseif (2 === $set) {
                $data = json_decode((string) ($form['commissiondata2'] ?? ''), true) ?: [];
                $commission = (float) ($data[$levelId]['commission' . $dai] ?? 0);
            } else {
                $commission = (float) ($aglevels[$dai]['commission' . $dai] ?? 0) * $money * 0.01;
            }

            $ogdata['parent' . $dai . 'commission'] = round($commission, 2);
        }

        return $ogdata;
    }

    /**
     * 确认发放分销佣金
     * 条件：commissionset!=-1 且 is_send_commission=0 且 isrefund=0
     *
     * @param array<string, mixed> $order form_order 行
     * @return bool 是否本次发放
     */
    public function sendCommission(int $uid, array $order): bool
    {
        if ((int) ($order['commissionset'] ?? -1) === -1) {
            return false;
        }
        if ((int) ($order['is_send_commission'] ?? 0) !== 0 || (int) ($order['isrefund'] ?? 0) !== 0) {
            return false;
        }

        $sent = false;
        foreach ([1, 2, 3] as $dai) {
            $parent = (int) ($order['parent' . $dai] ?? 0);
            $commission = round((float) ($order['parent' . $dai . 'commission'] ?? 0), 2);
            if ($parent > 0 && $commission > 0) {
                $remark = (1 === $dai ? '下级' : (2 === $dai ? '下二级' : '下三级')) . '购买奖励';
                $this->memberAdminService->addCommission((int) $order['aid'], $parent, $commission, $remark, $uid);
                $sent = true;
            }
        }

        Db::name('form_order')->where('id', (int) $order['id'])->update(['is_send_commission' => 1]);

        return $sent;
    }

    /**
     * 驳回退款
     * 原路退款 + 扣回已发佣金（is_send_commission=2，isrefund=1）
     *
     * @param array<string, mixed> $order form_order 行
     */
    public function refundOrder(array $order, string $reason): void
    {
        $money = (float) ($order['money'] ?? 0);
        if ((int) ($order['paystatus'] ?? 0) !== 1 || $money <= 0) {
            throw new BusinessException('该记录未支付，无需退款');
        }

        // 原路退款（mock 即时成功；真实模式走微信 V3）
        PayService::refundBiz('form', (int) $order['id'], $money, 'FR' . date('YmdHis') . random_int(1000, 9999));

        // 扣回已发佣金（is_send_commission=1 时负数扣回 → 2）
        $update = ['isrefund' => 1];
        if ((int) ($order['is_send_commission'] ?? 0) === 1) {
            foreach ([1, 2, 3] as $dai) {
                $parent = (int) ($order['parent' . $dai] ?? 0);
                $commission = round((float) ($order['parent' . $dai . 'commission'] ?? 0), 2);
                if ($parent > 0 && $commission > 0) {
                    $remark = (1 === $dai ? '下级' : (2 === $dai ? '下二级' : '下三级')) . '购买退款扣除';
                    $this->memberAdminService->addCommission((int) $order['aid'], $parent, -$commission, $remark, 0);
                }
            }
            $update['is_send_commission'] = 2;
        }

        Db::name('form_order')->where('id', (int) $order['id'])->update($update);
    }
}
