<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;
use think\facade\Log;

/**
 * 微信红包服务（批次4-E 任务190）
 *
 * 口径对照参考系统 ApiHongbao::index / Hongbao 控制器：
 * - B 端：活动 CRUD（minmoney~maxmoney 随机 / totalmoney 预算 / pernum 限次）
 *   + 抽奖码 makecode 批量生成 / 导入 / 导出 + 领取记录
 * - C 端：行锁 + gettj 人群 + needcode 抽奖码校验 + pernum 限次 + 时间校验
 *   + rand(min*100,max*100)/100 随机金额 + 预算不足取余额 + givenmoney 累加
 *   + 发放走 wx_transfer mock 通道，失败落 record.status=2
 *
 * 表：hongbao / hongbao_record / hongbao_codelist
 */
class HongbaoService
{
    /* ================= B 端：活动 CRUD ================= */

    /**
     * @return array{list: array<int, array<string, mixed>>, total: int}
     */
    public function activityList(int $aid, string $keyword, int $page, int $pageSize): array
    {
        $query = ScopeService::query('hongbao', $aid);

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
     * @return array<string, mixed>
     */
    public function activityDetail(int $aid, int $id): array
    {
        $row = ScopeService::query('hongbao', $aid)->where('id', $id)->find();

        if (empty($row)) {
            throw new BusinessException('活动不存在');
        }

        $row         = (array) $row;
        $row['gettj'] = $this->csvToArray((string) ($row['gettj'] ?? '-1'));

        return $row;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{id: int}
     */
    public function saveActivity(int $aid, array $data): array
    {
        $id   = (int) ($data['id'] ?? 0);
        $name = trim((string) ($data['name'] ?? ''));

        if ('' === $name) {
            throw new BusinessException('请输入活动名称');
        }

        $minMoney   = round((float) ($data['minmoney'] ?? 0), 2);
        $maxMoney   = round((float) ($data['maxmoney'] ?? 0), 2);
        $totalMoney = round((float) ($data['totalmoney'] ?? 0), 2);

        if ($minMoney <= 0 || $maxMoney <= 0) {
            throw new BusinessException('请输入红包金额区间');
        }

        if ($maxMoney < $minMoney) {
            throw new BusinessException('最大金额不能小于最小金额');
        }

        if ($totalMoney <= 0) {
            throw new BusinessException('请输入发放总预算');
        }

        $start = $this->stampOf($data['starttime'] ?? 0);
        $end   = $this->stampOf($data['endtime'] ?? 0);

        if ($start <= 0 || $end <= 0) {
            throw new BusinessException('请选择活动时间');
        }

        if ($end <= $start) {
            throw new BusinessException('活动结束时间必须晚于开始时间');
        }

        $status = (int) ($data['status'] ?? 0);

        if (!in_array($status, [0, 1], true)) {
            throw new BusinessException('状态值无效');
        }

        $update = [
            'name'       => $name,
            'sendname'   => trim((string) ($data['sendname'] ?? '')),
            'wishing'    => trim((string) ($data['wishing'] ?? '')),
            'minmoney'   => $minMoney,
            'maxmoney'   => $maxMoney,
            'totalmoney' => $totalMoney,
            'pernum'     => max(1, (int) ($data['pernum'] ?? 1)),
            'starttime'  => $start,
            'endtime'    => $end,
            'status'     => $status,
            'sharepic'   => trim((string) ($data['sharepic'] ?? '')),
            'gettj'      => $this->normalizeCsv($data['gettj'] ?? []) ?: '-1',
            'needcode'   => (int) ($data['needcode'] ?? 0) === 1 ? 1 : 0,
        ];

        if ($id > 0) {
            $exists = ScopeService::query('hongbao', $aid)->where('id', $id)->find();

            if (empty($exists)) {
                throw new BusinessException('活动不存在');
            }

            if ((float) $exists['givenmoney'] > $totalMoney) {
                throw new BusinessException('总预算不能小于已发放金额');
            }

            $update['updatetime'] = time();
            Db::name('hongbao')->where('id', $id)->where('aid', $aid)->update($update);
        } else {
            $update['aid']        = $aid;
            $update['givenmoney'] = 0;
            $update['createtime'] = time();

            $id = (int) Db::name('hongbao')->insertGetId($update);
        }

        return ['id' => $id];
    }

    /**
     * @param array<int, int> $ids
     */
    public function deleteActivity(int $aid, array $ids): void
    {
        $exists = ScopeService::query('hongbao', $aid)->whereIn('id', $ids)->column('id');

        if (empty($exists)) {
            throw new BusinessException('活动不存在');
        }

        Db::name('hongbao_record')->where('aid', $aid)->whereIn('hid', $exists)->delete();
        Db::name('hongbao_codelist')->where('aid', $aid)->whereIn('hid', $exists)->delete();
        Db::name('hongbao')->where('aid', $aid)->whereIn('id', $exists)->delete();
    }

    /* ================= B 端：领取记录 / 抽奖码 ================= */

    /**
     * @return array{list: array<int, array<string, mixed>>, total: int}
     */
    public function recordList(int $aid, int $hid, string $nickname, int $page, int $pageSize): array
    {
        $query = ScopeService::query('hongbao_record', $aid);

        if ($hid > 0) {
            $query->where('hid', $hid);
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

    /**
     * @return array{list: array<int, array<string, mixed>>, total: int}
     */
    public function codelist(int $aid, int $hid, int $status, int $page, int $pageSize): array
    {
        $query = ScopeService::query('hongbao_codelist', $aid);

        if ($hid > 0) {
            $query->where('hid', $hid);
        }

        if ($status >= 0) {
            $query->where('status', $status);
        }

        $total = (clone $query)->count();
        $rows  = $query->order('id', 'desc')->page($page, $pageSize)->select()->toArray();

        foreach ($rows as &$row) {
            $row['createtime_text'] = (int) $row['createtime'] > 0 ? date('Y-m-d H:i:s', (int) $row['createtime']) : '';
            $row['usetime_text']    = (int) ($row['usetime'] ?? 0) > 0 ? date('Y-m-d H:i:s', (int) $row['usetime']) : '';
        }

        unset($row);

        return ['list' => $rows, 'total' => $total];
    }

    /**
     * 抽奖码导出（Excel）
     *
     * @return array{titles: array<int, string>, rows: array<int, array<int, mixed>>}
     */
    public function codelistExport(int $aid, int $hid): array
    {
        $query = ScopeService::query('hongbao_codelist', $aid);

        if ($hid > 0) {
            $query->where('hid', $hid);
        }

        $rows = $query->order('id', 'asc')->select()->toArray();

        $names = ScopeService::query('hongbao', $aid)->column('name', 'id');

        $titles = ['序号', '活动ID', '活动名称', '抽奖码', '状态', '使用者ID', '昵称', '领取金额', '使用时间'];
        $data   = [];

        foreach ($rows as $i => $row) {
            $data[] = [
                $i + 1,
                (int) $row['hid'],
                (string) ($names[(int) $row['hid']] ?? ''),
                (string) $row['code'],
                1 === (int) $row['status'] ? '已使用' : '未使用',
                (int) ($row['mid'] ?? 0),
                (string) ($row['nickname'] ?? ''),
                (float) ($row['money'] ?? 0),
                (int) ($row['usetime'] ?? 0) > 0 ? date('Y-m-d H:i:s', (int) $row['usetime']) : '',
            ];
        }

        return ['titles' => $titles, 'rows' => $data];
    }

    /**
     * 批量生成抽奖码（makecode：长度 8~16，默认 10）
     *
     * @return array{num: int}
     */
    public function makeCodes(int $aid, int $hid, int $num, int $length): array
    {
        $exists = ScopeService::query('hongbao', $aid)->where('id', $hid)->find();

        if (empty($exists)) {
            throw new BusinessException('活动不存在');
        }

        $num    = max(1, min($num, 10000));
        $length = max(8, min($length, 16));

        // 已存在码集合（防重）
        $existing = Db::name('hongbao_codelist')->where('aid', $aid)->where('hid', $hid)->column('code');
        $existing = array_flip($existing);

        $batch = [];
        $made  = 0;

        for ($i = 0; $i < $num; $i++) {
            do {
                $code = $this->randCode($length);
            } while (isset($existing[$code]));

            $existing[$code] = true;

            $batch[] = [
                'aid'        => $aid,
                'hid'        => $hid,
                'code'       => $code,
                'status'     => 0,
                'createtime' => time(),
            ];

            if (count($batch) >= 500) {
                Db::name('hongbao_codelist')->insertAll($batch);
                $made += count($batch);
                $batch = [];
            }
        }

        if (!empty($batch)) {
            Db::name('hongbao_codelist')->insertAll($batch);
            $made += count($batch);
        }

        return ['num' => $made];
    }

    /**
     * 抽奖码导入（Excel 首列为抽奖码；跳过表头与重复）
     *
     * @param array<int, array<int, mixed>> $rows
     * @return array{imported: int, skipped: int}
     */
    public function importCodes(int $aid, int $hid, array $rows): array
    {
        $exists = ScopeService::query('hongbao', $aid)->where('id', $hid)->find();

        if (empty($exists)) {
            throw new BusinessException('活动不存在');
        }

        $existing = Db::name('hongbao_codelist')->where('aid', $aid)->where('hid', $hid)->column('code');
        $existing = array_flip($existing);

        $batch   = [];
        $seen    = [];
        $skipped = 0;

        foreach ($rows as $i => $row) {
            $code = strtoupper(trim((string) ($row[0] ?? '')));

            // 跳过表头行
            if ($i === 0 && !preg_match('/^[A-Z0-9]{6,32}$/', $code)) {
                continue;
            }

            if ('' === $code || isset($existing[$code]) || isset($seen[$code])) {
                $skipped++;
                continue;
            }

            $seen[$code] = true;

            $batch[] = [
                'aid'        => $aid,
                'hid'        => $hid,
                'code'       => $code,
                'status'     => 0,
                'createtime' => time(),
            ];

            if (count($batch) >= 500) {
                Db::name('hongbao_codelist')->insertAll($batch);
                $batch = [];
            }
        }

        if (!empty($batch)) {
            Db::name('hongbao_codelist')->insertAll($batch);
        }

        return ['imported' => count($seen), 'skipped' => $skipped];
    }

    /* ================= C 端：领取 ================= */

    /**
     * 红包领取（行锁 + 校验 + 随机金额 + 预算扣减 + wx_transfer 发放）
     *
     * @return array{st: int, msg: string, money: float}
     */
    public function receive(int $aid, int $mid, int $hid, string $cjcode): array
    {
        if ($hid <= 0) {
            throw new BusinessException('参数错误');
        }

        $money = 0.0;
        $msg   = '';
        $st    = 1;

        Db::transaction(function () use ($aid, $mid, $hid, $cjcode, &$money, &$msg, &$st) {
            $hd = ScopeService::query('hongbao', $aid)->where('id', $hid)->lock(true)->find();

            if (empty($hd)) {
                throw new BusinessException('活动不存在');
            }

            $hd = (array) $hd;

            if (0 === (int) $hd['status']) {
                throw new BusinessException('活动未开启');
            }

            $member = Db::name('member')->where('aid', $aid)->where('id', $mid)->find();

            if (empty($member)) {
                throw new BusinessException('未找到会员信息');
            }

            $this->checkGettj($member, (string) ($hd['gettj'] ?? '-1'));

            $codeinfo = [];

            if (1 === (int) $hd['needcode']) {
                if ('' === trim($cjcode)) {
                    $st  = 5;
                    $msg = '请输入抽奖码';

                    return;
                }

                $codeinfo = Db::name('hongbao_codelist')
                    ->where('aid', $aid)->where('hid', $hid)->where('code', trim($cjcode))->find();

                if (empty($codeinfo)) {
                    throw new BusinessException('抽奖码不正确');
                }

                if (0 !== (int) $codeinfo['status']) {
                    throw new BusinessException('该抽奖码已使用');
                }
            }

            // 已领奖次数
            $mynum = Db::name('hongbao_record')->where('aid', $aid)->where('hid', $hid)->where('mid', $mid)->count();

            if ($mynum >= (int) $hd['pernum']) {
                $st  = 4;
                $msg = '您已经抢过了';

                return;
            }

            if ((int) $hd['starttime'] > time()) {
                $st  = 0;
                $msg = '活动还没开始';

                return;
            }

            if ((int) $hd['endtime'] < time()) {
                $st  = 2;
                $msg = '活动已经结束';

                return;
            }

            if ((float) $hd['givenmoney'] >= (float) $hd['totalmoney']
                || (float) $hd['givenmoney'] + (float) $hd['minmoney'] > (float) $hd['totalmoney']) {
                $st  = 3;
                $msg = '红包已经被抢完了';

                return;
            }

            // 随机金额（分区间整数再转元，剩余预算不足则取余额）
            $money = random_int((int) round((float) $hd['minmoney'] * 100), (int) round((float) $hd['maxmoney'] * 100)) / 100;
            $money = round($money, 2);

            $left = round((float) $hd['totalmoney'] - (float) $hd['givenmoney'], 2);

            if ($left < $money) {
                $money = $left;
            }

            Db::name('hongbao')->where('aid', $aid)->where('id', $hid)
                ->update(['givenmoney' => Db::raw('givenmoney+' . $money)]);

            // 发放（wx_transfer mock 通道；失败落 status=2）
            try {
                $this->transfer($aid, $mid, $money, (string) $hd['wishing'], $hid);
                $status = 1;
                $remark = '发放成功';
            } catch (\Throwable $e) {
                $status = 2;
                $remark = $e->getMessage();

                Log::error('hongbao transfer fail hid#' . $hid . ' mid#' . $mid . ': ' . $e->getMessage());
            }

            Db::name('hongbao_record')->insert([
                'aid'        => $aid,
                'hid'        => $hid,
                'name'       => (string) ($hd['name'] ?? ''),
                'mid'        => $mid,
                'headimg'    => (string) ($member['headimg'] ?? ''),
                'nickname'   => (string) ($member['nickname'] ?? ''),
                'money'      => $money,
                'createtime' => time(),
                'status'     => $status,
                'remark'     => $remark,
            ]);

            if (!empty($codeinfo)) {
                Db::name('hongbao_codelist')->where('id', (int) $codeinfo['id'])->update([
                    'status'   => 1,
                    'usetime'  => time(),
                    'mid'      => $mid,
                    'headimg'  => (string) ($member['headimg'] ?? ''),
                    'nickname' => (string) ($member['nickname'] ?? ''),
                    'money'    => $money,
                    'remark'   => $remark,
                ]);
            }

            $st  = 1 === $status ? 1 : 10;
            $msg = 1 === $status ? '恭喜抢到红包' : '很遗憾没有抢到';
        });

        return ['st' => $st, 'msg' => $msg, 'money' => $money];
    }

    /**
     * C 端活动信息（展示用，不含预算敏感字段口径保留 givenmoney 供前端进度条）
     *
     * @return array<string, mixed>
     */
    public function detail(int $aid, int $hid): array
    {
        $hd = ScopeService::query('hongbao', $aid)->where('id', $hid)->find();

        if (empty($hd)) {
            throw new BusinessException('活动不存在');
        }

        return (array) $hd;
    }

    /* ================= 内部工具 ================= */

    /**
     * wx_transfer 发放（mock 通道落 wx_transfer_log；真实通道未接入视为失败）
     */
    protected function transfer(int $aid, int $mid, float $money, string $wishing, int $hid): void
    {
        if (!PayService::isMock()) {
            throw new BusinessException('真实微信转账通道未接入');
        }

        $member = Db::name('member')->where('id', $mid)->where('aid', $aid)->find();

        Db::name('wx_transfer_log')->insert([
            'aid'              => $aid,
            'mid'              => $mid,
            'ordernum'         => 'HB' . date('YmdHis') . str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT),
            'money'            => $money,
            'realname'         => (string) ($member['realname'] ?? ''),
            'desc'             => '' !== $wishing ? $wishing : '微信红包',
            'data_tbl'         => 'hongbao',
            'data_id'          => $hid,
            'status_code'      => 'SUCCESS',
            'createtime'       => time(),
            'state'            => 'SUCCESS',
            'transfer_bill_no' => 'MOCK' . date('YmdHis') . random_int(1000, 9999),
            'message'          => '',
        ]);
    }

    /**
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

        throw new BusinessException('您没有领取权限');
    }

    /**
     * 随机抽奖码（数字+大写字母，去易混淆字符）
     */
    protected function randCode(int $length): string
    {
        $pool = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= $pool[random_int(0, strlen($pool) - 1)];
        }

        return $code;
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
