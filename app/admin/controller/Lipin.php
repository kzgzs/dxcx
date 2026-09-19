<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\ExcelHelper;
use app\common\service\ScopeService;
use think\facade\Db;

/**
 * 租户后台 - 礼品卡管理（admin 应用，JWT role=admin，aid 走 ScopeService）
 *
 * 对照 canzhao app/controller/Lipin.php：
 * 礼品卡活动 CRUD / 兑换码列表 / 生成兑换码 / 导入导出 / 批量改状态 / 礼品卡设置
 */
class Lipin extends BaseController
{
    /** 兑换类型（对照 canzhao：0余额/1商品/2积分/3优惠券） */
    public const TYPE_LIST = [0 => '余额', 1 => '商品', 2 => '积分', 3 => '优惠券'];

    /**
     * 礼品卡列表（附加兑换码总数/已兑换数/分类名）
     * GET /admin/lipin/list?page&pagesize&name&status
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        $name   = trim((string) $this->request->get('name', ''));
        $status = trim((string) $this->request->get('status', ''));

        $query = ScopeService::query('lipin', $aid);
        if ('' !== $name) {
            $query->where('name', 'like', '%' . $name . '%');
        }
        if ('' !== $status) {
            $query->where('status', (int) $status);
        }

        $total = (clone $query)->count();
        $rows  = $query->order('id', 'desc')->page($page, $pageSize)->select()->toArray();

        $ids = array_map(static fn ($r) => (int) $r['id'], $rows);
        $totalMap = $usedMap = [];
        if (!empty($ids)) {
            $totalMap = Db::name('lipin_codelist')->where('aid', $aid)
                ->whereIn('hid', $ids)->group('hid')->column('count(*)', 'hid');
            $usedMap = Db::name('lipin_codelist')->where('aid', $aid)
                ->whereIn('hid', $ids)->where('status', 1)->group('hid')->column('count(*)', 'hid');
        }

        $cnames = Db::name('lipin_category')->where('aid', $aid)->column('name', 'id');

        foreach ($rows as &$row) {
            $row['totalcount'] = (int) ($totalMap[$row['id']] ?? 0);
            $row['usedcount']  = (int) ($usedMap[$row['id']] ?? 0);
            $row['cname']      = $cnames[(int) $row['cid']] ?? '';
        }
        unset($row);

        return $this->success(['total' => $total, 'list' => $rows]);
    }

    /**
     * 礼品卡详情
     * GET /admin/lipin/detail?id=
     */
    public function detail()
    {
        $aid = ScopeService::aidOf($this->request);
        $id  = (int) $this->request->get('id', 0);

        $row = ScopeService::query('lipin', $aid)->where('id', $id)->find();
        if (empty($row)) {
            throw new BusinessException('礼品卡不存在');
        }

        return $this->success($row);
    }

    /**
     * 礼品卡新增/编辑（starttime/endtime 转时间戳存储）
     * POST /admin/lipin/save
     */
    public function save()
    {
        $aid  = ScopeService::aidOf($this->request);
        $data = $this->request->post();
        $data = is_array($data) ? $data : [];

        if ('' === trim((string) ($data['name'] ?? ''))) {
            throw new BusinessException('请填写名称');
        }

        $allow = ['name', 'cid', 'type', 'num_type', 'money', 'prodata', 'prodata4',
            'num_type4', 'status', 'score', 'coupon_ids'];

        $record = [];
        foreach ($allow as $field) {
            if (array_key_exists($field, $data)) {
                $record[$field] = is_scalar($data[$field]) ? (string) $data[$field] : json_encode($data[$field], JSON_UNESCAPED_UNICODE);
            }
        }

        foreach (['starttime', 'endtime'] as $tf) {
            $val = trim((string) ($data[$tf] ?? ''));
            if ('' !== $val) {
                $ts = is_numeric($val) ? (int) $val : (int) strtotime($val);
                if ($ts <= 0) {
                    throw new BusinessException('时间格式错误');
                }
                $record[$tf] = (string) $ts;
            } else {
                $record[$tf] = '';
            }
        }

        $id = (int) ($data['id'] ?? 0);
        if ($id > 0) {
            $exists = ScopeService::query('lipin', $aid)->where('id', $id)->find();
            if (empty($exists)) {
                throw new BusinessException('礼品卡不存在');
            }
            ScopeService::query('lipin', $aid)->where('id', $id)->update($record);
        } else {
            $record['aid']        = $aid;
            $record['createtime'] = time();
            $id = (int) Db::name('lipin')->insertGetId($record);
        }

        return $this->success(['id' => $id], '保存成功');
    }

    /**
     * 礼品卡删除（级联兑换码）
     * POST /admin/lipin/delete {ids[]|id}
     */
    public function delete()
    {
        $aid = ScopeService::aidOf($this->request);

        $ids = (array) $this->request->post('ids/a', []);
        if (empty($ids)) {
            $ids = [(int) $this->request->post('id', 0)];
        }
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) {
            throw new BusinessException('请选择要删除的礼品卡');
        }

        ScopeService::query('lipin', $aid)->whereIn('id', $ids)->delete();
        ScopeService::query('lipin_codelist', $aid)->whereIn('hid', $ids)->delete();

        return $this->success([], '删除成功');
    }

    /**
     * 礼品卡设置（GET 回显 / POST 保存：status/scanshow/guize）
     * GET|POST /admin/lipin/set
     */
    public function set()
    {
        $aid = ScopeService::aidOf($this->request);

        if ($this->request->isPost()) {
            $data   = $this->request->post();
            $data   = is_array($data) ? $data : [];
            $record = [
                'status'   => (int) ($data['status'] ?? 0),
                'scanshow' => (int) ($data['scanshow'] ?? 0),
                'guize'    => (string) ($data['guize'] ?? ''),
            ];

            $exists = ScopeService::query('lipin_set', $aid)->find();
            if (empty($exists)) {
                $record['aid'] = $aid;
                Db::name('lipin_set')->insert($record);
            } else {
                ScopeService::query('lipin_set', $aid)->update($record);
            }

            return $this->success([], '保存成功');
        }

        $row = ScopeService::query('lipin_set', $aid)->find();
        if (empty($row)) {
            $row = ['aid' => $aid, 'status' => 0, 'scanshow' => 0, 'guize' => ''];
        }

        return $this->success($row);
    }

    /**
     * 兑换码列表（hid/cardno区间/code/mid/nickname/status/ctime 筛选）
     * GET /admin/lipin/codelist?page&pagesize&hid&cardno&cardno2&code&mid&nickname&status&starttime&endtime
     */
    public function codelist()
    {
        $aid = ScopeService::aidOf($this->request);

        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        $query = $this->codelistQuery($aid);

        $total = (clone $query)->count();
        $rows  = $query->order('c.id', 'desc')->page($page, $pageSize)->select()->toArray();

        $names = Db::name('lipin')->where('aid', $aid)->column('name', 'id');
        foreach ($rows as &$row) {
            $row['hname'] = $names[(int) $row['hid']] ?? '';
        }
        unset($row);

        return $this->success(['total' => $total, 'list' => $rows]);
    }

    /**
     * 兑换码批量改状态 / 删除
     * POST /admin/lipin/setst {ids[], st}（st：0未使用/1已使用/2已售）
     * POST /admin/lipin/codelistdel {ids[]}
     */
    public function setst()
    {
        $aid = ScopeService::aidOf($this->request);
        $ids = array_values(array_filter(array_map('intval', (array) $this->request->post('ids/a', []))));
        $st  = (int) $this->request->post('st', 0);

        if (empty($ids)) {
            throw new BusinessException('请选择兑换码');
        }
        if (!in_array($st, [0, 1, 2], true)) {
            throw new BusinessException('非法的状态');
        }

        $update = ['status' => $st];
        if (1 === $st) {
            $update['usetime'] = time();
        }
        if (2 === $st) {
            $update['saletime'] = time();
        }

        ScopeService::query('lipin_codelist', $aid)->whereIn('id', $ids)->update($update);

        return $this->success([], '操作成功');
    }

    public function codelistdel()
    {
        $aid = ScopeService::aidOf($this->request);
        $ids = array_values(array_filter(array_map('intval', (array) $this->request->post('ids/a', []))));

        if (empty($ids)) {
            throw new BusinessException('请选择兑换码');
        }

        ScopeService::query('lipin_codelist', $aid)->whereIn('id', $ids)->delete();

        return $this->success([], '删除成功');
    }

    /**
     * 生成兑换码（makecount 1~5000；codetype 1纯数字/2大小写字母/3小写/4大写/5数字+小写/6数字+大写）
     * POST /admin/lipin/makecode {hid, makecount, codelength, codetype, qrtype}
     */
    public function makecode()
    {
        $aid = ScopeService::aidOf($this->request);
        $hid = (int) $this->request->post('hid', 0);

        $makeCount   = (int) $this->request->post('makecount', 100);
        $codeLength  = (int) $this->request->post('codelength', 8);
        $codeType    = (int) $this->request->post('codetype', 5);
        $qrType      = (int) $this->request->post('qrtype', 0);

        $huodong = ScopeService::query('lipin', $aid)->where('id', $hid)->find();
        if (empty($huodong)) {
            throw new BusinessException('礼品卡活动不存在');
        }
        if ($makeCount < 1 || $makeCount > 5000) {
            throw new BusinessException('每次生成数量须在5000以内');
        }
        if ($codeLength < 1) {
            throw new BusinessException('兑换码长度须大于等于1');
        }

        $lengthLimit = [1 => 20, 2 => 52, 3 => 26, 4 => 26, 5 => 35, 6 => 35];
        if (!isset($lengthLimit[$codeType])) {
            throw new BusinessException('兑换码类型取值 1~6');
        }
        if ($codeLength > $lengthLimit[$codeType]) {
            throw new BusinessException('该类型兑换码长度须小于等于' . $lengthLimit[$codeType]);
        }
        if ($qrType < 0 || $qrType > 4) {
            throw new BusinessException('图形码类型取值 0~4');
        }

        $pools = [
            1 => '0123456789',
            2 => 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ',
            3 => 'abcdefghijklmnopqrstuvwxyz',
            4 => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
            5 => '0123456789abcdefghijklmnopqrstuvwxyz',
            6 => '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ',
        ];
        $pool = $pools[$codeType];

        // 已有兑换码防重
        $exists = array_flip(array_map('strval', ScopeService::query('lipin_codelist', $aid)
            ->where('hid', $hid)->column('code')));
        // 卡号防重：唯一键 hid_2(aid,cardno)，cardno 须全租户唯一（任务234 Bug2 修复）
        $taken = array_flip(array_map('strval', Db::name('lipin_codelist')->where('aid', $aid)->column('cardno')));

        $created = 0;
        $now     = time();
        $batch   = [];
        $guard   = 0;

        while ($created < $makeCount && $guard < $makeCount * 10) {
            ++$guard;
            $code = '';
            for ($i = 0; $i < $codeLength; ++$i) {
                $code .= $pool[random_int(0, strlen($pool) - 1)];
            }
            if (isset($exists[$code])) {
                continue;
            }
            $exists[$code] = true;

            $row = [
                'aid'        => $aid,
                'hid'        => $hid,
                'cardno'     => $this->uniqueCardno($taken),
                'code'       => $code,
                'qr'         => $qrType > 0 ? $code : '',
                'status'     => 0,
                'createtime' => $now,
            ];
            $batch[] = $row;

            if (count($batch) >= 500) {
                Db::name('lipin_codelist')->insertAll($batch);
                $batch = [];
            }
            ++$created;
        }

        if (!empty($batch)) {
            Db::name('lipin_codelist')->insertAll($batch);
        }

        return $this->success(['created' => $created], '成功生成' . $created . '条兑换码');
    }

    /**
     * 兑换码导出（xlsx：序号/活动ID/兑换码/图形码链接/使用人/兑换时间/状态/备注）
     * GET /admin/lipin/codelistexcel（筛选条件同 codelist）
     */
    public function codelistexcel()
    {
        $aid  = ScopeService::aidOf($this->request);
        $rows = $this->codelistQuery($aid)->order('c.id', 'desc')->limit(50000)->select()->toArray();

        $names = Db::name('lipin')->where('aid', $aid)->column('name', 'id');
        $stMap = [0 => '未使用', 1 => '已使用', 2 => '已售'];

        $titles = ['序号', '活动ID', '兑换码', '图形码链接', '使用人', '兑换时间', '状态', '备注'];
        $data   = [];
        foreach ($rows as $i => $row) {
            $user = '';
            if (!empty($row['mid'])) {
                $user = ($row['nickname'] !== '' ? $row['nickname'] : ('会员' . $row['mid'])) . '(ID:' . $row['mid'] . ')';
            }
            $data[] = [
                $i + 1,
                $row['hid'] . '(' . ($names[(int) $row['hid']] ?? '') . ')',
                $row['code'],
                (string) $row['qr'],
                $user,
                !empty($row['usetime']) ? date('Y-m-d H:i:s', (int) $row['usetime']) : '',
                $stMap[(int) $row['status']] ?? '',
                (string) $row['remark'],
            ];
        }

        return ExcelHelper::download('lipin_codelist_' . date('YmdHis') . '.xlsx', $titles, $data);
    }

    /**
     * 兑换码导入（xlsx 首列为兑换码，按 code 去重）
     * POST /admin/lipin/importexcel {hid, file}
     */
    public function importexcel()
    {
        $aid  = ScopeService::aidOf($this->request);
        $hid  = (int) $this->request->post('hid', 0);
        $file = $this->request->file('file');

        $huodong = ScopeService::query('lipin', $aid)->where('id', $hid)->find();
        if (empty($huodong)) {
            throw new BusinessException('礼品卡活动不存在');
        }
        if (empty($file)) {
            throw new BusinessException('请上传 Excel 文件');
        }

        $extension = strtolower($file->getOriginalExtension());
        if (!in_array($extension, ['xlsx', 'xls', 'csv'], true)) {
            throw new BusinessException('仅支持 xlsx/xls/csv 格式');
        }
        if ($file->getSize() > 10 * 1024 * 1024) {
            throw new BusinessException('表格文件不能超过10MB');
        }

        $rows = ExcelHelper::readRows($file->getPathname());

        // 按表头识别兑换码列：仅匹配 /兑换码|code/ 的列入库，序号等非兑换码列一律不导（任务234 Bug4 修复）
        $codeCol  = 0;
        $startRow = 0;
        $header   = $rows[0] ?? [];
        foreach ((array) $header as $ci => $cell) {
            if (preg_match('/兑换码|code/iu', trim((string) $cell))) {
                $codeCol  = (int) $ci;
                $startRow = 1;
                break;
            }
        }

        $exists = array_flip(array_map('strval', ScopeService::query('lipin_codelist', $aid)
            ->where('hid', $hid)->column('code')));
        // 卡号防重：唯一键 hid_2(aid,cardno)，导入行同样须生成唯一 cardno（任务234 Bug2 修复）
        $taken = array_flip(array_map('strval', Db::name('lipin_codelist')->where('aid', $aid)->column('cardno')));

        $inserted = 0;
        $skipped  = 0;
        $batch    = [];
        $now      = time();

        foreach ($rows as $ri => $row) {
            if ($ri < $startRow) {
                continue; // 跳过表头行
            }
            $code = trim((string) ($row[$codeCol] ?? ''));
            if ('' === $code) {
                ++$skipped; // 空兑换码行跳过并计数
                continue;
            }
            if (isset($exists[$code])) {
                continue;
            }
            $exists[$code] = true;

            $batch[] = [
                'aid'        => $aid,
                'hid'        => $hid,
                'cardno'     => $this->uniqueCardno($taken),
                'code'       => $code,
                'qr'         => '',
                'status'     => 0,
                'createtime' => $now,
            ];
            if (count($batch) >= 500) {
                Db::name('lipin_codelist')->insertAll($batch);
                $inserted += count($batch);
                $batch = [];
            }
        }

        if (!empty($batch)) {
            Db::name('lipin_codelist')->insertAll($batch);
            $inserted += count($batch);
        }

        return $this->success(['inserted' => $inserted, 'skipped' => $skipped], '成功导入' . $inserted . '条兑换码' . ($skipped > 0 ? '，跳过' . $skipped . '条空兑换码行' : ''));
    }

    /**
     * 生成全租户唯一卡号（16位随机数字，唯一键 hid_2(aid,cardno) 要求）
     *
     * @param array<string, mixed> $taken 已占用卡号集合（引用传入，生成后回写）
     */
    private function uniqueCardno(array &$taken): string
    {
        do {
            $cardno = '';
            for ($i = 0; $i < 16; ++$i) {
                $cardno .= random_int(0, 9);
            }
        } while ('' === $cardno || isset($taken[$cardno]));
        $taken[$cardno] = true;

        return $cardno;
    }

    /**
     * 兑换码通用筛选查询（列表与导出共用）
     */
    private function codelistQuery(int $aid): \think\db\BaseQuery
    {
        $hid       = (int) $this->request->param('hid', 0);
        $cardno    = trim((string) $this->request->param('cardno', ''));
        $cardno2   = trim((string) $this->request->param('cardno2', ''));
        $code      = trim((string) $this->request->param('code', ''));
        $mid       = (int) $this->request->param('mid', 0);
        $nickname  = trim((string) $this->request->param('nickname', ''));
        $status    = trim((string) $this->request->param('status', ''));
        $startTime = trim((string) $this->request->param('starttime', ''));
        $endTime   = trim((string) $this->request->param('endtime', ''));

        $query = ScopeService::queryAs('lipin_codelist', $aid, 'c');

        if ($hid > 0) {
            $query->where('c.hid', $hid);
        }
        if ('' !== $cardno && '' !== $cardno2) {
            $query->where('c.cardno', '>=', $cardno)->where('c.cardno', '<=', $cardno2);
        } elseif ('' !== $cardno) {
            $query->where('c.cardno', $cardno);
        }
        if ('' !== $code) {
            $query->where('c.code', 'like', '%' . $code . '%');
        }
        if ($mid > 0) {
            $query->where('c.mid', $mid);
        }
        if ('' !== $nickname) {
            $query->where('c.nickname', 'like', '%' . $nickname . '%');
        }
        if ('' !== $status) {
            $query->where('c.status', (int) $status);
        }
        if ('' !== $startTime) {
            $query->where('c.createtime', '>=', strtotime($startTime));
        }
        if ('' !== $endTime) {
            $query->where('c.createtime', '<=', strtotime($endTime . ' 23:59:59'));
        }

        return $query->field('c.*');
    }
}
