<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\ScopeService;
use think\facade\Db;

/**
 * 租户后台 - 餐饮排队队列（restaurant_queue）
 *
 * 状态机：0排队中 → 1已入座（叫号 jiaohao 置 call_time）/ 2过号（guohao 置 expiry_time）/ 3用户取消
 * 语音降级：不生成 mp3，大屏与 B 端均走浏览器 TTS 播报 call_text（批次6-T6-2 决策）
 */
class RestaurantQueue extends BaseController
{
    /**
     * 队列列表（分页 + 状态/分类/日期/关键字筛选，附分类名与会员昵称）
     * GET /admin/restaurant/queue/list
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;
        $keyword  = trim((string) $this->request->get('keyword', ''));
        $status   = trim((string) $this->request->get('status', ''));
        $cid      = (int) $this->request->get('cid', 0);
        $date     = trim((string) $this->request->get('date', ''));

        $query = ScopeService::query('restaurant_queue', $aid)->where('bid', 0);
        if ('' !== $status) {
            $query->where('status', (int) $status);
        }
        if ($cid > 0) {
            $query->where('cid', $cid);
        }
        if ('' !== $date) {
            $query->where('date', $date);
        }
        if ('' !== $keyword) {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('queue_no', '%' . $keyword . '%')
                    ->whereOr('linkman', 'like', '%' . $keyword . '%')
                    ->whereOr('tel', 'like', '%' . $keyword . '%');
            });
        }

        $total = (clone $query)->count();
        $rows  = $query->order('id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        // 分类名映射
        $cidList = array_values(array_unique(array_map(static fn ($r) => (int) $r['cid'], $rows)));
        $catMap  = [];
        if ($cidList) {
            $cats = ScopeService::query('restaurant_queue_category', $aid)
                ->whereIn('id', $cidList)
                ->column('name', 'id');
            foreach ($cats as $catId => $catName) {
                $catMap[(int) $catId] = (string) $catName;
            }
        }

        // 会员昵称映射
        $midList = array_values(array_unique(array_filter(array_map(static fn ($r) => (int) $r['mid'], $rows))));
        $memberMap = [];
        if ($midList) {
            $memberMap = Db::name('member')->whereIn('id', $midList)->column('nickname', 'id');
        }

        foreach ($rows as &$row) {
            $row['id']          = (int) $row['id'];
            $row['cid']         = (int) $row['cid'];
            $row['mid']         = (int) $row['mid'];
            $row['status']      = (int) $row['status'];
            $row['create_time'] = (int) $row['create_time'];
            $row['call_time']   = (int) ($row['call_time'] ?? 0);
            $row['expiry_time'] = (int) ($row['expiry_time'] ?? 0);
            $row['cname']       = $catMap[(int) $row['cid']] ?? '';
            $row['nickname']    = $row['mid'] > 0 ? (string) ($memberMap[$row['mid']] ?? '') : '';
        }
        unset($row);

        return $this->success(['list' => $rows, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize]);
    }

    /**
     * 叫号（二选一）：cid=分类最早一桌（status=0 按取号顺序）；id=指定单重复叫
     * POST /admin/restaurant/queue/jiaohao {cid?|id?}
     */
    public function jiaohao()
    {
        $aid = ScopeService::aidOf($this->request);

        $cid = (int) $this->request->post('cid', 0);
        $id  = (int) $this->request->post('id', 0);

        $queue = null;
        if ($id > 0) {
            // 重复叫指定号（允许对已入座/过号单再次播报）
            $queue = ScopeService::query('restaurant_queue', $aid)->where('id', $id)->find();
        } elseif ($cid > 0) {
            // 叫该分类当前排队中的最早一桌
            $queue = ScopeService::query('restaurant_queue', $aid)
                ->where('bid', 0)
                ->where('cid', $cid)
                ->where('status', 0)
                ->order('create_time', 'asc')
                ->order('id', 'asc')
                ->find();
        }

        if (empty($queue)) {
            throw new BusinessException('当前没有可叫号的排队');
        }

        Db::name('restaurant_queue')->where('id', (int) $queue['id'])->update([
            'status'    => 1,
            'call_time' => time(),
            'need_play' => 1,
        ]);

        $queue['status']    = 1;
        $queue['call_time'] = time();

        return $this->success(['queue' => $queue], '叫号成功：' . $queue['queue_no']);
    }

    /**
     * 过号（仅排队中可过号）
     * POST /admin/restaurant/queue/guohao {id}
     */
    public function guohao()
    {
        $aid = ScopeService::aidOf($this->request);

        $id = (int) $this->request->post('id', 0);
        if ($id <= 0) {
            throw new BusinessException('缺少排队ID');
        }

        $queue = ScopeService::query('restaurant_queue', $aid)->where('id', $id)->find();
        if (empty($queue)) {
            throw new BusinessException('排队记录不存在');
        }
        if ((int) $queue['status'] !== 0) {
            throw new BusinessException('该排队已不在排队中，无法过号');
        }

        Db::name('restaurant_queue')->where('id', $id)->update([
            'status'      => 2,
            'expiry_time' => time(),
        ]);

        return $this->success([], '已过号');
    }

    /**
     * 后台取消（仅排队中）
     * POST /admin/restaurant/queue/cancel {id}
     */
    public function cancel()
    {
        $aid = ScopeService::aidOf($this->request);

        $id = (int) $this->request->post('id', 0);
        if ($id <= 0) {
            throw new BusinessException('缺少排队ID');
        }

        $queue = ScopeService::query('restaurant_queue', $aid)->where('id', $id)->find();
        if (empty($queue)) {
            throw new BusinessException('排队记录不存在');
        }
        if ((int) $queue['status'] !== 0) {
            throw new BusinessException('该排队已不在排队中，无法取消');
        }

        Db::name('restaurant_queue')->where('id', $id)->update([
            'status'      => 3,
            'expiry_time' => time(),
        ]);

        return $this->success([], '已取消');
    }

    /**
     * 删除排队记录
     * POST /admin/restaurant/queue/del {id}
     */
    public function del()
    {
        $aid = ScopeService::aidOf($this->request);

        $id = (int) $this->request->post('id', 0);
        if ($id <= 0) {
            throw new BusinessException('缺少排队ID');
        }

        $queue = ScopeService::query('restaurant_queue', $aid)->where('id', $id)->find();
        if (empty($queue)) {
            throw new BusinessException('排队记录不存在');
        }

        Db::name('restaurant_queue')->where('id', $id)->delete();

        return $this->success([], '删除成功');
    }
}
