<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\ScopeService;
use think\facade\Db;

/**
 * 租户后台 - 礼品卡分类（对照 canzhao app/controller/LipinCategory.php）
 *
 * 两级分类平铺列表（子级加 ├ 前缀）/新增/编辑/删除
 */
class LipinCategory extends BaseController
{
    /**
     * 分类列表（两级平铺）
     * GET /admin/lipinCategory/list
     */
    public function list()
    {
        $aid  = ScopeService::aidOf($this->request);
        $rows = ScopeService::query('lipin_category', $aid)
            ->order('pid', 'asc')->order('sort', 'asc')->order('id', 'asc')
            ->select()
            ->toArray();

        $parents = [];
        $children = [];
        foreach ($rows as $row) {
            if ((int) $row['pid'] <= 0) {
                $parents[] = $row;
            } else {
                $children[(int) $row['pid']][] = $row;
            }
        }

        $result = [];
        foreach ($parents as $parent) {
            $result[] = $parent;
            foreach ($children[(int) $parent['id']] ?? [] as $child) {
                $child['name'] = '├ ' . $child['name'];
                $result[] = $child;
            }
        }

        return $this->success(['total' => count($result), 'list' => $result]);
    }

    /**
     * 父级选项（编辑时排除自身及子级）
     * GET /admin/lipinCategory/parents?id=
     */
    public function parents()
    {
        $aid = ScopeService::aidOf($this->request);
        $id  = (int) $this->request->get('id', 0);

        $rows = ScopeService::query('lipin_category', $aid)
            ->where('pid', 0)
            ->order('sort', 'asc')->order('id', 'asc')
            ->field('id, name')
            ->select()
            ->toArray();

        if ($id > 0) {
            $rows = array_values(array_filter($rows, static fn ($r) => (int) $r['id'] !== $id));
        }

        return $this->success($rows);
    }

    /**
     * 分类新增/编辑
     * POST /admin/lipinCategory/save {id?, pid, name, pic, status, sort}
     */
    public function save()
    {
        $aid  = ScopeService::aidOf($this->request);
        $data = $this->request->post();
        $data = is_array($data) ? $data : [];

        if ('' === trim((string) ($data['name'] ?? ''))) {
            throw new BusinessException('请填写分类名称');
        }

        $record = [
            'pid'    => (int) ($data['pid'] ?? 0),
            'name'   => trim((string) $data['name']),
            'pic'    => (string) ($data['pic'] ?? ''),
            'status' => (int) ($data['status'] ?? 1),
            'sort'   => (int) ($data['sort'] ?? 0),
        ];

        $id = (int) ($data['id'] ?? 0);
        if ($id > 0) {
            $exists = ScopeService::query('lipin_category', $aid)->where('id', $id)->find();
            if (empty($exists)) {
                throw new BusinessException('分类不存在');
            }
            if ($record['pid'] === $id) {
                throw new BusinessException('父级分类不能选择自身');
            }
            ScopeService::query('lipin_category', $aid)->where('id', $id)->update($record);
        } else {
            $record['aid']        = $aid;
            $record['createtime'] = time();
            $id = (int) Db::name('lipin_category')->insertGetId($record);
        }

        return $this->success(['id' => $id], '保存成功');
    }

    /**
     * 分类删除（含子级校验）
     * POST /admin/lipinCategory/delete {id}
     */
    public function delete()
    {
        $aid = ScopeService::aidOf($this->request);
        $id  = (int) $this->request->post('id', 0);

        if ($id <= 0) {
            throw new BusinessException('请选择要删除的分类');
        }

        $exists = ScopeService::query('lipin_category', $aid)->where('id', $id)->find();
        if (empty($exists)) {
            throw new BusinessException('分类不存在');
        }

        $childCount = ScopeService::query('lipin_category', $aid)->where('pid', $id)->count();
        if ($childCount > 0) {
            throw new BusinessException('请先删除子分类');
        }

        $usedCount = ScopeService::query('lipin', $aid)->where('cid', $id)->count();
        if ($usedCount > 0) {
            throw new BusinessException('该分类下存在礼品卡，无法删除');
        }

        ScopeService::query('lipin_category', $aid)->where('id', $id)->delete();

        return $this->success([], '删除成功');
    }
}
