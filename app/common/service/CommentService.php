<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;

/**
 * 商品评价模块服务（租户后台评价管理）
 *
 * 表复用旧系统基线：
 * - shop_comment ：商品评价（status 0待审核 1已通过 2已驳回；无 deleted 列，删除即物理删除）
 * - shop_product ：商品聚合列 comment_num/comment_score/comment_haopercent
 * - shop_sysset  ：comment 评价总开关 / comment_check 审核开关
 *
 * 约定：
 * - 所有查询强制经 ScopeService 携带 aid
 * - 审核/删除/虚拟评价落库后同事务重算商品聚合（recalcProduct）
 * - 好评口径：score > 3 计为好评（与旧系统一致）
 */
class CommentService
{
    /** 评价状态 */
    public const STATUS_PENDING  = 0;
    public const STATUS_APPROVED = 1;
    public const STATUS_REJECTED = 2;

    // ================= 列表 / 统计 =================

    /**
     * 评价分页列表 + 顶部统计
     *
     * @param array $filters {keyword, score, status, start_time, end_time, bid}
     * @return array{list: array, total: int, page: int, pagesize: int, stat: array}
     */
    public function list(int $aid, int $page, int $pagesize, array $filters): array
    {
        $query = ScopeService::query('shop_comment', $aid);

        // 商户维度过滤（任务177 showtype-2：''/all 不过滤，0 自营，gt0 商户）
        ScopeService::applyBid($query, (string) ($filters['bid'] ?? ''), 'bid');

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ('' !== $keyword) {
            $query->whereLike('content', '%' . $keyword . '%');
        }

        $score = (string) ($filters['score'] ?? '');
        if ('' !== $score) {
            $query->where('score', (int) $score);
        }

        $status = (string) ($filters['status'] ?? '');
        if ('' !== $status) {
            $query->where('status', (int) $status);
        }

        $start = (int) ($filters['start_time'] ?? 0);
        $end   = (int) ($filters['end_time'] ?? 0);
        if ($start > 0) {
            $query->where('createtime', '>=', $start);
        }
        if ($end > 0) {
            $query->where('createtime', '<', $end + 86400);
        }

        $total = (clone $query)->count();

        // 鎺掑簭鐧藉悕鍗曪紙ID/璇勫垎/璇勪环鏃堕棿鍙帓搴忥級锛岄粯璁?ID 鍊掑簭
        $sortField = (string) ($filters['sort_field'] ?? '');
        $sortOrder = strtolower((string) ($filters['sort_order'] ?? '')) === 'asc' ? 'asc' : 'desc';
        $sortMap   = ['id' => 'id', 'score' => 'score', 'createtime' => 'createtime'];
        $orderField = $sortMap[$sortField] ?? 'id';

        $rows = $query->order($orderField, $sortOrder)
            ->page($page, $pagesize)
            ->select()
            ->toArray();

        $list = array_map(fn (array $row): array => $this->formatRow($row), $rows);

        return [
            'list'     => $list,
            'total'    => $total,
            'page'     => $page,
            'pagesize' => $pagesize,
            'stat'     => $this->stat($aid),
        ];
    }

    /**
     * 顶部统计：总数（全部状态）/ 平均分（已通过）/ 好评率（已通过且 score>3）
     *
     * @return array{total: int, avg_score: float, good_percent: int}
     */
    public function stat(int $aid): array
    {
        $total = ScopeService::query('shop_comment', $aid)->count();

        $approved = ScopeService::query('shop_comment', $aid)->where('status', self::STATUS_APPROVED);
        $num      = (clone $approved)->count();
        $avg      = (clone $approved)->avg('score');
        $goodNum  = (clone $approved)->where('score', '>', 3)->count();

        return [
            'total'        => $total,
            'avg_score'    => $num > 0 ? round((float) $avg, 1) : 5.0,
            'good_percent' => $num > 0 ? (int) round($goodNum / $num * 100) : 100,
        ];
    }

    // ================= 审核 / 回复 / 删除 =================

    /**
     * 审核评价（仅待审核可操作；同事务重算商品聚合）
     *
     * @param int $status 1通过 2驳回
     */
    public function check(int $aid, int $id, int $status): void
    {
        if (!in_array($status, [self::STATUS_APPROVED, self::STATUS_REJECTED], true)) {
            throw new BusinessException('审核状态不合法');
        }

        $comment = $this->findComment($aid, $id);
        if ((int) $comment['status'] !== self::STATUS_PENDING) {
            throw new BusinessException('该评价已审核，请勿重复操作');
        }

        Db::startTrans();
        try {
            Db::name('shop_comment')->where('id', $id)->update(['status' => $status]);
            $this->recalcProduct($aid, (int) ($comment['proid'] ?? 0));
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    /**
     * 商家回复（reply_content/reply_content_pic/reply_time）
     *
     * @param array $data {id, reply_content, reply_content_pic}
     */
    public function reply(int $aid, array $data): void
    {
        $id      = (int) ($data['id'] ?? 0);
        $content = trim((string) ($data['reply_content'] ?? ''));
        $pic     = trim((string) ($data['reply_content_pic'] ?? ''));

        if ('' === $content && '' === $pic) {
            throw new BusinessException('回复内容不能为空');
        }

        $comment = $this->findComment($aid, $id);

        Db::name('shop_comment')->where('id', (int) $comment['id'])->update([
            'reply_content'     => $content,
            'reply_content_pic' => $pic,
            'reply_time'        => time(),
        ]);
    }

    /**
     * 删除评价（物理删除；同事务重算商品聚合）
     */
    public function delete(int $aid, int $id): void
    {
        $comment = $this->findComment($aid, $id);

        Db::startTrans();
        try {
            Db::name('shop_comment')->where('id', (int) $comment['id'])->delete();
            $this->recalcProduct($aid, (int) ($comment['proid'] ?? 0));
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    /**
     * 批量设置审核状态（已审=1 / 未审=0，可互相切换；同事务重算相关商品聚合）
     *
     * @param int[] $ids
     * @param int $status 0未审核 1已审核
     */
    public function batchSetStatus(int $aid, array $ids, int $status): int
    {
        if (!in_array($status, [self::STATUS_PENDING, self::STATUS_APPROVED], true)) {
            throw new BusinessException('审核状态不合法');
        }

        $ids = array_values(array_unique(array_map('intval', array_filter($ids, fn ($v) => (int) $v > 0))));
        if (empty($ids)) {
            throw new BusinessException('请选择要操作的评价');
        }

        $comments = ScopeService::query('shop_comment', $aid)->whereIn('id', $ids)->select()->toArray();
        if (empty($comments)) {
            throw new BusinessException('评价不存在或无权操作');
        }

        $commentIds = array_map(fn ($r) => (int) $r['id'], $comments);
        $proids = array_values(array_unique(array_map(fn ($r) => (int) ($r['proid'] ?? 0), $comments)));

        Db::startTrans();
        try {
            Db::name('shop_comment')->whereIn('id', $commentIds)->update(['status' => $status]);
            foreach ($proids as $proid) {
                $this->recalcProduct($aid, $proid);
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }

        return count($comments);
    }

    /**
     * 批量物理删除（同事务重算相关商品聚合）
     *
     * @param int[] $ids
     */
    public function batchDelete(int $aid, array $ids): int
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($ids, fn ($v) => (int) $v > 0))));
        if (empty($ids)) {
            throw new BusinessException('请选择要删除的评价');
        }

        $comments = ScopeService::query('shop_comment', $aid)->whereIn('id', $ids)->select()->toArray();
        if (empty($comments)) {
            throw new BusinessException('评价不存在或无权操作');
        }

        $commentIds = array_map(fn ($r) => (int) $r['id'], $comments);
        $proids = array_values(array_unique(array_map(fn ($r) => (int) ($r['proid'] ?? 0), $comments)));

        Db::startTrans();
        try {
            Db::name('shop_comment')->whereIn('id', $commentIds)->delete();
            foreach ($proids as $proid) {
                $this->recalcProduct($aid, $proid);
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }

        return count($comments);
    }

    // ================= 虚拟评价 =================

    /**
     * 新增/编辑虚拟评价（无订单评价，商品与会员信息手填；同事务重算商品聚合）
     *
     * @param array $data {id?, proid?, proname, propic, ggname?, score, content, content_pic?, nickname, headimg?, createtime?}
     * @return array{id: int}
     */
    public function save(int $aid, array $data): array
    {
        $id       = (int) ($data['id'] ?? 0);
        $proid    = (int) ($data['proid'] ?? 0);
        $proname  = trim((string) ($data['proname'] ?? ''));
        $nickname = trim((string) ($data['nickname'] ?? ''));
        $score    = (int) ($data['score'] ?? 0);

        if ($proid <= 0 && '' === $proname) {
            throw new BusinessException('请选择商品或填写商品名称');
        }
        if ('' === $nickname) {
            throw new BusinessException('请填写会员昵称');
        }
        if ($score < 1 || $score > 5) {
            throw new BusinessException('评分须为 1-5');
        }

        // 关联真实商品时取商品名/主图兜底，并校验归属
        $propic = trim((string) ($data['propic'] ?? ''));
        $ggname = trim((string) ($data['ggname'] ?? ''));
        if ($proid > 0) {
            $product = ScopeService::query('shop_product', $aid)->where('id', $proid)->find();
            if (empty($product)) {
                throw new BusinessException('所选商品不存在');
            }
            if ('' === $proname) {
                $proname = (string) ($product['name'] ?? '');
            }
            if ('' === $propic) {
                $propic = (string) ($product['pic'] ?? '');
            }
        }

        $createtime = (int) ($data['createtime'] ?? 0);

        $row = [
            'proid'       => $proid,
            'proname'     => $proname,
            'propic'      => $propic,
            'ggname'      => $ggname,
            'score'       => $score,
            'content'     => trim((string) ($data['content'] ?? '')),
            'content_pic' => trim((string) ($data['content_pic'] ?? '')),
            'nickname'    => $nickname,
            'headimg'     => trim((string) ($data['headimg'] ?? '')),
        ];

        Db::startTrans();
        try {
            if ($id > 0) {
                $comment = $this->findComment($aid, $id);
                if ($createtime > 0) {
                    $row['createtime'] = $createtime;
                }
                Db::name('shop_comment')->where('id', (int) $comment['id'])->update($row);
                $oldProid = (int) ($comment['proid'] ?? 0);
            } else {
                $comment  = null;
                $oldProid = 0;
                $row      = array_merge([
                    'aid'        => $aid,
                    'bid'        => 0,
                    'mid'        => 0,
                    'orderid'    => 0,
                    'ogid'       => 0,
                    'ggid'       => 0,
                    'ordernum'   => '',
                    'openid'     => '',
                    'status'     => self::STATUS_APPROVED, // 虚拟评价免审核直接通过
                    'createtime' => $createtime > 0 ? $createtime : time(),
                ], $row);
                $id = (int) Db::name('shop_comment')->insertGetId($row);
            }

            // 聚合重算：新关联商品必算；编辑时若换了商品，旧商品也要重算
            $this->recalcProduct($aid, $proid);
            if ($oldProid > 0 && $oldProid !== $proid) {
                $this->recalcProduct($aid, $oldProid);
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }

        return ['id' => $id];
    }

    // ================= 评价开关（shop_sysset） =================

    /**
     * 读取评价开关（行不存在给表默认值）
     *
     * @return array{comment: int, comment_check: int}
     */
    public function getConfig(int $aid): array
    {
        $row = ScopeService::query('shop_sysset', $aid)->find();

        return [
            'comment'       => (int) ($row['comment'] ?? 1),
            'comment_check' => (int) ($row['comment_check'] ?? 1),
        ];
    }

    /**
     * 保存评价开关（行不存在兜底 insert）
     *
     * @param array $data {comment, comment_check}
     */
    public function saveConfig(int $aid, array $data): void
    {
        $update = [
            'comment'       => (int) ($data['comment'] ?? 0) === 1 ? 1 : 0,
            'comment_check' => (int) ($data['comment_check'] ?? 0) === 1 ? 1 : 0,
        ];

        $row = ScopeService::query('shop_sysset', $aid)->find();
        if (empty($row)) {
            ScopeService::query('shop_sysset', $aid)
                ->insert(array_merge($update, ['aid' => $aid]));
        } else {
            Db::name('shop_sysset')->where('id', (int) $row['id'])->update($update);
        }
    }

    // ================= 商品聚合重算（复用） =================

    /**
     * 重算商品评价聚合：comment_num=已通过评价数，comment_score=平均分，
     * comment_haopercent=好评（score>3）占比；无评价时回归商品表默认值（5.0 / 100）。
     *
     * 注意：须由调用方包裹在事务中（审核/删除/虚拟评价同事务调用）
     */
    public function recalcProduct(int $aid, int $proid): void
    {
        if ($proid <= 0) {
            return;
        }

        $product = ScopeService::query('shop_product', $aid)->where('id', $proid)->find();
        if (empty($product)) {
            return;
        }

        $base = ScopeService::query('shop_comment', $aid)
            ->where('proid', $proid)
            ->where('status', self::STATUS_APPROVED);

        $num     = (clone $base)->count();
        $avg     = (clone $base)->avg('score');
        $goodNum = (clone $base)->where('score', '>', 3)->count();

        Db::name('shop_product')->where('id', $proid)->update([
            'comment_num'        => $num,
            'comment_score'      => $num > 0 ? round((float) $avg, 1) : 5.0,
            'comment_haopercent' => $num > 0 ? (int) round($goodNum / $num * 100) : 100,
        ]);
    }

    // ================= 内部工具 =================

    /**
     * 查找评价（校验租户归属）
     */
    protected function findComment(int $aid, int $id): array
    {
        if ($id <= 0) {
            throw new BusinessException('缺少评价ID');
        }

        $comment = ScopeService::query('shop_comment', $aid)->where('id', $id)->find();
        if (empty($comment)) {
            throw new BusinessException('评价不存在');
        }

        return $comment;
    }

    /**
     * 行格式化（时间保持 Unix 秒；图片逗号串拆数组便于前端渲染）
     */
    protected function formatRow(array $row): array
    {
        return [
            'id'                => (int) $row['id'],
            'mid'               => (int) ($row['mid'] ?? 0),
            'nickname'          => (string) ($row['nickname'] ?? ''),
            'headimg'           => (string) ($row['headimg'] ?? ''),
            'orderid'           => (int) ($row['orderid'] ?? 0),
            'ordernum'          => (string) ($row['ordernum'] ?? ''),
            'proid'             => (int) ($row['proid'] ?? 0),
            'proname'           => (string) ($row['proname'] ?? ''),
            'propic'            => (string) ($row['propic'] ?? ''),
            'ggname'            => (string) ($row['ggname'] ?? ''),
            'score'             => (int) ($row['score'] ?? 0),
            'content'           => (string) ($row['content'] ?? ''),
            'content_pic'       => $this->splitPics((string) ($row['content_pic'] ?? '')),
            'reply_content'     => (string) ($row['reply_content'] ?? ''),
            'reply_content_pic' => $this->splitPics((string) ($row['reply_content_pic'] ?? '')),
            'append_content'    => (string) ($row['append_content'] ?? ''),
            'status'            => (int) ($row['status'] ?? 0),
            'createtime'        => (int) ($row['createtime'] ?? 0),
            'reply_time'        => (int) ($row['reply_time'] ?? 0),
        ];
    }

    /**
     * 逗号分隔图片串 → 数组（空串给空数组）
     *
     * @return string[]
     */
    protected function splitPics(string $pics): array
    {
        $pics = trim($pics);
        if ('' === $pics) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $pics))));
    }
}
