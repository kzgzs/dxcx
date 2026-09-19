<?php

declare(strict_types=1);

namespace app\api\controller;

use app\common\exception\BusinessException;
use app\common\service\ScopeService;
use think\facade\Db;

/**
 * 客户端 - 餐饮排队叫号（api 应用；批次6-T6-2）
 *
 * 会员接口（JWT role=member）：index 排队首页 / quhao 取号 / cancel 取消 / record 我的记录
 * 匿名接口（AuthMiddleware 白名单，aid 走 query）：getqueuelist 大屏轮询
 *
 * 状态机：0排队中 → 1已入座（叫号）/ 2过号 / 3用户取消
 * 取号规则：分类 code 前缀 + 当日分类内自增两位序号（A01），同会员同日仅一单
 * 语音降级：不接腾讯云语音，大屏浏览器 TTS 播报 call_text
 */
class RestaurantQueue extends Base
{
    /**
     * 排队首页：分类列表（前面人数+预计等待）+ 最近叫号 + 我的排队
     * GET /api/restaurantQueue/index
     */
    public function index()
    {
        [$aid, $mid] = $this->memberContext();

        $this->checkOpen($aid);

        $clist = $this->categoryListWithWait($aid);

        // 最近 300 秒内的叫号播报文字
        $lastqueue = ScopeService::query('restaurant_queue', $aid)
            ->where('bid', 0)
            ->where('date', date('Y-m-d'))
            ->where('status', 1)
            ->where('call_time', '>', time() - 300)
            ->order('call_time', 'desc')
            ->find();
        $notice = $lastqueue ? (string) $lastqueue['call_text'] : '当前没有叫号的顾客';

        // 我的排队（当日排队中）
        $myqueue = ScopeService::query('restaurant_queue', $aid)
            ->where('bid', 0)
            ->where('mid', $mid)
            ->where('date', date('Y-m-d'))
            ->where('status', 0)
            ->order('id', 'desc')
            ->find();
        $myjustqueue = null;
        if ($myqueue) {
            $category = ScopeService::query('restaurant_queue_category', $aid)->where('id', (int) $myqueue['cid'])->find();
            $myqueue['beforenum'] = ScopeService::query('restaurant_queue', $aid)
                ->where('bid', 0)
                ->where('id', '<', (int) $myqueue['id'])
                ->where('date', date('Y-m-d'))
                ->where('status', 0)
                ->count();
            $perMinute            = $category ? (int) $category['per_minute'] : 1;
            $myqueue['need_minute'] = ((int) $myqueue['beforenum'] + 1) * $perMinute;
            $myqueue['cname']       = $category ? (string) $category['name'] : '';
        } else {
            // 刚被叫到的号（300 秒内）
            $myjustqueue = ScopeService::query('restaurant_queue', $aid)
                ->where('bid', 0)
                ->where('mid', $mid)
                ->where('status', 1)
                ->where('call_time', '>', time() - 300)
                ->order('call_time', 'desc')
                ->find();
            if ($myjustqueue) {
                $myjustqueue['cname'] = (string) ScopeService::query('restaurant_queue_category', $aid)
                    ->where('id', (int) $myjustqueue['cid'])
                    ->value('name');
            }
        }

        return $this->success([
            'clist'       => $clist,
            'notice'      => $notice,
            'myqueue'     => $myqueue,
            'myjustqueue' => $myjustqueue,
        ]);
    }

    /**
     * 取号排队（同会员同日仅一单；时段校验；序号=前缀+当日分类内自增两位）
     * POST /api/restaurantQueue/quhao {cid, linkman, tel, renshu}
     */
    public function quhao()
    {
        [$aid, $mid] = $this->memberContext();

        $this->checkOpen($aid);

        // 同人同日重复拦截（排队中即视为已取号）
        $myqueue = ScopeService::query('restaurant_queue', $aid)
            ->where('bid', 0)
            ->where('mid', $mid)
            ->where('date', date('Y-m-d'))
            ->where('status', 0)
            ->find();
        if ($myqueue) {
            throw new BusinessException('您已取号，请勿重复排队');
        }

        $cid     = (int) $this->request->post('cid', 0);
        $linkman = trim((string) $this->request->post('linkman', ''));
        $tel     = trim((string) $this->request->post('tel', ''));
        $renshu  = trim((string) $this->request->post('renshu', ''));

        if ($cid <= 0) {
            throw new BusinessException('请选择队列分类');
        }
        if ('' === $linkman) {
            throw new BusinessException('请填写联系人');
        }
        if ('' !== $tel && !preg_match('/^1[3-9]\d{9}$/', $tel)) {
            throw new BusinessException('手机号格式不正确');
        }

        $category = ScopeService::query('restaurant_queue_category', $aid)
            ->where('bid', 0)
            ->where('id', $cid)
            ->find();
        if (empty($category) || (int) $category['status'] !== 1) {
            throw new BusinessException('该队列分类不可取号');
        }

        // 人数段校验（seat_min/seat_max 均配置时生效）
        if ('' !== $renshu && (int) $category['seat_min'] > 0 && (int) $category['seat_max'] > 0) {
            $renshuInt = (int) $renshu;
            if ($renshuInt < (int) $category['seat_min'] || $renshuInt > (int) $category['seat_max']) {
                throw new BusinessException('该队列适用 ' . $category['seat_min'] . '-' . $category['seat_max'] . ' 人，请重新选择分类');
            }
        }

        // 取号：前缀 + 当日分类内自增两位序号（A01）
        $hasnum  = ScopeService::query('restaurant_queue', $aid)
            ->where('bid', 0)
            ->where('cid', $cid)
            ->where('date', date('Y-m-d'))
            ->count();
        $seq     = $hasnum + 1;
        $queueNo = (string) $category['code'] . str_pad((string) $seq, 2, '0', STR_PAD_LEFT);

        $callText = str_replace('[排队号]', $queueNo, (string) ($category['call_text'] ?: '请[排队号]号顾客前往就餐'));

        $newId = (int) Db::name('restaurant_queue')->insertGetId([
            'aid'         => $aid,
            'bid'         => 0,
            'cid'         => $cid,
            'mid'         => $mid,
            'date'        => date('Y-m-d'),
            'queue_no'    => $queueNo,
            'call_text'   => $callText,
            'create_time' => time(),
            'linkman'     => $linkman,
            'tel'         => $tel,
            'renshu'      => $renshu,
            'status'      => 0,
            'platform'    => 'wx',
        ]);

        return $this->success(['id' => $newId, 'queue_no' => $queueNo], '取号成功，排队号：' . $queueNo);
    }

    /**
     * 取消排队（仅本人排队中）
     * POST /api/restaurantQueue/cancel {id}
     */
    public function cancel()
    {
        [$aid, $mid] = $this->memberContext();

        $id = (int) $this->request->post('id', 0);
        if ($id <= 0) {
            throw new BusinessException('缺少排队ID');
        }

        $myqueue = ScopeService::query('restaurant_queue', $aid)
            ->where('mid', $mid)
            ->where('id', $id)
            ->find();
        if (empty($myqueue)) {
            throw new BusinessException('未找到该排队信息');
        }
        if ((int) $myqueue['status'] !== 0) {
            throw new BusinessException('取消失败，该排队已不在队列中');
        }

        Db::name('restaurant_queue')->where('id', $id)->update([
            'status'      => 3,
            'expiry_time' => time(),
        ]);

        return $this->success([], '取消成功');
    }

    /**
     * 我的排队记录
     * GET /api/restaurantQueue/record?page=&pagesize=
     */
    public function record()
    {
        [$aid, $mid] = $this->memberContext();

        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 20);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 20;

        $query = ScopeService::query('restaurant_queue', $aid)
            ->where('bid', 0)
            ->where('mid', $mid);

        $total = (clone $query)->count();
        $rows  = $query->order('id', 'desc')->page($page, $pageSize)->select()->toArray();

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

        foreach ($rows as &$row) {
            $row['id']     = (int) $row['id'];
            $row['status'] = (int) $row['status'];
            $row['cname']  = $catMap[(int) $row['cid']] ?? '';
        }
        unset($row);

        return $this->success(['list' => $rows, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize]);
    }

    /**
     * 大屏轮询：当前叫号（300 秒内）+ 等待列表（补齐 16 位）+ 大屏配置（匿名，白名单）
     * GET /api/restaurantQueue/getqueuelist?aid=
     */
    public function getqueuelist()
    {
        $aid = (int) ($this->request->aid ?? 0);
        if ($aid <= 0) {
            throw new BusinessException('非法的租户标识', 403, 403);
        }

        $set = ScopeService::query('restaurant_queue_sysset', $aid)->where('bid', 0)->find();

        $lastqueue = ScopeService::query('restaurant_queue', $aid)
            ->where('bid', 0)
            ->where('date', date('Y-m-d'))
            ->where('status', 1)
            ->where('call_time', '>', time() - 300)
            ->order('call_time', 'desc')
            ->find();
        if (empty($lastqueue)) {
            $lastqueue = ['id' => 0, 'queue_no' => '--', 'call_text' => '当前叫号'];
        } else {
            $lastqueue['id'] = (int) $lastqueue['id'];
        }

        $waitList = ScopeService::query('restaurant_queue', $aid)
            ->where('bid', 0)
            ->where('date', date('Y-m-d'))
            ->where('status', 0)
            ->order('create_time', 'asc')
            ->order('id', 'asc')
            ->field('id,queue_no,cid,renshu,create_time')
            ->select()
            ->toArray();

        // 等待列表补齐到 16 位（大屏栅格占位）
        for ($i = count($waitList); $i < 16; $i++) {
            $waitList[] = ['id' => 0, 'queue_no' => '', 'cid' => 0, 'renshu' => '', 'create_time' => 0];
        }

        return $this->success([
            'set'       => [
                'screen_title' => (string) ($set['screen_title'] ?? ''),
                'screen_pic'   => (string) ($set['screen_pic'] ?? ''),
                'screen_desc'  => (string) ($set['screen_desc'] ?? ''),
                'status'       => (int) ($set['status'] ?? 1),
            ],
            'lastqueue' => $lastqueue,
            'waitlist'  => $waitList,
        ]);
    }

    /**
     * 排队开关 + 可取号时段校验（start_hours=end_hours 表示全天）
     */
    protected function checkOpen(int $aid): void
    {
        $set = ScopeService::query('restaurant_queue_sysset', $aid)->where('bid', 0)->find();
        if (empty($set) || (int) $set['status'] !== 1) {
            throw new BusinessException('该商家未开启排队');
        }

        $startHours = (string) ($set['start_hours'] ?: '00:00');
        $endHours   = (string) ($set['end_hours'] ?: '00:00');
        if ($startHours === $endHours) {
            return; // 全天可取号
        }

        $start = strtotime(date('Y-m-d ' . $startHours));
        $end   = strtotime(date('Y-m-d ' . $endHours));
        $now   = time();

        $outside = $start < $end
            ? ($now < $start || $now > $end)
            : ($now < $start && $now > $end); // 跨天时段
        if ($outside) {
            throw new BusinessException('不在排队时间内（' . $startHours . ' - ' . $endHours . '）');
        }
    }

    /**
     * 启用中的分类列表（附前面人数与预计等待分钟）
     *
     * @return array<int, array<string, mixed>>
     */
    protected function categoryListWithWait(int $aid): array
    {
        $clist = ScopeService::query('restaurant_queue_category', $aid)
            ->where('bid', 0)
            ->where('status', 1)
            ->order('sort', 'desc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        foreach ($clist as $k => $v) {
            $waitnum = ScopeService::query('restaurant_queue', $aid)
                ->where('bid', 0)
                ->where('cid', (int) $v['id'])
                ->where('date', date('Y-m-d'))
                ->where('status', 0)
                ->count();
            $clist[$k]['waitnum']     = $waitnum;
            $clist[$k]['need_minute'] = $waitnum > 0 ? $waitnum * (int) $v['per_minute'] : 0;
        }

        return $clist;
    }
}
