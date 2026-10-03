<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;

/**
 * 用户论坛服务（批次5-B 任务195）
 *
 * 对照 canzhao/app/controller/Luntan*.php、ApiLuntan.php 重写为服务层：
 * - B 端：帖子列表/审核(通过/驳回)/置顶/删除、版块 CRUD（挂商品/发帖收积分/付费置顶套餐/发帖赠券）、
 *   评论与回评审核、系统设置
 * - C 端：index/ltlist/fatie 状态机/getform/detail/getprolist/zan/subpinglun/pzan/
 *   deltie/delpinglun/delplreply（仅作者自删）/fatielog/置顶支付
 *
 * 帖子状态机：0 待审核 / 1 正常 / 2 驳回 / 3 待支付置顶
 * 置顶支付走自写 payorder（type='luntan_top'，参照 TuangouService payorder 模式，零侵入 PayService）
 */
class LuntanService
{
    /** 帖子状态 */
    public const POST_PENDING  = 0; // 待审核
    public const POST_NORMAL   = 1; // 正常
    public const POST_REJECTED = 2; // 驳回
    public const POST_WAIT_PAY = 3; // 待支付置顶

    /** 挂载商品支持的类型 */
    public const GOODS_TYPES = ['shop', 'collage', 'kanjia', 'seckill', 'tuangou', 'yuyue'];

    public function __construct(
        protected CouponService $couponService,
        protected ScoreService $scoreService
    ) {
    }

    /* ================= 通用工具 ================= */

    /**
     * 论坛系统设置（不存在自动建行）
     */
    public function syssetOf(int $aid): array
    {
        $set = Db::name('luntan_sysset')->where('aid', $aid)->find();

        if (empty($set)) {
            Db::name('luntan_sysset')->insert(['aid' => $aid]);
            $set = Db::name('luntan_sysset')->where('aid', $aid)->find();
        }

        return (array) $set;
    }

    /**
     * C 端总开关校验（status=1 才可用）
     */
    protected function ensureOpen(int $aid): array
    {
        $set = $this->syssetOf($aid);

        if (1 !== (int) ($set['status'] ?? 0)) {
            throw new BusinessException('论坛功能未开启');
        }

        return $set;
    }

    /**
     * 友好时间（对照参考系统 getshowtime）
     */
    protected function showTime(int $time): string
    {
        $time = (int) $time;
        $diff = time() - $time;

        if ($diff < 60) {
            return '刚刚';
        }
        if ($diff < 3600) {
            return (int) ceil($diff / 60) . '分钟前';
        }
        if (date('Ymd') === date('Ymd', $time)) {
            return date('H:i', $time);
        }
        if ($diff < 86400) {
            return '昨天 ' . date('H:i', $time);
        }
        if (date('Y') === date('Y', $time)) {
            return date('m-d H:i', $time);
        }

        return date('Y-m-d H:i', $time);
    }

    /**
     * pics 逗号串 → 数组
     */
    protected function picsArr(?string $pics): array
    {
        $pics = trim((string) $pics);

        return '' === $pics ? [] : array_values(array_filter(explode(',', $pics)));
    }

    /**
     * 时间参数归一（int 时间戳或日期字符串）
     */
    protected function stampOf(mixed $value): int
    {
        if (is_numeric($value)) {
            return (int) $value;
        }

        $value = trim((string) $value);

        return '' === $value ? 0 : (int) strtotime($value);
    }

    /**
     * 版块置顶套餐解码
     */
    protected function topOptionsOf(array $category): array
    {
        $options = json_decode((string) ($category['top_options'] ?? ''), true);

        return is_array($options) ? $options : [];
    }

    /* ================= B端：帖子管理 ================= */

    /**
     * 帖子列表（status '' 全部 / 0待审 / 1正常 / 2驳回 / 3待支付置顶）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function adminPostList(int $aid, array $f): array
    {
        $page     = max(1, (int) ($f['page'] ?? 1));
        $pageSize = (int) ($f['pagesize'] ?? 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        $query = ScopeService::query('luntan', $aid);

        if ('' !== trim((string) ($f['status'] ?? ''))) {
            $query->where('status', (int) $f['status']);
        }
        if (!empty($f['cid'])) {
            $cid  = (int) $f['cid'];
            $cids = Db::name('luntan_category')->where('aid', $aid)->where('pid', $cid)->column('id');
            $cids[] = $cid;
            $query->whereIn('cid', $cids);
        }
        $keyword = trim((string) ($f['keyword'] ?? ''));
        if ('' !== $keyword) {
            $query->where('content|nickname', 'like', '%' . $keyword . '%');
        }
        if (!empty($f['starttime'])) {
            $query->where('createtime', '>=', $this->stampOf($f['starttime']));
        }
        if (!empty($f['endtime'])) {
            $query->where('createtime', '<', $this->stampOf($f['endtime']) + 86400);
        }

        $total = (clone $query)->count();
        $list  = $query->order('is_top', 'desc')->order('id', 'desc')
            ->page($page, $pageSize)->select()->toArray();

        $cateNames = Db::name('luntan_category')->where('aid', $aid)->column('name', 'id');

        foreach ($list as &$row) {
            $row['cname']      = (string) ($cateNames[$row['cid']] ?? '');
            $row['plcount']    = (int) Db::name('luntan_pinglun')->where('aid', $aid)->where('sid', $row['id'])->count();
            $row['pics_arr']   = $this->picsArr((string) $row['pics']);
            $row['createtime_text'] = date('Y-m-d H:i:s', (int) $row['createtime']);
        }
        unset($row);

        return ['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize];
    }

    /**
     * 帖子审核（status=1 通过 / 2 驳回；仅待审 0 → 目标态）
     */
    public function adminPostAudit(int $aid, array $ids, int $status): void
    {
        if (!in_array($status, [self::POST_NORMAL, self::POST_REJECTED], true)) {
            throw new BusinessException('目标状态无效');
        }

        $affected = ScopeService::query('luntan', $aid)
            ->whereIn('id', $ids)
            ->where('status', self::POST_PENDING)
            ->update(['status' => $status]);

        if ($affected <= 0) {
            throw new BusinessException('没有可审核的帖子（仅待审核状态可操作）');
        }
    }

    /**
     * 帖子置顶/取消置顶
     */
    public function adminPostSetTop(int $aid, array $ids, int $isTop): void
    {
        ScopeService::query('luntan', $aid)
            ->whereIn('id', $ids)
            ->update(['is_top' => $isTop ? 1 : 0]);
    }

    /**
     * 帖子删除（连带评论/回评/置顶订单）
     */
    public function adminPostDelete(int $aid, array $ids): void
    {
        ScopeService::query('luntan', $aid)->whereIn('id', $ids)->delete();
        ScopeService::query('luntan_pinglun', $aid)->whereIn('sid', $ids)->delete();
        ScopeService::query('luntan_pinglun_reply', $aid)->whereIn('sid', $ids)->delete();
        ScopeService::query('luntan_order', $aid)->whereIn('luntan_id', $ids)->delete();
    }

    /**
     * 帖子详情（后台编辑回显；pics 拆数组）
     */
    public function adminPostDetail(int $aid, int $id): array
    {
        $row = ScopeService::query('luntan', $aid)->where('id', $id)->find();

        if (empty($row)) {
            throw new BusinessException('帖子不存在', 404, 404);
        }

        $row['pics_arr'] = $this->picsArr((string) $row['pics']);

        return (array) $row;
    }

    /**
     * 帖子新增/编辑（后台代发）
     *
     * 入参：id/mid/cid/content/pics(array|string)/video/readcount/zan/status
     * - 编辑时 mid 变更则按会员重新带出昵称/头像
     * - 新增时按 mid 带出昵称/头像，写 aid/createtime
     */
    public function adminPostSave(int $aid, array $data): int
    {
        $id     = (int) ($data['id'] ?? 0);
        $mid    = (int) ($data['mid'] ?? 0);
        $cid    = (int) ($data['cid'] ?? 0);
        $status = (int) ($data['status'] ?? 1);

        if (!in_array($status, [self::POST_PENDING, self::POST_NORMAL, self::POST_REJECTED, self::POST_WAIT_PAY], true)) {
            $status = self::POST_NORMAL;
        }

        // pics 归一为逗号串（数组或逗号串皆可）
        $pics = $data['pics'] ?? '';
        if (is_array($pics)) {
            $pics = implode(',', array_values(array_filter(array_map('strval', $pics))));
        } else {
            $pics = trim((string) $pics);
        }

        $row = [
            'cid'        => $cid,
            'mid'        => $mid,
            'content'    => trim((string) ($data['content'] ?? '')),
            'pics'       => $pics,
            'video'      => trim((string) ($data['video'] ?? '')),
            'readcount'  => max(0, (int) ($data['readcount'] ?? 0)),
            'zan'        => max(0, (int) ($data['zan'] ?? 0)),
            'status'     => $status,
        ];

        // 会员昵称/头像：mid>0 时按会员表带出
        if ($mid > 0) {
            $member = Db::name('member')->where('aid', $aid)->where('id', $mid)->find();
            if (empty($member)) {
                throw new BusinessException('发帖会员不存在');
            }
            $row['nickname'] = (string) ($member['nickname'] ?? '');
            $row['headimg']  = (string) ($member['headimg'] ?? '');
        }

        if ($id > 0) {
            $exists = ScopeService::query('luntan', $aid)->where('id', $id)->find();
            if (empty($exists)) {
                throw new BusinessException('帖子不存在', 404, 404);
            }

            // mid 改为 0（管理员代发）时清空会员身份
            if (0 === $mid) {
                $row['nickname'] = '';
                $row['headimg']  = '';
            }

            Db::name('luntan')->where('aid', $aid)->where('id', $id)->update($row);

            return $id;
        }

        $row['aid']        = $aid;
        $row['createtime'] = time();
        if (0 === $mid) {
            $row['nickname'] = '管理员';
            $row['headimg']  = '';
        }

        return (int) Db::name('luntan')->insertGetId($row);
    }

    /* ================= B端：版块管理 ================= */

    /**
     * 版块树（二级：父级挂 children）
     */
    public function adminCategoryList(int $aid): array
    {
        $all = ScopeService::query('luntan_category', $aid)
            ->order('sort', 'desc')->order('id', 'asc')
            ->select()->toArray();

        $postCount = Db::name('luntan')->where('aid', $aid)->group('cid')->column('count(*)', 'cid');

        $tree = [];
        $children = [];

        foreach ($all as &$row) {
            $row['postnum']     = (int) ($postCount[$row['id']] ?? 0);
            $row['top_options'] = $this->topOptionsOf($row);
            $row['starttime_text'] = $row['starttime'] > 0 ? date('Y-m-d H:i', (int) $row['starttime']) : '';
            $row['endtime_text']   = $row['endtime'] > 0 ? date('Y-m-d H:i', (int) $row['endtime']) : '';

            if (0 === (int) $row['pid']) {
                $row['children'] = [];
                $tree[$row['id']] = $row;
            } else {
                $children[$row['pid']][] = $row;
            }
        }
        unset($row);

        foreach ($children as $pid => $rows) {
            if (isset($tree[$pid])) {
                $tree[$pid]['children'] = $rows;
            }
        }

        return array_values($tree);
    }

    /**
     * 版块保存（新增/编辑；含挂商品/发帖收费/置顶套餐/赠券扩展配置）
     */
    public function adminCategorySave(int $aid, array $data): int
    {
        $name = trim((string) ($data['name'] ?? ''));

        if ('' === $name) {
            throw new BusinessException('请填写版块名称');
        }

        $id  = (int) ($data['id'] ?? 0);
        $pid = (int) ($data['pid'] ?? 0);

        if ($pid > 0) {
            if ($pid === $id) {
                throw new BusinessException('父级版块不能选择自身');
            }

            $parent = ScopeService::query('luntan_category', $aid)->where('id', $pid)->find();

            if (empty($parent) || 0 !== (int) $parent['pid']) {
                throw new BusinessException('父级版块不存在或不是顶级版块（仅支持二级）');
            }
        }

        // 置顶套餐（[{hour, price}]）
        $topOptions = [];
        foreach ((array) ($data['top_options'] ?? []) as $item) {
            $hour  = (int) ($item['hour'] ?? 0);
            $price = round((float) ($item['price'] ?? 0), 2);

            if ($hour > 0 && $price >= 0) {
                $topOptions[] = ['hour' => $hour, 'price' => $price];
            }
        }

        // 赠券 ID（数组或逗号串归一）
        $couponRaw = $data['coupon_ids'] ?? '';
        $couponIds = is_array($couponRaw) ? $couponRaw : explode(',', (string) $couponRaw);
        $couponIds = array_values(array_filter(array_map('intval', $couponRaw === '' ? [] : $couponIds)));

        $row = [
            'pid'          => $pid,
            'name'         => $name,
            'pic'          => trim((string) ($data['pic'] ?? '')),
            'banner'       => trim((string) ($data['banner'] ?? '')),
            'sort'         => (int) ($data['sort'] ?? 1),
            'status'       => (int) ($data['status'] ?? 1) ? 1 : 0,
            'goods_status' => (int) ($data['goods_status'] ?? 0) ? 1 : 0,
            'post_pay'     => (int) ($data['post_pay'] ?? 0) ? 1 : 0,
            'post_score'   => max(0, (int) ($data['post_score'] ?? 0)),
            'top_options'  => json_encode($topOptions),
            'coupon_ids'   => implode(',', $couponIds),
            'starttime'    => $this->stampOf($data['starttime'] ?? 0),
            'endtime'      => $this->stampOf($data['endtime'] ?? 0),
            'limitnum'     => max(0, (int) ($data['limitnum'] ?? 0)),
        ];

        if ($row['post_pay'] && $row['post_score'] <= 0) {
            throw new BusinessException('开启发帖收费时必须填写发帖收取的积分');
        }

        if ($id > 0) {
            $exists = ScopeService::query('luntan_category', $aid)->where('id', $id)->find();

            if (empty($exists)) {
                throw new BusinessException('版块不存在', 404, 404);
            }

            // 顶级版块改为二级时，不允许已有子级
            if (0 === (int) $exists['pid'] && $pid > 0) {
                $childNum = (int) ScopeService::query('luntan_category', $aid)->where('pid', $id)->count();

                if ($childNum > 0) {
                    throw new BusinessException('该版块下已有子版块，不能改为二级版块');
                }
            }

            Db::name('luntan_category')->where('aid', $aid)->where('id', $id)->update($row);

            return $id;
        }

        $row['aid']        = $aid;
        $row['createtime'] = time();

        return (int) Db::name('luntan_category')->insertGetId($row);
    }

    /**
     * 版块删除（有子级或帖子时拒绝）
     */
    public function adminCategoryDelete(int $aid, array $ids): void
    {
        foreach ($ids as $id) {
            $childNum = (int) ScopeService::query('luntan_category', $aid)->where('pid', $id)->count();

            if ($childNum > 0) {
                throw new BusinessException('版块下存在子版块，请先删除子版块');
            }

            $postNum = (int) ScopeService::query('luntan', $aid)->where('cid', $id)->count();

            if ($postNum > 0) {
                throw new BusinessException('版块下存在帖子，请先删除帖子');
            }
        }

        ScopeService::query('luntan_category', $aid)->whereIn('id', $ids)->delete();
    }

    /* ================= B端：评论/回评管理 ================= */

    /**
     * 评论列表（status '' 全部 / 0待审 / 1正常；附帖子摘要与回评数）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function adminPinglunList(int $aid, array $f): array
    {
        $page     = max(1, (int) ($f['page'] ?? 1));
        $pageSize = (int) ($f['pagesize'] ?? 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        $query = ScopeService::query('luntan_pinglun', $aid);

        if ('' !== trim((string) ($f['status'] ?? ''))) {
            $query->where('status', (int) $f['status']);
        }
        if (!empty($f['sid'])) {
            $query->where('sid', (int) $f['sid']);
        }
        $keyword = trim((string) ($f['keyword'] ?? ''));
        if ('' !== $keyword) {
            $query->where('content|nickname', 'like', '%' . $keyword . '%');
        }
        if (!empty($f['starttime'])) {
            $query->where('createtime', '>=', $this->stampOf($f['starttime']));
        }
        if (!empty($f['endtime'])) {
            $query->where('createtime', '<', $this->stampOf($f['endtime']) + 86400);
        }

        $total = (clone $query)->count();
        $list  = $query->order('id', 'desc')->page($page, $pageSize)->select()->toArray();

        foreach ($list as &$row) {
            $post = Db::name('luntan')->where('aid', $aid)->where('id', $row['sid'])->field('id,content,nickname,pics,video')->find();
            $row['post_content'] = (string) ($post['content'] ?? '');
            $row['post_pics']    = $this->picsArr((string) ($post['pics'] ?? ''));
            $row['post_video']    = (string) ($post['video'] ?? '');
            $row['replycount']   = (int) Db::name('luntan_pinglun_reply')->where('aid', $aid)->where('pid', $row['id'])->count();
            $row['createtime_text'] = date('Y-m-d H:i:s', (int) $row['createtime']);
        }
        unset($row);

        return ['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize];
    }

    /**
     * 评论审核（status 0/1）
     */
    public function adminPinglunAudit(int $aid, array $ids, int $status): void
    {
        if (!in_array($status, [0, 1], true)) {
            throw new BusinessException('目标状态无效');
        }

        ScopeService::query('luntan_pinglun', $aid)->whereIn('id', $ids)->update(['status' => $status]);
    }

    /**
     * 评论删除（连带回评）
     */
    public function adminPinglunDelete(int $aid, array $ids): void
    {
        ScopeService::query('luntan_pinglun', $aid)->whereIn('id', $ids)->delete();
        ScopeService::query('luntan_pinglun_reply', $aid)->whereIn('pid', $ids)->delete();
    }

    /**
     * 回评列表
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function adminPlreplyList(int $aid, array $f): array
    {
        $page     = max(1, (int) ($f['page'] ?? 1));
        $pageSize = (int) ($f['pagesize'] ?? 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        $query = ScopeService::query('luntan_pinglun_reply', $aid);

        if ('' !== trim((string) ($f['status'] ?? ''))) {
            $query->where('status', (int) $f['status']);
        }
        $keyword = trim((string) ($f['keyword'] ?? ''));
        if ('' !== $keyword) {
            $query->where('content|nickname', 'like', '%' . $keyword . '%');
        }
        if (!empty($f['starttime'])) {
            $query->where('createtime', '>=', $this->stampOf($f['starttime']));
        }
        if (!empty($f['endtime'])) {
            $query->where('createtime', '<', $this->stampOf($f['endtime']) + 86400);
        }

        $total = (clone $query)->count();
        $list  = $query->order('id', 'desc')->page($page, $pageSize)->select()->toArray();

        foreach ($list as &$row) {
            $pl = Db::name('luntan_pinglun')->where('aid', $aid)->where('id', $row['pid'])->field('id,content')->find();
            $row['pinglun_content'] = (string) ($pl['content'] ?? '');
            $row['createtime_text'] = date('Y-m-d H:i:s', (int) $row['createtime']);
        }
        unset($row);

        return ['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize];
    }

    /**
     * 回评审核（status 0/1）
     */
    public function adminPlreplyAudit(int $aid, array $ids, int $status): void
    {
        if (!in_array($status, [0, 1], true)) {
            throw new BusinessException('目标状态无效');
        }

        ScopeService::query('luntan_pinglun_reply', $aid)->whereIn('id', $ids)->update(['status' => $status]);
    }

    /**
     * 回评删除
     */
    public function adminPlreplyDelete(int $aid, array $ids): void
    {
        ScopeService::query('luntan_pinglun_reply', $aid)->whereIn('id', $ids)->delete();
    }

    /* ================= B端：系统设置 ================= */

    /**
     * 系统设置读取（sendtj 转数组）
     */
    public function adminSysset(int $aid): array
    {
        $info = $this->syssetOf($aid);

        $info['sendtj'] = array_values(array_filter(explode(',', (string) $info['sendtj'])));
        $info['sendcheck']    = (int) $info['sendcheck'];
        $info['pingluncheck'] = (int) $info['pingluncheck'];
        $info['cansave']      = (int) $info['cansave'];
        $info['status']       = (int) $info['status'];

        // 发帖条件候选：所有人(-1) + 会员等级（对齐点大 sendtj 多选 chips）
        $defaultCid = (int) Db::name('member_level_category')->where('aid', $aid)->where('isdefault', 1)->value('id');
        $levels = Db::name('member_level')
            ->where('aid', $aid)
            ->where('cid', $defaultCid)
            ->order('sort,id')
            ->field('id,name')
            ->select()->toArray();
        if (empty($levels)) {
            $levels = Db::name('member_level')->where('aid', $aid)->order('sort,id')->field('id,name')->select()->toArray();
        }
        $info['member_levels'] = array_values($levels);

        return $info;
    }

    /**
     * 系统设置保存
     */
    public function adminSyssetSave(int $aid, array $data): void
    {
        $this->syssetOf($aid); // 确保配置行存在（首次保存）

        $sendtj = (array) ($data['sendtj'] ?? ['-1']);
        $sendtj = array_values(array_filter(array_map('strval', $sendtj), static fn ($v) => '' !== trim($v)));

        if (empty($sendtj)) {
            $sendtj = ['-1'];
        }

        $row = [
            'title'        => trim((string) ($data['title'] ?? '')),
            'pic'          => trim((string) ($data['pic'] ?? '')),
            'picurl'       => trim((string) ($data['picurl'] ?? '')),
            'sendtj'       => implode(',', $sendtj),
            'sendcheck'    => in_array((int) ($data['sendcheck'] ?? 0), [0, 1, 2], true) ? (int) $data['sendcheck'] : 0,
            'pingluncheck' => (int) ($data['pingluncheck'] ?? 0) ? 1 : 0,
            'cansave'      => (int) ($data['cansave'] ?? 0) ? 1 : 0,
            'status'       => (int) ($data['status'] ?? 0) ? 1 : 0,
        ];

        Db::name('luntan_sysset')->where('aid', $aid)->update($row);
    }

    /* ================= C端：列表/详情 ================= */

    /**
     * 论坛首页（帖子列表 status=1；第一页附 sysset 与一级版块）
     *
     * @return array<string, mixed>
     */
    public function apiIndex(int $aid, int $mid, int $page): array
    {
        $sysset = $this->ensureOpen($aid);

        $list = ScopeService::query('luntan', $aid)
            ->where('status', self::POST_NORMAL)
            ->order('is_top', 'desc')->order('id', 'desc')
            ->page(max(1, $page), 20)
            ->select()->toArray();

        foreach ($list as &$row) {
            $row = $this->decoratePost($aid, $mid, $row);
        }
        unset($row);

        $rdata = ['datalist' => $list];

        if ($page <= 1) {
            $clist = ScopeService::query('luntan_category', $aid)
                ->where('pid', 0)->where('status', 1)
                ->order('sort', 'desc')->order('id', 'asc')
                ->select()->toArray();

            $rdata['sysset'] = $sysset;
            $rdata['title']  = (string) $sysset['title'];
            $rdata['clist']  = $clist;
        }

        return $rdata;
    }

    /**
     * 版块帖子列表（含二级版块 tab；keyword 搜索）
     *
     * @return array<string, mixed>
     */
    public function apiLtlist(int $aid, int $mid, int $cid, string $keyword, int $page): array
    {
        $sysset = $this->ensureOpen($aid);

        $title  = (string) $sysset['title'];
        $banner = '';
        $categoryTabs = [];

        $query = ScopeService::query('luntan', $aid)->where('status', self::POST_NORMAL);

        if ($cid > 0) {
            $cdata = ScopeService::query('luntan_category', $aid)->where('id', $cid)->find();

            if (empty($cdata)) {
                throw new BusinessException('版块不存在', 404, 404);
            }

            $title  = (string) $cdata['name'];
            $banner = (string) ($cdata['banner'] ?? '');

            // 二级版块联动：传父级 → 父级+全部子级；传子级 → 仅子级并回显同级 tab
            if (0 === (int) $cdata['pid']) {
                $subList = ScopeService::query('luntan_category', $aid)
                    ->where('pid', $cid)->where('status', 1)
                    ->order('sort', 'desc')->order('id', 'asc')->select()->toArray();

                $cids = [$cid];

                if (!empty($subList)) {
                    $categoryTabs = [['value' => $cid, 'label' => '全部']];

                    foreach ($subList as $sub) {
                        $categoryTabs[] = ['value' => (int) $sub['id'], 'label' => (string) $sub['name']];
                        $cids[] = (int) $sub['id'];
                    }
                }
            } else {
                $pid = (int) $cdata['pid'];
                $subList = ScopeService::query('luntan_category', $aid)
                    ->where('pid', $pid)->where('status', 1)
                    ->order('sort', 'desc')->order('id', 'asc')->select()->toArray();

                $categoryTabs = [['value' => $pid, 'label' => '全部']];

                foreach ($subList as $sub) {
                    $categoryTabs[] = ['value' => (int) $sub['id'], 'label' => (string) $sub['name']];
                }

                $cids = [$cid];
            }

            $query->whereIn('cid', $cids);
        }

        if ('' !== $keyword) {
            $query->where('content|nickname', 'like', '%' . $keyword . '%');
        }

        $list = $query->order('id', 'desc')->page(max(1, $page), 20)->select()->toArray();

        foreach ($list as &$row) {
            $row = $this->decoratePost($aid, $mid, $row);
        }
        unset($row);

        return [
            'cid'          => $cid,
            'title'        => $title,
            'banner'       => $banner,
            'datalist'     => $list,
            'sysset'       => $sysset,
            'categoryTabs' => $categoryTabs,
        ];
    }

    /**
     * 帖子详情（浏览量+1；附评论/回评、点赞状态）
     *
     * @return array<string, mixed>
     */
    public function apiDetail(int $aid, int $mid, int $id, int $page): array
    {
        $this->ensureOpen($aid);

        $detail = ScopeService::query('luntan', $aid)->where('id', $id)->where('status', self::POST_NORMAL)->find();

        if (empty($detail)) {
            throw new BusinessException('帖子不存在或已删除', 404, 404);
        }

        Db::name('luntan')->where('aid', $aid)->where('id', $id)->inc('readcount')->update();
        $detail['readcount'] = (int) $detail['readcount'] + 1;

        $detail['pics']     = $this->picsArr((string) $detail['pics']);
        $detail['showtime'] = $this->showTime((int) $detail['createtime']);
        $this->attachMountedGoods($aid, $detail);

        // 评论（status=1）+ 回评
        $datalist = Db::name('luntan_pinglun')
            ->where('aid', $aid)->where('sid', $id)->where('status', 1)
            ->page(max(1, $page), 20)->order('createtime', 'desc')
            ->select()->toArray();

        foreach ($datalist as &$pl) {
            $pl['iszan'] = (int) Db::name('luntan_pzanlog')->where('aid', $aid)->where('pid', $pl['id'])->where('mid', $mid)->count() > 0 ? 1 : 0;

            $replylist = Db::name('luntan_pinglun_reply')
                ->where('aid', $aid)->where('pid', $pl['id'])->where('status', 1)
                ->field('id,mid,nickname,headimg,content,createtime')
                ->order('createtime', 'asc')->select()->toArray();

            foreach ($replylist as &$rp) {
                $rp['showtime'] = $this->showTime((int) $rp['createtime']);
            }
            unset($rp);

            $pl['replylist'] = $replylist;
            $pl['showtime']  = $this->showTime((int) $pl['createtime']);
        }
        unset($pl);

        $plcount = (int) Db::name('luntan_pinglun')->where('aid', $aid)->where('sid', $id)->where('status', 1)->count();
        $iszan   = (int) Db::name('luntan_zanlog')->where('aid', $aid)->where('sid', $id)->where('mid', $mid)->count() > 0 ? 1 : 0;

        return [
            'mid'       => $mid,
            'detail'    => $detail,
            'datalist'  => $datalist,
            'plcount'   => $plcount,
            'iszan'     => $iszan,
            'pinglunstatus' => true,
        ];
    }

    /**
     * 帖子挂载商品列表（goods_info JSON → 各商品表实体）
     *
     * @return array{list: array}
     */
    public function apiGetprolist(int $aid, int $id): array
    {
        $this->ensureOpen($aid);

        $detail = ScopeService::query('luntan', $aid)->where('id', $id)->where('status', self::POST_NORMAL)->find();

        if (empty($detail)) {
            throw new BusinessException('帖子不存在或已删除', 404, 404);
        }

        return ['list' => $this->mountedGoodsList($aid, (string) ($detail['goods_info'] ?? ''))];
    }

    /**
     * goods_info JSON 展开为商品列表（各类型商品表统一输出）
     */
    protected function mountedGoodsList(int $aid, string $goodsInfoJson): array
    {
        $goodsInfo = json_decode($goodsInfoJson, true);

        if (!is_array($goodsInfo)) {
            return [];
        }

        $datalist = [];

        foreach ($goodsInfo as $type => $proids) {
            $type = (string) $type;

            if (!in_array($type, self::GOODS_TYPES, true) || empty($proids)) {
                continue;
            }

            $proids = array_values(array_filter(array_map('intval', (array) $proids)));

            if (empty($proids)) {
                continue;
            }

            $list = Db::name($type . '_product')
                ->where('aid', $aid)->whereIn('id', $proids)
                ->field('id,name,pic,sell_price,sales,status')
                ->select()->toArray();

            foreach ($list as &$item) {
                $item['type'] = $type;
            }
            unset($item);

            $datalist = array_merge($datalist, $list);
        }

        return $datalist;
    }

    /**
     * 列表/详情统一装饰：评论数、点赞状态、图片数组、友好时间、挂载商品摘要
     */
    protected function decoratePost(int $aid, int $mid, array $row): array
    {
        $row['plcount']  = (int) Db::name('luntan_pinglun')->where('aid', $aid)->where('sid', $row['id'])->where('status', 1)->count();
        $row['iszan']    = (int) Db::name('luntan_zanlog')->where('aid', $aid)->where('sid', $row['id'])->where('mid', $mid)->count() > 0 ? 1 : 0;
        $row['pics']     = $this->picsArr((string) $row['pics']);
        $row['showtime'] = $this->showTime((int) $row['createtime']);

        $this->attachMountedGoods($aid, $row);

        return $row;
    }

    /**
     * 挂载商品摘要（首个有效商品）
     */
    protected function attachMountedGoods(int $aid, array &$row): void
    {
        $row['pronum'] = 0;

        $goodsInfo = json_decode((string) ($row['goods_info'] ?? ''), true);

        if (!is_array($goodsInfo)) {
            return;
        }

        foreach ($goodsInfo as $type => $proids) {
            $type = (string) $type;

            if (!in_array($type, self::GOODS_TYPES, true) || empty($proids)) {
                continue;
            }

            $proids = array_values(array_filter(array_map('intval', (array) $proids)));

            if (empty($proids)) {
                continue;
            }

            $row['pronum'] += (int) Db::name($type . '_product')->where('aid', $aid)->whereIn('id', $proids)->count();

            if (empty($row['product'])) {
                $product = Db::name($type . '_product')
                    ->where('aid', $aid)->where('id', $proids[0])
                    ->field('id,name,pic,sell_price,sales,status')
                    ->find();

                if (!empty($product)) {
                    $product['type'] = $type;
                    $row['product']  = $product;
                }
            }
        }
    }

    /* ================= C端：发帖状态机 ================= */

    /**
     * 发帖表单数据（版块树 + 置顶套餐 + 收费标识 + 发帖权限预判）
     *
     * @return array<string, mixed>
     */
    public function apiFatieForm(int $aid, int $mid): array
    {
        $sysset = $this->ensureOpen($aid);

        $clist = ScopeService::query('luntan_category', $aid)
            ->where('pid', 0)->where('status', 1)
            ->order('sort', 'desc')->order('id', 'asc')
            ->select()->toArray();

        $subList = ScopeService::query('luntan_category', $aid)
            ->where('pid', '>', 0)->where('status', 1)
            ->order('sort', 'desc')->order('id', 'asc')
            ->select()->toArray();

        $grouped = [];

        foreach ($subList as $sub) {
            $sub['top_options'] = $this->topOptionsOf($sub);
            $grouped[$sub['pid']][] = $sub;
        }

        foreach ($clist as &$item) {
            $item['top_options'] = $this->topOptionsOf($item);
            $item['children']    = $grouped[$item['id']] ?? [];
        }
        unset($item);

        $member = Db::name('member')->where('aid', $aid)->where('id', $mid)->find();
        $canSend = $this->checkSendPermission($sysset, (array) $member, $msg);

        return [
            'clist'        => $clist,
            'sendcheck'    => (int) $sysset['sendcheck'],
            'can_send'     => $canSend && 2 !== (int) $sysset['sendcheck'],
            'send_msg'     => 2 === (int) $sysset['sendcheck'] ? '管理员已关闭发帖' : $msg,
        ];
    }

    /**
     * 版块详情（发帖附加表单：扩展配置随版块返回）
     */
    public function apiGetform(int $aid, int $cid): array
    {
        $this->ensureOpen($aid);

        $category = ScopeService::query('luntan_category', $aid)->where('id', $cid)->find();

        if (empty($category)) {
            throw new BusinessException('版块不存在', 404, 404);
        }

        $category['top_options']  = $this->topOptionsOf($category);
        $category['starttime_text'] = $category['starttime'] > 0 ? date('Y-m-d H:i', (int) $category['starttime']) : '';
        $category['endtime_text']   = $category['endtime'] > 0 ? date('Y-m-d H:i', (int) $category['endtime']) : '';
        $category['children'] = ScopeService::query('luntan_category', $aid)
            ->where('pid', $cid)->where('status', 1)
            ->order('sort', 'desc')->order('id', 'asc')->select()->toArray();

        return $category;
    }

    /**
     * 发帖权限校验（sendtj：-1 全部 / 0 关注 / 会员等级 ID）
     */
    protected function checkSendPermission(array $sysset, array $member, ?string &$msg = ''): bool
    {
        $sendtj = explode(',', (string) ($sysset['sendtj'] ?? '-1'));

        if (in_array('-1', $sendtj, true) || in_array((string) ($member['levelid'] ?? ''), $sendtj, true)) {
            $msg = '';

            return true;
        }

        if (in_array('0', $sendtj, true)) {
            if (1 === (int) ($member['subscribe'] ?? 0)) {
                $msg = '';

                return true;
            }

            $msg = '请先关注公众号后再发帖';

            return false;
        }

        $msg = '您没有发帖权限';

        return false;
    }

    /**
     * 发帖（核心状态机）
     *
     * 流程：sendtj 权限校验 → sendcheck=2 拒绝 → sendcheck=1 则 status=0 待审否则 1
     *       → 选付费置顶套餐则 status=3 待支付 → 可扣发帖积分 post_pay → 发帖赠券（限时限量防超发）
     *
     * @param array $input cid/cid2/content/pics/video/selected_goods/top_index
     * @return array<string, mixed>
     */
    public function apiFatie(int $aid, int $mid, array $input): array
    {
        $sysset = $this->ensureOpen($aid);

        $member = Db::name('member')->where('aid', $aid)->where('id', $mid)->find();

        if (empty($member)) {
            throw new BusinessException('会员不存在', 404, 404);
        }

        if (!$this->checkSendPermission($sysset, (array) $member, $permMsg)) {
            throw new BusinessException((string) $permMsg);
        }

        if (2 === (int) $sysset['sendcheck']) {
            throw new BusinessException('管理员已关闭发帖');
        }

        $content = trim((string) ($input['content'] ?? ''));
        $pics    = $input['pics'] ?? '';
        $video   = trim((string) ($input['video'] ?? ''));
        $cid     = (int) ($input['cid'] ?? 0);

        if ('' === $content && '' === trim(is_array($pics) ? implode(',', $pics) : (string) $pics) && '' === $video) {
            throw new BusinessException('请输入帖子内容');
        }

        // 版块校验（一级）
        $category = ScopeService::query('luntan_category', $aid)->where('id', $cid)->where('status', 1)->find();

        if (empty($category)) {
            throw new BusinessException('版块不存在或已关闭');
        }

        // 二级版块：一级下存在启用子级时必须选择二级
        $childIds = ScopeService::query('luntan_category', $aid)
            ->where('pid', $cid)->where('status', 1)->column('id');

        if (!empty($childIds)) {
            $cid2 = (int) ($input['cid2'] ?? 0);

            if ($cid2 <= 0) {
                throw new BusinessException('请选择二级版块');
            }

            if (!in_array($cid2, array_map('intval', $childIds), true)) {
                throw new BusinessException('二级版块不存在或已关闭');
            }

            $category = (array) ScopeService::query('luntan_category', $aid)->where('id', $cid2)->find();
            $cid      = $cid2;
        }

        // 发帖收积分（余额不足拒绝）
        if (1 === (int) $category['post_pay'] && (int) $category['post_score'] > 0) {
            $this->scoreService->deductForOrder($aid, $mid, (int) $category['post_score'], '论坛发帖收费');
        }

        // 状态机：审核策略 → 置顶套餐覆盖
        $status = 1 === (int) $sysset['sendcheck'] ? self::POST_PENDING : self::POST_NORMAL;
        $msg    = self::POST_PENDING === $status ? '提交成功，请等待审核' : '发布成功';

        $topSelected = (int) ($input['top_index'] ?? -1);
        $topOptions  = $this->topOptionsOf($category);
        $topOption   = $topOptions[$topSelected] ?? null;

        if (null !== $topOption && (float) ($topOption['price'] ?? 0) >= 0 && (int) ($topOption['hour'] ?? 0) > 0) {
            $status = self::POST_WAIT_PAY;
            $msg    = '提交成功，请完成置顶支付';
        } else {
            $topOption = null;
        }

        $picsStr = is_array($pics) ? implode(',', array_map('strval', $pics)) : trim((string) $pics);

        $data = [
            'aid'        => $aid,
            'cid'        => $cid,
            'mid'        => $mid,
            'nickname'   => (string) ($member['nickname'] ?? ''),
            'headimg'    => (string) ($member['headimg'] ?? ''),
            'content'    => $content,
            'pics'       => $picsStr,
            'video'      => $video,
            'createtime' => time(),
            'status'     => $status,
            'goods_info' => '',
        ];

        // 挂载商品（版块开启时）
        if (1 === (int) ($category['goods_status'] ?? 0) && !empty($input['selected_goods'])) {
            $goodsInfo = [];

            foreach ((array) $input['selected_goods'] as $type => $ids) {
                $type = (string) $type;

                if (!in_array($type, self::GOODS_TYPES, true)) {
                    continue;
                }

                $ids = array_values(array_filter(array_map('intval', (array) $ids)));

                if (!empty($ids)) {
                    $goodsInfo[$type] = $ids;
                }
            }

            $data['goods_info'] = json_encode($goodsInfo);
        }

        $topOrderId = 0;
        $ordernum   = '';
        $payorderId = 0;

        Db::transaction(function () use ($aid, $mid, &$data, $category, $topOption, &$topOrderId, &$ordernum, &$payorderId) {
            $postId = (int) Db::name('luntan')->insertGetId($data);
            $data['id'] = $postId;

            // 发帖赠券（限时限量；member 行锁防并发超发）
            $this->givePostCoupons($aid, $mid, $category);

            // 置顶套餐 → 业务单 + 支付单（type='luntan_top'）
            if (null !== $topOption) {
                $hour  = (int) $topOption['hour'];
                $price = round((float) $topOption['price'], 2);

                $ordernum = 'LT' . date('YmdHis') . str_pad((string) ($postId % 100000000), 8, '0', STR_PAD_LEFT);

                $topOrderId = (int) Db::name('luntan_order')->insertGetId([
                    'aid'             => $aid,
                    'mid'             => $mid,
                    'luntan_id'       => $postId,
                    'title'           => '论坛置顶' . $hour . '小时',
                    'ordernum'        => $ordernum,
                    'product_price'   => $price,
                    'totalprice'      => $price,
                    'top_hour'        => $hour,
                    'top_expire_time' => time() + $hour * 3600,
                    'status'          => 0,
                    'createtime'      => time(),
                ]);

                $payorderId = (int) Db::name('payorder')->insertGetId([
                    'aid'        => $aid,
                    'bid'        => 0,
                    'mid'        => $mid,
                    'orderid'    => $topOrderId,
                    'ordernum'   => $ordernum,
                    'title'      => '论坛置顶' . $hour . '小时',
                    'money'      => $price,
                    'score'      => 0,
                    'type'       => 'luntan_top',
                    'createtime' => time(),
                    'status'     => 0,
                    'platform'   => 'wx',
                ]);
            }
        });

        return [
            'id'         => (int) $data['id'],
            'status'     => $status,
            'msg'        => $msg,
            'need_pay'   => null !== $topOption,
            'orderid'    => $topOrderId,
            'ordernum'   => $ordernum,
            'payorderid' => $payorderId,
        ];
    }

    /**
     * 发帖赠券（版块配置限时限量；member 行锁串行化防超发；单券失败不阻断发帖）
     */
    protected function givePostCoupons(int $aid, int $mid, array $category): void
    {
        $couponIds = trim((string) ($category['coupon_ids'] ?? ''));

        if ('' === $couponIds) {
            return;
        }

        $now   = time();
        $start = (int) ($category['starttime'] ?? 0);
        $end   = (int) ($category['endtime'] ?? 0);

        if (($start > 0 && $now < $start) || ($end > 0 && $now > $end)) {
            return; // 不在赠券时段
        }

        // 以 member 行锁串行化同一会员的并发发帖，保证 limitnum 防超发
        Db::name('member')->where('aid', $aid)->where('id', $mid)->lock(true)->find();

        $given = (int) Db::name('luntan_give_couponlog')
            ->where('aid', $aid)->where('mid', $mid)->where('cateid', (int) $category['id'])
            ->count();

        $limit = (int) ($category['limitnum'] ?? 0);

        if ($limit > 0 && $given >= $limit) {
            return; // 已达每人限次
        }

        foreach (explode(',', $couponIds) as $couponId) {
            $couponId = (int) trim($couponId);

            if ($couponId <= 0) {
                continue;
            }

            try {
                $this->couponService->receive($aid, $mid, $couponId);
            } catch (\Throwable $e) {
                // 券失效/领完等不阻断发帖
            }
        }

        Db::name('luntan_give_couponlog')->insert([
            'aid'        => $aid,
            'mid'        => $mid,
            'cateid'     => (int) $category['id'],
            'couponid'   => $couponIds,
            'createtime' => $now,
        ]);
    }

    /**
     * 置顶套餐支付（mock 模式即时入账；真实模式本期桩）
     *
     * 入账：luntan_order status 0→1 + payorder(status=1) + 帖子 status 3→1 且 is_top=1
     *
     * @return array<string, mixed>
     */
    public function apiPayTop(int $aid, int $mid, int $orderId, string $payType = 'wechat'): array
    {
        if (!isset(PayService::PAY_TYPE_IDS[$payType])) {
            throw new BusinessException('不支持的支付方式');
        }

        return Db::transaction(function () use ($aid, $mid, $orderId, $payType) {
            $order = Db::name('luntan_order')
                ->where('aid', $aid)->where('id', $orderId)->where('mid', $mid)
                ->lock(true)->find();

            if (empty($order)) {
                throw new BusinessException('订单不存在', 404, 404);
            }

            if (1 === (int) $order['status']) {
                return ['mock' => PayService::isMock(), 'status' => 1]; // 幂等
            }

            if (2 === (int) $order['status']) {
                throw new BusinessException('订单已取消');
            }

            if (!PayService::isMock()) {
                throw new BusinessException('真实模式支付暂未接入', 501, 501);
            }

            $payNum = 'MOCK' . time() . random_int(1000, 9999);

            Db::name('luntan_order')->where('aid', $aid)->where('id', $orderId)->where('status', 0)->update([
                'status'  => 1,
                'paytime' => time(),
                'paytype' => $payType,
            ]);

            Db::name('payorder')
                ->where('aid', $aid)->where('type', 'luntan_top')
                ->where('ordernum', (string) $order['ordernum'])->where('status', 0)
                ->update([
                    'status'     => 1,
                    'paytype'    => $payType,
                    'paytypeid'  => PayService::PAY_TYPE_IDS[$payType] ?? 0,
                    'paynum'     => $payNum,
                    'paytime'    => time(),
                ]);

            // 帖子：待支付置顶 → 正常并置顶
            Db::name('luntan')
                ->where('aid', $aid)->where('id', (int) $order['luntan_id'])
                ->where('status', self::POST_WAIT_PAY)
                ->update(['status' => self::POST_NORMAL, 'is_top' => 1]);

            return ['mock' => true, 'status' => 1, 'ordernum' => (string) $order['ordernum']];
        });
    }

    /* ================= C端：互动（点赞/评论/删除/我的发帖） ================= */

    /**
     * 帖子点赞/取消（toggle）
     *
     * @return array{type: int, zancount: int}
     */
    public function apiZan(int $aid, int $mid, int $id): array
    {
        $this->ensureOpen($aid);

        $detail = ScopeService::query('luntan', $aid)->where('id', $id)->find();

        if (empty($detail)) {
            throw new BusinessException('帖子不存在', 404, 404);
        }

        $zanlog = Db::name('luntan_zanlog')->where('aid', $aid)->where('sid', $id)->where('mid', $mid)->find();

        if (!empty($zanlog)) {
            Db::name('luntan_zanlog')->where('aid', $aid)->where('sid', $id)->where('mid', $mid)->delete();
            Db::name('luntan')->where('aid', $aid)->where('id', $id)->dec('zan')->update();
            $type = 0;
        } else {
            Db::name('luntan_zanlog')->insert(['aid' => $aid, 'sid' => $id, 'mid' => $mid, 'createtime' => time()]);
            Db::name('luntan')->where('aid', $aid)->where('id', $id)->inc('zan')->update();
            $type = 1;
        }

        return ['type' => $type, 'zancount' => (int) Db::name('luntan')->where('aid', $aid)->where('id', $id)->value('zan')];
    }

    /**
     * 提交评论/回评（type=0 评论帖子 / 1 回复评论；pingluncheck 决定待审/直显）
     *
     * @return array{status: int, msg: string}
     */
    public function apiSubpinglun(int $aid, int $mid, int $id, int $type, int $hfid, string $content): array
    {
        $sysset = $this->ensureOpen($aid);

        $detail = ScopeService::query('luntan', $aid)->where('id', $id)->where('status', self::POST_NORMAL)->find();

        if (empty($detail)) {
            throw new BusinessException('帖子不存在或已删除', 404, 404);
        }

        $content = trim($content);

        if ('' === $content) {
            throw new BusinessException('请输入评论内容');
        }

        if (mb_strlen($content) > 1000) {
            throw new BusinessException('请输入1000字以内的评论内容');
        }

        $member = Db::name('member')->where('aid', $aid)->where('id', $mid)->find();

        $data = [
            'aid'        => $aid,
            'mid'        => $mid,
            'sid'        => $id,
            'headimg'    => (string) ($member['headimg'] ?? ''),
            'nickname'   => (string) ($member['nickname'] ?? ''),
            'content'    => $content,
            'createtime' => time(),
            'status'     => 1 === (int) $sysset['pingluncheck'] ? 0 : 1,
        ];

        if (1 === $type) {
            $hfid = (int) $hfid;

            if ($hfid <= 0) {
                throw new BusinessException('缺少被回复的评论');
            }

            $parent = Db::name('luntan_pinglun')->where('aid', $aid)->where('id', $hfid)->where('sid', $id)->find();

            if (empty($parent)) {
                throw new BusinessException('被回复的评论不存在');
            }

            $data['pid'] = $hfid;
            $newId = (int) Db::name('luntan_pinglun_reply')->insertGetId($data);
        } else {
            $newId = (int) Db::name('luntan_pinglun')->insertGetId($data);
        }

        return [
            'id'     => $newId,
            'status' => (int) $data['status'],
            'msg'    => 0 === (int) $data['status'] ? '提交成功，请等待审核' : '发表评论成功',
        ];
    }

    /**
     * 评论点赞/取消（toggle）
     *
     * @return array{type: int, zancount: int}
     */
    public function apiPzan(int $aid, int $mid, int $id): array
    {
        $this->ensureOpen($aid);

        $pinglun = Db::name('luntan_pinglun')->where('aid', $aid)->where('id', $id)->find();

        if (empty($pinglun)) {
            throw new BusinessException('评论不存在', 404, 404);
        }

        $zanlog = Db::name('luntan_pzanlog')->where('aid', $aid)->where('pid', $id)->where('mid', $mid)->find();

        if (!empty($zanlog)) {
            Db::name('luntan_pzanlog')->where('aid', $aid)->where('pid', $id)->where('mid', $mid)->delete();
            Db::name('luntan_pinglun')->where('aid', $aid)->where('id', $id)->dec('zan')->update();
            $type = 0;
        } else {
            Db::name('luntan_pzanlog')->insert(['aid' => $aid, 'pid' => $id, 'mid' => $mid, 'createtime' => time()]);
            Db::name('luntan_pinglun')->where('aid', $aid)->where('id', $id)->inc('zan')->update();
            $type = 1;
        }

        return ['type' => $type, 'zancount' => (int) Db::name('luntan_pinglun')->where('aid', $aid)->where('id', $id)->value('zan')];
    }

    /**
     * 删除帖子（仅作者；连带评论/回评）
     */
    public function apiDeltie(int $aid, int $mid, int $id): void
    {
        $detail = ScopeService::query('luntan', $aid)->where('id', $id)->find();

        if (empty($detail)) {
            throw new BusinessException('帖子不存在', 404, 404);
        }

        if ((int) $detail['mid'] !== $mid) {
            throw new BusinessException('无权限操作', 403, 403);
        }

        Db::name('luntan')->where('aid', $aid)->where('id', $id)->delete();
        Db::name('luntan_pinglun')->where('aid', $aid)->where('sid', $id)->delete();
        Db::name('luntan_pinglun_reply')->where('aid', $aid)->where('sid', $id)->delete();
        Db::name('luntan_zanlog')->where('aid', $aid)->where('sid', $id)->delete();
    }

    /**
     * 删除评论（仅作者；连带回评）
     */
    public function apiDelpinglun(int $aid, int $mid, int $id): void
    {
        $pinglun = Db::name('luntan_pinglun')->where('aid', $aid)->where('id', $id)->find();

        if (empty($pinglun)) {
            throw new BusinessException('评论不存在', 404, 404);
        }

        if ((int) $pinglun['mid'] !== $mid) {
            throw new BusinessException('无权限操作', 403, 403);
        }

        Db::name('luntan_pinglun')->where('aid', $aid)->where('id', $id)->delete();
        Db::name('luntan_pinglun_reply')->where('aid', $aid)->where('pid', $id)->delete();
    }

    /**
     * 删除回评（仅作者）
     */
    public function apiDelplreply(int $aid, int $mid, int $id): void
    {
        $reply = Db::name('luntan_pinglun_reply')->where('aid', $aid)->where('id', $id)->find();

        if (empty($reply)) {
            throw new BusinessException('回评不存在', 404, 404);
        }

        if ((int) $reply['mid'] !== $mid) {
            throw new BusinessException('无权限操作', 403, 403);
        }

        Db::name('luntan_pinglun_reply')->where('aid', $aid)->where('id', $id)->delete();
    }

    /**
     * 我的发帖记录（全部状态，含审核状态供本人查看）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function apiFatielog(int $aid, int $mid, int $page): array
    {
        $page     = max(1, $page);
        $pageSize = 20;

        $query = ScopeService::query('luntan', $aid)->where('mid', $mid);

        $total = (clone $query)->count();

        $list = $query->field('id,cid,content,pics,video,createtime,status,is_top,zan,readcount')
            ->order('is_top', 'desc')->order('id', 'desc')
            ->page($page, $pageSize)->select()->toArray();

        foreach ($list as &$row) {
            $row['pic']            = $this->picsArr((string) $row['pics'])[0] ?? '';
            $row['pics']           = $this->picsArr((string) $row['pics']);
            $row['createtime_text'] = date('Y-m-d H:i:s', (int) $row['createtime']);
        }
        unset($row);

        return ['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize];
    }
}
