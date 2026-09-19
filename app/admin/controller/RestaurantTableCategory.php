<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\ScopeService;
use think\facade\Db;

/**
 * 租户后台 - 餐饮餐桌分类（restaurant_table_category）
 *
 * 字段：name/pic/service_fee 服务费/limit_fee 最低消费/booking_fee 预定费/seat 座位/sort/status
 */
class RestaurantTableCategory extends BaseController
{
    /**
     * 分类列表（全量，sort desc/id desc，附桌台数）
     * GET /admin/dining/tablecat/list?keyword=
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        $keyword = trim((string) $this->request->get('keyword', ''));

        $query = ScopeService::query('restaurant_table_category', $aid);
        if ('' !== $keyword) {
            $query->where('name', 'like', '%' . $keyword . '%');
        }

        $rows = $query->order('sort', 'desc')->order('id', 'desc')->select()->toArray();

        // 各分类下的桌台数量
        $counts = ScopeService::query('restaurant_table', $aid)
            ->field('cid, count(*) as cnt')
            ->group('cid')
            ->select()
            ->toArray();
        $countMap = [];
        foreach ($counts as $c) {
            $countMap[(int) $c['cid']] = (int) $c['cnt'];
        }

        foreach ($rows as &$row) {
            $row['id']           = (int) $row['id'];
            $row['service_fee']  = (float) $row['service_fee'];
            $row['limit_fee']    = (float) $row['limit_fee'];
            $row['booking_fee']  = (float) $row['booking_fee'];
            $row['seat']         = (int) $row['seat'];
            $row['sort']         = (int) $row['sort'];
            $row['status']       = (int) $row['status'];
            $row['table_count']  = $countMap[(int) $row['id']] ?? 0;
        }
        unset($row);

        return $this->success(['list' => $rows]);
    }

    /**
     * 新增/编辑分类
     * POST /admin/dining/tablecat/save
     */
    public function save()
    {
        $aid = ScopeService::aidOf($this->request);

        $id         = (int) $this->request->post('id', 0);
        $name       = trim((string) $this->request->post('name', ''));
        $pic        = trim((string) $this->request->post('pic', ''));
        $serviceFee = max(0, (float) $this->request->post('service_fee', 0));
        $limitFee   = max(0, (float) $this->request->post('limit_fee', 0));
        $bookingFee = max(0, (float) $this->request->post('booking_fee', 0));
        $seat       = max(0, (int) $this->request->post('seat', 0));
        $sort       = (int) $this->request->post('sort', 1);
        $status     = (int) $this->request->post('status', 1) ? 1 : 0;

        if ('' === $name) {
            throw new BusinessException('请填写分类名称');
        }

        $data = [
            'name'        => $name,
            'pic'         => $pic,
            'service_fee' => $serviceFee,
            'limit_fee'   => $limitFee,
            'booking_fee' => $bookingFee,
            'seat'        => $seat,
            'sort'        => $sort,
            'status'      => $status,
        ];

        if ($id > 0) {
            $exists = ScopeService::query('restaurant_table_category', $aid)->where('id', $id)->find();
            if (empty($exists)) {
                throw new BusinessException('分类不存在');
            }
            $data['update_time'] = time();
            Db::name('restaurant_table_category')->where('id', $id)->update($data);

            return $this->success(['id' => $id], '保存成功');
        }

        $data['aid']         = $aid;
        $data['bid']         = 0;
        $data['pid']         = 0;
        $data['create_time'] = time();
        $data['update_time'] = time();
        $newId = (int) Db::name('restaurant_table_category')->insertGetId($data);

        return $this->success(['id' => $newId], '创建成功');
    }

    /**
     * 删除分类（分类下有餐桌时拒绝）
     * POST /admin/dining/tablecat/del {id}
     */
    public function del()
    {
        $aid = ScopeService::aidOf($this->request);

        $id = (int) $this->request->post('id', 0);
        if ($id <= 0) {
            throw new BusinessException('缺少分类ID');
        }

        $exists = ScopeService::query('restaurant_table_category', $aid)->where('id', $id)->find();
        if (empty($exists)) {
            throw new BusinessException('分类不存在');
        }

        $tableCount = ScopeService::query('restaurant_table', $aid)->where('cid', $id)->count();
        if ($tableCount > 0) {
            throw new BusinessException('该分类下还有 ' . $tableCount . ' 张餐桌，无法删除');
        }

        Db::name('restaurant_table_category')->where('id', $id)->delete();

        return $this->success([], '删除成功');
    }
}
