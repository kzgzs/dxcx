<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;

/**
 * 会员管理服务（admin 租户后台侧，任务138）
 *
 * 表口径：
 * - member            会员主表（money decimal 余额 / score int 积分 / isfreeze 冻结 / islock 锁定）
 * - member_level      会员等级（discount：10=无折扣，9.5=95折）
 * - member_moneylog   余额流水（mid int / money 带符号 / after 变动后余额）
 * - member_scorelog   积分流水（mid varchar(100)，写入须转字符串 / score 带符号 / after 变动后积分）
 * - member_levelup_order 等级变动记录（后台改级同事务写入）
 *
 * 金额/积分变动一律事务 + 行锁，流水 after 值与主表余额同事务落库；
 * 扣款（负数）校验变动后余额/积分不为负，否则拒绝。
 */
class MemberAdminService
{
    /* ================= 会员列表 / 详情 ================= */

    /**
     * 会员列表
     * 筛选：keyword（id|nickname|tel|realname like）、levelid、注册时间范围
     * 默认 id desc
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function list(int $aid, string $keyword, int $levelId, string $startTime, string $endTime, int $page, int $pageSize): array
    {
        $query = ScopeService::query('member', $aid);

        if ('' !== $keyword) {
            $like = '%' . $keyword . '%';
            $query->where(function ($q) use ($like, $keyword) {
                $q->whereOr('nickname', 'like', $like)
                    ->whereOr('tel', 'like', $like)
                    ->whereOr('realname', 'like', $like);
                if (ctype_digit($keyword)) {
                    $q->whereOr('id', (int) $keyword);
                }
            });
        }

        if ($levelId > 0) {
            $query->where('levelid', $levelId);
        }

        $range = $this->timeRange($startTime, $endTime);
        if (null !== $range) {
            $query->whereBetween('createtime', $range);
        }

        $total = (clone $query)->count();

        $rows = $query->field('id, headimg, nickname, realname, tel, levelid, money, score, buynum, buymoney, createtime, last_visittime, isfreeze, islock, remark')
            ->order('id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        $levelMap = $this->levelNameMap($aid);

        $list = array_map(static function (array $row) use ($levelMap) {
            return [
                'id'             => (int) $row['id'],
                'headimg'        => (string) ($row['headimg'] ?? ''),
                'nickname'       => (string) ($row['nickname'] ?? ''),
                'realname'       => (string) ($row['realname'] ?? ''),
                'tel'            => (string) ($row['tel'] ?? ''),
                'levelid'        => (int) ($row['levelid'] ?? 0),
                'level_name'     => (string) ($levelMap[(int) ($row['levelid'] ?? 0)] ?? ''),
                'money'          => (float) ($row['money'] ?? 0),
                'score'          => (int) ($row['score'] ?? 0),
                'buynum'         => (int) ($row['buynum'] ?? 0),
                'buymoney'       => (float) ($row['buymoney'] ?? 0),
                'createtime'     => (int) ($row['createtime'] ?? 0),
                'last_visittime' => (int) ($row['last_visittime'] ?? 0),
                'isfreeze'       => (int) ($row['isfreeze'] ?? 0),
                'islock'         => (int) ($row['islock'] ?? 0),
                'remark'         => (string) ($row['remark'] ?? ''),
            ];
        }, $rows);

        return ['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize];
    }

    /**
     * 会员详情（基本信息 + 各计数）
     *
     * @return array<string, mixed>
     */
    public function detail(int $aid, int $mid): array
    {
        $member = $this->findMember($aid, $mid);

        $levelMap = $this->levelNameMap($aid);

        $orderStat = ScopeService::query('shop_order', $aid)
            ->where('mid', $mid)
            ->where('delete', 0)
            ->field('count(*) as cnt, ifnull(sum(totalprice),0) as amount')
            ->find();

        $addressCount = ScopeService::query('member_address', $aid)
            ->where('mid', (string) $mid)
            ->where('deleted', 0)
            ->count();

        return [
            'id'             => (int) $member['id'],
            'headimg'        => (string) ($member['headimg'] ?? ''),
            'nickname'       => (string) ($member['nickname'] ?? ''),
            'realname'       => (string) ($member['realname'] ?? ''),
            'tel'            => (string) ($member['tel'] ?? ''),
            'sex'            => (string) ($member['sex'] ?? ''),
            'levelid'        => (int) ($member['levelid'] ?? 0),
            'level_name'     => (string) ($levelMap[(int) ($member['levelid'] ?? 0)] ?? ''),
            'levelendtime'   => (int) ($member['levelendtime'] ?? 0),
            'money'          => (float) ($member['money'] ?? 0),
            'score'          => (int) ($member['score'] ?? 0),
            'buynum'         => (int) ($member['buynum'] ?? 0),
            'buymoney'       => (float) ($member['buymoney'] ?? 0),
            'createtime'     => (int) ($member['createtime'] ?? 0),
            'last_visittime' => (int) ($member['last_visittime'] ?? 0),
            'last_buytime'   => (int) ($member['last_buytime'] ?? 0),
            'isfreeze'       => (int) ($member['isfreeze'] ?? 0),
            'islock'         => (int) ($member['islock'] ?? 0),
            'remark'         => (string) ($member['remark'] ?? ''),
            'platform'       => (string) ($member['platform'] ?? ''),
            'order_count'    => (int) ($orderStat['cnt'] ?? 0),
            'order_amount'   => (float) ($orderStat['amount'] ?? 0),
            'address_count'  => (int) $addressCount,
        ];
    }

    /* ================= 编辑 / 状态 ================= */

    /**
     * 编辑会员（昵称/手机/等级/等级到期/备注）
     * 等级变更同事务写 member_levelup_order 变动记录
     *
     * @param array<string, mixed> $data nickname / tel / levelid / levelendtime(YYYY-MM-DD 或空) / remark
     */
    public function edit(int $aid, int $mid, array $data): void
    {
        if ($mid <= 0) {
            throw new BusinessException('缺少会员ID');
        }

        $nickname = trim((string) ($data['nickname'] ?? ''));
        $tel      = trim((string) ($data['tel'] ?? ''));
        $levelId  = (int) ($data['levelid'] ?? -1);
        $remark   = trim((string) ($data['remark'] ?? ''));
        $endStr   = trim((string) ($data['levelendtime'] ?? ''));

        if ('' === $nickname) {
            throw new BusinessException('昵称不能为空');
        }
        if ('' !== $tel && 1 !== preg_match('/^1\d{10}$/', $tel)) {
            throw new BusinessException('手机号格式不正确');
        }

        $member = $this->findMember($aid, $mid);

        $update = [
            'nickname' => $nickname,
            'tel'      => $tel,
            'remark'   => $remark,
        ];

        $levelChanged = $levelId >= 0 && $levelId !== (int) ($member['levelid'] ?? 0);
        $endTime      = (int) ($member['levelendtime'] ?? 0);

        if ($levelId >= 0) {
            if ($levelId > 0) {
                $exists = ScopeService::query('member_level', $aid)->where('id', $levelId)->find();
                if (empty($exists)) {
                    throw new BusinessException('所选会员等级不存在');
                }
            }
            $update['levelid'] = $levelId;
            if ('' !== $endStr) {
                $ts = strtotime($endStr);
                if (false === $ts) {
                    throw new BusinessException('等级到期时间格式不正确');
                }
                $endTime              = $ts + 86399;
                $update['levelendtime'] = $endTime;
            } elseif ($levelChanged) {
                $endTime                = 0;
                $update['levelendtime'] = 0;
            }
        }

        Db::transaction(function () use ($aid, $mid, $update, $levelChanged, $member, $levelId) {
            Db::name('member')->where('id', $mid)->where('aid', $aid)->update($update);

            if ($levelChanged) {
                $beforeId = (int) ($member['levelid'] ?? 0);
                Db::name('member')->where('id', $mid)->where('aid', $aid)->update(['levelstarttime' => time()]);
                Db::name('member_levelup_order')->insert([
                    'aid'           => $aid,
                    'mid'           => $mid,
                    'levelid'       => $levelId,
                    'beforelevelid' => $beforeId,
                    'ordernum'      => '',
                    'totalprice'    => 0,
                    'title'         => '后台调整会员等级',
                    'status'        => 1,
                    'createtime'    => time(),
                    'levelup_time'  => time(),
                    'type'          => $levelId >= $beforeId ? 0 : 1,
                    'platform'      => 'admin',
                ]);
            }
        });
    }

    /**
     * 切换冻结状态（isfreeze 0/1 翻转）
     *
     * @return array{isfreeze: int}
     */
    public function freeze(int $aid, int $mid): array
    {
        $member = $this->findMember($aid, $mid);
        $next   = (int) ($member['isfreeze'] ?? 0) === 1 ? 0 : 1;

        Db::name('member')->where('id', $mid)->where('aid', $aid)->update(['isfreeze' => $next]);

        return ['isfreeze' => $next];
    }

    /**
     * 切换锁定状态（islock 0/1 翻转）
     *
     * @return array{islock: int}
     */
    public function lock(int $aid, int $mid): array
    {
        $member = $this->findMember($aid, $mid);
        $next   = (int) ($member['islock'] ?? 0) === 1 ? 0 : 1;

        Db::name('member')->where('id', $mid)->where('aid', $aid)->update(['islock' => $next]);

        return ['islock' => $next];
    }

    /* ================= 余额 / 积分变动 ================= */

    /**
     * 后台充值/扣费（money 正=充值 负=扣款）
     * 事务内行锁校验扣款后余额不为负；member.money 与 member_moneylog 同事务落库
     *
     * @return array{money: float} 变动后余额
     */
    public function recharge(int $aid, int $mid, float $money, string $remark, int $uid): array
    {
        if ($mid <= 0) {
            throw new BusinessException('缺少会员ID');
        }
        if (0.0 === round($money, 2)) {
            throw new BusinessException('变动金额不能为0');
        }

        $money  = round($money, 2);
        $remark = '' !== trim($remark) ? trim($remark) : ($money > 0 ? '后台充值' : '后台扣费');

        $after = 0.0;

        Db::transaction(function () use ($aid, $mid, $money, $remark, $uid, &$after) {
            $member = Db::name('member')
                ->where('id', $mid)
                ->where('aid', $aid)
                ->lock(true)
                ->find();

            if (empty($member)) {
                throw new BusinessException('会员不存在', 404, 404);
            }

            $after = round((float) ($member['money'] ?? 0) + $money, 2);

            if ($after < 0) {
                throw new BusinessException('余额不足，扣款后余额不能为负');
            }

            Db::name('member')->where('id', $mid)->where('aid', $aid)->update(['money' => $after]);

            Db::name('member_moneylog')->insert([
                'aid'        => $aid,
                'mid'        => $mid,
                'money'      => $money,
                'after'      => $after,
                'createtime' => time(),
                'remark'     => $remark,
                'paytype'    => 'admin',
                'uid'        => $uid,
            ]);
        });

        return ['money' => $after];
    }

    /**
     * 后台积分调整（score 正负整数）
     * 事务内行锁校验扣减后积分不为负；member.score 与 member_scorelog 同事务落库
     *
     * @return array{score: int} 变动后积分
     */
    public function addScore(int $aid, int $mid, int $score, string $remark, int $uid): array
    {
        if ($mid <= 0) {
            throw new BusinessException('缺少会员ID');
        }
        if (0 === $score) {
            throw new BusinessException('调整积分不能为0');
        }

        $remark = '' !== trim($remark) ? trim($remark) : ($score > 0 ? '后台加积分' : '后台扣积分');

        $after = 0;

        Db::transaction(function () use ($aid, $mid, $score, $remark, $uid, &$after) {
            $member = Db::name('member')
                ->where('id', $mid)
                ->where('aid', $aid)
                ->lock(true)
                ->find();

            if (empty($member)) {
                throw new BusinessException('会员不存在', 404, 404);
            }

            $after = (int) ($member['score'] ?? 0) + $score;

            if ($after < 0) {
                throw new BusinessException('积分不足，扣减后积分不能为负');
            }

            Db::name('member')->where('id', $mid)->where('aid', $aid)->update(['score' => $after]);

            // member_scorelog.mid 为 varchar(100)，写入须转字符串
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
                'channel'    => 'admin',
                'status'     => 0,
                'uid'        => $uid,
            ]);
        });

        return ['score' => $after];
    }

    /* ================= 明细列表 ================= */

    /**
     * 余额流水（member_moneylog）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function moneyLog(int $aid, int $mid, int $page, int $pageSize): array
    {
        $query = ScopeService::query('member_moneylog', $aid);

        if ($mid > 0) {
            $query->where('mid', $mid);
        }

        $total = (clone $query)->count();

        $rows = $query->order('id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        $list = array_map(static fn (array $row) => [
            'id'         => (int) $row['id'],
            'mid'        => (int) ($row['mid'] ?? 0),
            'money'      => (float) ($row['money'] ?? 0),
            'after'      => (float) ($row['after'] ?? 0),
            'remark'     => (string) ($row['remark'] ?? ''),
            'paytype'    => (string) ($row['paytype'] ?? ''),
            'createtime' => (int) ($row['createtime'] ?? 0),
        ], $rows);

        return ['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize];
    }

    /**
     * 积分流水（member_scorelog，mid 为 varchar）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function scoreLog(int $aid, int $mid, int $page, int $pageSize): array
    {
        $query = ScopeService::query('member_scorelog', $aid);

        if ($mid > 0) {
            $query->where('mid', (string) $mid);
        }

        $total = (clone $query)->count();

        $rows = $query->order('id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        $list = array_map(static fn (array $row) => [
            'id'         => (int) $row['id'],
            'mid'        => (int) ($row['mid'] ?? 0),
            'score'      => (int) ($row['score'] ?? 0),
            'after'      => (int) ($row['after'] ?? 0),
            'remark'     => (string) ($row['remark'] ?? ''),
            'channel'    => (string) ($row['channel'] ?? ''),
            'createtime' => (int) ($row['createtime'] ?? 0),
        ], $rows);

        return ['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize];
    }

    /**
     * 会员订单（shop_order，按 mid 过滤）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function orders(int $aid, int $mid, int $page, int $pageSize): array
    {
        $query = ScopeService::query('shop_order', $aid)
            ->where('mid', $mid)
            ->where('delete', 0);

        $total = (clone $query)->count();

        $rows = $query->field('id, ordernum, totalprice, status, createtime')
            ->order('id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        $list = array_map(static fn (array $row) => [
            'id'         => (int) $row['id'],
            'ordernum'   => (string) ($row['ordernum'] ?? ''),
            'totalprice' => (float) ($row['totalprice'] ?? 0),
            'status'     => (int) ($row['status'] ?? 0),
            'createtime' => (int) ($row['createtime'] ?? 0),
        ], $rows);

        return ['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize];
    }

    /**
     * 会员收货地址（member_address.mid 为 varchar，含软删过滤）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function address(int $aid, int $mid, int $page, int $pageSize): array
    {
        $query = ScopeService::query('member_address', $aid)
            ->where('mid', (string) $mid)
            ->where('deleted', 0);

        $total = (clone $query)->count();

        $rows = $query->order('id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        $list = array_map(static fn (array $row) => [
            'id'         => (int) $row['id'],
            'name'       => (string) ($row['name'] ?? ''),
            'tel'        => (string) ($row['tel'] ?? ''),
            'region'     => trim(implode(' ', array_filter([(string) ($row['province'] ?? ''), (string) ($row['city'] ?? ''), (string) ($row['district'] ?? '')]))),
            'address'    => (string) ($row['address'] ?? ''),
            'isdefault'  => (int) ($row['isdefault'] ?? 0),
            'createtime' => (int) ($row['createtime'] ?? 0),
        ], $rows);

        return ['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize];
    }

    /* ================= 会员等级设置 ================= */

    /**
     * 等级列表（含各等级会员数）
     *
     * @return array{list: array}
     */
    public function levelList(int $aid): array
    {
        $rows = ScopeService::query('member_level', $aid)
            ->field('id, name, icon, sort, discount, isdefault, up_ordermoney, up_rechargemoney, up_orderprice')
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        $counts = ScopeService::query('member', $aid)
            ->field('levelid, count(*) as cnt')
            ->group('levelid')
            ->select()
            ->toArray();

        $countMap = [];
        foreach ($counts as $c) {
            $countMap[(int) $c['levelid']] = (int) $c['cnt'];
        }

        $list = array_map(static fn (array $row) => [
            'id'               => (int) $row['id'],
            'name'             => (string) ($row['name'] ?? ''),
            'icon'             => (string) ($row['icon'] ?? ''),
            'sort'             => (int) ($row['sort'] ?? 1),
            'discount'         => (float) ($row['discount'] ?? 10),
            'isdefault'        => (int) ($row['isdefault'] ?? 0),
            'up_ordermoney'    => (float) ($row['up_ordermoney'] ?? 0),
            'up_rechargemoney' => (float) ($row['up_rechargemoney'] ?? 0),
            'up_orderprice'    => (float) ($row['up_orderprice'] ?? 0),
            'member_count'     => (int) ($countMap[(int) $row['id']] ?? 0),
        ], $rows);

        return ['list' => $list];
    }

    /**
     * 等级详情（编辑回读：含升级条件/分销/分红/赠送全字段，P19-2）
     *
     * @return array<string, mixed>
     */
    public function levelDetail(int $aid, int $id): array
    {
        if ($id <= 0) {
            throw new BusinessException('缺少等级ID');
        }

        $row = ScopeService::query('member_level', $aid)->where('id', $id)->find();
        if (empty($row)) {
            throw new BusinessException('等级不存在', 404, 404);
        }
        $row = (array) $row;

        // 金额/比例类字段
        $floatFields = [
            'discount', 'up_ordermoney', 'up_rechargemoney', 'up_wxpaymoney', 'up_perpaymoney',
            'up_orderprice', 'up_fxordermoney', 'up_cat_ordermoney',
            'commission1', 'commission2', 'commission3', 'commission4', 'commission5', 'commission6',
            'commission_parent', 'commission_parent_pj',
            'fenhong', 'fenhong_max_money', 'teamfenhongbl', 'teamfenhong_money', 'areafenhongbl',
            'up_give_commission', 'up_give_money', 'up_give_parent_money',
        ];
        // 整型字段
        $intFields = [
            'id', 'sort', 'isdefault', 'maxnum', 'up_getmembercard', 'can_agent', 'agent_rule',
            'commissionset', 'commissiontype', 'commission_max', 'fx_jinsuo',
            'up_fxdowncount', 'up_fxdowncount2', 'up_fxdowncount3',
            'fenhong_num', 'teamfenhonglv', 'areafenhong', 'areafenhongmaxnum', 'up_give_score',
        ];
        // ID/级数 CSV 字段（逗号分隔字符串）
        $csvFields = [
            'up_fxorderlevelnum', 'up_fxorderlevelid',
            'up_fxdownlevelnum', 'up_fxdownlevelid',
            'up_fxdownlevelnum2', 'up_fxdownlevelid2',
            'up_fxdownlevelnum3', 'up_fxdownlevelid3',
            'up_proid', 'up_catid', 'gettj', 'commission_appointlevelid',
        ];

        $detail = ['name' => (string) ($row['name'] ?? ''), 'icon' => (string) ($row['icon'] ?? '')];
        foreach ($floatFields as $f) {
            $detail[$f] = (float) ($row[$f] ?? 0);
        }
        foreach ($intFields as $f) {
            $detail[$f] = (int) ($row[$f] ?? 0);
        }
        foreach ($csvFields as $f) {
            $detail[$f] = (string) ($row[$f] ?? '');
        }

        return $detail;
    }

    /**
     * 等级新增/编辑（P19-2：升级条件/分销配置/分红配置/升级赠送四组字段白名单）
     * discount：10 为无折扣，9.5 为 95 折；isdefault=1 时清除其他默认等级
     *
     * @param array<string, mixed> $data
     */
    public function levelSave(int $aid, array $data): void
    {
        $id               = (int) ($data['id'] ?? 0);
        $name             = trim((string) ($data['name'] ?? ''));
        $icon             = trim((string) ($data['icon'] ?? ''));
        $sort             = (int) ($data['sort'] ?? 1);
        $discount         = $data['discount'] ?? 10;
        $isdefault        = (int) ($data['isdefault'] ?? 0);

        if ('' === $name) {
            throw new BusinessException('请输入等级名称');
        }
        if (!is_numeric($discount) || (float) $discount <= 0 || (float) $discount > 10) {
            throw new BusinessException('折扣须为 0-10 之间的数字（10为无折扣）');
        }

        // ---- 数值字段规整：金额/比例类 float（>=0）、计数类 int（>=0）、开关 int（0/1） ----
        $floatOf = static function ($v): float {
            if (!is_numeric($v) || (float) $v < 0) {
                throw new BusinessException('金额/比例字段须为不小于0的数字');
            }
            return round((float) $v, 2);
        };
        $intOf = static function ($v): int {
            if (!is_numeric($v) || (int) $v < 0) {
                throw new BusinessException('人数/份数字段须为不小于0的整数');
            }
            return (int) $v;
        };
        $flagOf = static fn ($v): int => ((int) $v === 1 ? 1 : 0);
        // ID/级数 CSV 规整：仅保留数字与逗号；空值按给定默认
        $csvOf = static function ($v, string $default = ''): string {
            $s = trim((string) $v);
            if ('' === $s) {
                return $default;
            }
            $s = preg_replace('/[^0-9,\-]/', '', $s) ?? '';
            $s = trim(preg_replace('/,{2,}/', ',', $s) ?? '', ',');
            return '' === $s ? $default : $s;
        };

        $canAgent = (int) ($data['can_agent'] ?? 0);
        if ($canAgent < 0 || $canAgent > 3) {
            throw new BusinessException('分销权限取值须为 0-3');
        }
        $agentRule = (int) ($data['agent_rule'] ?? 0);
        if ($agentRule < 0 || $agentRule > 3) {
            throw new BusinessException('推荐规则取值须为 0-3');
        }
        $commissionset = (int) ($data['commissionset'] ?? 0);
        if (!in_array($commissionset, [-1, 0, 1, 2], true)) {
            throw new BusinessException('佣金设置模式取值须为 -1/0/1/2');
        }
        $commissiontype = (int) ($data['commissiontype'] ?? 0);
        if (!in_array($commissiontype, [0, 1], true)) {
            throw new BusinessException('提成方式取值须为 0（百分比）或 1（固定金额）');
        }
        $areafenhong = (int) ($data['areafenhong'] ?? 0);
        if ($areafenhong < 0 || $areafenhong > 3) {
            throw new BusinessException('区域代理取值须为 0-3');
        }

        $update = [
            'name'      => $name,
            'icon'      => $icon,
            'sort'      => $sort > 0 ? $sort : 1,
            'discount'  => round((float) $discount, 2),
            'isdefault' => $isdefault === 1 ? 1 : 0,
            'maxnum'    => $intOf($data['maxnum'] ?? 0),

            // ---- 升级条件组（组内 or 关系，任一满足即可升级） ----
            'up_ordermoney'      => $floatOf($data['up_ordermoney'] ?? 0),
            'up_rechargemoney'   => $floatOf($data['up_rechargemoney'] ?? 0),
            'up_wxpaymoney'      => $floatOf($data['up_wxpaymoney'] ?? 0),
            'up_perpaymoney'     => $floatOf($data['up_perpaymoney'] ?? 0),
            'up_orderprice'      => $floatOf($data['up_orderprice'] ?? 0),
            'up_getmembercard'   => $flagOf($data['up_getmembercard'] ?? 0),
            'up_fxordermoney'    => $floatOf($data['up_fxordermoney'] ?? 0),
            'up_fxorderlevelnum' => $csvOf($data['up_fxorderlevelnum'] ?? '', '0'),
            'up_fxorderlevelid'  => $csvOf($data['up_fxorderlevelid'] ?? '', '0'),
            'up_fxdowncount'     => $intOf($data['up_fxdowncount'] ?? 0),
            'up_fxdownlevelnum'  => $csvOf($data['up_fxdownlevelnum'] ?? '', '0'),
            'up_fxdownlevelid'   => $csvOf($data['up_fxdownlevelid'] ?? '', '0'),
            'up_fxdowncount2'    => $intOf($data['up_fxdowncount2'] ?? 0),
            'up_fxdownlevelnum2' => $csvOf($data['up_fxdownlevelnum2'] ?? '', '0'),
            'up_fxdownlevelid2'  => $csvOf($data['up_fxdownlevelid2'] ?? '', '0'),
            'up_fxdowncount3'    => $intOf($data['up_fxdowncount3'] ?? 0),
            'up_fxdownlevelnum3' => $csvOf($data['up_fxdownlevelnum3'] ?? '', '0'),
            'up_fxdownlevelid3'  => $csvOf($data['up_fxdownlevelid3'] ?? '', '0'),
            'up_proid'           => $csvOf($data['up_proid'] ?? '', ''),
            'up_catid'           => $csvOf($data['up_catid'] ?? '', ''),
            'up_cat_ordermoney'  => $floatOf($data['up_cat_ordermoney'] ?? 0),
            'gettj'              => $csvOf($data['gettj'] ?? '', '-1'),

            // ---- 分销配置组 ----
            'can_agent'                 => $canAgent,
            'agent_rule'                => $agentRule,
            'commissionset'             => $commissionset,
            'commissiontype'            => $commissiontype,
            'commission1'               => $floatOf($data['commission1'] ?? 0),
            'commission2'               => $floatOf($data['commission2'] ?? 0),
            'commission3'               => $floatOf($data['commission3'] ?? 0),
            'commission4'               => $floatOf($data['commission4'] ?? 0),
            'commission5'               => $floatOf($data['commission5'] ?? 0),
            'commission6'               => $floatOf($data['commission6'] ?? 0),
            'commission_parent'         => $floatOf($data['commission_parent'] ?? 0),
            'commission_parent_pj'      => $floatOf($data['commission_parent_pj'] ?? 0),
            'commission_appointlevelid' => $csvOf($data['commission_appointlevelid'] ?? '', ''),
            'commission_max'            => $intOf($data['commission_max'] ?? 0),
            'fx_jinsuo'                 => $flagOf($data['fx_jinsuo'] ?? 0),

            // ---- 分红配置组 ----
            'fenhong'           => $floatOf($data['fenhong'] ?? 0),
            'fenhong_num'       => $intOf($data['fenhong_num'] ?? 0),
            'fenhong_max_money' => $floatOf($data['fenhong_max_money'] ?? 0),
            'teamfenhonglv'     => $intOf($data['teamfenhonglv'] ?? 0),
            'teamfenhongbl'     => $floatOf($data['teamfenhongbl'] ?? 0),
            'teamfenhong_money' => $floatOf($data['teamfenhong_money'] ?? 0),
            'areafenhong'       => $areafenhong,
            'areafenhongbl'     => $floatOf($data['areafenhongbl'] ?? 0),
            'areafenhongmaxnum' => $intOf($data['areafenhongmaxnum'] ?? 0),

            // ---- 升级赠送组 ----
            'up_give_score'        => $intOf($data['up_give_score'] ?? 0),
            'up_give_commission'   => $floatOf($data['up_give_commission'] ?? 0),
            'up_give_money'        => $floatOf($data['up_give_money'] ?? 0),
            'up_give_parent_money' => $floatOf($data['up_give_parent_money'] ?? 0),
        ];

        Db::transaction(function () use ($aid, $id, $update) {
            if ($id > 0) {
                $exists = ScopeService::query('member_level', $aid)->where('id', $id)->find();
                if (empty($exists)) {
                    throw new BusinessException('等级不存在', 404, 404);
                }
                Db::name('member_level')->where('id', $id)->where('aid', $aid)->update($update);
            } else {
                $update['aid']      = $aid;
                $update['createtime'] = time();
                $id = (int) Db::name('member_level')->insertGetId($update);
            }

            // 默认等级互斥
            if (1 === (int) $update['isdefault']) {
                Db::name('member_level')
                    ->where('aid', $aid)
                    ->where('id', '<>', $id)
                    ->where('isdefault', 1)
                    ->update(['isdefault' => 0]);
            }
        });
    }

    /**
     * 等级删除（有会员占用时拒绝）
     */
    public function levelDelete(int $aid, int $id): void
    {
        if ($id <= 0) {
            throw new BusinessException('缺少等级ID');
        }

        $exists = ScopeService::query('member_level', $aid)->where('id', $id)->find();
        if (empty($exists)) {
            throw new BusinessException('等级不存在', 404, 404);
        }

        $used = ScopeService::query('member', $aid)->where('levelid', $id)->count();
        if ($used > 0) {
            throw new BusinessException('该等级下还有 ' . $used . ' 个会员，不能删除');
        }

        Db::name('member_level')->where('id', $id)->where('aid', $aid)->delete();
    }

    /**
     * 区域代理归属记录列表（member_level_record 按等级过滤，含会员昵称/手机号，P19-2）
     *
     * @return array{list: array}
     */
    public function levelRecordList(int $aid, int $levelId): array
    {
        if ($levelId <= 0) {
            throw new BusinessException('缺少等级ID');
        }

        $exists = ScopeService::query('member_level', $aid)->where('id', $levelId)->find();
        if (empty($exists)) {
            throw new BusinessException('等级不存在', 404, 404);
        }

        $rows = ScopeService::query('member_level_record', $aid)
            ->where('levelid', $levelId)
            ->order('id', 'desc')
            ->select()
            ->toArray();

        $mids = array_values(array_unique(array_map(static fn (array $r) => (int) ($r['mid'] ?? 0), $rows)));
        $memberMap = [];
        if ($mids) {
            $members = ScopeService::query('member', $aid)
                ->whereIn('id', $mids)
                ->field('id, nickname, tel')
                ->select()
                ->toArray();
            foreach ($members as $m) {
                $memberMap[(int) $m['id']] = $m;
            }
        }

        $list = array_map(static function (array $row) use ($memberMap) {
            $mid  = (int) ($row['mid'] ?? 0);
            $info = $memberMap[$mid] ?? null;
            return [
                'id'                    => (int) ($row['id'] ?? 0),
                'mid'                   => $mid,
                'nickname'              => (string) ($info['nickname'] ?? ''),
                'tel'                   => (string) ($info['tel'] ?? ''),
                'areafenhong'           => (int) ($row['areafenhong'] ?? 0),
                'areafenhongbl'         => (float) ($row['areafenhongbl'] ?? 0),
                'areafenhong_province'  => (string) ($row['areafenhong_province'] ?? ''),
                'areafenhong_city'      => (string) ($row['areafenhong_city'] ?? ''),
                'areafenhong_area'      => (string) ($row['areafenhong_area'] ?? ''),
                'createtime'            => (int) ($row['createtime'] ?? 0),
            ];
        }, $rows);

        return ['list' => $list];
    }

    /**
     * 区域代理归属保存（member_level_record 省市区 + 开关/比例）
     *
     * @param array<string, mixed> $data
     */
    public function levelRecordSave(int $aid, array $data): void
    {
        $id = (int) ($data['id'] ?? 0);
        if ($id <= 0) {
            throw new BusinessException('缺少记录ID');
        }

        $exists = ScopeService::query('member_level_record', $aid)->where('id', $id)->find();
        if (empty($exists)) {
            throw new BusinessException('记录不存在', 404, 404);
        }

        $bl = $data['areafenhongbl'] ?? ($exists['areafenhongbl'] ?? 0);
        if (!is_numeric($bl) || (float) $bl < 0 || (float) $bl > 100) {
            throw new BusinessException('区域分红比例须为 0-100 之间的数字');
        }

        Db::name('member_level_record')->where('id', $id)->where('aid', $aid)->update([
            'areafenhong'          => (int) ($data['areafenhong'] ?? $exists['areafenhong'] ?? 0) === 1 ? 1 : 0,
            'areafenhongbl'        => round((float) $bl, 2),
            'areafenhong_province' => trim((string) ($data['areafenhong_province'] ?? '')),
            'areafenhong_city'     => trim((string) ($data['areafenhong_city'] ?? '')),
            'areafenhong_area'     => trim((string) ($data['areafenhong_area'] ?? '')),
        ]);
    }

    /* ================= 内部工具 ================= */

    /**
     * 读取会员（租户隔离；不存在抛 404）
     *
     * @return array<string, mixed>
     */
    protected function findMember(int $aid, int $mid): array
    {
        if ($mid <= 0) {
            throw new BusinessException('缺少会员ID');
        }

        $member = ScopeService::query('member', $aid)->where('id', $mid)->find();

        if (empty($member)) {
            throw new BusinessException('会员不存在', 404, 404);
        }

        return (array) $member;
    }

    /**
     * 等级 ID → 名称映射
     *
     * @return array<int, string>
     */
    protected function levelNameMap(int $aid): array
    {
        $rows = ScopeService::query('member_level', $aid)->field('id, name')->select()->toArray();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['id']] = (string) ($row['name'] ?? '');
        }

        return $map;
    }

    /**
     * 注册时间范围解析（YYYY-MM-DD 字符串 → [start, end] 时间戳）
     *
     * @return array{0: int, 1: int}|null
     */
    protected function timeRange(string $startTime, string $endTime): ?array
    {
        $start = '' !== trim($startTime) ? strtotime(trim($startTime)) : false;
        $end   = '' !== trim($endTime) ? strtotime(trim($endTime) . ' 23:59:59') : false;

        if (false === $start && false === $end) {
            return null;
        }

        return [(int) ($start ?: 0), (int) ($end ?: time())];
    }
}
