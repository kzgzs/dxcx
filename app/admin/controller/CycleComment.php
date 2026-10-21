<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\ScopeService;
use think\facade\Db;

/**
 * 租户后台 - 周期购评价管理（P24-7 批次8 任务227）
 *
 * 仿 ActivityComment（批次4-E 任务190）模式，落到 cycle_comment ↔ cycle_product：
 * - 列表 + 顶部统计（总数/待审核/已通过/平均分/好评率）
 * - 审核通过/驳回、商家回复、删除后同事务重算商品聚合
 * - 聚合列：comment_num / comment_score（无评价默认5.0）/ comment_haopercent（无评价默认100）
 */
class CycleComment extends BaseController
{
    public const STATUS_PENDING  = 0;
    public const STATUS_APPROVED = 1;
    public const STATUS_REJECTED = 2;

    /**
     * 评价列表（含顶部统计）
     * GET /admin/cycleComment/list?page=&pagesize=&keyword=&score=&status=&start_time=&end_time=
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        $query = ScopeService::query('cycle_comment', $aid);

        $keyword = trim((string) $this->request->get('keyword', ''));

        if ('' !== $keyword) {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('nickname', '%' . $keyword . '%')
                    ->whereOr('proname', 'like', '%' . $keyword . '%')
                    ->whereOr('content', 'like', '%' . $keyword . '%');
            });
        }

        $score = (int) $this->request->get('score', 0);

        if ($score > 0) {
            $query->where('score', $score);
        }

        $status = (int) $this->request->get('status', -1);

        if ($status >= 0) {
            $query->where('status', $status);
        }

        // 评价内容精确模糊（对齐参考系统「评价内容」搜索框）
        $content = trim((string) $this->request->get('content', ''));
        if ('' !== $content) {
            $query->where('content', 'like', '%' . $content . '%');
        }

        $startTime = (int) $this->request->get('start_time', 0);
        $endTime   = (int) $this->request->get('end_time', 0);

        if ($startTime > 0) {
            $query->where('createtime', '>=', $startTime);
        }

        if ($endTime > 0) {
            $query->where('createtime', '<', $endTime + 86400);
        }

        $total = (clone $query)->count();

        // 排序：评分 score / 评价时间 createtime 可点列头排序，默认 id desc
        $orderField = trim((string) $this->request->get('order_field', 'id'));
        $orderType  = strtolower((string) $this->request->get('order_type', 'desc'));
        if (!in_array($orderField, ['id', 'score', 'createtime'], true)) {
            $orderField = 'id';
        }
        $orderType = 'asc' === $orderType ? 'asc' : 'desc';

        $rows = $query->order($orderField, $orderType)->page($page, $pageSize)->select()->toArray();

        foreach ($rows as &$row) {
            $row['createtime_text'] = (int) $row['createtime'] > 0 ? date('Y-m-d H:i:s', (int) $row['createtime']) : '';
            $row['reply_time_text'] = (int) ($row['reply_time'] ?? 0) > 0 ? date('Y-m-d H:i:s', (int) $row['reply_time']) : '';
        }

        unset($row);

        return $this->success([
            'list'     => $rows,
            'total'    => $total,
            'page'     => $page,
            'pagesize' => $pageSize,
            'stat'     => $this->stat($aid),
        ]);
    }

    /**
     * 审核（通过=1 / 驳回=2 / 取消审核=0，批量 ids 或单 id）
     * POST /admin/cycleComment/check {ids:[], status} 或 {id, status}
     */
    public function check()
    {
        $aid    = ScopeService::aidOf($this->request);
        $status = (int) $this->request->post('status', 1);

        if (!in_array($status, [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED], true)) {
            throw new BusinessException('审核状态不合法');
        }

        $ids = (array) $this->request->post('ids/a', []);
        if (empty($ids)) {
            $ids = [(int) $this->request->post('id', 0)];
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $ids = array_filter($ids, fn ($v) => $v > 0);
        if (empty($ids)) {
            throw new BusinessException('请选择评价');
        }

        $rows = ScopeService::query('cycle_comment', $aid)->whereIn('id', $ids)->select()->toArray();
        if (empty($rows)) {
            throw new BusinessException('评价不存在');
        }

        Db::startTrans();

        try {
            Db::name('cycle_comment')->where('aid', $aid)->whereIn('id', $ids)->update(['status' => $status]);

            // 重算每条商品的评价聚合
            $proIds = array_values(array_unique(array_map(fn ($r) => (int) $r['proid'], $rows)));
            foreach ($proIds as $proId) {
                $this->recalcProduct($aid, $proId);
            }

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();

            throw $e;
        }

        return $this->success([], '操作成功');
    }

    /**
     * 商家回复
     * POST /admin/cycleComment/reply {id, reply_content, reply_content_pic}
     */
    public function reply()
    {
        $aid     = ScopeService::aidOf($this->request);
        $id      = (int) $this->request->post('id', 0);
        $content = trim((string) $this->request->post('reply_content', ''));
        $pic     = trim((string) $this->request->post('reply_content_pic', ''));

        if ('' === $content && '' === $pic) {
            throw new BusinessException('请输入回复内容');
        }

        $comment = $this->findComment($aid, $id);

        Db::name('cycle_comment')->where('id', (int) $comment['id'])->update([
            'reply_content'     => $content,
            'reply_content_pic' => $pic,
            'reply_time'        => time(),
        ]);

        return $this->success([], '回复成功');
    }

    /**
     * 删除（物理删除，批量 ids 或单 id）+ 重算商品聚合
     * POST /admin/cycleComment/delete {ids:[]} 或 {id}
     */
    public function delete()
    {
        $aid = ScopeService::aidOf($this->request);

        $ids = (array) $this->request->post('ids/a', []);
        if (empty($ids)) {
            $ids = [(int) $this->request->post('id', 0)];
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $ids = array_filter($ids, fn ($v) => $v > 0);
        if (empty($ids)) {
            throw new BusinessException('请选择评价');
        }

        $rows = ScopeService::query('cycle_comment', $aid)->whereIn('id', $ids)->select()->toArray();
        if (empty($rows)) {
            throw new BusinessException('评价不存在');
        }

        Db::startTrans();

        try {
            Db::name('cycle_comment')->where('aid', $aid)->whereIn('id', $ids)->delete();

            $proIds = array_values(array_unique(array_map(fn ($r) => (int) $r['proid'], $rows)));
            foreach ($proIds as $proId) {
                $this->recalcProduct($aid, $proId);
            }

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();

            throw $e;
        }

        return $this->success([], '删除成功');
    }

    /**
     * 评价详情（含评价人信息，对齐参考系统 getdetail）
     * POST /admin/cycleComment/detail {id}
     */
    public function detail()
    {
        $aid = ScopeService::aidOf($this->request);
        $id  = (int) $this->request->post('id', 0);

        $comment = $this->findComment($aid, $id);

        $member = ScopeService::query('member', $aid)
            ->where('id', (int) $comment['mid'])
            ->field('id,nickname,headimg,realname,tel')
            ->find();

        if (empty($member)) {
            $member = ['nickname' => $comment['nickname'] ?? '', 'headimg' => $comment['headimg'] ?? ''];
        }

        $comment['content_pic_arr'] = !empty($comment['content_pic']) ? explode(',', (string) $comment['content_pic']) : [];
        $comment['createtime_text'] = (int) $comment['createtime'] > 0 ? date('Y-m-d H:i', (int) $comment['createtime']) : '';
        $comment['reply_time_text'] = (int) ($comment['reply_time'] ?? 0) > 0 ? date('Y-m-d H:i', (int) $comment['reply_time']) : '';

        return $this->success(['detail' => $comment, 'member' => $member]);
    }

    // ================= 内部工具 =================

    /**
     * 顶部统计（总数/待审核/已通过/平均分/好评率）
     *
     * @return array<string, mixed>
     */
    protected function stat(int $aid): array
    {
        $total = ScopeService::query('cycle_comment', $aid)->count();

        $approved = ScopeService::query('cycle_comment', $aid)->where('status', self::STATUS_APPROVED);
        $num      = (clone $approved)->count();
        $avg      = (clone $approved)->avg('score');
        $goodNum  = (clone $approved)->where('score', '>', 3)->count();

        return [
            'total'      => $total,
            'approved'   => $num,
            'pending'    => ScopeService::query('cycle_comment', $aid)->where('status', self::STATUS_PENDING)->count(),
            'avg_score'  => $num > 0 ? round((float) $avg, 1) : 5.0,
            'haopercent' => $num > 0 ? (int) round($goodNum / $num * 100) : 100,
        ];
    }

    /**
     * 重算周期购商品评价聚合（无评价回归默认 5.0 / 100）
     */
    protected function recalcProduct(int $aid, int $proid): void
    {
        if ($proid <= 0) {
            return;
        }

        $product = ScopeService::query('cycle_product', $aid)->where('id', $proid)->find();

        if (empty($product)) {
            return;
        }

        $approved = ScopeService::query('cycle_comment', $aid)->where('proid', $proid)->where('status', self::STATUS_APPROVED);
        $num      = (clone $approved)->count();
        $avg      = (clone $approved)->avg('score');
        $goodNum  = (clone $approved)->where('score', '>', 3)->count();

        Db::name('cycle_product')->where('id', $proid)->update([
            'comment_num'        => $num,
            'comment_score'      => $num > 0 ? round((float) $avg, 1) : 5.0,
            'comment_haopercent' => $num > 0 ? (int) round($goodNum / $num * 100) : 100,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function findComment(int $aid, int $id): array
    {
        $comment = ScopeService::query('cycle_comment', $aid)->where('id', $id)->find();

        if (empty($comment)) {
            throw new BusinessException('评价不存在');
        }

        return (array) $comment;
    }
}
