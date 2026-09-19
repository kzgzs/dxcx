<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;

/**
 * 财务中心服务（admin 租户后台侧，任务144）
 *
 * 表口径：
 * - payorder                  统一支付单（status 0未支付 1已支付 2已取消；type 业务类型）
 * - member_moneylog           余额流水（money 带符号 / after 变动后余额）
 * - recharge_order            充值记录（status 0未支付 1已支付 2已退款）
 * - member_withdrawlog        余额提现（status 0待审核 1待打款 2已驳回 3已打款 4转账中）
 * - member_commissionlog      佣金流水（commission 带符号 / after 变动后佣金）
 * - member_commission_withdrawlog 佣金提现（状态机同余额提现）
 * - hexiao_order              核销记录（mid varchar / mdid 门店）
 * - invoice                   发票申请（status 0待审核 1通过 2驳回）
 * - wxpay_log                 微信支付流水
 * - wx_transfer_log           微信转账记录（打款落账）
 *
 * 金额/佣金变动一律事务 + 行锁，流水 after 值与主表余额同事务落库；
 * 提现口径沿用参考系统：txmoney=申请扣减额、money=手续费后实付额，驳回返还 txmoney。
 */
class FinanceService
{
    /** paytypeid → 支付方式名 */
    public const PAYTYPE_TEXT = [
        1 => '余额支付', 2 => '微信支付', 3 => '支付宝支付',
        4 => '货到付款', 5 => '转账汇款', 11 => '百度小程序', 12 => '头条小程序',
    ];

    /** payorder.type → 业务类型名 */
    public const PAYORDER_TYPE_TEXT = [
        'shop' => '商城订单', 'collage' => '拼团订单', 'scoreshop' => '积分兑换',
        'kanjia' => '砍价订单', 'seckill' => '秒杀订单', 'recharge' => '充值',
        'maidan' => '买单', 'designerpage' => '页面设计', 'form' => '表单订单',
        'tuangou' => '团购订单', 'yuyue' => '预约订单', 'hotel' => '酒店订单',
        'carhailing' => '网约车订单', 'restaurant' => '餐饮订单',
    ];

    /** 提现状态 0待审核 1已审待打款 2已驳回 3已打款 4转账中 */
    public const WITHDRAW_STATUS_TEXT = [
        0 => '待审核', 1 => '待打款', 2 => '已驳回', 3 => '已打款', 4 => '转账中',
    ];

    /** 核销订单类型名 */
    public const HEXIAO_TYPE_TEXT = [
        'shop' => '商城订单', 'yuyue' => '预约订单', 'hotel' => '酒店订单',
        'carhailing' => '网约车订单', 'restaurant' => '餐饮订单', 'lipin' => '礼品卡',
        'kecheng' => '课程订单', 'collage' => '拼团订单', 'seckill' => '秒杀订单',
        'kanjia' => '砍价订单', 'scoreshop' => '积分兑换', 'tuangou' => '团购订单',
    ];

    /* ================= 1. 支付单列表（消费明细） ================= */

    /**
     * 支付单列表 + 顶部统计
     * 筛选：status（''=全部）/paytypeid/昵称/mid/手机/订单号/时间（已支付按 paytime，其余按 createtime）
     * 统计（按 paytime 口径，status=1）：总收款 / 总退款 / 总消费
     *
     * @param array<string, mixed> $f
     * @return array{list: array, total: int, page: int, pagesize: int, stat: array}
     */
    public function payorderList(int $aid, array $f): array
    {
        $status    = trim((string) ($f['status'] ?? ''));
        $paytypeid = (int) ($f['paytypeid'] ?? 0);
        $nickname  = trim((string) ($f['nickname'] ?? ''));
        $mid       = (int) ($f['mid'] ?? 0);
        $tel       = trim((string) ($f['tel'] ?? ''));
        $ordernum  = trim((string) ($f['ordernum'] ?? ''));
        $page      = max(1, (int) ($f['page'] ?? 1));
        $pageSize  = $this->pageSize($f);
        $range     = $this->timeRange((string) ($f['start_date'] ?? ''), (string) ($f['end_date'] ?? ''));

        $timeField = '1' === $status ? 'p.paytime' : 'p.createtime';

        $query = ScopeService::queryAs('payorder', $aid, 'p')
            ->leftJoin('member m', 'm.id = p.mid');

        if ('' !== $status) {
            $query->where('p.status', (int) $status);
        }

        if ($paytypeid > 0) {
            $query->where('p.paytypeid', $paytypeid);
        }
        if ('' !== $nickname) {
            $query->where('m.nickname', 'like', '%' . $nickname . '%');
        }
        if ($mid > 0) {
            $query->where('p.mid', $mid);
        }
        if ('' !== $tel) {
            $query->where('m.tel', 'like', '%' . $tel . '%');
        }
        if ('' !== $ordernum) {
            $query->where('p.ordernum', 'like', '%' . $ordernum . '%');
        }
        if (null !== $range) {
            // 已支付视角按支付时间，其余按下单时间
            $query->whereBetween($timeField, $range);
        }

        $total = (clone $query)->count();

        $rows = $query->field('p.id, p.ordernum, p.title, p.money, p.paytype, p.paytypeid, p.paynum, p.paytime, p.type, p.status, p.refund_money, p.createtime, p.mid, m.nickname, m.tel')
            ->order('p.id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        $list = array_map(fn (array $row) => [
            'id'              => (int) $row['id'],
            'ordernum'        => (string) ($row['ordernum'] ?? ''),
            'title'           => (string) ($row['title'] ?? ''),
            'money'           => (float) ($row['money'] ?? 0),
            'paytype'         => (string) ($row['paytype'] ?? ''),
            'paytypeid'       => (int) ($row['paytypeid'] ?? 0),
            'paytype_text'    => (string) (self::PAYTYPE_TEXT[(int) ($row['paytypeid'] ?? 0)] ?? ($row['paytype'] ?: '-')),
            'paynum'          => (string) ($row['paynum'] ?? ''),
            'paytime'         => (int) ($row['paytime'] ?? 0),
            'type'            => (string) ($row['type'] ?? ''),
            'type_text'       => (string) (self::PAYORDER_TYPE_TEXT[(string) ($row['type'] ?? '')] ?? ($row['type'] ?: '-')),
            'status'          => (int) ($row['status'] ?? 0),
            'refund_money'    => (float) ($row['refund_money'] ?? 0),
            'createtime'      => (int) ($row['createtime'] ?? 0),
            'mid'             => (int) ($row['mid'] ?? 0),
            'nickname'        => (string) ($row['nickname'] ?? ''),
            'tel'             => (string) ($row['tel'] ?? ''),
        ], $rows);

        // 顶部统计（已支付口径，按 paytime 时间范围）
        $statQuery = ScopeService::query('payorder', $aid)
            ->where('status', 1)
            ->where('paytime', '>', 0);
        if (null !== $range) {
            $statQuery->whereBetween('paytime', $range);
        }
        $stat = $statQuery->field('ifnull(sum(money),0) as received, ifnull(sum(refund_money),0) as refund')->find();

        $received = (float) ($stat['received'] ?? 0);
        $refund   = (float) ($stat['refund'] ?? 0);

        return [
            'list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize,
            'stat' => [
                'total_received' => round($received, 2),
                'total_refund'   => round($refund, 2),
                'total_cost'     => round($received - $refund, 2),
            ],
        ];
    }

    /* ================= 2. 余额明细 ================= */

    /**
     * 余额流水列表 + 收支汇总
     * 筛选：昵称/mid/收支（1收入 2支出）/时间
     *
     * @param array<string, mixed> $f
     * @return array{list: array, total: int, page: int, pagesize: int, stat: array}
     */
    public function moneylogList(int $aid, array $f): array
    {
        $nickname = trim((string) ($f['nickname'] ?? ''));
        $mid      = (int) ($f['mid'] ?? 0);
        $inout    = (int) ($f['inout'] ?? 0);
        $page     = max(1, (int) ($f['page'] ?? 1));
        $pageSize = $this->pageSize($f);
        $range    = $this->timeRange((string) ($f['start_date'] ?? ''), (string) ($f['end_date'] ?? ''));

        $query = ScopeService::queryAs('member_moneylog', $aid, 'l')
            ->leftJoin('member m', 'm.id = l.mid');

        if ('' !== $nickname) {
            $query->where('m.nickname', 'like', '%' . $nickname . '%');
        }
        if ($mid > 0) {
            $query->where('l.mid', $mid);
        }
        if (1 === $inout) {
            $query->where('l.money', '>', 0);
        } elseif (2 === $inout) {
            $query->where('l.money', '<', 0);
        }
        if (null !== $range) {
            $query->whereBetween('l.createtime', $range);
        }

        $total = (clone $query)->count();

        $rows = $query->field('l.id, l.mid, l.money, l.after, l.remark, l.paytype, l.uid, l.createtime, m.nickname, m.tel')
            ->order('l.id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        $list = array_map(static fn (array $row) => [
            'id'         => (int) $row['id'],
            'mid'        => (int) ($row['mid'] ?? 0),
            'nickname'   => (string) ($row['nickname'] ?? ''),
            'tel'        => (string) ($row['tel'] ?? ''),
            'money'      => (float) ($row['money'] ?? 0),
            'after'      => (float) ($row['after'] ?? 0),
            'remark'     => (string) ($row['remark'] ?? ''),
            'paytype'    => (string) ($row['paytype'] ?? ''),
            'uid'        => (int) ($row['uid'] ?? 0),
            'createtime' => (int) ($row['createtime'] ?? 0),
        ], $rows);

        $statQuery = ScopeService::query('member_moneylog', $aid);
        if (null !== $range) {
            $statQuery->whereBetween('createtime', $range);
        }
        $stat = $statQuery->field(
            'ifnull(sum(case when money > 0 then money else 0 end),0) as income, '
            . 'ifnull(sum(case when money < 0 then -money else 0 end),0) as expense'
        )->find();

        $income  = (float) ($stat['income'] ?? 0);
        $expense = (float) ($stat['expense'] ?? 0);

        return [
            'list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize,
            'stat' => [
                'income'  => round($income, 2),
                'expense' => round($expense, 2),
                'net'     => round($income - $expense, 2),
            ],
        ];
    }

    /* ================= 3. 充值记录 ================= */

    /**
     * 充值记录列表 + 统计（status 0未支付 1已支付 2已退款）
     *
     * @param array<string, mixed> $f
     * @return array{list: array, total: int, page: int, pagesize: int, stat: array}
     */
    public function rechargeList(int $aid, array $f): array
    {
        $nickname = trim((string) ($f['nickname'] ?? ''));
        $mid      = (int) ($f['mid'] ?? 0);
        $status   = trim((string) ($f['status'] ?? ''));
        $ordernum = trim((string) ($f['ordernum'] ?? ''));
        $page     = max(1, (int) ($f['page'] ?? 1));
        $pageSize = $this->pageSize($f);
        $range    = $this->timeRange((string) ($f['start_date'] ?? ''), (string) ($f['end_date'] ?? ''));

        $query = ScopeService::queryAs('recharge_order', $aid, 'r')
            ->leftJoin('member m', 'm.id = r.mid');

        if ('' !== $nickname) {
            $query->where('m.nickname', 'like', '%' . $nickname . '%');
        }
        if ($mid > 0) {
            $query->where('r.mid', $mid);
        }
        if ('' !== $status) {
            $query->where('r.status', (int) $status);
        }
        if ('' !== $ordernum) {
            $query->where('r.ordernum', 'like', '%' . $ordernum . '%');
        }
        if (null !== $range) {
            $query->whereBetween('r.createtime', $range);
        }

        $total = (clone $query)->count();

        $rows = $query->field('r.id, r.ordernum, r.money, r.status, r.paytype, r.paytypeid, r.paynum, r.paytime, r.createtime, r.mid, m.nickname, m.tel')
            ->order('r.id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        $list = array_map(fn (array $row) => [
            'id'           => (int) $row['id'],
            'ordernum'     => (string) ($row['ordernum'] ?? ''),
            'money'        => (float) ($row['money'] ?? 0),
            'status'       => (int) ($row['status'] ?? 0),
            'paytype'      => (string) ($row['paytype'] ?? ''),
            'paytypeid'    => (int) ($row['paytypeid'] ?? 0),
            'paytype_text' => (string) (self::PAYTYPE_TEXT[(int) ($row['paytypeid'] ?? 0)] ?? ($row['paytype'] ?: '-')),
            'paynum'       => (string) ($row['paynum'] ?? ''),
            'paytime'      => (int) ($row['paytime'] ?? 0),
            'createtime'   => (int) ($row['createtime'] ?? 0),
            'mid'          => (int) ($row['mid'] ?? 0),
            'nickname'     => (string) ($row['nickname'] ?? ''),
            'tel'          => (string) ($row['tel'] ?? ''),
        ], $rows);

        $stat = ScopeService::query('recharge_order', $aid)
            ->field(
                'ifnull(sum(case when status = 1 then money else 0 end),0) as paid_total, '
                . 'sum(case when status = 1 then 1 else 0 end) as paid_count, '
                . 'sum(case when status = 2 then 1 else 0 end) as refund_count'
            )
            ->find();

        return [
            'list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize,
            'stat' => [
                'paid_total'   => round((float) ($stat['paid_total'] ?? 0), 2),
                'paid_count'   => (int) ($stat['paid_count'] ?? 0),
                'refund_count' => (int) ($stat['refund_count'] ?? 0),
            ],
        ];
    }

    /**
     * 充值退款（事务：recharge_order.status=1→2 + member.money 扣回 + 余额流水）
     *
     * @return array{money: float} 变动后余额
     */
    public function rechargeRefund(int $aid, int $id, int $uid): array
    {
        if ($id <= 0) {
            throw new BusinessException('缺少充值单ID');
        }

        $after = 0.0;

        Db::transaction(function () use ($aid, $id, $uid, &$after) {
            $order = Db::name('recharge_order')
                ->where('id', $id)
                ->where('aid', $aid)
                ->lock(true)
                ->find();

            if (empty($order)) {
                throw new BusinessException('充值单不存在', 404, 404);
            }
            if (1 !== (int) $order['status']) {
                throw new BusinessException('仅已支付的充值单可退款');
            }

            $money = round((float) $order['money'], 2);
            $mid   = (int) $order['mid'];

            $member = Db::name('member')->where('id', $mid)->where('aid', $aid)->lock(true)->find();
            if (empty($member)) {
                throw new BusinessException('会员不存在', 404, 404);
            }

            $after = round((float) ($member['money'] ?? 0) - $money, 2);
            if ($after < 0) {
                throw new BusinessException('会员余额不足，无法扣回充值金额');
            }

            Db::name('member')->where('id', $mid)->where('aid', $aid)->update(['money' => $after]);

            Db::name('member_moneylog')->insert([
                'aid'        => $aid,
                'mid'        => $mid,
                'money'      => -$money,
                'after'      => $after,
                'createtime' => time(),
                'remark'     => '充值退款 订单' . (string) ($order['ordernum'] ?? ''),
                'paytype'    => 'admin',
                'rechargeid' => $id,
                'uid'        => $uid,
            ]);

            Db::name('recharge_order')->where('id', $id)->where('aid', $aid)->where('status', 1)
                ->update(['status' => 2]);
        });

        return ['money' => $after];
    }

    /* ================= 4. 余额提现 ================= */

    /**
     * 提现申请列表 + 状态统计
     * 状态机：0待审核 → 1已审待打款 → 3已打款（4转账中）；0 → 2已驳回（返还 txmoney）
     *
     * @param array<string, mixed> $f
     * @return array{list: array, total: int, page: int, pagesize: int, stat: array}
     */
    public function withdrawList(int $aid, array $f, string $table = 'member_withdrawlog'): array
    {
        $nickname = trim((string) ($f['nickname'] ?? ''));
        $mid      = (int) ($f['mid'] ?? 0);
        $status   = trim((string) ($f['status'] ?? ''));
        $paytype  = trim((string) ($f['paytype'] ?? ''));
        $page     = max(1, (int) ($f['page'] ?? 1));
        $pageSize = $this->pageSize($f);
        $range    = $this->timeRange((string) ($f['start_date'] ?? ''), (string) ($f['end_date'] ?? ''));

        $query = ScopeService::queryAs($table, $aid, 'w')
            ->leftJoin('member m', 'm.id = w.mid');

        if ('' !== $nickname) {
            $query->where('m.nickname', 'like', '%' . $nickname . '%');
        }
        if ($mid > 0) {
            $query->where('w.mid', $mid);
        }
        if ('' !== $status) {
            $query->where('w.status', (int) $status);
        }
        if ('' !== $paytype) {
            $query->where('w.paytype', $paytype);
        }
        if (null !== $range) {
            $query->whereBetween('w.createtime', $range);
        }

        $total = (clone $query)->count();

        $rows = $query->field('w.*, m.nickname, m.tel')
            ->order('w.id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        $list = array_map(fn (array $row) => [
            'id'           => (int) $row['id'],
            'mid'          => (int) ($row['mid'] ?? 0),
            'nickname'     => (string) ($row['nickname'] ?? ''),
            'tel'          => (string) ($row['tel'] ?? ''),
            'ordernum'     => (string) ($row['ordernum'] ?? ''),
            'money'        => (float) ($row['money'] ?? 0),
            'txmoney'      => (float) ($row['txmoney'] ?? 0),
            'paytype'      => (string) ($row['paytype'] ?? ''),
            'aliaccount'     => (string) ($row['aliaccount'] ?? ''),
            'aliaccountname' => (string) ($row['aliaccountname'] ?? ''),
            'bankname'     => (string) ($row['bankname'] ?? ''),
            'bankcarduser' => (string) ($row['bankcarduser'] ?? ''),
            'bankcardnum'  => (string) ($row['bankcardnum'] ?? ''),
            'status'       => (int) ($row['status'] ?? 0),
            'status_text'  => (string) (self::WITHDRAW_STATUS_TEXT[(int) ($row['status'] ?? 0)] ?? '未知'),
            'reason'       => (string) ($row['reason'] ?? ''),
            'createtime'   => (int) ($row['createtime'] ?? 0),
            'paytime'      => (int) ($row['paytime'] ?? 0),
            'paynum'       => (string) ($row['paynum'] ?? ''),
        ], $rows);

        $statRows = ScopeService::query($table, $aid)
            ->field('status, count(*) as cnt, ifnull(sum(txmoney),0) as amount')
            ->group('status')
            ->select()
            ->toArray();

        $stat = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'paid' => 0, 'pending_amount' => 0.0];
        foreach ($statRows as $s) {
            $st  = (int) $s['status'];
            $cnt = (int) $s['cnt'];
            if (0 === $st) {
                $stat['pending']        = $cnt;
                $stat['pending_amount'] = round((float) $s['amount'], 2);
            } elseif (1 === $st || 4 === $st) {
                $stat['approved'] += $cnt;
            } elseif (2 === $st) {
                $stat['rejected'] += $cnt;
            } elseif (3 === $st) {
                $stat['paid'] += $cnt;
            }
        }

        return ['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize, 'stat' => $stat];
    }

    /**
     * 提现审核（事务）
     * pass=1 通过：status 0→1（待打款）
     * pass=2 驳回：status 0→2 + 返还 txmoney 至余额/佣金 + 流水
     */
    public function withdrawAudit(int $aid, int $id, int $pass, string $reason, int $uid, string $table = 'member_withdrawlog'): void
    {
        if ($id <= 0) {
            throw new BusinessException('缺少提现单ID');
        }
        if (1 !== $pass && 2 !== $pass) {
            throw new BusinessException('审核结果无效');
        }

        $isCommission = 'member_commission_withdrawlog' === $table;

        Db::transaction(function () use ($aid, $id, $pass, $reason, $uid, $table, $isCommission) {
            $row = Db::name($table)->where('id', $id)->where('aid', $aid)->lock(true)->find();

            if (empty($row)) {
                throw new BusinessException('提现单不存在', 404, 404);
            }
            if (0 !== (int) $row['status']) {
                throw new BusinessException('该提现单已审核，请勿重复操作');
            }

            if (1 === $pass) {
                Db::name($table)->where('id', $id)->update(['status' => 1]);
                return;
            }

            // 驳回：返还申请扣减额（txmoney）
            $mid     = (int) $row['mid'];
            $txmoney = round((float) $row['txmoney'], 2);

            $member = Db::name('member')->where('id', $mid)->where('aid', $aid)->lock(true)->find();
            if (empty($member)) {
                throw new BusinessException('会员不存在', 404, 404);
            }

            if ($isCommission) {
                $after = round((float) ($member['commission'] ?? 0) + $txmoney, 2);
                Db::name('member')->where('id', $mid)->where('aid', $aid)->update(['commission' => $after]);

                Db::name('member_commissionlog')->insert([
                    'aid'        => $aid,
                    'mid'        => $mid,
                    'frommid'    => 0,
                    'commission' => $txmoney,
                    'after'      => $after,
                    'createtime' => time(),
                    'remark'     => '佣金提现驳回返还',
                    'fhtype'     => 'withdraw_back',
                    'uid'        => $uid,
                ]);
            } else {
                $after = round((float) ($member['money'] ?? 0) + $txmoney, 2);
                Db::name('member')->where('id', $mid)->where('aid', $aid)->update(['money' => $after]);

                Db::name('member_moneylog')->insert([
                    'aid'        => $aid,
                    'mid'        => $mid,
                    'money'      => $txmoney,
                    'after'      => $after,
                    'createtime' => time(),
                    'remark'     => '余额提现驳回返还',
                    'paytype'    => 'admin',
                    'uid'        => $uid,
                ]);
            }

            Db::name($table)->where('id', $id)->where('status', 0)
                ->update(['status' => 2, 'reason' => $reason]);
        });
    }

    /**
     * 提现打款（状态机：仅 1待打款 → 3已打款；4转账中拒绝重复打款）
     * mock 模式即时成功并落 wx_transfer_log；真实模式预留 V3 转账桩
     *
     * @return array{mock: bool, status: int}
     */
    public function withdrawPay(int $aid, int $id, int $uid, string $table = 'member_withdrawlog'): array
    {
        if ($id <= 0) {
            throw new BusinessException('缺少提现单ID');
        }

        $row = Db::name($table)->where('id', $id)->where('aid', $aid)->find();

        if (empty($row)) {
            throw new BusinessException('提现单不存在', 404, 404);
        }

        $status = (int) $row['status'];
        if (4 === $status) {
            throw new BusinessException('转账处理中，请勿重复打款');
        }
        if (3 === $status) {
            throw new BusinessException('该笔提现已打款');
        }
        if (1 !== $status) {
            throw new BusinessException('仅已审核待打款的提现单可打款');
        }

        $isCommission = 'member_commission_withdrawlog' === $table;
        $payNum       = 'MOCK' . time() . random_int(1000, 9999);

        if (!PayService::isMock()) {
            // 真实模式：微信 V3 商家转账桩（TODO）
            // 流程：置 status=4 转账中 → POST /v3/transfer/batches 发起转账 →
            //       回调/查单确认 SUCCESS 后置 status=3 + 落 wx_transfer_log
            throw new BusinessException('真实微信 V3 转账接入开发中（TODO）', 501, 501);
        }

        Db::transaction(function () use ($aid, $id, $uid, $row, $table, $isCommission, $payNum) {
            Db::name($table)->where('id', $id)->where('aid', $aid)->where('status', 1)
                ->update(['status' => 3, 'paytime' => time(), 'paynum' => $payNum]);

            // 打款落微信转账记录
            Db::name('wx_transfer_log')->insert([
                'aid'                => $aid,
                'mid'                => (int) $row['mid'],
                'ordernum'           => (string) ($row['ordernum'] ?? ''),
                'money'              => (float) ($row['txmoney'] ?? 0),
                'realname'           => $isCommission ? (string) ($row['aliaccountname'] ?? '') : (string) ($row['aliaccountname'] ?? ($row['bankcarduser'] ?? '')),
                'desc'               => $isCommission ? '佣金提现打款' : '余额提现打款',
                'data_tbl'           => $table,
                'data_id'            => $id,
                'status_code'        => 'SUCCESS',
                'createtime'         => time(),
                'state'              => 'SUCCESS',
                'transfer_bill_no'   => $payNum,
                'message'            => '',
            ]);
        });

        return ['mock' => true, 'status' => 3];
    }

    /* ================= 5. 佣金明细 / 佣金提现 ================= */

    /**
     * 佣金明细列表 + 收支统计
     *
     * @param array<string, mixed> $f
     * @return array{list: array, total: int, page: int, pagesize: int, stat: array}
     */
    public function commissionList(int $aid, array $f): array
    {
        $nickname = trim((string) ($f['nickname'] ?? ''));
        $mid      = (int) ($f['mid'] ?? 0);
        $fhtype   = trim((string) ($f['fhtype'] ?? ''));
        $page     = max(1, (int) ($f['page'] ?? 1));
        $pageSize = $this->pageSize($f);
        $range    = $this->timeRange((string) ($f['start_date'] ?? ''), (string) ($f['end_date'] ?? ''));

        $query = ScopeService::queryAs('member_commissionlog', $aid, 'c')
            ->leftJoin('member m', 'm.id = c.mid');

        if ('' !== $nickname) {
            $query->where('m.nickname', 'like', '%' . $nickname . '%');
        }
        if ($mid > 0) {
            $query->where('c.mid', $mid);
        }
        if ('' !== $fhtype) {
            $query->where('c.fhtype', $fhtype);
        }
        if (null !== $range) {
            $query->whereBetween('c.createtime', $range);
        }

        $total = (clone $query)->count();

        $rows = $query->field('c.id, c.mid, c.frommid, c.commission, c.after, c.fhtype, c.remark, c.createtime, m.nickname, m.tel, f.nickname as from_nickname')
            ->leftJoin('member f', 'f.id = c.frommid')
            ->order('c.id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        $list = array_map(static fn (array $row) => [
            'id'           => (int) $row['id'],
            'mid'          => (int) ($row['mid'] ?? 0),
            'nickname'     => (string) ($row['nickname'] ?? ''),
            'tel'          => (string) ($row['tel'] ?? ''),
            'commission'   => (float) ($row['commission'] ?? 0),
            'after'        => (float) ($row['after'] ?? 0),
            'fhtype'       => (string) ($row['fhtype'] ?? ''),
            'frommid'      => (int) ($row['frommid'] ?? 0),
            'from_nickname' => (string) ($row['from_nickname'] ?? ''),
            'remark'       => (string) ($row['remark'] ?? ''),
            'createtime'   => (int) ($row['createtime'] ?? 0),
        ], $rows);

        $statQuery = ScopeService::query('member_commissionlog', $aid);
        if (null !== $range) {
            $statQuery->whereBetween('createtime', $range);
        }
        $stat = $statQuery->field(
            'ifnull(sum(case when commission > 0 then commission else 0 end),0) as income, '
            . 'ifnull(sum(case when commission < 0 then -commission else 0 end),0) as expense'
        )->find();

        $income  = (float) ($stat['income'] ?? 0);
        $expense = (float) ($stat['expense'] ?? 0);

        return [
            'list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize,
            'stat' => [
                'income'  => round($income, 2),
                'expense' => round($expense, 2),
                'net'     => round($income - $expense, 2),
            ],
        ];
    }

    /* ================= 6. 核销记录 ================= */

    /**
     * 核销记录（只读）：hexiao_order + 会员 + 门店
     *
     * @param array<string, mixed> $f
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function hexiaoList(int $aid, array $f): array
    {
        $keyword = trim((string) ($f['keyword'] ?? ''));
        $type    = trim((string) ($f['type'] ?? ''));
        $page    = max(1, (int) ($f['page'] ?? 1));
        $pageSize = $this->pageSize($f);
        $range   = $this->timeRange((string) ($f['start_date'] ?? ''), (string) ($f['end_date'] ?? ''));

        $query = ScopeService::queryAs('hexiao_order', $aid, 'h')
            ->leftJoin('member m', 'm.id = h.mid')
            ->leftJoin('mendian md', 'md.id = h.mdid');

        if ('' !== $keyword) {
            $like = '%' . $keyword . '%';
            $query->where(function ($q) use ($like) {
                $q->whereOr('h.ordernum', 'like', $like)
                    ->whereOr('h.title', 'like', $like)
                    ->whereOr('m.nickname', 'like', $like);
            });
        }
        if ('' !== $type) {
            $query->where('h.type', $type);
        }
        if (null !== $range) {
            $query->whereBetween('h.createtime', $range);
        }

        $total = (clone $query)->count();

        $rows = $query->field('h.id, h.ordernum, h.title, h.type, h.mid, h.uid, h.mdid, h.remark, h.createtime, m.nickname, m.tel, md.name as mendian_name')
            ->order('h.id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        $list = array_map(fn (array $row) => [
            'id'           => (int) $row['id'],
            'ordernum'     => (string) ($row['ordernum'] ?? ''),
            'title'        => (string) ($row['title'] ?? ''),
            'type'         => (string) ($row['type'] ?? ''),
            'type_text'    => (string) (self::HEXIAO_TYPE_TEXT[(string) ($row['type'] ?? '')] ?? ($row['type'] ?: '-')),
            'mid'          => (string) ($row['mid'] ?? ''),
            'nickname'     => (string) ($row['nickname'] ?? ''),
            'tel'          => (string) ($row['tel'] ?? ''),
            'uid'          => (int) ($row['uid'] ?? 0),
            'mdid'         => (int) ($row['mdid'] ?? 0),
            'mendian_name' => (string) ($row['mendian_name'] ?? ''),
            'remark'       => (string) ($row['remark'] ?? ''),
            'createtime'   => (int) ($row['createtime'] ?? 0),
        ], $rows);

        return ['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize];
    }

    /* ================= 7. 发票管理 ================= */

    /**
     * 发票列表 + 状态统计（status 0待审核 1通过 2驳回）
     *
     * @param array<string, mixed> $f
     * @return array{list: array, total: int, page: int, pagesize: int, stat: array}
     */
    public function invoiceList(int $aid, array $f): array
    {
        $status  = trim((string) ($f['status'] ?? ''));
        $keyword = trim((string) ($f['keyword'] ?? ''));
        $page    = max(1, (int) ($f['page'] ?? 1));
        $pageSize = $this->pageSize($f);
        $range   = $this->timeRange((string) ($f['start_date'] ?? ''), (string) ($f['end_date'] ?? ''));

        $query = ScopeService::queryAs('invoice', $aid, 'i')
            ->leftJoin('shop_order o', 'o.id = i.orderid');

        if ('' !== $status) {
            $query->where('i.status', (int) $status);
        }
        if ('' !== $keyword) {
            $like = '%' . $keyword . '%';
            $query->where(function ($q) use ($like) {
                $q->whereOr('i.invoice_name', 'like', $like)
                    ->whereOr('i.ordernum', 'like', $like)
                    ->whereOr('i.tax_no', 'like', $like);
            });
        }
        if (null !== $range) {
            $query->whereBetween('i.create_time', $range);
        }

        $total = (clone $query)->count();

        $rows = $query->field('i.id, i.ordernum, i.order_type, i.type, i.invoice_name, i.name_type, i.tax_no, i.address, i.tel, i.mobile, i.email, i.status, i.check_remark, i.create_time, i.check_time, o.totalprice as pay_money')
            ->order('i.id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        $list = array_map(static fn (array $row) => [
            'id'           => (int) $row['id'],
            'ordernum'     => (string) ($row['ordernum'] ?? ''),
            'order_type'   => (string) ($row['order_type'] ?? ''),
            'type'         => (int) ($row['type'] ?? 1),
            'type_text'    => 2 === (int) ($row['type'] ?? 1) ? '专票' : '普票',
            'invoice_name' => (string) ($row['invoice_name'] ?? ''),
            'name_type'    => (int) ($row['name_type'] ?? 1),
            'tax_no'       => (string) ($row['tax_no'] ?? ''),
            'address'      => (string) ($row['address'] ?? ''),
            'tel'          => (string) ($row['tel'] ?? ''),
            'mobile'       => (string) ($row['mobile'] ?? ''),
            'email'        => (string) ($row['email'] ?? ''),
            'status'       => (int) ($row['status'] ?? 0),
            'check_remark' => (string) ($row['check_remark'] ?? ''),
            'create_time'  => (int) ($row['create_time'] ?? 0),
            'check_time'   => (int) ($row['check_time'] ?? 0),
            'pay_money'    => (float) ($row['pay_money'] ?? 0),
        ], $rows);

        $statRows = ScopeService::query('invoice', $aid)
            ->field('status, count(*) as cnt')
            ->group('status')
            ->select()
            ->toArray();

        $stat = ['pending' => 0, 'passed' => 0, 'rejected' => 0];
        foreach ($statRows as $s) {
            if (0 === (int) $s['status']) {
                $stat['pending'] = (int) $s['cnt'];
            } elseif (1 === (int) $s['status']) {
                $stat['passed'] = (int) $s['cnt'];
            } elseif (2 === (int) $s['status']) {
                $stat['rejected'] = (int) $s['cnt'];
            }
        }

        return ['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize, 'stat' => $stat];
    }

    /**
     * 发票批量审核（ids 数组；pass 1通过 2驳回；同写 check_remark/check_time）
     *
     * @param array<int> $ids
     * @return array{count: int}
     */
    public function invoiceAudit(int $aid, array $ids, int $pass, string $remark): array
    {
        if (empty($ids)) {
            throw new BusinessException('请选择发票');
        }
        if (1 !== $pass && 2 !== $pass) {
            throw new BusinessException('审核结果无效');
        }

        $count = Db::name('invoice')
            ->where('aid', $aid)
            ->whereIn('id', $ids)
            ->update([
                'status'       => $pass,
                'check_remark' => $remark,
                'check_time'   => time(),
            ]);

        return ['count' => (int) $count];
    }

    /**
     * 发票回复（写审核备注 + 回复时间）
     */
    public function invoiceReply(int $aid, int $id, string $remark): void
    {
        if ($id <= 0) {
            throw new BusinessException('缺少发票ID');
        }
        if ('' === trim($remark)) {
            throw new BusinessException('回复内容不能为空');
        }

        $exists = ScopeService::query('invoice', $aid)->where('id', $id)->find();
        if (empty($exists)) {
            throw new BusinessException('发票不存在', 404, 404);
        }

        Db::name('invoice')->where('id', $id)->where('aid', $aid)->update([
            'check_remark' => trim($remark),
            'check_time'   => time(),
        ]);
    }

    /* ================= 8. 微信支付流水 ================= */

    /**
     * 微信支付流水（wxpay_log，只读）
     *
     * @param array<string, mixed> $f
     * @return array{list: array, total: int, page: int, pagesize: int, stat: array}
     */
    public function wxpaylogList(int $aid, array $f): array
    {
        $ordernum      = trim((string) ($f['ordernum'] ?? ''));
        $transactionId = trim((string) ($f['transaction_id'] ?? ''));
        $isfenzhang    = trim((string) ($f['isfenzhang'] ?? ''));
        $page          = max(1, (int) ($f['page'] ?? 1));
        $pageSize      = $this->pageSize($f);
        $range         = $this->timeRange((string) ($f['start_date'] ?? ''), (string) ($f['end_date'] ?? ''));

        $query = ScopeService::queryAs('wxpay_log', $aid, 'w')
            ->leftJoin('member m', 'm.id = w.mid');

        if ('' !== $ordernum) {
            $query->where('w.ordernum', 'like', '%' . $ordernum . '%');
        }
        if ('' !== $transactionId) {
            $query->where('w.transaction_id', 'like', '%' . $transactionId . '%');
        }
        if ('' !== $isfenzhang) {
            $query->where('w.isfenzhang', (int) $isfenzhang);
        }
        if (null !== $range) {
            $query->whereBetween('w.createtime', $range);
        }

        $total = (clone $query)->count();

        $rows = $query->field('w.id, w.ordernum, w.mch_id, w.sub_mchid, w.transaction_id, w.total_fee, w.fenzhangmoney, w.isfenzhang, w.fz_ordernum, w.fz_errmsg, w.refund_money, w.createtime, w.mid, m.nickname')
            ->order('w.id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        $list = array_map(static fn (array $row) => [
            'id'             => (int) $row['id'],
            'ordernum'       => (string) ($row['ordernum'] ?? ''),
            'mch_id'         => (string) ($row['mch_id'] ?? ''),
            'sub_mchid'      => (string) ($row['sub_mchid'] ?? ''),
            'transaction_id' => (string) ($row['transaction_id'] ?? ''),
            'total_fee'      => (float) ($row['total_fee'] ?? 0),
            'fenzhangmoney'  => (float) ($row['fenzhangmoney'] ?? 0),
            'isfenzhang'     => (int) ($row['isfenzhang'] ?? 0),
            'fz_ordernum'    => (string) ($row['fz_ordernum'] ?? ''),
            'fz_errmsg'      => (string) ($row['fz_errmsg'] ?? ''),
            'refund_money'   => (float) ($row['refund_money'] ?? 0),
            'createtime'     => (int) ($row['createtime'] ?? 0),
            'mid'            => (int) ($row['mid'] ?? 0),
            'nickname'       => (string) ($row['nickname'] ?? ''),
        ], $rows);

        $stat = ScopeService::query('wxpay_log', $aid)
            ->field('count(*) as cnt, ifnull(sum(total_fee),0) as total')
            ->find();

        return [
            'list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize,
            'stat' => [
                'count' => (int) ($stat['cnt'] ?? 0),
                'total' => round((float) ($stat['total'] ?? 0), 2),
            ],
        ];
    }

    /* ================= 9. 佣金记录（member_commission_record，任务177） ================= */

    /** 佣金记录状态 0未发放 1已发放 2已退回 */
    public const RECORD_STATUS_TEXT = [0 => '未发放', 1 => '已发放', 2 => '已退回'];

    /**
     * 佣金记录列表 + 状态统计（member_commission_record，区别于佣金明细 member_commissionlog）
     * 筛选：昵称/mid/状态/时间/订单ID（+业务类型）；按 type 回查对应订单表取订单状态与单号
     *
     * @param array<string, mixed> $f
     * @return array{list: array, total: int, page: int, pagesize: int, stat: array}
     */
    public function commissionRecordList(int $aid, array $f): array
    {
        $nickname = trim((string) ($f['nickname'] ?? ''));
        $mid      = (int) ($f['mid'] ?? 0);
        $status   = trim((string) ($f['status'] ?? ''));
        $orderid  = trim((string) ($f['orderid'] ?? ''));
        $type     = trim((string) ($f['type'] ?? ''));
        $page     = max(1, (int) ($f['page'] ?? 1));
        $pageSize = $this->pageSize($f);
        $range    = $this->timeRange((string) ($f['start_date'] ?? ''), (string) ($f['end_date'] ?? ''));

        $query = ScopeService::queryAs('member_commission_record', $aid, 'r')
            ->leftJoin('member m', 'm.id = r.mid')
            ->where('r.status', 'in', [0, 1, 2]);

        if ('' !== $nickname) {
            $query->where('m.nickname', 'like', '%' . $nickname . '%');
        }
        if ($mid > 0) {
            $query->where('r.mid', $mid);
        }
        if ('' !== $status) {
            $query->where('r.status', (int) $status);
        }
        if ('' !== $orderid) {
            if ('' !== $type) {
                $query->where('r.type', $type);
            }
            $query->where('r.orderid', (int) $orderid);
        }
        if (null !== $range) {
            $query->whereBetween('r.createtime', $range);
        }

        $total = (clone $query)->count();

        $rows = $query->field('r.id, r.mid, r.frommid, r.orderid, r.type, r.commission, r.score, r.remark, r.createtime, r.endtime, r.status, m.nickname, m.headimg, f.nickname as from_nickname')
            ->leftJoin('member f', 'f.id = r.frommid')
            ->order('r.id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        $list = array_map(function (array $row) use ($aid) {
            $orderInfo = $this->recordTypeOrder($aid, (string) ($row['type'] ?? ''), (int) ($row['orderid'] ?? 0));

            return [
                'id'             => (int) $row['id'],
                'mid'            => (int) ($row['mid'] ?? 0),
                'nickname'       => (string) ($row['nickname'] ?? ''),
                'headimg'        => (string) ($row['headimg'] ?? ''),
                'frommid'        => (int) ($row['frommid'] ?? 0),
                'from_nickname'  => (string) ($row['from_nickname'] ?? ''),
                'type'           => (string) ($row['type'] ?? ''),
                'commission'     => (float) ($row['commission'] ?? 0),
                'score'          => (float) ($row['score'] ?? 0),
                'orderid'        => (int) ($row['orderid'] ?? 0),
                'ordernum'       => $orderInfo['ordernum'],
                'orderstatus'    => $orderInfo['status'],
                'status'         => (int) ($row['status'] ?? 0),
                'status_text'    => (string) (self::RECORD_STATUS_TEXT[(int) ($row['status'] ?? 0)] ?? '-'),
                'remark'         => (string) ($row['remark'] ?? ''),
                'createtime'     => (int) ($row['createtime'] ?? 0),
                'endtime'        => (int) ($row['endtime'] ?? 0),
            ];
        }, $rows);

        $statQuery = ScopeService::query('member_commission_record', $aid)->where('status', 'in', [0, 1, 2]);
        if (null !== $range) {
            $statQuery->whereBetween('createtime', $range);
        }
        $stat = $statQuery->field(
            'count(*) as cnt, '
            . 'ifnull(sum(case when status = 0 then 1 else 0 end),0) as pending, '
            . 'ifnull(sum(case when status = 1 then 1 else 0 end),0) as paid, '
            . 'ifnull(sum(case when status = 2 then 1 else 0 end),0) as backed, '
            . 'ifnull(sum(case when status in (0,1) then commission else 0 end),0) as total_commission'
        )->find();

        return [
            'list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize,
            'stat' => [
                'count'           => (int) ($stat['cnt'] ?? 0),
                'pending'         => (int) ($stat['pending'] ?? 0),
                'paid'            => (int) ($stat['paid'] ?? 0),
                'backed'          => (int) ($stat['backed'] ?? 0),
                'total_commission' => round((float) ($stat['total_commission'] ?? 0), 2),
            ],
        ];
    }

    /**
     * 佣金记录导出（筛选口径同列表）
     *
     * @param array<string, mixed> $f
     * @return array{0: array<int, string>, 1: array<int, array<int, mixed>>}
     */
    public function commissionRecordExport(int $aid, array $f): array
    {
        $f['pagesize'] = 50000;
        $f['page']     = 1;
        $data          = $this->commissionRecordList($aid, $f);

        $titles = ['ID', '会员信息', '佣金', '积分', '状态', '业务类型', '订单编号', '产生时间', '发放时间', '备注'];
        $rows   = [];
        foreach ($data['list'] as $v) {
            $rows[] = [
                $v['id'],
                ($v['nickname'] ?: '会员' . $v['mid']) . '(会员ID:' . $v['mid'] . ')',
                $v['commission'],
                $v['score'],
                $v['status_text'],
                $v['type'],
                $v['ordernum'],
                $v['createtime'] ? date('Y-m-d H:i:s', $v['createtime']) : '',
                $v['endtime'] ? date('Y-m-d H:i:s', $v['endtime']) : '',
                $v['remark'],
            ];
        }

        return [$titles, $rows];
    }

    /* ================= 10. 分红记录（member_fenhonglog，任务177） ================= */

    /**
     * 分红记录列表 + 状态统计（member_fenhonglog）
     * 筛选：昵称/mid/状态/备注/时间/订单号（+订单类型）；按 module+ogids 回查订单状态与单号
     *
     * @param array<string, mixed> $f
     * @return array{list: array, total: int, page: int, pagesize: int, stat: array}
     */
    public function fenhonglogList(int $aid, array $f): array
    {
        $nickname  = trim((string) ($f['nickname'] ?? ''));
        $mid       = (int) ($f['mid'] ?? 0);
        $status    = trim((string) ($f['status'] ?? ''));
        $remark    = trim((string) ($f['remark'] ?? ''));
        $ordernum  = trim((string) ($f['ordernum'] ?? ''));
        $orderType = trim((string) ($f['order_type'] ?? ''));
        $page      = max(1, (int) ($f['page'] ?? 1));
        $pageSize  = $this->pageSize($f);
        $range     = $this->timeRange((string) ($f['start_date'] ?? ''), (string) ($f['end_date'] ?? ''));

        $query = ScopeService::queryAs('member_fenhonglog', $aid, 'r')
            ->leftJoin('member m', 'm.id = r.mid');

        if ('' !== $nickname) {
            $query->where('m.nickname', 'like', '%' . $nickname . '%');
        }
        if ($mid > 0) {
            $query->where('r.mid', $mid);
        }
        if ('' !== $status) {
            $query->where('r.status', (int) $status);
        }
        if ('' !== $remark) {
            $query->where('r.remark', 'like', '%' . $remark . '%');
        }
        if ('' !== $ordernum) {
            $module = '' !== $orderType ? $orderType : 'shop';
            $ogids  = $this->fenhongOgidsByOrdernum($aid, $module, $ordernum);
            $query->where('r.module', $module)->where('r.ogids', 'in', $ogids ?: [0]);
        }
        if (null !== $range) {
            $query->whereBetween('r.createtime', $range);
        }

        $total = (clone $query)->count();

        $rows = $query->field('r.id, r.mid, r.commission, r.send_commission, r.send_money, r.createtime, r.remark, r.type, r.module, r.ogids, r.status, r.frommid, m.nickname, m.headimg')
            ->order('r.id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        $list = array_map(function (array $row) use ($aid) {
            $module    = (string) ($row['module'] ?? '') ?: 'shop';
            $orderInfo = $this->fenhongModuleOrder($aid, $module, (string) ($row['ogids'] ?? ''));

            return [
                'id'              => (int) $row['id'],
                'mid'             => (int) ($row['mid'] ?? 0),
                'nickname'        => (string) ($row['nickname'] ?? ''),
                'headimg'         => (string) ($row['headimg'] ?? ''),
                'commission'      => (float) ($row['commission'] ?? 0),
                'send_commission' => (float) ($row['send_commission'] ?? 0),
                'send_money'      => (float) ($row['send_money'] ?? 0),
                'type'            => (string) ($row['type'] ?? ''),
                'module'          => $module,
                'ogids'           => (string) ($row['ogids'] ?? ''),
                'ordernum'        => $orderInfo['ordernum'],
                'orderstatus'     => $orderInfo['status'],
                'status'          => (int) ($row['status'] ?? 0),
                'status_text'     => (string) (self::RECORD_STATUS_TEXT[(int) ($row['status'] ?? 0)] ?? '-'),
                'remark'          => (string) ($row['remark'] ?? ''),
                'createtime'      => (int) ($row['createtime'] ?? 0),
            ];
        }, $rows);

        $statQuery = ScopeService::query('member_fenhonglog', $aid);
        if (null !== $range) {
            $statQuery->whereBetween('createtime', $range);
        }
        $stat = $statQuery->field(
            'count(*) as cnt, '
            . 'ifnull(sum(case when status = 0 then 1 else 0 end),0) as pending, '
            . 'ifnull(sum(case when status = 1 then 1 else 0 end),0) as paid, '
            . 'ifnull(sum(case when status = 2 then 1 else 0 end),0) as backed, '
            . 'ifnull(sum(case when status in (0,1) then commission else 0 end),0) as total_commission'
        )->find();

        return [
            'list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize,
            'stat' => [
                'count'           => (int) ($stat['cnt'] ?? 0),
                'pending'         => (int) ($stat['pending'] ?? 0),
                'paid'            => (int) ($stat['paid'] ?? 0),
                'backed'          => (int) ($stat['backed'] ?? 0),
                'total_commission' => round((float) ($stat['total_commission'] ?? 0), 2),
            ],
        ];
    }

    /**
     * 分红记录导出（筛选口径同列表）
     *
     * @param array<string, mixed> $f
     * @return array{0: array<int, string>, 1: array<int, array<int, mixed>>}
     */
    public function fenhonglogExport(int $aid, array $f): array
    {
        $f['pagesize'] = 50000;
        $f['page']     = 1;
        $data          = $this->fenhonglogList($aid, $f);

        $titles = ['ID', '会员信息', '分红金额', '订单编号', '订单类型', '状态', '结算时间', '备注'];
        $rows   = [];
        foreach ($data['list'] as $v) {
            $rows[] = [
                $v['id'],
                ($v['nickname'] ?: '会员' . $v['mid']) . '(会员ID:' . $v['mid'] . ')',
                $v['commission'],
                $v['ordernum'],
                $v['module'],
                $v['status_text'],
                $v['createtime'] ? date('Y-m-d H:i:s', $v['createtime']) : '',
                $v['remark'],
            ];
        }

        return [$titles, $rows];
    }

    /* ================= 11. 计次核销记录（hexiao_shopproduct，任务177） ================= */

    /**
     * 计次核销记录列表 + 汇总统计（hexiao_shopproduct join member/business）
     * 筛选：商户bid/昵称/商品名/mid/状态/时间；统计：记录数 + 核销总次数
     *
     * @param array<string, mixed> $f
     * @return array{list: array, total: int, page: int, pagesize: int, stat: array}
     */
    public function hexiaoShopproductList(int $aid, array $f): array
    {
        $nickname = trim((string) ($f['nickname'] ?? ''));
        $title    = trim((string) ($f['title'] ?? ''));
        $mid      = (int) ($f['mid'] ?? 0);
        $status   = trim((string) ($f['status'] ?? ''));
        $bid      = trim((string) ($f['bid'] ?? ''));
        $page     = max(1, (int) ($f['page'] ?? 1));
        $pageSize = $this->pageSize($f);
        $range    = $this->timeRange((string) ($f['start_date'] ?? ''), (string) ($f['end_date'] ?? ''));

        $query = ScopeService::queryAs('hexiao_shopproduct', $aid, 'h')
            ->leftJoin('member m', 'm.id = h.mid');

        if ('' !== $nickname) {
            $query->where('m.nickname', 'like', '%' . $nickname . '%');
        }
        if ('' !== $title) {
            $query->where('h.title', 'like', '%' . $title . '%');
        }
        if ($mid > 0) {
            $query->where('h.mid', $mid);
        }
        if ('' !== $status) {
            $query->where('h.status', (int) $status);
        }
        ScopeService::applyBid($query, $bid, 'h.bid');
        if (null !== $range) {
            $query->whereBetween('h.createtime', $range);
        }

        $total = (clone $query)->count();

        $rows = $query->field('h.id, h.bid, h.uid, h.mid, h.ordernum, h.title, h.name, h.pic, h.num, h.status, h.remark, h.delivery_time, h.delivery_remark, h.createtime, m.nickname, m.headimg')
            ->order('h.id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        $bnames = $this->businessNames($aid);

        $list = array_map(static function (array $row) use ($bnames): array { return [
            'id'              => (int) $row['id'],
            'bid'             => (int) ($row['bid'] ?? 0),
            'bname'           => (int) ($row['bid'] ?? 0) > 0 ? (string) ($bnames[(int) $row['bid']] ?? ('商户' . (int) $row['bid'])) : '[平台]',
            'uid'             => (int) ($row['uid'] ?? 0),
            'mid'             => (int) ($row['mid'] ?? 0),
            'nickname'        => (string) ($row['nickname'] ?? ''),
            'headimg'         => (string) ($row['headimg'] ?? ''),
            'ordernum'        => (string) ($row['ordernum'] ?? ''),
            'title'           => (string) ($row['title'] ?? ''),
            'pic'             => (string) ($row['pic'] ?? ''),
            'num'             => (int) ($row['num'] ?? 0),
            'status'          => (int) ($row['status'] ?? 1),
            'remark'          => (string) ($row['remark'] ?? ''),
            'delivery_time'   => (int) ($row['delivery_time'] ?? 0),
            'delivery_remark' => (string) ($row['delivery_remark'] ?? ''),
            'createtime'      => (int) ($row['createtime'] ?? 0),
        ]; }, $rows);

        $statQuery = ScopeService::query('hexiao_shopproduct', $aid);
        if (null !== $range) {
            $statQuery->whereBetween('createtime', $range);
        }
        $stat = $statQuery->field('count(*) as cnt, ifnull(sum(num),0) as total_num')->find();

        return [
            'list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize,
            'stat' => [
                'count'     => (int) ($stat['cnt'] ?? 0),
                'total_num' => (int) ($stat['total_num'] ?? 0),
            ],
        ];
    }

    /**
     * 计次核销记录导出（筛选口径同列表）
     *
     * @param array<string, mixed> $f
     * @return array{0: array<int, string>, 1: array<int, array<int, mixed>>}
     */
    public function hexiaoShopproductExport(int $aid, array $f): array
    {
        $f['pagesize'] = 50000;
        $f['page']     = 1;
        $data          = $this->hexiaoShopproductList($aid, $f);

        $titles = ['ID', '核销商家', '会员信息', '订单号', '商品名称', '核销数量', '核销时间', '配送备注', '备注信息'];
        $rows   = [];
        foreach ($data['list'] as $v) {
            $delivery = '';
            if ($v['delivery_remark'] || $v['delivery_time']) {
                $delivery = '备注：' . $v['delivery_remark'];
                if ($v['delivery_time']) {
                    $delivery .= '；时间：' . date('Y-m-d', $v['delivery_time']);
                }
            }
            $rows[] = [
                $v['id'],
                $v['bname'],
                ($v['nickname'] ?: '会员' . $v['mid']) . '(会员ID:' . $v['mid'] . ')',
                ' ' . $v['ordernum'],
                $v['title'],
                $v['num'],
                $v['createtime'] ? date('Y-m-d H:i:s', $v['createtime']) : '',
                $delivery,
                $v['remark'],
            ];
        }

        return [$titles, $rows];
    }

    /* ================= 内部工具 ================= */

    /**
     * 分页大小归一（1~100，默认 10）
     *
     * @param array<string, mixed> $f
     */
    protected function pageSize(array $f): int
    {
        $pageSize = (int) ($f['pagesize'] ?? 10);

        return $pageSize > 0 ? min($pageSize, 100) : 10;
    }

    /**
     * 时间范围解析（YYYY-MM-DD → [start, end] 时间戳；两端皆空返回 null）
     *
     * @return array{0: int, 1: int}|null
     */
    protected function timeRange(string $startDate, string $endDate): ?array
    {
        $start = '' !== trim($startDate) ? strtotime(trim($startDate)) : false;
        $end   = '' !== trim($endDate) ? strtotime(trim($endDate) . ' 23:59:59') : false;

        if (false === $start && false === $end) {
            return null;
        }

        return [(int) ($start ?: 0), (int) ($end ?: time())];
    }

    /**
     * 佣金记录 type → 订单表回查（ordernum + status），对标参考系统 model\Commission::getRecordTypeOrder
     * 特殊 type：levelup 无订单；money_withdraw/commission_withdraw 查提现表
     *
     * @return array{ordernum: string, status: int}
     */
    protected function recordTypeOrder(int $aid, string $type, int $orderid): array
    {
        $none = ['ordernum' => '', 'status' => 0];

        if ('' === $type || $orderid <= 0 || in_array($type, ['platform', 'salary'], true)) {
            return $none;
        }
        if ('levelup' === $type) {
            return ['ordernum' => '', 'status' => 1];
        }
        if ('money_withdraw' === $type) {
            $status = ScopeService::query('member_withdrawlog', $aid)->where('id', $orderid)->value('status');

            return ['ordernum' => '', 'status' => null !== $status ? (int) $status : 0];
        }
        if ('commission_withdraw' === $type) {
            $status = ScopeService::query('member_commission_withdrawlog', $aid)->where('id', $orderid)->value('status');

            return ['ordernum' => '', 'status' => null !== $status ? (int) $status : 0];
        }

        // type → 订单表名（网约车口径 carhailing，幸运拼团 lucky_collage）
        $table = match ($type) {
            'car_hailing', 'carhailing' => 'carhailing_order',
            'luckycollage', 'lucky_collage' => 'lucky_collage_order',
            default => $type . '_order',
        };

        if (!$this->tableExists($table)) {
            return $none;
        }

        $row = ScopeService::query($table, $aid)->where('id', $orderid)->field('status, ordernum')->find();

        if (empty($row)) {
            return $none;
        }

        return [
            'ordernum' => (string) ($row['ordernum'] ?? ''),
            'status'   => (int) ($row['status'] ?? 0),
        ];
    }

    /**
     * 分红记录 module+ogids → 订单回查（对标参考系统 fenhonglog 逐行回查逻辑）
     * - shop/scoreshop/restaurant_shop/restaurant_takeaway：ogids 为订单商品ID → 查 *_order_goods
     * - lucky_collage/luckycollage：ogids 为幸运拼团订单ID
     * - cashier：ogids 为收银订单商品ID → 反查 cashier_order
     * - member：无订单
     * - 其余：ogids 为订单ID → 查 *_order
     *
     * @return array{ordernum: string, status: int}
     */
    protected function fenhongModuleOrder(int $aid, string $module, string $ogids): array
    {
        $none = ['ordernum' => '', 'status' => 0];

        if ('' === $ogids || 'member' === $module) {
            return $none;
        }

        // ogids 可能为逗号分隔多值，取首值回查（与参考系统一致）
        $ogid = (int) trim(explode(',', $ogids)[0]);
        if ($ogid <= 0) {
            return $none;
        }

        if (in_array($module, ['shop', 'scoreshop', 'restaurant_shop', 'restaurant_takeaway'], true)) {
            $table = $module . '_order_goods';
            if (!$this->tableExists($table)) {
                return $none;
            }
            $row = Db::name($table)->where('aid', $aid)->where('id', $ogid)->field('status, ordernum')->find();

            return $row ? ['ordernum' => (string) ($row['ordernum'] ?? ''), 'status' => (int) ($row['status'] ?? 0)] : $none;
        }

        if ('luckycollage' === $module || 'lucky_collage' === $module) {
            if (!$this->tableExists('lucky_collage_order')) {
                return $none;
            }
            $row = Db::name('lucky_collage_order')->where('aid', $aid)->where('id', $ogid)->field('status, ordernum')->find();

            return $row ? ['ordernum' => (string) ($row['ordernum'] ?? ''), 'status' => (int) ($row['status'] ?? 0)] : $none;
        }

        if ('cashier' === $module) {
            if (!$this->tableExists('cashier_order_goods') || !$this->tableExists('cashier_order')) {
                return $none;
            }
            $orderid = Db::name('cashier_order_goods')->where('aid', $aid)->where('id', $ogid)->value('orderid');
            if (!$orderid) {
                return $none;
            }
            $row = Db::name('cashier_order')->where('aid', $aid)->where('id', (int) $orderid)->field('status, ordernum')->find();

            return $row ? ['ordernum' => (string) ($row['ordernum'] ?? ''), 'status' => (int) ($row['status'] ?? 0)] : $none;
        }

        $table = $module . '_order';
        if (!$this->tableExists($table)) {
            return $none;
        }
        $row = Db::name($table)->where('aid', $aid)->where('id', $ogid)->field('status, ordernum')->find();

        return $row ? ['ordernum' => (string) ($row['ordernum'] ?? ''), 'status' => (int) ($row['status'] ?? 0)] : $none;
    }

    /**
     * 按订单号回查 ogids（分红记录订单号筛选，对标参考系统 ordernum 筛选逻辑）
     *
     * @return array<int, int>
     */
    protected function fenhongOgidsByOrdernum(int $aid, string $module, string $ordernum): array
    {
        if (in_array($module, ['shop', 'scoreshop', 'restaurant_shop', 'restaurant_takeaway', 'cashier'], true)) {
            $table = $module . '_order_goods';
            if (!$this->tableExists($table)) {
                return [];
            }

            return array_map('intval', Db::name($table)->where('aid', $aid)->where('ordernum', $ordernum)->column('id'));
        }

        $table = $module . '_order';
        if (!$this->tableExists($table)) {
            return [];
        }

        return array_map('intval', Db::name($table)->where('aid', $aid)->where('ordernum', $ordernum)->column('id'));
    }

    /**
     * 商户 id→name 映射（status=1，bid=0 时展示 [平台]）
     *
     * @return array<int, string>
     */
    protected function businessNames(int $aid): array
    {
        $map = ScopeService::query('business', $aid)->where('status', 1)->column('name', 'id');

        return array_map('strval', $map);
    }

    /** @var array<string, bool> 表存在性缓存（单次请求内） */
    protected static array $tableCache = [];

    /**
     * 表存在性检查（分红/佣金回查兼容未建模块，防 SQL 报错）
     */
    protected function tableExists(string $table): bool
    {
        if (!isset(self::$tableCache[$table])) {
            $prefix = (string) Db::connect()->getConfig('prefix');
            // SHOW TABLES LIKE 不支持 PDO 参数占位，表名经转义后拼接
            $pattern = str_replace('`', '', addslashes($prefix . $table));
            self::$tableCache[$table] = !empty(Db::query("SHOW TABLES LIKE '{$pattern}'"));
        }

        return self::$tableCache[$table];
    }
}
