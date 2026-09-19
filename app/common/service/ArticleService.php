<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;

/**
 * 文章管理服务（批次5-A 任务194，对照参考系统 Article/ArticleCategory/ArticlePinglun/ArticlePlreply/ArticleSet + ApiArticle）
 *
 * B 端：文章 CRUD/上下架/审核/复制、二级分类、评论与回评审核、系统设置
 * C 端：列表/详情（阅读幂等+点赞态+showtj 权限）/评论流/点赞切换/打赏/分享送积分/附件资源
 * 积分流水口径：member_scorelog（mid varchar 须转字符串，channel 区分来源）
 */
class ArticleService
{
    /** 文章保存白名单字段 */
    private const ARTICLE_FIELDS = [
        'cid', 'name', 'subname', 'pic', 'author', 'content', 'sort', 'sendtime',
        'status', 'showname', 'showsubname', 'showreadcount', 'showsendtime', 'showauthor',
        'canpl', 'canplrp', 'pinglun_check', 'showtj', 'pcid', 'fujian', 'mid',
    ];

    // ==================== B 端：文章 ====================

    /**
     * 文章列表（分页 + 筛选；商户维度 bid 走 ScopeService::applyBid 口径）
     */
    public function list(int $aid, int $page, int $pagesize, array $filters): array
    {
        $query = ScopeService::query('article', $aid);
        ScopeService::applyBid($query, (string) ($filters['bid'] ?? ''));

        if ('' !== trim((string) ($filters['keyword'] ?? ''))) {
            $query->where('name|subname', 'like', '%' . trim((string) $filters['keyword']) . '%');
        }
        if ('' !== ($filters['status'] ?? '') && null !== ($filters['status'] ?? null)) {
            $query->where('status', (int) $filters['status']);
        }
        if (!empty($filters['cid'])) {
            $cid   = (int) $filters['cid'];
            $cids  = [$cid];
            $child = ScopeService::query('article_category', $aid)->where('pid', $cid)->column('id');
            if ($child) {
                $cids = array_merge($cids, $child);
            }
            $query->whereIn('cid', $cids);
        }
        if (!empty($filters['start_time'])) {
            $query->where('createtime', '>=', (int) $filters['start_time']);
        }
        if (!empty($filters['end_time'])) {
            $query->where('createtime', '<=', (int) $filters['end_time']);
        }

        $total = (clone $query)->count();
        $list  = $query->order('sort desc,id desc')->page($page, $pagesize)->select()->toArray();

        // 分类名映射
        $cmap = [];
        $cats = ScopeService::query('article_category', $aid)->column('name', 'id');
        foreach ($cats as $cidKey => $cname) {
            $cmap[(int) $cidKey] = $cname;
        }
        // 商户名映射
        $bmap = [];
        $bids = array_values(array_unique(array_filter(array_map(static fn ($v) => (int) $v['bid'], $list))));
        if ($bids) {
            $bmap = ScopeService::query('business', $aid)->whereIn('id', $bids)->column('name', 'id');
        }

        foreach ($list as &$row) {
            $row['cname'] = $cmap[(int) $row['cid']] ?? '';
            $row['bname'] = (int) $row['bid'] > 0 ? ($bmap[(int) $row['bid']] ?? '') : '平台';
        }
        unset($row);

        return ['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pagesize];
    }

    /**
     * 文章详情（编辑回显）
     */
    public function detail(int $aid, int $id): array
    {
        $info = ScopeService::query('article', $aid)->where('id', $id)->find();
        if (!$info) {
            throw new BusinessException('文章不存在', 404, 404);
        }

        return $info;
    }

    /**
     * 新增/编辑文章（字段白名单入库，showtj 支持数组或逗号串）
     */
    public function save(int $aid, array $info): array
    {
        $data = [];
        foreach (self::ARTICLE_FIELDS as $field) {
            if (array_key_exists($field, $info)) {
                $data[$field] = $info[$field];
            }
        }
        if (!$data) {
            throw new BusinessException('没有可保存的数据');
        }
        if (isset($data['showtj'])) {
            $data['showtj'] = is_array($data['showtj']) ? implode(',', $data['showtj']) : (string) $data['showtj'];
            if ('' === trim($data['showtj'])) {
                $data['showtj'] = '-1';
            }
        }
        if (array_key_exists('name', $data) && '' === trim((string) $data['name'])) {
            throw new BusinessException('请填写文章标题');
        }

        $id = (int) ($info['id'] ?? 0);
        if ($id > 0) {
            $exists = ScopeService::query('article', $aid)->where('id', $id)->find();
            if (!$exists) {
                throw new BusinessException('文章不存在', 404, 404);
            }
            Db::name('article')->where('id', $id)->where('aid', $aid)->update($data);
        } else {
            $data['aid']        = $aid;
            $data['bid']        = (int) ($info['bid'] ?? 0);
            $data['createtime'] = time();
            $data['status']     = (int) ($data['status'] ?? 1);
            $id                 = (int) Db::name('article')->insertGetId($data);
        }

        return ['id' => $id];
    }

    /**
     * 删除文章（连同评论/回评/点赞/阅读记录一并清理）
     */
    public function delete(int $aid, array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            throw new BusinessException('缺少文章ID');
        }
        $exists = ScopeService::query('article', $aid)->whereIn('id', $ids)->column('id');
        if (!$exists) {
            throw new BusinessException('无可操作的文章');
        }

        Db::transaction(function () use ($aid, $exists) {
            ScopeService::query('article', $aid)->whereIn('id', $exists)->delete();
            $plids = ScopeService::query('article_pinglun', $aid)->whereIn('sid', $exists)->column('id');
            if ($plids) {
                ScopeService::query('article_pinglun_reply', $aid)->whereIn('pid', $plids)->delete();
                ScopeService::query('article_pzanlog', $aid)->whereIn('pid', $plids)->delete();
                ScopeService::query('article_pinglun', $aid)->whereIn('id', $plids)->delete();
            }
            ScopeService::query('article_zanlog', $aid)->whereIn('sid', $exists)->delete();
            ScopeService::query('article_readlog', $aid)->whereIn('artid', $exists)->delete();
            Db::name('article_resource')->where('aid', $aid)->whereIn('artid', $exists)->delete();
        });
    }

    /**
     * 上下架（status 1上架 0下架）
     */
    public function setst(int $aid, array $ids, int $st): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            throw new BusinessException('缺少文章ID');
        }
        $count = ScopeService::query('article', $aid)->whereIn('id', $ids)->update(['status' => $st ? 1 : 0]);
        if ($count <= 0) {
            throw new BusinessException('无可操作的文章');
        }
    }

    /**
     * 审核（st 0待审 1通过 2驳回，驳回时记录原因）
     */
    public function setcheckst(int $aid, int $id, int $st, string $reason): void
    {
        $article = ScopeService::query('article', $aid)->where('id', $id)->find();
        if (!$article) {
            throw new BusinessException('无可操作的文章');
        }
        Db::name('article')->where('aid', $aid)->where('id', $id)
            ->update(['status' => in_array($st, [0, 1, 2], true) ? $st : 0, 'reason' => $reason]);
    }

    /**
     * 复制文章（复制- 前缀，默认下架）
     */
    public function copy(int $aid, int $id): array
    {
        $article = ScopeService::query('article', $aid)->where('id', $id)->find();
        if (!$article) {
            throw new BusinessException('文章不存在，请重新选择');
        }
        unset($article['id'], $article['readcount'], $article['zan']);

        $article['name']       = '复制-' . (string) $article['name'];
        $article['status']     = 0;
        $article['createtime'] = time();

        $newId = (int) Db::name('article')->insertGetId($article);

        return ['id' => $newId];
    }

    // ==================== B 端：分类（二级） ====================

    /**
     * 分类列表（平铺含 pid，前端按树渲染）
     */
    public function categoryList(int $aid): array
    {
        $list = ScopeService::query('article_category', $aid)
            ->order('sort desc,id desc')
            ->select()
            ->toArray();

        return ['list' => $list];
    }

    /**
     * 新增/编辑分类（二级限制：pid 只能为 0 或顶级分类）
     */
    public function categorySave(int $aid, array $info): array
    {
        $name = trim((string) ($info['name'] ?? ''));
        if ('' === $name) {
            throw new BusinessException('请填写分类名称');
        }
        $pid = (int) ($info['pid'] ?? 0);
        if ($pid > 0) {
            $parent = ScopeService::query('article_category', $aid)->where('id', $pid)->find();
            if (!$parent) {
                throw new BusinessException('上级分类不存在');
            }
            if ((int) $parent['pid'] !== 0) {
                throw new BusinessException('最多支持二级分类');
            }
        }

        $data = [
            'name'   => $name,
            'pid'    => $pid,
            'pic'    => trim((string) ($info['pic'] ?? '')),
            'status' => !empty($info['status']) ? 1 : 0,
            'sort'   => (int) ($info['sort'] ?? 1),
        ];

        $id = (int) ($info['id'] ?? 0);
        if ($id > 0) {
            $exists = ScopeService::query('article_category', $aid)->where('id', $id)->find();
            if (!$exists) {
                throw new BusinessException('分类不存在', 404, 404);
            }
            Db::name('article_category')->where('aid', $aid)->where('id', $id)->update($data);
        } else {
            $data['aid']        = $aid;
            $data['bid']        = 0;
            $data['createtime'] = time();
            $id                 = (int) Db::name('article_category')->insertGetId($data);
        }

        return ['id' => $id];
    }

    /**
     * 删除分类（级联删除子分类）
     */
    public function categoryDelete(int $aid, array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            throw new BusinessException('缺少分类ID');
        }
        $childIds = ScopeService::query('article_category', $aid)->whereIn('pid', $ids)->column('id');
        $allIds   = $childIds ? array_merge($ids, $childIds) : $ids;

        ScopeService::query('article_category', $aid)->whereIn('id', $allIds)->delete();
    }

    // ==================== B 端：评论/回评 ====================

    /**
     * 评论列表（带文章标题 + 回评数）
     */
    public function pinglunList(int $aid, int $page, int $pagesize, array $filters): array
    {
        $query = ScopeService::query('article_pinglun', $aid);
        ScopeService::applyBid($query, (string) ($filters['bid'] ?? ''));

        if ('' !== ($filters['status'] ?? '') && null !== ($filters['status'] ?? null)) {
            $query->where('status', (int) $filters['status']);
        }
        if ('' !== trim((string) ($filters['content'] ?? ''))) {
            $query->where('content', 'like', '%' . trim((string) $filters['content']) . '%');
        }
        if (!empty($filters['start_time'])) {
            $query->where('createtime', '>=', (int) $filters['start_time']);
        }
        if (!empty($filters['end_time'])) {
            $query->where('createtime', '<=', (int) $filters['end_time']);
        }

        $total = (clone $query)->count();
        $list  = $query->order('id desc')->page($page, $pagesize)->select()->toArray();

        $sids   = array_values(array_unique(array_map(static fn ($v) => (int) $v['sid'], $list)));
        $titleMap = $sids ? ScopeService::query('article', $aid)->whereIn('id', $sids)->column('name', 'id') : [];
        foreach ($list as &$row) {
            $row['title']       = $titleMap[(int) $row['sid']] ?? '';
            $row['replycount']  = (int) ScopeService::query('article_pinglun_reply', $aid)->where('pid', $row['id'])->count();
        }
        unset($row);

        return ['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pagesize];
    }

    /**
     * 评论审核（status 0待审 1通过；givescore 记入 score 列）
     */
    public function pinglunSetst(int $aid, array $ids, int $st, int $score = 0): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            throw new BusinessException('缺少评论ID');
        }
        ScopeService::query('article_pinglun', $aid)->whereIn('id', $ids)
            ->update(['status' => $st ? 1 : 0, 'score' => $score]);
    }

    /**
     * 删除评论（连同回评与评论点赞）
     */
    public function pinglunDelete(int $aid, array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            throw new BusinessException('缺少评论ID');
        }
        Db::transaction(function () use ($aid, $ids) {
            ScopeService::query('article_pinglun_reply', $aid)->whereIn('pid', $ids)->delete();
            ScopeService::query('article_pzanlog', $aid)->whereIn('pid', $ids)->delete();
            ScopeService::query('article_pinglun', $aid)->whereIn('id', $ids)->delete();
        });
    }

    /**
     * 后台回复评论（mid=0 表示平台/商家，直显）
     */
    public function pinglunReply(int $aid, int $pid, string $content): array
    {
        $content = trim($content);
        if ('' === $content) {
            throw new BusinessException('请填写回复内容');
        }
        $pinglun = ScopeService::query('article_pinglun', $aid)->where('id', $pid)->find();
        if (!$pinglun) {
            throw new BusinessException('评论不存在');
        }

        $id = (int) Db::name('article_pinglun_reply')->insertGetId([
            'aid'        => $aid,
            'bid'        => (int) $pinglun['bid'],
            'sid'        => (int) $pinglun['sid'],
            'pid'        => $pid,
            'mid'        => 0,
            'nickname'   => '平台回复',
            'headimg'    => '',
            'content'    => $content,
            'createtime' => time(),
            'status'     => 1,
        ]);

        return ['id' => $id];
    }

    /**
     * 回评列表（带原评论内容 + 文章标题）
     */
    public function replyList(int $aid, int $page, int $pagesize, array $filters): array
    {
        $query = ScopeService::query('article_pinglun_reply', $aid);
        ScopeService::applyBid($query, (string) ($filters['bid'] ?? ''));

        if ('' !== ($filters['status'] ?? '') && null !== ($filters['status'] ?? null)) {
            $query->where('status', (int) $filters['status']);
        }
        if ('' !== trim((string) ($filters['content'] ?? ''))) {
            $query->where('content', 'like', '%' . trim((string) $filters['content']) . '%');
        }

        $total = (clone $query)->count();
        $list  = $query->order('id desc')->page($page, $pagesize)->select()->toArray();

        $pids  = array_values(array_unique(array_map(static fn ($v) => (int) $v['pid'], $list)));
        $plMap = [];
        if ($pids) {
            $pls = ScopeService::query('article_pinglun', $aid)->whereIn('id', $pids)->select()->toArray();
            foreach ($pls as $pl) {
                $plMap[(int) $pl['id']] = $pl;
            }
        }
        $sids     = array_values(array_unique(array_map(static fn ($v) => (int) $v['sid'], $list)));
        $titleMap = $sids ? ScopeService::query('article', $aid)->whereIn('id', $sids)->column('name', 'id') : [];

        foreach ($list as &$row) {
            $pl              = $plMap[(int) $row['pid']] ?? null;
            $row['plcontent'] = $pl ? (string) $pl['content'] : '';
            $row['title']    = $titleMap[(int) $row['sid']] ?? '';
        }
        unset($row);

        return ['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pagesize];
    }

    /**
     * 回评审核
     */
    public function replySetst(int $aid, array $ids, int $st): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            throw new BusinessException('缺少回评ID');
        }
        ScopeService::query('article_pinglun_reply', $aid)->whereIn('id', $ids)
            ->update(['status' => $st ? 1 : 0]);
    }

    /**
     * 删除回评
     */
    public function replyDelete(int $aid, array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            throw new BusinessException('缺少回评ID');
        }
        ScopeService::query('article_pinglun_reply', $aid)->whereIn('id', $ids)->delete();
    }

    // ==================== B 端：系统设置 ====================

    /**
     * 读取设置（bid=0 平台行，缺省自动建档）
     */
    public function getSet(int $aid): array
    {
        $set = Db::name('article_set')->where('aid', $aid)->where('bid', 0)->find();
        if (!$set) {
            Db::name('article_set')->insert(['aid' => $aid, 'bid' => 0]);
            $set = Db::name('article_set')->where('aid', $aid)->where('bid', 0)->find();
        }

        return $set;
    }

    /**
     * 保存设置（白名单字段）
     */
    public function saveSet(int $aid, array $info): void
    {
        $set = $this->getSet($aid);

        $data = [];
        foreach (['listtype', 'title_size', 'share_score', 'share_score_max_perday', 'reward_status'] as $field) {
            if (array_key_exists($field, $info)) {
                $data[$field] = (int) $info[$field];
            }
        }
        foreach (['money_data', 'score_data'] as $field) {
            if (array_key_exists($field, $info)) {
                $val = $info[$field];
                $data[$field] = is_array($val) ? implode(',', $val) : trim((string) $val);
            }
        }
        if (!$data) {
            throw new BusinessException('没有可保存的数据');
        }

        Db::name('article_set')->where('aid', $aid)->where('id', (int) $set['id'])->update($data);
    }

    // ==================== C 端 ====================

    /**
     * C 端文章列表（上架 + showtj 可见性过滤 + 分类含子级）
     */
    public function apiList(int $aid, int $pagenum, int $cid, string $keyword, int $mid): array
    {
        $set = $this->getSet($aid);

        $query = ScopeService::query('article', $aid)
            ->where('status', 1)
            ->where('bid', 0);

        if ('' !== trim($keyword)) {
            $query->where('name|subname', 'like', '%' . trim($keyword) . '%');
        }

        // showtj 可见性：-1 全部 / 0 需关注 / 指定等级
        $query->where($this->showtjCondition($aid, $mid));

        $title = '文章列表';
        if ($cid > 0) {
            $cinfo = ScopeService::query('article_category', $aid)->where('id', $cid)->find();
            if (!$cinfo) {
                throw new BusinessException('分类不存在', 404, 404);
            }
            $title    = (string) $cinfo['name'];
            $cids     = [$cid];
            $downCids = ScopeService::query('article_category', $aid)->where('pid', $cid)->column('id');
            if ($downCids) {
                $cids = array_merge($cids, $downCids);
            }
            $query->whereIn('cid', $cids);
        }

        $list = $query->order('sort desc,id desc')->page($pagenum, 20)->select()->toArray();
        foreach ($list as &$row) {
            $row['sendtime_fmt'] = $row['sendtime'] ? (string) $row['sendtime'] : date('Y-m-d', (int) $row['createtime']);
        }
        unset($row);

        // 一级分类（含二级子分类）
        $clist = [];
        if (1 === $pagenum) {
            $clist = ScopeService::query('article_category', $aid)
                ->where('pid', 0)->where('bid', 0)->where('status', 1)
                ->order('sort desc,id desc')->select()->toArray();
            foreach ($clist as &$c) {
                $c['child'] = ScopeService::query('article_category', $aid)
                    ->where('pid', $c['id'])->where('status', 1)
                    ->order('sort desc,id desc')->select()->toArray();
            }
            unset($c);
        }

        return ['list' => $list, 'clist' => $clist, 'title' => $title, 'listtype' => (int) $set['listtype']];
    }

    /**
     * C 端文章详情（showtj 校验 + 阅读幂等 + 点赞态 + 评论数 + 打赏信息）
     */
    public function apiDetail(int $aid, int $id, int $mid): array
    {
        $detail = ScopeService::query('article', $aid)->where('id', $id)->where('status', 1)->find();
        if (!$detail) {
            throw new BusinessException('文章不存在', 404, 404);
        }

        // showtj 权限校验：-1 全部可见；0 需关注；其余为指定等级
        $showtjArr = array_filter(array_map('trim', explode(',', (string) $detail['showtj'])), static fn ($v) => '' !== $v);
        if (!in_array('-1', $showtjArr, true)) {
            if ($mid <= 0) {
                throw new BusinessException('请先登录', 401, 401);
            }
            $member = ScopeService::query('member', $aid)->where('id', $mid)->field('id,levelid,subscribe')->find();
            if (!$member) {
                throw new BusinessException('请先登录', 401, 401);
            }
            if (in_array('0', $showtjArr, true)) {
                if (1 !== (int) $member['subscribe']) {
                    throw new BusinessException('文章仅关注用户可见');
                }
            } elseif (!in_array((string) (int) $member['levelid'], $showtjArr, true)) {
                throw new BusinessException('文章状态不可见');
            }
        }

        // 阅读数 +1：member 维度 article_readlog 幂等（匿名访问每次计数，同参考系统）
        $counted = false;
        if ($mid > 0) {
            $hasRead = ScopeService::query('article_readlog', $aid)
                ->where('artid', $id)->where('mid', $mid)->find();
            if (!$hasRead) {
                Db::name('article_readlog')->insert([
                    'aid'        => $aid,
                    'bid'        => (int) $detail['bid'],
                    'artid'      => $id,
                    'mid'        => $mid,
                    'createtime' => time(),
                ]);
                $counted = true;
            }
        } else {
            $counted = true;
        }
        if ($counted) {
            Db::name('article')->where('aid', $aid)->where('id', $id)->inc('readcount')->update();
            $detail['readcount'] = (int) $detail['readcount'] + 1;
        }

        // 评论数 + 点赞态
        $plcount = (int) ScopeService::query('article_pinglun', $aid)->where('sid', $id)->where('status', 1)->count();
        $iszan   = 0;
        if ($mid > 0) {
            $zanlog = ScopeService::query('article_zanlog', $aid)->where('sid', $id)->where('mid', $mid)->find();
            $iszan  = $zanlog ? 1 : 0;
        }

        // 打赏信息
        $reward      = false;
        $rewardData  = ['money_data' => [], 'score_data' => []];
        $rewardNum   = 0;
        if ((int) $detail['mid'] > 0) {
            $artset = $this->getSet($aid);
            $reward = 1 === (int) $artset['reward_status'];
            if ($artset['money_data']) {
                $rewardData['money_data'] = explode(',', (string) $artset['money_data']);
            }
            if ($artset['score_data']) {
                $rewardData['score_data'] = explode(',', (string) $artset['score_data']);
            }
            $rewardNum = (int) Db::name('article_reward_order')
                ->where('aid', $aid)->where('artid', $id)->where('status', 1)->count();
        }

        return [
            'detail'     => $detail,
            'plcount'    => $plcount,
            'iszan'      => $iszan,
            'reward'     => $reward,
            'reward_data' => $rewardData,
            'reward_num' => $rewardNum,
        ];
    }

    /**
     * C 端评论列表（通过审核的评论 + 各自回评）
     */
    public function apiPllist(int $aid, int $sid, int $pagenum, int $mid): array
    {
        $list = ScopeService::query('article_pinglun', $aid)
            ->where('sid', $sid)->where('status', 1)
            ->order('createtime desc')
            ->page($pagenum, 20)
            ->select()
            ->toArray();

        foreach ($list as &$row) {
            $row['iszan'] = 0;
            if ($mid > 0) {
                $zs = ScopeService::query('article_pzanlog', $aid)->where('pid', $row['id'])->where('mid', $mid)->find();
                $row['iszan'] = $zs ? 1 : 0;
            }
            $row['replylist'] = ScopeService::query('article_pinglun_reply', $aid)
                ->where('pid', $row['id'])->where('status', 1)
                ->field('nickname,headimg,content,createtime')
                ->order('createtime')
                ->select()
                ->toArray();
            $row['createtime_fmt'] = date('Y-m-d H:i', (int) $row['createtime']);
        }
        unset($row);

        return ['list' => $list];
    }

    /**
     * 点赞切换（article_zanlog 幂等，zan 计数同步增减）
     */
    public function apiZan(int $aid, int $mid, int $id): array
    {
        $detail = ScopeService::query('article', $aid)->where('id', $id)->find();
        if (!$detail) {
            throw new BusinessException('文章不存在', 404, 404);
        }

        $zanlog = ScopeService::query('article_zanlog', $aid)->where('sid', $id)->where('mid', $mid)->find();
        if ($zanlog) {
            Db::name('article_zanlog')->where('id', (int) $zanlog['id'])->delete();
            Db::name('article')->where('aid', $aid)->where('id', $id)->dec('zan')->update();
            $type = 0;
        } else {
            Db::name('article_zanlog')->insert([
                'aid'        => $aid,
                'bid'        => (int) $detail['bid'],
                'sid'        => $id,
                'mid'        => $mid,
                'createtime' => time(),
            ]);
            Db::name('article')->where('aid', $aid)->where('id', $id)->inc('zan')->update();
            $type = 1;
        }

        return ['type' => $type, 'zancount' => (int) Db::name('article')->where('aid', $aid)->where('id', $id)->value('zan')];
    }

    /**
     * 提交评论/回评（pinglun_check=1 待审，否则直显）
     * @param array $params {id, type 0评论 1回评, hfid 被回复评论id, content}
     */
    public function apiSubpinglun(int $aid, int $mid, array $params): array
    {
        $id      = (int) ($params['id'] ?? 0);
        $type    = (int) ($params['type'] ?? 0);
        $hfid    = (int) ($params['hfid'] ?? 0);
        $content = trim((string) ($params['content'] ?? ''));
        if ($id <= 0) {
            throw new BusinessException('参数错误');
        }
        if ('' === $content) {
            throw new BusinessException('请输入评论内容');
        }

        $detail = ScopeService::query('article', $aid)->where('id', $id)->where('status', 1)->find();
        if (!$detail) {
            throw new BusinessException('文章不存在', 404, 404);
        }
        if (0 === (int) $detail['canpl']) {
            throw new BusinessException('评论功能未开启');
        }
        if ($hfid > 0 && 0 === (int) $detail['canplrp']) {
            throw new BusinessException('评论回复功能未开启');
        }

        $member = ScopeService::query('member', $aid)->where('id', $mid)->field('id,nickname,headimg')->find();

        $status = 1 === (int) $detail['pinglun_check'] ? 0 : 1;
        $msg    = 0 === $status ? '提交成功，请等待审核' : '发表评论成功';

        $data = [
            'aid'        => $aid,
            'bid'        => (int) $detail['bid'],
            'sid'        => $id,
            'mid'        => $mid,
            'nickname'   => $member['nickname'] ?? '',
            'headimg'    => $member['headimg'] ?? '',
            'content'    => $content,
            'createtime' => time(),
            'status'     => $status,
        ];

        if (0 === $type) {
            $pid = (int) Db::name('article_pinglun')->insertGetId($data);
        } else {
            if ($hfid <= 0) {
                throw new BusinessException('缺少被回复的评论');
            }
            $parent = ScopeService::query('article_pinglun', $aid)->where('id', $hfid)->find();
            if (!$parent) {
                throw new BusinessException('被回复的评论不存在');
            }
            $data['pid'] = $hfid;
            $pid         = (int) Db::name('article_pinglun_reply')->insertGetId($data);
        }

        return ['id' => $pid, 'status' => $status, 'msg' => $msg];
    }

    /**
     * 评论点赞切换（article_pzanlog 幂等）
     */
    public function apiPzan(int $aid, int $mid, int $id): array
    {
        $pinglun = ScopeService::query('article_pinglun', $aid)->where('id', $id)->find();
        if (!$pinglun) {
            throw new BusinessException('评论不存在', 404, 404);
        }

        $zanlog = ScopeService::query('article_pzanlog', $aid)->where('pid', $id)->where('mid', $mid)->find();
        if ($zanlog) {
            Db::name('article_pzanlog')->where('id', (int) $zanlog['id'])->delete();
            Db::name('article_pinglun')->where('aid', $aid)->where('id', $id)->dec('zan')->update();
            $type = 0;
        } else {
            Db::name('article_pzanlog')->insert([
                'aid'        => $aid,
                'bid'        => (int) $pinglun['bid'],
                'pid'        => $id,
                'mid'        => $mid,
                'createtime' => time(),
            ]);
            Db::name('article_pinglun')->where('aid', $aid)->where('id', $id)->inc('zan')->update();
            $type = 1;
        }

        return ['type' => $type, 'zancount' => (int) Db::name('article_pinglun')->where('aid', $aid)->where('id', $id)->value('zan')];
    }

    /**
     * 打赏（type 1现金 生成待支付订单；type 2积分 同事务转移并记流水）
     */
    public function apiReward(int $aid, int $mid, int $id, int $type, $num): array
    {
        $detail = ScopeService::query('article', $aid)->where('id', $id)->where('status', 1)->find();
        if (!$detail) {
            throw new BusinessException('文章不存在', 404, 404);
        }
        if ((int) $detail['mid'] <= 0) {
            throw new BusinessException('打赏功能暂未开启');
        }
        $recvMember = ScopeService::query('member', $aid)->where('id', (int) $detail['mid'])->find();
        if (!$recvMember) {
            throw new BusinessException('打赏功能暂未开启');
        }
        $artset = $this->getSet($aid);
        if (1 !== (int) $artset['reward_status']) {
            throw new BusinessException('打赏功能暂未开启');
        }
        if (1 !== $type && 2 !== $type) {
            throw new BusinessException('打赏类型错误');
        }
        if (!is_numeric($num)) {
            throw new BusinessException('打赏数额错误');
        }
        $num = round((float) $num, 2);
        if ($num <= 0) {
            throw new BusinessException('打赏数额必须大于0');
        }

        $order = [
            'aid'        => $aid,
            'bid'        => (int) $detail['bid'],
            'ordernum'   => 'D' . date('YmdHis') . (string) random_int(111111, 999999),
            'mid'        => $mid,
            'send_mid'   => (int) $detail['mid'],
            'artid'      => $id,
            'type'       => $type,
            'num'        => $num,
            'createtime' => time(),
        ];

        if (2 === $type) {
            // 积分打赏：必须为整数，同事务转移
            if (floor($num) !== $num) {
                throw new BusinessException('积分必须为整数');
            }
            $scoreNum = (int) $num;

            Db::transaction(function () use ($aid, $mid, &$order, $scoreNum, $detail) {
                $this->addScoreLog($aid, $mid, -$scoreNum, '文章打赏', 'artreward');
                $this->addScoreLog($aid, (int) $detail['mid'], $scoreNum, '文章打赏收入', 'artreward');
                $order['status']  = 1;
                $order['paytime'] = time();
            });
        } else {
            // 现金打赏：生成待支付订单（支付链路由支付模块承接）
            $order['status'] = 0;
        }

        $orderId = (int) Db::name('article_reward_order')->insertGetId($order);

        return ['id' => $orderId, 'ordernum' => $order['ordernum'], 'status' => (int) $order['status']];
    }

    /**
     * 分享文章领积分（每日上限 share_score_max_perday，口径 remark='文章转发赠送'）
     */
    public function apiGiveScorenum(int $aid, int $mid): array
    {
        $set = $this->getSet($aid);
        $per      = (int) $set['share_score'];
        $maxPerDay = (int) $set['share_score_max_perday'];
        if ($per <= 0 || $maxPerDay <= 0) {
            return ['added' => 0, 'msg' => '未增加'];
        }

        $startTime = strtotime(date('Y-m-d 00:00:00'));
        $endTime   = $startTime + 86399;

        $todayTotal = (int) Db::name('member_scorelog')
            ->where('aid', $aid)
            ->where('mid', (string) $mid)
            ->where('createtime', 'between', [$startTime, $endTime])
            ->where('score', '>', 0)
            ->where('remark', '文章转发赠送')
            ->sum('score');

        if ($todayTotal + $per > $maxPerDay) {
            return ['added' => 0, 'msg' => '今日已达上限'];
        }

        $this->addScoreLog($aid, $mid, $per, '文章转发赠送', 'artshare');

        return ['added' => $per, 'msg' => '增加积分成功'];
    }

    /**
     * 获取文章附件资源（幂等记录 article_resource）
     */
    public function apiResourceSave(int $aid, int $mid, int $id): array
    {
        $detail = ScopeService::query('article', $aid)->where('id', $id)->where('status', 1)->find();
        if (!$detail) {
            throw new BusinessException('文章不存在', 404, 404);
        }
        if ('' === trim((string) $detail['fujian'])) {
            throw new BusinessException('无资源可获取');
        }

        $exists = Db::name('article_resource')
            ->where('aid', $aid)->where('mid', $mid)->where('artid', $id)->find();
        if (!$exists) {
            Db::name('article_resource')->insert([
                'aid'        => $aid,
                'bid'        => (int) $detail['bid'],
                'mid'        => $mid,
                'artid'      => $id,
                'createtime' => time(),
            ]);
        }

        return ['ok' => true];
    }

    /**
     * 我已获取的附件资源列表
     */
    public function apiResourceList(int $aid, int $mid, int $pagenum, string $keyword): array
    {
        $query = Db::name('article_resource')->alias('ar')
            ->join('article a', 'a.id = ar.artid')
            ->where('ar.aid', $aid)
            ->where('ar.mid', $mid);
        if ('' !== trim($keyword)) {
            $query->where('a.name|a.subname', 'like', '%' . trim($keyword) . '%');
        }

        $list = $query->field('ar.*,a.name,a.pic,a.subname')
            ->order('ar.createtime desc')
            ->page($pagenum, 20)
            ->select()
            ->toArray();
        foreach ($list as &$row) {
            $row['createtime_fmt'] = date('Y-m-d', (int) $row['createtime']);
        }
        unset($row);

        return ['list' => $list];
    }

    /**
     * 按资源记录取文章附件明细
     */
    public function apiGetFujian(int $aid, int $mid, int $resourceId): array
    {
        $resource = Db::name('article_resource')
            ->where('id', $resourceId)->where('aid', $aid)->where('mid', $mid)->find();
        if (!$resource) {
            throw new BusinessException('资源不存在', 404, 404);
        }
        $fujianStr = (string) Db::name('article')->where('aid', $aid)->where('id', (int) $resource['artid'])->value('fujian');
        $fujianArr = array_values(array_filter(array_map('trim', explode(',', $fujianStr))));

        $fujian = [];
        if ($fujianArr) {
            $fujian = ScopeService::query('admin_upload', $aid)
                ->whereIn('url', $fujianArr)
                ->field('id,name,type,url')
                ->select()
                ->toArray();
        }

        return ['fujian' => $fujian];
    }

    // ==================== 内部工具 ====================

    /**
     * showtj 列表可见性条件（-1 全部 / 0 需关注 / 指定等级）
     */
    private function showtjCondition(int $aid, int $mid): \think\db\Raw
    {
        $cond = "find_in_set('-1',showtj)";
        if ($mid > 0) {
            $member = ScopeService::query('member', $aid)->where('id', $mid)->field('id,levelid,subscribe')->find();
            if ($member) {
                $cond .= ' OR ' . "find_in_set('" . (int) $member['levelid'] . "',showtj)";
                if (1 === (int) $member['subscribe']) {
                    $cond .= " OR find_in_set('0',showtj)";
                }
            }
        }

        return Db::raw('(' . $cond . ')');
    }

    /**
     * 积分入账/扣减（事务外自行包裹；行锁 + member_scorelog 流水）
     */
    private function addScoreLog(int $aid, int $mid, int $score, string $remark, string $channel): void
    {
        if ($mid <= 0 || 0 === $score) {
            throw new BusinessException('积分变动参数错误');
        }

        $member = Db::name('member')->where('id', $mid)->where('aid', $aid)->lock(true)->find();
        if (!$member) {
            throw new BusinessException('会员不存在', 404, 404);
        }

        $after = (int) ($member['score'] ?? 0) + $score;
        if ($after < 0) {
            throw new BusinessException('积分不足');
        }

        Db::name('member')->where('id', $mid)->where('aid', $aid)->update(['score' => $after]);
        Db::name('member_scorelog')->insert([
            'aid'        => $aid,
            'bid'        => 0,
            'mid'        => (string) $mid,
            'score'      => $score,
            'after'      => $after,
            'used'       => 0,
            'createtime' => time(),
            'remark'     => $remark,
            'type'       => 1,
            'channel'    => $channel,
        ]);
    }
}
