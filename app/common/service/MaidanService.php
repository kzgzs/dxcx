<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;

/**
 * 买单收款服务（P18-2，对标参考系统 Maidan）
 *
 * 表口径：
 * - maidan_order       买单订单：money=消费金额 paymoney=实付 disprice=等级折扣
 *                      scoredk=积分抵扣额 decscore=抵扣积分 couponmoney=券抵扣
 *                      status：1已支付 0未支付；refund_money=已退累计
 * - maidan_refund_order 买单退款单：refund_status 2=成功 5=失败
 *
 * 扣费链路（全部在一个事务内）：
 * 手机号找会员 → 等级折扣(member_level.discount) → 优惠券(coupon_record 校验) →
 * 积分抵扣(admin_set.score2money + scoredkmaxpercent 上限比例) → 扣余额(行锁校验) →
 * 写 maidan_order(paytype 余额支付)。余额/积分变动复用 MemberAdminService / ScoreService 既有方法。
 */
class MaidanService
{
    public function __construct(
        protected MemberAdminService $memberAdminService,
        protected ScoreService $scoreService
    ) {
    }

    /* ================= 会员/券查询 ================= */

    /**
     * 手机号找会员（含等级折扣信息，用于买单扣费页联动）
     *
     * @return array<string, mixed>
     */
    public function memberByTel(int $aid, string $tel): array
    {
        $tel = trim($tel);

        if ('' === $tel) {
            throw new BusinessException('请输入手机号');
        }

        $member = ScopeService::query('member', $aid)->where('tel', $tel)->find();

        if (empty($member)) {
            throw new BusinessException('未找到用户');
        }

        $level = Db::name('member_level')
            ->where('aid', $aid)
            ->where('id', (int) ($member['levelid'] ?? 0))
            ->find();

        $discount = 10.0;

        if (null !== $level && (float) ($level['discount'] ?? 0) > 0 && (float) ($level['discount'] ?? 0) < 10) {
            $discount = (float) $level['discount'];
        }

        return [
            'mid'        => (int) $member['id'],
            'nickname'   => (string) ($member['nickname'] ?? ''),
            'headimg'    => (string) ($member['headimg'] ?? ''),
            'tel'        => (string) ($member['tel'] ?? ''),
            'money'      => round((float) ($member['money'] ?? 0), 2),
            'score'      => (int) ($member['score'] ?? 0),
            'levelid'    => (int) ($member['levelid'] ?? 0),
            'levelname'  => null !== $level ? (string) ($level['name'] ?? '') : '',
            'discount'   => $discount,
        ];
    }

    /**
     * 会员可用优惠券列表（满减型 type=1，status=0 未使用、有效期内、满足门槛）
     *
     * @return array<int, array<string, mixed>>
     */
    public function usableCoupons(int $aid, int $mid, float $money): array
    {
        if ($mid <= 0) {
            return [];
        }

        $now = time();

        $rows = ScopeService::query('coupon_record', $aid)
            ->where('mid', $mid)
            ->where('status', 0)
            ->where('type', 1)
            ->where('starttime', '<=', $now)
            ->where(function ($q) use ($now) {
                $q->where('endtime', '>=', $now)->whereOr('endtime', 0);
            })
            ->order('endtime', 'asc')
            ->select()
            ->toArray();

        $list = [];

        foreach ($rows as $row) {
            if ((float) ($row['minprice'] ?? 0) > $money) {
                continue; // 不满足使用门槛
            }

            $list[] = [
                'id'         => (int) $row['id'],
                'couponname' => (string) ($row['couponname'] ?? ''),
                'money'      => (float) ($row['money'] ?? 0),
                'minprice'   => (float) ($row['minprice'] ?? 0),
                'endtime'    => (int) ($row['endtime'] ?? 0),
            ];
        }

        return $list;
    }

    /* ================= 试算与扣费 ================= */

    /**
     * 买单试算（扣费前预览：折扣/券/积分/实付）
     *
     * @return array<string, mixed>
     */
    public function preview(int $aid, string $tel, float $money, int $couponRid, bool $useScore): array
    {
        $member = $this->memberByTel($aid, $tel);

        if ($money <= 0) {
            throw new BusinessException('支付金额必须大于0');
        }

        // ① 等级折扣
        $disprice = 0.0;

        if ($member['discount'] > 0 && $member['discount'] < 10) {
            $disprice = round($money * (1 - $member['discount'] * 0.1), 2);
        }

        $paymoney = round($money - $disprice, 2);

        // ② 优惠券
        $couponmoney = 0.0;

        if ($couponRid > 0) {
            $record = ScopeService::query('coupon_record', $aid)
                ->where('id', $couponRid)
                ->where('mid', $member['mid'])
                ->find();

            $this->assertCouponUsable($record, $money);

            $couponmoney = (float) ($record['money'] ?? 0);

            if ($couponmoney > $paymoney) {
                $couponmoney = $paymoney;
            }
        }

        $paymoney = max(round($paymoney - $couponmoney, 2), 0.0);

        // ③ 积分抵扣（score2money + scoredkmaxpercent 上限比例）
        $scoredk   = 0.0;
        $decscore  = 0;
        $maxScoreDk = 0.0;

        if ($useScore) {
            [$scoredk, $decscore, $maxScoreDk] = $this->calcScoreDk($aid, $member, $paymoney);
            $paymoney = round($paymoney - $scoredk, 2);
        }

        $paymoney = max($paymoney, 0.0);

        return [
            'member'      => $member,
            'money'       => round($money, 2),
            'disprice'    => $disprice,
            'couponmoney' => $couponmoney,
            'scoredk'     => $scoredk,
            'decscore'    => $decscore,
            'max_scoredk' => $maxScoreDk,
            'paymoney'    => $paymoney,
            'coupons'     => $this->usableCoupons($aid, $member['mid'], $money),
            'enough'      => $paymoney <= $member['money'],
        ];
    }

    /**
     * 买单扣费（余额支付；事务：券占用行锁 + 积分预扣 + 余额行锁扣减 + 落单）
     *
     * @param array<string, mixed> $data {tel, money, couponrid, usescore(0/1), mdid}
     * @return array{orderid: int, ordernum: string, paymoney: float}
     */
    public function pay(int $aid, int $uid, array $data): array
    {
        $tel       = trim((string) ($data['tel'] ?? ''));
        $money     = round((float) ($data['money'] ?? 0), 2);
        $couponRid = (int) ($data['couponrid'] ?? 0);
        $useScore  = (int) ($data['usescore'] ?? 0) === 1;
        $mdid      = (int) ($data['mdid'] ?? 0);

        if ($money <= 0) {
            throw new BusinessException('支付金额必须大于0');
        }

        $preview = $this->preview($aid, $tel, $money, $couponRid, $useScore);
        $member  = $preview['member'];

        if ($preview['paymoney'] > $member['money']) {
            throw new BusinessException('余额不足');
        }

        $now      = time();
        $ordernum = date('ymdHis', $now) . (string) ($aid % 10000) . (string) random_int(1000, 9999);
        $title    = '后台买单收款';

        return Db::transaction(function () use ($aid, $uid, $member, $money, $couponRid, $mdid, $preview, $ordernum, $title, $now) {
            $paymoney   = (float) $preview['paymoney'];
            $couponRidF = (int) $couponRid;

            // ① 优惠券行锁占用并核销（status=0→1）
            if ($couponRidF > 0) {
                $record = Db::name('coupon_record')
                    ->where('id', $couponRidF)
                    ->where('aid', $aid)
                    ->lock(true)
                    ->find();

                $this->assertCouponUsable($record, $money);

                Db::name('coupon_record')->where('id', $couponRidF)->update([
                    'status'  => 1,
                    'usetime' => $now,
                ]);
            }

            // ② 积分抵扣（复用积分服务：行锁+流水）
            $decscore = (int) $preview['decscore'];

            if ($decscore > 0) {
                $this->scoreService->deductForOrder($aid, $member['mid'], $decscore, '买单抵扣 ' . $ordernum);
            }

            // ③ 余额扣减（复用后台充值/扣费：行锁+流水；paymoney=0 时不扣）
            if ($paymoney > 0) {
                $this->memberAdminService->recharge(
                    $aid,
                    $member['mid'],
                    -$paymoney,
                    '后台买单收款 ' . $ordernum,
                    $uid
                );
            }

            // ④ 落买单订单
            $orderid = (int) Db::name('maidan_order')->insertGetId([
                'aid'         => $aid,
                'bid'         => 0,
                'mid'         => $member['mid'],
                'ordernum'    => $ordernum,
                'title'       => $title,
                'money'       => $money,
                'paymoney'    => $paymoney,
                'disprice'    => (float) $preview['disprice'],
                'scoredk'     => (float) $preview['scoredk'],
                'decscore'    => $decscore,
                'decmoney'    => 0,
                'couponrid'   => $couponRidF,
                'couponmoney' => (float) $preview['couponmoney'],
                'createtime'  => $now,
                'paytime'     => $now,
                'paytypeid'   => 1,
                'paytype'     => '余额支付',
                'platform'    => 'admin',
                'status'      => 1,
                'mdid'        => $mdid,
                'uid'         => $uid,
                'remark'      => '',
            ]);

            return ['orderid' => $orderid, 'ordernum' => $ordernum, 'paymoney' => $paymoney];
        });
    }

    /* ================= 记录/退款/导出 ================= */

    /**
     * 买单记录列表（过滤：ordernum/mid/mdid/status/时间区间/昵称）
     *
     * @param array<string, mixed> $f
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function list(int $aid, array $f, int $page, int $pageSize): array
    {
        $query = ScopeService::queryAs('maidan_order', $aid, 'o')
            ->leftJoin('member m', 'm.id = o.mid')
            ->field('o.*, m.nickname as nickname, m.headimg as headimg');

        $ordernum = trim((string) ($f['ordernum'] ?? ''));
        if ('' !== $ordernum) {
            $query->where('o.ordernum', $ordernum);
        }

        $mid = (int) ($f['mid'] ?? 0);
        if ($mid > 0) {
            $query->where('o.mid', $mid);
        }

        $mdid = (int) ($f['mdid'] ?? 0);
        if ($mdid > 0) {
            $query->where('o.mdid', $mdid);
        }

        $status = $f['status'] ?? '';
        if ('' !== $status && null !== $status) {
            $query->where('o.status', (int) $status);
        }

        $nickname = trim((string) ($f['nickname'] ?? ''));
        if ('' !== $nickname) {
            $query->where('m.nickname', 'like', '%' . $nickname . '%');
        }

        $starttime = trim((string) ($f['starttime'] ?? ''));
        $endtime   = trim((string) ($f['endtime'] ?? ''));

        if ('' !== $starttime) {
            $ts = strtotime($starttime);
            if (false !== $ts) {
                $query->where('o.paytime', '>=', $ts);
            }
        }
        if ('' !== $endtime) {
            $ts = strtotime($endtime);
            if (false !== $ts) {
                $query->where('o.paytime', '<', $ts + 86400);
            }
        }

        $total = (clone $query)->count();

        $rows = $query->order('o.id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        $mdArr = ScopeService::query('mendian', $aid)->column('name', 'id');
        $userArr = ScopeService::query('admin_user', $aid)->column('un', 'id');

        foreach ($rows as &$row) {
            $row['mdname'] = $row['mdid'] ? ($mdArr[(int) $row['mdid']] ?? '') : '';
            $row['un'] = $row['uid'] ? ($userArr[(int) $row['uid']] ?? '已失效') : '';
            $row['can_refund_money'] = max(round((float) $row['paymoney'] - (float) ($row['refund_money'] ?? 0), 2), 0.0);
            $row['refund_reason'] = Db::name('maidan_refund_order')
                ->where('orderid', (int) $row['id'])
                ->order('id', 'desc')
                ->value('refund_reason') ?: '';
        }
        unset($row);

        return ['list' => $rows, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize];
    }

    /**
     * 买单退款（余额原路退回；全额退时返还积分并释放优惠券）
     *
     * @return array{refund_id: int}
     */
    public function refund(int $aid, int $uid, int $orderId, float $refundMoney, string $reason): array
    {
        $refundMoney = round($refundMoney, 2);

        if ($refundMoney <= 0) {
            throw new BusinessException('退款金额有误');
        }

        return Db::transaction(function () use ($aid, $uid, $orderId, $refundMoney, $reason) {
            $order = Db::name('maidan_order')
                ->where('aid', $aid)
                ->where('id', $orderId)
                ->lock(true)
                ->find();

            if (empty($order)) {
                throw new BusinessException('买单订单不存在', 404, 404);
            }

            $canRefund = round((float) ($order['paymoney'] ?? 0) - (float) ($order['refund_money'] ?? 0), 2);

            if ($refundMoney > $canRefund) {
                throw new BusinessException('可退款金额不足');
            }

            $now = time();
            $isFull = $refundMoney >= $canRefund;

            $refundId = (int) Db::name('maidan_refund_order')->insertGetId([
                'aid'             => $aid,
                'bid'             => (int) ($order['bid'] ?? 0),
                'mdid'            => (int) ($order['mdid'] ?? 0),
                'mid'             => (int) ($order['mid'] ?? 0),
                'orderid'         => (int) $order['id'],
                'ordernum'        => (string) ($order['ordernum'] ?? ''),
                'title'           => (string) ($order['title'] ?? ''),
                'refund_type'     => 'refund',
                'refund_ordernum' => date('ymdHis', $now) . (string) random_int(100000, 999999),
                'refund_money'    => $refundMoney,
                'refund_reason'   => $reason,
                'createtime'      => $now,
                'refund_time'     => $now,
                'status'          => 1,
                'refund_status'   => 2, // 余额即时到账，直接成功
                'platform'        => 'admin',
                'uid'             => $uid,
            ]);

            // 订单累计退款额
            Db::name('maidan_order')->where('id', (int) $order['id'])->inc('refund_money', $refundMoney)->update();

            // 余额原路退回
            $this->memberAdminService->recharge(
                $aid,
                (int) ($order['mid'] ?? 0),
                $refundMoney,
                '买单退款 ' . (string) ($order['ordernum'] ?? ''),
                $uid
            );

            // 全额退款：返还抵扣积分 + 释放优惠券
            if ($isFull) {
                $decscore = (int) ($order['decscore'] ?? 0);

                if ($decscore > 0) {
                    $this->scoreService->refundForCancel(
                        $aid,
                        (int) ($order['mid'] ?? 0),
                        $decscore,
                        '买单退款返还 ' . (string) ($order['ordernum'] ?? '')
                    );
                }

                $couponRid = (int) ($order['couponrid'] ?? 0);

                if ($couponRid > 0) {
                    Db::name('coupon_record')
                        ->where('id', $couponRid)
                        ->where('aid', $aid)
                        ->update(['status' => 0, 'usetime' => 0]);
                }
            }

            return ['refund_id' => $refundId];
        });
    }

    /**
     * 按ID批量删除买单记录（严格 aid 隔离）
     *
     * @param array<int, int> $ids
     */
    public function deleteByIds(int $aid, array $ids): void
    {
        Db::name('maidan_order')
            ->where('aid', $aid)
            ->whereIn('id', $ids)
            ->delete();
    }

    /**
     * 导出组装（与列表同口径过滤）
     *
     * @param array<string, mixed> $f
     * @return array{0: array<int, string>, 1: array<int, array<int, mixed>>}
     */
    public function exportData(int $aid, array $f, int $limit = 20000): array
    {
        $result = $this->list($aid, $f, 1, $limit);

        $titles = [
            '订单号', '所属门店', '会员信息', '付款金额', '实付金额',
            '会员折扣', '积分抵扣', '优惠券抵扣', '付款方式', '付款时间', '操作员',
        ];

        $rows = [];

        foreach ($result['list'] as $v) {
            $rows[] = [
                (string) ($v['ordernum'] ?? ''),
                (string) ($v['mdname'] ?? ''),
                (string) ($v['nickname'] ?? '') . '(会员ID:' . (int) ($v['mid'] ?? 0) . ')',
                (float) ($v['money'] ?? 0),
                (float) ($v['paymoney'] ?? 0),
                (float) ($v['disprice'] ?? 0),
                (float) ($v['scoredk'] ?? 0),
                (float) ($v['couponmoney'] ?? 0),
                (string) ($v['paytype'] ?? ''),
                $this->formatTime($v['paytime'] ?? null),
                (string) ($v['un'] ?? ''),
            ];
        }

        return [$titles, $rows];
    }

    /* ================= 聚合收款码设置 ================= */

    /**
     * 收款码设置读取（maidan_login/maidan_getlocation/maidan_payaftertourl/maidan_score_discount + H5 买单页链接）
     *
     * @return array<string, mixed>
     */
    public function setOf(int $aid): array
    {
        try {
            $row = ScopeService::query('admin_set', $aid)
                ->field('maidan_login,maidan_getlocation,maidan_payaftertourl,maidan_score_discount,name')
                ->find();
        } catch (\PDOException $e) {
            // 新环境未跑迁移、maidan_score_discount 列不存在时降级查询
            $row = ScopeService::query('admin_set', $aid)
                ->field('maidan_login,maidan_getlocation,maidan_payaftertourl,name')
                ->find();
        }

        $adminRow = Db::name('admin')->where('id', $aid)->field('platform')->find();
        $platformStr = trim((string) ($adminRow['platform'] ?? ''));
        $platformArr = array_filter(array_map('trim', explode(',', $platformStr)));

        $domain = trim((string) env('APP.DOMAIN', ''));

        if ('' === $domain) {
            $domain = request()->domain();
        }

        // H5 买单页链接（微信/支付宝扫码进入）——对标 canzhao: {PRE_URL2}/h5/{aid}.html#/pagesB/maidan/pay
        $h5Url = rtrim($domain, '/') . '/h5/' . $aid . '.html#/pagesB/maidan/pay';

        return [
            'maidan_login'          => (int) ($row['maidan_login'] ?? 1),
            'maidan_getlocation'     => (int) ($row['maidan_getlocation'] ?? 0),
            'maidan_payaftertourl'   => (string) ($row['maidan_payaftertourl'] ?? ''),
            'maidan_score_discount'  => (int) ($row['maidan_score_discount'] ?? 1),
            'h5_url'                 => $h5Url,
            'mp_path'                => 'pagesB/maidan/pay',
            'shop_name'              => (string) ($row['name'] ?? ''),
            'platform_arr'           => $platformArr,
            'show_wx'                => in_array('wx', $platformArr, true),
            'show_alipay'             => in_array('alipay', $platformArr, true),
            'is_platform'            => true,
        ];
    }

    /**
     * 收款码设置保存（白名单四字段）
     *
     * @param array<string, mixed> $data
     */
    public function saveSet(int $aid, array $data): void
    {
        ScopeService::query('admin_set', $aid)->update([
            'maidan_login'          => (int) ($data['maidan_login'] ?? 0) === 1 ? 1 : 0,
            'maidan_getlocation'    => (int) ($data['maidan_getlocation'] ?? 0) === 1 ? 1 : 0,
            'maidan_payaftertourl'  => trim((string) ($data['maidan_payaftertourl'] ?? '')),
            'maidan_score_discount' => (int) ($data['maidan_score_discount'] ?? 0) === 1 ? 1 : 0,
        ]);
    }

    /**
     * 上传域名校验文件（对标 canzhao Maidan::uploadjstxt）
     * 微信txt 放 h5/ 目录；支付宝html 放根目录
     *
     * @param array<string, mixed> $file $_FILES['file'] 结构
     */
    public function uploadJstxt(array $file): string
    {
        if (empty($file['tmp_name'] ?? '')) {
            throw new BusinessException('请选择文件');
        }

        $type = (string) ($file['type'] ?? '');
        if (!in_array($type, ['text/plain', 'text/html'], true)) {
            throw new BusinessException('文件类型错误');
        }

        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        $content = file_get_contents($file['tmp_name']);

        if (false === $content || !preg_match('/^[A-Za-z0-9]+$/', $content)) {
            throw new BusinessException('上传文件不合法,请重新上传');
        }

        $fileName = (string) $file['name'];

        // 安全：禁止路径穿越
        foreach (['../', '..\\', '\\\\', '<?php', '<%', '<?', '%00', "\0"] as $bad) {
            if (false !== strpos($fileName, $bad)) {
                throw new BusinessException('文件名不合法');
            }
        }

        $root = public_path();

        if ('txt' === $ext) {
            $dest = rtrim($root, '/\\') . '/h5/' . $fileName;
        } elseif ('html' === $ext) {
            $dest = rtrim($root, '/\\') . '/' . $fileName;
        } else {
            throw new BusinessException('上传文件不合法,请重新上传');
        }

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            if (!rename($file['tmp_name'], $dest)) {
                throw new BusinessException('上传失败');
            }
        }

        return $dest;
    }

    /* ================= 内部工具 ================= */

    /**
     * 积分抵扣计算：抵扣额 = 会员积分 × score2money，受应付额与上限比例约束
     *
     * @param array<string, mixed> $member
     * @return array{0: float, 1: int, 2: float} [抵扣额, 抵扣积分数, 上限金额]
     */
    protected function calcScoreDk(int $aid, array $member, float $paymoney): array
    {
        $adminset = ScopeService::query('admin_set', $aid)
            ->field('score2money,scoredkmaxpercent')
            ->find();

        $score2money = (float) ($adminset['score2money'] ?? 0);
        $maxPercent  = (float) ($adminset['scoredkmaxpercent'] ?? 100);

        if ($score2money <= 0 || $paymoney <= 0 || (int) ($member['score'] ?? 0) <= 0) {
            return [0.0, 0, 0.0];
        }

        $scoredk = round((int) ($member['score'] ?? 0) * $score2money, 2);

        if ($scoredk > $paymoney) {
            $scoredk = $paymoney;
        }

        $maxMoney = 0.0;

        if ($maxPercent >= 0 && $maxPercent <= 100) {
            $maxMoney = round($paymoney * $maxPercent * 0.01, 2);

            if ($scoredk > $maxMoney) {
                $scoredk = $maxMoney;
            }
        }

        $decscore = (int) ceil($scoredk / $score2money);

        return [round($scoredk, 2), $decscore, $maxMoney];
    }

    /**
     * 优惠券可用性校验（对标参考系统口径）
     *
     * @param array<string, mixed>|null $record
     */
    protected function assertCouponUsable(?array $record, float $money): void
    {
        if (empty($record)) {
            throw new BusinessException('该优惠券不存在');
        }

        if ((int) ($record['status'] ?? -1) !== 0) {
            throw new BusinessException('该优惠券已使用过了');
        }

        $now = time();

        if ((int) ($record['starttime'] ?? 0) > $now) {
            throw new BusinessException('该优惠券尚未开始使用');
        }

        $end = (int) ($record['endtime'] ?? 0);

        if ($end > 0 && $end < $now) {
            throw new BusinessException('该优惠券已过期');
        }

        if ((float) ($record['minprice'] ?? 0) > $money) {
            throw new BusinessException('该优惠券不符合条件');
        }

        if ((int) ($record['type'] ?? 0) !== 1) {
            throw new BusinessException('该优惠券不符合条件');
        }
    }

    /**
     * 时间戳格式化（空值返回空串）
     */
    protected function formatTime(mixed $timestamp): string
    {
        $timestamp = (int) $timestamp;

        return $timestamp > 0 ? date('Y-m-d H:i:s', $timestamp) : '';
    }
}
