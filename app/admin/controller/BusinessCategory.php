<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\PlogService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 商户分类（批次8 任务221）
 *
 * 对标参考系统 Business.php category/categoryedit/categorysave/categorydel：
 * 字段口径 name/pic/sort/status（business_category 表已存在）
 */
class BusinessCategory extends BaseController
{
    /**
     * 分类列表
     * GET /admin/businessCategory/list?page=&pagesize=&name=&status=
     */
    public function list()
    {
        $aid      = ScopeService::aidOf($this->request);
        $page     = max(1, (int) $this->request->get('page', 1));
        $pagesize = min(100, max(1, (int) $this->request->get('pagesize', 20)));
        $name     = trim((string) $this->request->get('name', ''));
        $status   = $this->request->get('status', '');

        $query = ScopeService::query('business_category', $aid);
        if ('' !== $name) {
            $query->whereLike('name', '%' . $name . '%');
        }
        if ('' !== $status && $status !== null) {
            $query->where('status', (int) $status);
        }

        $total = (clone $query)->count();
        // 表头排序（白名单字段，默认 sort desc,id asc）
        $sf = (string) $this->request->get('field', 'sort');
        $so = strtolower((string) $this->request->get('order', 'desc')) === 'asc' ? 'asc' : 'desc';
        if (!in_array($sf, ['id', 'name', 'sort', 'status'], true)) {
            $sf = 'sort';
        }
        if ($sf === 'sort') {
            $query->order('sort', $so)->order('id', 'asc');
        } else {
            $query->order($sf, $so);
        }
        $list  = $query->page($page, $pagesize)->select()->toArray();

        foreach ($list as &$row) {
            $row['id']         = (int) $row['id'];
            $row['status']     = (int) $row['status'];
            $row['sort']       = (int) $row['sort'];
            $row['createtime'] = (int) $row['createtime'];
        }
        unset($row);

        return $this->success(['list' => $list, 'total' => $total]);
    }

    /**
     * 分类下拉（商户编辑等联动用，仅启用项）
     * GET /admin/businessCategory/options
     */
    public function options()
    {
        $aid = ScopeService::aidOf($this->request);

        $rows = ScopeService::query('business_category', $aid)
            ->where('status', 1)
            ->order('sort', 'desc')->order('id', 'asc')
            ->field('id,name')
            ->select()->toArray();

        return $this->success(array_map(static fn ($r) => ['id' => (int) $r['id'], 'name' => (string) $r['name']], $rows));
    }

    /**
     * 分类保存（新增/编辑）
     * POST /admin/businessCategory/save  {id?, name, pic, sort, status}
     */
    public function save()
    {
        $aid    = ScopeService::aidOf($this->request);
        $uid    = (int) ($this->request->authUid ?? 0);
        $id     = (int) $this->request->post('id', 0);
        $name   = trim((string) $this->request->post('name', ''));
        $pic    = trim((string) $this->request->post('pic', ''));
        $sort   = (int) $this->request->post('sort', 0);
        $status = (int) $this->request->post('status', 1);

        if ('' === $name) {
            return $this->error('请填写分类名称');
        }

        $data = [
            'name'   => $name,
            'pic'    => $pic,
            'sort'   => $sort,
            'status' => $status ? 1 : 0,
        ];

        if ($id > 0) {
            $exists = ScopeService::query('business_category', $aid)->where('id', $id)->find();
            if (empty($exists)) {
                return $this->error('分类不存在');
            }
            ScopeService::query('business_category', $aid)->where('id', $id)->update($data);
            PlogService::write($aid, $uid, '修改商家分类' . $id);
        } else {
            $data['aid']        = $aid;
            $data['createtime'] = time();
            $id = (int) ScopeService::query('business_category', $aid)->insertGetId($data);
            PlogService::write($aid, $uid, '添加商家分类' . $id);
        }

        return $this->success(['id' => $id], '操作成功');
    }

    /**
     * 分类删除（批量）
     * POST /admin/businessCategory/delete  {ids:[]}
     */
    public function delete()
    {
        $aid = ScopeService::aidOf($this->request);
        $uid = (int) ($this->request->authUid ?? 0);
        $ids = array_map('intval', (array) $this->request->post('ids', []));

        if (empty($ids)) {
            return $this->error('请选择分类');
        }

        ScopeService::query('business_category', $aid)->whereIn('id', $ids)->delete();
        PlogService::write($aid, $uid, '删除商家分类' . implode(',', $ids));

        return $this->success([], '删除成功');
    }
}
