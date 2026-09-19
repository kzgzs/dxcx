<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\ScopeService;

/**
 * 租户后台 - 拼团商品分类（P24-7 批次8 任务227）
 *
 * 仿 LuckyCollage 商品分类现成模式，落到 collage_category 表（支持 pid 二级）
 */
class CollageCategory extends BaseController
{
    /**
     * 分类列表
     * GET /admin/collageCategory/list
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        $rows = ScopeService::query('collage_category', $aid)
            ->order('sort', 'desc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        $list = array_map(fn (array $r) => [
            'id'         => (int) ($r['id'] ?? 0),
            'pid'        => (int) ($r['pid'] ?? 0),
            'name'       => (string) ($r['name'] ?? ''),
            'pic'        => (string) ($r['pic'] ?? ''),
            'status'     => (int) ($r['status'] ?? 1),
            'sort'       => (int) ($r['sort'] ?? 1),
            'createtime' => (int) ($r['createtime'] ?? 0),
        ], $rows);

        return $this->success(['list' => $list]);
    }

    /**
     * 新增/编辑分类
     * POST /admin/collageCategory/save {id?, pid, name, pic, status, sort}
     */
    public function save()
    {
        $aid = ScopeService::aidOf($this->request);

        $data = $this->request->post();
        $name = trim((string) ($data['name'] ?? ''));

        if ('' === $name) {
            throw new BusinessException('请输入分类名称');
        }

        $pid = (int) ($data['pid'] ?? 0);
        $id  = (int) ($data['id'] ?? 0);

        if ($pid > 0) {
            $parent = ScopeService::query('collage_category', $aid)->where('id', $pid)->find();
            if (empty($parent)) {
                throw new BusinessException('上级分类不存在');
            }
            if ($id > 0 && $pid === $id) {
                throw new BusinessException('上级分类不能是自身');
            }
        }

        $row = [
            'pid'    => $pid,
            'name'   => $name,
            'pic'    => trim((string) ($data['pic'] ?? '')),
            'status' => (int) ($data['status'] ?? 1) === 1 ? 1 : 0,
            'sort'   => (int) ($data['sort'] ?? 1),
        ];

        if ($id > 0) {
            ScopeService::query('collage_category', $aid)->where('id', $id)->update($row);
        } else {
            $row['aid']        = $aid;
            $row['createtime'] = time();
            $id                = (int) ScopeService::query('collage_category', $aid)->insertGetId($row);
        }

        return $this->success(['id' => $id], '保存成功');
    }

    /**
     * 删除分类（有子分类或被商品占用时拒绝）
     * POST /admin/collageCategory/delete {id}
     */
    public function delete()
    {
        $aid = ScopeService::aidOf($this->request);

        $id = (int) $this->request->post('id', 0);

        if ($id <= 0) {
            throw new BusinessException('请选择分类');
        }

        $child = ScopeService::query('collage_category', $aid)->where('pid', $id)->count();

        if ($child > 0) {
            throw new BusinessException('该分类下存在子分类，暂不能删除');
        }

        $used = ScopeService::query('collage_product', $aid)->where('cid', $id)->count();

        if ($used > 0) {
            throw new BusinessException('该分类下存在商品，暂不能删除');
        }

        ScopeService::query('collage_category', $aid)->where('id', $id)->delete();

        return $this->success([], '删除成功');
    }
}
