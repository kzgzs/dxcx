<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\ScopeService;
use think\facade\Db;

/**
 * 租户后台 - 餐饮寄存位置字典（批次6-T6-3 任务204，自研，canzhao 无位置管理控制器）
 *
 * 两级字典：位置（restaurant_deposit_position）→ 编号（restaurant_deposit_position_number.position_id）
 * 订单冗余存 position_name/number_name，删除字典不影响历史订单展示
 */
class RestaurantDepositPosition extends BaseController
{
    /**
     * 位置列表（含各位置下编号数量）
     * GET /admin/restaurant/deposit/position/list?keyword=
     */
    public function positionList()
    {
        $aid     = ScopeService::aidOf($this->request);
        $keyword = trim((string) $this->request->get('keyword', ''));

        $query = ScopeService::query('restaurant_deposit_position', $aid)->where('bid', 0);

        if ('' !== $keyword) {
            $query->where('name', 'like', '%' . $keyword . '%');
        }

        $rows = $query->order('sort', 'desc')->order('id', 'asc')->select()->toArray();

        $ids = array_map(static fn (array $r) => (int) $r['id'], $rows);

        $countMap = [];

        if ($ids) {
            $counts = Db::name('restaurant_deposit_position_number')
                ->where('aid', $aid)->whereIn('position_id', $ids)
                ->group('position_id')->field('position_id, COUNT(*) AS c')
                ->select()->toArray();

            foreach ($counts as $c) {
                $countMap[(int) $c['position_id']] = (int) $c['c'];
            }
        }

        return $this->success([
            'list' => array_map(static fn (array $r) => [
                'id'           => (int) $r['id'],
                'name'         => (string) $r['name'],
                'sort'         => (int) $r['sort'],
                'status'       => (int) $r['status'],
                'createtime'   => (int) $r['createtime'],
                'number_count' => $countMap[(int) $r['id']] ?? 0,
            ], $rows),
        ]);
    }

    /**
     * 新增/编辑位置（带 id 为编辑）
     * POST /admin/restaurant/deposit/position/save {id?, name, sort?, status?}
     */
    public function positionSave()
    {
        $aid    = ScopeService::aidOf($this->request);
        $id     = (int) $this->request->post('id', 0);
        $name   = trim((string) $this->request->post('name', ''));
        $sort   = (int) $this->request->post('sort', 0);
        $status = (int) $this->request->post('status', 1);
        $status = in_array($status, [0, 1], true) ? $status : 1;

        if ('' === $name) {
            throw new BusinessException('请填写位置名称');
        }

        $dup = ScopeService::query('restaurant_deposit_position', $aid)
            ->where('bid', 0)->where('name', $name);

        if ($id > 0) {
            $dup->where('id', '<>', $id);
        }

        if ($dup->count() > 0) {
            throw new BusinessException('位置名称已存在');
        }

        if ($id > 0) {
            $exists = ScopeService::query('restaurant_deposit_position', $aid)
                ->where('bid', 0)->where('id', $id)->find();

            if (empty($exists)) {
                throw new BusinessException('位置不存在', 404, 404);
            }

            Db::name('restaurant_deposit_position')->where('id', $id)->update([
                'name'   => $name,
                'sort'   => $sort,
                'status' => $status,
            ]);

            return $this->success([], '保存成功');
        }

        $newId = (int) Db::name('restaurant_deposit_position')->insertGetId([
            'aid'        => $aid,
            'bid'        => 0,
            'name'       => $name,
            'sort'       => $sort,
            'status'     => $status,
            'createtime' => time(),
        ]);

        return $this->success(['id' => $newId], '添加成功');
    }

    /**
     * 删除位置（级联删除其下编号）
     * POST /admin/restaurant/deposit/position/del {id}
     */
    public function positionDel()
    {
        $aid = ScopeService::aidOf($this->request);
        $id  = (int) $this->request->post('id', 0);

        if ($id <= 0) {
            throw new BusinessException('缺少位置ID');
        }

        $exists = ScopeService::query('restaurant_deposit_position', $aid)
            ->where('bid', 0)->where('id', $id)->find();

        if (empty($exists)) {
            throw new BusinessException('位置不存在或已被删除', 404, 404);
        }

        Db::name('restaurant_deposit_position')->where('id', $id)->delete();
        Db::name('restaurant_deposit_position_number')
            ->where('aid', $aid)->where('position_id', $id)->delete();

        return $this->success([], '删除成功');
    }

    /**
     * 编号列表（按位置筛选）
     * GET /admin/restaurant/deposit/number/list?position_id=&keyword=
     */
    public function numberList()
    {
        $aid        = ScopeService::aidOf($this->request);
        $positionId = (int) $this->request->get('position_id', 0);
        $keyword    = trim((string) $this->request->get('keyword', ''));

        $query = ScopeService::query('restaurant_deposit_position_number', $aid)->where('bid', 0);

        if ($positionId > 0) {
            $query->where('position_id', $positionId);
        }

        if ('' !== $keyword) {
            $query->where('name', 'like', '%' . $keyword . '%');
        }

        $rows = $query->order('sort', 'desc')->order('id', 'asc')->select()->toArray();

        // 位置名称补全
        $positionIds = array_values(array_unique(array_map(static fn (array $r) => (int) $r['position_id'], $rows)));
        $nameMap     = [];

        if ($positionIds) {
            $positions = Db::name('restaurant_deposit_position')
                ->where('aid', $aid)->whereIn('id', $positionIds)->field('id,name')->select()->toArray();

            foreach ($positions as $p) {
                $nameMap[(int) $p['id']] = (string) $p['name'];
            }
        }

        return $this->success([
            'list' => array_map(static fn (array $r) => [
                'id'            => (int) $r['id'],
                'position_id'   => (int) $r['position_id'],
                'position_name' => $nameMap[(int) $r['position_id']] ?? '',
                'name'          => (string) $r['name'],
                'sort'          => (int) $r['sort'],
                'status'        => (int) $r['status'],
                'createtime'    => (int) $r['createtime'],
            ], $rows),
        ]);
    }

    /**
     * 新增/编辑编号（带 id 为编辑）
     * POST /admin/restaurant/deposit/number/save {id?, position_id, name, sort?, status?}
     */
    public function numberSave()
    {
        $aid        = ScopeService::aidOf($this->request);
        $id         = (int) $this->request->post('id', 0);
        $positionId = (int) $this->request->post('position_id', 0);
        $name       = trim((string) $this->request->post('name', ''));
        $sort       = (int) $this->request->post('sort', 0);
        $status     = (int) $this->request->post('status', 1);
        $status     = in_array($status, [0, 1], true) ? $status : 1;

        if ('' === $name) {
            throw new BusinessException('请填写编号名称');
        }

        if ($positionId <= 0) {
            throw new BusinessException('请选择所属位置');
        }

        $position = ScopeService::query('restaurant_deposit_position', $aid)
            ->where('bid', 0)->where('id', $positionId)->find();

        if (empty($position)) {
            throw new BusinessException('所属位置不存在');
        }

        $dup = ScopeService::query('restaurant_deposit_position_number', $aid)
            ->where('bid', 0)->where('position_id', $positionId)->where('name', $name);

        if ($id > 0) {
            $dup->where('id', '<>', $id);
        }

        if ($dup->count() > 0) {
            throw new BusinessException('该位置下编号已存在');
        }

        if ($id > 0) {
            $exists = ScopeService::query('restaurant_deposit_position_number', $aid)
                ->where('bid', 0)->where('id', $id)->find();

            if (empty($exists)) {
                throw new BusinessException('编号不存在', 404, 404);
            }

            Db::name('restaurant_deposit_position_number')->where('id', $id)->update([
                'position_id' => $positionId,
                'name'        => $name,
                'sort'        => $sort,
                'status'      => $status,
            ]);

            return $this->success([], '保存成功');
        }

        $newId = (int) Db::name('restaurant_deposit_position_number')->insertGetId([
            'aid'         => $aid,
            'bid'         => 0,
            'position_id' => $positionId,
            'name'        => $name,
            'sort'        => $sort,
            'status'      => $status,
            'createtime'  => time(),
        ]);

        return $this->success(['id' => $newId], '添加成功');
    }

    /**
     * 删除编号
     * POST /admin/restaurant/deposit/number/del {id}
     */
    public function numberDel()
    {
        $aid = ScopeService::aidOf($this->request);
        $id  = (int) $this->request->post('id', 0);

        if ($id <= 0) {
            throw new BusinessException('缺少编号ID');
        }

        $affected = ScopeService::query('restaurant_deposit_position_number', $aid)
            ->where('bid', 0)->where('id', $id)->delete();

        if ($affected < 1) {
            throw new BusinessException('编号不存在或已被删除', 404, 404);
        }

        return $this->success([], '删除成功');
    }
}
