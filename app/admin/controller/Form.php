<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\FormService;
use app\common\service\ScopeService;
use think\facade\Db;
use util\ExcelUtil;

/**
 * 租户后台 - 自定义表单
 *
 * 双表结构：form（表单定义）+ form_order（提交记录，form0~form50 动态字段）。
 * 列表支持 bid 过滤（form-index-showtype-2 商户视图经 merchantViewOf 双挂同组件）。
 */
class Form extends BaseController
{
    public function __construct(\think\App $app, protected FormService $formService)
    {
        parent::__construct($app);
    }

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
        $rows  = $query->field('f.*')->order('f.sort', 'desc')->order('f.id', 'desc')
            ->page($page, $pageSize)->select()->toArray();

        // 待处理记录数（开启在线支付且默认金额>0 时，仅统计已支付的待处理记录）
        $ids = array_map(static fn ($r) => (int) $r['id'], $rows);
        $st0Map = [];
        $payIds = [];
        foreach ($rows as $r) {
            if ((int) $r['payset'] === 1 && (float) $r['price'] > 0) {
                $payIds[] = (int) $r['id'];
            }
        }
        if (!empty($ids)) {
            $st0Map = Db::name('form_order')->where('aid', $aid)
                ->whereIn('formid', $ids)->where('status', 0)->where('isudel', 0)
                ->when(!empty($payIds), function ($q) use ($payIds) {
                    $q->whereNotIn('formid', $payIds);
                })
                ->group('formid')->column('count(*)', 'formid');
            if (!empty($payIds)) {
                $payMap = Db::name('form_order')->where('aid', $aid)
                    ->whereIn('formid', $payIds)->where('status', 0)->where('paystatus', 1)->where('isudel', 0)
                    ->group('formid')->column('count(*)', 'formid');
                $st0Map += $payMap;
            }
        }

        // 商户名映射（bid=0 平台自营）
        $bnameArr = Db::name('business')->where('aid', $aid)->column('name', 'id');

        foreach ($rows as &$row) {
            $row['st0count'] = (int) ($st0Map[$row['id']] ?? 0);
            $row['bname'] = ((int) $row['bid'] > 0)
                ? (string) ($bnameArr[$row['bid']] ?? ('商户#' . $row['bid']))
                : '平台自营';
        }
        unset($row);

        // 平台视图「所属商户」下拉选项（商户视图不提供该筛选）
        $businessOptions = [['id' => 0, 'name' => '全部']];
        foreach ($bnameArr as $bId => $bName) {
            $businessOptions[] = ['id' => (int) $bId, 'name' => (string) $bName];
        }

        return $this->success(['total' => $total, 'list' => $rows, 'business_options' => $businessOptions]);
    }

    /**
     * 表单详情（附加 aglevellist：默认分类下具有分销权限的会员等级）
     * GET /admin/form/detail?id=
     */
    public function detail()
    {
        $aid = ScopeService::aidOf($this->request);
        $id  = (int) $this->request->get('id', 0);

        // id=0：新建场景，仅下发 aglevellist 供分销设置渲染
        if ($id <= 0) {
            return $this->success(['aglevellist' => $this->agentLevelList($aid)]);
        }

        $row = ScopeService::query('form', $aid)->where('id', $id)->find();
        if (empty($row)) {
            throw new BusinessException('表单不存在');
        }

        $row['aglevellist'] = $this->agentLevelList($aid);

        return $this->success($row);
    }

    /**
     * 具有分销权限的会员等级列表（默认分类 + can_agent<>0）
     * @return array<int, array<string, mixed>>
     */
    protected function agentLevelList(int $aid): array
    {
        $cid = (int) Db::name('member_level_category')->where('aid', $aid)->where('isdefault', 1)->value('id');

        return Db::name('member_level')->where('aid', $aid)->where('cid', $cid)
            ->where('can_agent', '<>', 0)->order('sort,id')
            ->field('id,name,can_agent')->select()->toArray();
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
        // 数值列禁止写入空字符串（MySQL strict 下 decimal/int 报 1366）；未开启支付/未填提成时归零
        foreach (['price', 'commission1', 'commission2', 'commission3'] as $f) {
            if (array_key_exists($f, $record)) {
                $record[$f] = (string) (float) $record[$f];
            }
        }
        foreach (['payset', 'priceedit', 'maxlimit', 'perlimit', 'commissionset', 'fanwei', 'form_query', 'form_query_type'] as $f) {
            if (array_key_exists($f, $record)) {
                $record[$f] = (int) $record[$f];
            }
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
     * 表单复制（name+'-复制'）
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

        // 表单定义（动态列渲染）+ upload_pics 字段拆数组
        $formdef = null;
        $formcontent = [];
        if ($formid > 0) {
            $formRow = ScopeService::query('form', $aid)->where('id', $formid)->find();
            if ($formRow) {
                $formdef = $formRow;
                $formcontent = json_decode((string) ($formRow['content'] ?? ''), true) ?: [];
            }
        }
        foreach ($rows as &$row) {
            foreach ($formcontent as $k => $field) {
                if (($field['key'] ?? '') === 'upload_pics') {
                    $pics = (string) ($row['form' . $k] ?? '');
                    $row['form' . $k] = $pics ? explode(',', $pics) : [];
                }
            }
        }
        unset($row);

        return $this->success(['total' => $total, 'list' => $rows, 'formdef' => $formdef, 'formcontent' => $formcontent]);
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
     * 提交记录改状态
     *
     * st=1 确认：发放分销佣金（commissionset!=-1 且未发放未退款 → addCommission → is_send_commission=1）
     * st=2 驳回：写驳回原因；istuikuan=1 时原路退款 + 扣回商户结算 + 扣回已发佣金（→2，isrefund=1）
     *
     * POST /admin/form/recordsetst {ids[], st, reason, istuikuan}
     */
    public function recordsetst()
    {
        $aid = ScopeService::aidOf($this->request);
        $ids = array_values(array_filter(array_map('intval', (array) $this->request->post('ids/a', []))));
        $st  = (int) $this->request->post('st', 1);
        $reason = trim((string) $this->request->post('reason', ''));
        $istuikuan = (int) $this->request->post('istuikuan', 0) === 1 ? 1 : 0;

        if (empty($ids)) {
            throw new BusinessException('请选择记录');
        }
        if (!in_array($st, [1, 2], true)) {
            throw new BusinessException('非法的状态');
        }
        if (2 === $st && '' === $reason) {
            throw new BusinessException('请填写驳回原因');
        }

        $uid = (int) ($this->request->authUid ?? 0);
        $orders = ScopeService::query('form_order', $aid)->whereIn('id', $ids)->select()->toArray();

        foreach ($orders as $order) {
            if (2 === $st) {
                // 驳回：先落状态与原因
                Db::name('form_order')->where('aid', $aid)->where('id', (int) $order['id'])
                    ->update(['status' => 2, 'reason' => $reason]);

                // 退款：原路退款 + 扣回商户结算 + 扣回已发佣金
                if (1 === $istuikuan) {
                    $this->formService->refundOrder((array) $order, $reason);
                }
            } else {
                // 确认：发放佣金（内部校验 commissionset!=-1 且 is_send_commission=0 且 isrefund=0）
                $this->formService->sendCommission($uid, (array) $order);

                Db::name('form_order')->where('aid', $aid)->where('id', (int) $order['id'])
                    ->update(['status' => 1]);
            }
        }

        return $this->success([], 1 === $st ? '已确认' : '已驳回');
    }

    /**
     * 提交记录删除（软删 isudel=1）
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

    /**
     * 表单数据新增/编辑
     * POST /admin/form/recordsave {formid, id?, mid, status, paystatus, money, reason, fields:{form0..formN}}
     */
    public function recordsave()
    {
        $aid    = ScopeService::aidOf($this->request);
        $formid = (int) $this->request->post('formid', 0);
        $id     = (int) $this->request->post('id', 0);

        $form = ScopeService::query('form', $aid)->where('id', $formid)->find();
        if (empty($form)) {
            throw new BusinessException('表单不存在');
        }
        $formcontent = json_decode((string) ($form['content'] ?? ''), true) ?: [];

        $fields = (array) $this->request->post('fields/a', []);
        $data   = [];
        $data['mid']    = (int) $this->request->post('mid', 0);
        $data['status']  = (int) $this->request->post('status', 0);
        if (!in_array($data['status'], [0, 1, 2], true)) {
            $data['status'] = 0;
        }

        foreach ($formcontent as $k => $v) {
            $key = (string) ($v['key'] ?? '');
            $value = $fields['form' . $k] ?? '';
            if (is_array($value)) {
                $value = implode(',', array_filter($value, static fn ($x) => '' !== (string) $x));
            }
            $data['form' . $k] = strval($value);
            // 必填校验（val3==1 且为空则报错；map 类型除外）
            if (($v['val3'] ?? 0) == 1 && '' === $data['form' . $k] && 'map' !== $key) {
                throw new BusinessException(($v['val1'] ?? '该项') . ' 必填');
            }
        }

        // 支付字段（仅表单开启在线支付时）
        if ((int) ($form['payset'] ?? 0) === 1) {
            $data['money']      = (string) (float) $this->request->post('money', 0);
            $data['paystatus']  = (int) $this->request->post('paystatus', 0) === 1 ? 1 : 0;
        }

        if ($id > 0) {
            $old = ScopeService::query('form_order', $aid)->where('formid', $formid)->where('id', $id)->find();
            if (empty($old)) {
                throw new BusinessException('数据不存在');
            }
            if ('' !== (string) $this->request->post('reason', '')) {
                $data['reason'] = (string) $this->request->post('reason', '');
            }
            ScopeService::query('form_order', $aid)->where('formid', $formid)->where('id', $id)->update($data);
        } else {
            $data['aid']        = $aid;
            $data['bid']        = (int) $form['bid'];
            $data['formid']     = $formid;
            $data['title']      = (string) $form['name'];
            $data['createtime'] = time();
            $data['ordernum']   = date('ymdHis') . $aid . rand(1000, 9999);
            if (!isset($data['paystatus'])) {
                $data['paystatus'] = 0;
            }
            if (!isset($data['money'])) {
                $data['money'] = '0';
            }
            Db::name('form_order')->insert($data);
        }

        return $this->success([], $id > 0 ? '编辑成功' : '新增成功');
    }

    /**
     * 表单数据导出（CSV/XLSX，动态列按表单内容生成）
     * GET /admin/form/recordexcel?formid&status&keyword&starttime&endtime&bid
     */
    public function recordexcel()
    {
        $aid = ScopeService::aidOf($this->request);
        set_time_limit(0);
        ini_set('memory_limit', '1024M');

        $formid  = (int) $this->request->get('formid', 0);
        $status  = trim((string) $this->request->get('status', ''));
        $keyword = trim((string) $this->request->get('keyword', ''));
        $startTime = trim((string) $this->request->get('starttime', ''));
        $endTime   = trim((string) $this->request->get('endtime', ''));

        $form = ScopeService::query('form', $aid)->where('id', $formid)->find();
        if (empty($form)) {
            throw new BusinessException('表单不存在');
        }
        $formcontent = json_decode((string) ($form['content'] ?? ''), true) ?: [];

        $query = ScopeService::queryAs('form_order', $aid, 'o')->where('o.isudel', 0);
        ScopeService::applyBid($query, trim((string) $this->request->get('bid', '')), 'o.bid');
        $query->where('o.formid', $formid);
        if ('' !== $status) {
            $query->where('o.status', (int) $status);
        }
        if ('' !== $keyword) {
            $like = [];
            for ($i = 0; $i <= 20; ++$i) {
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

        $rows = $query->order('o.id', 'desc')->limit(10000)->select()->toArray();

        $statusText = [0 => '待处理', 1 => '已确认', 2 => '已驳回'];
        $titles = ['ID', '用户ID', '昵称'];
        foreach ($formcontent as $v) {
            $titles[] = (string) ($v['val1'] ?? '');
        }
        $titles = array_merge($titles, ['支付状态', '支付金额', '支付单号', '支付时间', '提交时间', '状态']);

        $dataRows = [];
        foreach ($rows as $row) {
            $member = Db::name('member')->where('id', (int) $row['mid'])->field('nickname')->find();
            $line = [$row['id'], $row['mid'], (string) ($member['nickname'] ?? '')];
            foreach ($formcontent as $k => $v) {
                $line[] = (string) ($row['form' . $k] ?? '');
            }
            $line = array_merge($line, [
                (int) ($row['paystatus'] ?? 0) === 1 ? '已支付' : '未支付',
                (string) ($row['money'] ?? '0'),
                (string) ($row['ordernum'] ?? ''),
                !empty($row['paytime']) ? date('Y-m-d H:i:s', (int) $row['paytime']) : '',
                !empty($row['createtime']) ? date('Y-m-d H:i:s', (int) $row['createtime']) : '',
                $statusText[(int) ($row['status'] ?? 0)] ?? '待处理',
            ]);
            $dataRows[] = $line;
        }

        ExcelUtil::export($titles, $dataRows, '表单数据_' . $form['name'] . '_' . date('YmdHis'));
    }
}
