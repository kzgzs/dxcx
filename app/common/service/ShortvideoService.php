<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;

/**
 * 短视频服务（批次5-C 任务196）
 *
 * 表口径（基线 20260812000006_content_tables，字段与参考系统完全一致）：
 * - shortvideo             视频（status 1显示 0待审/驳回；reason 驳回理由；productids 挂商品逗号串）
 * - shortvideo_category    分类（status 1正常 0禁用）
 * - shortvideo_comment     评论（status 0待审 1显示；zan 点赞冗余计数）
 * - shortvideo_comment_reply 回评（pid 指向评论；status 同上）
 * - shortvideo_comment_zanlog 评论点赞日志（pid+mid 一人一赞）
 * - shortvideo_zanlog      视频点赞日志（vid+mid 一人一赞，zan_num 冗余回写）
 * - shortvideo_sysset      系统设置（list_type/can_upload/upload_maxsize/upload_maxduration/upload_check/show_business_video）
 *
 * 对照参考系统 app/controller/Shortvideo*.php 与 ApiShortvideo.php：
 * - 商户维度：admin 侧 bid 过滤走 ScopeService::applyBid（''/all/0/gt0/数值）
 * - 商户投稿视频审核：business_sysset.shortvideo_check=1 时商户保存视频强制回待审
 * - C 端：点赞/评论点赞均 zanlog 去重可取消；评论按视频 comment 开关 + comment_check 审核流
 * - 用户投稿：sysset.can_upload=0 拒绝；upload_check=1 → status=0 待审
 */
class ShortvideoService
{
    /* ================= 系统设置 ================= */

    /**
     * 读取短视频系统设置（无记录自动初始化）
     *
     * @return array<string, mixed>
     */
    public function sysset(int $aid): array
    {
        $row = ScopeService::query('shortvideo_sysset', $aid)->find();

        if (empty($row)) {
            Db::name('shortvideo_sysset')->insert(['aid' => $aid]);
            $row = ScopeService::query('shortvideo_sysset', $aid)->find();
        }

        $row = (array) $row;

        return [
            'list_type'           => (int) ($row['list_type'] ?? 0),
            'can_upload'          => (int) ($row['can_upload'] ?? 0),
            'upload_maxsize'      => (int) ($row['upload_maxsize'] ?? 0),
            'upload_maxduration'  => (int) ($row['upload_maxduration'] ?? 0),
            'upload_check'        => (int) ($row['upload_check'] ?? 1),
            'show_business_video' => (int) ($row['show_business_video'] ?? 0),
        ];
    }

    /**
     * 保存系统设置（白名单六字段）
     *
     * @param array<string, mixed> $data
     */
    public function saveSysset(int $aid, array $data): void
    {
        $allow = [];
        foreach (['list_type', 'can_upload', 'upload_maxsize', 'upload_maxduration', 'upload_check', 'show_business_video'] as $field) {
            if (array_key_exists($field, $data)) {
                $allow[$field] = (int) $data[$field];
            }
        }

        if (empty($allow)) {
            throw new BusinessException('没有可保存的设置项');
        }

        $allow['aid'] = $aid;

        if (ScopeService::query('shortvideo_sysset', $aid)->find()) {
            Db::name('shortvideo_sysset')->where('aid', $aid)->update($allow);
        } else {
            Db::name('shortvideo_sysset')->insert($allow);
        }
    }

    /* ================= 分类 ================= */

    /**
     * 分类列表（分页；keyword 名称模糊；bid 走商户维度口径）
     *
     * @return array{list: array<int, array<string, mixed>>, total: int, page: int, pagesize: int}
     */
    public function categoryList(int $aid, string $bid, string $keyword, string $status, int $page, int $pageSize): array
    {
        $query = ScopeService::query('shortvideo_category', $aid);
        ScopeService::applyBid($query, $bid);

        if ('' !== $keyword) {
            $query->where('name', 'like', '%' . $keyword . '%');
        }
        if ('' !== $status) {
            $query->where('status', (int) $status);
        }

        $total = (clone $query)->count();
        $rows  = $query->order('sort', 'desc')->order('id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        $list = array_map(function (array $row) use ($aid) {
            return $this->formatCategoryRow($aid, $row);
        }, $rows);

        return ['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize];
    }

    /**
     * 分类下拉选项（启用状态；bid=-1 全部，否则按 bid 精确）
     *
     * @return array<int, array{id: int, name: string}>
     */
    public function categoryOptions(int $aid, int $bid = -1): array
    {
        $query = ScopeService::query('shortvideo_category', $aid)->where('status', 1);

        if ($bid >= 0) {
            $query->where('bid', $bid);
        }

        return array_map(static fn (array $v) => ['id' => (int) $v['id'], 'name' => (string) $v['name']], $query->order('sort', 'desc')->order('id', 'desc')->select()->toArray());
    }

    /**
     * 分类下拉选项（按 bid 维度口径，供后台列表筛选 / 编辑回显）
     *
     * bid 口径与 ScopeService::applyBid 一致：''/all 全部、'0' 平台、'gt0' 商户、数字=指定商户。
     * 点大列表/编辑的分类下拉随当前归属（aid+bid）过滤，且只取启用分类。
     *
     * @return array<int, array{id: int, name: string}>
     */
    public function categoryOptionsByScope(int $aid, string $bid): array
    {
        $query = ScopeService::query('shortvideo_category', $aid)->where('status', 1);
        ScopeService::applyBid($query, $bid);

        return array_map(static fn (array $v) => ['id' => (int) $v['id'], 'name' => (string) $v['name']], $query->order('sort', 'desc')->order('id', 'desc')->select()->toArray());
    }

    /**
     * 分类新增/编辑
     *
     * @param array<string, mixed> $data
     * @return array{id: int}
     */
    public function categorySave(int $aid, array $data): array
    {
        $id     = (int) ($data['id'] ?? 0);
        $name   = trim((string) ($data['name'] ?? ''));
        $status = (int) ($data['status'] ?? 1);
        $sort   = (int) ($data['sort'] ?? 0);
        $bid    = (int) ($data['bid'] ?? 0);

        if ('' === $name) {
            throw new BusinessException('请输入分类名称');
        }

        if ($id > 0) {
            $exists = ScopeService::query('shortvideo_category', $aid)->where('id', $id)->find();
            if (empty($exists)) {
                throw new BusinessException('分类不存在', 404, 404);
            }

            Db::name('shortvideo_category')->where('id', $id)->where('aid', $aid)
                ->update(['name' => $name, 'status' => $status, 'sort' => $sort]);
        } else {
            $id = (int) Db::name('shortvideo_category')->insertGetId([
                'aid'        => $aid,
                'bid'        => $bid,
                'name'       => $name,
                'status'     => $status,
                'sort'       => $sort,
                'createtime' => time(),
            ]);
        }

        return ['id' => $id];
    }

    /**
     * 删除分类（分类下有视频不允许删除）
     */
    public function categoryDelete(int $aid, int $id): void
    {
        $exists = ScopeService::query('shortvideo_category', $aid)->where('id', $id)->find();
        if (empty($exists)) {
            throw new BusinessException('分类不存在', 404, 404);
        }

        $videonum = ScopeService::query('shortvideo', $aid)->where('cid', $id)->count();
        if ($videonum > 0) {
            throw new BusinessException('该分类下还有 ' . $videonum . ' 个视频，请先移除后再删除');
        }

        Db::name('shortvideo_category')->where('id', $id)->where('aid', $aid)->delete();
    }

    /**
     * 分类批量开启/关闭（status 1 开启 0 关闭）
     *
     * @param array<int, int> $ids
     */
    public function categoryStatus(int $aid, array $ids, int $status): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) {
            throw new BusinessException('请选择要操作的分类');
        }
        if (!in_array($status, [0, 1], true)) {
            throw new BusinessException('状态值非法');
        }

        Db::name('shortvideo_category')->where('aid', $aid)->whereIn('id', $ids)->update(['status' => $status]);
    }

    /* ================= 视频 ================= */

    /**
     * 视频列表（keyword/status/cid/bid 过滤；附分类名/发布人/商户名/评论数）
     *
     * @return array{list: array<int, array<string, mixed>>, total: int, page: int, pagesize: int}
     */
    public function videoList(int $aid, string $keyword, string $status, int $cid, string $bid, int $page, int $pageSize, string $createtime = '', string $orderby = ''): array
    {
        $query = ScopeService::query('shortvideo', $aid);
        ScopeService::applyBid($query, $bid);

        if ('' !== $keyword) {
            $query->where('name|description', 'like', '%' . $keyword . '%');
        }
        if ('' !== $status) {
            $query->where('status', (int) $status);
        }
        if ($cid > 0) {
            $query->where('cid', $cid);
        }
        // 创建时间：支持单日（YYYY-MM-DD）与范围（YYYY-MM-DD,YYYY-MM-DD）
        $this->applyCreatetimeRange($query, $createtime);

        $total = (clone $query)->count();

        // 列排序（点大列表头可点排序）：白名单 createtime/view_num/zan_num/sort，方向 asc/desc
        $this->applyOrderby($query, $orderby, 'sort', 'desc');

        $rows = $query->page($page, $pageSize)
            ->select()
            ->toArray();

        $catNames = $this->categoryNameMap($aid);
        $list     = [];

        foreach ($rows as $row) {
            $list[] = $this->formatVideoRow($aid, $row, $catNames);
        }

        return ['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize];
    }

    /**
     * 视频详情（编辑回显；productids 转数组）
     *
     * @return array<string, mixed>
     */
    public function videoDetail(int $aid, int $id): array
    {
        $row = ScopeService::query('shortvideo', $aid)->where('id', $id)->find();
        if (empty($row)) {
            throw new BusinessException('视频不存在', 404, 404);
        }

        return $this->formatVideoRow($aid, (array) $row, $this->categoryNameMap($aid));
    }

    /**
     * 视频新增/编辑（productids 数组转逗号串；商户视频按 business_sysset.shortvideo_check 回待审）
     *
     * @param array<string, mixed> $data
     * @return array{id: int}
     */
    public function videoSave(int $aid, array $data): array
    {
        $id  = (int) ($data['id'] ?? 0);
        $cid = (int) ($data['cid'] ?? 0);
        $bid = (int) ($data['bid'] ?? 0);

        $name = trim((string) ($data['name'] ?? ''));
        if ('' === $name) {
            throw new BusinessException('请输入视频名称');
        }

        $url = trim((string) ($data['url'] ?? ''));
        if ('' === $url) {
            throw new BusinessException('请提供视频地址');
        }

        $productIds = $data['productids'] ?? [];
        if (is_string($productIds)) {
            $productIds = array_filter(array_map('trim', explode(',', $productIds)));
        }
        $productIds = implode(',', array_values(array_unique(array_filter(array_map('intval', (array) $productIds)))));

        $info = [
            'cid'            => $cid,
            'name'           => $name,
            'url'            => $url,
            'video_duration' => (float) ($data['video_duration'] ?? 0),
            'coverimg'       => trim((string) ($data['coverimg'] ?? '')),
            'description'    => trim((string) ($data['description'] ?? '')),
            'productids'     => $productIds,
            'linkurl'        => trim((string) ($data['linkurl'] ?? '')),
            'linkname'       => trim((string) ($data['linkname'] ?? '')),
            'comment'        => (int) ($data['comment'] ?? 1),
            'comment_check'  => (int) ($data['comment_check'] ?? 0),
            'view_num'       => max(0, (int) ($data['view_num'] ?? 0)),
            'zan_num'        => max(0, (int) ($data['zan_num'] ?? 0)),
            'sort'           => (int) ($data['sort'] ?? 0),
        ];

        if ($cid > 0) {
            $cat = ScopeService::query('shortvideo_category', $aid)->where('id', $cid)->find();
            if (empty($cat)) {
                throw new BusinessException('所选分类不存在');
            }
        }

        // 状态三态：0 待审核 / 1 已通过 / 2 已驳回（驳回必填 reason，通过清空 reason）。
        // 平台后台保存总会显式传 status，可直接审核商户视频；needCheck 仅为未来商户端未传 status 时兜底。
        $hasStatus = array_key_exists('status', $data) && null !== $data['status'] && '' !== $data['status'];
        $statusVal = $hasStatus ? (int) $data['status'] : 1;
        if (!in_array($statusVal, [0, 1, 2], true)) {
            throw new BusinessException('状态值非法');
        }
        $reason = trim((string) ($data['reason'] ?? ''));
        if (2 === $statusVal && '' === $reason) {
            throw new BusinessException('驳回时请填写驳回原因');
        }

        // 商户视频审核口径（对标参考系统 business_sysset.shortvideo_check）
        $needCheck = false;
        if ($bid > 0) {
            $needCheck = 1 === (int) ScopeService::query('business_sysset', $aid)->value('shortvideo_check');
        }

        if ($id > 0) {
            $exists = ScopeService::query('shortvideo', $aid)->where('id', $id)->find();
            if (empty($exists)) {
                throw new BusinessException('视频不存在', 404, 404);
            }

            if ($needCheck && !$hasStatus && 0 !== (int) $exists['status']) {
                $info['status'] = 0; // 商户端改动需重新审核
            } elseif ($hasStatus) {
                $info['status'] = $statusVal;
                $info['reason'] = 2 === $statusVal ? $reason : '';
            }

            Db::name('shortvideo')->where('id', $id)->where('aid', $aid)->update($info);
        } else {
            $newStatus = $hasStatus ? $statusVal : ($needCheck ? 0 : 1);

            $info['aid']        = $aid;
            $info['bid']        = $bid;
            $info['mid']        = 0;
            $info['createtime'] = time();
            $info['status']     = $newStatus;
            $info['reason']     = 2 === $newStatus ? $reason : '';

            $id = (int) Db::name('shortvideo')->insertGetId($info);
        }

        return ['id' => $id];
    }

    /**
     * 视频批量上下架
     *
     * @param array<int, int> $ids
     */
    public function videoStatus(int $aid, array $ids, int $status): void
    {
        if (empty($ids)) {
            throw new BusinessException('请选择要操作的视频');
        }
        if (!in_array($status, [0, 1, 2], true)) {
            throw new BusinessException('状态值非法');
        }

        $affected = ScopeService::query('shortvideo', $aid)->where('id', 'in', $ids)->count();
        if (0 === $affected) {
            throw new BusinessException('无可操作的视频');
        }

        // 批量通过清空驳回原因；批量驳回保留各自原有原因
        $update = ['status' => $status];
        if (1 === $status) {
            $update['reason'] = '';
        }
        Db::name('shortvideo')->where('aid', $aid)->where('id', 'in', $ids)->update($update);
    }

    /**
     * 视频审核（status 1 已通过；2 已驳回并记录 reason；对齐点大 setcheckst）
     */
    public function videoCheck(int $aid, int $id, int $status, string $reason): void
    {
        if (!in_array($status, [1, 2], true)) {
            throw new BusinessException('审核状态非法');
        }

        $exists = ScopeService::query('shortvideo', $aid)->where('id', $id)->find();
        if (empty($exists)) {
            throw new BusinessException('视频不存在', 404, 404);
        }

        $reason = trim($reason);
        if (2 === $status && '' === $reason) {
            throw new BusinessException('驳回时请填写驳回原因');
        }

        Db::name('shortvideo')->where('aid', $aid)->where('id', $id)
            ->update(['status' => $status, 'reason' => 1 === $status ? '' : $reason]);
    }

    /**
     * 视频批量删除（级联清理评论/回评/点赞日志）
     *
     * @param array<int, int> $ids
     */
    public function videoDelete(int $aid, array $ids): void
    {
        if (empty($ids)) {
            throw new BusinessException('请选择要删除的视频');
        }

        $list = ScopeService::query('shortvideo', $aid)->where('id', 'in', $ids)->column('id');
        if (empty($list)) {
            throw new BusinessException('无可操作的视频');
        }

        Db::name('shortvideo')->where('aid', $aid)->where('id', 'in', $list)->delete();
        Db::name('shortvideo_zanlog')->where('aid', $aid)->where('vid', 'in', $list)->delete();

        $commentIds = Db::name('shortvideo_comment')->where('aid', $aid)->where('vid', 'in', $list)->column('id');
        Db::name('shortvideo_comment')->where('aid', $aid)->where('vid', 'in', $list)->delete();
        Db::name('shortvideo_comment_reply')->where('aid', $aid)->where('vid', 'in', $list)->delete();
        if (!empty($commentIds)) {
            Db::name('shortvideo_comment_zanlog')->where('aid', $aid)->where('pid', 'in', $commentIds)->delete();
        }
    }

    /* ================= 评论 / 回评 ================= */

    /**
     * 评论列表（status ''=全部 0待审 1显示；vid/content 过滤；附视频标题/回评数）
     *
     * @return array{list: array<int, array<string, mixed>>, total: int, page: int, pagesize: int}
     */
    public function commentList(int $aid, string $status, int $vid, string $content, int $page, int $pageSize, string $createtime = '', string $orderby = ''): array
    {
        $query = ScopeService::query('shortvideo_comment', $aid);

        if ('' !== $status) {
            $query->where('status', (int) $status);
        }
        if ($vid > 0) {
            $query->where('vid', $vid);
        }
        if ('' !== $content) {
            $query->where('content', 'like', '%' . $content . '%');
        }
        $this->applyCreatetimeRange($query, $createtime);

        $total = (clone $query)->count();
        $this->applyOrderby($query, $orderby, 'id', 'desc');
        $rows  = $query->page($page, $pageSize)->select()->toArray();

        $videoNames = ScopeService::query('shortvideo', $aid)->column('name', 'id');
        $replyCount = Db::name('shortvideo_comment_reply')
            ->where('aid', $aid)
            ->field('pid, count(1) c')
            ->group('pid')
            ->select()
            ->toArray();
        $replyMap = [];
        foreach ($replyCount as $v) {
            $replyMap[(int) $v['pid']] = (int) $v['c'];
        }

        $list = [];
        foreach ($rows as $row) {
            $list[] = [
                'id'          => (int) $row['id'],
                'vid'         => (int) $row['vid'],
                'vname'       => (string) ($videoNames[$row['vid']] ?? ''),
                'mid'         => (int) $row['mid'],
                'nickname'    => (string) ($row['nickname'] ?? ''),
                'headimg'     => (string) ($row['headimg'] ?? ''),
                'content'     => (string) ($row['content'] ?? ''),
                'zan'         => (int) ($row['zan'] ?? 0),
                'status'      => (int) $row['status'],
                'status_text' => $this->commentStatusText((int) $row['status']),
                'replycount'  => $replyMap[(int) $row['id']] ?? 0,
                'createtime'  => (int) ($row['createtime'] ?? 0),
            ];
        }

        return ['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize];
    }

    /**
     * 评论批量审核（status 0待审 1显示）
     *
     * @param array<int, int> $ids
     */
    public function commentSetStatus(int $aid, array $ids, int $status): void
    {
        if (empty($ids)) {
            throw new BusinessException('请选择要操作的评论');
        }

        Db::name('shortvideo_comment')->where('aid', $aid)->where('id', 'in', $ids)->update(['status' => $status]);
    }

    /**
     * 后台回复评论（以平台身份写入回评表，直接显示）
     */
    public function commentReply(int $aid, int $id, string $content): void
    {
        $content = trim($content);
        if ('' === $content) {
            throw new BusinessException('回复内容不能为空');
        }

        $comment = ScopeService::query('shortvideo_comment', $aid)->where('id', $id)->find();
        if (empty($comment)) {
            throw new BusinessException('评论不存在', 404, 404);
        }

        $adminName = (string) ScopeService::query('admin_set', $aid)->value('name');

        Db::name('shortvideo_comment_reply')->insert([
            'aid'        => $aid,
            'bid'        => (int) ($comment['bid'] ?? 0),
            'vid'        => (int) ($comment['vid'] ?? 0),
            'pid'        => $id,
            'mid'        => 0,
            'headimg'    => '',
            'nickname'   => $adminName ?: '平台',
            'content'    => $content,
            'createtime' => time(),
            'status'     => 1,
        ]);
    }

    /**
     * 评论批量删除（级联回评与评论点赞日志）
     *
     * @param array<int, int> $ids
     */
    public function commentDelete(int $aid, array $ids): void
    {
        if (empty($ids)) {
            throw new BusinessException('请选择要删除的评论');
        }

        Db::name('shortvideo_comment')->where('aid', $aid)->where('id', 'in', $ids)->delete();
        Db::name('shortvideo_comment_reply')->where('aid', $aid)->where('pid', 'in', $ids)->delete();
        Db::name('shortvideo_comment_zanlog')->where('aid', $aid)->where('pid', 'in', $ids)->delete();
    }

    /**
     * 回评列表（status ''=全部 0待审 1显示；pid/content 过滤；附原评论内容）
     *
     * @return array{list: array<int, array<string, mixed>>, total: int, page: int, pagesize: int}
     */
    public function replyList(int $aid, string $status, int $pid, string $content, int $page, int $pageSize, string $createtime = '', string $orderby = ''): array
    {
        $query = ScopeService::query('shortvideo_comment_reply', $aid);

        if ('' !== $status) {
            $query->where('status', (int) $status);
        }
        if ($pid > 0) {
            $query->where('pid', $pid);
        }
        if ('' !== $content) {
            $query->where('content', 'like', '%' . $content . '%');
        }
        $this->applyCreatetimeRange($query, $createtime);

        $total = (clone $query)->count();
        $this->applyOrderby($query, $orderby, 'id', 'desc');
        $rows  = $query->page($page, $pageSize)->select()->toArray();

        $commentContents = ScopeService::query('shortvideo_comment', $aid)->column('content', 'id');
        $videoNames      = ScopeService::query('shortvideo', $aid)->column('name', 'id');

        $list = [];
        foreach ($rows as $row) {
            $list[] = [
                'id'          => (int) $row['id'],
                'pid'         => (int) $row['pid'],
                'pcontent'    => (string) ($commentContents[$row['pid']] ?? ''),
                'vid'         => (int) $row['vid'],
                'vname'       => (string) ($videoNames[$row['vid']] ?? ''),
                'mid'         => (int) $row['mid'],
                'nickname'    => (string) ($row['nickname'] ?? ''),
                'headimg'     => (string) ($row['headimg'] ?? ''),
                'content'     => (string) ($row['content'] ?? ''),
                'status'      => (int) $row['status'],
                'status_text' => $this->commentStatusText((int) $row['status']),
                'createtime'  => (int) ($row['createtime'] ?? 0),
            ];
        }

        return ['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize];
    }

    /**
     * 回评批量审核
     *
     * @param array<int, int> $ids
     */
    public function replySetStatus(int $aid, array $ids, int $status): void
    {
        if (empty($ids)) {
            throw new BusinessException('请选择要操作的回评');
        }

        Db::name('shortvideo_comment_reply')->where('aid', $aid)->where('id', 'in', $ids)->update(['status' => $status]);
    }

    /**
     * 回评批量删除
     *
     * @param array<int, int> $ids
     */
    public function replyDelete(int $aid, array $ids): void
    {
        if (empty($ids)) {
            throw new BusinessException('请选择要删除的回评');
        }

        Db::name('shortvideo_comment_reply')->where('aid', $aid)->where('id', 'in', $ids)->delete();
    }

    /* ================= C 端 ================= */

    /**
     * C 端视频列表（第 1 页附带分类与系统设置；show_business_video=0 时仅平台视频）
     *
     * @return array<string, mixed>
     */
    public function apiIndex(int $aid, int $bid, int $cid, string $keyword, int $page): array
    {
        $sysset = $this->sysset($aid);

        $query = ScopeService::query('shortvideo', $aid)->where('status', 1);

        if ($bid > 0) {
            $query->where('bid', $bid);
        } elseif (0 === (int) $sysset['show_business_video']) {
            $query->where('bid', 0);
        }

        if ($cid > 0) {
            $query->where('cid', $cid);
        }
        if ('' !== $keyword) {
            $query->where('name|description', 'like', '%' . $keyword . '%');
        }

        $rows = $query->order('sort', 'desc')->order('zan_num', 'desc')->order('createtime', 'desc')
            ->page($page, 10)
            ->select()
            ->toArray();

        $datalist = [];
        foreach ($rows as $row) {
            $datalist[] = $this->formatApiVideo($aid, $row);
        }

        $clist = 1 === $page ? $this->categoryOptions($aid, $bid > 0 ? $bid : 0) : [];

        return ['datalist' => $datalist, 'clist' => $clist, 'sysset' => $sysset];
    }

    /**
     * C 端视频详情（上滑播放列表：当前视频置顶循环；附 iszan/评论数/挂商品；自增播放量）
     *
     * @return array<string, mixed>
     */
    public function apiDetail(int $aid, int $mid, int $id, int $cid): array
    {
        $first = ScopeService::query('shortvideo', $aid)->where('id', $id)->find();
        if (empty($first) || 1 !== (int) $first['status']) {
            throw new BusinessException('视频不存在', 404, 404);
        }

        $sysset = $this->sysset($aid);

        $query = ScopeService::query('shortvideo', $aid)->where('status', 1);
        if ((int) $first['bid'] > 0) {
            $query->where('bid', (int) $first['bid']);
        } elseif (0 === (int) $sysset['show_business_video']) {
            $query->where('bid', 0);
        }
        if ($cid > 0) {
            $query->where('cid', $cid);
        }

        $all = $query->order('sort', 'desc')->order('id', 'desc')->select()->toArray();

        // 当前视频置顶，其余顺序后随（对齐参考系统循环播放口径）
        $before = [];
        $after  = [];
        $isin   = false;
        foreach ($all as $v) {
            if ((int) $v['id'] === (int) $first['id']) {
                $isin = true;
            }
            if ($isin) {
                $after[] = $v;
            } else {
                $before[] = $v;
            }
        }
        $videoList = array_merge($after, $before);

        $zanVids = ScopeService::query('shortvideo_zanlog', $aid)->where('mid', $mid)->column('vid');
        $zanVids = array_map('intval', $zanVids);

        $commentRows = ScopeService::query('shortvideo_comment', $aid)
            ->where('status', 1)
            ->field('vid, count(1) c')
            ->group('vid')
            ->select()
            ->toArray();
        $commentMap = [];
        foreach ($commentRows as $v) {
            $commentMap[(int) $v['vid']] = (int) $v['c'];
        }

        $binfo = $this->videoOwner($aid, (array) $first);

        $list = [];
        foreach ($videoList as $v) {
            $item            = $this->formatApiVideo($aid, $v);
            $item['iszan']   = in_array((int) $v['id'], $zanVids, true);
            $item['commentnum'] = $commentMap[(int) $v['id']] ?? 0;

            // 挂商品摘要（首个商品）
            $item['pronum'] = 0;
            if (!empty($v['productids'])) {
                $proIds            = array_filter(array_map('intval', explode(',', (string) $v['productids'])));
                $item['pronum']    = count($proIds);
                $product           = ScopeService::query('shop_product', $aid)
                    ->where('id', reset($proIds))
                    ->field('id, name, pic, sales, sell_price, market_price')
                    ->find();
                if ($product) {
                    $item['proid']          = (int) $product['id'];
                    $item['proname']        = (string) $product['name'];
                    $item['propic']         = (string) $product['pic'];
                    $item['prosales']       = (int) $product['sales'];
                    $item['prosell_price']  = (string) $product['sell_price'];
                    $item['detail_url']     = '/pagesM/shop/product?id=' . (int) $product['id'];
                }
            }

            $list[] = $item;
        }

        // 播放量自增
        Db::name('shortvideo')->where('aid', $aid)->where('id', (int) $first['id'])->inc('view_num')->update();

        return ['videoList' => $list, 'binfo' => $binfo, 'list_type' => (int) $sysset['list_type']];
    }

    /**
     * 播放量累加
     */
    public function apiUpdateViewNum(int $aid, int $id): void
    {
        $exists = ScopeService::query('shortvideo', $aid)->where('id', $id)->find();
        if (empty($exists)) {
            throw new BusinessException('视频不存在', 404, 404);
        }

        Db::name('shortvideo')->where('aid', $aid)->where('id', $id)->inc('view_num')->update();
    }

    /**
     * C 端评论列表（显示状态；附回复列表与当前会员是否已点赞）
     *
     * @return array<string, mixed>
     */
    public function apiCommentList(int $aid, int $mid, int $id, int $page): array
    {
        $rows = ScopeService::query('shortvideo_comment', $aid)
            ->where('vid', $id)
            ->where('status', 1)
            ->order('createtime', 'desc')
            ->page($page, 20)
            ->select()
            ->toArray();

        $myZanPids = ScopeService::query('shortvideo_comment_zanlog', $aid)
            ->where('mid', $mid)
            ->column('pid');
        $myZanPids = array_map('intval', $myZanPids);

        $list = [];
        foreach ($rows as $row) {
            $replyRows = ScopeService::query('shortvideo_comment_reply', $aid)
                ->where('pid', (int) $row['id'])
                ->where('status', 1)
                ->field('nickname, headimg, content, createtime')
                ->order('createtime', 'asc')
                ->select()
                ->toArray();

            $list[] = [
                'id'         => (int) $row['id'],
                'mid'        => (int) $row['mid'],
                'nickname'   => (string) ($row['nickname'] ?? ''),
                'headimg'    => (string) ($row['headimg'] ?? ''),
                'content'    => (string) ($row['content'] ?? ''),
                'zan'        => (int) ($row['zan'] ?? 0),
                'iszan'      => in_array((int) $row['id'], $myZanPids, true),
                'createtime' => (int) ($row['createtime'] ?? 0),
                'replylist'  => array_map(static fn (array $r) => [
                    'nickname'   => (string) ($r['nickname'] ?? ''),
                    'headimg'    => (string) ($r['headimg'] ?? ''),
                    'content'    => (string) ($r['content'] ?? ''),
                    'createtime' => (int) ($r['createtime'] ?? 0),
                ], $replyRows),
            ];
        }

        return ['data' => $list];
    }

    /**
     * 视频点赞/取消点赞（zanlog 一人一赞去重；zan_num 冗余回写）
     *
     * @return array{iszan: bool, zan_num: int}
     */
    public function apiZan(int $aid, int $mid, int $id): array
    {
        $video = ScopeService::query('shortvideo', $aid)->where('id', $id)->find();
        if (empty($video)) {
            throw new BusinessException('视频不存在', 404, 404);
        }

        $zanlog = ScopeService::query('shortvideo_zanlog', $aid)
            ->where('vid', $id)
            ->where('mid', $mid)
            ->find();

        if ($zanlog) {
            Db::name('shortvideo_zanlog')->where('id', (int) $zanlog['id'])->delete();
            if ((int) $video['zan_num'] > 0) {
                Db::name('shortvideo')->where('aid', $aid)->where('id', $id)->dec('zan_num')->update();
            }
            $iszan = false;
        } else {
            Db::name('shortvideo_zanlog')->insert([
                'aid'        => $aid,
                'bid'        => (int) ($video['bid'] ?? 0),
                'vid'        => $id,
                'mid'        => $mid,
                'createtime' => time(),
            ]);
            Db::name('shortvideo')->where('aid', $aid)->where('id', $id)->inc('zan_num')->update();
            $iszan = true;
        }

        $zanNum = (int) Db::name('shortvideo')->where('aid', $aid)->where('id', $id)->value('zan_num');

        return ['iszan' => $iszan, 'zan_num' => $zanNum];
    }

    /**
     * 发表评论/回评（comment 开关校验；comment_check=1 进待审）
     *
     * @return array{msg: string, commentnum: int}
     */
    public function apiSubpinglun(int $aid, int $mid, int $id, int $hfid, string $content): array
    {
        $content = trim($content);
        if ($id <= 0) {
            throw new BusinessException('参数错误');
        }
        if ('' === $content) {
            throw new BusinessException('请输入评论内容');
        }

        $video = ScopeService::query('shortvideo', $aid)->where('id', $id)->where('status', 1)->find();
        if (empty($video)) {
            throw new BusinessException('视频不存在', 404, 404);
        }
        if (0 === (int) $video['comment']) {
            throw new BusinessException('评论功能未开启');
        }

        $member = ScopeService::query('member', $aid)->where('id', $mid)->field('nickname, headimg')->find();

        $status = 1 === (int) $video['comment_check'] ? 0 : 1;
        $msg    = 0 === $status ? '提交成功，请等待审核' : '发表评论成功';

        $data = [
            'aid'        => $aid,
            'bid'        => (int) ($video['bid'] ?? 0),
            'vid'        => $id,
            'mid'        => $mid,
            'headimg'    => (string) ($member['headimg'] ?? ''),
            'nickname'   => (string) ($member['nickname'] ?? ''),
            'content'    => $content,
            'createtime' => time(),
            'status'     => $status,
        ];

        if ($hfid > 0) {
            $parent = ScopeService::query('shortvideo_comment', $aid)->where('id', $hfid)->find();
            if (empty($parent)) {
                throw new BusinessException('原评论不存在', 404, 404);
            }
            $data['pid'] = $hfid;
            Db::name('shortvideo_comment_reply')->insert($data);
        } else {
            Db::name('shortvideo_comment')->insert($data);
        }

        $commentnum = ScopeService::query('shortvideo_comment', $aid)
            ->where('vid', $id)
            ->where('status', 1)
            ->count();

        return ['msg' => $msg, 'commentnum' => $commentnum];
    }

    /**
     * 评论点赞/取消点赞（comment_zanlog 一人一赞去重；zan 冗余回写）
     *
     * @return array{type: int, zancount: int}
     */
    public function apiPzan(int $aid, int $mid, int $id): array
    {
        $comment = ScopeService::query('shortvideo_comment', $aid)->where('id', $id)->find();
        if (empty($comment)) {
            throw new BusinessException('评论不存在', 404, 404);
        }

        $zanlog = ScopeService::query('shortvideo_comment_zanlog', $aid)
            ->where('pid', $id)
            ->where('mid', $mid)
            ->find();

        if ($zanlog) {
            Db::name('shortvideo_comment_zanlog')->where('id', (int) $zanlog['id'])->delete();
            if ((int) $comment['zan'] > 0) {
                Db::name('shortvideo_comment')->where('aid', $aid)->where('id', $id)->dec('zan')->update();
            }
            $type = 0;
        } else {
            Db::name('shortvideo_comment_zanlog')->insert([
                'aid'        => $aid,
                'bid'        => (int) ($comment['bid'] ?? 0),
                'pid'        => $id,
                'mid'        => $mid,
                'createtime' => time(),
            ]);
            Db::name('shortvideo_comment')->where('aid', $aid)->where('id', $id)->inc('zan')->update();
            $type = 1;
        }

        $zancount = (int) Db::name('shortvideo_comment')->where('aid', $aid)->where('id', $id)->value('zan');

        return ['type' => $type, 'zancount' => $zancount];
    }

    /**
     * 分享次数累加
     */
    public function apiAddShareNum(int $aid, int $id): void
    {
        $exists = ScopeService::query('shortvideo', $aid)->where('id', $id)->find();
        if (empty($exists)) {
            throw new BusinessException('视频不存在', 404, 404);
        }

        Db::name('shortvideo')->where('aid', $aid)->where('id', $id)->inc('share_num')->update();
    }

    /**
     * 视频挂载商品列表（按 productids 顺序）
     *
     * @return array<int, array<string, mixed>>
     */
    public function apiGetProList(int $aid, int $id): array
    {
        $video = ScopeService::query('shortvideo', $aid)->where('id', $id)->find();
        if (empty($video)) {
            throw new BusinessException('视频不存在', 404, 404);
        }

        $productIds = trim((string) ($video['productids'] ?? ''));
        if ('' === $productIds) {
            return [];
        }

        $rows = ScopeService::query('shop_product', $aid)
            ->where('id', 'in', $productIds)
            ->field('id, pic, name, sales, market_price, sell_price, sellpoint')
            ->order(Db::raw('field(id,' . $productIds . ')'))
            ->select()
            ->toArray();

        return array_map(static fn (array $v) => [
            'id'          => (int) $v['id'],
            'pic'         => (string) $v['pic'],
            'name'        => (string) $v['name'],
            'sales'       => (int) $v['sales'],
            'market_price'=> (string) $v['market_price'],
            'sell_price'  => (string) $v['sell_price'],
            'sellpoint'   => (string) ($v['sellpoint'] ?? ''),
            'detail_url'  => '/pagesM/shop/product?id=' . (int) $v['id'],
            'type'        => 'goods',
        ], $rows);
    }

    /**
     * 用户投稿配置（can_upload 开关 + 分类 + 上传限制）
     *
     * @return array<string, mixed>
     */
    public function apiUploadConfig(int $aid, int $bid): array
    {
        $sysset = $this->sysset($aid);

        return [
            'sysset' => $sysset,
            'clist'  => $this->categoryOptions($aid, $bid),
        ];
    }

    /**
     * 用户投稿（can_upload=0 拒绝；upload_check=1 待审）
     *
     * @return array{msg: string, id: int}
     */
    public function apiUploadVideo(int $aid, int $mid, int $bid, array $data): array
    {
        $sysset = $this->sysset($aid);
        if (0 === (int) $sysset['can_upload']) {
            throw new BusinessException('发布功能未启用');
        }

        $title  = trim((string) ($data['title'] ?? ''));
        $video  = trim((string) ($data['video'] ?? ''));
        $pics   = trim((string) ($data['pics'] ?? ''));
        $cid    = (int) ($data['cid'] ?? 0);

        if ('' === $title) {
            throw new BusinessException('请输入视频标题');
        }
        if ('' === $video) {
            throw new BusinessException('请上传视频文件');
        }

        if ($bid > 0 && empty(ScopeService::query('business', $aid)->where('id', $bid)->find())) {
            $bid = 0;
        }

        $status = 1 === (int) $sysset['upload_check'] ? 0 : 1;
        $msg    = 0 === $status ? '提交成功，请等待审核' : '发布成功';

        $id = (int) Db::name('shortvideo')->insertGetId([
            'aid'         => $aid,
            'bid'         => $bid,
            'cid'         => $cid,
            'mid'         => $mid,
            'name'        => $title,
            'description' => trim((string) ($data['content'] ?? '')),
            'coverimg'    => $pics,
            'url'         => $video,
            'createtime'  => time(),
            'status'      => $status,
            'comment'     => 1,
        ]);

        return ['msg' => $msg, 'id' => $id];
    }

    /**
     * 我的投稿列表（st all/0/1；含各状态计数）
     *
     * @return array<string, mixed>
     */
    public function apiMyUpload(int $aid, int $mid, string $st, string $keyword, int $page): array
    {
        $query = ScopeService::query('shortvideo', $aid)->where('mid', $mid);

        if ('' !== $keyword) {
            $query->where('name|description', 'like', '%' . $keyword . '%');
        }

        $countAll = (clone $query)->count();
        $count0   = (clone $query)->where('status', 0)->count();
        $count1   = (clone $query)->where('status', 1)->count();

        if ('0' === $st || '1' === $st) {
            $query->where('status', (int) $st);
        }

        $rows = $query->order('sort', 'desc')->order('id', 'desc')
            ->page($page, 20)
            ->select()
            ->toArray();

        $datalist = [];
        foreach ($rows as $row) {
            $datalist[] = $this->formatApiVideo($aid, $row);
        }

        return [
            'countall' => $countAll,
            'count0'   => $count0,
            'count1'   => $count1,
            'datalist' => $datalist,
            'st'       => $st,
        ];
    }

    /**
     * 我的投稿上下架（仅本人）
     */
    public function apiMyUploadSetst(int $aid, int $mid, int $id, int $status): void
    {
        $video = ScopeService::query('shortvideo', $aid)->where('mid', $mid)->where('id', $id)->find();
        if (empty($video)) {
            throw new BusinessException('视频不存在', 404, 404);
        }

        Db::name('shortvideo')->where('aid', $aid)->where('id', $id)->update(['status' => $status]);
    }

    /**
     * 我的投稿删除（仅本人；级联评论/点赞）
     */
    public function apiMyUploadDel(int $aid, int $mid, int $id): void
    {
        $video = ScopeService::query('shortvideo', $aid)->where('mid', $mid)->where('id', $id)->find();
        if (empty($video)) {
            throw new BusinessException('视频不存在', 404, 404);
        }

        $this->videoDelete($aid, [$id]);
    }

    /**
     * 播放完成回调（奖励结算口径预留，当前无奖励配置直接返回空）
     *
     * @return array<string, mixed>
     */
    public function apiPlayEnd(int $aid, int $id): array
    {
        $video = ScopeService::query('shortvideo', $aid)->where('id', $id)->find();
        if (empty($video)) {
            throw new BusinessException('视频不存在', 404, 404);
        }

        return ['award_msg' => '', 'award_data' => [], 'has_more' => 0];
    }

    /* ================= 内部工具 ================= */

    /**
     * 创建时间过滤：单日 YYYY-MM-DD 按全天；范围 YYYY-MM-DD,YYYY-MM-DD 按闭区间。
     *
     * @param \think\Db\Query $query
     */
    protected function applyCreatetimeRange($query, string $createtime): void
    {
        $createtime = trim($createtime);
        if ('' === $createtime) {
            return;
        }
        if (false !== strpos($createtime, ',')) {
            [$start, $end] = array_map('trim', explode(',', $createtime, 2));
            $ts = strtotime($start);
            $te = strtotime($end);
            if (false !== $ts) {
                $query->where('createtime', '>=', $ts);
            }
            if (false !== $te) {
                $query->where('createtime', '<', $te + 86400);
            }
            return;
        }
        $day = strtotime(substr($createtime, 0, 10));
        if (false !== $day) {
            $query->where('createtime', '>=', $day)->where('createtime', '<', $day + 86400);
        }
    }

    /**
     * 列排序：orderby 形如 "field|dir"（dir=asc/desc），白名单字段；非法则回退默认。
     *
     * @param \think\Db\Query $query
     * @param array<int, string> $allow
     */
    protected function applyOrderby($query, string $orderby, string $defaultField, string $defaultDir, array $allow = ['createtime', 'view_num', 'zan_num', 'sort', 'id']): void
    {
        $orderby = trim($orderby);
        if ('' !== $orderby) {
            [$field, $dir] = array_pad(explode('|', $orderby, 2), 2, '');
            $field = trim($field);
            $dir   = strtolower(trim($dir));
            if (in_array($field, $allow, true)) {
                $dir = 'asc' === $dir ? 'asc' : 'desc';
                $query->order($field, $dir);

                return;
            }
        }
        $query->order($defaultField, $defaultDir)->order('id', 'desc');
    }

    /**
     * 评论/回评状态文案：1 已审核 / 2 已驳回 / 0 待审核
     */
    protected function commentStatusText(int $status): string
    {
        return 1 === $status ? '已审核' : (2 === $status ? '已驳回' : '待审核');
    }

    /**
     * 分类 id=>name 映射
     *
     * @return array<int, string>
     */
    protected function categoryNameMap(int $aid): array
    {
        $map = ScopeService::query('shortvideo_category', $aid)->column('name', 'id');

        return array_map('strval', $map);
    }

    /**
     * admin 视频行格式化
     *
     * @param array<string, mixed> $row
     * @param array<int, string>   $catNames
     * @return array<string, mixed>
     */
    protected function formatVideoRow(int $aid, array $row, array $catNames): array
    {
        $cid = (int) ($row['cid'] ?? 0);
        $mid = (int) ($row['mid'] ?? 0);
        $bid = (int) ($row['bid'] ?? 0);

        $member = null;
        if ($mid > 0) {
            $member = ScopeService::query('member', $aid)->where('id', $mid)->field('nickname, headimg')->find();
        }

        $bname = '平台';
        if ($bid > 0) {
            $bname = (string) (ScopeService::query('business', $aid)->where('id', $bid)->value('name') ?: '商户' . $bid);
        }

        $commentNum = ScopeService::query('shortvideo_comment', $aid)
            ->where('vid', (int) $row['id'])
            ->where('status', 1)
            ->count();

        $productIds = trim((string) ($row['productids'] ?? ''));

        return [
            'id'             => (int) $row['id'],
            'bid'            => $bid,
            'bname'          => $bname,
            'mid'            => $mid,
            'nickname'       => (string) ($member['nickname'] ?? ''),
            'headimg'        => (string) ($member['headimg'] ?? ''),
            'cid'            => $cid,
            'cname'          => (string) ($catNames[$cid] ?? ''),
            'name'           => (string) ($row['name'] ?? ''),
            'url'            => (string) ($row['url'] ?? ''),
            'video_duration' => (float) ($row['video_duration'] ?? 0),
            'coverimg'       => (string) ($row['coverimg'] ?? ''),
            'description'    => (string) ($row['description'] ?? ''),
            'productids'     => '' === $productIds ? [] : array_map('intval', explode(',', $productIds)),
            'linkurl'        => (string) ($row['linkurl'] ?? ''),
            'linkname'       => (string) ($row['linkname'] ?? ''),
            'comment'        => (int) ($row['comment'] ?? 1),
            'comment_check'  => (int) ($row['comment_check'] ?? 0),
            'comment_num'    => $commentNum,
            'view_num'       => (int) ($row['view_num'] ?? 0),
            'zan_num'        => (int) ($row['zan_num'] ?? 0),
            'share_num'      => (int) ($row['share_num'] ?? 0),
            'sort'           => (int) ($row['sort'] ?? 0),
            'status'         => (int) $row['status'],
            'status_text'    => 1 === (int) $row['status'] ? '已通过' : (2 === (int) $row['status'] ? '已驳回' : '待审核'),
            'reason'         => (string) ($row['reason'] ?? ''),
            'createtime'     => (int) ($row['createtime'] ?? 0),
        ];
    }

    /**
     * admin 分类行格式化
     *
     * @return array<string, mixed>
     */
    protected function formatCategoryRow(int $aid, array $row): array
    {
        $bid   = (int) ($row['bid'] ?? 0);
        $bname = '平台';
        if ($bid > 0) {
            $bname = (string) (ScopeService::query('business', $aid)->where('id', $bid)->value('name') ?: '商户' . $bid);
        }

        $videonum = ScopeService::query('shortvideo', $aid)->where('cid', (int) $row['id'])->count();

        return [
            'id'         => (int) $row['id'],
            'bid'        => $bid,
            'bname'      => $bname,
            'name'       => (string) ($row['name'] ?? ''),
            'status'     => (int) ($row['status'] ?? 0),
            'sort'       => (int) ($row['sort'] ?? 0),
            'videonum'   => $videonum,
            'createtime' => (int) ($row['createtime'] ?? 0),
        ];
    }

    /**
     * C 端视频行格式化
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function formatApiVideo(int $aid, array $row): array
    {
        return [
            'id'             => (int) $row['id'],
            'bid'            => (int) ($row['bid'] ?? 0),
            'cid'            => (int) ($row['cid'] ?? 0),
            'name'           => (string) ($row['name'] ?? ''),
            'src'            => (string) ($row['url'] ?? ''),
            'url'            => (string) ($row['url'] ?? ''),
            'video_duration' => (float) ($row['video_duration'] ?? 0),
            'coverimg'       => (string) ($row['coverimg'] ?? ''),
            'description'    => (string) ($row['description'] ?? ''),
            'linkurl'        => (string) ($row['linkurl'] ?? ''),
            'linkname'       => (string) ($row['linkname'] ?? ''),
            'view_num'       => (int) ($row['view_num'] ?? 0),
            'zan_num'        => (int) ($row['zan_num'] ?? 0),
            'share_num'      => (int) ($row['share_num'] ?? 0),
            'status'         => (int) ($row['status'] ?? 0),
            'reason'         => (string) ($row['reason'] ?? ''),
            'createtime'     => (int) ($row['createtime'] ?? 0),
            'binfo'          => $this->videoOwner($aid, $row),
        ];
    }

    /**
     * 视频归属信息（商户 > 投稿会员 > 平台设置）
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function videoOwner(int $aid, array $row): array
    {
        $bid = (int) ($row['bid'] ?? 0);
        $mid = (int) ($row['mid'] ?? 0);

        if ($bid > 0) {
            $binfo = ScopeService::query('business', $aid)
                ->where('id', $bid)
                ->field('id, name, logo')
                ->find();
            if ($binfo) {
                return ['bid' => (int) $binfo['id'], 'name' => (string) $binfo['name'], 'logo' => (string) ($binfo['logo'] ?? '')];
            }
        }

        if ($mid > 0) {
            $member = ScopeService::query('member', $aid)
                ->where('id', $mid)
                ->field('nickname, headimg')
                ->find();
            if ($member) {
                return ['bid' => 0, 'name' => (string) ($member['nickname'] ?? ''), 'logo' => (string) ($member['headimg'] ?? '')];
            }
        }

        $adminSet = ScopeService::query('admin_set', $aid)->field('name, logo')->find();

        return ['bid' => 0, 'name' => (string) ($adminSet['name'] ?? ''), 'logo' => (string) ($adminSet['logo'] ?? '')];
    }
}
