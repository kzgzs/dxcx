<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;

/**
 * 注册赠送服务（批次4-A 任务186）
 *
 * 表口径：register_giveset（UNIQUE aid，单行配置）
 * - money 送余额 / score 送积分 / wanshan_score 完善资料送积分
 * - give_coupon=1 时按 coupon_ids（逗号分隔）逐张发券
 * - starttime/endtime datetime 生效时间段，status 1启用 0停用
 *
 * 触发链（对标参考系统 Common.php::registerGive@381）：
 * - 注册成功（MemberService wx/sms 自动注册、官网注册）→ grantOnRegister
 * - 完善资料（realname+tel+sex+birthday 齐全且未送过）→ grantWanshan（is_wanshan_score 条件更新幂等）
 *
 * 流水口径：余额 member_moneylog（paytype=reggive）/ 积分 member_scorelog（channel=reggive|wanshan）
 */
class GiveService
{
    public function __construct(protected CouponService $couponService)
    {
    }

    /* ================= admin 租户后台侧 ================= */

    /**
     * 读取注册赠送配置（无记录返回全零默认）
     *
     * @return array{money: float, score: int, wanshan_score: int, give_coupon: int, coupon_ids: string, starttime: string, endtime: string, status: int}
     */
    public function config(int $aid): array
    {
        $row = ScopeService::query('register_giveset', $aid)->find();

        return [
            'money'         => (float) ($row['money'] ?? 0),
            'score'         => (int) ($row['score'] ?? 0),
            'wanshan_score' => (int) ($row['wanshan_score'] ?? 0),
            'give_coupon'   => (int) ($row['give_coupon'] ?? 0),
            'coupon_ids'    => (string) ($row['coupon_ids'] ?? ''),
            'starttime'     => (string) ($row['starttime'] ?? ''),
            'endtime'       => (string) ($row['endtime'] ?? ''),
            'status'        => (int) ($row['status'] ?? 0),
        ];
    }

    /**
     * 保存注册赠送配置（按 aid upsert）
     *
     * @param array<string, mixed> $data
     */
    public function saveConfig(int $aid, array $data): void
    {
        $money = round((float) ($data['money'] ?? 0), 2);
        $score = (int) ($data['score'] ?? 0);
        $wanshanScore = (int) ($data['wanshan_score'] ?? 0);
        $giveCoupon = (int) ($data['give_coupon'] ?? 0);
        $couponIds = trim((string) ($data['coupon_ids'] ?? ''));
        $starttime = trim((string) ($data['starttime'] ?? ''));
        $endtime = trim((string) ($data['endtime'] ?? ''));
        $status = (int) ($data['status'] ?? 0);

        if ($money < 0 || $score < 0 || $wanshanScore < 0) {
            throw new BusinessException('赠送数值不能为负数');
        }
        if (!in_array($giveCoupon, [0, 1], true)) {
            throw new BusinessException('送券开关值无效');
        }
        if (!in_array($status, [0, 1], true)) {
            throw new BusinessException('状态值无效');
        }
        if (1 === $giveCoupon && '' === $couponIds) {
            throw new BusinessException('请选择赠送的优惠券');
        }

        // 时间段校验（datetime 字符串，均可空=永久生效）
        $startTs = '' !== $starttime ? strtotime($starttime) : false;
        $endTs   = '' !== $endtime ? strtotime($endtime) : false;

        if ('' !== $starttime && false === $startTs) {
            throw new BusinessException('开始时间格式不正确');
        }
        if ('' !== $endtime && false === $endTs) {
            throw new BusinessException('结束时间格式不正确');
        }
        if (false !== $startTs && false !== $endTs && $endTs <= $startTs) {
            throw new BusinessException('结束时间必须晚于开始时间');
        }

        $update = [
            'money'         => $money,
            'score'         => $score,
            'wanshan_score' => $wanshanScore,
            'give_coupon'   => $giveCoupon,
            'coupon_ids'    => $couponIds,
            'starttime'     => '' !== $starttime ? date('Y-m-d H:i:s', (int) $startTs) : null,
            'endtime'       => '' !== $endtime ? date('Y-m-d H:i:s', (int) $endTs) : null,
            'status'        => $status,
        ];

        $exists = ScopeService::query('register_giveset', $aid)->find();

        if (empty($exists)) {
            $update['aid']        = $aid;
            $update['createtime'] = time();
            Db::name('register_giveset')->insert($update);
        } else {
            Db::name('register_giveset')->where('id', (int) $exists['id'])->update($update);
        }
    }

    /* ================= 注册链路触发 ================= */

    /**
     * 注册成功赠送（开关+时间段校验；余额/积分/券按需发放）
     *
     * @return array{money: float, score: int, coupons: int}
     */
    public function grantOnRegister(int $aid, int $mid): array
    {
        $result = ['money' => 0.0, 'score' => 0, 'coupons' => 0];

        $set = ScopeService::query('register_giveset', $aid)->find();

        if (empty($set) || 1 !== (int) ($set['status'] ?? 0)) {
            return $result;
        }

        if (!$this->inPeriod((string) ($set['starttime'] ?? ''), (string) ($set['endtime'] ?? ''))) {
            return $result;
        }

        // 送余额
        $money = round((float) ($set['money'] ?? 0), 2);

        if ($money > 0) {
            $this->addMoney($aid, $mid, $money, '注册赠送');
            $result['money'] = $money;
        }

        // 送积分
        $score = (int) ($set['score'] ?? 0);

        if ($score > 0) {
            $this->addScore($aid, $mid, $score, '注册赠送', 'reggive');
            $result['score'] = $score;
        }

        // 送券（逐张发放，单张失败跳过不影响其余）
        if (1 === (int) ($set['give_coupon'] ?? 0)) {
            $ids = array_filter(array_map('intval', explode(',', (string) ($set['coupon_ids'] ?? ''))));

            foreach ($ids as $couponId) {
                try {
                    $this->couponService->receive($aid, $mid, $couponId);
                    ++$result['coupons'];
                } catch (\Throwable) {
                    // 券失效/领完/限领：跳过
                }
            }
        }

        return $result;
    }

    /**
     * 完善资料送分（is_wanshan_score=0 条件更新保证只送一次；金额取配置 wanshan_score）
     *
     * 前置：会员 realname/tel/sex/birthday 已全部填写（调用方保证）
     *
     * @return int 实际赠送积分
     */
    public function grantWanshan(int $aid, int $mid): int
    {
        $affected = Db::name('member')
            ->where('id', $mid)
            ->where('aid', $aid)
            ->where('is_wanshan_score', 0)
            ->update(['is_wanshan_score' => 1]);

        if (1 !== $affected) {
            return 0; // 已送过（幂等）
        }

        $set = ScopeService::query('register_giveset', $aid)->find();
        $score = (int) ($set['wanshan_score'] ?? 0);

        if ($score <= 0) {
            return 0;
        }

        $this->addScore($aid, $mid, $score, '完善资料赠送', 'wanshan');

        return $score;
    }

    /* ================= 内部工具 ================= */

    /**
     * 生效时间段判断（datetime 字符串，空=不限）
     */
    protected function inPeriod(string $starttime, string $endtime): bool
    {
        $now = time();

        if ('' !== $starttime && '0000-00-00 00:00:00' !== $starttime) {
            $ts = strtotime($starttime);

            if (false !== $ts && $now < $ts) {
                return false;
            }
        }

        if ('' !== $endtime && '0000-00-00 00:00:00' !== $endtime) {
            $ts = strtotime($endtime);

            if (false !== $ts && $now > $ts) {
                return false;
            }
        }

        return true;
    }

    /**
     * 余额入账（事务行锁 + member_moneylog 流水）
     */
    protected function addMoney(int $aid, int $mid, float $money, string $remark): void
    {
        Db::transaction(function () use ($aid, $mid, $money, $remark) {
            $member = Db::name('member')->where('id', $mid)->where('aid', $aid)->lock(true)->find();

            if (empty($member)) {
                throw new BusinessException('会员不存在', 404, 404);
            }

            $after = round((float) ($member['money'] ?? 0) + $money, 2);

            Db::name('member')->where('id', $mid)->where('aid', $aid)->update(['money' => $after]);

            Db::name('member_moneylog')->insert([
                'aid'        => $aid,
                'mid'        => $mid,
                'money'      => $money,
                'after'      => $after,
                'createtime' => time(),
                'remark'     => $remark,
                'paytype'    => 'reggive',
            ]);
        });
    }

    /**
     * 积分入账（事务行锁 + member_scorelog 流水）
     */
    protected function addScore(int $aid, int $mid, int $score, string $remark, string $channel): void
    {
        Db::transaction(function () use ($aid, $mid, $score, $remark, $channel) {
            $member = Db::name('member')->where('id', $mid)->where('aid', $aid)->lock(true)->find();

            if (empty($member)) {
                throw new BusinessException('会员不存在', 404, 404);
            }

            $after = (int) ($member['score'] ?? 0) + $score;

            Db::name('member')->where('id', $mid)->where('aid', $aid)->update(['score' => $after]);

            Db::name('member_scorelog')->insert([
                'aid'        => $aid,
                'bid'        => 0,
                'mid'        => (string) $mid,
                'score'      => $score,
                'after'      => $after,
                'used'       => 0,
                'createtime' => time(),
                'remark'     => $remark,
                'type'       => 1,
                'channel'    => $channel,
                'status'     => 0,
                'uid'        => 0,
            ]);
        });
    }
}
