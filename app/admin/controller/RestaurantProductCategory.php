<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\ScopeService;
use think\facade\Db;

/**
 * 租户后台 - 餐饮菜品分类（restaurant_product_category）
 *
 * 字段：name/pic/pid 层级/is_shop 店内/is_takeaway 外卖/is_booking 预定/status 显隐/sort
 */
class RestaurantProductCategory extends BaseController
{
    /**
     * 分类列表（全量，sort desc/id asc，前端自行组树）
     * GET /admin/dining/procat/list?keyword=
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        $keyword = trim((string) $this->request->get('keyword', ''));

        $query = ScopeService::query('restaurant_product_category', $aid);
        if ('' !== $keyword) {
            $query->where('name', 'like', '%' . $keyword . '%');
        }

        $rows = $query->order('sort', 'desc')->order('id', 'asc')->select()->toArray();

        // 各分类下的菜品数量（菜品 cid 为逗号串，用 FIND_IN_SET 统计）
        $counts = Db::name('restaurant_product')
            ->where('aid', $aid)
            ->where('is_delete', 0)
            ->fieldRaw('id, cid')
            ->select()
            ->toArray();
        $countMap = [];
        foreach ($counts as $p) {
            $cids = array_filter(array_map('intval', explode(',', (string) $p['cid'])));
            foreach ($cids as $cid) {
                $countMap[$cid] = ($countMap[$cid] ?? 0) + 1;
            }
        }

        foreach ($rows as &$row) {
            $row['id']           = (int) $row['id'];
            $row['pid']          = (int) $row['pid'];
            $row['is_shop']      = (int) $row['is_shop'];
            $row['is_takeaway']  = (int) $row['is_takeaway'];
            $row['is_booking']   = (int) $row['is_booking'];
            $row['status']       = (int) $row['status'];
            $row['sort']         = (int) $row['sort'];
            $row['product_count'] = $countMap[(int) $row['id']] ?? 0;
        }
        unset($row);

        return $this->success(['list' => $rows]);
    }

    /**
     * 新增/编辑分类
     * POST /admin/dining/procat/save
     */
    public function save()
    {
        $aid = ScopeService::aidOf($this->request);

        $id   = (int) $this->request->post('id', 0);
        $name = trim((string) $this->request->post('name', ''));
        if ('' === $name) {
            throw new BusinessException('请填写分类名称');
        }

        $pid = (int) $this->request->post('pid', 0);
        if ($pid > 0) {
            if ($pid === $id) {
                throw new BusinessException('上级分类不能选择自身');
            }
            $parent = ScopeService::query('restaurant_product_category', $aid)->where('id', $pid)->find();
            if (empty($parent)) {
                throw new BusinessException('上级分类不存在');
            }
            if ((int) $parent['pid'] > 0) {
                throw new BusinessException('仅支持两级分类');
            }
        }

        $data = [
            'name'         => $name,
            'pic'          => trim((string) $this->request->post('pic', '')),
            'pid'          => $pid,
            'is_shop'      => (int) $this->request->post('is_shop', 1) === 1 ? 1 : 0,
            'is_takeaway'  => (int) $this->request->post('is_takeaway', 1) === 1 ? 1 : 0,
            'is_booking'   => (int) $this->request->post('is_booking', 1) === 1 ? 1 : 0,
            'status'       => (int) $this->request->post('status', 1) === 1 ? 1 : 0,
            'sort'         => (int) $this->request->post('sort', 0),
        ];

        if ($id > 0) {
            $exists = ScopeService::query('restaurant_product_category', $aid)->where('id', $id)->find();
            if (empty($exists)) {
                throw new BusinessException('分类不存在');
            }
            Db::name('restaurant_product_category')->where('id', $id)->update($data);
        } else {
            $data['aid']         = $aid;
            $data['bid']         = 0;
            $data['create_time'] = time();
            $id = Db::name('restaurant_product_category')->insertGetId($data);
        }

        return $this->success(['id' => (int) $id], '保存成功');
    }

    /**
     * 删除分类（有子分类或被菜品引用时拒绝）
     * POST /admin/dining/procat/del
     */
    public function del()
    {
        $aid = ScopeService::aidOf($this->request);

        $id = (int) $this->request->post('id', 0);
        if ($id <= 0) {
            throw new BusinessException('参数错误');
        }

        $row = ScopeService::query('restaurant_product_category', $aid)->where('id', $id)->find();
        if (empty($row)) {
            throw new BusinessException('分类不存在');
        }

        $childCount = ScopeService::query('restaurant_product_category', $aid)->where('pid', $id)->count();
        if ($childCount > 0) {
            throw new BusinessException('该分类下存在子分类，请先删除子分类');
        }

        // 菜品 cid 为逗号串，FIND_IN_SET 校验引用
        $refCount = Db::name('restaurant_product')
            ->where('aid', $aid)
            ->where('is_delete', 0)
            ->whereRaw('FIND_IN_SET(' . $id . ', `cid`)')
            ->count();
        if ($refCount > 0) {
            throw new BusinessException('该分类下存在菜品，不能删除');
        }

        Db::name('restaurant_product_category')->where('id', $id)->delete();

        return $this->success([], '删除成功');
    }
}
