<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;

/**
 * 知识付费·题库与考试服务（批次5-D2 任务198）
 *
 * 对标参考系统 canzhao/app/controller/ApiKecheng.php 考试段（getTiku..geterrotiku/givescore/讲师段）
 * 与 canzhao/app/controller/KechengTiku.php（B端题库管理）：
 * - 抽题：已合格阻断 → 未答完续答（timu JSON 固化题目不变）→ 否则随机抽 dtnum 题固化
 * - 判分：多选数量相等且每项在正确答案集合内；单选/填空直接比对
 * - 交卷：sum(score where status=1) 与 hgscore 比对置 ishg；限时 sxdate 分钟，
 *   getTiku/答题时惰性判超时直接结算（isend=1），无定时任务
 * - 写卷完成 kecheng_record.ishg 后，KechengService::isChapterPassed() 解锁链自动生效
 * - 答题送积分：kecheng_sysset.give_score_num 0仅首次/1每次，recordlog.give_score 幂等
 * - 讲师体系：kecheng_sysset.lecturer_open 开关（对标 getcustom('kecheng_lecturer')）
 */
class KechengExamService
{
    /** 选项序号字母表 */
    protected const OPTION_SORTS = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];

    /* ==================== B端：题库管理 ==================== */

    /**
     * 题库列表（按课程/章节/题型/状态/关键词筛选）
     *
     * @return array{list: array<int, array<string, mixed>>, total: int, page: int, pagesize: int}
     */
    public function tikuList(int $aid, int $kcid, int $mlid, string $keyword, string $type, string $status, int $page, int $pageSize): array
    {
        $query = ScopeService::query('kecheng_tiku', $aid);

        if ($kcid > 0) {
            $query->where('kcid', $kcid);
        }
        if ($mlid > 0) {
            $query->where('mlid', $mlid);
        }
        if ('' !== $keyword) {
            $query->where('title', 'like', '%' . $keyword . '%');
        }
        if ('' !== $type) {
            $query->where('type', (int) $type);
        }
        if ('' !== $status) {
            $query->where('status', (int) $status);
        }

        $total = (clone $query)->count();
        $rows  = $query->order('id', 'desc')->page($page, $pageSize)->select()->toArray();

        // 课程/章节名回显 + 正确答案字母化
        $kcNames = Db::name('kecheng_list')->where('aid', $aid)->column('name', 'id');
        $mlNames = Db::name('kecheng_chapter')->where('aid', $aid)->column('name', 'id');

        foreach ($rows as $k => $row) {
            $rows[$k]['kcname']  = $kcNames[(int) $row['kcid']] ?? '';
            $rows[$k]['mlname']  = $mlNames[(int) $row['mlid']] ?? '';
            $rows[$k]['tname']   = 1 === (int) $row['type'] ? '选择题' : '填空题';
            $rows[$k]['options'] = 1 === (int) $row['type']
                ? (json_decode((string) $row['option_group'], true) ?: [])
                : [];

            if (1 === (int) $row['type']) {
                // 正确答案下标 → 字母（编辑回显用数组，列表展示用字母）
                $idx = array_filter(explode(',', (string) $row['right_option']), 'strlen');
                $rows[$k]['right_option_text'] = implode(',', array_map(
                    fn ($i) => self::OPTION_SORTS[(int) $i] ?? '?',
                    $idx
                ));
                $rows[$k]['right_option'] = array_map('intval', $idx);
            }
        }

        return ['list' => $rows, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize];
    }

    /**
     * 题库新增/编辑
     *
     * 选择题（type=1）：options 为 [{value, key}]（key=1 正确答案），right_option 落逗号下标；
     * 填空题（type=2）：right_option 直接存答案文本
     *
     * @param array<string, mixed> $data
     * @return array{id: int}
     */
    public function tikuSave(int $aid, array $data): array
    {
        $id   = (int) ($data['id'] ?? 0);
        $kcid = (int) ($data['kcid'] ?? 0);
        $mlid = (int) ($data['mlid'] ?? 0);
        $type = (int) ($data['type'] ?? 1);

        $kc = ScopeService::query('kecheng_list', $aid)->where('id', $kcid)->find();
        if (empty($kc)) {
            throw new BusinessException('请选择所属课程');
        }
        if ($mlid > 0) {
            $ml = ScopeService::query('kecheng_chapter', $aid)->where('id', $mlid)->where('kcid', $kcid)->find();
            if (empty($ml)) {
                throw new BusinessException('章节不属于该课程');
            }
        }

        $title = trim((string) ($data['title'] ?? ''));
        if ('' === $title) {
            throw new BusinessException('请填写题目');
        }
        if (!in_array($type, [1, 2], true)) {
            throw new BusinessException('题型错误');
        }

        $row = [
            'kcid'       => $kcid,
            'mlid'       => $mlid,
            'title'      => $title,
            'type'       => $type,
            'score'      => (float) ($data['score'] ?? 0),
            'status'     => (int) ($data['status'] ?? 1),
            'jiexi'      => (string) ($data['jiexi'] ?? ''),
            'give_score' => max(0, (int) ($data['give_score'] ?? 0)),
            'sort'       => (int) ($data['sort'] ?? 0),
        ];

        if ($row['score'] <= 0) {
            throw new BusinessException('题目分值必须大于0');
        }

        if (1 === $type) {
            $options = (array) ($data['options'] ?? []);
            $rightOption = [];
            $optionValues = [];

            foreach ($options as $key => $opt) {
                $value = trim((string) ($opt['value'] ?? ''));
                if ('' === $value) {
                    continue;
                }
                if (1 === (int) ($opt['key'] ?? 0)) {
                    $rightOption[] = (string) $key;
                }
                $optionValues[] = $value;
            }

            if (count($optionValues) < 2) {
                throw new BusinessException('选择题至少填写两个选项');
            }
            if (empty($rightOption)) {
                throw new BusinessException('请设置一个正确答案');
            }

            $row['option_group'] = json_encode($optionValues, JSON_UNESCAPED_UNICODE);
            $row['right_option'] = implode(',', $rightOption);
        } else {
            $right = trim((string) ($data['right_option'] ?? ''));
            if ('' === $right) {
                throw new BusinessException('请填写填空题答案');
            }
            $row['option_group'] = '';
            $row['right_option'] = $right;
        }

        if ($id > 0) {
            $tiku = ScopeService::query('kecheng_tiku', $aid)->where('id', $id)->find();
            if (empty($tiku)) {
                throw new BusinessException('题目不存在', 404, 404);
            }
            Db::name('kecheng_tiku')->where('id', $id)->where('aid', $aid)->update($row);
        } else {
            $row['aid']        = $aid;
            $row['bid']        = 0;
            $row['createtime'] = time();
            $id = (int) Db::name('kecheng_tiku')->insertGetId($row);
        }

        return ['id' => $id];
    }

    /**
     * 题库删除（批量）
     *
     * @param array<int, int> $ids
     */
    public function tikuDelete(int $aid, array $ids): void
    {
        if (empty($ids)) {
            throw new BusinessException('请选择题目');
        }

        ScopeService::query('kecheng_tiku', $aid)->whereIn('id', $ids)->delete();
    }

    /**
     * 题库批量启停用
     *
     * @param array<int, int> $ids
     */
    public function tikuStatus(int $aid, array $ids, int $status): void
    {
        if (empty($ids)) {
            throw new BusinessException('请选择题目');
        }

        ScopeService::query('kecheng_tiku', $aid)->whereIn('id', $ids)->update(['status' => $status]);
    }

    /**
     * Excel 批量导入（对标参考系统 importexcel）
     *
     * 兼容两种格式（自动识别）：
     * - 参考系统 9 列：课程id/题目/选项A-D/正确答案(下标或字母，多选逗号分隔)/分值/解析
     * - 扩展 11 列：课程id/章节id/题型(选择题|填空题)/题目/选项A-D/正确答案/分值/解析
     *
     * @param array<int, array<int, string>> $rows
     * @return array{total: int, success: int, fail: int, errors: array<int, string>}
     */
    public function tikuImport(int $aid, array $rows): array
    {
        // 首行若为表头（含“题目”字样）自动跳过
        if (!empty($rows)) {
            $head = implode('', array_map('strval', $rows[0]));
            if (str_contains($head, '题目') || str_contains($head, '课程')) {
                array_shift($rows);
            }
        }

        $kcIds = array_map('intval', ScopeService::query('kecheng_list', $aid)->column('id'));
        $kcIds = array_flip($kcIds);

        $success = 0;
        $errors  = [];

        foreach ($rows as $i => $r) {
            $lineNo = $i + 1;

            if (count($r) >= 11) {
                [$kcid, $mlid, $typeName, $title] = [$r[0] ?? '', $r[1] ?? '', $r[2] ?? '', $r[3] ?? ''];
                $opts = [$r[4] ?? '', $r[5] ?? '', $r[6] ?? '', $r[7] ?? ''];
                [$right, $score, $jiexi] = [$r[8] ?? '', $r[9] ?? '', $r[10] ?? ''];
                $type = str_contains((string) $typeName, '填空') ? 2 : 1;
            } else {
                [$kcid, $title] = [$r[0] ?? '', $r[1] ?? ''];
                $opts = [$r[2] ?? '', $r[3] ?? '', $r[4] ?? '', $r[5] ?? ''];
                [$right, $score, $jiexi] = [$r[6] ?? '', $r[7] ?? '', $r[8] ?? ''];
                $mlid = '';
                $type = 1;
            }

            $kcid = (int) $kcid;
            if ($kcid <= 0 || '' === trim((string) $title)) {
                continue;
            }
            if (!isset($kcIds[$kcid])) {
                $errors[] = sprintf('第%d行：课程%d不存在', $lineNo, $kcid);
                continue;
            }

            $row = [
                'aid'         => $aid,
                'bid'         => 0,
                'kcid'        => $kcid,
                'mlid'        => max(0, (int) $mlid),
                'title'       => trim((string) $title),
                'type'        => $type,
                'score'       => (float) ($score ?: 10),
                'jiexi'       => (string) $jiexi,
                'give_score'  => 0,
                'status'      => 1,
                'createtime'  => time(),
            ];

            if (1 === $type) {
                $optionValues = array_values(array_filter(array_map(
                    fn ($v) => trim((string) $v),
                    $opts
                ), 'strlen'));

                if (count($optionValues) < 2) {
                    $errors[] = sprintf('第%d行：选择题至少两个选项', $lineNo);
                    continue;
                }

                $rightIdx = [];
                foreach (array_filter(explode(',', trim((string) $right)), 'strlen') as $one) {
                    $one = strtoupper(trim($one));
                    $pos = array_search($one, self::OPTION_SORTS, true);
                    $rightIdx[] = false !== $pos ? $pos : (int) $one;
                }

                if (empty($rightIdx)) {
                    $errors[] = sprintf('第%d行：缺少正确答案', $lineNo);
                    continue;
                }

                $row['option_group'] = json_encode($optionValues, JSON_UNESCAPED_UNICODE);
                $row['right_option'] = implode(',', $rightIdx);
            } else {
                if ('' === trim((string) $right)) {
                    $errors[] = sprintf('第%d行：填空题缺少答案', $lineNo);
                    continue;
                }
                $row['option_group'] = '';
                $row['right_option'] = trim((string) $right);
            }

            Db::name('kecheng_tiku')->insert($row);
            ++$success;
        }

        return ['total' => count($rows), 'success' => $success, 'fail' => count($rows) - $success, 'errors' => $errors];
    }

    /**
     * 题库导出行（11 列扩展格式，可回导）
     *
     * @return array<int, array<int, mixed>>
     */
    public function tikuExportRows(int $aid, int $kcid, int $mlid): array
    {
        $query = ScopeService::query('kecheng_tiku', $aid)->order('id', 'asc');
        if ($kcid > 0) {
            $query->where('kcid', $kcid);
        }
        if ($mlid > 0) {
            $query->where('mlid', $mlid);
        }

        $list = $query->select()->toArray();
        $rows = [];

        foreach ($list as $v) {
            $opts = 1 === (int) $v['type'] ? (json_decode((string) $v['option_group'], true) ?: []) : [];

            if (1 === (int) $v['type']) {
                $letters = [];
                foreach (array_filter(explode(',', (string) $v['right_option']), 'strlen') as $idx) {
                    $letters[] = self::OPTION_SORTS[(int) $idx] ?? '?';
                }
                $rightText = implode(',', $letters);
            } else {
                $rightText = (string) $v['right_option'];
            }

            $rows[] = [
                (int) $v['kcid'],
                (int) $v['mlid'],
                1 === (int) $v['type'] ? '选择题' : '填空题',
                (string) $v['title'],
                (string) ($opts[0] ?? ''),
                (string) ($opts[1] ?? ''),
                (string) ($opts[2] ?? ''),
                (string) ($opts[3] ?? ''),
                $rightText,
                (float) $v['score'],
                (string) $v['jiexi'],
            ];
        }

        return $rows;
    }

    /**
     * 课程下章节选项（题库筛选下拉）
     *
     * @return array{list: array<int, array<string, mixed>>}
     */
    public function chapterOptions(int $aid, int $kcid): array
    {
        $list = ScopeService::query('kecheng_chapter', $aid)
            ->where('kcid', $kcid)
            ->where('status', 1)
            ->field('id,name')
            ->order('sort', 'desc')
            ->order('id', 'desc')
            ->select()
            ->toArray();

        return ['list' => $list];
    }

    /* ==================== B端：讲师审核 ==================== */

    /**
     * 讲师列表
     *
     * @return array{list: array<int, array<string, mixed>>, total: int, page: int, pagesize: int}
     */
    public function lecturerList(int $aid, string $keyword, string $checkstatus, int $page, int $pageSize): array
    {
        $query = ScopeService::query('kecheng_lecturer', $aid);

        if ('' !== $keyword) {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('nickname', '%' . $keyword . '%')
                    ->whereOr('realname', 'like', '%' . $keyword . '%')
                    ->whereOr('tel', 'like', '%' . $keyword . '%');
            });
        }
        if ('' !== $checkstatus) {
            $query->where('checkstatus', (int) $checkstatus);
        }

        $total = (clone $query)->count();
        $rows  = $query->order('id', 'desc')->page($page, $pageSize)->select()->toArray();

        foreach ($rows as $k => $row) {
            $rows[$k]['course_count'] = (int) ScopeService::query('kecheng_list', $aid)
                ->where('lecturerid', (int) $row['id'])
                ->count();
        }

        return ['list' => $rows, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize];
    }

    /**
     * 讲师审核（通过/驳回）
     */
    public function lecturerCheck(int $aid, int $id, int $checkstatus, string $reason): void
    {
        if (!in_array($checkstatus, [1, -1], true)) {
            throw new BusinessException('审核状态错误');
        }

        $lecturer = ScopeService::query('kecheng_lecturer', $aid)->where('id', $id)->find();
        if (empty($lecturer)) {
            throw new BusinessException('讲师不存在', 404, 404);
        }

        Db::name('kecheng_lecturer')->where('id', $id)->where('aid', $aid)->update([
            'checkstatus'  => $checkstatus,
            'check_reason' => $checkstatus > 0 ? '' : $reason,
            'updatetime'   => time(),
        ]);
    }

    /**
     * 讲师启停用
     */
    public function lecturerStatus(int $aid, int $id, int $status): void
    {
        $lecturer = ScopeService::query('kecheng_lecturer', $aid)->where('id', $id)->find();
        if (empty($lecturer)) {
            throw new BusinessException('讲师不存在', 404, 404);
        }

        Db::name('kecheng_lecturer')->where('id', $id)->where('aid', $aid)->update([
            'status'     => $status,
            'updatetime' => time(),
        ]);
    }

    /* ==================== C端：考试引擎 ==================== */

    /**
     * 抽题开考（对标 getTiku）
     *
     * 已合格阻断 → 未答完卷续答（timu 固化题目不变）→ 否则随机抽 dtnum 题固化；
     * 限时 sxdate 分钟，惰性判超时直接结算（isend=1）
     *
     * @return array<string, mixed>
     */
    public function apiGetTiku(int $aid, int $mid, int $kcid, int $mlid): array
    {
        $set = ScopeService::query('kecheng_list', $aid)
            ->field('id,aid,bid,sxdate,isdt,dtnum,hgscore,orderlearn,learnhg')
            ->where('id', $kcid)
            ->find();

        if (empty($set)) {
            throw new BusinessException('该课程不存在', 404, 404);
        }
        if (0 === (int) $set['isdt']) {
            throw new BusinessException('该课程未开启答题');
        }

        $chapterMode = 1 === (int) $set['orderlearn'] || 1 === (int) $set['learnhg'];

        if ($chapterMode) {
            if ($mlid <= 0) {
                throw new BusinessException('请选择要作答的章节');
            }
            $study = ScopeService::query('kecheng_studylog', $aid)
                ->where('mid', $mid)
                ->where('mlid', $mlid)
                ->where('kcid', $kcid)
                ->where('status', 1)
                ->find();
            if (empty($study)) {
                throw new BusinessException('请学习完成后再答题');
            }
            $chapter = ScopeService::query('kecheng_chapter', $aid)
                ->where('id', $mlid)
                ->where('kcid', $kcid)
                ->where('status', 1)
                ->count();
            if ($chapter <= 0) {
                throw new BusinessException('此章节不存在');
            }
        }

        // 已合格阻断重考
        $passedQuery = ScopeService::query('kecheng_record', $aid)
            ->where('kcid', $kcid)
            ->where('mid', $mid)
            ->where('ishg', 1);
        if ($chapterMode) {
            $passedQuery->where('mlid', $mlid);
        }
        if ($passedQuery->count() > 0) {
            throw new BusinessException('已考试合格，无需再次考试');
        }

        // 未答完卷续答（题目已固化，保持不变）
        $recordQuery = ScopeService::query('kecheng_record', $aid)
            ->where('kcid', $kcid)
            ->where('mid', $mid)
            ->where('status', 0);
        if ($chapterMode) {
            $recordQuery->where('mlid', $mlid);
        }
        $record = $recordQuery->order('id', 'desc')->find();

        if (empty($record)) {
            // 随机抽题并固化
            $tikuQuery = ScopeService::query('kecheng_tiku', $aid)
                ->where('kcid', $kcid)
                ->where('status', 1);
            if ($chapterMode) {
                $tikuQuery->where('mlid', $mlid);
            }

            $tklist = $tikuQuery->orderRaw('rand()')->limit(max(1, (int) $set['dtnum']))->select()->toArray();
            if (empty($tklist)) {
                throw new BusinessException('暂没有考试题目');
            }

            $rid = (int) Db::name('kecheng_record')->insertGetId([
                'aid'   => $aid,
                'mid'   => $mid,
                'kcid'  => $kcid,
                'mlid'  => $chapterMode ? $mlid : 0,
                'time'  => time(),
                'timu'  => json_encode(array_map(fn ($t) => (int) $t['id'], $tklist)),
                'status' => 0,
            ]);
            $record = Db::name('kecheng_record')->where('id', $rid)->find();
            $log    = null;
        } else {
            $log = ScopeService::query('kecheng_recordlog', $aid)
                ->where('mid', $mid)
                ->where('recordid', (int) $record['id'])
                ->where('status', 0)
                ->order('id', 'desc')
                ->find();
        }

        $count = (int) ScopeService::query('kecheng_recordlog', $aid)
            ->where('recordid', (int) $record['id'])
            ->count();
        $timu = json_decode((string) $record['timu'], true) ?: [];
        $nums = count($timu);

        // 限时：惰性判超时直接结算（无定时任务）
        $sxdate  = (int) $set['sxdate'];
        $endTime = (int) $record['time'] + $sxdate * 60;

        if ($sxdate > 0 && time() > $endTime) {
            $this->settleRecord($aid, (array) $record, (array) $set, true);

            return [
                'ended' => 1,
                'rid'   => (int) $record['id'],
            ];
        }

        // 逐题惰性写入 recordlog（sort 递增）
        if (empty($log) && $count < $nums) {
            $this->insertRecordLog($aid, (int) $set['bid'], $mid, (array) $record, (int) $timu[$count], $count + 1, $chapterMode ? $mlid : 0);

            $log = ScopeService::query('kecheng_recordlog', $aid)
                ->where('mid', $mid)
                ->where('recordid', (int) $record['id'])
                ->where('status', 0)
                ->order('id', 'desc')
                ->find();
        }

        if (empty($log)) {
            // 题目全部作答但卷子未交：直接结算
            $this->settleRecord($aid, (array) $record, (array) $set, false);

            return [
                'ended' => 1,
                'rid'   => (int) $record['id'],
            ];
        }

        $tkdata = $this->formatQuestion($aid, (int) $log['tmid'], false);

        return [
            'ended'    => 0,
            'lefttime' => $sxdate > 0 ? $endTime - time() : 0,
            'tkdata'   => $tkdata,
            'rid'      => (int) $record['id'],
            'nums'     => $nums,
            'set'      => [
                'id'      => (int) $set['id'],
                'sxdate'  => $sxdate,
                'dtnum'   => (int) $set['dtnum'],
                'hgscore' => (int) $set['hgscore'],
                'isdt'    => (int) $set['isdt'],
            ],
            'hasnum'   => (int) $log['sort'],
            'dtid'     => (int) $log['id'],
        ];
    }

    /**
     * 上一题（回看已作答）
     *
     * @return array<string, mixed>
     */
    public function apiPrevQuestion(int $aid, int $mid, int $dtid): array
    {
        $dtlog = ScopeService::query('kecheng_recordlog', $aid)->where('id', $dtid)->where('mid', $mid)->find();
        if (empty($dtlog)) {
            throw new BusinessException('答题记录不存在', 404, 404);
        }

        $log = ScopeService::query('kecheng_recordlog', $aid)
            ->where('recordid', (int) $dtlog['recordid'])
            ->where('mid', $mid)
            ->where('id', '<', $dtid)
            ->order('id', 'desc')
            ->find();

        if (empty($log)) {
            throw new BusinessException('已是第一题');
        }

        $answer = json_decode((string) $log['answer'], true);
        if (null === $answer) {
            $answer = (string) $log['answer'];
        }

        return [
            'answer' => $answer,
            'tkdata' => $this->formatQuestion($aid, (int) $log['tmid'], false),
            'hasnum' => (int) $log['sort'],
            'dtid'   => (int) $log['id'],
        ];
    }

    /**
     * 答题判分 + 取下一题（对标 nextquestion）
     *
     * @param mixed $options 单选/填空为字符串，多选为数组
     * @return array<string, mixed>
     */
    public function apiNextQuestion(int $aid, int $mid, int $dtid, mixed $options): array
    {
        $dtlog = ScopeService::query('kecheng_recordlog', $aid)->where('id', $dtid)->where('mid', $mid)->find();
        if (empty($dtlog)) {
            throw new BusinessException('答题记录不存在', 404, 404);
        }

        $record = ScopeService::query('kecheng_record', $aid)->where('id', (int) $dtlog['recordid'])->find();
        if (empty($record) || 0 !== (int) $record['status']) {
            throw new BusinessException('该场考试已结束');
        }

        // 限时：惰性超时先结算
        $this->assertRecordNotTimeout($aid, (array) $record);

        $tiku = ScopeService::query('kecheng_tiku', $aid)->where('id', (int) $dtlog['tmid'])->find();
        if (empty($tiku)) {
            throw new BusinessException('题目不存在', 404, 404);
        }

        $update = $this->judgeAndReward($aid, $mid, (array) $tiku, $options, (int) $dtlog['id']);
        Db::name('kecheng_recordlog')->where('id', (int) $dtlog['id'])->where('aid', $aid)->update($update);

        // 取下一题（无则惰性写入）
        $next = ScopeService::query('kecheng_recordlog', $aid)
            ->where('recordid', (int) $dtlog['recordid'])
            ->where('id', '>', $dtid)
            ->order('id', 'asc')
            ->find();

        $count = (int) ScopeService::query('kecheng_recordlog', $aid)
            ->where('recordid', (int) $dtlog['recordid'])
            ->count();
        $timu = json_decode((string) $record['timu'], true) ?: [];

        if (empty($next) && $count < count($timu)) {
            $this->insertRecordLog($aid, (int) $tiku['bid'], $mid, (array) $record, (int) $timu[$count], $count + 1, (int) $dtlog['mlid']);

            $next = ScopeService::query('kecheng_recordlog', $aid)
                ->where('recordid', (int) $dtlog['recordid'])
                ->where('status', 0)
                ->order('id', 'desc')
                ->find();
        }

        if (empty($next)) {
            // 已是最后一题：前端改调 tofinish 交卷（或兜底直接结算）
            return [
                'finished' => 1,
                'is_right' => $update['status'] ?? 2,
                'rid'      => (int) $record['id'],
            ];
        }

        $answer = json_decode((string) $next['answer'], true);
        if (null === $answer) {
            $answer = (string) ($next['answer'] ?? '');
        }

        return [
            'finished' => 0,
            'is_right' => $update['status'] ?? 2,
            'tkdata'   => $this->formatQuestion($aid, (int) $next['tmid'], false),
            'hasnum'   => (int) $next['sort'],
            'dtid'     => (int) $next['id'],
            'answer'   => $answer,
        ];
    }

    /**
     * 最后一题判分并交卷（对标 tofinish）
     *
     * @param mixed $options
     * @return array<string, mixed>
     */
    public function apiToFinish(int $aid, int $mid, int $dtid, mixed $options): array
    {
        $dtlog = ScopeService::query('kecheng_recordlog', $aid)->where('id', $dtid)->where('mid', $mid)->find();
        if (empty($dtlog)) {
            throw new BusinessException('答题记录不存在', 404, 404);
        }

        $record = ScopeService::query('kecheng_record', $aid)->where('id', (int) $dtlog['recordid'])->find();
        if (empty($record)) {
            throw new BusinessException('考试记录不存在', 404, 404);
        }
        if (0 !== (int) $record['status']) {
            throw new BusinessException('该场考试已结束');
        }

        $this->assertRecordNotTimeout($aid, (array) $record);

        $tiku = ScopeService::query('kecheng_tiku', $aid)->where('id', (int) $dtlog['tmid'])->find();
        if (empty($tiku)) {
            throw new BusinessException('题目不存在', 404, 404);
        }

        $update = $this->judgeAndReward($aid, $mid, (array) $tiku, $options, (int) $dtlog['id']);
        Db::name('kecheng_recordlog')->where('id', (int) $dtlog['id'])->where('aid', $aid)->update($update);

        $set = ScopeService::query('kecheng_list', $aid)
            ->field('id,hgscore,sxdate')
            ->where('id', (int) $dtlog['kcid'])
            ->find();

        $result = $this->settleRecord($aid, (array) $record, (array) ($set ?: []), false);

        return [
            'is_right' => $update['status'] ?? 2,
            'rid'      => (int) $record['id'],
            'score'    => $result['score'],
            'ishg'     => $result['ishg'],
        ];
    }

    /**
     * 交卷结果页（对标 complete）
     *
     * @return array<string, mixed>
     */
    public function apiComplete(int $aid, int $mid, int $rid): array
    {
        $record = ScopeService::query('kecheng_record', $aid)->where('id', $rid)->where('mid', $mid)->find();
        if (empty($record)) {
            throw new BusinessException('考试记录不存在', 404, 404);
        }

        $rightNum = (int) ScopeService::query('kecheng_recordlog', $aid)
            ->where('recordid', $rid)->where('status', 1)->count();
        $errorNum = (int) ScopeService::query('kecheng_recordlog', $aid)
            ->where('recordid', $rid)->where('status', 2)->count();

        $duration = max(0, (int) $record['endtime'] - (int) $record['time']);

        return [
            'id'       => (int) $record['id'],
            'kcid'     => (int) $record['kcid'],
            'mlid'     => (int) $record['mlid'],
            'score'    => (float) $record['score'],
            'ishg'     => (int) $record['ishg'],
            'isend'    => (int) $record['isend'],
            'time'     => date('Y-m-d H:i:s', (int) $record['time']),
            'endtime'  => (int) $record['endtime'] > 0 ? date('Y-m-d H:i:s', (int) $record['endtime']) : '',
            'rightnum' => $rightNum,
            'errornum' => $errorNum,
            'longtime' => $this->formatDuration($duration),
        ];
    }

    /**
     * 答题记录（已交卷；对标 recordlog）
     *
     * @return array{datalist: array<int, array<string, mixed>>}
     */
    public function apiRecordLog(int $aid, int $mid, int $kcid, int $mlid, int $pagenum): array
    {
        $query = ScopeService::query('kecheng_record', $aid)
            ->where('mid', $mid)
            ->where('kcid', $kcid)
            ->where('status', 1);

        if ($mlid > 0) {
            $query->where('mlid', $mlid);
        }

        $list = $query->order('id', 'desc')->page($pagenum, 10)->select()->toArray();

        $title = (string) ScopeService::query('kecheng_list', $aid)->where('id', $kcid)->value('name');

        foreach ($list as $k => $row) {
            $list[$k]['title'] = $title;
            if ((int) $row['mlid'] > 0) {
                $chapterName = (string) ScopeService::query('kecheng_chapter', $aid)->where('id', (int) $row['mlid'])->value('name');
                if ('' !== $chapterName) {
                    $list[$k]['title'] .= '-' . $chapterName;
                }
            }
            $list[$k]['date'] = date('Y-m-d H:i:s', (int) $row['time']);
        }

        return ['datalist' => $list];
    }

    /**
     * 错题解析回看（对标 error：op=up/down 翻题）
     *
     * @return array<string, mixed>
     */
    public function apiError(int $aid, int $mid, int $rid, string $op, int $logid): array
    {
        $record = ScopeService::query('kecheng_record', $aid)->where('id', $rid)->where('mid', $mid)->find();
        if (empty($record)) {
            throw new BusinessException('考试记录不存在', 404, 404);
        }

        $base = ['recordid' => $rid, 'status' => 2];

        if ('down' === $op) {
            $timu = ScopeService::query('kecheng_recordlog', $aid)
                ->where($base)->where('id', '>', $logid)->order('id', 'asc')->find();
        } elseif ('up' === $op) {
            $timu = ScopeService::query('kecheng_recordlog', $aid)
                ->where($base)->where('id', '<', $logid)->order('id', 'desc')->find();
        } else {
            $timu = ScopeService::query('kecheng_recordlog', $aid)
                ->where($base)->order('id', 'asc')->find();
        }

        if (empty($timu)) {
            throw new BusinessException('没有更多错题了');
        }

        $count = (int) ScopeService::query('kecheng_recordlog', $aid)->where($base)->count();

        $tkdata = $this->formatQuestion($aid, (int) $timu['tmid'], true);

        // 我的作答
        $answer = json_decode((string) $timu['answer'], true);
        if (null === $answer) {
            $answer = (string) $timu['answer'];
        }
        $tkdata['answer']   = $answer;
        $tkdata['nums']     = $count;
        $tkdata['sort']     = (int) $timu['sort'];
        $tkdata['logid']    = (int) $timu['id'];

        // 正确答案字母展示
        if (1 === (int) $tkdata['type']) {
            $letters = [];
            foreach ((array) ($tkdata['right_option_idx'] ?? []) as $idx) {
                $letters[] = self::OPTION_SORTS[(int) $idx] ?? '?';
            }
            $tkdata['right_options'] = implode(',', $letters);
        }

        // 上下题标识
        $tkdata['isup'] = (int) ScopeService::query('kecheng_recordlog', $aid)
            ->where($base)->where('id', '<', (int) $timu['id'])->count() > 0 ? 1 : 0;
        $tkdata['isdown'] = (int) ScopeService::query('kecheng_recordlog', $aid)
            ->where($base)->where('id', '>', (int) $timu['id'])->count() > 0 ? 1 : 0;

        return $tkdata;
    }

    /* ==================== C端：讲师体系 ==================== */

    /**
     * 讲师申请页状态（GET；对标 lecturerapply 非 POST 段）
     *
     * @return array<string, mixed>
     */
    public function apiLecturerApplyInfo(int $aid, int $mid): array
    {
        $this->assertLecturerOpen($aid);

        $lecturer = ScopeService::query('kecheng_lecturer', $aid)->where('mid', $mid)->find();

        return [
            'cansub' => empty($lecturer) || 0 !== (int) $lecturer['checkstatus'] ? 1 : 0,
            'info'   => $lecturer ?: [],
        ];
    }

    /**
     * 讲师申请/修改信息（POST；对标 lecturerapply POST 段）
     *
     * @param array<string, mixed> $info pic/nickname/realname/tel/shortdesc
     * @return array<string, mixed>
     */
    public function apiLecturerApply(int $aid, int $mid, array $info, int $opttype): array
    {
        $this->assertLecturerOpen($aid);

        $lecturer = ScopeService::query('kecheng_lecturer', $aid)->where('mid', $mid)->find();

        if ($lecturer && 0 === $opttype) {
            if (1 === (int) $lecturer['checkstatus']) {
                return ['status' => 2, 'msg' => '您已成功入驻'];
            }
            if (0 === (int) $lecturer['checkstatus']) {
                throw new BusinessException('您已提交过申请，请等待审核中');
            }
            // checkstatus=-1 驳回后允许重新提交
        }

        if ($lecturer && 1 === $opttype) {
            if (1 !== (int) $lecturer['checkstatus']) {
                throw new BusinessException(0 === (int) $lecturer['checkstatus'] ? '您已提交过申请，请等待审核中' : '申请未通过，暂无法修改');
            }
        }
        if (empty($lecturer) && 1 === $opttype) {
            return ['status' => 2, 'msg' => '请先申请'];
        }

        $headimg  = trim((string) ($info['pic'] ?? ''));
        $nickname = trim((string) ($info['nickname'] ?? ''));
        $realname = trim((string) ($info['realname'] ?? ''));
        $tel      = trim((string) ($info['tel'] ?? ''));
        $short    = trim((string) ($info['shortdesc'] ?? ''));

        if ('' === $headimg) {
            throw new BusinessException('请上传头像');
        }
        if ('' === $nickname || mb_strlen($nickname) >= 30) {
            throw new BusinessException('请填写昵称（不超过30个字符）');
        }
        if ('' === $realname || mb_strlen($realname) >= 30) {
            throw new BusinessException('请填写姓名（不超过30个字符）');
        }

        $hasName = ScopeService::query('kecheng_lecturer', $aid)->where('realname', $realname)->find();
        if ($hasName && (int) $hasName['id'] !== (int) ($lecturer['id'] ?? 0)) {
            throw new BusinessException('该姓名已存在，请填写其它姓名');
        }

        if (!preg_match('/^1[3-9]\d{9}$/', $tel)) {
            throw new BusinessException('请填写正确的手机号');
        }
        $hasTel = ScopeService::query('kecheng_lecturer', $aid)->where('tel', $tel)->find();
        if ($hasTel && (int) $hasTel['id'] !== (int) ($lecturer['id'] ?? 0)) {
            throw new BusinessException('该手机号已存在，请填写其它手机号');
        }

        if (mb_strlen($short) >= 200) {
            throw new BusinessException('简介不能超出200个字符');
        }

        $row = [
            'headimg'   => $headimg,
            'nickname'  => $nickname,
            'realname'  => $realname,
            'tel'       => $tel,
            'shortdesc' => $short,
        ];

        if ($lecturer && (1 === $opttype || -1 === (int) $lecturer['checkstatus'])) {
            $row['updatetime'] = time();
            if (1 !== (int) $lecturer['checkstatus']) {
                $row['checkstatus'] = 0; // 驳回后重提 → 重新审核
            }
            Db::name('kecheng_lecturer')->where('id', (int) $lecturer['id'])->where('aid', $aid)->update($row);

            return ['status' => 1, 'msg' => 1 === $opttype ? '保存成功' : '提交成功，等待审核中'];
        }

        $row['aid']         = $aid;
        $row['mid']         = $mid;
        $row['checkstatus'] = 0;
        $row['status']      = 1;
        $row['createtime']  = time();
        Db::name('kecheng_lecturer')->insertGetId($row);

        return ['status' => 1, 'msg' => '提交成功，等待审核中'];
    }

    /**
     * 讲师中心（对标 lecturercenter：本人讲师信息 + 名下课程）
     *
     * @return array<string, mixed>
     */
    public function apiLecturerCenter(int $aid, int $mid, int $st, int $pagenum): array
    {
        $this->assertLecturerOpen($aid);

        $lecturer = ScopeService::query('kecheng_lecturer', $aid)
            ->field('id,mid,nickname,headimg,realname,tel,shortdesc,checkstatus,status')
            ->where('mid', $mid)
            ->find();

        if (empty($lecturer)) {
            throw new BusinessException('请先申请讲师');
        }
        if (0 === (int) $lecturer['checkstatus']) {
            throw new BusinessException('等待审核中');
        }
        if (1 !== (int) $lecturer['checkstatus']) {
            throw new BusinessException('审核未通过');
        }

        $query = ScopeService::query('kecheng_list', $aid)
            ->where('lecturerid', (int) $lecturer['id'])
            ->where('status', 1)
            ->where('kctype', 0 === $st ? 1 : 3);

        $list = $query->field('id,name,pic,kctype,createtime,join_num')
            ->page(max(1, $pagenum), 20)
            ->order('id', 'desc')
            ->select()
            ->toArray();

        foreach ($list as $k => $row) {
            $list[$k]['showtime'] = date('Y-m-d H:i', (int) $row['createtime']);
        }

        return ['data' => $list, 'lecturer' => $lecturer, 'canedit' => 1];
    }

    /**
     * 讲师课程保存（对标 lecturerEditkecheng 精简版：讲师自建/编辑课程 + 同步默认章节）
     *
     * @param array<string, mixed> $info
     * @return array{id: int}
     */
    public function apiLecturerCourseSave(int $aid, int $mid, array $info): array
    {
        $this->assertLecturerOpen($aid);

        $lecturer = ScopeService::query('kecheng_lecturer', $aid)->where('mid', $mid)->find();
        if (empty($lecturer)) {
            throw new BusinessException('请先申请讲师');
        }
        if (1 !== (int) $lecturer['checkstatus']) {
            throw new BusinessException(0 === (int) $lecturer['checkstatus'] ? '等待审核中' : '申请被驳回');
        }

        $lecturerId = (int) $lecturer['id'];
        $id         = (int) ($info['id'] ?? 0);

        $name = trim((string) ($info['name'] ?? ''));
        $pic  = trim((string) ($info['pic'] ?? ''));
        if ('' === $name) {
            throw new BusinessException('名称不能为空');
        }
        if ('' === $pic) {
            throw new BusinessException('主图不能为空');
        }

        $kctype = (int) ($info['kctype'] ?? 1);
        if (!in_array($kctype, [1, 2, 3], true)) {
            throw new BusinessException('类型错误');
        }

        $data = [
            'name'         => $name,
            'pic'          => $pic,
            'cid'          => (string) ($info['cid'] ?? '0'),
            'kctype'       => $kctype,
            'price'        => max(0, (float) ($info['price'] ?? 0)),
            'market_price' => max(0, (float) ($info['market_price'] ?? 0)),
            'detail'       => (string) ($info['detail'] ?? ''),
            'status'       => (int) ($info['status'] ?? 1),
            'lecturerid'   => $lecturerId,
            'isdt'         => 0, // 讲师课程无答题
        ];

        if ($id > 0) {
            $kc = ScopeService::query('kecheng_list', $aid)->where('id', $id)->where('lecturerid', $lecturerId)->find();
            if (empty($kc)) {
                throw new BusinessException('课程不存在', 404, 404);
            }
            Db::name('kecheng_list')->where('id', $id)->where('aid', $aid)->update($data);
        } else {
            $data['aid']        = $aid;
            $data['bid']        = 0;
            $data['createtime'] = time();
            $id = (int) Db::name('kecheng_list')->insertGetId($data);

            // 默认章节（对标参考系统 chaptertype=2 自动建章）
            Db::name('kecheng_chapter')->insertGetId([
                'aid'        => $aid,
                'bid'        => 0,
                'kcid'       => $id,
                'name'       => $name,
                'pic'        => $pic,
                'kctype'     => $kctype,
                'detail'     => (string) ($info['detail'] ?? ''),
                'video_url'  => (string) ($info['video_url'] ?? ''),
                'voice_url'  => (string) ($info['voice_url'] ?? ''),
                'status'     => 1,
                'sort'       => 0,
                'createtime' => time(),
            ]);
        }

        return ['id' => $id];
    }

    /**
     * 讲师列表（C端展示；对标 lecturerlist）
     *
     * @return array{data: array<int, array<string, mixed>>}
     */
    public function apiLecturerList(int $aid, int $pagenum): array
    {
        $this->assertLecturerOpen($aid);

        $list = ScopeService::query('kecheng_lecturer', $aid)
            ->field('id,headimg,nickname,shortdesc')
            ->where('checkstatus', 1)
            ->where('status', 1)
            ->page(max(1, $pagenum), 6)
            ->order('sort', 'desc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        return ['data' => $list];
    }

    /* ==================== 内部工具 ==================== */

    /**
     * 讲师开关校验（kecheng_sysset.lecturer_open，对标 getcustom('kecheng_lecturer')）
     */
    protected function assertLecturerOpen(int $aid): void
    {
        $open = (int) ScopeService::query('kecheng_sysset', $aid)->value('lecturer_open');
        if (1 !== $open) {
            throw new BusinessException('该功能系统暂未开通');
        }
    }

    /**
     * 题目回显格式化（withAnswer=true 附解析与正确答案，用于错题回看）
     *
     * @return array<string, mixed>
     */
    protected function formatQuestion(int $aid, int $tmid, bool $withAnswer): array
    {
        $fields = 'id,title,type,option_group' . ($withAnswer ? ',right_option,jiexi,score,give_score' : '');
        $tiku = ScopeService::query('kecheng_tiku', $aid)->field($fields)->where('id', $tmid)->find();
        if (empty($tiku)) {
            throw new BusinessException('题目不存在', 404, 404);
        }

        $tiku['sorts'] = self::OPTION_SORTS;

        if (1 === (int) $tiku['type']) {
            $tiku['option'] = json_decode((string) $tiku['option_group'], true) ?: [];

            if ($withAnswer) {
                $idx = array_values(array_filter(explode(',', (string) $tiku['right_option']), 'strlen'));
                $tiku['right_option_idx'] = array_map('intval', $idx);
                $tiku['rightcount'] = count($idx);
            }
            unset($tiku['right_option'], $tiku['option_group']);
        } else {
            unset($tiku['option_group']);
            if (!$withAnswer) {
                unset($tiku['right_option']);
            }
        }

        return $tiku;
    }

    /**
     * 判分 + 答题送积分（对标 nextquestion 判分段 + give_score 幂等）
     *
     * 多选：所选数量相等且每项在正确答案集合内；单选/填空：直接比对
     *
     * @param mixed $options
     * @return array<string, mixed> recordlog 更新数据
     */
    protected function judgeAndReward(int $aid, int $mid, array $tiku, mixed $options, int $currentLogId): array
    {
        $rightOptions = explode(',', (string) $tiku['right_option']);
        $isRight      = 0;

        if (1 === (int) $tiku['type'] && count($rightOptions) > 1) {
            // 多选题
            $picked = array_values(array_filter((array) $options, fn ($v) => '' !== trim((string) $v)));
            $answer = json_encode(array_map('strval', $picked), JSON_UNESCAPED_UNICODE);

            if (!empty($picked) && count($picked) === count($rightOptions)) {
                $isRight = 1;
                foreach ($picked as $p) {
                    if (!in_array((string) $p, $rightOptions, true)) {
                        $isRight = 0;
                        break;
                    }
                }
            }
        } else {
            // 单选 / 填空：直接比对
            $picked = trim((string) (is_array($options) ? ($options[0] ?? '') : $options));
            $answer = $picked;

            if ($picked === trim((string) $tiku['right_option'])) {
                $isRight = 1;
            }
        }

        $update = [
            'answer' => $answer,
            'status' => 1 === $isRight ? 1 : 2,
            'score'  => 1 === $isRight ? (float) $tiku['score'] : 0,
        ];

        if (1 !== $isRight) {
            return $update;
        }

        $set = ScopeService::query('kecheng_sysset', $aid)->find();

        // 积分仅首次：同题历史答对过（score>0）则本次不计分
        if ($set && 1 === (int) $set['send_score_num']) {
            $oldScore = (float) ScopeService::query('kecheng_recordlog', $aid)
                ->where('tmid', (int) $tiku['id'])
                ->where('mid', $mid)
                ->where('id', '<>', $currentLogId)
                ->where('status', 1)
                ->where('score', '>', 0)
                ->value('score');
            if ($oldScore > 0) {
                $update['score'] = 0;
            }
        }

        // 答题送积分（give_score_num：0 仅首次 / 1 每次）
        $giveScore = (int) ($tiku['give_score'] ?? 0);
        if ($giveScore > 0) {
            if (!$set || 0 === (int) $set['give_score_num']) {
                $given = (int) ScopeService::query('kecheng_recordlog', $aid)
                    ->where('tmid', (int) $tiku['id'])
                    ->where('mid', $mid)
                    ->where('id', '<>', $currentLogId)
                    ->where('status', 1)
                    ->where('give_score', '>', 0)
                    ->value('give_score');
                if ($given > 0) {
                    $giveScore = 0;
                }
            }

            if ($giveScore > 0) {
                $update['give_score'] = $giveScore;
                $this->addMemberScore($aid, $mid, $giveScore, '答题送积分');
            }
        }

        return $update;
    }

    /**
     * 交卷结算：sum(score where status=1) 与 hgscore 比对置 ishg
     *
     * @param array<string, mixed> $record
     * @param array<string, mixed> $set
     * @return array{score: float, ishg: int}
     */
    protected function settleRecord(int $aid, array $record, array $set, bool $timeout): array
    {
        $score = (float) ScopeService::query('kecheng_recordlog', $aid)
            ->where('recordid', (int) $record['id'])
            ->where('status', 1)
            ->sum('score');

        $update = [
            'status'  => 1,
            'endtime' => time(),
            'score'   => $score,
            'ishg'    => $score >= (float) ($set['hgscore'] ?? 0) ? 1 : 0,
        ];
        if ($timeout) {
            $update['isend'] = 1;
        }

        Db::name('kecheng_record')->where('id', (int) $record['id'])->where('aid', $aid)->update($update);

        return ['score' => $score, 'ishg' => $update['ishg']];
    }

    /**
     * 限时校验：超时则惰性结算并抛错
     *
     * @param array<string, mixed> $record
     */
    protected function assertRecordNotTimeout(int $aid, array $record): void
    {
        $sxdate = (int) ScopeService::query('kecheng_list', $aid)->where('id', (int) $record['kcid'])->value('sxdate');

        if ($sxdate > 0 && time() > (int) $record['time'] + $sxdate * 60) {
            $set = ScopeService::query('kecheng_list', $aid)
                ->field('id,hgscore,sxdate')
                ->where('id', (int) $record['kcid'])
                ->find();
            $this->settleRecord($aid, $record, (array) ($set ?: []), true);

            throw new BusinessException('考试时间已结束，试卷已自动提交');
        }
    }

    /**
     * 惰性写入当前题 recordlog（sort 递增）
     */
    protected function insertRecordLog(int $aid, int $bid, int $mid, array $record, int $tmid, int $sort, int $mlid): void
    {
        Db::name('kecheng_recordlog')->insertGetId([
            'aid'      => $aid,
            'bid'      => $bid,
            'mid'      => $mid,
            'recordid' => (int) $record['id'],
            'time'     => time(),
            'kcid'     => (int) $record['kcid'],
            'mlid'     => $mlid,
            'tmid'     => $tmid,
            'sort'     => $sort,
            'status'   => 0,
        ]);
    }

    /**
     * 会员积分入账（member.score + score_log 流水；口径对齐 D1 apiGiveScore）
     */
    protected function addMemberScore(int $aid, int $mid, int $score, string $remark): void
    {
        Db::transaction(function () use ($aid, $mid, $score, $remark) {
            Db::name('member')->where('id', $mid)->where('aid', $aid)->inc('score', $score)->update();
            Db::name('score_log')->insert([
                'aid'         => $aid,
                'mid'         => $mid,
                'score'       => $score,
                'remark'      => $remark,
                'type'        => 1,
                'create_time' => time(),
            ]);
        });
    }

    /**
     * 秒数 → 时分秒
     */
    protected function formatDuration(int $seconds): string
    {
        return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }
}
