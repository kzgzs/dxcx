<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\ScopeService;
use think\facade\Db;

/**
 * 租户后台 - 自定义表单（对照 canzhao app/controller/Form.php + ApiAdminForm.php）
 *
 * 双表结构：form（表单定义）+ form_order（提交记录，form0~form50 动态字段）。
 * 列表支持 bid 过滤（form-index-showtype-2 商户视图经 merchantViewOf 双挂同组件）。
 */
class Form extends BaseController
{
    /**
     * 表单列表（name/bid 筛选，附加未处理记录数 st0count）
     * GET /admin/form/list?page&pagesize&name&bid
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        $name   = trim((string) $this->request->get('name', ''));

        $query = ScopeService::queryAs('form', $aid, 'f');
        ScopeService::applyBid($query, trim((string) $this->request->get('bid', '')), 'f.bid');

        if ('' !== $name) {
            $query->where('f.name', 'like', '%' . $name . '%');
        }

        $total = (clone $query)->count();
        $rows  = $query->field('f.*')->order('f.sort', 'asc')->order('f.id', 'desc')
            ->page($page, $pageSize)->select()->toArray();

        // 未处理记录数（status=0）
        $ids = array_map(static fn ($r) => (int) $r['id'], $rows);
        $st0Map = [];
        if (!empty($ids)) {
            $st0Map = Db::name('form_order')->where('aid', $aid)
                ->whereIn('formid', $ids)->where('status', 0)->where('isudel', 0)
                ->group('formid')->column('count(*)', 'formid');
        }
        foreach ($rows as &$row) {
            $row['st0count'] = (int) ($st0Map[$row['id']] ?? 0);
        }
        unset($row);

        return $this->success(['total' => $total, 'list' => $rows]);
    }

    /**
     * 表单详情
     * GET /admin/form/detail?id=
     */
    public function detail()
    {
        $aid = ScopeService::aidOf($this->request);
        $id  = (int) $this->request->get('id', 0);

        $row = ScopeService::query('form', $aid)->where('id', $id)->find();
        if (empty($row)) {
            throw new BusinessException('表单不存在');
        }

        return $this->success($row);
    }

    /**
     * 表单新增/编辑
     * POST /admin/form/save
     */
    public function save()
    {
        $aid  = ScopeService::aidOf($this->request);
        $data = $this->request->post();
        $data = is_array($data) ? $data : [];

        if ('' === trim((string) ($data['name'] ?? ''))) {
            throw new BusinessException('请填写表单名称');
        }

        $allow = ['bid', 'name', 'content', 'payset', 'price', 'priceedit', 'maxlimit',
            'perlimit', 'sort', 'commissionset', 'commission1', 'commission2', 'commission3',
            'commissiondata1', 'commissiondata2', 'fanwei', 'fanwei_lng', 'fanwei_lat',
            'fanwei_range', 'form_query', 'form_query_type', 'form_query_bgcolor',
            'form_query_txtcolor', 'submit_tourl'];

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
            } elseif (array_key_exists($tf, $data)) {
                $record[$tf] = '';
            }
        }

        if (isset($record['bid'])) {
            $record['bid'] = (int) $record['bid'];
        }
        if (isset($record['sort'])) {
            $record['sort'] = (int) $record['sort'];
        }

        $id = (int) ($data['id'] ?? 0);
        if ($id > 0) {
            $exists = ScopeService::query('form', $aid)->where('id', $id)->find();
            if (empty($exists)) {
                throw new BusinessException('表单不存在');
            }
            ScopeService::query('form', $aid)->where('id', $id)->update($record);
        } else {
            $record['aid']        = $aid;
            $record['bid']        = (int) ($record['bid'] ?? 0);
            $record['createtime'] = time();
            $id = (int) Db::name('form')->insertGetId($record);
        }

        return $this->success(['id' => $id], '保存成功');
    }

    /**
     * 表单删除（级联提交记录软删口径改为物理删除）
     * POST /admin/form/delete {ids[]|id}
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
            throw new BusinessException('请选择要删除的表单');
        }

        ScopeService::query('form', $aid)->whereIn('id', $ids)->delete();
        ScopeService::query('form_order', $aid)->whereIn('formid', $ids)->delete();

        return $this->success([], '删除成功');
    }

    /**
     * 表单复制（name+'-复制'，对照 canzhao copyform）
     * POST /admin/form/copy {id}
     */
    public function copy()
    {
        $aid = ScopeService::aidOf($this->request);
        $id  = (int) $this->request->post('id', 0);

        $row = ScopeService::query('form', $aid)->where('id', $id)->find();
        if (empty($row)) {
            throw new BusinessException('表单不存在');
        }

        unset($row['id']);
        $row['name']       = $row['name'] . '-复制';
        $row['createtime'] = time();

        $newId = (int) Db::name('form')->insertGetId($row);

        return $this->success(['id' => $newId], '复制成功');
    }

    /**
     * 提交记录列表（formid/status/keyword(form0-10)/ctime/bid 筛选，附加会员信息）
     * GET /admin/form/record?page&pagesize&formid&status&keyword&starttime&endtime&bid
     */
    public function record()
    {
        $aid = ScopeService::aidOf($this->request);

        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        $formid  = (int) $this->request->get('formid', 0);
        $status  = trim((string) $this->request->get('status', ''));
        $keyword = trim((string) $this->request->get('keyword', ''));
        $startTime = trim((string) $this->request->get('starttime', ''));
        $endTime   = trim((string) $this->request->get('endtime', ''));

        $query = ScopeService::queryAs('form_order', $aid, 'o')
            ->leftJoin('member u', 'u.id = o.mid')
            ->where('o.isudel', 0)
            ->field('o.*, u.nickname, u.headimg');

        ScopeService::applyBid($query, trim((string) $this->request->get('bid', '')), 'o.bid');

        if ($formid > 0) {
            $query->where('o.formid', $formid);
        }
        if ('' !== $status) {
            $query->where('o.status', (int) $status);
        }
        if ('' !== $keyword) {
            $like = [];
            for ($i = 0; $i <= 10; ++$i) {
                $like[] = 'o.form' . $i . ' like "%' . addslashes($keyword) . '%"';
            }
            $query->whereRaw(implode(' or ', $like));
        }
        if ('' !== $startTime) {
            $query->where('o.createtime', '>=', strtotime($startTime));
        }
        if ('' !== $endTime) {
            $query->where('o.createtime', '<=', strtotime($endTime . ' 23:59:59'));
        }

        $total = (clone $query)->count();
        $rows  = $query->order('o.id', 'desc')->page($page, $pageSize)->select()->toArray();

        return $this->success(['total' => $total, 'list' => $rows]);
    }

    /**
     * 提交记录详情
     * GET /admin/form/recorddetail?id=
     */
    public function recorddetail()
    {
        $aid = ScopeService::aidOf($this->request);
        $id  = (int) $this->request->get('id', 0);

        $row = ScopeService::query('form_order', $aid)->where('id', $id)->find();
        if (empty($row)) {
            throw new BusinessException('记录不存在');
        }

        $member = Db::name('member')->where('id', (int) $row['mid'])->field('id, nickname, headimg')->find();
        $row['nickname'] = $member['nickname'] ?? '';
        $row['headimg']  = $member['headimg'] ?? '';

        // 表单定义（字段标题回显）
        $row['formdef'] = ScopeService::query('form', $aid)->where('id', (int) $row['formid'])->find() ?: null;

        return $this->success($row);
    }

    /**
     * 提交记录改状态（st：1确认/2驳回，驳回须 reason；对照 canzhao recordsetst）
     * POST /admin/form/recordsetst {ids[], st, reason}
     */
    public function recordsetst()
    {
        $aid = ScopeService::aidOf($this->request);
        $ids = array_values(array_filter(array_map('intval', (array) $this->request->post('ids/a', []))));
        $st  = (int) $this->request->post('st', 1);
        $reason = trim((string) $this->request->post('reason', ''));

        if (empty($ids)) {
            throw new BusinessException('请选择记录');
        }
        if (!in_array($st, [1, 2], true)) {
            throw new BusinessException('非法的状态');
        }
        if (2 === $st && '' === $reason) {
            throw new BusinessException('请填写驳回原因');
        }

        $update = ['status' => $st];
        if (2 === $st) {
            $update['reason'] = $reason;
        }

        ScopeService::query('form_order', $aid)->whereIn('id', $ids)->update($update);

        return $this->success([], 1 === $st ? '已确认' : '已驳回');
    }

    /**
     * 提交记录删除（软删 isudel=1，对照 canzhao recorddel）
     * POST /admin/form/recorddel {ids[]}
     */
    public function recorddel()
    {
        $aid = ScopeService::aidOf($this->request);
        $ids = array_values(array_filter(array_map('intval', (array) $this->request->post('ids/a', []))));

        if (empty($ids)) {
            throw new BusinessException('请选择记录');
        }

        ScopeService::query('form_order', $aid)->whereIn('id', $ids)->update(['isudel' => 1]);

        return $this->success([], '删除成功');
    }
}
