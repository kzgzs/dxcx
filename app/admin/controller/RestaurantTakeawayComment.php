<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\PlogService;
use app\common\service\ScopeService;
use think\facade\Db;

/**
 * 租户后台 - 外卖评价管理（对照参考系统 RestaurantTakeawayComment）
 *
 * 审核改状态后重算 restaurant_product 的评分/评价数/好评率
 */
class RestaurantTakeawayComment extends BaseController
{
    protected string $table = 'restaurant_takeaway_comment';

    /**
     * 评价列表
     * GET /admin/restaurant/takeawayComment/list
     */
    public function list()
    {
        $aid   = ScopeService::aidOf($this->request);
        $page  = max(1, (int) $this->request->get('page', 1));
        $limit = min(100, max(1, (int) $this->request->get('limit', 10)));

        $where = function ($q) use ($aid) {
            $q->where('aid', $aid)->where('bid', 0);
            $content = trim((string) $this->request->get('content', ''));
            if ('' !== $content) {
                $q->where('content', 'like', '%' . $content . '%');
            }
            $status = $this->request->get('status', '');
            if ('' !== $status && null !== $status) {
                $q->where('status', (int) $status);
            }
            $stime = trim((string) $this->request->get('stime', ''));
            $etime = trim((string) $this->request->get('etime', ''));
            if ('' !== $stime) {
                $q->where('createtime', '>=', strtotime($stime));
            }
            if ('' !== $etime) {
                $q->where('createtime', '<', strtotime($etime) + 86400);
            }
            return $q;
        };

        $count = (int) $where(ScopeService::query($this->table, $aid))->count();
        $rows  = $where(ScopeService::query($this->table, $aid))
            ->page($page, $limit)
            ->order('id', 'desc')
            ->select()
            ->toArray();

        foreach ($rows as &$row) {
            $row['id']          = (int) $row['id'];
            $row['mid']         = (int) $row['mid'];
            $row['score']       = (int) $row['score'];
            $row['status']      = (int) $row['status'];
            $row['createtime']  = (int) $row['createtime'];
            $row['reply_time']  = (int) $row['reply_time'];
            $row['content_pic'] = '' !== $row['content_pic'] ? explode(',', (string) $row['content_pic']) : [];
        }
        unset($row);

        return $this->success(['count' => $count, 'list' => $rows]);
    }

    /**
     * 评价审核（批量）
     * POST /admin/restaurant/takeawayComment/setst  { ids, st }
     */
    public function setst()
    {
        $aid = ScopeService::aidOf($this->request);
        $uid = (int) ($this->request->authUid ?? 0);

        $ids = $this->request->post('ids/a', []);
        $st  = (int) $this->request->post('st', 1);
        if (empty($ids)) {
            throw new BusinessException('参数错误');
        }

        $list = ScopeService::query($this->table, $aid)
            ->where('bid', 0)->whereIn('id', array_map('intval', $ids))
            ->select()->toArray();

        foreach ($list as $v) {
            Db::name($this->table)->where('id', $v['id'])->update(['status' => $st]);
            $this->recalcProduct((int) $aid, (int) $v['proid']);
        }

        PlogService::write($aid, $uid, '餐饮外卖评价审核' . implode(',', array_map('intval', $ids)), 0, (string) $this->request->ip());

        return $this->success([], '操作成功');
    }

    /**
     * 评价详情
     * GET /admin/restaurant/takeawayComment/detail?id=
     */
    public function detail()
    {
        $aid = ScopeService::aidOf($this->request);
        $id  = (int) $this->request->get('id', 0);

        $detail = ScopeService::query($this->table, $aid)->where('bid', 0)->where('id', $id)->find();
        if (empty($detail)) {
            throw new BusinessException('评价不存在');
        }
        $detail['content_pic'] = '' !== $detail['content_pic'] ? explode(',', (string) $detail['content_pic']) : [];

        $member = ScopeService::query('member', $aid)->where('id', $detail['mid'])->find();
        if (empty($member)) {
            $member = ['nickname' => $detail['nickname'], 'headimg' => $detail['headimg']];
        } else {
            $member = ['nickname' => $member['nickname'], 'headimg' => $member['headimg']];
        }

        return $this->success(['detail' => $detail, 'member' => $member]);
    }

    /**
     * 评价回复
     * POST /admin/restaurant/takeawayComment/reply  { id, content }
     */
    public function reply()
    {
        $aid = ScopeService::aidOf($this->request);
        $uid = (int) ($this->request->authUid ?? 0);

        $id      = (int) $this->request->post('id', 0);
        $content = trim((string) $this->request->post('content', ''));
        if ($id <= 0 || '' === $content) {
            throw new BusinessException('参数错误');
        }

        $row = ScopeService::query($this->table, $aid)->where('bid', 0)->where('id', $id)->find();
        if (empty($row)) {
            throw new BusinessException('评价不存在');
        }

        Db::name($this->table)->where('id', $id)->update(['reply_content' => $content, 'reply_time' => time()]);

        PlogService::write($aid, $uid, '餐饮外卖评价回复' . $id, 0, (string) $this->request->ip());

        return $this->success([], '回复成功');
    }

    /**
     * 删除评价（批量）
     * POST /admin/restaurant/takeawayComment/del  { ids }
     */
    public function del()
    {
        $aid = ScopeService::aidOf($this->request);
        $uid = (int) ($this->request->authUid ?? 0);

        $ids = $this->request->post('ids/a', []);
        if (empty($ids)) {
            throw new BusinessException('参数错误');
        }
        $ids = array_map('intval', $ids);

        $list = ScopeService::query($this->table, $aid)->where('bid', 0)->whereIn('id', $ids)->select()->toArray();
        if (empty($list)) {
            throw new BusinessException('评价不存在');
        }

        Db::name($this->table)->whereIn('id', $ids)->delete();

        foreach ($list as $v) {
            $this->recalcProduct((int) $aid, (int) $v['proid']);
        }

        PlogService::write($aid, $uid, '餐饮外卖评价删除' . implode(',', $ids), 0, (string) $this->request->ip());

        return $this->success([], '删除成功');
    }

    /** 重算菜品评分统计（口径同参考系统） */
    protected function recalcProduct(int $aid, int $proid): void
    {
        if ($proid <= 0) {
            return;
        }
        $base = Db::name($this->table)->where('aid', $aid)->where('bid', 0)->where('proid', $proid)->where('status', 1);
        $commentNum = (clone $base)->count();
        $avgScore   = (float) (clone $base)->avg('score');
        if (0 === $commentNum) {
            $avgScore = 5.0;
        }
        $haoNum     = (int) (clone $base)->where('score', '>', 3)->count();
        $haoPercent = $commentNum > 0 ? round($haoNum / $commentNum * 100, 2) : 100;

        Db::name('restaurant_product')->where('aid', $aid)->where('id', $proid)->update([
            'comment_score'     => round($avgScore, 2),
            'comment_num'       => $commentNum,
            'comment_haopercent' => $haoPercent,
        ]);
    }
}
