<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\PlogService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 城市商户（批次8 任务221）
 *
 * 参考系统 Business.php businesscity/businesscitysave/businesscitydel/businesscityset
 * 均为空壳方法，按其 ApiBusiness.php（城市链接过滤口径）自研：
 * 一行 = 一个城市入口，过滤商户时按 district > city > province 逐级匹配。
 */
class BusinessCity extends BaseController
{
    /**
     * 城市商户列表
     * GET /admin/businessCity/list?page=&pagesize=&name=&province=&city=&district=&status=&bid=
     */
    public function list()
    {
        $aid      = ScopeService::aidOf($this->request);
        $page     = max(1, (int) $this->request->get('page', 1));
        $pagesize = min(100, max(1, (int) $this->request->get('pagesize', 10)));
        $name     = trim((string) $this->request->get('name', ''));
        $province = trim((string) $this->request->get('province', ''));
        $city     = trim((string) $this->request->get('city', ''));
        $district = trim((string) $this->request->get('district', ''));
        $status   = $this->request->get('status', '');

        $query = ScopeService::query('business_city', $aid);
        if ('' !== $name) {
            $query->whereLike('name', '%' . $name . '%');
        }
        if ('' !== $province) {
            $query->whereLike('province', '%' . $province . '%');
        }
        if ('' !== $city) {
            $query->whereLike('city', '%' . $city . '%');
        }
        if ('' !== $district) {
            $query->whereLike('district', '%' . $district . '%');
        }
        if ('' !== $status && $status !== null) {
            $query->where('status', (int) $status);
        }

        $total = (clone $query)->count();
        $list  = $query->order('sort', 'desc')->order('id', 'desc')->page($page, $pagesize)->select()->toArray();

        foreach ($list as &$row) {
            $row['id']       = (int) $row['id'];
            $row['status']   = (int) $row['status'];
            $row['sort']     = (int) $row['sort'];
            $row['createtime'] = (int) $row['createtime'];
        }
        unset($row);

        return $this->success(['list' => $list, 'total' => $total]);
    }

    /**
     * 城市商户保存（新增/编辑）
     * POST /admin/businessCity/save  {id?, name, province, city, district, status, sort}
     */
    public function save()
    {
        $aid      = ScopeService::aidOf($this->request);
        $uid      = (int) ($this->request->authUid ?? 0);
        $id       = (int) $this->request->post('id', 0);
        $name     = trim((string) $this->request->post('name', ''));
        $province = trim((string) $this->request->post('province', ''));
        $city     = trim((string) $this->request->post('city', ''));
        $district = trim((string) $this->request->post('district', ''));
        $status   = (int) $this->request->post('status', 1);
        $sort     = (int) $this->request->post('sort', 0);

        if ('' === $name) {
            return $this->error('请填写城市名称');
        }
        if ('' === $province && '' === $city && '' === $district) {
            return $this->error('请至少填写省/市/区其中一项');
        }

        $data = [
            'name'     => $name,
            'province' => $province,
            'city'     => $city,
            'district' => $district,
            'status'   => $status ? 1 : 0,
            'sort'     => $sort,
        ];

        if ($id > 0) {
            $exists = ScopeService::query('business_city', $aid)->where('id', $id)->find();
            if (empty($exists)) {
                return $this->error('记录不存在');
            }
            ScopeService::query('business_city', $aid)->where('id', $id)->update($data);
            PlogService::write($aid, $uid, '修改城市商户' . $id);
        } else {
            $data['aid']        = $aid;
            $data['createtime'] = time();
            $id = (int) ScopeService::query('business_city', $aid)->insertGetId($data);
            PlogService::write($aid, $uid, '添加城市商户' . $id);
        }

        return $this->success(['id' => $id], '操作成功');
    }

    /**
     * 城市商户改状态（批量启停）
     * POST /admin/businessCity/setst  {ids:[], st:0|1}
     */
    public function setst()
    {
        $aid = ScopeService::aidOf($this->request);
        $uid = (int) ($this->request->authUid ?? 0);
        $ids = array_map('intval', (array) $this->request->post('ids', []));
        $st  = (int) $this->request->post('st', 1);

        if (empty($ids)) {
            return $this->error('请选择记录');
        }

        ScopeService::query('business_city', $aid)->whereIn('id', $ids)->update(['status' => $st ? 1 : 0]);
        PlogService::write($aid, $uid, '城市商户改状态' . implode(',', $ids));

        return $this->success([], '操作成功');
    }

    /**
     * 城市商户删除（批量）
     * POST /admin/businessCity/delete  {ids:[]}
     */
    public function delete()
    {
        $aid = ScopeService::aidOf($this->request);
        $uid = (int) ($this->request->authUid ?? 0);
        $ids = array_map('intval', (array) $this->request->post('ids', []));

        if (empty($ids)) {
            return $this->error('请选择记录');
        }

        ScopeService::query('business_city', $aid)->whereIn('id', $ids)->delete();
        PlogService::write($aid, $uid, '删除城市商户' . implode(',', $ids));

        return $this->success([], '删除成功');
    }
}
