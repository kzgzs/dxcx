<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\BindingTokenService;
use app\common\service\OpenPlatformService;
use app\common\service\PlogService;
use app\common\service\ScopeService;
use app\common\service\WxApiClient;
use think\facade\Db;

/**
 * 租户后台 - 公众号粉丝与群发（批次7-4 任务212，对标参考系统 Mpfans）
 *
 * - 粉丝四步同步（前端驱动）：getpage 取总页数 → 逐页 getopenid → 每100批量 getuserinfo → xiuzheng 校正清理失效 openid
 * - 标签 CRUD：fans_tag 本地表 + 微信 tags 接口（mock 直出）
 * - 图文素材管理：add_news/update_news 走微信接口（mock 落 mp_material 本地镜像），图片本地↔微信互转走 pictolocal
 * - 模板消息群发：to_type 1指定openid/2标签/3全部，分页循环发送，sendlog/errlog 落库，last_page 游标断点续发不重发
 * - 客服图文群发（48小时）：素材库作为数据源，同套分页引擎
 */
class Mpfans extends BaseController
{
    /** mock 粉丝总数（确定性数据集，供实测校验行数） */
    private const MOCK_FANS_TOTAL = 235;

    /** mock 单页 openid 数（真实微信为 10000，mock 缩小以演练分页） */
    private const MOCK_PAGE_SIZE = 100;

    /** mock 预置标签（tagid => name） */
    private const MOCK_TAGS = [101 => 'VIP会员', 102 => '活动粉丝', 103 => '黑名单'];

    /** mp_tmplset 字段 → 中文标签 */
    private const TMPL_LABELS = [
        'tmpl_orderconfirm' => '下单成功通知',
        'tmpl_orderpay' => '付款成功通知',
        'tmpl_orderfahuo' => '发货通知',
        'tmpl_ordershouhuo' => '确认收货通知',
        'tmpl_ordertui' => '退款申请通知',
        'tmpl_tuisuccess' => '退款成功通知',
        'tmpl_tuierror' => '退款失败通知',
        'tmpl_withdraw' => '提现申请通知',
        'tmpl_tixiansuccess' => '提现成功通知',
        'tmpl_tixianerror' => '提现失败通知',
        'tmpl_collagesuccess' => '拼团成功通知',
        'tmpl_formsub' => '表单收集通知',
        'tmpl_kehuzixun' => '客户咨询通知',
        'tmpl_fenxiaosuccess' => '分销成功通知',
        'tmpl_fuwudaoqi' => '服务到期通知',
        'tmpl_joinin' => '参与成功通知',
        'tmpl_peisongorder' => '配送订单通知',
        'tmpl_uplv' => '会员升级通知',
        'tmpl_moneychange' => '余额变动通知',
        'tmpl_restaurant_booking' => '餐饮预定通知',
        'tmpl_shenhe' => '审核结果通知',
        'tmpl_prize' => '抽奖结果通知',
        'tmpl_fenhong' => '分红发放通知',
        'tmpl_coupon_expire' => '优惠券到期提醒',
        'tmpl_maidanpay' => '买单付款通知',
        'tmpl_register' => '注册成功通知',
        'tmpl_joinfree' => '免单活动通知',
        'tmpl_zhaopin_notice' => '招聘通知',
        'tmpl_yuyue_jiedan' => '预约接单提醒',
    ];

    public function __construct(
        \think\App $app,
        protected OpenPlatformService $open,
        protected WxApiClient $api,
        protected BindingTokenService $bindingToken,
    ) {
        parent::__construct($app);
    }

    /* ==================== 粉丝列表 ==================== */

    /**
     * 粉丝列表（标签筛选/关注状态/昵称搜索）
     * GET /admin/mpfans/fansList
     */
    public function fansList()
    {
        $aid      = ScopeService::aidOf($this->request);
        $page     = max(1, (int) $this->request->param('page', 1));
        $pagesize = min(max(1, (int) $this->request->param('pagesize', 10)), 100);

        $query = Db::name('fans')->where('aid', $aid);

        $subscribe = (string) $this->request->param('subscribe', '');
        if ('' !== $subscribe) {
            $query->where('subscribe', (int) $subscribe);
        }
        $keyword = trim((string) $this->request->param('keyword', ''));
        if ('' !== $keyword) {
            $query->where('nickname', 'like', '%' . $keyword . '%');
        }
        $tagid = (int) $this->request->param('tagid', 0);
        if ($tagid > 0) {
            $query->whereRaw('find_in_set(' . $tagid . ',tag)');
        }

        $total = (clone $query)->count();
        $list  = $query->order('id', 'desc')->page($page, $pagesize)->select()->toArray();

        $tagMap = Db::name('fans_tag')->where('aid', $aid)->column('name', 'tagid');
        foreach ($list as $k => $v) {
            $names = [];
            if (!empty($v['tag'])) {
                foreach (explode(',', (string) $v['tag']) as $tid) {
                    if (isset($tagMap[(int) $tid]) && '' !== trim((string) $tid)) {
                        $names[] = (string) $tagMap[(int) $tid];
                    }
                }
            }
            $list[$k]['tagname'] = implode(',', $names);
        }

        return $this->success(['list' => $list, 'total' => $total]);
    }

    /**
     * 粉丝记录删除
     * POST /admin/mpfans/fansDelete {ids[]}
     */
    public function fansDelete()
    {
        $aid = ScopeService::aidOf($this->request);
        $ids = array_map('intval', (array) $this->request->post('ids', []));
        if (empty($ids)) {
            throw new BusinessException('请选择粉丝');
        }

        Db::name('fans')->where('aid', $aid)->whereIn('id', $ids)->delete();
        PlogService::write($aid, $this->uid(), '粉丝记录删除' . implode(',', $ids));

        return $this->success([], '删除成功');
    }

    /**
     * 批量打标签（粉丝本地 tag 与微信标签同步）
     * POST /admin/mpfans/setTag {fansids[], tagids[]}
     */
    public function setTag()
    {
        $aid     = ScopeService::aidOf($this->request);
        $fansids = array_map('intval', (array) $this->request->post('fansids', []));
        $tagids  = array_map('intval', (array) $this->request->post('tagids', []));
        if (empty($fansids)) {
            throw new BusinessException('请选择粉丝');
        }

        $token = '';
        foreach ($fansids as $fansid) {
            $fans = Db::name('fans')->where('aid', $aid)->where('id', $fansid)->find();
            if (empty($fans)) {
                throw new BusinessException('未找到粉丝 ' . $fansid);
            }

            $oldtags = '' !== trim((string) $fans['tag'])
                ? array_map('intval', explode(',', (string) $fans['tag']))
                : [];
            $remove = array_diff($oldtags, $tagids);
            $add    = array_diff($tagids, $oldtags);

            if (!$this->open->isMock()) {
                $token = '' === $token ? $this->bindingToken->accessToken('mp', $aid) : $token;
                foreach ($remove as $tid) {
                    $this->assertApiOk($this->api->tagsBatchUntagging($token, $tid, [(string) $fans['openid']]));
                }
                foreach ($add as $tid) {
                    $this->assertApiOk($this->api->tagsBatchTagging($token, $tid, [(string) $fans['openid']]));
                }
            }

            $newtags = array_values(array_unique(array_merge(array_diff($oldtags, $remove), $add)));
            Db::name('fans')->where('id', $fansid)->update(['tag' => implode(',', $newtags)]);
        }

        PlogService::write($aid, $this->uid(), '粉丝批量打标签 fansids=' . implode(',', $fansids));

        return $this->success([], '设置成功');
    }

    /* ==================== 粉丝四步同步（前端驱动） ==================== */

    /**
     * 粉丝同步分步接口（op=getpage|getopenid|getuserinfo|xiuzheng）
     * POST /admin/mpfans/sync
     */
    public function sync()
    {
        $aid = ScopeService::aidOf($this->request);
        $op  = (string) $this->request->post('op', '');

        return match ($op) {
            'getpage' => $this->syncGetPage($aid),
            'getopenid' => $this->syncGetOpenid($aid),
            'getuserinfo' => $this->syncGetUserinfo($aid),
            'xiuzheng' => $this->syncXiuzheng($aid),
            default => throw new BusinessException('未知同步步骤 ' . $op),
        };
    }

    /** 第一步：取总页数（并标记 doing=1 供末步校正清理） */
    protected function syncGetPage(int $aid)
    {
        Db::name('fans')->where('aid', $aid)->update(['doing' => 1]);

        if ($this->open->isMock()) {
            $total     = self::MOCK_FANS_TOTAL;
            $totalpage = (int) ceil($total / self::MOCK_PAGE_SIZE);
        } else {
            $token = $this->bindingToken->accessToken('mp', $aid);
            $rs    = $this->api->userGet($token);
            $err   = (int) ($rs['errcode'] ?? 0);
            if (0 !== $err) {
                throw new BusinessException(48001 === $err ? '仅认证的公众号才有同步权限' : '微信接口错误[' . $err . '] ' . (string) ($rs['errmsg'] ?? ''));
            }
            $total     = (int) ($rs['total'] ?? 0);
            $totalpage = (int) ceil($total / 10000);
        }

        return $this->success(['total' => $total, 'totalpage' => max(1, $totalpage)]);
    }

    /** 第二步：分页取 openid（每100个一组，供第三步批量拉取） */
    protected function syncGetOpenid(int $aid)
    {
        $nextOpenid = trim((string) $this->request->post('next_openid', ''));

        if ($this->open->isMock()) {
            $start = '' === $nextOpenid ? 1 : (int) substr($nextOpenid, -4) + 1;
            $openids = [];
            for ($i = $start; $i <= min($start + self::MOCK_PAGE_SIZE - 1, self::MOCK_FANS_TOTAL); ++$i) {
                $openids[] = $this->mockFansOpenid($i);
            }
            $next = count($openids) < self::MOCK_PAGE_SIZE ? '' : end($openids);
        } else {
            $token = $this->bindingToken->accessToken('mp', $aid);
            $rs    = $this->api->userGet($token, $nextOpenid);
            if (0 !== (int) ($rs['errcode'] ?? 0)) {
                throw new BusinessException('微信接口错误[' . (int) ($rs['errcode'] ?? 0) . '] ' . (string) ($rs['errmsg'] ?? ''));
            }
            $openids = array_values(array_map('strval', (array) ($rs['data']['openid'] ?? [])));
            $next    = (string) ($rs['next_openid'] ?? '');
        }

        // 每100条一组
        $chunks = [];
        $bucket = [];
        foreach ($openids as $k => $openid) {
            $bucket[] = $openid;
            if (($k + 1) % 100 === 0) {
                $chunks[] = $bucket;
                $bucket   = [];
            }
        }
        if (!empty($bucket)) {
            $chunks[] = $bucket;
        }

        return $this->success(['openids' => $chunks, 'next_openid' => $next, 'count' => count($openids)]);
    }

    /** 第三步：批量取用户信息并 upsert fans */
    protected function syncGetUserinfo(int $aid)
    {
        $openids = array_values(array_filter(array_map('strval', (array) $this->request->post('openids', []))));
        if (empty($openids)) {
            return $this->success(['count' => 0]);
        }

        if ($this->open->isMock()) {
            $userlist = array_map(fn (string $openid): array => $this->mockFansInfo($openid), $openids);
        } else {
            $token = $this->bindingToken->accessToken('mp', $aid);
            $rs    = $this->api->userBatchGet($token, $openids);
            if (0 !== (int) ($rs['errcode'] ?? 0)) {
                throw new BusinessException('微信接口错误[' . (int) ($rs['errcode'] ?? 0) . '] ' . (string) ($rs['errmsg'] ?? ''));
            }
            $userlist = (array) ($rs['user_info_list'] ?? []);
        }

        $count = 0;
        foreach ($userlist as $userinfo) {
            $this->upsertFans($aid, (array) $userinfo);
            ++$count;
        }

        return $this->success(['count' => $count]);
    }

    /** 第四步：校正清理（doing=1 即本轮未被微信返回的失效 openid 置为取消关注） */
    protected function syncXiuzheng(int $aid)
    {
        $fixed = Db::name('fans')->where('aid', $aid)->where('doing', 1)->update(['doing' => 0, 'subscribe' => 0]);
        PlogService::write($aid, $this->uid(), '粉丝记录同步');

        return $this->success(['fixed' => $fixed], '同步完成');
    }

    /** upsert 单个粉丝（对标参考系统 getuserinfo 落库逻辑，member 昵称优先） */
    protected function upsertFans(int $aid, array $userinfo): void
    {
        $openid = (string) ($userinfo['openid'] ?? '');
        if ('' === $openid) {
            return;
        }

        $data = [
            'aid'             => $aid,
            'subscribe'       => (int) ($userinfo['subscribe'] ?? 0),
            'openid'          => $openid,
            'unionid'         => (string) ($userinfo['unionid'] ?? ''),
            'language'        => (string) ($userinfo['language'] ?? ''),
            'city'            => (string) ($userinfo['city'] ?? ''),
            'province'        => (string) ($userinfo['province'] ?? ''),
            'country'         => (string) ($userinfo['country'] ?? ''),
            'subscribe_time'  => (int) ($userinfo['subscribe_time'] ?? 0),
            'subscribe_scene' => (string) ($userinfo['subscribe_scene'] ?? ''),
            'qr_scene'        => (string) ($userinfo['qr_scene'] ?? ''),
            'qr_scene_str'    => (string) ($userinfo['qr_scene_str'] ?? ''),
            'tag'             => implode(',', array_map('strval', (array) ($userinfo['tagid_list'] ?? []))),
            'doing'           => 0,
        ];

        $member = Db::name('member')->where('aid', $aid)->where('mpopenid', $openid)->find();
        if (!empty($member) && !empty($member['nickname']) && false === strpos((string) $member['headimg'], '/static/img/touxiang.png')) {
            $data['nickname']   = (string) $member['nickname'];
            $data['headimgurl'] = (string) $member['headimg'];
            $data['sex']        = '1' === (string) $member['sex'] || 1 === (int) $member['sex'] ? '男' : ('2' === (string) $member['sex'] ? '女' : '未知');
        } else {
            $data['nickname']   = (string) ($userinfo['nickname'] ?? '');
            $data['headimgurl'] = (string) ($userinfo['headimgurl'] ?? '');
            $data['sex']        = ['1' => '男', '2' => '女'][(string) ($userinfo['sex'] ?? 0)] ?? '未知';
        }

        $exists = Db::name('fans')->where('aid', $aid)->where('openid', $openid)->find();
        if (!empty($exists)) {
            Db::name('fans')->where('id', $exists['id'])->update($data);
        } else {
            Db::name('fans')->insert($data);
        }
    }

    /* ==================== 标签管理 ==================== */

    /**
     * 标签列表（含各标签关注粉丝数）
     * GET /admin/mpfans/tagsList
     */
    public function tagsList()
    {
        $aid  = ScopeService::aidOf($this->request);
        $list = Db::name('fans_tag')->where('aid', $aid)->order('tagid', 'asc')->select()->toArray();
        foreach ($list as $k => $v) {
            $list[$k]['count'] = Db::name('fans')->where('aid', $aid)->where('subscribe', 1)
                ->whereRaw('find_in_set(' . (int) $v['tagid'] . ',tag)')->count();
        }

        return $this->success(['list' => $list]);
    }

    /**
     * 标签新增/编辑（同步微信 tags create/update）
     * POST /admin/mpfans/tagsSave {id?, name}
     */
    public function tagsSave()
    {
        $aid  = ScopeService::aidOf($this->request);
        $id   = (int) $this->request->post('id', 0);
        $name = trim((string) $this->request->post('name', ''));
        if ('' === $name) {
            throw new BusinessException('标签名称不能为空');
        }

        if ($id > 0) {
            $tag = Db::name('fans_tag')->where('aid', $aid)->where('id', $id)->find();
            if (empty($tag)) {
                throw new BusinessException('标签不存在');
            }
            if (!$this->open->isMock()) {
                $this->assertApiOk($this->api->tagsUpdate($this->bindingToken->accessToken('mp', $aid), (int) $tag['tagid'], $name));
            }
            Db::name('fans_tag')->where('id', $id)->update(['name' => $name]);

            return $this->success(['id' => $id], '编辑成功');
        }

        if ($this->open->isMock()) {
            $tagid = (int) Db::name('fans_tag')->where('aid', $aid)->max('tagid') + 1;
        } else {
            $rs = $this->api->tagsCreate($this->bindingToken->accessToken('mp', $aid), $name);
            $this->assertApiOk($rs);
            $tagid = (int) ($rs['tag']['id'] ?? 0);
            $name  = (string) ($rs['tag']['name'] ?? $name);
        }

        $newid = (int) Db::name('fans_tag')->insertGetId(['aid' => $aid, 'name' => $name, 'tagid' => $tagid]);

        return $this->success(['id' => $newid, 'tagid' => $tagid], '添加成功');
    }

    /**
     * 标签删除（同步微信 tags delete）
     * POST /admin/mpfans/tagsDelete {tagids[]}
     */
    public function tagsDelete()
    {
        $aid    = ScopeService::aidOf($this->request);
        $tagids = array_map('intval', (array) $this->request->post('tagids', []));
        if (empty($tagids)) {
            throw new BusinessException('请选择标签');
        }

        if (!$this->open->isMock()) {
            $token = $this->bindingToken->accessToken('mp', $aid);
            foreach ($tagids as $tagid) {
                $this->assertApiOk($this->api->tagsDelete($token, $tagid));
            }
        }

        Db::name('fans_tag')->where('aid', $aid)->whereIn('tagid', $tagids)->delete();
        PlogService::write($aid, $this->uid(), '删除粉丝标签' . implode(',', $tagids));

        return $this->success([], '删除成功');
    }

    /**
     * 同步微信标签（mock：预置标签 upsert，保留本地自建；真实：以微信为准全量重建）
     * POST /admin/mpfans/syncTags
     */
    public function syncTags()
    {
        $aid = ScopeService::aidOf($this->request);

        if ($this->open->isMock()) {
            foreach (self::MOCK_TAGS as $tagid => $name) {
                $has = Db::name('fans_tag')->where('aid', $aid)->where('tagid', $tagid)->find();
                if (empty($has)) {
                    Db::name('fans_tag')->insert(['aid' => $aid, 'name' => $name, 'tagid' => $tagid]);
                } else {
                    Db::name('fans_tag')->where('id', $has['id'])->update(['name' => $name]);
                }
            }
        } else {
            $rs = $this->api->tagsGet($this->bindingToken->accessToken('mp', $aid));
            if (0 !== (int) ($rs['errcode'] ?? 0)) {
                throw new BusinessException('微信接口错误[' . (int) ($rs['errcode'] ?? 0) . '] ' . (string) ($rs['errmsg'] ?? ''));
            }
            $tagids = [];
            foreach ((array) ($rs['tags'] ?? []) as $tag) {
                $tagids[] = (int) $tag['id'];
                $has = Db::name('fans_tag')->where('aid', $aid)->where('tagid', (int) $tag['id'])->find();
                $row = ['aid' => $aid, 'name' => (string) $tag['name'], 'tagid' => (int) $tag['id']];
                if (empty($has)) {
                    Db::name('fans_tag')->insert($row);
                } else {
                    Db::name('fans_tag')->where('id', $has['id'])->update($row);
                }
            }
            if (!empty($tagids)) {
                Db::name('fans_tag')->where('aid', $aid)->where('tagid', 'not in', $tagids)->delete();
            }
        }

        PlogService::write($aid, $this->uid(), '同步粉丝标签');

        return $this->success([], '同步完成');
    }

    /* ==================== 图文素材管理 ==================== */

    /**
     * 图文素材列表（本地 mp_material 镜像，真实模式 add/update 时同步微信）
     * GET /admin/mpfans/sourceList
     */
    public function sourceList()
    {
        $aid      = ScopeService::aidOf($this->request);
        $page     = max(1, (int) $this->request->param('page', 1));
        $pagesize = min(max(1, (int) $this->request->param('pagesize', 10)), 20);

        $query = Db::name('mp_material')->where('aid', $aid)->where('type', 'news');
        $total = (clone $query)->count();
        $rows  = $query->order('id', 'desc')->page($page, $pagesize)->select()->toArray();

        $list = [];
        foreach ($rows as $row) {
            $items = json_decode((string) $row['description'], true);
            $list[] = [
                'media_id'    => (string) $row['media_id'],
                'update_time' => (int) $row['createtime'],
                'content'     => ['news_item' => is_array($items) ? $items : []],
            ];
        }

        return $this->success(['list' => $list, 'total' => $total]);
    }

    /**
     * 图文素材详情（内容图片微信→本地化，防止「未经允许禁止转载」不显示）
     * GET /admin/mpfans/sourceDetail?media_id=
     */
    public function sourceDetail()
    {
        $aid     = ScopeService::aidOf($this->request);
        $mediaId = trim((string) $this->request->param('media_id', ''));
        if ('' === $mediaId) {
            throw new BusinessException('缺少 media_id');
        }

        $row = Db::name('mp_material')->where('aid', $aid)->where('type', 'news')->where('media_id', $mediaId)->find();
        if (empty($row)) {
            throw new BusinessException('素材不存在');
        }

        $items = json_decode((string) $row['description'], true);
        $items = is_array($items) ? $items : [];
        foreach ($items as $k => $item) {
            $items[$k]['content'] = $this->contentToLocal((string) ($item['content'] ?? ''), $aid);
        }

        return $this->success(['media_id' => $mediaId, 'news_item' => $items]);
    }

    /**
     * 图文素材保存（条数/评论设置变更→add_news 新建；否则 update_news 逐篇修改）
     * POST /admin/mpfans/sourceSave {media_id?, news_item[], old_news_item[]?}
     */
    public function sourceSave()
    {
        $aid         = ScopeService::aidOf($this->request);
        $mediaId     = trim((string) $this->request->post('media_id', ''));
        $newsItem    = (array) $this->request->post('news_item', []);
        $oldNewsItem = (array) $this->request->post('old_news_item', []);
        if (empty($newsItem)) {
            throw new BusinessException('请填写图文内容');
        }

        // 是否需要重建：条数不同 或 评论设置变化（对标参考系统 sourcesave 判定）
        $needadd = true;
        if ('' !== $mediaId && count($newsItem) === count($oldNewsItem) && !empty($oldNewsItem)) {
            $needadd = false;
            foreach ($newsItem as $k => $v) {
                $old = (array) ($oldNewsItem[$k] ?? []);
                if ((int) ($v['need_open_comment'] ?? 0) !== (int) ($old['need_open_comment'] ?? 0)
                    || (0 !== (int) ($v['need_open_comment'] ?? 0) && (int) ($v['only_fans_can_comment'] ?? 0) === (int) ($old['only_fans_can_comment'] ?? 0))) {
                    $needadd = true;
                }
            }
        }

        // 预处理：封面转 media_id、正文图片本地→微信
        $articles = [];
        foreach ($newsItem as $item) {
            $item = (array) $item;
            $item['thumb_media_id'] = $this->thumbToMediaId($aid, (string) ($item['thumb_url'] ?? ''));
            $item['content']        = $this->contentToWx((string) ($item['content'] ?? ''), $aid);
            unset($item['thumb_url']);
            $articles[] = $item;
        }

        $token = $this->open->isMock() ? '' : $this->bindingToken->accessToken('mp', $aid);

        if (!$needadd) {
            if (!$this->open->isMock()) {
                foreach ($articles as $k => $article) {
                    $this->assertApiOk($this->api->updateNews($token, $mediaId, $article, $k));
                }
            }
            $this->mirrorMaterial($aid, $mediaId, $articles);
            PlogService::write($aid, $this->uid(), '编辑公众号素材' . $mediaId);

            return $this->success(['media_id' => $mediaId], '提交成功');
        }

        if ($this->open->isMock()) {
            $mediaId = 'MOCKNEWS' . base_convert((string) time(), 10, 36) . random_int(100, 999);
        } else {
            $rs = $this->api->addNews($token, $articles);
            $this->assertApiOk($rs);
            $mediaId = (string) ($rs['media_id'] ?? '');
        }
        $this->mirrorMaterial($aid, $mediaId, $articles);
        PlogService::write($aid, $this->uid(), '添加公众号素材' . $mediaId);

        return $this->success(['media_id' => $mediaId], '提交成功');
    }

    /**
     * 图文素材删除
     * POST /admin/mpfans/sourceDelete {media_id}
     */
    public function sourceDelete()
    {
        $aid     = ScopeService::aidOf($this->request);
        $mediaId = trim((string) $this->request->post('media_id', ''));
        if ('' === $mediaId) {
            throw new BusinessException('缺少 media_id');
        }

        Db::name('mp_material')->where('aid', $aid)->where('media_id', $mediaId)->delete();
        PlogService::write($aid, $this->uid(), '删除公众号素材' . $mediaId);

        return $this->success([], '删除成功');
    }

    /** mp_material 本地镜像（add/update 后落库，列表/详情/群发数据源） */
    protected function mirrorMaterial(int $aid, string $mediaId, array $articles): void
    {
        $save = [
            'aid'         => $aid,
            'media_id'    => $mediaId,
            'type'        => 'news',
            'url'         => (string) ($articles[0]['thumb_url'] ?? ''),
            'description' => (string) json_encode(array_values($articles), JSON_UNESCAPED_UNICODE),
            'createtime'  => time(),
        ];

        $row = Db::name('mp_material')->where('aid', $aid)->where('media_id', $mediaId)->find();
        if (empty($row)) {
            Db::name('mp_material')->insert($save);
        } else {
            Db::name('mp_material')->where('id', $row['id'])->update($save);
        }
    }

    /** 封面图 → 微信 media_id（mock 直出；真实走 add_material） */
    protected function thumbToMediaId(int $aid, string $thumbUrl): string
    {
        if ('' === trim($thumbUrl)) {
            return '';
        }

        if ($this->open->isMock()) {
            return 'MOCKTHUMB_' . substr(md5($thumbUrl), 0, 16);
        }

        $path = $this->localPathOf($thumbUrl);
        if ('' === $path) {
            return '';
        }

        $rs = $this->api->addMaterial($this->bindingToken->accessToken('mp', $aid), 'image', $path);

        return (string) ($rs['media_id'] ?? '');
    }

    /** 正文图片本地→微信（pictolocal 缓存互转） */
    protected function contentToWx(string $content, int $aid): string
    {
        $content = (string) preg_replace_callback('/(<img.*?src=[\'|\"])([^\"\']*?)([\'|\"].*?[\/]?>)/is', function ($m) use ($aid) {
            return $m[1] . $this->picToWxUrl($aid, trim(str_replace('&quot;', '', $m[2]))) . $m[3];
        }, $content);

        return (string) preg_replace_callback('/(background(\-image)?\s*:\s*url\([|\"|\']?)([^\"\']*?)([|\"|\']?\))/is', function ($m) use ($aid) {
            return $m[1] . $this->picToWxUrl($aid, trim(str_replace('&quot;', '', $m[3]))) . $m[4];
        }, $content);
    }

    /** 正文图片微信→本地（pictolocal 反向缓存） */
    protected function contentToLocal(string $content, int $aid): string
    {
        $content = (string) preg_replace_callback('/(<img.*?src=[\'|\"])([^\"\']*?)([\'|\"].*?[\/]?>)/is', function ($m) use ($aid) {
            return $m[1] . $this->wxUrlToLocal($aid, trim(str_replace('&quot;', '', $m[2]))) . $m[3];
        }, $content);

        return (string) preg_replace_callback('/(background(\-image)?\s*:\s*url\([|\"|\']?)([^\"\']*?)([|\"|\']?\))/is', function ($m) use ($aid) {
            return $m[1] . $this->wxUrlToLocal($aid, trim(str_replace('&quot;', '', $m[3]))) . $m[4];
        }, $content);
    }

    /** 本地图片 → 微信图片 URL（pictolocal 记录映射） */
    protected function picToWxUrl(int $aid, string $pic): string
    {
        if ('' === trim($pic)) {
            return $pic;
        }
        $row = Db::name('pictolocal')->where('aid', $aid)->where('pic', $pic)->find();
        if (!empty($row)) {
            return (string) $row['url'];
        }

        $url = $pic;
        if ($this->open->isMock()) {
            $url = 'https://mmbiz.qpic.cn/mock/' . substr(md5($pic), 0, 16) . '/640';
        } else {
            $path = $this->localPathOf($pic);
            if ('' !== $path) {
                $rs = $this->api->uploadImg($this->bindingToken->accessToken('mp', $aid), $path);
                if (!empty($rs['url'])) {
                    $url = (string) $rs['url'];
                }
            }
        }

        Db::name('pictolocal')->insert(['aid' => $aid, 'pic' => $pic, 'url' => $url, 'createtime' => time()]);

        return $url;
    }

    /** 微信图片 URL → 本地图片（pictolocal 反向查询，mock 原样返回） */
    protected function wxUrlToLocal(int $aid, string $url): string
    {
        if ('' === trim($url)) {
            return $url;
        }
        $row = Db::name('pictolocal')->where('aid', $aid)->where('url', $url)->find();
        if (!empty($row)) {
            return (string) $row['pic'];
        }
        if ($this->open->isMock()) {
            return $url;
        }

        // 真实模式：下载到本地 uploads/wximg（失败原样返回）
        try {
            $bin = file_get_contents($url);
            if (false !== $bin && '' !== $bin) {
                $dir  = $this->app->getRootPath() . 'public/uploads/wximg/';
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                $file = $dir . md5($url) . '.jpg';
                file_put_contents($file, $bin);
                $pic = '/uploads/wximg/' . basename($file);
                Db::name('pictolocal')->insert(['aid' => $aid, 'pic' => $pic, 'url' => $url, 'createtime' => time()]);

                return $pic;
            }
        } catch (\Throwable) {
            // 下载失败降级为原 URL
        }

        return $url;
    }

    /** 站内图片相对路径 → 磁盘绝对路径（外链/不存在返回空） */
    protected function localPathOf(string $pic): string
    {
        if (str_starts_with($pic, 'http://') || str_starts_with($pic, 'https://')) {
            return '';
        }
        $path = $this->app->getRootPath() . 'public/' . ltrim($pic, '/');

        return is_file($path) ? $path : '';
    }

    /* ==================== 模板消息群发 ==================== */

    /**
     * 群发页初始数据（mp_tmplset 聚合模板 + 标签粉丝数 + 关注总数）
     * GET /admin/mpfans/tmplsendPrepare
     */
    public function tmplsendPrepare()
    {
        $aid = ScopeService::aidOf($this->request);

        $set    = Db::name('mp_tmplset')->where('aid', $aid)->find() ?: [];
        $setNew = Db::name('mp_tmplset_new')->where('aid', $aid)->find() ?: [];
        $templates = [];
        foreach ((array) $set as $k => $v) {
            if (!str_starts_with((string) $k, 'tmpl_') || 'tmpl_message_link' === $k) {
                continue;
            }
            $tid    = trim((string) $v);
            $tidNew = trim((string) ($setNew[$k] ?? ''));
            if ('' === $tid && '' === $tidNew) {
                continue;
            }
            $templates[] = [
                'field'           => (string) $k,
                'label'           => self::TMPL_LABELS[$k] ?? $k,
                'template_id'     => $tid,
                'template_id_new' => $tidNew,
            ];
        }

        $tags = Db::name('fans_tag')->where('aid', $aid)->order('tagid', 'asc')->select()->toArray();
        foreach ($tags as $k => $v) {
            $tags[$k]['count'] = Db::name('fans')->where('aid', $aid)->where('subscribe', 1)
                ->whereRaw('find_in_set(' . (int) $v['tagid'] . ',tag)')->count();
        }

        $fanscount = Db::name('fans')->where('aid', $aid)->where('subscribe', 1)->count();

        return $this->success(['templates' => $templates, 'tags' => $tags, 'fanscount' => $fanscount]);
    }

    /**
     * 模板消息群发（分页循环；logid 续发走 last_page 游标，不重发）
     * POST /admin/mpfans/tmplSend {info{title,template_id,url,to_type,tagid,openids}, data{}, pagenum, pagelimit, logid?}
     */
    public function tmplSend()
    {
        $aid       = ScopeService::aidOf($this->request);
        $info      = (array) $this->request->post('info', []);
        $data      = $this->normalizeTmplData((array) $this->request->post('data', []));
        $pagenum   = max(1, (int) $this->request->post('pagenum', 1));
        $pagelimit = min(max(1, (int) $this->request->post('pagelimit', 100)), 500);
        $logid     = (int) $this->request->post('logid', 0);

        if ($logid > 0) {
            $log = Db::name('mp_tmpl_sendlog')->where('aid', $aid)->where('id', $logid)->find();
            if (empty($log)) {
                throw new BusinessException('发送记录不存在');
            }
            $cursor = (int) $log['last_page'] + 1;
            $limit  = (int) $log['pagelimit'] > 0 ? (int) $log['pagelimit'] : $pagelimit;
        } else {
            $templateId = trim((string) ($info['template_id'] ?? ''));
            if ('' === $templateId) {
                throw new BusinessException('请选择模板');
            }
            $toType = (int) ($info['to_type'] ?? 3);
            if (!in_array($toType, [1, 2, 3], true)) {
                throw new BusinessException('发送目标类型无效');
            }
            $allopenids = [];
            if (1 === $toType) {
                $allopenids = $this->parseOpenids((string) ($info['openids'] ?? ''));
                if (empty($allopenids)) {
                    throw new BusinessException('请填写 openid');
                }
            }
            if (2 === $toType && (int) ($info['tagid'] ?? 0) <= 0) {
                throw new BusinessException('请选择标签');
            }

            $logid = (int) Db::name('mp_tmpl_sendlog')->insertGetId([
                'aid'          => $aid,
                'title'        => (string) ($info['title'] ?? ''),
                'template_id'  => $templateId,
                'data'         => (string) json_encode($data, JSON_UNESCAPED_UNICODE),
                'url'          => (string) ($info['url'] ?? ''),
                'to_type'      => $toType,
                'tagid'        => (int) ($info['tagid'] ?? 0),
                'openids'      => (string) json_encode($allopenids, JSON_UNESCAPED_UNICODE),
                'createtime'   => time(),
                'endtime'      => time(),
                'sendcount'    => 0,
                'successcount' => 0,
                'errorcount'   => 0,
                'last_page'    => 0,
                'pagelimit'    => $pagelimit,
                'isdel'        => 0,
            ]);
            $log    = Db::name('mp_tmpl_sendlog')->where('id', $logid)->find();
            $cursor = $pagenum;
            $limit  = $pagelimit;
        }

        $senddata = [
            'template_id' => (string) $log['template_id'],
            'data'        => $this->normalizeTmplData((array) (json_decode((string) $log['data'], true) ?: [])),
        ];
        if ('' !== trim((string) $log['url'])) {
            $senddata['url'] = (string) $log['url'];
        }

        $openids = $this->resolvePageOpenids($aid, (array) $log, $cursor, $limit);
        $stat    = $this->sendTmplBatch($aid, $logid, $openids, $senddata);

        Db::name('mp_tmpl_sendlog')->where('id', $logid)->update([
            'sendcount'    => Db::raw('sendcount+' . $stat['send']),
            'successcount' => Db::raw('successcount+' . $stat['ok']),
            'errorcount'   => Db::raw('errorcount+' . $stat['err']),
            'last_page'    => $cursor,
            'endtime'      => time(),
        ]);
        $log = Db::name('mp_tmpl_sendlog')->where('id', $logid)->find();

        return $this->success([
            'status'       => count($openids) < $limit ? 1 : 2,
            'logid'        => $logid,
            'pagenum'      => $cursor,
            'sendcount'    => (int) $log['sendcount'],
            'successcount' => (int) $log['successcount'],
            'errorcount'   => (int) $log['errorcount'],
        ]);
    }

    /** 发送一批模板消息（mock 直出，openid 含 ERR 模拟拒收；失败写 errlog 继续） */
    protected function sendTmplBatch(int $aid, int $logid, array $openids, array $senddata): array
    {
        $stat  = ['send' => 0, 'ok' => 0, 'err' => 0];
        $token = '';

        foreach ($openids as $openid) {
            if ($this->open->isMock()) {
                $rs = str_contains(strtoupper((string) $openid), 'ERR')
                    ? ['errcode' => 43101, 'errmsg' => 'user refuse to receive the message']
                    : ['errcode' => 0, 'errmsg' => 'ok', 'msgid' => crc32((string) $openid)];
            } else {
                if ('' === $token) {
                    $token = $this->bindingToken->accessToken('mp', $aid);
                }
                $senddata['touser'] = (string) $openid;
                $rs = $this->api->templateSend($token, $senddata);
            }

            if (0 === (int) ($rs['errcode'] ?? 0)) {
                ++$stat['ok'];
            } else {
                ++$stat['err'];
                Db::name('mp_tmpl_sendlog_errlog')->insert([
                    'aid'        => $aid,
                    'logid'      => $logid,
                    'openid'     => (string) $openid,
                    'errcode'    => (string) ($rs['errcode'] ?? ''),
                    'errmsg'     => mb_substr((string) ($rs['errmsg'] ?? ''), 0, 200),
                    'createtime' => time(),
                ]);
            }
            ++$stat['send'];
        }

        return $stat;
    }

    /**
     * 模板群发历史列表
     * GET /admin/mpfans/tmplSendLog
     */
    public function tmplSendLog()
    {
        $aid      = ScopeService::aidOf($this->request);
        $page     = max(1, (int) $this->request->param('page', 1));
        $pagesize = min(max(1, (int) $this->request->param('pagesize', 10)), 100);

        $query = Db::name('mp_tmpl_sendlog')->where('aid', $aid)->where('isdel', 0);
        $total = (clone $query)->count();
        $list  = $query->order('id', 'desc')->page($page, $pagesize)->select()->toArray();

        $tagMap = Db::name('fans_tag')->where('aid', $aid)->column('name', 'tagid');
        foreach ($list as $k => $v) {
            $list[$k]['data']    = json_decode((string) $v['data'], true) ?: [];
            $list[$k]['tagname'] = (string) ($tagMap[(int) $v['tagid']] ?? '');
        }

        return $this->success(['list' => $list, 'total' => $total]);
    }

    /**
     * 模板群发记录删除（软删）
     * POST /admin/mpfans/tmplSendLogDelete {ids[]}
     */
    public function tmplSendLogDelete()
    {
        $aid = ScopeService::aidOf($this->request);
        $ids = array_map('intval', (array) $this->request->post('ids', []));
        if (empty($ids)) {
            throw new BusinessException('请选择记录');
        }

        Db::name('mp_tmpl_sendlog')->where('aid', $aid)->whereIn('id', $ids)->update(['isdel' => 1]);

        return $this->success([], '删除成功');
    }

    /**
     * 模板群发失败明细
     * GET /admin/mpfans/tmplSendErrLog?logid=
     */
    public function tmplSendErrLog()
    {
        $aid      = ScopeService::aidOf($this->request);
        $logid    = (int) $this->request->param('logid', 0);
        $page     = max(1, (int) $this->request->param('page', 1));
        $pagesize = min(max(1, (int) $this->request->param('pagesize', 20)), 100);

        $query = Db::name('mp_tmpl_sendlog_errlog')->where('aid', $aid)->where('logid', $logid);
        $total = (clone $query)->count();
        $list  = $query->order('id', 'desc')->page($page, $pagesize)->select()->toArray();

        return $this->success(['list' => $list, 'total' => $total]);
    }

    /* ==================== 客服图文群发（48小时，素材库数据源） ==================== */

    /**
     * 客服图文群发（同模板群发分页引擎）
     * POST /admin/mpfans/kfmsgSend {info{title,media_id,to_type,tagid,openids}, pagenum, pagelimit, logid?}
     */
    public function kfmsgSend()
    {
        $aid       = ScopeService::aidOf($this->request);
        $info      = (array) $this->request->post('info', []);
        $pagenum   = max(1, (int) $this->request->post('pagenum', 1));
        $pagelimit = min(max(1, (int) $this->request->post('pagelimit', 100)), 500);
        $logid     = (int) $this->request->post('logid', 0);

        if ($logid > 0) {
            $log = Db::name('mp_kfmsg_sendlog')->where('aid', $aid)->where('id', $logid)->find();
            if (empty($log)) {
                throw new BusinessException('发送记录不存在');
            }
            $cursor = (int) $log['last_page'] + 1;
            $limit  = (int) $log['pagelimit'] > 0 ? (int) $log['pagelimit'] : $pagelimit;
        } else {
            $mediaId = trim((string) ($info['media_id'] ?? ''));
            if ('' === $mediaId) {
                throw new BusinessException('请选择素材');
            }
            $toType = (int) ($info['to_type'] ?? 3);
            if (!in_array($toType, [1, 2, 3], true)) {
                throw new BusinessException('发送目标类型无效');
            }
            $allopenids = [];
            if (1 === $toType) {
                $allopenids = $this->parseOpenids((string) ($info['openids'] ?? ''));
                if (empty($allopenids)) {
                    throw new BusinessException('请填写 openid');
                }
            }
            if (2 === $toType && (int) ($info['tagid'] ?? 0) <= 0) {
                throw new BusinessException('请选择标签');
            }

            $logid = (int) Db::name('mp_kfmsg_sendlog')->insertGetId([
                'aid'          => $aid,
                'title'        => (string) ($info['title'] ?? ''),
                'media_id'     => $mediaId,
                'to_type'      => $toType,
                'tagid'        => (int) ($info['tagid'] ?? 0),
                'openids'      => (string) json_encode($allopenids, JSON_UNESCAPED_UNICODE),
                'createtime'   => time(),
                'endtime'      => time(),
                'sendcount'    => 0,
                'successcount' => 0,
                'errorcount'   => 0,
                'last_page'    => 0,
                'pagelimit'    => $pagelimit,
                'isdel'        => 0,
            ]);
            $log    = Db::name('mp_kfmsg_sendlog')->where('id', $logid)->find();
            $cursor = $pagenum;
            $limit  = $pagelimit;
        }

        $openids = $this->resolvePageOpenids($aid, (array) $log, $cursor, $limit);
        $stat    = $this->sendKfmsgBatch($aid, $logid, $openids, (string) $log['media_id']);

        Db::name('mp_kfmsg_sendlog')->where('id', $logid)->update([
            'sendcount'    => Db::raw('sendcount+' . $stat['send']),
            'successcount' => Db::raw('successcount+' . $stat['ok']),
            'errorcount'   => Db::raw('errorcount+' . $stat['err']),
            'last_page'    => $cursor,
            'endtime'      => time(),
        ]);
        $log = Db::name('mp_kfmsg_sendlog')->where('id', $logid)->find();

        return $this->success([
            'status'       => count($openids) < $limit ? 1 : 2,
            'logid'        => $logid,
            'pagenum'      => $cursor,
            'sendcount'    => (int) $log['sendcount'],
            'successcount' => (int) $log['successcount'],
            'errorcount'   => (int) $log['errorcount'],
        ]);
    }

    /** 发送一批客服图文消息（mpnews；失败写 errlog 继续） */
    protected function sendKfmsgBatch(int $aid, int $logid, array $openids, string $mediaId): array
    {
        $stat  = ['send' => 0, 'ok' => 0, 'err' => 0];
        $token = '';

        foreach ($openids as $openid) {
            if ($this->open->isMock()) {
                $rs = str_contains(strtoupper((string) $openid), 'ERR')
                    ? ['errcode' => 45015, 'errmsg' => 'response out of time limit']
                    : ['errcode' => 0, 'errmsg' => 'ok'];
            } else {
                if ('' === $token) {
                    $token = $this->bindingToken->accessToken('mp', $aid);
                }
                $rs = $this->api->sendCustomerMessage($token, [
                    'touser'  => (string) $openid,
                    'msgtype' => 'mpnews',
                    'mpnews'  => ['media_id' => $mediaId],
                ]);
            }

            if (0 === (int) ($rs['errcode'] ?? 0)) {
                ++$stat['ok'];
            } else {
                ++$stat['err'];
                Db::name('mp_kfmsg_sendlog_errlog')->insert([
                    'aid'        => $aid,
                    'logid'      => $logid,
                    'openid'     => (string) $openid,
                    'errcode'    => (string) ($rs['errcode'] ?? ''),
                    'errmsg'     => mb_substr((string) ($rs['errmsg'] ?? ''), 0, 200),
                    'createtime' => time(),
                ]);
            }
            ++$stat['send'];
        }

        return $stat;
    }

    /**
     * 客服图文群发历史列表
     * GET /admin/mpfans/kfmsgSendLog
     */
    public function kfmsgSendLog()
    {
        $aid      = ScopeService::aidOf($this->request);
        $page     = max(1, (int) $this->request->param('page', 1));
        $pagesize = min(max(1, (int) $this->request->param('pagesize', 10)), 100);

        $query = Db::name('mp_kfmsg_sendlog')->where('aid', $aid)->where('isdel', 0);
        $total = (clone $query)->count();
        $list  = $query->order('id', 'desc')->page($page, $pagesize)->select()->toArray();

        $tagMap = Db::name('fans_tag')->where('aid', $aid)->column('name', 'tagid');
        foreach ($list as $k => $v) {
            $list[$k]['tagname'] = (string) ($tagMap[(int) $v['tagid']] ?? '');
        }

        return $this->success(['list' => $list, 'total' => $total]);
    }

    /**
     * 客服图文群发记录删除（软删）
     * POST /admin/mpfans/kfmsgSendLogDelete {ids[]}
     */
    public function kfmsgSendLogDelete()
    {
        $aid = ScopeService::aidOf($this->request);
        $ids = array_map('intval', (array) $this->request->post('ids', []));
        if (empty($ids)) {
            throw new BusinessException('请选择记录');
        }

        Db::name('mp_kfmsg_sendlog')->where('aid', $aid)->whereIn('id', $ids)->update(['isdel' => 1]);

        return $this->success([], '删除成功');
    }

    /**
     * 客服图文群发失败明细
     * GET /admin/mpfans/kfmsgSendErrLog?logid=
     */
    public function kfmsgSendErrLog()
    {
        $aid      = ScopeService::aidOf($this->request);
        $logid    = (int) $this->request->param('logid', 0);
        $page     = max(1, (int) $this->request->param('page', 1));
        $pagesize = min(max(1, (int) $this->request->param('pagesize', 20)), 100);

        $query = Db::name('mp_kfmsg_sendlog_errlog')->where('aid', $aid)->where('logid', $logid);
        $total = (clone $query)->count();
        $list  = $query->order('id', 'desc')->page($page, $pagesize)->select()->toArray();

        return $this->success(['list' => $list, 'total' => $total]);
    }

    /* ==================== 公共辅助 ==================== */

    /** 按日志行解析当页待发送 openid（to_type 1=openid清单切片 2=标签 3=全部，确定性排序保证续发一致） */
    protected function resolvePageOpenids(int $aid, array $log, int $cursor, int $limit): array
    {
        $toType = (int) $log['to_type'];
        if (1 === $toType) {
            $all = json_decode((string) $log['openids'], true);

            return array_slice(is_array($all) ? array_map('strval', $all) : [], ($cursor - 1) * $limit, $limit);
        }

        $query = Db::name('fans')->where('aid', $aid)->where('subscribe', 1);
        if (2 === $toType) {
            $query->whereRaw('find_in_set(' . (int) $log['tagid'] . ',tag)');
        }

        return array_map('strval', $query->order('id', 'asc')->page($cursor, $limit)->column('openid'));
    }

    /** openid 清单解析（换行/空白/逗号分隔 → 去重数组） */
    protected function parseOpenids(string $raw): array
    {
        $raw = preg_replace('/\s+/', ',', str_replace(["\r\n", "\r", "\n"], ',', $raw)) ?? '';
        $arr = explode(',', preg_replace('/,+/', ',', $raw) ?? '');

        return array_values(array_unique(array_filter($arr)));
    }

    /** 模板 data 归一（值统一 {value: xxx} 结构） */
    protected function normalizeTmplData(array $data): array
    {
        foreach ($data as $k => $v) {
            $data[(string) $k] = is_array($v) ? $v : ['value' => (string) $v];
        }

        return $data;
    }

    /** 微信接口 errcode 断言 */
    protected function assertApiOk(array $rs): void
    {
        $errcode = (int) ($rs['errcode'] ?? 0);
        if (0 !== $errcode) {
            throw new BusinessException('微信接口错误[' . $errcode . '] ' . (string) ($rs['errmsg'] ?? ''));
        }
    }

    protected function uid(): int
    {
        return (int) ($this->request->authUid ?? 0);
    }

    /* ==================== mock 数据集（确定性） ==================== */

    /** mock 粉丝 openid（每50个造一个含 ERR 的「拒收」粉丝，供 errlog 演练） */
    protected function mockFansOpenid(int $i): string
    {
        return ($i % 50 === 0 ? 'MOCKERROPEN' : 'MOCKOPEN') . str_pad((string) $i, 4, '0', STR_PAD_LEFT);
    }

    /** mock 粉丝信息（标签/关注状态按序号规律分布） */
    protected function mockFansInfo(string $openid): array
    {
        $i    = (int) substr($openid, -4);
        $tags = [];
        if ($i % 3 === 0) {
            $tags[] = 101;
        }
        if ($i % 5 === 0) {
            $tags[] = 102;
        }
        if ($i % 7 === 0) {
            $tags[] = 103;
        }

        return [
            'openid'          => $openid,
            'unionid'         => 'MOCKUNION' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
            'nickname'        => '模拟粉丝' . $i,
            'sex'             => [1, 2, 0][$i % 3],
            'city'            => '广州',
            'province'        => '广东',
            'country'         => '中国',
            'headimgurl'      => '',
            'subscribe'       => $i % 23 === 0 ? 0 : 1,
            'subscribe_time'  => 1755388800 + $i * 60,
            'subscribe_scene' => 'ADD_SCENE_SEARCH',
            'qr_scene'        => 0,
            'qr_scene_str'    => '',
            'language'        => 'zh_CN',
            'tagid_list'      => $tags,
        ];
    }
}
