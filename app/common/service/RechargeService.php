<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;

/**
 * 余额充值服务（批次4-A 任务186：充值赠送）
 *
 * 表口径：
 * - recharge_giveset（UNIQUE aid）：givedata text JSON [{money,give,give_score}] 档位行、
 *   status 1启用、caninput 1允许自定义金额、shuoming 说明
 * - recharge_order：money 充值金额、status 0待支付 1已支付、give_money/give_score 赠送快照
 *
 * 赠送匹配口径（对标参考系统 Payorder.php::recharge_pay@2983）：
 * 支付成功后遍历档位，取「充值金额 >= 档位金额」中档位金额最高者，发 give 余额 + give_score 积分
 *
 * 幂等：handleSuccess 行锁 + status=0 条件更新，重复回调不重复入账/赠送
 */
class RechargeService
{
    /* ================= admin 租户后台侧 ================= */

    /**
     * 读取充值赠送配置
     *
     * @return array{givedata: array, status: int, caninput: int, shuoming: string}
     */
    public function config(int $aid): array
    {
        $row = ScopeService::query('recharge_giveset', $aid)->find();

        return [
            'givedata' => $this->decodeGivedata((string) ($row['givedata'] ?? '')),
            'status'   => (int) ($row['status'] ?? 0),
            'caninput' => (int) ($row['caninput'] ?? 1),
            'shuoming' => (string) ($row['shuoming'] ?? ''),
        ];
    }

    /**
     * 保存充值赠送配置（档位行：money 充值额 / give 送余额 / give_score 送积分）
     *
     * @param array<string, mixed> $data {givedata: array, status, caninput, shuoming}
     */
    public function saveConfig(int $aid, array $data): void
    {
        $rows = $data['givedata'] ?? [];
        $status = (int) ($data['status'] ?? 0);
        $caninput = (int) ($data['caninput'] ?? 1);
        $shuoming = trim((string) ($data['shuoming'] ?? ''));

        if (!in_array($status, [0, 1], true)) {
            throw new BusinessException('状态值无效');
        }
        if (!in_array($caninput, [0, 1], true)) {
            throw new BusinessException('自定义金额开关值无效');
        }

        $givedata = [];

        if (is_array($rows)) {
            foreach ($rows as $row) {
                $money = round((float) ($row['money'] ?? 0), 2);
                $give = round((float) ($row['give'] ?? 0), 2);
                $giveScore = (int) ($row['give_score'] ?? 0);

                if ($money <= 0) {
                    continue; // 空行跳过
                }
                if ($give < 0 || $giveScore < 0) {
                    throw new BusinessException('赠送数值不能为负数');
                }

                $givedata[] = ['money' => $money, 'give' => $give, 'give_score' => $giveScore];
            }
        }

        // 档位金额唯一性
        $moneys = array_column($givedata, 'money');

        if (count($moneys) !== count(array_unique($moneys))) {
            throw new BusinessException('充值档位金额不能重复');
        }

        $update = [
            'givedata' => json_encode($givedata, JSON_UNESCAPED_UNICODE),
            'status'   => $status,
            'caninput' => $caninput,
            'shuoming' => $shuoming,
        ];

        $exists = ScopeService::query('recharge_giveset', $aid)->find();

        if (empty($exists)) {
            $update['aid']        = $aid;
            $update['createtime'] = time();
            Db::name('recharge_giveset')->insert($update);
        } else {
            Db::name('recharge_giveset')->where('id', (int) $exists['id'])->update($update);
        }
    }

    /* ================= api 会员侧 ================= */

    /**
     * C 端充值档位（未启用时返回空档位）
     *
     * @return array{status: int, caninput: int, shuoming: string, givedata: array}
     */
    public function apiConfig(int $aid): array
    {
        $config = $this->config($aid);

        if (1 !== $config['status']) {
            $config['givedata'] = [];
        }

        // 档位升序展示
        usort($config['givedata'], static fn (array $a, array $b) => $a['money'] <=> $b['money']);

        return $config;
    }

    /**
     * 创建充值单（校验开关与档位；caninput=0 时金额必须命中档位）
     *
     * @return array{id: int, order_sn: string, money: float}
     */
    public function create(int $aid, int $mid, float $money): array
    {
        $money = round($money, 2);

        if ($money <= 0) {
            throw new BusinessException('充值金额必须大于0');
        }

        $config = $this->config($aid);

        if (1 !== $config['status']) {
            throw new BusinessException('充值功能暂未开启');
        }

        if (1 !== $config['caninput']) {
            $matched = false;

            foreach ($config['givedata'] as $row) {
                if (abs((float) $row['money'] - $money) < 0.005) {
                    $matched = true;
                    break;
                }
            }

            if (!$matched) {
                throw new BusinessException('请选择充值档位金额');
            }
        }

        $now = time();

        $orderId = (int) Db::name('recharge_order')->insertGetId([
            'aid'        => $aid,
            'mid'        => $mid,
            'money'      => $money,
            'ordernum'   => '',
            'createtime' => $now,
            'status'     => 0,
            'platform'   => 'api',
        ]);

        $orderSn = 'R' . date('YmdHis', $now)
            . str_pad((string) ($aid % 10000), 4, '0', STR_PAD_LEFT)
            . str_pad((string) ($orderId % 1000000), 6, '0', STR_PAD_LEFT);

        Db::name('recharge_order')->where('id', $orderId)->update(['ordernum' => $orderSn]);

        return ['id' => $orderId, 'order_sn' => $orderSn, 'money' => $money];
    }

    /**
     * 充值支付（mock 模式内部直接入账；真实模式本期桩）
     *
     * @return array<string, mixed>
     */
    public function pay(int $aid, int $mid, int $orderId, string $payType): array
    {
        if (!isset(PayService::PAY_TYPE_IDS[$payType])) {
            throw new BusinessException('不支持的支付方式');
        }

        $order = ScopeService::query('recharge_order', $aid)
            ->where('id', $orderId)
            ->where('mid', $mid)
            ->find();

        if (empty($order)) {
            throw new BusinessException('充值单不存在', 404, 404);
        }

        if (1 === (int) $order['status']) {
            return ['mock' => PayService::isMock(), 'status' => 1]; // 已支付：幂等
        }

        if (PayService::isMock()) {
            $this->handleSuccess((int) $order['id'], $payType, 'MOCK' . time() . random_int(1000, 9999));

            return ['mock' => true, 'status' => 1];
        }

        // 真实模式：微信 V3 下单（回调经 pay/notify 路由时按 ordernum 回落 recharge 处理，本期桩）
        if ('wechat' === $payType) {
            return WxpayService::createOrder(
                $aid,
                $mid,
                (string) $order['ordernum'],
                (float) $order['money'],
                '余额充值',
                'recharge'
            );
        }

        throw new BusinessException('支付宝支付接入开发中（TODO）', 501, 501);
    }

    /**
     * 充值成功入账 + 档位赠送（幂等核心：行锁 + status=0 条件更新）
     *
     * @return bool true=本次入账 false=已处理过
     */
    public function handleSuccess(int $orderId, string $payType, string $payNum = ''): bool
    {
        return (bool) Db::transaction(function () use ($orderId, $payType, $payNum) {
            $order = Db::name('recharge_order')->where('id', $orderId)->lock(true)->find();

            if (empty($order) || 0 !== (int) $order['status']) {
                return false; // 已处理：幂等
            }

            $aid   = (int) ($order['aid'] ?? 0);
            $mid   = (int) ($order['mid'] ?? 0);
            $money = round((float) ($order['money'] ?? 0), 2);

            // 档位匹配：取满足金额的最高档
            $config = $this->config($aid);
            $give = $this->matchGive($config['givedata'], $money);

            Db::name('recharge_order')->where('id', $orderId)->where('status', 0)->update([
                'status'     => 1,
                'paytime'    => time(),
                'paytype'    => $payType,
                'paytypeid'  => PayService::PAY_TYPE_IDS[$payType] ?? 0,
                'paynum'     => $payNum,
                'give_money' => $give['give'],
                'give_score' => $give['give_score'],
            ]);

            $member = Db::name('member')->where('id', $mid)->where('aid', $aid)->lock(true)->find();

            if (empty($member)) {
                throw new BusinessException('会员不存在', 404, 404);
            }

            // 充值本金入账
            $after = round((float) ($member['money'] ?? 0) + $money, 2);

            Db::name('member')->where('id', $mid)->where('aid', $aid)->update(['money' => $after]);

            Db::name('member_moneylog')->insert([
                'aid'        => $aid,
                'mid'        => $mid,
                'money'      => $money,
                'after'      => $after,
                'createtime' => time(),
                'remark'     => '余额充值',
                'paytype'    => 'recharge',
            ]);

            // 档位赠送：送余额
            if ($give['give'] > 0) {
                $afterGive = round($after + $give['give'], 2);

                Db::name('member')->where('id', $mid)->where('aid', $aid)->update(['money' => $afterGive]);

                Db::name('member_moneylog')->insert([
                    'aid'        => $aid,
                    'mid'        => $mid,
                    'money'      => $give['give'],
                    'after'      => $afterGive,
                    'createtime' => time(),
                    'remark'     => '充值赠送',
                    'paytype'    => 'rechargegive',
                ]);
            }

            // 档位赠送：送积分
            if ($give['give_score'] > 0) {
                $afterScore = (int) ($member['score'] ?? 0) + $give['give_score'];

                Db::name('member')->where('id', $mid)->where('aid', $aid)->update(['score' => $afterScore]);

                Db::name('member_scorelog')->insert([
                    'aid'        => $aid,
                    'bid'        => 0,
                    'mid'        => (string) $mid,
                    'score'      => $give['give_score'],
                    'after'      => $afterScore,
                    'used'       => 0,
                    'createtime' => time(),
                    'remark'     => '充值赠送',
                    'type'       => 1,
                    'channel'    => 'rechargegive',
                    'status'     => 0,
                    'uid'        => 0,
                ]);
            }

            return true;
        });
    }

    /**
     * 我的充值记录
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function myOrders(int $aid, int $mid, int $page, int $pageSize): array
    {
        $query = ScopeService::query('recharge_order', $aid)->where('mid', $mid);

        $total = (clone $query)->count();

        $rows = $query->order('id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        $list = array_map(static fn (array $row) => [
            'id'         => (int) ($row['id'] ?? 0),
            'order_sn'   => (string) ($row['ordernum'] ?? ''),
            'money'      => (float) ($row['money'] ?? 0),
            'give_money' => (float) ($row['give_money'] ?? 0),
            'give_score' => (int) ($row['give_score'] ?? 0),
            'status'     => (int) ($row['status'] ?? 0),
            'pay_type'   => (string) ($row['paytype'] ?? ''),
            'pay_time'   => (int) ($row['paytime'] ?? 0) > 0 ? date('Y-m-d H:i:s', (int) $row['paytime']) : '',
            'create_time' => (int) ($row['createtime'] ?? 0) > 0 ? date('Y-m-d H:i:s', (int) $row['createtime']) : '',
        ], $rows);

        return ['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize];
    }

    /* ================= 内部工具 ================= */

    /**
     * 档位匹配：充值金额 >= 档位金额 中档位金额最高者（对标参考系统 recharge_pay）
     *
     * @param array<int, array<string, mixed>> $givedata
     * @return array{give: float, give_score: int}
     */
    protected function matchGive(array $givedata, float $money): array
    {
        $give = 0.0;
        $giveScore = 0;
        $bestMoney = 0.0;

        foreach ($givedata as $row) {
            $rowMoney = (float) ($row['money'] ?? 0);

            if ($rowMoney > 0 && $money >= $rowMoney && $rowMoney > $bestMoney) {
                $bestMoney = $rowMoney;
                $give = round((float) ($row['give'] ?? 0), 2);
                $giveScore = (int) ($row['give_score'] ?? 0);
            }
        }

        return ['give' => $give, 'give_score' => $giveScore];
    }

    /**
     * givedata JSON 解析（容错：非法 JSON 返回空数组）
     *
     * @return array<int, array<string, mixed>>
     */
    protected function decodeGivedata(string $raw): array
    {
        $raw = trim($raw);

        if ('' === $raw) {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return [];
        }

        $rows = [];

        foreach ($decoded as $row) {
            $rows[] = [
                'money'      => round((float) ($row['money'] ?? 0), 2),
                'give'       => round((float) ($row['give'] ?? 0), 2),
                'give_score' => (int) ($row['give_score'] ?? 0),
            ];
        }

        return $rows;
    }
}
