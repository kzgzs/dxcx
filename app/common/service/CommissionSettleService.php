<?php

declare(strict_types=1);

namespace app\common\service;

use think\facade\Db;

/**
 * 佣金结算服务（任务182 批次3-B）
 *
 * 对标参考系统 canzhao/app/common/Order.php：
 * - jiesuanCommission@4246    日结（fxjiesuantime 0收货后/1付款后 + delaydays 延迟天数）
 * - jiesuanCommissionWeek@4703 周结（delaydays 语义为星期几，每周指定日结算）
 * - giveCommission@4733        入账（record 0→1 + member_commissionlog + 可选发余额 + 通知）
 *
 * 与分销引擎（FenxiaoService）职责分离：引擎只在支付成功时写冻结记录（status=0），
 * 本服务负责冻结→结算/作废的状态流转，不触碰引擎计算逻辑。
 *
 * member_commission_record.status：0 冻结 / 1 已结算 / 2 已作废
 * 幂等：入账在事务内 lock(true) + status=0 复核，重复执行不重复入账。
 */
class CommissionSettleService
{
    /** 参与结算的业务类型（订单表 = {type}_order） */
    protected const TYPES = ['shop', 'seckill', 'scoreshop', 'collage', 'lucky_collage', 'tuangou', 'kecheng', 'hotel'];

    /* ================= 调度入口（ScheduleRun 调用，全局扫描） ================= */

    /**
     * 日结扫描：遍历有冻结记录的租户，perweek=1 的租户跳过（走周结）
     *
     * @return array{scanned: int, settled: int, void: int}
     */
    public function settleDailyAll(): array
    {
        $total = ['scanned' => 0, 'settled' => 0, 'void' => 0];

        foreach ($this->frozenAids() as $aid) {
            $sysset = $this->sysset($aid);

            if (1 === (int) ($sysset['fxjiesuantime_perweek'] ?? 0)) {
                continue; // 周结租户由 settleWeekAll 处理
            }

            $result = $this->jiesuanCommission($aid, $sysset);
            $total['scanned'] += $result['scanned'];
            $total['settled'] += $result['settled'];
            $total['void']    += $result['void'];
        }

        return $total;
    }

    /**
     * 周结扫描：仅 fxjiesuantime_perweek=1 的租户
     *
     * @return array{scanned: int, settled: int, void: int}
     */
    public function settleWeekAll(): array
    {
        $total = ['scanned' => 0, 'settled' => 0, 'void' => 0];

        foreach ($this->frozenAids() as $aid) {
            $sysset = $this->sysset($aid);

            if (1 !== (int) ($sysset['fxjiesuantime_perweek'] ?? 0)) {
                continue;
            }

            $result = $this->jiesuanCommissionWeek($aid, $sysset);
            $total['scanned'] += $result['scanned'];
            $total['settled'] += $result['settled'];
            $total['void']    += $result['void'];
        }

        return $total;
    }

    /* ================= 租户级结算 ================= */

    /**
     * 日结（对标 jiesuanCommission@4246）
     *
     * fxjiesuantime：0 确认收货后（订单 status==3）/ 1 付款后（status in 1,2,3）
     * fxjiesuantime_delaydays：延迟天数（基于付款时间），同时要求 record.createtime 早于延迟窗口
     * 订单不存在或已关闭（status 4/-1）→ record 置 2 作废
     *
     * @param array<string, mixed>|null $sysset admin_set 行（缺省自查）
     * @return array{scanned: int, settled: int, void: int}
     */
    public function jiesuanCommission(int $aid, ?array $sysset = null): array
    {
        $sysset ??= $this->sysset($aid);

        $result = ['scanned' => 0, 'settled' => 0, 'void' => 0];

        $dtime = time();
        $delay = (float) ($sysset['fxjiesuantime_delaydays'] ?? 0);
        if ($delay > 0) {
            $dtime -= (int) ($delay * 86400);
        }

        $records = Db::name('member_commission_record')
            ->where('aid', $aid)
            ->where('status', 0)
            ->whereIn('type', self::TYPES)
            ->where('createtime', '<', $dtime)
            ->select()
            ->toArray();

        foreach ($records as $record) {
            ++$result['scanned'];

            $order = $this->findOrder((string) $record['type'], (int) $record['orderid']);

            // 订单不存在或已关闭 → 作废
            if (empty($order) || in_array((int) $order['status'], [4, -1], true)) {
                Db::name('member_commission_record')->where('id', (int) $record['id'])->where('status', 0)
                    ->update(['status' => 2, 'endtime' => time()]);
                ++$result['void'];
                continue;
            }

            if ($this->orderReady((string) $record['type'], (array) $order, (int) ($sysset['fxjiesuantime'] ?? 0), $dtime)) {
                if ($this->giveCommission((array) $record)) {
                    ++$result['settled'];
                }
            }
        }

        return $result;
    }

    /**
     * 周结（对标 jiesuanCommissionWeek@4703）
     *
     * delaydays 语义为星期几（1-7，7=周日），仅当天为指定星期几时结算昨日前的冻结记录
     *
     * @param array<string, mixed>|null $sysset
     * @return array{scanned: int, settled: int, void: int}
     */
    public function jiesuanCommissionWeek(int $aid, ?array $sysset = null): array
    {
        $sysset ??= $this->sysset($aid);

        $result = ['scanned' => 0, 'settled' => 0, 'void' => 0];

        $week = (int) ($sysset['fxjiesuantime_delaydays'] ?? 0);
        if ($week <= 0) {
            return $result; // 未配置结算星期
        }
        if (7 === $week) {
            $week = 0;
        }
        if ((string) $week !== date('w')) {
            return $result; // 今天不是结算日
        }

        $dtime = strtotime(date('Y-m-d', time() - 86400));

        $records = Db::name('member_commission_record')
            ->where('aid', $aid)
            ->where('status', 0)
            ->whereIn('type', self::TYPES)
            ->where('createtime', '<', $dtime)
            ->select()
            ->toArray();

        foreach ($records as $record) {
            ++$result['scanned'];

            $order = $this->findOrder((string) $record['type'], (int) $record['orderid']);

            if (empty($order) || in_array((int) $order['status'], [4, -1], true)) {
                Db::name('member_commission_record')->where('id', (int) $record['id'])->where('status', 0)
                    ->update(['status' => 2, 'endtime' => time()]);
                ++$result['void'];
                continue;
            }

            if ($this->orderReady((string) $record['type'], (array) $order, (int) ($sysset['fxjiesuantime'] ?? 0), $dtime)) {
                if ($this->giveCommission((array) $record)) {
                    ++$result['settled'];
                }
            }
        }

        return $result;
    }

    /* ================= 手动结算（对照参考系统后台 jiesuan 手动按钮） ================= */

    /**
     * 手动结算指定冻结记录（跳过延迟/结算日条件，但仍校验订单有效性）
     *
     * @param array<int|string> $ids record id 列表
     * @return array{settled: int, void: int, skipped: int}
     */
    public function manualSettle(int $aid, array $ids): array
    {
        $result = ['settled' => 0, 'void' => 0, 'skipped' => 0];

        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) {
            return $result;
        }

        $records = Db::name('member_commission_record')
            ->where('aid', $aid)
            ->whereIn('id', $ids)
            ->where('status', 0)
            ->select()
            ->toArray();

        foreach ($records as $record) {
            $order = $this->findOrder((string) $record['type'], (int) $record['orderid']);

            if (empty($order) || in_array((int) $order['status'], [4, -1], true)) {
                Db::name('member_commission_record')->where('id', (int) $record['id'])->where('status', 0)
                    ->update(['status' => 2, 'endtime' => time()]);
                ++$result['void'];
                continue;
            }

            if ($this->giveCommission((array) $record)) {
                ++$result['settled'];
            } else {
                ++$result['skipped'];
            }
        }

        return $result;
    }

    /* ================= 入账（对标 giveCommission@4733） ================= */

    /**
     * 单条冻结记录入账（事务 + 行锁幂等）
     *
     * - record：status 0→1 + endtime（仅 status=0 可入账，重复执行直接跳过）
     * - 佣金：member.commission/totalcommission 增加 + member_commissionlog（after 快照，fhtype=fenxiao）
     * - commission_send_wallet=1 且等级符合：同步发余额（member.money + member_moneylog）
     * - 积分：member.score 增加 + member_scorelog（channel=fenxiao）
     * - 通知：写模板消息队列 wechat_send_tmpl_data（tmpl_fenxiaosuccess 占位）
     *
     * @param array<string, mixed> $record member_commission_record 行
     * @return bool 是否实际入账（幂等跳过返回 false）
     */
    public function giveCommission(array $record): bool
    {
        $aid      = (int) ($record['aid'] ?? 0);
        $recordId = (int) ($record['id'] ?? 0);

        if ($aid <= 0 || $recordId <= 0) {
            return false;
        }

        return Db::transaction(function () use ($aid, $recordId, $record): bool {
            // 行锁复核：仅 status=0 可入账（幂等核心）
            $locked = Db::name('member_commission_record')
                ->where('id', $recordId)
                ->where('aid', $aid)
                ->lock(true)
                ->find();

            if (empty($locked) || 0 !== (int) $locked['status']) {
                return false;
            }

            $record = (array) $locked;

            Db::name('member_commission_record')->where('id', $recordId)->update([
                'status'  => 1,
                'endtime' => time(),
            ]);

            $mid        = (int) $record['mid'];
            $commission = round((float) ($record['commission'] ?? 0), 2);
            $score      = (int) ($record['score'] ?? 0);
            $remark     = (string) ($record['remark'] ?? '');
            $member     = null;

            if ($commission > 0) {
                // 佣金入账：佣金余额 + 流水快照
                $member = Db::name('member')->where('id', $mid)->where('aid', $aid)->lock(true)->find();

                if (!empty($member)) {
                    $after = round((float) ($member['commission'] ?? 0) + $commission, 2);
                    Db::name('member')->where('id', $mid)->where('aid', $aid)->update([
                        'commission'      => $after,
                        'totalcommission' => round((float) ($member['totalcommission'] ?? 0) + $commission, 2),
                    ]);

                    Db::name('member_commissionlog')->insert([
                        'aid'        => $aid,
                        'mid'        => $mid,
                        'frommid'    => (int) ($record['frommid'] ?? 0),
                        'commission' => $commission,
                        'after'      => $after,
                        'createtime' => time(),
                        'remark'     => $remark,
                        'fhtype'     => 'fenxiao',
                        'uid'        => 0,
                        'fhid'       => $recordId,
                    ]);

                    // commission_send_wallet=1：结算同步发余额（等级限制，空/-1 不限）
                    if ($this->sendWallet($aid, (int) ($member['levelid'] ?? 0))) {
                        $afterMoney = round((float) ($member['money'] ?? 0) + $commission, 2);
                        Db::name('member')->where('id', $mid)->where('aid', $aid)->update(['money' => $afterMoney]);

                        Db::name('member_moneylog')->insert([
                            'aid'        => $aid,
                            'mid'        => $mid,
                            'money'      => $commission,
                            'after'      => $afterMoney,
                            'createtime' => time(),
                            'remark'     => '分销佣金结算 ' . $remark,
                            'paytype'    => 'commission',
                            'uid'        => 0,
                        ]);
                    }
                }
            }

            if ($score > 0) {
                $member = $member ?? Db::name('member')->where('id', $mid)->where('aid', $aid)->find();

                if (!empty($member)) {
                    $afterScore = (int) ($member['score'] ?? 0) + $score;
                    Db::name('member')->where('id', $mid)->where('aid', $aid)->update(['score' => $afterScore]);

                    // member_scorelog.mid 为 varchar，写入须转字符串（同 MemberAdminService 口径）
                    Db::name('member_scorelog')->insert([
                        'aid'        => $aid,
                        'bid'        => 0,
                        'mid'        => (string) $mid,
                        'score'      => $score,
                        'after'      => $afterScore,
                        'used'       => 0,
                        'createtime' => time(),
                        'remark'     => '分销佣金结算 ' . $remark,
                        'type'       => 1,
                        'channel'    => 'fenxiao',
                        'status'     => 0,
                        'uid'        => 0,
                    ]);
                }
            }

            $this->notify($aid, $mid, $record);

            return true;
        });
    }

    /* ================= 内部工具 ================= */

    /**
     * 订单是否满足结算条件
     *
     * @param array<string, mixed> $order
     */
    protected function orderReady(string $type, array $order, int $fxjiesuantime, int $dtime): bool
    {
        $status  = (int) $order['status'];
        $paytime = (int) ($order['paytime'] ?? 0);

        // 业务状态语义对齐（对标参考系统）：kecheng 已支付视作已完成；hotel 4=已离店视作已完成
        if ('kecheng' === $type && 1 === $status) {
            $status = 3;
        }
        if ('hotel' === $type && 4 === $status) {
            $status = 3;
        }

        if ($paytime > 0 && $paytime >= $dtime) {
            return false; // 延迟未到
        }

        if (0 === $fxjiesuantime) {
            return 3 === $status; // 确认收货后结算
        }

        return in_array($status, [1, 2, 3], true); // 付款后结算
    }

    /**
     * 按业务类型回查订单行（表 = {type}_order）
     *
     * @return array<string, mixed>|null
     */
    protected function findOrder(string $type, int $orderId): ?array
    {
        if (!in_array($type, self::TYPES, true) || $orderId <= 0) {
            return null;
        }

        $row = Db::name($type . '_order')->where('id', $orderId)->find();

        return empty($row) ? null : (array) $row;
    }

    /**
     * commission_send_wallet=1 且会员等级在限制范围内（空/-1 不限）
     */
    protected function sendWallet(int $aid, int $levelid): bool
    {
        $sysset = $this->sysset($aid);

        if (1 !== (int) ($sysset['commission_send_wallet'] ?? 0)) {
            return false;
        }

        $levelids = array_values(array_filter(explode(',', (string) ($sysset['commission_send_wallet_levelids'] ?? '')), static fn ($v) => '' !== trim($v)));

        if (empty($levelids) || in_array('-1', $levelids, true)) {
            return true;
        }

        return in_array((string) $levelid, $levelids, true);
    }

    /**
     * 结算通知：模板消息队列占位（对标参考系统 tmpl_fenxiaosuccess 模板消息/短信）
     *
     * @param array<string, mixed> $record
     */
    protected function notify(int $aid, int $mid, array $record): void
    {
        $commission = round((float) ($record['commission'] ?? 0), 2);
        $content    = sprintf(
            '恭喜您，分销佣金已结算入账：￥%s（%s）',
            number_format($commission, 2, '.', ''),
            (string) ($record['remark'] ?? '')
        );

        Db::name('wechat_send_tmpl_data')->insert([
            'aid'          => $aid,
            'bid'          => 0,
            'mid'          => $mid,
            'tmpl_name'    => 'tmpl_fenxiaosuccess',
            'tmpl_content' => $content,
            'm_url'        => '',
            'mdid'         => 0,
            'status'       => 0,
            'type'         => 'fenxiao',
            'createtime'   => time(),
            'updatetime'   => time(),
        ]);
    }

    /**
     * 存在冻结记录（status=0）的租户 aid 列表
     *
     * @return array<int>
     */
    protected function frozenAids(): array
    {
        $rows = Db::name('member_commission_record')
            ->where('status', 0)
            ->whereIn('type', self::TYPES)
            ->group('aid')
            ->column('aid');

        return array_map('intval', $rows);
    }

    /**
     * 租户 admin_set 行
     *
     * @return array<string, mixed>
     */
    protected function sysset(int $aid): array
    {
        $row = Db::name('admin_set')->where('aid', $aid)->find();

        return empty($row) ? ['aid' => $aid] : (array) $row;
    }
}
