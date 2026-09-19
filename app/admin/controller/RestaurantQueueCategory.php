<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\ScopeService;
use think\facade\Db;

/**
 * 租户后台 - 餐饮排队队列分类（restaurant_queue_category）
 *
 * 字段：name/code 前缀/seat_min-seat_max 人数段/per_minute 每号预计分钟/call_text 叫号模板/sort/status
 * 批次6-T6-2：取号序号 = code 前缀 + 当日分类内自增两位（A01）
 */
class RestaurantQueueCategory extends BaseController
{
    /**
     * 分类列表（全量，sort desc/id desc，附当日排队中数量）
     * GET /admin/restaurant/queue/category/list
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        $rows = ScopeService::query('restaurant_queue_category', $aid)
            ->where('bid', 0)
            ->order('sort', 'desc')
            ->order('id', 'desc')
            ->select()
            ->toArray();

        // 各分类下「排队中」数量（当日）
        $counts = ScopeService::query('restaurant_queue', $aid)
            ->where('bid', 0)
            ->where('date', date('Y-m-d'))
            ->where('status', 0)
            ->field('cid, count(*) as cnt')
            ->group('cid')
            ->select()
            ->toArray();
        $countMap = [];
        foreach ($counts as $c) {
            $countMap[(int) $c['cid']] = (int) $c['cnt'];
        }

        foreach ($rows as &$row) {
            $row['id']         = (int) $row['id'];
            $row['seat_min']   = (int) $row['seat_min'];
            $row['seat_max']   = (int) $row['seat_max'];
            $row['per_minute'] = (int) $row['per_minute'];
            $row['sort']       = (int) $row['sort'];
            $row['status']     = (int) $row['status'];
            $row['waitnum']    = $countMap[(int) $row['id']] ?? 0;
        }
        unset($row);

        return $this->success(['list' => $rows]);
    }

    /**
     * 新增/编辑分类
     * POST /admin/restaurant/queue/category/save
     */
    public function save()
    {
        $aid = ScopeService::aidOf($this->request);

        $id         = (int) $this->request->post('id', 0);
        $name       = trim((string) $this->request->post('name', ''));
        $code       = trim((string) $this->request->post('code', ''));
        $seatMin    = max(0, (int) $this->request->post('seat_min', 0));
        $seatMax    = max(0, (int) $this->request->post('seat_max', 0));
        $perMinute  = max(1, (int) $this->request->post('per_minute', 1));
        $callText   = trim((string) $this->request->post('call_text', ''));
        $sort       = (int) $this->request->post('sort', 1);
        $status     = (int) $this->request->post('status', 1) ? 1 : 0;

        if ('' === $name) {
            throw new BusinessException('请填写分类名称');
        }
        if ('' === $code) {
            throw new BusinessException('请填写队列前缀');
        }
        if ('' === $callText) {
            $callText = '请[排队号]号顾客前往就餐';
        }

        // 前缀唯一（同租户内不允许重复，避免取号撞号）
        $dupQuery = ScopeService::query('restaurant_queue_category', $aid)
            ->where('bid', 0)
            ->where('code', $code);
        if ($id > 0) {
            $dupQuery->where('id', '<>', $id);
        }
        if ($dupQuery->count() > 0) {
            throw new BusinessException('队列前缀已被其它分类使用');
        }

        $data = [
            'name'       => $name,
            'code'       => $code,
            'seat_min'   => $seatMin,
            'seat_max'   => $seatMax,
            'per_minute' => $perMinute,
            'call_text'  => $callText,
            'sort'       => $sort,
            'status'     => $status,
        ];

        if ($id > 0) {
            $exists = ScopeService::query('restaurant_queue_category', $aid)->where('id', $id)->find();
            if (empty($exists)) {
                throw new BusinessException('分类不存在');
            }
            $data['update_time'] = time();
            Db::name('restaurant_queue_category')->where('id', $id)->update($data);

            return $this->success(['id' => $id], '保存成功');
        }

        $data['aid']         = $aid;
        $data['bid']         = 0;
        $data['create_time'] = time();
        $data['update_time'] = time();
        $newId = (int) Db::name('restaurant_queue_category')->insertGetId($data);

        return $this->success(['id' => $newId], '创建成功');
    }

    /**
     * 删除分类（同步删除该分类下的排队记录，对标参考系统口径）
     * POST /admin/restaurant/queue/category/del {id}
     */
    public function del()
    {
        $aid = ScopeService::aidOf($this->request);

        $id = (int) $this->request->post('id', 0);
        if ($id <= 0) {
            throw new BusinessException('缺少分类ID');
        }

        $exists = ScopeService::query('restaurant_queue_category', $aid)->where('id', $id)->find();
        if (empty($exists)) {
            throw new BusinessException('分类不存在');
        }

        Db::name('restaurant_queue_category')->where('id', $id)->delete();
        Db::name('restaurant_queue')->where('aid', $aid)->where('cid', $id)->delete();

        return $this->success([], '删除成功');
    }
}
