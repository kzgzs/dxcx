<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;

/**
 * 积分服务（P5 第二期）
 *
 * 表口径：
 * - member.score   会员积分余额（int，复用基线）
 * - score_log      积分流水：score 带符号 / type 1支付赠送 2下单抵扣 3取消返还 4后台调整 / create_time
 * - tenant_config  租户配置键：score_switch(0/1) / score_ratio(积分/元) / pay_score(支付每满1元赠送积分)
 *
 * 下单抵扣口径（写进计价管道）：
 * - 抵扣额 = floor(use_score / score_ratio * 100) / 100，不超过应付-0.01（实付下限 0.01）
 * - 下单即预扣积分（防多笔未支付订单并发双花），取消（手动+超时命令）原数返还，均幂等
 * - 支付成功按 pay_score × floor(实付) 赠送
 */
class ScoreService
{
    /** 流水类型 */
    public const TYPE_PAY_GIFT     = 1; // 支付赠送
    public const TYPE_ORDER_USE    = 2; // 下单抵扣
    public const TYPE_CANCEL_BACK  = 3; // 取消返还
    public const TYPE_ADMIN_ADJUST = 4; // 后台调整

    /** 配置键与默认值 */
    public const KEY_SWITCH = 'score_switch';
    public const KEY_RATIO  = 'score_ratio';
    public const KEY_PAY    = 'pay_score';

    /* ================= 配置 ================= */

    /**
     * 读取积分配置
     *
     * @return array{score_switch: int, score_ratio: int, pay_score: int}
     */
    public function config(int $aid): array
    {
        return [
            'score_switch' => self::switchOn($aid) ? 1 : 0,
            'score_ratio'  => self::ratioOf($aid),
            'pay_score'    => self::payScoreOf($aid),
        ];
    }

    /**
     * 保存积分配置（ratio≥1 整数，pay_score≥0 整数）
     *
     * @param array<string, mixed> $data
     */
    public function saveConfig(int $aid, array $data): void
    {
        foreach (['score_switch', 'score_ratio', 'pay_score'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new BusinessException('缺少配置项 ' . $key);
            }
        }

        $switch = $data['score_switch'];

        if (!in_array((int) $switch, [0, 1], true) || (string) (int) $switch !== (string) $switch) {
            throw new BusinessException('积分开关须为 0 或 1');
        }

        $ratio = $data['score_ratio'];

        if (!is_numeric($ratio) || (int) $ratio != $ratio || (int) $ratio < 1) {
            throw new BusinessException('积分比例须为不小于1的整数');
        }

        $payScore = $data['pay_score'];

        if (!is_numeric($payScore) || (int) $payScore != $payScore || (int) $payScore < 0) {
            throw new BusinessException('支付赠送积分须为不小于0的整数');
        }

        TenantConfigService::set($aid, self::KEY_SWITCH, (string) (int) $switch);
        TenantConfigService::set($aid, self::KEY_RATIO, (string) (int) $ratio);
        TenantConfigService::set($aid, self::KEY_PAY, (string) (int) $payScore);
    }

    /**
     * 积分抵扣开关是否开启
     */
    public static function switchOn(int $aid): bool
    {
        return '1' === TenantConfigService::get($aid, self::KEY_SWITCH, '0');
    }

    /**
     * 积分抵扣比例（多少积分抵 1 元；未配置/非法返回 0 表示不可抵扣）
     */
    public static function ratioOf(int $aid): int
    {
        $ratio = (int) TenantConfigService::get($aid, self::KEY_RATIO, '0');

        return $ratio >= 1 ? $ratio : 0;
    }

    /**
     * 支付每满 1 元赠送积分数
     */
    public static function payScoreOf(int $aid): int
    {
        $payScore = (int) TenantConfigService::get($aid, self::KEY_PAY, '0');

        return $payScore >= 0 ? $payScore : 0;
    }

    /* ================= admin 租户后台侧 ================= */

    /**
     * 会员积分列表（keyword 模糊匹配手机号）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function memberList(int $aid, string $keyword, int $page, int $pageSize): array
    {
        $query = ScopeService::query('member', $aid);

        if ('' !== $keyword) {
            $query->where('tel', 'like', '%' . $keyword . '%');
        }

        $total = (clone $query)->count();

        $rows = $query->field('id, tel, score')
            ->order('id', 'asc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        $list = array_map(static fn (array $row) => [
            'mid'   => (int) $row['id'],
            'phone' => (string) ($row['tel'] ?? ''),
            'score' => (int) ($row['score'] ?? 0),
        ], $rows);

        return ['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize];
    }

    /**
     * 后台调整积分（整数可负；余额不足扣负拒绝；流水 type=4）
     */
    public function adjust(int $aid, int $mid, int $score, string $remark): void
    {
        if ($mid <= 0) {
            throw new BusinessException('缺少会员ID');
        }
        if (0 === $score) {
            throw new BusinessException('调整积分不能为0');
        }
        if ('' === trim($remark)) {
            throw new BusinessException('请填写调整备注');
        }

        $member = ScopeService::query('member', $aid)->where('id', $mid)->find();

        if (empty($member)) {
            throw new BusinessException('会员不存在', 404, 404);
        }

        $this->change($aid, $mid, $score, self::TYPE_ADMIN_ADJUST, trim($remark));
    }

    /**
     * 积分流水（admin：可指定会员；含会员手机号）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function adminLog(int $aid, int $mid, int $page, int $pageSize): array
    {
        $query = ScopeService::queryAs('score_log', $aid, 'l')
            ->leftJoin('member m', 'm.id = l.mid')
            ->field('l.*, m.tel as phone');

        if ($mid > 0) {
            $query->where('l.mid', $mid);
        }

        $total = (clone $query)->count();

        $rows = $query->order('l.id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        return [
            'list'     => array_map(fn (array $row) => $this->formatLog($row), $rows),
            'total'    => $total,
            'page'     => $page,
            'pagesize' => $pageSize,
        ];
    }

    /* ================= api 会员侧 ================= */

    /**
     * 我的积分余额
     *
     * @return array{score: int}
     */
    public function balance(int $aid, int $mid): array
    {
        return ['score' => $this->scoreOf($aid, $mid)];
    }

    /**
     * 我的积分流水
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function myLog(int $aid, int $mid, int $page, int $pageSize): array
    {
        $query = ScopeService::query('score_log', $aid)->where('mid', $mid);

        $total = (clone $query)->count();

        $rows = $query->order('id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        return [
            'list'     => array_map(fn (array $row) => $this->formatLog($row), $rows),
            'total'    => $total,
            'page'     => $page,
            'pagesize' => $pageSize,
        ];
    }

    /* ================= 订单联动 ================= */

    /**
     * 下单抵扣预扣积分（余额不足拒绝；流水 type=2）
     */
    public function deductForOrder(int $aid, int $mid, int $score, string $remark): void
    {
        $this->change($aid, $mid, -$score, self::TYPE_ORDER_USE, $remark);
    }

    /**
     * 取消订单返还抵扣积分（流水 type=3）
     */
    public function refundForCancel(int $aid, int $mid, int $score, string $remark): void
    {
        if ($score <= 0) {
            return;
        }

        $this->change($aid, $mid, $score, self::TYPE_CANCEL_BACK, $remark);
    }

    /**
     * 支付成功赠送积分（pay_score × floor(实付)；流水 type=1；0 分不写流水）
     */
    public function grantForPay(int $aid, int $mid, float $payAmount, string $remark): void
    {
        if (!self::switchOn($aid)) {
            return;
        }

        $payScore = self::payScoreOf($aid);

        if ($payScore <= 0) {
            return;
        }

        $gift = (int) floor($payAmount) * $payScore;

        if ($gift <= 0) {
            return;
        }

        $this->change($aid, $mid, $gift, self::TYPE_PAY_GIFT, $remark);
    }

    /* ================= 内部工具 ================= */

    /**
     * 读取会员积分余额（会员不存在抛 404）
     */
    protected function scoreOf(int $aid, int $mid): int
    {
        $score = ScopeService::query('member', $aid)->where('id', $mid)->value('score');

        if (null === $score) {
            throw new BusinessException('会员不存在', 404, 404);
        }

        return (int) $score;
    }

    /**
     * 积分变动核心（事务行锁；扣减时余额不足拒绝；同步落流水）
     */
    protected function change(int $aid, int $mid, int $delta, int $type, string $remark): void
    {
        if (0 === $delta) {
            return;
        }

        Db::transaction(function () use ($aid, $mid, $delta, $type, $remark) {
            $member = Db::name('member')
                ->where('id', $mid)
                ->where('aid', $aid)
                ->lock(true)
                ->find();

            if (empty($member)) {
                throw new BusinessException('会员不存在', 404, 404);
            }

            if ($delta < 0 && (int) ($member['score'] ?? 0) + $delta < 0) {
                throw new BusinessException('积分余额不足');
            }

            Db::name('member')->where('id', $mid)->where('aid', $aid)->inc('score', $delta)->update();

            Db::name('score_log')->insert([
                'aid'         => $aid,
                'mid'         => $mid,
                'score'       => $delta,
                'remark'      => $remark,
                'type'        => $type,
                'create_time' => time(),
            ]);
        });
    }

    /**
     * 流水行格式化
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function formatLog(array $row): array
    {
        return [
            'id'          => (int) ($row['id'] ?? 0),
            'mid'         => (int) ($row['mid'] ?? 0),
            'phone'       => (string) ($row['phone'] ?? ''),
            'score'       => (int) ($row['score'] ?? 0),
            'remark'      => (string) ($row['remark'] ?? ''),
            'type'        => (int) ($row['type'] ?? 0),
            'create_time' => (int) ($row['create_time'] ?? 0),
        ];
    }

    /**
     * 时间戳格式化（空值返回空串）
     */
    protected function formatTime(mixed $timestamp): string
    {
        $timestamp = (int) $timestamp;


    }

    /* ================= 后台积分明细（任务对齐点大 score/scorelog.html） ================= */

    /**
     * 积分明细列表（搜索 mid/nickname/时间/remark；含会员头像/昵称/变更后剩余/操作显隐）
     *
     * @param array<string, mixed> $f
     * @return array{list: array, total: int, page: int, pagesize: int, stat: array}
     */
    public function adminScoreLogList(int $aid, array $f): array
    {
        $mid      = (int) ($f['mid'] ?? 0);
        $nickname = trim((string) ($f['nickname'] ?? ''));
        $remark   = trim((string) ($f['remark'] ?? ''));
        $page     = max(1, (int) ($f['page'] ?? 1));
        $pageSize = $this->pageSize($f);
        $range    = $this->scoreTimeRange((string) ($f['start_date'] ?? ''), (string) ($f['end_date'] ?? ''));

        $query = ScopeService::queryAs('score_log', $aid, 'l')
            ->leftJoin('member m', 'm.id = l.mid');

        if ($mid > 0) {
            $query->where('l.mid', $mid);
        }
        if ('' !== $nickname) {
            $query->where('m.nickname', 'like', '%' . $nickname . '%');
        }
        if ('' !== $remark) {
            $query->where('l.remark', 'like', '%' . $remark . '%');
        }
        if (null !== $range) {
            $query->whereBetween('l.create_time', $range);
        }

        $total = (clone $query)->count();

        // 变更后剩余：按会员累计到当前行（id<=l.id）的积分，如实计算
        $afterSql = '(SELECT IFNULL(SUM(s2.score),0) FROM score_log s2 WHERE s2.mid=l.mid AND s2.aid=l.aid AND s2.id<=l.id)';

        $rows = $query->field('l.id, l.mid, l.score, l.remark, l.type, l.is_cancel, l.create_time, m.nickname, m.headimg, m.tel')
            ->fieldRaw("$afterSql as after_score")
            ->order('l.id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        $list = array_map(static fn (array $row) => [
            'id'           => (int) ($row['id'] ?? 0),
            'mid'          => (int) ($row['mid'] ?? 0),
            'nickname'     => (string) ($row['nickname'] ?? ''),
            'headimg'      => (string) ($row['headimg'] ?? ''),
            'tel'          => (string) ($row['tel'] ?? ''),
            'score'        => (int) ($row['score'] ?? 0),
            'after_score'  => (int) ($row['after_score'] ?? 0),
            'remark'       => (string) ($row['remark'] ?? ''),
            'type'         => (int) ($row['type'] ?? 0),
            'create_time'  => (int) ($row['create_time'] ?? 0),
            'is_cancel'    => (int) ($row['is_cancel'] ?? 0),
            // 点大操作员：score_log 无 uid 列，返回空串
            'un'           => '',
            // 行[撤销] 显隐：未撤销才允许；[删除] 恒显
            'can_cancel'   => 0 === (int) ($row['is_cancel'] ?? 0),
            'can_delete'   => true,
        ], $rows);

        return ['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize, 'stat' => ['count' => $total]];
    }

    /**
     * 积分明细批量删除（aid 租户隔离逐条校验）
     *
     * @param array<int, int|string> $ids
     */
    public function deleteScoreLog(int $aid, array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) {
            throw new BusinessException('请选择要删除的记录');
        }
        $n = 0;
        foreach ($ids as $id) {
            $exist = Db::name('score_log')->where('id', $id)->where('aid', $aid)->find();
            if (!empty($exist)) {
                Db::name('score_log')->where('id', $id)->where('aid', $aid)->delete();
                ++$n;
            }
        }
        return $n;
    }

    /**
     * 积分撤销（对齐点大 Score::cancel）：未撤销(is_cancel=0)的流水，
     * 标记 is_cancel=1 + 反向扣/加会员积分 + 落一条 type=3「撤销操作」流水
     *
     * @param array<int, int|string> $ids
     */
    public function cancelScoreLog(int $aid, array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) {
            throw new BusinessException('请选择要撤销的记录');
        }
        $n = 0;
        foreach ($ids as $id) {
            $done = Db::transaction(function () use ($aid, $id) {
                $row = Db::name('score_log')->where('id', $id)->where('aid', $aid)->lock(true)->find();
                if (empty($row)) {
                    return false;
                }
                if ((int) ($row['is_cancel'] ?? 0) === 1) {
                    return false; // 已撤销不重复
                }
                $delta = -(int) $row['score']; // 反向
                $mid   = (int) $row['mid'];

                if ($delta < 0) {
                    $memberScore = (int) Db::name('member')->where('id', $mid)->where('aid', $aid)->value('score');
                    if ($memberScore + $delta < 0) {
                        throw new BusinessException("会员#{$mid} 积分不足，无法撤销");
                    }
                }

                Db::name('member')->where('id', $mid)->where('aid', $aid)->inc('score', $delta)->update();

                Db::name('score_log')->where('id', $id)->where('aid', $aid)->update(['is_cancel' => 1]);

                Db::name('score_log')->insert([
                    'aid'         => $aid,
                    'mid'         => $mid,
                    'score'       => $delta,
                    'remark'      => '撤销操作',
                    'type'        => 3,
                    'is_cancel'   => 1,
                    'create_time' => time(),
                ]);
                return true;
            });
            if ($done) {
                ++$n;
            }
        }
        return $n;
    }

    /** 积分明细导出 */
    public function scoreLogExport(int $aid, array $f): array
    {
        $f['pagesize'] = 50000;
        $f['page']     = 1;
        $data          = $this->adminScoreLogList($aid, $f);
        $titles        = ['ID', '会员ID', '会员信息', '变更积分', '变更后剩余', '变更时间', '备注', '操作员'];
        $rows = [];
        foreach ($data['list'] as $v) {
            $rows[] = [$v['id'], $v['mid'], $v['nickname'], $v['score'], $v['after_score'], date('Y-m-d H:i:s', $v['create_time']), $v['remark'], $v['un']];
        }
        return [$titles, $rows];
    }

    /** 分页大小 */
    protected function pageSize(array $f): int
    {
        return max(1, min(200, (int) ($f['pagesize'] ?? 10)));
    }

    /** 时间范围 */
    protected function scoreTimeRange(string $start, string $end): ?array
    {
        $start = trim($start);
        $end   = trim($end);
        if ('' === $start && '' === $end) {
            return null;
        }
        $s = '' !== $start ? strtotime($start) : 0;
        $e = '' !== $end ? strtotime($end . ' 23:59:59') : PHP_INT_MAX;
        return [$s, $e];
    }
}

