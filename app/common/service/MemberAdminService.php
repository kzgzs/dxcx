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
     * 会员列表（对齐点大会员列表）
     * 筛选：id 精确、keyword（id|nickname|tel|realname like）、pid 推荐人ID、levelid、是否领卡、注册时间范围
     * 排序白名单：id / money / score / commission / createtime
     *
     * @param array<string, mixed> $f
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function list(int $aid, array $f): array
    {
        $keyword   = trim((string) ($f['keyword'] ?? ''));
        $levelId   = (int) ($f['levelid'] ?? 0);
        $startTime = trim((string) ($f['start_time'] ?? ''));
        $endTime   = trim((string) ($f['end_time'] ?? ''));
        $searchId  = (int) ($f['id'] ?? 0);
        $pid       = (int) ($f['pid'] ?? 0);
        $hasCard   = trim((string) ($f['hascard'] ?? ''));
        $page      = max(1, (int) ($f['page'] ?? 1));
        $pageSize  = (int) ($f['pagesize'] ?? 10);
        $pageSize  = $pageSize > 0 ? min($pageSize, 100) : 10;

        $sortMap = ['id' => 'id', 'money' => 'money', 'score' => 'score', 'commission' => 'commission', 'createtime' => 'createtime'];
        $sortCol = $sortMap[trim((string) ($f['field'] ?? 'id'))] ?? 'id';
        $sortDir = strtolower(trim((string) ($f['order'] ?? 'desc'))) === 'asc' ? 'asc' : 'desc';

        $query = ScopeService::queryAs('member', $aid, 'm')->leftJoin('member p', 'p.id = m.pid');

        if ($searchId > 0) {
            $query->where('m.id', $searchId);
        }
        if ('' !== $keyword) {
            $like = '%' . $keyword . '%';
            $query->where(function ($q) use ($like, $keyword) {
                $q->whereOr('m.nickname', 'like', $like)
                    ->whereOr('m.tel', 'like', $like)
                    ->whereOr('m.realname', 'like', $like);
                if (ctype_digit($keyword)) {
                    $q->whereOr('m.id', (int) $keyword);
                }
            });
        }
        if ($levelId > 0) {
            $query->where('m.levelid', $levelId);
        }
        if ($pid > 0) {
            $query->where('m.pid', $pid);
        }
        if ('1' === $hasCard) {
            $query->where(function ($q) {
                $q->whereOr('m.card_id', '<>', '')->whereOr('m.card_code', '<>', '');
            });
        } elseif ('2' === $hasCard) {
            $query->where('m.card_id', '')->where('m.card_code', '');
        }

        $range = $this->timeRange($startTime, $endTime);
        if (null !== $range) {
            $query->whereBetween('m.createtime', $range);
        }

        $total = (clone $query)->count();

        $rows = $query
            ->field("m.*, p.nickname as parent_nickname,
                (SELECT COUNT(*) FROM coupon_record cr WHERE cr.mid = m.id AND cr.aid = {$aid}) as coupon_count")
            ->order('m.' . $sortCol, $sortDir)
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        $levelMap = $this->levelNameMap($aid);

        $list = array_map(static function (array $row) use ($levelMap) {
            $hasCard = ('' !== (string) ($row['card_id'] ?? '') || '' !== (string) ($row['card_code'] ?? '')) ? 1 : 0;
            return [
                'id'              => (int) $row['id'],
                'headimg'         => (string) ($row['headimg'] ?? ''),
                'nickname'        => (string) ($row['nickname'] ?? ''),
                'realname'        => (string) ($row['realname'] ?? ''),
                'sex'             => (string) ($row['sex'] ?? ''),
                'tel'             => (string) ($row['tel'] ?? ''),
                'pid'             => (int) ($row['pid'] ?? 0),
                'parent_nickname' => (string) ($row['parent_nickname'] ?? ''),
                'levelid'         => (int) ($row['levelid'] ?? 0),
                'level_name'      => (string) ($levelMap[(int) ($row['levelid'] ?? 0)] ?? ''),
                'money'           => (float) ($row['money'] ?? 0),
                'score'           => (int) ($row['score'] ?? 0),
                'commission'      => (float) ($row['commission'] ?? 0),
                'platform'        => (string) ($row['platform'] ?? ''),
                'subscribe'       => (int) ($row['subscribe'] ?? 0),
                'has_card'        => $hasCard,
                'coupon_count'    => (int) ($row['coupon_count'] ?? 0),
                'buynum'          => (int) ($row['buynum'] ?? 0),
                'buymoney'        => (float) ($row['buymoney'] ?? 0),
                'createtime'      => (int) ($row['createtime'] ?? 0),
                'last_visittime'  => (int) ($row['last_visittime'] ?? 0),
                'isfreeze'        => (int) ($row['isfreeze'] ?? 0),
                'islock'          => (int) ($row['islock'] ?? 0),
                'remark'          => (string) ($row['remark'] ?? ''),
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
            'usercard'       => (string) ($member['usercard'] ?? ''),
            'sex'            => (string) ($member['sex'] ?? ''),
            'birthday'       => (string) ($member['birthday'] ?? ''),
            'pid'            => (int) ($member['pid'] ?? 0),
            'mpopenid'       => (string) ($member['mpopenid'] ?? ''),
            'levelid'        => (int) ($member['levelid'] ?? 0),
            'level_name'     => (string) ($levelMap[(int) ($member['levelid'] ?? 0)] ?? ''),
            'levelendtime'   => (int) ($member['levelendtime'] ?? 0),
            'money'          => (float) ($member['money'] ?? 0),
            'score'          => (int) ($member['score'] ?? 0),
            'commission'     => (float) ($member['commission'] ?? 0),
            'subscribe'      => (int) ($member['subscribe'] ?? 0),
            'has_card'       => ('' !== (string) ($member['card_id'] ?? '') || '' !== (string) ($member['card_code'] ?? '')) ? 1 : 0,
            'has_paypwd'     => '' !== (string) ($member['paypwd'] ?? '') ? 1 : 0,
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
     * 编辑会员（对齐点大会员编辑）
     * 基本信息：头像/昵称/姓名/手机号/身份证/性别/生日/等级/推荐人ID/支付密码清除/备注
     * 等级变更同事务写 member_levelup_order 变动记录
     *
     * @param array<string, mixed> $data
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
        $headimg  = trim((string) ($data['headimg'] ?? ''));
        $realname = trim((string) ($data['realname'] ?? ''));
        $usercard = trim((string) ($data['usercard'] ?? ''));
        $sex      = trim((string) ($data['sex'] ?? ''));
        $birthday = trim((string) ($data['birthday'] ?? ''));
        $pid      = (int) ($data['pid'] ?? -1);
        $clearPwd = (int) ($data['clear_paypwd'] ?? 0) === 1;

        if ('' === $nickname) {
            throw new BusinessException('昵称不能为空');
        }
        if ('' !== $tel && 1 !== preg_match('/^1\d{10}$/', $tel)) {
            throw new BusinessException('手机号格式不正确');
        }
        if ('' !== $sex && !in_array($sex, ['男', '女', '未知'], true)) {
            $sex = '';
        }
        if ('' !== $birthday && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthday)) {
            throw new BusinessException('生日格式不正确（YYYY-MM-DD）');
        }
        if ('' !== $usercard && !preg_match('/(^\d{15}$)|(^\d{17}([0-9Xx])$)/', $usercard)) {
            throw new BusinessException('身份证号格式不正确');
        }

        $member = $this->findMember($aid, $mid);

        $update = [
            'nickname' => $nickname,
            'headimg'  => $headimg,
            'realname' => $realname,
            'usercard' => $usercard,
            'sex'      => $sex,
            'birthday' => $birthday,
            'tel'      => $tel,
            'remark'   => $remark,
        ];

        // 推荐人：0=无；不能是自己，不能形成推荐环（新推荐人不能是本人下级）
        if ($pid >= 0 && $pid !== (int) ($member['pid'] ?? 0)) {
            if ($pid === $mid) {
                throw new BusinessException('推荐人不能是会员本人');
            }
            if ($pid > 0) {
                $parent = ScopeService::query('member', $aid)->where('id', $pid)->find();
                if (empty($parent)) {
                    throw new BusinessException('推荐人不存在');
                }
                $cursor = $pid;
                $guard  = 0;
                while ($cursor > 0 && $guard < 30) {
                    if ($cursor === $mid) {
                        throw new BusinessException('不能将会员的下级设为其推荐人（会形成循环）');
                    }
                    $row    = ScopeService::query('member', $aid)->where('id', $cursor)->field('pid')->find();
                    $cursor = (int) ($row['pid'] ?? 0);
                    $guard++;
                }
            }
            $update['pid'] = $pid;
        }

        // 清除支付密码
        if ($clearPwd) {
            $update['paypwd']      = '';
            $update['paypwd_rand'] = '';
        }

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
                $endTime                = $ts + 86399;
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
    public function recharge(int $aid, int $mid, float $money, string $remark, int $uid, string $paytype = 'admin'): array
    {
        if ($mid <= 0) {
            throw new BusinessException('缺少会员ID');
        }
        if (0.0 === round($money, 2)) {
            throw new BusinessException('变动金额不能为0');
        }

        // 充值方式白名单（对齐点大：微信/现金/支付宝；后台调整记 admin）
        $paytypeMap = ['wxpay' => '微信支付', 'cash' => '现金支付', 'alipay' => '支付宝支付', 'admin' => '后台调整'];
        $paytype    = $paytypeMap[$paytype] ?? '后台调整';

        $money  = round($money, 2);
        $remark = '' !== trim($remark) ? trim($remark) : ($money > 0 ? '后台充值' : '后台扣费');

        $after = 0.0;

        Db::transaction(function () use ($aid, $mid, $money, $remark, $uid, $paytype, &$after) {
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
                'paytype'    => $paytype,
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

    /* ================= 佣金变动 ================= */

    /**
     * 后台加/扣佣金（commission 正=增加 负=扣除），对齐点大 addcommission
     * 事务内行锁校验扣减后佣金不为负；member.commission / totalcommission 与 member_commissionlog 同事务落库
     *
     * @return array{commission: float} 变动后佣金
     */
    public function addCommission(int $aid, int $mid, float $commission, string $remark, int $uid): array
    {
        if ($mid <= 0) {
            throw new BusinessException('缺少会员ID');
        }
        if (0.0 === round($commission, 2)) {
            throw new BusinessException('佣金金额不能为0');
        }

        $commission = round($commission, 2);
        $remark     = '' !== trim($remark) ? trim($remark) : ($commission > 0 ? '后台加佣金' : '后台扣佣金');

        $after = 0.0;

        Db::transaction(function () use ($aid, $mid, $commission, $remark, $uid, &$after) {
            $member = Db::name('member')
                ->where('id', $mid)
                ->where('aid', $aid)
                ->lock(true)
                ->find();

            if (empty($member)) {
                throw new BusinessException('会员不存在', 404, 404);
            }

            $after = round((float) ($member['commission'] ?? 0) + $commission, 2);
            if ($after < 0) {
                throw new BusinessException('佣金不足，扣减后佣金不能为负');
            }

            $memberUpdate = ['commission' => $after];
            if ($commission > 0) {
                $memberUpdate['totalcommission'] = round((float) ($member['totalcommission'] ?? 0) + $commission, 2);
            }
            Db::name('member')->where('id', $mid)->where('aid', $aid)->update($memberUpdate);

            Db::name('member_commissionlog')->insert([
                'aid'        => $aid,
                'mid'        => $mid,
                'frommid'    => 0,
                'commission' => $commission,
                'after'      => $after,
                'createtime' => time(),
                'remark'     => $remark,
                'fhtype'     => 'admin',
                'uid'        => $uid,
                'fhid'       => 0,
            ]);
        });

        return ['commission' => $after];
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

        // 等级名 map（下级条件展示用）
        $levelMap = [];
        foreach ($rows as $r) {
            $levelMap[(int) $r['id']] = (string) ($r['name'] ?? '');
        }

        // 商品名 map（购买商品升级条件用）
        $proIds = [];
        foreach ($rows as $r) {
            foreach (explode(',', (string) ($r['up_proid'] ?? '')) as $pid) {
                $pid = (int) trim($pid);
                if ($pid > 0) {
                    $proIds[$pid] = true;
                }
            }
        }
        $goodsMap = [];
        if ($proIds) {
            $goods = Db::name('shop_product')->where('aid', $aid)
                ->whereIn('id', array_keys($proIds))->column('name', 'id');
            foreach ($goods as $gid => $gname) {
                $goodsMap[(int) $gid] = (string) $gname;
            }
        }

        $list = array_map(function (array $row) use ($countMap, $levelMap, $goodsMap) {
            $isDefault = (int) ($row['isdefault'] ?? 0) === 1;
            $canApply  = (int) ($row['can_apply'] ?? 0) === 1;
            $canUp     = (int) ($row['can_up'] ?? 0) === 1;

            return [
                'id'             => (int) $row['id'],
                'name'           => (string) ($row['name'] ?? ''),
                'icon'           => (string) ($row['icon'] ?? ''),
                'sort'           => (int) ($row['sort'] ?? 1),
                'discount'       => (float) ($row['discount'] ?? 10),
                'isdefault'      => (int) ($row['isdefault'] ?? 0),
                'can_apply'      => (int) ($row['can_apply'] ?? 0),
                'can_up'         => (int) ($row['can_up'] ?? 0),
                'can_agent'      => (int) ($row['can_agent'] ?? 0),
                'applytj'        => $this->buildLevelApplyTj($row, $canApply),
                'apply_paymoney' => (float) ($row['apply_paymoney'] ?? 0),
                'uptj'           => $this->buildLevelUpTj($row, $isDefault, $canUp, $levelMap, $goodsMap),
                'commission'     => $this->buildLevelCommission($row),
                'member_count'   => (int) ($countMap[(int) $row['id']] ?? 0),
            ];
        }, $rows);

        return ['list' => $list];
    }

    /**
     * 申请条件文案（对齐点大 MemberLevel::index）
     * can_apply=0 不可申请；=1 时按订单金额/充值金额条件拼，无条件为“无”
     */
    private function buildLevelApplyTj(array $row, bool $canApply): string
    {
        if (!$canApply) {
            return '不可申请';
        }

        $tj = [];
        if ((float) ($row['apply_ordermoney'] ?? 0) > 0) {
            $tj[] = '订单金额满' . number_format((float) $row['apply_ordermoney'], 2) . '元';
        }
        if ((float) ($row['apply_rechargemoney'] ?? 0) > 0) {
            $tj[] = '充值金额满' . number_format((float) $row['apply_rechargemoney'], 2) . '元';
        }

        return $tj ? implode(' 或 ', $tj) : '无';
    }

    /**
     * 升级条件文案（对齐点大 MemberLevel::index uptj 拼接，仅实现本项目表中存在的字段）
     */
    private function buildLevelUpTj(array $row, bool $isDefault, bool $canUp, array $levelMap, array $goodsMap): string
    {
        if ($isDefault) {
            return '默认等级无需升级';
        }
        if (!$canUp) {
            return '不自动升级';
        }

        $tj = [];
        $money2 = static fn ($v): string => number_format((float) $v, 2);

        if ((float) ($row['up_wxpaymoney'] ?? 0) > 0) {
            $tj[] = '微信支付金额满' . $money2($row['up_wxpaymoney']) . '元';
        }
        if ((float) ($row['up_ordermoney'] ?? 0) > 0) {
            $tj[] = '订单金额满' . $money2($row['up_ordermoney']) . '元';
        }
        if ((float) ($row['up_rechargemoney'] ?? 0) > 0) {
            $tj[] = '充值金额满' . $money2($row['up_rechargemoney']) . '元';
        }
        if ((float) ($row['up_perpaymoney'] ?? 0) > 0) {
            $tj[] = '单次消费满' . $money2($row['up_perpaymoney']) . '元';
        }
        if ((float) ($row['up_orderprice'] ?? 0) > 0) {
            $tj[] = '单次订单满' . $money2($row['up_orderprice']) . '元';
        }

        // 下 N 级[等级]总订单金额满 X 元
        if ((float) ($row['up_fxordermoney'] ?? 0) > 0) {
            $n    = trim((string) ($row['up_fxorderlevelnum'] ?? ''));
            $name = $this->levelNamesFromCsv((string) ($row['up_fxorderlevelid'] ?? ''), $levelMap);
            $tj[] = '下' . $n . '级' . $name . '总订单金额满' . $money2($row['up_fxordermoney']) . '元';
        }

        // 下 N 级[等级]总人数满 X 个（三档）
        foreach (['', '2', '3'] as $suffix) {
            $cnt = (int) ($row['up_fxdowncount' . $suffix] ?? 0);
            if ($cnt > 0) {
                $n    = trim((string) ($row['up_fxdownlevelnum' . $suffix] ?? ''));
                $name = $this->levelNamesFromCsv((string) ($row['up_fxdownlevelid' . $suffix] ?? ''), $levelMap);
                $tj[] = '下' . $n . '级' . $name . '总人数满' . $cnt . '个';
            }
        }

        if ((int) ($row['up_getmembercard'] ?? 0) === 1) {
            $tj[] = '领取会员卡';
        }

        // 购买指定商品总数量到达 N 件
        $proIdCsv = trim((string) ($row['up_proid'] ?? ''));
        if ($proIdCsv !== '') {
            $proIds  = array_values(array_filter(array_map('intval', explode(',', $proIdCsv)), static fn ($v) => $v > 0));
            $proNums = array_values(array_filter(array_map('intval', explode(',', (string) ($row['up_pronum'] ?? '1'))), static fn ($v) => $v > 0));
            $names   = [];
            foreach ($proIds as $pid) {
                if (isset($goodsMap[$pid])) {
                    $names[] = $goodsMap[$pid];
                }
            }
            if ($names) {
                if (count($proNums) <= 1) {
                    $num  = $proNums[0] ?? 1;
                    $tj[] = '购买商品[' . implode('+', $names) . '] 总数量到达' . $num . '件';
                } else {
                    $line = '';
                    foreach ($proIds as $i => $pid) {
                        if (isset($goodsMap[$pid])) {
                            $line .= '购买商品[' . $goodsMap[$pid] . ']*' . ($proNums[$i] ?? 1) . '；';
                        }
                    }
                    if ('' !== $line) {
                        $tj[] = rtrim($line, '；');
                    }
                }
            }
        }

        // 指定分类订单金额满 X 元
        if (trim((string) ($row['up_catid'] ?? '')) !== '' && (float) ($row['up_cat_ordermoney'] ?? 0) > 0) {
            $tj[] = '分类订单金额满' . $money2($row['up_cat_ordermoney']) . '元';
        }

        return $tj ? implode(' 或 ', $tj) : '不自动升级';
    }

    /**
     * 提成比例文案（对齐点大 MemberLevel::index commission 拼接）
     */
    private function buildLevelCommission(array $row): string
    {
        $canAgent = (int) ($row['can_agent'] ?? 0);
        $unit     = (int) ($row['commissiontype'] ?? 0) === 1 ? '元' : '%';
        $c        = static fn ($v): string => number_format((float) ($v ?? 0), 2);

        if (1 === $canAgent) {
            return $c($row['commission1'] ?? 0) . $unit;
        }
        if (2 === $canAgent) {
            return $c($row['commission1'] ?? 0) . $unit . ' / ' . $c($row['commission2'] ?? 0) . $unit;
        }
        if (3 === $canAgent) {
            return $c($row['commission1'] ?? 0) . $unit . ' / ' . $c($row['commission2'] ?? 0) . $unit . ' / ' . $c($row['commission3'] ?? 0) . $unit;
        }
        if (99 === $canAgent) {
            return '递归分销';
        }

        return '无分销权限';
    }

    /**
     * 等级 ID csv 转 [等级名1/等级名2]（点大下级条件展示）
     */
    private function levelNamesFromCsv(string $csv, array $levelMap): string
    {
        $names = [];
        foreach (explode(',', $csv) as $v) {
            $v = (int) trim($v);
            if ($v > 0 && isset($levelMap[$v]) && '' !== $levelMap[$v]) {
                $names[] = $levelMap[$v];
            }
        }

        return $names ? '[' . implode('/', $names) . ']' : '';
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
            'apply_ordermoney', 'apply_rechargemoney', 'apply_paymoney',
            'commission1', 'commission2', 'commission3', 'commission4', 'commission5', 'commission6',
            'commission_parent', 'commission_parent_pj',
            'fenhong', 'fenhong_max_money', 'teamfenhongbl', 'teamfenhong_money', 'areafenhongbl',
            'up_give_commission', 'up_give_money', 'up_give_parent_money',
        ];
        // 整型字段
        $intFields = [
            'id', 'sort', 'isdefault', 'maxnum', 'up_getmembercard', 'can_agent', 'agent_rule',
            'commissionset', 'commissiontype', 'commission_max', 'fx_jinsuo',
            'can_up', 'can_apply', 'apply_check', 'score1', 'score2', 'score3', 'scoremax',
            'up_fxdowncount', 'up_fxdowncount2', 'up_fxdowncount3',
            'fenhong_num', 'teamfenhonglv', 'areafenhong', 'areafenhongmaxnum', 'up_give_score',
            'commission1own', 'team_showtel', 'team_givemoney', 'team_givescore',
        ];
        // ID/级数 CSV 字段（逗号分隔字符串）
        $csvFields = [
            'up_fxorderlevelnum', 'up_fxorderlevelid',
            'up_fxdownlevelnum', 'up_fxdownlevelid',
            'up_fxdownlevelnum2', 'up_fxdownlevelid2',
            'up_fxdownlevelnum3', 'up_fxdownlevelid3',
            'up_proid', 'up_pronum', 'up_catid', 'gettj', 'commission_appointlevelid',
        ];

        $detail = [
            'name'         => (string) ($row['name'] ?? ''),
            'icon'         => (string) ($row['icon'] ?? ''),
            'apply_paytxt' => (string) ($row['apply_paytxt'] ?? ''),
            'explain'      => (string) ($row['explain'] ?? ''),
        ];
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
            'up_pronum'          => $csvOf($data['up_pronum'] ?? '', '1'),
            'up_catid'           => $csvOf($data['up_catid'] ?? '', ''),
            'up_cat_ordermoney'  => $floatOf($data['up_cat_ordermoney'] ?? 0),
            'gettj'              => $csvOf($data['gettj'] ?? '', '-1'),

            // ---- 申请设置组（会员主动申请升级，对齐点大“升级设置”） ----
            'can_up'             => $flagOf($data['can_up'] ?? 0),
            'can_apply'          => $flagOf($data['can_apply'] ?? 0),
            'apply_check'        => $flagOf($data['apply_check'] ?? 0),
            'apply_ordermoney'   => $floatOf($data['apply_ordermoney'] ?? 0),
            'apply_rechargemoney' => $floatOf($data['apply_rechargemoney'] ?? 0),
            'apply_paymoney'     => $floatOf($data['apply_paymoney'] ?? 0),
            'apply_paytxt'       => trim((string) ($data['apply_paytxt'] ?? '')) ?: '升级费用',

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

            // ---- 推荐积分（对齐点大“推荐积分”：一/二/三级 + 每人最高限制） ----
            'score1'                    => $intOf($data['score1'] ?? 0),
            'score2'                    => $intOf($data['score2'] ?? 0),
            'score3'                    => $intOf($data['score3'] ?? 0),
            'scoremax'                  => $intOf($data['scoremax'] ?? 0),

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

            // ---- 自己拿一级提成 / 我的团队权限 / 特权说明（对齐点大等级编辑第二部分） ----
            'commission1own' => $flagOf($data['commission1own'] ?? 0),
            'team_showtel'   => $flagOf($data['team_showtel'] ?? 0),
            'team_givemoney' => $flagOf($data['team_givemoney'] ?? 0),
            'team_givescore' => $flagOf($data['team_givescore'] ?? 0),
            'explain'        => trim((string) ($data['explain'] ?? '')),
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

        if ((int) ($exists['isdefault'] ?? 0) === 1) {
            throw new BusinessException('默认等级不能删除');
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

    /* ================= 会员维度：删除 / 导出 / 商城订单 / 优惠券 / 分销订单 ================= */

    /**
     * 批量删除会员（物理删除会员主记录；订单、资金流水等业务凭证保留）
     *
     * @param array<int, mixed> $ids
     * @return int 删除条数
     */
    public function deleteMembers(int $aid, array $ids): int
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $ids = array_filter($ids, static fn ($v) => $v > 0);
        if (empty($ids)) {
            throw new BusinessException('请选择要删除的会员');
        }

        $count = ScopeService::query('member', $aid)->whereIn('id', $ids)->delete();

        return (int) $count;
    }

    /**
     * 会员列表导出（筛选口径同 list）
     *
     * @param array<string, mixed> $f
     * @return array{0: array<int, string>, 1: array<int, array<int, mixed>>}
     */
    public function memberExport(int $aid, array $f): array
    {
        $f['pagesize'] = 50000;
        $f['page']     = 1;
        $data          = $this->list($aid, $f);

        $titles = ['ID', '昵称', '姓名', '手机号', '推荐人ID', '等级', '余额', '积分', '佣金', '优惠券数', '注册时间', '备注'];
        $rows   = [];
        foreach ($data['list'] as $v) {
            $rows[] = [
                $v['id'],
                $v['nickname'],
                $v['realname'],
                $v['tel'],
                $v['pid'],
                $v['level_name'],
                $v['money'],
                $v['score'],
                $v['commission'],
                $v['coupon_count'],
                $v['createtime'] ? date('Y-m-d H:i:s', $v['createtime']) : '',
                $v['remark'],
            ];
        }

        return [$titles, $rows];
    }

    /**
     * 会员的商城订单（shop_order by mid，对齐点大会员-商城订单）
     * 筛选：status 状态tab、ordernum 单号、freight_type 配送方式、createtime 范围、totalprice 区间
     *
     * @param array<string, mixed> $f
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function shopOrders(int $aid, int $mid, array $f): array
    {
        if ($mid <= 0) {
            throw new BusinessException('缺少会员ID');
        }
        $status    = trim((string) ($f['status'] ?? ''));
        $orderKw   = trim((string) ($f['ordernum'] ?? ''));
        $freight   = trim((string) ($f['freight_type'] ?? ''));
        $minPrice  = trim((string) ($f['min_price'] ?? ''));
        $maxPrice  = trim((string) ($f['max_price'] ?? ''));
        $page      = max(1, (int) ($f['page'] ?? 1));
        $pageSize  = (int) ($f['pagesize'] ?? 10);
        $pageSize  = $pageSize > 0 ? min($pageSize, 100) : 10;

        $query = ScopeService::queryAs('shop_order', $aid, 'o')
            ->leftJoin('business b', 'b.id = o.bid')
            ->where('o.mid', $mid)
            ->where('o.delete', 0);

        if ('' !== $status && ctype_digit($status)) {
            $query->where('o.status', (int) $status);
        }
        if ('' !== $orderKw) {
            $like = '%' . $orderKw . '%';
            $query->where(function ($q) use ($like) {
                $q->whereOr('o.ordernum', 'like', $like)->whereOr('o.sysOrderNo', 'like', $like);
            });
        }
        if ('' !== $freight && ctype_digit($freight)) {
            $query->where('o.freight_type', (int) $freight);
        }
        if ('' !== $minPrice && is_numeric($minPrice)) {
            $query->where('o.totalprice', '>=', (float) $minPrice);
        }
        if ('' !== $maxPrice && is_numeric($maxPrice)) {
            $query->where('o.totalprice', '<=', (float) $maxPrice);
        }
        $range = $this->timeRange((string) ($f['start_time'] ?? ''), (string) ($f['end_time'] ?? ''));
        if (null !== $range) {
            $query->whereBetween('o.createtime', $range);
        }

        $total = (clone $query)->count();
        $rows  = $query
            ->field('o.*, b.name as business_name')
            ->order('o.id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        $orderIds = array_map(static fn ($r) => (int) $r['id'], $rows);
        $goodsMap = [];
        if ($orderIds) {
            $goods = ScopeService::query('shop_order_goods', $aid)
                ->whereIn('orderid', $orderIds)
                ->field('id, orderid, name, pic, ggname, num, sell_price, totalprice')
                ->select()
                ->toArray();
            foreach ($goods as $g) {
                $goodsMap[(int) $g['orderid']][] = [
                    'id'         => (int) $g['id'],
                    'name'       => (string) ($g['name'] ?? ''),
                    'pic'        => (string) ($g['pic'] ?? ''),
                    'ggname'     => (string) ($g['ggname'] ?? ''),
                    'num'        => (int) ($g['num'] ?? 0),
                    'sell_price' => (float) ($g['sell_price'] ?? 0),
                    'totalprice' => (float) ($g['totalprice'] ?? 0),
                ];
            }
        }

        $payTypeMap = [
            'wechat' => '微信支付', 'wxpay' => '微信支付', 'wxapp' => '微信支付',
            'alipay' => '支付宝支付', 'alipay2' => '支付宝支付',
            'balance' => '余额支付', 'cash' => '现金支付',
            'mock' => '模拟支付', 'score' => '积分支付', 'admin' => '后台下单',
        ];

        $list = array_map(static function (array $r) use ($goodsMap, $payTypeMap) {
            $area = trim((string) ($r['area'] ?? '') . ' ' . (string) ($r['area2'] ?? '') . ' ' . (string) ($r['address'] ?? ''));
            $payRaw = (string) ($r['paytype'] ?? '');
            return [
                'id'            => (int) $r['id'],
                'bid'           => (int) ($r['bid'] ?? 0),
                'business_name' => (string) ($r['business_name'] ?? ''),
                'ordernum'      => (string) ($r['ordernum'] ?? ''),
                'sysOrderNo'    => (string) ($r['sysOrderNo'] ?? ''),
                'goods'         => $goodsMap[(int) $r['id']] ?? [],
                'product_price' => (float) ($r['product_price'] ?? 0),
                'totalprice'    => (float) ($r['totalprice'] ?? 0),
                'freight_price' => (float) ($r['freight_price'] ?? 0),
                'paytype'       => (string) ($payTypeMap[$payRaw] ?? $payRaw),
                'status'        => (int) ($r['status'] ?? 0),
                'createtime'    => (int) ($r['createtime'] ?? 0),
                'paytime'       => (int) ($r['paytime'] ?? 0),
                'linkman'       => (string) ($r['linkman'] ?? ''),
                'tel'           => (string) ($r['tel'] ?? ''),
                'address'       => $area,
                'remark'        => (string) ($r['remark'] ?? ''),
                'freight_text'  => (string) ($r['freight_text'] ?? ''),
                'freight_type'  => (int) ($r['freight_type'] ?? 0),
                'platform'      => (string) ($r['platform'] ?? ''),
            ];
        }, $rows);

        return ['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize];
    }

    /**
     * 会员领取的优惠券记录（coupon_record by mid，对齐点大会员-优惠券）
     * 筛选：status、使用时间(usetime)范围、remark 关键字
     *
     * @param array<string, mixed> $f
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function memberCoupons(int $aid, int $mid, array $f): array
    {
        if ($mid <= 0) {
            throw new BusinessException('缺少会员ID');
        }
        $status   = trim((string) ($f['status'] ?? ''));
        $remarkKw = trim((string) ($f['remark'] ?? ''));
        $page     = max(1, (int) ($f['page'] ?? 1));
        $pageSize = (int) ($f['pagesize'] ?? 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        $query = ScopeService::queryAs('coupon_record', $aid, 'r')
            ->leftJoin('business b', 'b.id = r.bid')
            ->where('r.mid', $mid);

        if ('' !== $status && ctype_digit($status)) {
            $query->where('r.status', (int) $status);
        }
        if ('' !== $remarkKw) {
            $query->where('r.remark', 'like', '%' . $remarkKw . '%');
        }
        $range = $this->timeRange((string) ($f['start_time'] ?? ''), (string) ($f['end_time'] ?? ''));
        if (null !== $range) {
            // 截图为“使用时间”范围
            $query->whereBetween('r.usetime', $range);
        }

        $total = (clone $query)->count();
        $rows  = $query
            ->field('r.*, b.name as business_name')
            ->order('r.id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        $list = array_map(static fn (array $r) => [
            'id'            => (int) $r['id'],
            'bid'           => (int) ($r['bid'] ?? 0),
            'business_name' => (string) ($r['business_name'] ?? ''),
            'couponname'    => (string) ($r['couponname'] ?? ''),
            'money'         => (float) ($r['money'] ?? 0),
            'discount'      => (float) ($r['discount'] ?? 0),
            'minprice'      => (float) ($r['minprice'] ?? 0),
            'status'        => (int) ($r['status'] ?? 0),
            'limit_count'   => (int) ($r['limit_count'] ?? 0),
            'used_count'    => (int) ($r['used_count'] ?? 0),
            'createtime'    => (int) ($r['createtime'] ?? 0),
            'receive_time'  => (int) ($r['receive_time'] ?? 0),
            'starttime'     => (int) ($r['starttime'] ?? 0),
            'endtime'       => (int) ($r['endtime'] ?? 0),
            'usetime'       => (int) ($r['usetime'] ?? 0),
            'remark'        => (string) ($r['remark'] ?? ''),
        ], $rows);

        return ['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize];
    }

    /**
     * 批量改优惠券状态（0未使用 / 1已使用），限定会员归属防越权
     *
     * @param array<int, mixed> $ids
     */
    public function couponSetStatus(int $aid, int $mid, array $ids, int $status): void
    {
        if (!in_array($status, [0, 1], true)) {
            throw new BusinessException('状态取值不正确');
        }
        $ids = array_values(array_filter(array_unique(array_map('intval', $ids)), static fn ($v) => $v > 0));
        if (empty($ids)) {
            throw new BusinessException('请选择优惠券记录');
        }

        $update = ['status' => $status];
        if (1 === $status) {
            $update['usetime'] = time();
        } else {
            $update['usetime'] = 0;
        }

        ScopeService::query('coupon_record', $aid)
            ->where('mid', $mid)
            ->whereIn('id', $ids)
            ->update($update);
    }

    /**
     * 批量删除优惠券记录，限定会员归属
     *
     * @param array<int, mixed> $ids
     */
    public function couponDelete(int $aid, int $mid, array $ids): int
    {
        $ids = array_values(array_filter(array_unique(array_map('intval', $ids)), static fn ($v) => $v > 0));
        if (empty($ids)) {
            throw new BusinessException('请选择优惠券记录');
        }

        return (int) ScopeService::query('coupon_record', $aid)
            ->where('mid', $mid)
            ->whereIn('id', $ids)
            ->delete();
    }

    /**
     * 会员作为分销员（佣金获得者）的佣金订单记录，对齐点大-分销订单
     *
     * 表口径（核对 member_commission_record 实测）：
     *   r.mid     = 分销员（佣金获得者，即当前查看的会员 $distMid）
     *   r.frommid = 下单人（来源会员）
     *   商品行 parent1..4 = 下单人之上的第 1..4 级推荐人，命中分销员即“分销层级”
     * 筛选：mid 下单人、status、type 订单类型、orderid 订单ID、ordernum 单号、createtime 范围
     *
     * @param array<string, mixed> $f
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function fenxiaoOrders(int $aid, int $distMid, array $f): array
    {
        if ($distMid <= 0) {
            throw new BusinessException('缺少会员ID');
        }
        $typeMap = ['shop' => '商城订单'];

        $buyerMid = (int) ($f['mid'] ?? 0);
        $status   = trim((string) ($f['status'] ?? ''));
        $type     = trim((string) ($f['type'] ?? ''));
        $orderid  = (int) ($f['orderid'] ?? 0);
        $orderKw  = trim((string) ($f['ordernum'] ?? ''));
        $page     = max(1, (int) ($f['page'] ?? 1));
        $pageSize = (int) ($f['pagesize'] ?? 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        $query = ScopeService::queryAs('member_commission_record', $aid, 'r')
            ->leftJoin('member m', 'm.id = r.frommid')
            ->leftJoin('shop_order o', 'o.id = r.orderid')
            ->leftJoin('business b', 'b.id = o.bid')
            ->leftJoin('shop_order_goods g', 'g.id = r.ogid')
            ->where('r.mid', $distMid);

        if ($buyerMid > 0) {
            $query->where('r.frommid', $buyerMid);
        }
        if ('' !== $status && ctype_digit($status)) {
            $query->where('r.status', (int) $status);
        }
        if ('' !== $type) {
            $query->where('r.type', $type);
        }
        if ($orderid > 0) {
            $query->where('r.orderid', $orderid);
        }
        if ('' !== $orderKw) {
            $like = '%' . $orderKw . '%';
            $query->where(function ($q) use ($like) {
                $q->whereOr('o.ordernum', 'like', $like)->whereOr('o.sysOrderNo', 'like', $like);
            });
        }
        $range = $this->timeRange((string) ($f['start_time'] ?? ''), (string) ($f['end_time'] ?? ''));
        if (null !== $range) {
            $query->whereBetween('r.createtime', $range);
        }

        $total = (clone $query)->count();
        $rows  = $query
            ->field('r.id, r.mid as dist_mid, r.frommid as buyer_mid, r.orderid, r.ogid, r.type, r.commission, r.score, r.remark, r.createtime, r.endtime, r.status, r.levelid,
                m.nickname as m_nickname, m.headimg as m_headimg,
                o.ordernum, o.sysOrderNo, o.bid, o.createtime as order_createtime,
                b.name as business_name,
                g.name as goods_name, g.pic as goods_pic, g.ggname as goods_gg, g.num as goods_num,
                g.parent1, g.parent2, g.parent3, g.parent4')
            ->order('r.id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        $statusText = [0 => '未发放', 1 => '已发放', 2 => '已退回'];
        $list = array_map(static function (array $r) use ($distMid, $typeMap, $statusText) {
            // 分销层级：分销员 distMid 命中商品行 parentN 即第 N 级
            $level = 0;
            foreach ([1, 2, 3, 4] as $n) {
                if ((int) ($r['parent' . $n] ?? 0) === $distMid) {
                    $level = $n;
                    break;
                }
            }
            return [
                'id'            => (int) $r['id'],
                'orderid'       => (int) ($r['orderid'] ?? 0),
                'bid'           => (int) ($r['bid'] ?? 0),
                'business_name' => (string) ($r['business_name'] ?? ''),
                'type'          => (string) ($r['type'] ?? ''),
                'type_text'     => (string) ($typeMap[(string) ($r['type'] ?? '')] ?? (string) ($r['type'] ?? '')),
                'mid'           => (int) ($r['buyer_mid'] ?? 0),
                'nickname'      => (string) ($r['m_nickname'] ?? ''),
                'headimg'       => (string) ($r['m_headimg'] ?? ''),
                'goods_name'    => (string) ($r['goods_name'] ?? ''),
                'goods_pic'     => (string) ($r['goods_pic'] ?? ''),
                'goods_gg'      => (string) ($r['goods_gg'] ?? ''),
                'goods_num'     => (int) ($r['goods_num'] ?? 0),
                'ordernum'      => (string) ($r['ordernum'] ?? ''),
                'order_createtime' => (int) ($r['order_createtime'] ?? 0),
                'level'         => $level,
                'real_level'    => $level,
                'commission'    => (float) ($r['commission'] ?? 0),
                'score'         => (int) ($r['score'] ?? 0),
                'status'        => (int) ($r['status'] ?? 0),
                'status_text'   => (string) ($statusText[(int) ($r['status'] ?? 0)] ?? '-'),
                'remark'        => (string) ($r['remark'] ?? ''),
                'createtime'    => (int) ($r['createtime'] ?? 0),
            ];
        }, $rows);

        return ['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize];
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
