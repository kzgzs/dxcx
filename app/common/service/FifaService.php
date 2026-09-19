<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;

/**
 * Fifa 世界杯竞猜服务（批次4-E 任务190）
 *
 * 口径对照参考系统 Fifa / ApiFifa 控制器：
 * - fifa 赛事改手工录入（外部 API 已过时），按 aid 隔离（20260817002303 补 aid 列）
 * - fifa_set 配置：竞猜题（guess1set/guess2set）/ 送积分 / 规则 / 截止时间 starthour
 * - fifa_record：竞猜提交（guess1/guess2）+ 开奖（guess1st/guess2st + givescore1/givescore2 实发积分）
 * - 截止口径：开赛前 starthour 小时内可竞猜（startTimestamp > time+starthour*3600 未开始；< time 已结束）
 * - 开奖口径：以 90 分钟常规赛比分（leftTeam_score/rightTeam_score）判定猜胜负；
 *   guess2 按「主:客」精确匹配猜比分
 */
class FifaService
{
    public function __construct(protected ScoreService $scoreService)
    {
    }

    /* ================= B 端：赛事录入 ================= */

    /**
     * @return array{list: array<int, array<string, mixed>>, total: int}
     */
    public function matchList(int $aid, string $keyword, int $page, int $pageSize): array
    {
        $query = Db::name('fifa')->where('aid', $aid);

        if ('' !== $keyword) {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('leftTeam_name', '%' . $keyword . '%')
                    ->whereOr('rightTeam_name', 'like', '%' . $keyword . '%');
            });
        }

        $total = (clone $query)->count();
        $rows  = $query->order('startTimestamp', 'asc')->order('id', 'asc')->page($page, $pageSize)->select()->toArray();

        foreach ($rows as &$row) {
            $row['open'] = $this->isOpen($row);
        }

        unset($row);

        return ['list' => $rows, 'total' => $total];
    }

    /**
     * @return array<string, mixed>
     */
    public function matchDetail(int $aid, int $id): array
    {
        $row = Db::name('fifa')->where('id', $id)->where('aid', $aid)->find();

        if (empty($row)) {
            throw new BusinessException('赛事不存在');
        }

        return (array) $row;
    }

    /**
     * 赛事录入/编辑
     *
     * @param array<string, mixed> $data
     * @return array{id: int}
     */
    public function saveMatch(int $aid, array $data): array
    {
        $id    = (int) ($data['id'] ?? 0);
        $left  = trim((string) ($data['leftTeam_name'] ?? ''));
        $right = trim((string) ($data['rightTeam_name'] ?? ''));

        if ('' === $left || '' === $right) {
            throw new BusinessException('请输入主客队名称');
        }

        $startDate = trim((string) ($data['startDate'] ?? ''));
        $startTime = trim((string) ($data['startTime'] ?? ''));

        // m-d 自动补当年
        if (preg_match('/^\d{1,2}-\d{1,2}$/', $startDate)) {
            $startDate = date('Y') . '-' . $startDate;
        }

        $stamp = strtotime($startDate . ' ' . ($startTime ?: '00:00'));

        if (false === $stamp || $stamp <= 0) {
            throw new BusinessException('请输入正确的开赛日期时间');
        }

        $update = [
            'matchId'                    => trim((string) ($data['matchId'] ?? '')) ?: (string) ($id > 0 ? $id : uniqid('m')),
            'startDate'                  => date('m-d', $stamp),
            'startTime'                  => date('H:i', $stamp),
            'matchStage'                 => trim((string) ($data['matchStage'] ?? '')),
            'matchDesc'                  => trim((string) ($data['matchDesc'] ?? '')),
            'matchStatus'                => trim((string) ($data['matchStatus'] ?? '')),
            'matchStatusText'            => trim((string) ($data['matchStatusText'] ?? '')),
            'leftTeam_name'              => $left,
            'leftTeam_logo'              => trim((string) ($data['leftTeam_logo'] ?? '')),
            'leftTeam_score'             => trim((string) ($data['leftTeam_score'] ?? '')),
            'leftTeam_BigScore'          => trim((string) ($data['leftTeam_BigScore'] ?? '')),
            'leftTeam_penaltyScore'      => trim((string) ($data['leftTeam_penaltyScore'] ?? '')),
            'rightTeam_name'             => $right,
            'rightTeam_logo'             => trim((string) ($data['rightTeam_logo'] ?? '')),
            'rightTeam_score'            => trim((string) ($data['rightTeam_score'] ?? '')),
            'rightTeam_BigScore'         => trim((string) ($data['rightTeam_BigScore'] ?? '')),
            'rightTeam_penaltyScore'     => trim((string) ($data['rightTeam_penaltyScore'] ?? '')),
            'startTimestamp'             => $stamp,
        ];

        if ($id > 0) {
            $exists = Db::name('fifa')->where('id', $id)->where('aid', $aid)->find();

            if (empty($exists)) {
                throw new BusinessException('赛事不存在');
            }

            Db::name('fifa')->where('id', $id)->update($update);
        } else {
            $update['aid']        = $aid;
            $update['createtime'] = time();

            $id = (int) Db::name('fifa')->insertGetId($update);
        }

        return ['id' => $id];
    }

    /**
     * @param array<int, int> $ids
     */
    public function deleteMatch(int $aid, array $ids): void
    {
        $exists = Db::name('fifa')->where('aid', $aid)->whereIn('id', $ids)->column('id');

        if (empty($exists)) {
            throw new BusinessException('赛事不存在');
        }

        Db::name('fifa_record')->where('aid', $aid)->whereIn('hid', $exists)->delete();
        Db::name('fifa')->where('aid', $aid)->whereIn('id', $exists)->delete();
    }

    /* ================= B 端：竞猜设置 ================= */

    /**
     * @return array<string, mixed>
     */
    public function getSet(int $aid): array
    {
        $row = Db::name('fifa_set')->where('aid', $aid)->find();

        if (empty($row)) {
            Db::name('fifa_set')->insert([
                'aid'   => $aid,
                'guize' => '猜比分及猜胜负均以90分钟常规赛结果为准（即不包含点球大战比分）',
            ]);

            $row = Db::name('fifa_set')->where('aid', $aid)->find();
        }

        $row              = (array) $row;
        $row['guess1set'] = $this->jsonToArray((string) ($row['guess1set'] ?? '')) ?: ['主胜', '平局', '客胜'];
        $row['guess2set'] = $this->jsonToArray((string) ($row['guess2set'] ?? ''));

        return $row;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function saveSet(int $aid, array $data): void
    {
        $status = (int) ($data['status'] ?? 0);

        if (!in_array($status, [0, 1], true)) {
            throw new BusinessException('状态值无效');
        }

        $update = [
            'name'       => trim((string) ($data['name'] ?? '世界杯竞猜')),
            'givescore1' => max(0, (int) ($data['givescore1'] ?? 0)),
            'givescore2' => max(0, (int) ($data['givescore2'] ?? 0)),
            'guess1set'  => json_encode(array_values(array_filter(array_map('strval', (array) ($data['guess1set'] ?? [])))), JSON_UNESCAPED_UNICODE),
            'guess2set'  => json_encode(array_values(array_filter(array_map('strval', (array) ($data['guess2set'] ?? [])))), JSON_UNESCAPED_UNICODE),
            'status'     => $status,
            'guize'      => trim((string) ($data['guize'] ?? '')),
            'starthour'  => max(0, (int) ($data['starthour'] ?? 24)),
        ];

        $exists = Db::name('fifa_set')->where('aid', $aid)->find();

        if ($exists) {
            Db::name('fifa_set')->where('aid', $aid)->update($update);
        } else {
            $update['aid'] = $aid;

            Db::name('fifa_set')->insert($update);
        }
    }

    /* ================= B 端：竞猜记录 + 开奖 ================= */

    /**
     * @return array{list: array<int, array<string, mixed>>, total: int}
     */
    public function recordList(int $aid, int $hid, string $nickname, int $page, int $pageSize): array
    {
        $query = Db::name('fifa_record')->where('aid', $aid);

        if ($hid > 0) {
            $query->where('hid', $hid);
        }

        if ('' !== $nickname) {
            $query->where('nickname', 'like', '%' . $nickname . '%');
        }

        $total = (clone $query)->count();
        $rows  = $query->order('id', 'desc')->page($page, $pageSize)->select()->toArray();

        $matches = Db::name('fifa')->where('aid', $aid)
            ->column('leftTeam_name,rightTeam_name,startDate,startTime', 'id');

        foreach ($rows as &$row) {
            $m = $matches[(int) $row['hid']] ?? [];

            $row['duiju']           = ($m['leftTeam_name'] ?? '') . ' vs ' . ($m['rightTeam_name'] ?? '');
            $row['startTime']       = ($m['startDate'] ?? '') . ' ' . ($m['startTime'] ?? '');
            $row['createtime_text'] = (int) $row['createtime'] > 0 ? date('Y-m-d H:i:s', (int) $row['createtime']) : '';
        }

        unset($row);

        return ['list' => $rows, 'total' => $total];
    }

    /**
     * @param array<int, int> $ids
     */
    public function deleteRecords(int $aid, array $ids): void
    {
        Db::name('fifa_record')->where('aid', $aid)->whereIn('id', $ids)->delete();
    }

    /**
     * 单场开奖：按录入的常规赛比分判定全部竞猜记录并发放积分
     *
     * @return array{total: int, win1: int, win2: int}
     */
    public function openMatch(int $aid, int $hid): array
    {
        $match = Db::name('fifa')->where('id', $hid)->where('aid', $aid)->find();

        if (empty($match)) {
            throw new BusinessException('赛事不存在');
        }

        $leftScore  = trim((string) ($match['leftTeam_score'] ?? ''));
        $rightScore = trim((string) ($match['rightTeam_score'] ?? ''));

        if ('' === $leftScore || '' === $rightScore || !is_numeric($leftScore) || !is_numeric($rightScore)) {
            throw new BusinessException('请先录入该场比赛的常规赛比分再开奖');
        }

        $leftScore  = (int) $leftScore;
        $rightScore = (int) $rightScore;

        $result = $leftScore > $rightScore ? '主胜' : ($leftScore < $rightScore ? '客胜' : '平局');
        $score2 = $leftScore . ':' . $rightScore;

        $set = Db::name('fifa_set')->where('aid', $aid)->find();

        $give1 = (int) ($set['givescore1'] ?? 0);
        $give2 = (int) ($set['givescore2'] ?? 0);

        $records = Db::name('fifa_record')->where('aid', $aid)->where('hid', $hid)->select()->toArray();

        $win1 = 0;
        $win2 = 0;

        foreach ($records as $record) {
            $update = [];

            // 猜胜负
            if (0 === (int) $record['guess1st'] && '' !== trim((string) ($record['guess1'] ?? ''))) {
                $hit          = trim((string) $record['guess1']) === $result;
                $update['guess1st']  = $hit ? 1 : 2;
                $update['givescore1'] = $hit ? $give1 : 0;

                if ($hit) {
                    $win1++;

                    if ($give1 > 0) {
                        $this->scoreService->adjust($aid, (int) $record['mid'], $give1, '世界杯竞猜猜中胜负 场次#' . $hid);
                    }
                }
            }

            // 猜比分（归一化「主:客」精确匹配）
            if (0 === (int) $record['guess2st'] && '' !== trim((string) ($record['guess2'] ?? ''))) {
                $hit = $this->normalizeScore((string) $record['guess2']) === $score2;

                $update['guess2st']  = $hit ? 1 : 2;
                $update['givescore2'] = $hit ? $give2 : 0;

                if ($hit) {
                    $win2++;

                    if ($give2 > 0) {
                        $this->scoreService->adjust($aid, (int) $record['mid'], $give2, '世界杯竞猜猜中比分 场次#' . $hid);
                    }
                }
            }

            if (!empty($update)) {
                $update['status'] = 1;

                Db::name('fifa_record')->where('id', (int) $record['id'])->update($update);
            }
        }

        // 标记赛事已开奖
        Db::name('fifa')->where('id', $hid)->update(['matchStatus' => '3', 'matchStatusText' => '已开奖']);

        return ['total' => count($records), 'win1' => $win1, 'win2' => $win2];
    }

    /* ================= C 端 ================= */

    /**
     * 竞猜首页（设置 + 按日期分组赛事 + 我的战绩）
     *
     * @return array<string, mixed>
     */
    public function apiIndex(int $aid, int $mid): array
    {
        $set = Db::name('fifa_set')->where('aid', $aid)->find();

        if (empty($set) || 0 === (int) $set['status']) {
            throw new BusinessException('活动未开启');
        }

        $rows = Db::name('fifa')->where('aid', $aid)->order('startTimestamp', 'asc')->order('id', 'asc')->select()->toArray();

        $grouped = [];

        foreach ($rows as $row) {
            $date = (string) ($row['startDate'] ?? '');

            if (!isset($grouped[$date])) {
                $grouped[$date] = [
                    'date' => $date,
                    'week' => $this->weekText($date),
                    'data' => [],
                ];
            }

            $grouped[$date]['data'][] = $row;
        }

        $successNum = Db::name('fifa_record')->where('aid', $aid)->where('mid', $mid)
            ->whereRaw('guess1st=1 or guess2st=1')->count();
        $winScore = (int) Db::name('fifa_record')->where('aid', $aid)->where('mid', $mid)->sum('givescore1')
            + (int) Db::name('fifa_record')->where('aid', $aid)->where('mid', $mid)->sum('givescore2');
        $myScore = (int) (Db::name('member')->where('id', $mid)->where('aid', $aid)->value('score') ?? 0);

        return [
            'datalist'   => array_values($grouped),
            'myscore'    => $myScore,
            'successnum' => $successNum,
            'winscore'   => $winScore,
            'guize'      => (string) ($set['guize'] ?? ''),
        ];
    }

    /**
     * 场次详情（含我的竞猜与竞猜状态 0无 1可竞猜 2已结束）
     *
     * @return array<string, mixed>
     */
    public function apiDetail(int $aid, int $mid, int $hid): array
    {
        $set = Db::name('fifa_set')->where('aid', $aid)->find();

        if (empty($set) || 0 === (int) $set['status']) {
            throw new BusinessException('活动未开启');
        }

        $detail = Db::name('fifa')->where('id', $hid)->where('aid', $aid)->find();

        if (empty($detail)) {
            throw new BusinessException('赛事不存在');
        }

        $myrecord = Db::name('fifa_record')->where('aid', $aid)->where('mid', $mid)->where('hid', $hid)->find();

        $guessStatus = 0;
        $stamp       = (int) ($detail['startTimestamp'] ?? 0);
        $starthour   = (int) ($set['starthour'] ?? 24);

        // 0 当前可竞猜；1 竞猜窗口未开（距开赛超过 starthour 小时）；2 已开赛结束竞猜
        if ($stamp > time() + $starthour * 3600) {
            $guessStatus = 1;
        }

        if ($stamp < time()) {
            $guessStatus = 2;
        }

        $set              = (array) $set;
        $set['guess1set'] = $this->jsonToArray((string) ($set['guess1set'] ?? '')) ?: ['主胜', '平局', '客胜'];
        $set['guess2set'] = $this->jsonToArray((string) ($set['guess2set'] ?? ''));

        return [
            'detail'      => (array) $detail,
            'myrecord'    => empty($myrecord) ? [] : (array) $myrecord,
            'sset'        => $set,
            'guessStatus' => $guessStatus,
        ];
    }

    /**
     * 提交竞猜（guesstype 1猜胜负 2猜比分；每场每题限一次）
     *
     * @return array{msg: string}
     */
    public function apiSubguess(int $aid, int $mid, int $hid, int $guesstype, string $guess): array
    {
        if (!in_array($guesstype, [1, 2], true)) {
            throw new BusinessException('参数错误');
        }

        $guess = trim($guess);

        if ('' === $guess) {
            throw new BusinessException('请输入竞猜内容');
        }

        $set = Db::name('fifa_set')->where('aid', $aid)->find();

        if (empty($set) || 0 === (int) $set['status']) {
            throw new BusinessException('活动未开启');
        }

        $detail = Db::name('fifa')->where('id', $hid)->where('aid', $aid)->find();

        if (empty($detail)) {
            throw new BusinessException('赛事不存在');
        }

        $stamp     = (int) ($detail['startTimestamp'] ?? 0);
        $starthour = (int) ($set['starthour'] ?? 24);

        if ($stamp > time() + $starthour * 3600) {
            throw new BusinessException('本场竞猜尚未开始');
        }

        if ($stamp < time()) {
            throw new BusinessException('本场竞猜已结束');
        }

        $myrecord = Db::name('fifa_record')->where('aid', $aid)->where('mid', $mid)->where('hid', $hid)->find();

        if (1 === $guesstype) {
            if (!empty($myrecord) && '' !== trim((string) ($myrecord['guess1'] ?? ''))) {
                throw new BusinessException('本场您已竞猜过了');
            }
        } else {
            if (!empty($myrecord) && '' !== trim((string) ($myrecord['guess2'] ?? ''))) {
                throw new BusinessException('本场您已竞猜过了');
            }
        }

        if (!empty($myrecord)) {
            Db::name('fifa_record')->where('id', (int) $myrecord['id'])
                ->update([1 === $guesstype ? 'guess1' : 'guess2' => $guess]);
        } else {
            $member = Db::name('member')->where('id', $mid)->where('aid', $aid)->find();

            Db::name('fifa_record')->insert([
                'aid'        => $aid,
                'hid'        => $hid,
                'mid'        => $mid,
                'headimg'    => (string) ($member['headimg'] ?? ''),
                'nickname'   => (string) ($member['nickname'] ?? ''),
                1 === $guesstype ? 'guess1' : 'guess2' => $guess,
                'createtime' => time(),
            ]);
        }

        return ['msg' => '竞猜成功'];
    }

    /* ================= 内部工具 ================= */

    /**
     * 是否已开奖（录入比分 + matchStatus=3）
     *
     * @param array<string, mixed> $row
     */
    protected function isOpen(array $row): bool
    {
        return '3' === (string) ($row['matchStatus'] ?? '');
    }

    /**
     * 归一化比分：全角冒号/中文冒号/横线统一为「主:客」
     */
    protected function normalizeScore(string $text): string
    {
        $text = trim($text);
        $text = str_replace(['：', '－', '—', ' vs ', 'VS'], ':', $text);
        $text = str_replace('-', ':', $text);

        if (preg_match('/^(\d+)\s*[:]\s*(\d+)$/', $text, $m)) {
            return $m[1] . ':' . $m[2];
        }

        return $text;
    }

    /**
     * @return array<int, string>
     */
    protected function jsonToArray(string $json): array
    {
        $data = json_decode($json, true);

        return is_array($data) ? array_values(array_filter(array_map('strval', $data), static fn ($v) => '' !== trim($v))) : [];
    }

    protected function weekText(string $date): string
    {
        $stamp = strtotime(date('Y') . '-' . $date);

        if (false === $stamp) {
            return '';
        }

        $map = ['星期天', '星期一', '星期二', '星期三', '星期四', '星期五', '星期六'];

        return $map[(int) date('w', $stamp)] ?? '';
    }
}
