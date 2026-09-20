<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;

/**
 * 会员升级申请审核服务（任务176 批次2-D）
 *
 * 表口径：
 * - member_levelup_order 升级申请单（status：0待支付 1待审核/已支付 2已通过 3已驳回；
 *   type：0升级 1降级；form0~form20 存储「标题^_^值^_^类型」）
 * - member               主表（levelid/levelstarttime/levelendtime，只读+事务内 levelid 变更）
 * - member_level         等级（up_give_score/up_give_money/up_give_commission/up_give_parent_money/
 *   up_give_coupon/yxqdate 升级奖励与有效期）
 *
 * 审核通过全链路（单事务）：等级变更 → 有效期 → 奖励积分/余额/佣金/上级佣金 → 赠券（容错） → 通知；
 * 审核驳回：已付款订单按支付方式原路退款（余额/积分/微信渠道）。
 */
class MemberLevelupService
{
    /** 状态文案（对齐参考系统 status：0待支付 1待审核 2已通过 3已驳回） */
    public const STATUS_TEXT = [0 => '待支付', 1 => '待审核', 2 => '已通过', 3 => '已驳回'];

    public function __construct(
        protected CouponManageService $couponManageService,
        protected PayService $payService
    ) {
    }

    /* ================= 申请记录列表 ================= */

    /**
     * 升级申请记录列表
     * 过滤：orderid / mid / nickname / type(all|0|1) / status(all|0|1|2|3) / 时间范围 / formx 表单内容
     *
     * @param array<string, mixed> $filters
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function applyList(int $aid, array $filters, int $page, int $pageSize): array
    {
        $orderId   = (int) ($filters['orderid'] ?? 0);
        $mid       = (int) ($filters['mid'] ?? 0);
        $nickname  = trim((string) ($filters['nickname'] ?? ''));
        $realname  = trim((string) ($filters['realname'] ?? ''));
        $type      = (string) ($filters['type'] ?? 'all');
        $status    = (string) ($filters['status'] ?? 'all');
        $startTime = trim((string) ($filters['start_time'] ?? ''));
        $endTime   = trim((string) ($filters['end_time'] ?? ''));
        $formx     = trim((string) ($filters['formx'] ?? ''));

        $query = ScopeService::queryAs('member_levelup_order', $aid, 'vo')
            ->join('member m', 'vo.mid = m.id')
            ->leftJoin('member p', 'p.id = m.pid');

        if ($orderId > 0) {
            $query->where('vo.id', $orderId);
        }
        if ($mid > 0) {
            $query->where('vo.mid', $mid);
        }
        if ('' !== $nickname) {
            $query->where('m.nickname', 'like', '%' . $nickname . '%');
        }
        if ('' !== $realname) {
            $query->where('m.realname', 'like', '%' . $realname . '%');
        }
        if ('all' !== $type && '' !== $type) {
            $query->where('vo.type', (int) $type);
        }
        if ('all' !== $status && '' !== $status) {
            $query->where('vo.status', (int) $status);
        }

        $start = '' !== $startTime ? strtotime($startTime) : false;
        $end   = '' !== $endTime ? strtotime($endTime . ' 23:59:59') : false;
        if (false !== $start) {
            $query->where('vo.createtime', '>=', (int) $start);
        }
        if (false !== $end) {
            $query->where('vo.createtime', '<=', (int) $end);
        }
        if ('' !== $formx) {
            $formFields = array_map(static fn (int $i) => 'vo.form' . $i, range(0, 20));
            $query->where(function ($q) use ($formFields, $formx) {
                foreach ($formFields as $field) {
                    $q->whereOr($field, 'like', '%' . $formx . '%');
                }
            });
        }

        $total = (clone $query)->count();

        $rows = $query->field('vo.*, m.nickname, m.headimg, m.realname, m.pid, m.levelid as member_levelid, p.nickname as pnickname, p.headimg as pheadimg')
            ->order('vo.createtime', 'desc')
            ->order('vo.id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        $levelMap = $this->levelMap($aid);
        // 默认等级名（申请单未记录 beforelevelid 或会员当前为默认等级时兜底）
        $defaultLevel = ScopeService::query('member_level', $aid)->where('isdefault', 1)->field('name')->find();
        $defaultName  = (string) ($defaultLevel['name'] ?? '');

        $list = array_map(function (array $row) use ($levelMap, $defaultName) {
            $statusVal = (int) ($row['status'] ?? 0);

            // 当前等级：申请单记录了 beforelevelid 用之，否则回退会员当前 levelid，再否则默认等级
            $beforeId    = (int) ($row['beforelevelid'] ?? 0);
            $memberLvId  = (int) ($row['member_levelid'] ?? 0);
            $beforeLevel = $beforeId > 0
                ? ($levelMap[$beforeId] ?? '')
                : ($memberLvId > 0 ? ($levelMap[$memberLvId] ?? '') : '');
            if ('' === $beforeLevel) {
                $beforeLevel = $defaultName;
            }
            $applyLevel = $levelMap[(int) ($row['levelid'] ?? 0)] ?? '';
            if ('' === $applyLevel) {
                $applyLevel = $defaultName;
            }

            return [
                'id'              => (int) $row['id'],
                'mid'             => (int) ($row['mid'] ?? 0),
                'nickname'        => (string) ($row['nickname'] ?? ''),
                'headimg'         => (string) ($row['headimg'] ?? ''),
                'realname'        => (string) ($row['realname'] ?? ''),
                'pid'             => (int) ($row['pid'] ?? 0),
                'pnickname'       => (string) ($row['pnickname'] ?? ''),
                'pheadimg'        => (string) ($row['pheadimg'] ?? ''),
                'ordernum'        => (string) ($row['ordernum'] ?? ''),
                'title'           => (string) ($row['title'] ?? ''),
                'type'            => (int) ($row['type'] ?? 0),
                'type_text'       => (int) ($row['type'] ?? 0) === 1 ? '降级' : '升级',
                'status'          => $statusVal,
                'status_text'     => self::STATUS_TEXT[$statusVal] ?? (string) $statusVal,
                'totalprice'      => (float) ($row['totalprice'] ?? 0),
                'paytype'         => (string) ($row['paytype'] ?? ''),
                'paytime'         => (int) ($row['paytime'] ?? 0),
                'beforelevelid'   => $beforeId,
                'before_level'    => $beforeLevel,
                'levelid'         => (int) ($row['levelid'] ?? 0),
                'apply_level'     => $applyLevel,
                'createtime'      => (int) ($row['createtime'] ?? 0),
                'levelup_time'    => (int) ($row['levelup_time'] ?? 0),
                'platform'        => (string) ($row['platform'] ?? ''),
                'formdata'        => $this->parseForms($row),
            ];
        }, $rows);

        return ['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize];
    }

    /**
     * 删除升级申请记录（物理删，对齐点大 applydel：按 aid + ids 限定）
     *
     * @param array<int, mixed> $ids
     */
    public function deleteLevels(int $aid, array $ids): int
    {
        $ids = array_values(array_filter(array_unique(array_map('intval', $ids)), static fn ($v) => $v > 0));
        if (empty($ids)) {
            throw new BusinessException('请选择要删除的记录');
        }

        return (int) ScopeService::query('member_levelup_order', $aid)->whereIn('id', $ids)->delete();
    }

    /* ================= 审核（通过 / 驳回） ================= */

    /**
     * 审核升级申请
     *
     * @param int $st 2=通过 3=驳回
     * @return array{status: int, refund: array<string, mixed>}
     */
    public function audit(int $aid, int $id, int $st, string $remark): array
    {
        if (!in_array($st, [2, 3], true)) {
            throw new BusinessException('审核状态无效');
        }

        $order = ScopeService::query('member_levelup_order', $aid)->where('id', $id)->find();
        if (empty($order)) {
            throw new BusinessException('申请记录不存在', 404, 404);
        }

        $statusVal = (int) ($order['status'] ?? 0);
        if (in_array($statusVal, [2, 3], true)) {
            throw new BusinessException('该申请已审核，请勿重复操作');
        }

        $order = (array) $order;

        if (2 === $st) {
            $this->auditPass($aid, $order, $remark);
        } else {
            $this->auditReject($aid, $order, $remark);
        }

        return [
            'status' => $st,
            'refund' => 3 === $st ? $this->refundInfo($order) : [],
        ];
    }

    /**
     * 审核通过（单事务：等级变更 + 有效期 + 奖励发放 + 通知）
     *
     * @param array<string, mixed> $order
     */
    protected function auditPass(int $aid, array $order, string $remark): void
    {
        $mid     = (int) $order['mid'];
        $levelId = (int) $order['levelid'];

        $newLevel = ScopeService::query('member_level', $aid)->where('id', $levelId)->find();
        if (empty($newLevel)) {
            throw new BusinessException('申请的会员等级不存在，无法通过');
        }
        $newLevel = (array) $newLevel;

        // 有效期：yxqdate>0 → 明日零点 + N 天；否则永久
        $yxqdate    = (int) ($newLevel['yxqdate'] ?? 0);
        $levelEnd   = $yxqdate > 0 ? strtotime(date('Y-m-d')) + 86400 + 86400 * $yxqdate : 0;
        $giveScore  = (int) ($newLevel['up_give_score'] ?? 0);
        $giveMoney  = round((float) ($newLevel['up_give_money'] ?? 0), 2);
        $giveComm   = round((float) ($newLevel['up_give_commission'] ?? 0), 2);
        $giveParent = round((float) ($newLevel['up_give_parent_money'] ?? 0), 2);

        Db::transaction(function () use ($aid, $order, $mid, $levelId, $levelEnd, $giveScore, $giveMoney, $giveComm, $giveParent) {
            $orderId = (int) $order['id'];

            // 申请单行锁：防并发重复审核
            $locked = Db::name('member_levelup_order')
                ->where('id', $orderId)
                ->where('aid', $aid)
                ->lock(true)
                ->find();

            if (empty($locked) || in_array((int) $locked['status'], [2, 3], true)) {
                throw new BusinessException('该申请已审核，请勿重复操作');
            }

            // 会员行锁：等级变更与奖励同事务落库
            $member = Db::name('member')
                ->where('id', $mid)
                ->where('aid', $aid)
                ->lock(true)
                ->find();

            if (empty($member)) {
                throw new BusinessException('会员不存在', 404, 404);
            }

            // 1. 更新申请单状态
            Db::name('member_levelup_order')->where('id', $orderId)->update([
                'status'       => 2,
                'levelup_time' => time(),
            ]);

            // 2. 更新会员等级与有效期
            Db::name('member')->where('id', $mid)->where('aid', $aid)->update([
                'levelid'        => $levelId,
                'levelstarttime' => time(),
                'levelendtime'   => $levelEnd,
            ]);

            // 3. 赠送积分
            if ($giveScore > 0) {
                $afterScore = (int) ($member['score'] ?? 0) + $giveScore;
                Db::name('member')->where('id', $mid)->where('aid', $aid)->update(['score' => $afterScore]);
                Db::name('member_scorelog')->insert([
                    'aid'        => $aid,
                    'bid'        => 0,
                    'mid'        => (string) $mid,
                    'score'      => $giveScore,
                    'after'      => $afterScore,
                    'used'       => 0,
                    'createtime' => time(),
                    'remark'     => '升级奖励',
                    'type'       => 1,
                    'channel'    => 'levelup',
                    'status'     => 0,
                    'uid'        => 0,
                ]);
            }

            // 4. 奖励余额
            if ($giveMoney > 0) {
                $afterMoney = round((float) ($member['money'] ?? 0) + $giveMoney, 2);
                Db::name('member')->where('id', $mid)->where('aid', $aid)->update(['money' => $afterMoney]);
                Db::name('member_moneylog')->insert([
                    'aid'        => $aid,
                    'mid'        => $mid,
                    'money'      => $giveMoney,
                    'after'      => $afterMoney,
                    'createtime' => time(),
                    'remark'     => '升级奖励',
                    'paytype'    => 'levelup',
                    'uid'        => 0,
                ]);
            }

            // 5. 奖励佣金
            if ($giveComm > 0) {
                $afterComm = round((float) ($member['commission'] ?? 0) + $giveComm, 2);
                Db::name('member')->where('id', $mid)->where('aid', $aid)->update(['commission' => $afterComm]);
                Db::name('member_commissionlog')->insert([
                    'aid'        => $aid,
                    'mid'        => $mid,
                    'frommid'    => 0,
                    'commission' => $giveComm,
                    'after'      => $afterComm,
                    'createtime' => time(),
                    'remark'     => '升级奖励',
                    'fhtype'     => 'levelup_give',
                    'uid'        => 0,
                    'fhid'       => 0,
                ]);
            }

            // 6. 赠送上级佣金（直推奖）
            $pid = (int) ($member['pid'] ?? 0);
            if ($giveParent > 0 && $pid > 0) {
                $parent = Db::name('member')
                    ->where('id', $pid)
                    ->where('aid', $aid)
                    ->lock(true)
                    ->find();

                if (!empty($parent)) {
                    $afterParent = round((float) ($parent['commission'] ?? 0) + $giveParent, 2);
                    Db::name('member')->where('id', $pid)->where('aid', $aid)->update(['commission' => $afterParent]);
                    Db::name('member_commissionlog')->insert([
                        'aid'        => $aid,
                        'mid'        => $pid,
                        'frommid'    => $mid,
                        'commission' => $giveParent,
                        'after'      => $afterParent,
                        'createtime' => time(),
                        'remark'     => '直推奖',
                        'fhtype'     => 'levelup_parent',
                        'uid'        => 0,
                        'fhid'       => 0,
                    ]);
                }
            }
        });

        // 赠券（容错：库存不足等不阻断审核主链路）
        $couponResult = $this->giveCoupons($aid, $mid, (string) ($newLevel['up_give_coupon'] ?? ''));

        // 通知（模板消息队列，C 端/定时通道后续消费）
        $this->sendNotice($aid, $mid, sprintf(
            '您的%s申请已通过审核，当前等级：%s%s',
            (int) ($order['type'] ?? 0) === 1 ? '降级' : '升级',
            (string) ($newLevel['name'] ?? ''),
            '' !== $remark ? '（' . $remark . '）' : ('' !== $couponResult ? '（' . $couponResult . '）' : '')
        ));
    }

    /**
     * 审核驳回（已付款按支付方式退款）
     *
     * @param array<string, mixed> $order
     */
    protected function auditReject(int $aid, array $order, string $remark): void
    {
        $mid        = (int) $order['mid'];
        $totalprice = round((float) ($order['totalprice'] ?? 0), 2);
        $paid       = (int) ($order['status'] ?? 0) === 1 || (int) ($order['paytime'] ?? 0) > 0;

        Db::transaction(function () use ($aid, $order, $mid, $totalprice, $paid) {
            $orderId = (int) $order['id'];

            $locked = Db::name('member_levelup_order')
                ->where('id', $orderId)
                ->where('aid', $aid)
                ->lock(true)
                ->find();

            if (empty($locked) || in_array((int) $locked['status'], [2, 3], true)) {
                throw new BusinessException('该申请已审核，请勿重复操作');
            }

            // 已付款 → 按支付方式退款
            if ($paid && $totalprice > 0) {
                $this->refundOrder($aid, $mid, $order, $totalprice);
            }

            Db::name('member_levelup_order')->where('id', $orderId)->update(['status' => 3]);
        });

        $this->sendNotice($aid, $mid, sprintf(
            '您的等级申请已被驳回%s%s',
            $paid && $totalprice > 0 ? '，已支付费用将原路退回' : '',
            '' !== $remark ? '（原因：' . $remark . '）' : ''
        ));
    }

    /**
     * 驳回退款：money→余额返还 / score→积分返还 / wechat|alipay→渠道原路退款
     *
     * @param array<string, mixed> $order
     */
    protected function refundOrder(int $aid, int $mid, array $order, float $totalprice): void
    {
        $paytype = strtolower((string) ($order['paytype'] ?? ''));

        if ('score' === $paytype) {
            $score = (int) round($totalprice);
            $member = Db::name('member')->where('id', $mid)->where('aid', $aid)->find();
            $after  = (int) ($member['score'] ?? 0) + $score;
            Db::name('member')->where('id', $mid)->where('aid', $aid)->update(['score' => $after]);
            Db::name('member_scorelog')->insert([
                'aid'        => $aid,
                'bid'        => 0,
                'mid'        => (string) $mid,
                'score'      => $score,
                'after'      => $after,
                'used'       => 0,
                'createtime' => time(),
                'remark'     => '等级升级驳回返还',
                'type'       => 1,
                'channel'    => 'levelup_refund',
                'status'     => 0,
                'uid'        => 0,
            ]);

            return;
        }

        if ('wechat' === $paytype || 'alipay' === $paytype) {
            $result = $this->payService->refund($order, $totalprice, 'LU' . time() . random_int(1000, 9999));
            if (empty($result['success'])) {
                throw new BusinessException('渠道退款失败，请稍后重试');
            }

            return;
        }

        // 余额支付 / 其他：返还余额
        $member = Db::name('member')->where('id', $mid)->where('aid', $aid)->find();
        $after  = round((float) ($member['money'] ?? 0) + $totalprice, 2);
        Db::name('member')->where('id', $mid)->where('aid', $aid)->update(['money' => $after]);
        Db::name('member_moneylog')->insert([
            'aid'        => $aid,
            'mid'        => $mid,
            'money'      => $totalprice,
            'after'      => $after,
            'createtime' => time(),
            'remark'     => '等级升级驳回返还',
            'paytype'    => 'levelup_refund',
            'uid'        => 0,
        ]);
    }

    /* ================= 内部工具 ================= */

    /**
     * 升级赠券（up_give_coupon JSON：[{id, num}]；库存不足等仅记录不阻断）
     */
    protected function giveCoupons(int $aid, int $mid, string $couponJson): string
    {
        $coupons = json_decode($couponJson, true);
        if (!is_array($coupons) || empty($coupons)) {
            return '';
        }

        $sent = 0;
        foreach ($coupons as $v) {
            $couponId = (int) ($v['id'] ?? 0);
            $num      = (int) ($v['num'] ?? 0);
            if ($couponId <= 0 || $num < 1) {
                continue;
            }

            try {
                $result = $this->couponManageService->send($aid, $couponId, 0, [$mid], [], min($num, 100));
                $sent += (int) ($result['sucnum'] ?? 0);
            } catch (\Throwable) {
                // 库存不足/券停用：不阻断审核主链路
            }
        }

        return $sent > 0 ? '已赠送优惠券 ' . $sent . ' 张' : '';
    }

    /**
     * 会员通知（模板消息队列 wechat_send_tmpl_data，status=0 待发送）
     */
    protected function sendNotice(int $aid, int $mid, string $content): void
    {
        Db::name('wechat_send_tmpl_data')->insert([
            'aid'          => $aid,
            'bid'          => 0,
            'mid'          => $mid,
            'tmpl_name'    => 'levelup',
            'tmpl_content' => $content,
            'm_url'        => '',
            'mdid'         => 0,
            'status'       => 0,
            'type'         => 'levelup',
            'createtime'   => time(),
            'updatetime'   => time(),
        ]);
    }

    /**
     * 表单内容解析：form0~form20「标题^_^值^_^类型」→ [{title, value, type}]
     *
     * @param array<string, mixed> $row
     * @return array<int, array{title: string, value: string, type: string}>
     */
    protected function parseForms(array $row): array
    {
        $result = [];

        for ($i = 0; $i <= 20; $i++) {
            $raw = (string) ($row['form' . $i] ?? '');
            if ('' === $raw) {
                continue;
            }

            $parts = explode('^_^', $raw);
            $result[] = [
                'title' => (string) ($parts[0] ?? ''),
                'value' => (string) ($parts[1] ?? ''),
                'type'  => (string) ($parts[2] ?? 'text'),
            ];
        }

        return $result;
    }

    /**
     * 等级 ID → 名称映射
     *
     * @return array<int, string>
     */
    protected function levelMap(int $aid): array
    {
        $rows = ScopeService::query('member_level', $aid)->field('id, name')->select()->toArray();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['id']] = (string) ($row['name'] ?? '');
        }

        return $map;
    }

    /**
     * 驳回退款摘要（前端提示用）
     *
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    protected function refundInfo(array $order): array
    {
        $totalprice = round((float) ($order['totalprice'] ?? 0), 2);
        $paid       = (int) ($order['status'] ?? 0) === 1 || (int) ($order['paytime'] ?? 0) > 0;

        return [
            'paid'       => $paid,
            'totalprice' => $totalprice,
            'paytype'    => (string) ($order['paytype'] ?? ''),
            'refunded'   => $paid && $totalprice > 0,
        ];
    }
}
