<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;

/**
 * 拼团/秒杀活动评价服务（批次4-E 任务190 E2-4）
 *
 * 口径对照参考系统 CollageComment / SeckillComment 控制器，仿写 CommentService：
 * - 双表参数化：collage_comment ↔ collage_product / seckill_comment ↔ seckill_product
 * - 审核（setst）/ 回复（reply）/ 删除（del 物理删除）后同事务重算商品聚合
 * - 聚合列：comment_num=已通过评价数 / comment_score=平均分（无评价默认5.0）
 *   / comment_haopercent=好评（score>3）占比（无评价默认100）
 */
class ActivityCommentService
{
    public const STATUS_PENDING  = 0;
    public const STATUS_APPROVED = 1;
    public const STATUS_REJECTED = 2;

    /** 类型 → 表映射 */
    private const TABLES = [
        'collage' => ['comment' => 'collage_comment', 'product' => 'collage_product'],
        'seckill' => ['comment' => 'seckill_comment', 'product' => 'seckill_product'],
    ];

    /**
     * 评价分页列表 + 顶部统计
     *
     * @param array<string, mixed> $filters {keyword, score, status, start_time, end_time}
     * @return array{list: array<int, array<string, mixed>>, total: int, page: int, pagesize: int, stat: array<string, mixed>}
     */
    public function list(int $aid, string $type, int $page, int $pagesize, array $filters): array
    {
        $table = $this->tableOf($type, 'comment');

        $query = ScopeService::query($table, $aid);

        $keyword = trim((string) ($filters['keyword'] ?? ''));

        if ('' !== $keyword) {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('nickname', '%' . $keyword . '%')
                    ->whereOr('proname', 'like', '%' . $keyword . '%')
                    ->whereOr('content', 'like', '%' . $keyword . '%');
            });
        }

        $score = (int) ($filters['score'] ?? 0);

        if ($score > 0) {
            $query->where('score', $score);
        }

        $status = (int) ($filters['status'] ?? -1);

        if ($status >= 0) {
            $query->where('status', $status);
        }

        $startTime = (int) ($filters['start_time'] ?? 0);
        $endTime   = (int) ($filters['end_time'] ?? 0);

        if ($startTime > 0) {
            $query->where('createtime', '>=', $startTime);
        }

        if ($endTime > 0) {
            $query->where('createtime', '<', $endTime + 86400);
        }

        $total = (clone $query)->count();

        $rows = $query->order('id', 'desc')->page($page, $pagesize)->select()->toArray();

        foreach ($rows as &$row) {
            $row['createtime_text'] = (int) $row['createtime'] > 0 ? date('Y-m-d H:i:s', (int) $row['createtime']) : '';
            $row['reply_time_text'] = (int) ($row['reply_time'] ?? 0) > 0 ? date('Y-m-d H:i:s', (int) $row['reply_time']) : '';
        }

        unset($row);

        return [
            'list'     => $rows,
            'total'    => $total,
            'page'     => $page,
            'pagesize' => $pagesize,
            'stat'     => $this->stat($aid, $type),
        ];
    }

    /**
     * 顶部统计（总数/已通过/平均分/好评率）
     *
     * @return array<string, mixed>
     */
    public function stat(int $aid, string $type): array
    {
        $table = $this->tableOf($type, 'comment');

        $total = ScopeService::query($table, $aid)->count();

        $approved = ScopeService::query($table, $aid)->where('status', self::STATUS_APPROVED);
        $num      = (clone $approved)->count();
        $avg      = (clone $approved)->avg('score');
        $goodNum  = (clone $approved)->where('score', '>', 3)->count();

        return [
            'total'       => $total,
            'approved'    => $num,
            'pending'     => ScopeService::query($table, $aid)->where('status', self::STATUS_PENDING)->count(),
            'avg_score'   => $num > 0 ? round((float) $avg, 1) : 5.0,
            'haopercent'  => $num > 0 ? (int) round($goodNum / $num * 100) : 100,
        ];
    }

    /**
     * 审核（通过/驳回）+ 重算商品聚合
     */
    public function check(int $aid, string $type, int $id, int $status): void
    {
        if (!in_array($status, [self::STATUS_APPROVED, self::STATUS_REJECTED], true)) {
            throw new BusinessException('审核状态不合法');
        }

        $table   = $this->tableOf($type, 'comment');
        $comment = $this->findComment($aid, $type, $id);

        Db::startTrans();

        try {
            Db::name($table)->where('id', (int) $comment['id'])->update(['status' => $status]);

            $this->recalcProduct($aid, $type, (int) $comment['proid']);

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();

            throw $e;
        }
    }

    /**
     * 商家回复
     *
     * @param array<string, mixed> $data {id, reply_content, reply_content_pic}
     */
    public function reply(int $aid, string $type, array $data): void
    {
        $table   = $this->tableOf($type, 'comment');
        $id      = (int) ($data['id'] ?? 0);
        $content = trim((string) ($data['reply_content'] ?? ''));
        $pic     = trim((string) ($data['reply_content_pic'] ?? ''));

        if ('' === $content && '' === $pic) {
            throw new BusinessException('请输入回复内容');
        }

        $comment = $this->findComment($aid, $type, $id);

        Db::name($table)->where('id', (int) $comment['id'])->update([
            'reply_content'     => $content,
            'reply_content_pic' => $pic,
            'reply_time'        => time(),
        ]);
    }

    /**
     * 删除（物理删除）+ 重算商品聚合
     */
    public function delete(int $aid, string $type, int $id): void
    {
        $table   = $this->tableOf($type, 'comment');
        $comment = $this->findComment($aid, $type, $id);

        Db::startTrans();

        try {
            Db::name($table)->where('id', (int) $comment['id'])->delete();

            $this->recalcProduct($aid, $type, (int) $comment['proid']);

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();

            throw $e;
        }
    }

    /**
     * 重算商品评价聚合（无评价回归默认 5.0 / 100；须由调用方置于事务中）
     */
    public function recalcProduct(int $aid, string $type, int $proid): void
    {
        if ($proid <= 0) {
            return;
        }

        $table      = $this->tableOf($type, 'comment');
        $productTbl = $this->tableOf($type, 'product');

        $product = ScopeService::query($productTbl, $aid)->where('id', $proid)->find();

        if (empty($product)) {
            return;
        }

        $approved = ScopeService::query($table, $aid)->where('proid', $proid)->where('status', self::STATUS_APPROVED);
        $num      = (clone $approved)->count();
        $avg      = (clone $approved)->avg('score');
        $goodNum  = (clone $approved)->where('score', '>', 3)->count();

        Db::name($productTbl)->where('id', $proid)->update([
            'comment_num'        => $num,
            'comment_score'      => $num > 0 ? round((float) $avg, 1) : 5.0,
            'comment_haopercent' => $num > 0 ? (int) round($goodNum / $num * 100) : 100,
        ]);
    }

    // ================= 内部工具 =================

    /**
     * @return array<string, mixed>
     */
    protected function findComment(int $aid, string $type, int $id): array
    {
        $table = $this->tableOf($type, 'comment');

        $comment = ScopeService::query($table, $aid)->where('id', $id)->find();

        if (empty($comment)) {
            throw new BusinessException('评价不存在');
        }

        return (array) $comment;
    }

    protected function tableOf(string $type, string $which): string
    {
        if (!isset(self::TABLES[$type])) {
            throw new BusinessException('评价类型无效');
        }

        return self::TABLES[$type][$which];
    }
}
