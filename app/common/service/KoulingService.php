<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;

/**
 * 口令/谜语游戏服务（批次4-E 任务190）
 *
 * 口径对照参考系统 ApiCoupon::kouling/riddle 与 B 端 Kouling 控制器：
 * - kouling：口令精确匹配 + 时间段 + status=1 + gettj 人群 + num/zjnum 总量
 *   + perday/pertotal 每人限次 + give_coupon/coupon_ids 发券
 * - riddle：按 id 取谜面，谜底（name）精确匹配后同套校验发奖
 *
 * 表：kouling_set / kouling / kouling_record / riddle / riddle_record
 */
class KoulingService
{
    /** 活动类型 → 表名映射 */
    private const TABLES = [
        'kouling' => ['main' => 'kouling', 'record' => 'kouling_record'],
        'riddle'  => ['main' => 'riddle', 'record' => 'riddle_record'],
    ];

    public function __construct(
        protected CouponService $couponService,
        protected ScoreService $scoreService,
    ) {
    }

    /* ================= B 端：页面设置 ================= */

    /**
     * 读取口令页面设置
     *
     * @return array<string, mixed>
     */
    public function getSet(int $aid): array
    {
        $row = ScopeService::query('kouling_set', $aid)->order('id', 'desc')->find();

        return [
            'title'  => (string) ($row['title'] ?? '口令游戏'),
            'bgpic'  => (string) ($row['bgpic'] ?? ''),
            'guize'  => (string) ($row['guize'] ?? ''),
            'status' => (int) ($row['status'] ?? 0),
        ];
    }

    /**
     * 保存口令页面设置（按 aid upsert）
     *
     * @param array<string, mixed> $data
     */
    public function saveSet(int $aid, array $data): void
    {
        $status = (int) ($data['status'] ?? 0);

        if (!in_array($status, [0, 1], true)) {
            throw new BusinessException('状态值无效');
        }

        $update = [
            'title'  => trim((string) ($data['title'] ?? '口令游戏')),
            'bgpic'  => trim((string) ($data['bgpic'] ?? '')),
            'guize'  => trim((string) ($data['guize'] ?? '')),
            'status' => $status,
        ];

        $exists = ScopeService::query('kouling_set', $aid)->order('id', 'desc')->find();

        if ($exists) {
            Db::name('kouling_set')->where('id', (int) $exists['id'])->update($update);
        } else {
            $update['aid']        = $aid;
            $update['bid']        = 0;
            $update['createtime'] = time();

            Db::name('kouling_set')->insert($update);
        }
    }

    /* ================= B 端：活动 CRUD ================= */

    /**
     * 活动列表（kouling/riddle 通用）
     *
     * @return array{list: array<int, array<string, mixed>>, total: int}
     */
    public function activityList(int $aid, string $type, string $keyword, int $page, int $pageSize): array
    {
        $table = $this->tableOf($type, 'main');

        $query = ScopeService::query($table, $aid);

        if ('' !== $keyword) {
            $query->where('name', 'like', '%' . $keyword . '%');
        }

        $total = (clone $query)->count();
        $rows  = $query->order('id', 'desc')->page($page, $pageSize)->select()->toArray();

        foreach ($rows as &$row) {
            $row['starttime_text'] = (int) $row['starttime'] > 0 ? date('Y-m-d H:i', (int) $row['starttime']) : '';
            $row['endtime_text']   = (int) $row['endtime'] > 0 ? date('Y-m-d H:i', (int) $row['endtime']) : '';
        }

        unset($row);

        return ['list' => $rows, 'total' => $total];
    }

    /**
     * 活动详情
     *
     * @return array<string, mixed>
     */
    public function activityDetail(int $aid, string $type, int $id): array
    {
        $table = $this->tableOf($type, 'main');

        $row = ScopeService::query($table, $aid)->where('id', $id)->find();

        if (empty($row)) {
            throw new BusinessException('活动不存在');
        }

        $row                 = (array) $row;
        $row['gettj']        = $this->csvToArray((string) ($row['gettj'] ?? '-1'));
        $row['coupon_ids']   = $this->csvToArray((string) ($row['coupon_ids'] ?? ''));
        $row['coupon_names'] = $this->couponNames($aid, (string) ($row['coupon_ids'] ?? ''));

        return $row;
    }

    /**
     * 保存活动（新增/编辑，kouling/riddle 通用）
     *
     * @param array<string, mixed> $data
     * @return array{id: int}
     */
    public function saveActivity(int $aid, string $type, array $data): array
    {
        $table = $this->tableOf($type, 'main');

        $id   = (int) ($data['id'] ?? 0);
        $name = trim((string) ($data['name'] ?? ''));

        if ('' === $name) {
            throw new BusinessException('kouling' === $type ? '请输入口令' : '请输入谜底');
        }

        $start = $this->stampOf($data['starttime'] ?? 0);
        $end   = $this->stampOf($data['endtime'] ?? 0);

        if ($start <= 0 || $end <= 0) {
            throw new BusinessException('请选择活动时间');
        }

        if ($end <= $start) {
            throw new BusinessException('活动结束时间必须晚于开始时间');
        }

        // 同租户口令/谜底不允许重复
        $dup = ScopeService::query($table, $aid)->where('name', $name);

        if ($id > 0) {
            $dup->where('id', '<>', $id);
        }

        if ($dup->find()) {
            throw new BusinessException('该名称已存在');
        }

        $giveCoupon = (int) ($data['give_coupon'] ?? 0) === 1 ? 1 : 0;
        $couponIds  = $this->normalizeCsv($data['coupon_ids'] ?? []);

        if (1 === $giveCoupon && '' === $couponIds) {
            throw new BusinessException('请选择赠送优惠券');
        }

        $status = (int) ($data['status'] ?? 0);

        if (!in_array($status, [0, 1], true)) {
            throw new BusinessException('状态值无效');
        }

        $update = [
            'name'        => $name,
            'starttime'   => $start,
            'endtime'     => $end,
            'status'      => $status,
            'gettj'       => $this->normalizeCsv($data['gettj'] ?? []) ?: '-1',
            'num'         => max(0, (int) ($data['num'] ?? 0)),
            'perday'      => max(0, (int) ($data['perday'] ?? 0)),
            'pertotal'    => max(0, (int) ($data['pertotal'] ?? 0)),
            'give_coupon' => $giveCoupon,
            'coupon_ids'  => 1 === $giveCoupon ? $couponIds : '',
            'money'       => max(0, round((float) ($data['money'] ?? 0), 2)),
            'score'       => max(0, (int) ($data['score'] ?? 0)),
        ];

        if ('riddle' === $type) {
            $update['title']   = trim((string) ($data['title'] ?? ''));
            $update['alias']   = trim((string) ($data['alias'] ?? '')) ?: '谜底';
            $update['content'] = trim((string) ($data['content'] ?? ''));
        }

        if ($id > 0) {
            $exists = ScopeService::query($table, $aid)->where('id', $id)->find();

            if (empty($exists)) {
                throw new BusinessException('活动不存在');
            }

            Db::name($table)->where('id', $id)->update($update);
        } else {
            $update['aid']        = $aid;
            $update['bid']        = 0;
            $update['zjnum']      = 0;
            $update['createtime'] = time();

            $id = (int) Db::name($table)->insertGetId($update);
        }

        return ['id' => $id];
    }

    /**
     * 删除活动（含记录）
     *
     * @param array<int, int> $ids
     */
    public function deleteActivity(int $aid, string $type, array $ids): void
    {
        $main   = $this->tableOf($type, 'main');
        $record = $this->tableOf($type, 'record');

        $exists = ScopeService::query($main, $aid)->whereIn('id', $ids)->column('id');

        if (empty($exists)) {
            throw new BusinessException('活动不存在');
        }

        Db::name($record)->where('aid', $aid)->whereIn('objid', $exists)->delete();
        Db::name($main)->where('aid', $aid)->whereIn('id', $exists)->delete();
    }

    /**
     * 参与记录列表
     *
     * @return array{list: array<int, array<string, mixed>>, total: int}
     */
    public function recordList(int $aid, string $type, int $objId, string $nickname, int $page, int $pageSize): array
    {
        $table = $this->tableOf($type, 'record');

        $query = ScopeService::query($table, $aid);

        if ($objId > 0) {
            $query->where('objid', $objId);
        }

        if ('' !== $nickname) {
            $query->where('nickname', 'like', '%' . $nickname . '%');
        }

        $total = (clone $query)->count();
        $rows  = $query->order('id', 'desc')->page($page, $pageSize)->select()->toArray();

        foreach ($rows as &$row) {
            $row['createtime_text'] = (int) $row['createtime'] > 0 ? date('Y-m-d H:i:s', (int) $row['createtime']) : '';
        }

        unset($row);

        return ['list' => $rows, 'total' => $total];
    }

    /* ================= C 端：兑换 ================= */

    /**
     * 口令兑换（精确匹配 → 校验 → 发券/发奖 + 记录）
     *
     * @return array{msg: string}
     */
    public function exchange(int $aid, int $mid, string $kouling): array
    {
        $kouling = trim($kouling);

        if ('' === $kouling) {
            throw new BusinessException('请输入口令');
        }

        return Db::transaction(function () use ($aid, $mid, $kouling) {
            $info = ScopeService::query('kouling', $aid)
                ->where('name', $kouling)
                ->where('starttime', '<=', time())
                ->where('endtime', '>=', time())
                ->where('status', 1)
                ->lock(true)
                ->find();

            if (empty($info)) {
                throw new BusinessException('口令不存在或已过期，请重新输入');
            }

            $info = (array) $info;

            $this->grant($aid, $mid, 'kouling', 'kouling_record', $info);

            return ['msg' => '恭喜获得奖励'];
        });
    }

    /**
     * 谜语详情（C 端展示谜面，不下发谜底）
     *
     * @return array<string, mixed>
     */
    public function riddleDetail(int $aid, int $id): array
    {
        $info = ScopeService::query('riddle', $aid)->where('id', $id)->where('status', 1)->find();

        if (empty($info)) {
            throw new BusinessException('谜语不存在或已过期');
        }

        $info = (array) $info;

        unset($info['name']);

        $info['title'] = (string) ($info['title'] ?? '') ?: '猜谜语';
        $info['alias'] = (string) ($info['alias'] ?? '') ?: '谜底';

        return $info;
    }

    /**
     * 谜语提交（谜底精确匹配 → 校验 → 发奖 + 记录）
     *
     * @return array{msg: string}
     */
    public function riddleSubmit(int $aid, int $mid, int $id, string $answer): array
    {
        if ($id <= 0) {
            throw new BusinessException('参数错误');
        }

        return Db::transaction(function () use ($aid, $mid, $id, $answer) {
            $info = ScopeService::query('riddle', $aid)->where('id', $id)->where('status', 1)->lock(true)->find();

            if (empty($info)) {
                throw new BusinessException('谜语不存在或已过期');
            }

            $info  = (array) $info;
            $alias = (string) ($info['alias'] ?? '') ?: '谜底';

            if (time() < (int) $info['starttime']) {
                throw new BusinessException('活动还未开始');
            }

            if (time() > (int) $info['endtime']) {
                throw new BusinessException('活动已结束');
            }

            $answer = trim($answer);

            if ('' === $answer) {
                throw new BusinessException('请输入' . $alias);
            }

            if ($answer !== (string) $info['name']) {
                throw new BusinessException($alias . '不正确');
            }

            $this->grant($aid, $mid, 'riddle', 'riddle_record', $info);

            return ['msg' => '恭喜获得奖励'];
        });
    }

    /* ================= 内部工具 ================= */

    /**
     * 校验 + 发奖 + 落记录 + 累加 zjnum（事务内调用，主表已行锁）
     *
     * @param array<string, mixed> $info
     */
    protected function grant(int $aid, int $mid, string $type, string $recordTable, array $info): void
    {
        $mainTable = 'kouling' === $type ? 'kouling' : 'riddle';
        $objId     = (int) $info['id'];

        $member = Db::name('member')->where('id', $mid)->where('aid', $aid)->find();

        if (empty($member)) {
            throw new BusinessException('未找到会员信息');
        }

        $this->checkGettj($member, (string) ($info['gettj'] ?? '-1'));

        if ((int) $info['zjnum'] >= (int) $info['num'] && (int) $info['num'] > 0) {
            throw new BusinessException('奖品已领完');
        }

        if ((int) $info['perday'] > 0) {
            $todayCount = Db::name($recordTable)
                ->where('aid', $aid)->where('objid', $objId)->where('mid', $mid)
                ->where('createdate', date('Y-m-d'))->count();

            if ($todayCount >= (int) $info['perday']) {
                throw new BusinessException('今日参加次数已达上限，请明日再来');
            }
        }

        if ((int) $info['pertotal'] > 0) {
            $totalCount = Db::name($recordTable)
                ->where('aid', $aid)->where('objid', $objId)->where('mid', $mid)->count();

            if ($totalCount >= (int) $info['pertotal']) {
                throw new BusinessException('此活动参与已达上限');
            }
        }

        // 赠送优惠券
        if (1 === (int) $info['give_coupon'] && '' !== trim((string) $info['coupon_ids'])) {
            foreach (explode(',', (string) $info['coupon_ids']) as $couponId) {
                $couponId = (int) $couponId;

                if ($couponId > 0) {
                    $this->couponService->receive($aid, $mid, $couponId);
                }
            }
        }

        // 奖励积分
        if ((int) $info['score'] > 0) {
            $this->scoreService->adjust($aid, $mid, (int) $info['score'], 'kouling' === $type ? '口令游戏奖励' : '谜语竞猜奖励');
        }

        // 奖励余额
        if ((float) $info['money'] > 0) {
            $money = round((float) $info['money'], 2);
            $after = round((float) ($member['money'] ?? 0) + $money, 2);

            Db::name('member')->where('id', $mid)->where('aid', $aid)->update(['money' => $after]);

            Db::name('member_moneylog')->insert([
                'aid'        => $aid,
                'mid'        => $mid,
                'money'      => $money,
                'after'      => $after,
                'createtime' => time(),
                'remark'     => 'kouling' === $type ? '口令游戏奖励' : '谜语竞猜奖励',
                'paytype'    => $type,
                'uid'        => 0,
            ]);
        }

        Db::name($recordTable)->insert([
            'aid'        => $aid,
            'bid'        => (int) ($info['bid'] ?? 0),
            'objid'      => $objId,
            'name'       => (string) ($info['name'] ?? ''),
            'mid'        => $mid,
            'headimg'    => (string) ($member['headimg'] ?? ''),
            'nickname'   => (string) ($member['nickname'] ?? ''),
            'coupon_ids' => (string) ($info['coupon_ids'] ?? ''),
            'money'      => (float) ($info['money'] ?? 0),
            'score'      => (int) ($info['score'] ?? 0),
            'createtime' => time(),
            'createdate' => date('Y-m-d'),
            'status'     => 1,
        ]);

        Db::name($mainTable)->where('id', $objId)->inc('zjnum')->update();
    }

    /**
     * gettj 人群校验（-1 不限；0 需关注公众号；其余为等级 id）
     *
     * @param array<string, mixed> $member
     */
    protected function checkGettj(array $member, string $gettj): void
    {
        $list = array_filter(array_map('trim', explode(',', $gettj)), static fn ($v) => '' !== $v);

        if (in_array('-1', $list, true)) {
            return;
        }

        if (in_array((string) ($member['levelid'] ?? 0), $list, true)) {
            return;
        }

        if (in_array('0', $list, true) && 1 === (int) ($member['subscribe'] ?? 0)) {
            return;
        }

        throw new BusinessException('您没有参与权限');
    }

    /**
     * @return array<int, string>
     */
    protected function couponNames(int $aid, string $csv): array
    {
        $ids = array_filter(array_map('intval', explode(',', $csv)), static fn ($v) => $v > 0);

        if (empty($ids)) {
            return [];
        }

        return ScopeService::query('coupon', $aid)->whereIn('id', $ids)->column('name', 'id');
    }

    protected function tableOf(string $type, string $which): string
    {
        if (!isset(self::TABLES[$type])) {
            throw new BusinessException('活动类型无效');
        }

        return self::TABLES[$type][$which];
    }

    /**
     * @param mixed $value
     */
    protected function stampOf($value): int
    {
        if (is_numeric($value) && (int) $value > 0) {
            return (int) $value;
        }

        $stamp = strtotime((string) $value);

        return false === $stamp ? 0 : $stamp;
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    protected function csvToArray($value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('strval', $value), static fn ($v) => '' !== $v));
        }

        return array_values(array_filter(array_map('trim', explode(',', (string) $value)), static fn ($v) => '' !== $v));
    }

    /**
     * @param mixed $value
     */
    protected function normalizeCsv($value): string
    {
        return implode(',', $this->csvToArray($value));
    }
}
