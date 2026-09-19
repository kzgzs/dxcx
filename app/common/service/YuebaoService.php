<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;

/**
 * 余额宝服务（P19-3 批次3-F 任务181，完全独立模块）
 *
 * 表口径（对齐 canzhao/upgrade_2.php 450-488）：
 * - member.yuebao_money / member.yuebao_rate  余额宝余额 / 个人收益率（-1=跟随租户）
 * - member_yuebao_moneylog                    流水（type 1收益增加 2提现减少 3转余额减少 4退款返回）
 * - member_yuebao_withdrawlog                 提现（status 0审核中 1已审核 2已驳回 3已打款）
 *
 * 全局开关存 tenant_config（sys_ 前缀，TenantConfigService）：
 * - sys_open_yuebao        总开关（1开 0关）
 * - sys_yuebao_rate        日收益率（%，如 0.05 = 每日万分之五）
 * - sys_yuebao_withdrawmin 最低提现金额（元，0=不限）
 * - sys_yuebao_withdrawfee 提现手续费（%）
 * - sys_yuebao_turn_yue    收益转余额开关（1开 0关）
 *
 * 状态机：0审核中 → 1已审核 → 3已打款；0 → 2已驳回（同事务返还余额宝）。
 * 金额变动一律事务 + 行锁，流水 after 与主表余额同事务落库。
 */
class YuebaoService
{
    /** 流水类型：1收益增加 2提现减少 3转余额减少 4退款返回 */
    public const MONEYLOG_TYPE_TEXT = [
        1 => '收益增加', 2 => '提现减少', 3 => '转余额减少', 4 => '退款返回',
    ];

    /** 提现状态：0审核中 1已审核 2已驳回 3已打款 */
    public const WITHDRAW_STATUS_TEXT = [
        0 => '审核中', 1 => '已审核', 2 => '已驳回', 3 => '已打款',
    ];

    /** 结算 Redis 锁前缀（{aid}:{Ymd}，TTL 一天，防当日重复结算） */
    private const SETTLE_LOCK_PREFIX = 'dxcx:yuebao:settle:';

    /* ================= 1. 租户配置（tenant_config sys_ 前缀） ================= */

    /**
     * 余额宝是否开启
     */
    public static function openOf(int $aid): bool
    {
        return '1' === TenantConfigService::get($aid, 'sys_open_yuebao', '0');
    }

    /**
     * 租户全局日收益率（%）
     */
    public static function tenantRate(int $aid): float
    {
        $rate = (float) TenantConfigService::get($aid, 'sys_yuebao_rate', '0');

        return $rate > 0 ? $rate : 0.0;
    }

    /**
     * 生效收益率（member.yuebao_rate>=0 覆盖租户值；单位 %）
     *
     * @param array<string, mixed> $member
     */
    public static function rateOf(int $aid, array $member): float
    {
        $own = (float) ($member['yuebao_rate'] ?? -1);

        return $own >= 0 ? $own : self::tenantRate($aid);
    }

    /**
     * 读取余额宝设置（B 端系统设置段 / C 端页头展示）
     *
     * @return array{open_yuebao: int, yuebao_rate: float, yuebao_withdrawmin: float, yuebao_withdrawfee: float, yuebao_turn_yue: int}
     */
    public function config(int $aid): array
    {
        return [
            'open_yuebao'        => (int) TenantConfigService::get($aid, 'sys_open_yuebao', '0'),
            'yuebao_rate'        => (float) TenantConfigService::get($aid, 'sys_yuebao_rate', '0'),
            'yuebao_withdrawmin' => (float) TenantConfigService::get($aid, 'sys_yuebao_withdrawmin', '0'),
            'yuebao_withdrawfee' => (float) TenantConfigService::get($aid, 'sys_yuebao_withdrawfee', '0'),
            'yuebao_turn_yue'    => (int) TenantConfigService::get($aid, 'sys_yuebao_turn_yue', '1'),
        ];
    }

    /**
     * 保存余额宝设置（write-through 落 tenant_config）
     *
     * @param array<string, mixed> $data
     */
    public function saveConfig(int $aid, array $data): void
    {
        $open = (int) ($data['open_yuebao'] ?? 0);
        $rate = round((float) ($data['yuebao_rate'] ?? 0), 4);
        $min  = round((float) ($data['yuebao_withdrawmin'] ?? 0), 2);
        $fee  = round((float) ($data['yuebao_withdrawfee'] ?? 0), 2);
        $turn = (int) ($data['yuebao_turn_yue'] ?? 1);

        if ($open !== 0 && $open !== 1) {
            throw new BusinessException('开关取值无效');
        }
        if ($rate < 0 || $rate > 100) {
            throw new BusinessException('日收益率须为 0-100 之间的数值（%）');
        }
        if ($min < 0 || $fee < 0 || $fee > 100) {
            throw new BusinessException('提现最低金额/手续费取值无效');
        }

        TenantConfigService::set($aid, 'sys_open_yuebao', (string) $open);
        TenantConfigService::set($aid, 'sys_yuebao_rate', (string) $rate);
        TenantConfigService::set($aid, 'sys_yuebao_withdrawmin', (string) $min);
        TenantConfigService::set($aid, 'sys_yuebao_withdrawfee', (string) $fee);
        TenantConfigService::set($aid, 'sys_yuebao_turn_yue', (string) ($turn === 1 ? 1 : 0));
    }

    /* ================= 2. 余额宝余额变动（事务+行锁+流水） ================= */

    /**
     * 增减余额宝余额并写流水（money 带符号；返回变动后余额）
     */
    public function addYuebaoMoney(int $aid, int $mid, float $money, string $remark, int $type): float
    {
        $money = round($money, 2);
        if (0.0 === $money) {
            throw new BusinessException('变动金额不能为0');
        }

        $after = 0.0;

        Db::transaction(function () use ($aid, $mid, $money, $remark, $type, &$after) {
            $member = Db::name('member')->where('id', $mid)->where('aid', $aid)->lock(true)->find();
            if (empty($member)) {
                throw new BusinessException('会员不存在', 404, 404);
            }

            $after = round((float) ($member['yuebao_money'] ?? 0) + $money, 2);
            if ($after < 0) {
                throw new BusinessException('余额宝余额不足');
            }

            Db::name('member')->where('id', $mid)->where('aid', $aid)->update(['yuebao_money' => $after]);

            Db::name('member_yuebao_moneylog')->insert([
                'aid'        => $aid,
                'mid'        => $mid,
                'money'      => $money,
                'after'      => $after,
                'type'       => $type,
                'createtime' => time(),
                'remark'     => $remark,
            ]);
        });

        return $after;
    }

    /* ================= 3. 每日收益结算（schedule:run 挂接） ================= */

    /**
     * 扫描开启余额宝的租户，收益 = 余额宝余额 × 日收益率%，入账 + 流水
     *
     * 幂等双保险：
     * - Redis SETNX 租户日锁（TTL 86400s），当日已结算直接跳过
     * - DB 兜底：当日已存在 type=1 收益流水的会员不再入账
     *
     * @return array{tenants: int, settled: int, members: int}
     */
    public function settleDaily(): array
    {
        $dayStart = strtotime(date('Y-m-d'));
        $dayKey   = date('Ymd');

        // 开启余额宝的租户（tenant_config sys_open_yuebao=1 且 aid>0）
        $aids = Db::name('tenant_config')
            ->where('key', 'sys_open_yuebao')
            ->where('value', '1')
            ->column('aid');
        $aids = array_values(array_filter(array_map('intval', $aids), static fn (int $v): bool => $v > 0));

        $redis     = $this->redis();
        $tenantNum = 0;
        $memberNum = 0;

        foreach ($aids as $aid) {
            $rate = self::tenantRate($aid);
            if ($rate <= 0) {
                continue;
            }

            // 租户日锁：当日重复执行直接跳过（幂等）
            if (!$redis->set(self::SETTLE_LOCK_PREFIX . $aid . ':' . $dayKey, (string) time(), ['nx', 'ex' => 86400])) {
                continue;
            }

            ++$tenantNum;

            // 当日已入账会员（DB 兜底，防锁被清后重复入账）
            $settledMids = Db::name('member_yuebao_moneylog')
                ->where('aid', $aid)
                ->where('type', 1)
                ->where('createtime', '>=', $dayStart)
                ->column('mid');
            $settledMids = array_map('intval', $settledMids);

            $members = Db::name('member')
                ->where('aid', $aid)
                ->where('yuebao_money', '>', 0)
                ->field('id,yuebao_money,yuebao_rate')
                ->select()
                ->toArray();

            foreach ($members as $member) {
                $mid = (int) $member['id'];
                if (in_array($mid, $settledMids, true)) {
                    continue;
                }

                $ownRate = (float) $member['yuebao_rate'];
                $useRate = $ownRate >= 0 ? $ownRate : $rate;
                if ($useRate <= 0) {
                    continue;
                }

                $income = round((float) $member['yuebao_money'] * $useRate / 100, 2);
                if ($income <= 0) {
                    continue;
                }

                $this->addYuebaoMoney($aid, $mid, $income, '余额宝收益', 1);
                ++$memberNum;
            }
        }

        return ['tenants' => $tenantNum, 'settled' => $tenantNum, 'members' => $memberNum];
    }

    /* ================= 4. C 端：首页数据 / 明细 / 提现 / 转余额 ================= */

    /**
     * 余额宝页数据（余额/生效收益率/今日收益/累计收益/设置/收款账户）
     *
     * @return array<string, mixed>
     */
    public function info(int $aid, int $mid): array
    {
        $member = ScopeService::query('member', $aid)->where('id', $mid)->find();
        if (empty($member)) {
            throw new BusinessException('会员不存在', 404, 404);
        }

        $dayStart = strtotime(date('Y-m-d'));

        $todayIncome = (float) ScopeService::query('member_yuebao_moneylog', $aid)
            ->where('mid', $mid)
            ->where('type', 1)
            ->where('createtime', '>=', $dayStart)
            ->sum('money');

        $totalIncome = (float) ScopeService::query('member_yuebao_moneylog', $aid)
            ->where('mid', $mid)
            ->where('type', 1)
            ->sum('money');

        return [
            'open'          => self::openOf($aid),
            'yuebao_money'  => round((float) ($member['yuebao_money'] ?? 0), 2),
            'money'         => round((float) ($member['money'] ?? 0), 2),
            'rate'          => self::rateOf($aid, (array) $member),
            'today_income'  => round($todayIncome, 2),
            'total_income'  => round($totalIncome, 2),
            'config'        => $this->config($aid),
            'aliaccount'    => (string) ($member['aliaccount'] ?? ''),
            'bankname'      => (string) ($member['bankname'] ?? ''),
            'bankcarduser'  => (string) ($member['bankcarduser'] ?? ''),
            'bankcardnum'   => (string) ($member['bankcardnum'] ?? ''),
        ];
    }

    /**
     * 我的余额宝记录（st=0 收益明细 / st=1 提现记录）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function records(int $aid, int $mid, int $st, int $page, int $pageSize): array
    {
        $page     = max(1, $page);
        $pageSize = $pageSize > 0 ? min($pageSize, 50) : 20;

        if (1 === $st) {
            $query = ScopeService::query('member_yuebao_withdrawlog', $aid)->where('mid', $mid);
            $total = (clone $query)->count();

            $rows = $query->field('id,money,txmoney,paytype,status,reason,createtime')
                ->order('id', 'desc')
                ->page($page, $pageSize)
                ->select()
                ->toArray();

            $list = array_map(static fn (array $row) => [
                'id'          => (int) $row['id'],
                'money'       => (float) ($row['money'] ?? 0),
                'txmoney'     => (float) ($row['txmoney'] ?? 0),
                'paytype'     => (string) ($row['paytype'] ?? ''),
                'status'      => (int) ($row['status'] ?? 0),
                'status_text' => (string) (self::WITHDRAW_STATUS_TEXT[(int) ($row['status'] ?? 0)] ?? '-'),
                'reason'      => (string) ($row['reason'] ?? ''),
                'createtime'  => (int) ($row['createtime'] ?? 0),
            ], $rows);

            return ['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize];
        }

        $query = ScopeService::query('member_yuebao_moneylog', $aid)->where('mid', $mid);
        $total = (clone $query)->count();

        $rows = $query->field('id,money,after,type,remark,createtime')
            ->order('id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        $list = array_map(static fn (array $row) => [
            'id'         => (int) $row['id'],
            'money'      => (float) ($row['money'] ?? 0),
            'after'      => (float) ($row['after'] ?? 0),
            'type'       => (int) ($row['type'] ?? 0),
            'remark'     => (string) ($row['remark'] ?? ''),
            'createtime' => (int) ($row['createtime'] ?? 0),
        ], $rows);

        return ['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize];
    }

    /**
     * 申请提现（事务：扣余额宝 + 写流水 type=2 + 落提现单 status=0）
     *
     * @return array{id: int, ordernum: string, money: float}
     */
    public function applyWithdraw(int $aid, int $mid, float $money, string $paytype, string $platform = 'wx'): array
    {
        if (!self::openOf($aid)) {
            throw new BusinessException('余额宝功能未开启');
        }

        $config = $this->config($aid);
        $money  = round($money, 2);

        if ($money <= 0) {
            throw new BusinessException('提现金额必须大于0');
        }
        if ($config['yuebao_withdrawmin'] > 0 && $money < $config['yuebao_withdrawmin']) {
            throw new BusinessException('提现金额必须大于' . $config['yuebao_withdrawmin'] . '元');
        }
        if (!in_array($paytype, ['微信钱包', '支付宝', '银行卡'], true)) {
            throw new BusinessException('提现方式无效');
        }

        $result = ['id' => 0, 'ordernum' => '', 'money' => 0.0];

        Db::transaction(function () use ($aid, $mid, $money, $paytype, $platform, $config, &$result) {
            $member = Db::name('member')->where('id', $mid)->where('aid', $aid)->lock(true)->find();
            if (empty($member)) {
                throw new BusinessException('会员不存在', 404, 404);
            }
            if ($money > (float) ($member['yuebao_money'] ?? 0)) {
                throw new BusinessException('可提现余额宝余额不足');
            }

            if ('支付宝' === $paytype && '' === trim((string) ($member['aliaccount'] ?? ''))) {
                throw new BusinessException('请先设置支付宝账号');
            }
            if ('银行卡' === $paytype
                && ('' === trim((string) ($member['bankname'] ?? ''))
                    || '' === trim((string) ($member['bankcarduser'] ?? ''))
                    || '' === trim((string) ($member['bankcardnum'] ?? '')))) {
                throw new BusinessException('请先设置完整银行卡信息');
            }

            $realMoney = round($money * (1 - $config['yuebao_withdrawfee'] / 100), 2);
            if ($realMoney <= 0) {
                throw new BusinessException('提现金额有误');
            }

            $ordernum = date('ymdHis') . $aid . random_int(1000, 9999);

            $record = [
                'aid'        => $aid,
                'mid'        => $mid,
                'createtime' => time(),
                'money'      => $realMoney,
                'txmoney'    => $money,
                'ordernum'   => $ordernum,
                'paytype'    => $paytype,
                'platform'   => $platform,
                'status'     => 0,
            ];
            if ('支付宝' === $paytype) {
                $record['aliaccountname'] = (string) ($member['aliaccountname'] ?? '');
                $record['aliaccount']     = (string) ($member['aliaccount'] ?? '');
            }
            if ('银行卡' === $paytype) {
                $record['bankname']     = trim((string) ($member['bankname'] ?? '') . ' ' . (string) ($member['bankaddress'] ?? ''));
                $record['bankcarduser'] = (string) ($member['bankcarduser'] ?? '');
                $record['bankcardnum']  = (string) ($member['bankcardnum'] ?? '');
            }

            $recordId = (int) Db::name('member_yuebao_withdrawlog')->insertGetId($record);

            // 扣余额宝 + 流水（同事务，锁已持有直接更新）
            $after = round((float) ($member['yuebao_money'] ?? 0) - $money, 2);
            Db::name('member')->where('id', $mid)->where('aid', $aid)->update(['yuebao_money' => $after]);

            Db::name('member_yuebao_moneylog')->insert([
                'aid'        => $aid,
                'mid'        => $mid,
                'money'      => -$money,
                'after'      => $after,
                'type'       => 2,
                'createtime' => time(),
                'remark'     => '余额宝收益提现',
            ]);

            $result = ['id' => $recordId, 'ordernum' => $ordernum, 'money' => $realMoney];
        });

        return $result;
    }

    /**
     * 余额宝收益转余额（事务：余额宝扣减 type=3 + 余额增加 + member_moneylog）
     *
     * @return array{money: float} 变动后账户余额
     */
    public function turnToMoney(int $aid, int $mid, float $money): array
    {
        if (!self::openOf($aid)) {
            throw new BusinessException('余额宝功能未开启');
        }
        if ('1' !== TenantConfigService::get($aid, 'sys_yuebao_turn_yue', '1')) {
            throw new BusinessException('收益转余额功能未开启');
        }

        $money = round($money, 2);
        if ($money <= 0) {
            throw new BusinessException('转入金额必须大于0');
        }

        $afterMoney = 0.0;

        Db::transaction(function () use ($aid, $mid, $money, &$afterMoney) {
            $member = Db::name('member')->where('id', $mid)->where('aid', $aid)->lock(true)->find();
            if (empty($member)) {
                throw new BusinessException('会员不存在', 404, 404);
            }
            if ($money > (float) ($member['yuebao_money'] ?? 0)) {
                throw new BusinessException('可转入余额宝余额不足');
            }

            // 余额宝扣减 + 流水
            $yuebaoAfter = round((float) ($member['yuebao_money'] ?? 0) - $money, 2);
            Db::name('member')->where('id', $mid)->where('aid', $aid)
                ->update(['yuebao_money' => $yuebaoAfter, 'money' => round((float) ($member['money'] ?? 0) + $money, 2)]);

            Db::name('member_yuebao_moneylog')->insert([
                'aid'        => $aid,
                'mid'        => $mid,
                'money'      => -$money,
                'after'      => $yuebaoAfter,
                'type'       => 3,
                'createtime' => time(),
                'remark'     => '余额宝收益转余额',
            ]);

            // 余额增加 + 余额流水
            $afterMoney = round((float) ($member['money'] ?? 0) + $money, 2);
            Db::name('member_moneylog')->insert([
                'aid'        => $aid,
                'mid'        => $mid,
                'money'      => $money,
                'after'      => $afterMoney,
                'createtime' => time(),
                'remark'     => '余额宝收益转余额',
                'paytype'    => 'yuebao',
                'uid'        => 0,
            ]);
        });

        return ['money' => $afterMoney];
    }

    /* ================= 5. B 端：收益记录 / 提现审核状态机 ================= */

    /**
     * 余额宝收益记录列表（B 端）
     * 筛选：昵称/mid/类型/时间
     *
     * @param array<string, mixed> $f
     * @return array{list: array, total: int, page: int, pagesize: int, stat: array}
     */
    public function adminMoneylogList(int $aid, array $f): array
    {
        $nickname = trim((string) ($f['nickname'] ?? ''));
        $mid      = (int) ($f['mid'] ?? 0);
        $type     = trim((string) ($f['type'] ?? ''));
        $page     = max(1, (int) ($f['page'] ?? 1));
        $pageSize = $this->pageSize($f);
        $range    = $this->timeRange((string) ($f['start_date'] ?? ''), (string) ($f['end_date'] ?? ''));

        $query = ScopeService::queryAs('member_yuebao_moneylog', $aid, 'l')
            ->leftJoin('member m', 'm.id = l.mid');

        if ('' !== $nickname) {
            $query->where('m.nickname', 'like', '%' . $nickname . '%');
        }
        if ($mid > 0) {
            $query->where('l.mid', $mid);
        }
        if ('' !== $type) {
            $query->where('l.type', (int) $type);
        }
        if (null !== $range) {
            $query->whereBetween('l.createtime', $range);
        }

        $total = (clone $query)->count();

        $rows = $query->field('l.id, l.mid, l.money, l.after, l.type, l.remark, l.createtime, m.nickname, m.tel')
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
            'type'       => (int) ($row['type'] ?? 0),
            'type_text'  => (string) (self::MONEYLOG_TYPE_TEXT[(int) ($row['type'] ?? 0)] ?? '-'),
            'remark'     => (string) ($row['remark'] ?? ''),
            'createtime' => (int) ($row['createtime'] ?? 0),
        ], $rows);

        $statQuery = ScopeService::query('member_yuebao_moneylog', $aid);
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

    /**
     * 余额宝提现记录列表 + 状态统计（B 端）
     *
     * @param array<string, mixed> $f
     * @return array{list: array, total: int, page: int, pagesize: int, stat: array}
     */
    public function adminWithdrawList(int $aid, array $f): array
    {
        $nickname = trim((string) ($f['nickname'] ?? ''));
        $mid      = (int) ($f['mid'] ?? 0);
        $status   = trim((string) ($f['status'] ?? ''));
        $paytype  = trim((string) ($f['paytype'] ?? ''));
        $page     = max(1, (int) ($f['page'] ?? 1));
        $pageSize = $this->pageSize($f);
        $range    = $this->timeRange((string) ($f['start_date'] ?? ''), (string) ($f['end_date'] ?? ''));

        $query = ScopeService::queryAs('member_yuebao_withdrawlog', $aid, 'w')
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

        $rows = $query->field('w.id, w.mid, w.money, w.txmoney, w.ordernum, w.paytype, w.aliaccount, w.aliaccountname, w.bankname, w.bankcarduser, w.bankcardnum, w.status, w.reason, w.createtime, w.paytime, w.paynum, m.nickname, m.tel')
            ->order('w.id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        $list = array_map(static fn (array $row) => [
            'id'             => (int) $row['id'],
            'mid'            => (int) ($row['mid'] ?? 0),
            'nickname'       => (string) ($row['nickname'] ?? ''),
            'tel'            => (string) ($row['tel'] ?? ''),
            'money'          => (float) ($row['money'] ?? 0),
            'txmoney'        => (float) ($row['txmoney'] ?? 0),
            'ordernum'       => (string) ($row['ordernum'] ?? ''),
            'paytype'        => (string) ($row['paytype'] ?? ''),
            'aliaccount'     => (string) ($row['aliaccount'] ?? ''),
            'aliaccountname' => (string) ($row['aliaccountname'] ?? ''),
            'bankname'       => (string) ($row['bankname'] ?? ''),
            'bankcarduser'   => (string) ($row['bankcarduser'] ?? ''),
            'bankcardnum'    => (string) ($row['bankcardnum'] ?? ''),
            'status'         => (int) ($row['status'] ?? 0),
            'status_text'    => (string) (self::WITHDRAW_STATUS_TEXT[(int) ($row['status'] ?? 0)] ?? '-'),
            'reason'         => (string) ($row['reason'] ?? ''),
            'createtime'     => (int) ($row['createtime'] ?? 0),
            'paytime'        => (int) ($row['paytime'] ?? 0),
            'paynum'         => (string) ($row['paynum'] ?? ''),
        ], $rows);

        // 状态统计
        $statRows = ScopeService::query('member_yuebao_withdrawlog', $aid)
            ->field('status, count(*) as num, ifnull(sum(txmoney),0) as amount')
            ->group('status')
            ->select()
            ->toArray();

        $stat = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'paid' => 0, 'pending_amount' => 0.0];
        foreach ($statRows as $row) {
            $st  = (int) $row['status'];
            $num = (int) $row['num'];
            if (0 === $st) {
                $stat['pending']        = $num;
                $stat['pending_amount'] = round((float) $row['amount'], 2);
            } elseif (1 === $st) {
                $stat['approved'] = $num;
            } elseif (2 === $st) {
                $stat['rejected'] = $num;
            } elseif (3 === $st) {
                $stat['paid'] = $num;
            }
        }

        return ['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize, 'stat' => $stat];
    }

    /**
     * 提现审核（状态机：0 → 1 通过 / 0 → 2 驳回，驳回同事务返还余额宝 + 流水 type=4）
     */
    public function withdrawAudit(int $aid, int $id, int $pass, string $reason, int $uid): void
    {
        if ($id <= 0) {
            throw new BusinessException('缺少提现单ID');
        }
        if (1 !== $pass && 2 !== $pass) {
            throw new BusinessException('审核结果无效');
        }

        Db::transaction(function () use ($aid, $id, $pass, $reason, $uid) {
            $row = Db::name('member_yuebao_withdrawlog')->where('id', $id)->where('aid', $aid)->lock(true)->find();

            if (empty($row)) {
                throw new BusinessException('提现单不存在', 404, 404);
            }
            if (0 !== (int) $row['status']) {
                throw new BusinessException('该提现单已审核，请勿重复操作');
            }

            if (1 === $pass) {
                Db::name('member_yuebao_withdrawlog')->where('id', $id)->update(['status' => 1, 'reason' => '']);

                return;
            }

            // 驳回：返还申请扣减额（txmoney）回余额宝
            $mid     = (int) $row['mid'];
            $txmoney = round((float) $row['txmoney'], 2);

            $member = Db::name('member')->where('id', $mid)->where('aid', $aid)->lock(true)->find();
            if (empty($member)) {
                throw new BusinessException('会员不存在', 404, 404);
            }

            $after = round((float) ($member['yuebao_money'] ?? 0) + $txmoney, 2);
            Db::name('member')->where('id', $mid)->where('aid', $aid)->update(['yuebao_money' => $after]);

            Db::name('member_yuebao_moneylog')->insert([
                'aid'        => $aid,
                'mid'        => $mid,
                'money'      => $txmoney,
                'after'      => $after,
                'type'       => 4,
                'createtime' => time(),
                'remark'     => '余额宝收益提现驳回返还',
            ]);

            Db::name('member_yuebao_withdrawlog')->where('id', $id)->update(['status' => 2, 'reason' => $reason]);
        });
    }

    /**
     * 提现打款（仅 status=1 可打款；mock 即时成功落 status=3 + 转账记录，真实模式预留 V3 转账桩）
     *
     * @return array{mock: bool, status: int}
     */
    public function withdrawPay(int $aid, int $id, int $uid): array
    {
        if ($id <= 0) {
            throw new BusinessException('缺少提现单ID');
        }

        $row = Db::name('member_yuebao_withdrawlog')->where('id', $id)->where('aid', $aid)->find();

        if (empty($row)) {
            throw new BusinessException('提现单不存在', 404, 404);
        }
        if (3 === (int) $row['status']) {
            throw new BusinessException('该笔提现已打款');
        }
        if (1 !== (int) $row['status']) {
            throw new BusinessException('已审核状态才能打款');
        }

        if (!PayService::isMock()) {
            // 真实模式：微信 V3 商家转账桩（TODO，与 FinanceService::withdrawPay 同口径）
            throw new BusinessException('真实微信 V3 转账接入开发中（TODO）', 501, 501);
        }

        $payNum = 'MOCK' . time() . random_int(1000, 9999);

        Db::transaction(function () use ($aid, $id, $row, $payNum) {
            Db::name('member_yuebao_withdrawlog')->where('id', $id)->where('aid', $aid)->where('status', 1)
                ->update(['status' => 3, 'paytime' => time(), 'paynum' => $payNum]);

            Db::name('wx_transfer_log')->insert([
                'aid'              => $aid,
                'mid'              => (int) $row['mid'],
                'ordernum'         => (string) ($row['ordernum'] ?? ''),
                'money'            => (float) ($row['money'] ?? 0),
                'realname'         => (string) ($row['aliaccountname'] ?? ($row['bankcarduser'] ?? '')),
                'desc'             => '余额宝收益提现打款',
                'data_tbl'         => 'member_yuebao_withdrawlog',
                'data_id'          => $id,
                'status_code'      => 'SUCCESS',
                'createtime'       => time(),
                'state'            => 'SUCCESS',
                'transfer_bill_no' => $payNum,
                'message'          => '',
            ]);
        });

        return ['mock' => true, 'status' => 3];
    }

    /* ================= 工具方法 ================= */

    /**
     * 分页大小（默认 10，上限 100）
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
     * Redis 连接（与 ScheduleRun/MemberService 同口径）
     */
    protected function redis(): \Redis
    {
        static $client = null;

        if (null === $client) {
            $client = new \Redis();
            $client->connect((string) env('REDIS.HOST', '127.0.0.1'), (int) env('REDIS.PORT', 6379), 3.0);

            $password = (string) env('REDIS.PASSWORD', '');
            if ('' !== $password) {
                $client->auth($password);
            }
        }

        return $client;
    }
}
