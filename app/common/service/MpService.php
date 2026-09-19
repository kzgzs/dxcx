<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;
use think\file\UploadedFile;

/**
 * 租户后台 - 公众号运营服务（菜单 + 自动回复）
 *
 * 参照 canzhao\app\controller\Mpmenu.php / Mpkeyword.php 的路数重写：
 * - 菜单：mp_menu.menudata 存编辑器数据；「同步到微信」组装 button 报文调
 *   menuCreate（click 文本写 mp_menukey 映射、素材类先取 media_id），空菜单走 menuDelete；
 *   「获取线上菜单」getSelfMenuInfo 转换回填落库
 * - 关键字回复/被关注回复：mp_keyword（ktype 0完全匹配 1包含匹配 2关注回复），
 *   msgtype 七种（text/image/voice/video/music/news/miniprogrampage），
 *   素材类先 getMediaId 落 MediaId，文本类直接存内容；关注回复最多 3 条
 * - mock 模式（OpenPlatformService::isMock）所有微信调用返回预置成功报文
 */
class MpService
{
    /** 关注回复条数上限（对标参考系统） */
    public const SUBSCRIBE_MAX = 3;

    /** 消息类型白名单 */
    public const MSGTYPES = ['text', 'image', 'voice', 'video', 'music', 'news', 'miniprogrampage'];

    public function __construct(
        protected OpenPlatformService $open,
        protected BindingTokenService $bindingToken,
    ) {
    }

    /* ==================== 账号基础 ==================== */

    /**
     * 租户绑定的公众号 appid（admin_setapp_mp）；mock 无绑定时回落 Mock 授权方
     */
    public function resolveAppid(int $aid): string
    {
        $appid = (string) (Db::name('admin_setapp_mp')->where('aid', $aid)->value('appid') ?: '');
        if ('' === $appid) {
            if (!$this->open->isMock()) {
                throw new BusinessException('请先在平台绑定中心绑定微信公众号');
            }
            $appid = OpenPlatformService::MOCK_APPID;
        }

        return $appid;
    }

    /** 公众号 access_token（仅真实模式调用） */
    protected function token(int $aid): string
    {
        return $this->bindingToken->accessToken('mp', $aid);
    }

    /* ==================== 自定义菜单 ==================== */

    /**
     * 读取菜单编辑数据（无记录返回空数组）
     *
     * @return array<int, array<string, mixed>>
     */
    public function getMenu(int $aid): array
    {
        $raw = (string) (ScopeService::query('mp_menu', $aid)->value('menudata') ?: '');
        if ('' === $raw) {
            return [];
        }
        $data = json_decode($raw, true);

        return is_array($data) ? array_values($data) : [];
    }

    /**
     * 保存菜单编辑数据（仅落 mp_menu，不同步微信）
     *
     * @param array<int, array<string, mixed>> $menudata
     */
    public function saveMenu(int $aid, array $menudata): void
    {
        $menudata = array_values($menudata);
        if (count($menudata) > 3) {
            throw new BusinessException('一级菜单最多 3 个');
        }
        foreach ($menudata as $btn) {
            $this->assertButton($btn);
        }

        $json = (string) json_encode($menudata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $row  = ScopeService::query('mp_menu', $aid)->find();
        if (empty($row)) {
            Db::name('mp_menu')->insert(['aid' => $aid, 'menudata' => $json, 'createtime' => time()]);
        } else {
            Db::name('mp_menu')->where('id', $row['id'])->update(['menudata' => $json, 'updatetime' => time()]);
        }
    }

    /**
     * 同步菜单到微信（menuCreate；空菜单走 menuDelete）
     *
     * @return array<string, mixed> 组装后的 button 报文概要
     */
    public function syncMenu(int $aid): array
    {
        $menudata = $this->getMenu($aid);
        $appid    = $this->resolveAppid($aid);

        if (empty($menudata)) {
            if (!$this->open->isMock()) {
                $rs = $this->open->callWithAuthorizerToken($appid, fn (string $token) => $this->open->api()->menuDelete($token));
                $this->checkErr($rs, 'menu/delete');
            }

            return ['action' => 'delete', 'button' => [], 'is_mock' => $this->open->isMock()];
        }

        $buttons = [];
        foreach ($menudata as $btn) {
            $buttons[] = $this->buildButton($aid, $btn);
        }

        if (!$this->open->isMock()) {
            $rs = $this->open->callWithAuthorizerToken($appid, fn (string $token) => $this->open->api()->menuCreate($token, ['button' => $buttons]));
            $this->checkErr($rs, 'menu/create');
        }

        return ['action' => 'create', 'button' => $buttons, 'is_mock' => $this->open->isMock()];
    }

    /**
     * 获取线上菜单并回填本地（get_current_selfmenu_info → menudata 结构）
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchOnlineMenu(int $aid): array
    {
        $appid = $this->resolveAppid($aid);

        if ($this->open->isMock()) {
            // mock 预置线上菜单（view + 含二级子菜单的混合结构）
            $buttons = [
                ['name' => '商城首页', 'type' => 'view', 'url' => 'https://mock.dxcx.local/h5/index.html'],
                ['name' => '更多服务', 'sub_button' => ['list' => [
                    ['name' => '联系客服', 'type' => 'click', 'key' => 'MOCK_ONLINE_KEY_1'],
                    ['name' => '小程序', 'type' => 'miniprogram', 'appid' => OpenPlatformService::MOCK_APPID, 'pagepath' => 'pages/index/index', 'url' => 'https://mock.dxcx.local/h5/1.html'],
                ]]],
            ];
        } else {
            $rs = $this->open->callWithAuthorizerToken($appid, fn (string $token) => $this->open->api()->menuGetCurrentSelfInfo($token));
            $this->checkErr($rs, 'get_current_selfmenu_info');
            $buttons = (array) ($rs['selfmenu_info']['button'] ?? []);
        }

        $menudata = $this->transformOnlineButtons($buttons);
        $this->saveMenu($aid, $menudata);

        return $menudata;
    }

    /** 线上 button 结构 → 本地 menudata 结构 */
    protected function transformOnlineButtons(array $buttons): array
    {
        $out = [];
        foreach ($buttons as $v) {
            $item = ['menu_name' => (string) ($v['name'] ?? '')];
            $subs = $v['sub_button']['list'] ?? ($v['sub_button'] ?? null);
            if (is_array($subs) && !empty($subs)) {
                $subOut = [];
                foreach ($subs as $v2) {
                    $subOut[] = $this->transformOnlineLeaf((array) $v2);
                }
                $item['sub_button'] = $subOut;
                $out[]              = $item;
                continue;
            }
            $out[] = array_merge($item, $this->transformOnlineLeaf($v));
        }

        return $out;
    }

    /** 线上单个叶子按钮 → 本地结构 @param array<string, mixed> $v */
    protected function transformOnlineLeaf(array $v): array
    {
        $item = ['menu_name' => (string) ($v['name'] ?? '')];
        $type = (string) ($v['type'] ?? '');

        if ('view' === $type) {
            $item['menu_type'] = 'view';
            $item['menu_url']  = (string) ($v['url'] ?? '');
        } elseif ('miniprogram' === $type) {
            $item['menu_type']     = 'miniprogram';
            $item['menu_appid']    = (string) ($v['appid'] ?? '');
            $item['menu_pagepath'] = (string) ($v['pagepath'] ?? '');
            $item['menu_url']      = (string) ($v['url'] ?? '');
        } elseif ('media_id' === $type) {
            $item['menu_type']    = 'click';
            $item['message_type'] = 'news';
            $item['news_content'] = (string) ($v['media_id'] ?? '');
        } elseif ('click' === $type) {
            $item['menu_type']    = 'click';
            $item['message_type'] = 'text';
            $key                  = (string) ($v['key'] ?? '');
            $item['text_content'] = (string) (Db::name('mp_menukey')->where('key', $key)->value('val') ?: '');
        } else {
            $item['menu_type'] = '' !== $type ? $type : 'view';
        }

        return $item;
    }

    /**
     * 单个一级按钮 → 微信 button 报文（含二级）
     *
     * @param array<string, mixed> $btn
     * @return array<string, mixed>
     */
    protected function buildButton(int $aid, array $btn): array
    {
        $name = trim((string) ($btn['menu_name'] ?? ''));
        if ('' === $name) {
            throw new BusinessException('请填写菜单名称');
        }
        if (mb_strlen($name) > 4) {
            throw new BusinessException('一级菜单「' . $name . '」名称过长（最多4个汉字）');
        }

        $new = ['name' => $name];
        $subs = (array) ($btn['sub_button'] ?? []);
        if (!empty($subs)) {
            if (count($subs) > 5) {
                throw new BusinessException('菜单「' . $name . '」的子菜单最多 5 个');
            }
            $list = [];
            foreach ($subs as $sub) {
                $list[] = $this->buildLeaf($aid, (array) $sub, true);
            }
            $new['sub_button'] = $list;

            return $new;
        }

        return array_merge($new, $this->buildLeaf($aid, $btn, false));
    }

    /**
     * 叶子按钮 → 微信报文（view/click/miniprogram）
     *
     * @param array<string, mixed> $btn
     * @return array<string, mixed>
     */
    protected function buildLeaf(int $aid, array $btn, bool $isSub): array
    {
        $name = trim((string) ($btn['menu_name'] ?? ''));
        if ('' === $name) {
            throw new BusinessException('请填写菜单名称');
        }
        if (mb_strlen($name) > ($isSub ? 8 : 4)) {
            throw new BusinessException('菜单「' . $name . '」名称过长（' . ($isSub ? '子菜单最多8个汉字' : '一级菜单最多4个汉字') . '）');
        }

        $type = (string) ($btn['menu_type'] ?? '');
        $out  = ['name' => $name];

        if ('view' === $type) {
            $url = trim((string) ($btn['menu_url'] ?? ''));
            if ('' === $url) {
                throw new BusinessException('菜单「' . $name . '」请填写链接地址');
            }
            $out['type'] = 'view';
            $out['url']  = $url;

            return $out;
        }

        if ('miniprogram' === $type) {
            $appid    = trim((string) ($btn['menu_appid'] ?? ''));
            $pagepath = trim((string) ($btn['menu_pagepath'] ?? ''));
            if ('' === $appid || '' === $pagepath) {
                throw new BusinessException('菜单「' . $name . '」请填写小程序 appid 与页面路径');
            }
            $out['type']     = 'miniprogram';
            $out['appid']    = $appid;
            $out['pagepath'] = $pagepath;
            // 兜底 H5：未填写时按租户 H5 入口拼装（对标参考系统 PRE_URL2/h5/{aid}.html）
            $url         = trim((string) ($btn['menu_url'] ?? ''));
            $out['url']  = '' !== $url ? $url : request()->domain() . '/h5/' . $aid . '.html#/' . $pagepath;

            return $out;
        }

        // click 类：按消息内容拆 media_id / click+key
        $msgtype = (string) ($btn['message_type'] ?? 'text');
        if ('news' === $msgtype) {
            $mediaId = trim((string) ($btn['news_content'] ?? ''));
            if ('' === $mediaId) {
                throw new BusinessException('菜单「' . $name . '」请选择图文素材');
            }
            $out['type']     = 'media_id';
            $out['media_id'] = $mediaId;

            return $out;
        }
        if ('image' === $msgtype || 'voice' === $msgtype) {
            $url = trim((string) ($btn[$msgtype . '_content'] ?? ''));
            if ('' === $url) {
                throw new BusinessException('菜单「' . $name . '」请上传' . ('image' === $msgtype ? '图片' : '语音') . '素材');
            }
            $out['type']     = 'media_id';
            $out['media_id'] = $this->getMediaId($aid, $url, $msgtype);

            return $out;
        }
        if ('video' === $msgtype) {
            $url = trim((string) ($btn['video_content'] ?? ''));
            if ('' === $url || '' === trim((string) ($btn['video_title'] ?? '')) || '' === trim((string) ($btn['video_introduction'] ?? ''))) {
                throw new BusinessException('菜单「' . $name . '」请上传视频并填写视频标题及描述');
            }
            $out['type']     = 'media_id';
            $out['media_id'] = $this->getMediaId($aid, $url, 'video', (string) json_encode([
                'title'        => (string) $btn['video_title'],
                'introduction' => (string) $btn['video_introduction'],
            ], JSON_UNESCAPED_UNICODE));

            return $out;
        }

        // 文本 click：生成 key 写 mp_menukey 映射
        $text = trim((string) ($btn['text_content'] ?? ''));
        if ('' === $text) {
            throw new BusinessException('菜单「' . $name . '」请填写回复文本');
        }
        $key = uniqid() . '_' . random_int(1000, 9999);
        Db::name('mp_menukey')->insert(['key' => $key, 'val' => $text]);
        $out['type'] = 'click';
        $out['key']  = $key;

        return $out;
    }

    /** 菜单结构基础校验（保存时用，不触微信） @param array<string, mixed> $btn */
    protected function assertButton(array $btn): void
    {
        if ('' === trim((string) ($btn['menu_name'] ?? ''))) {
            throw new BusinessException('请填写菜单名称');
        }
        $subs = (array) ($btn['sub_button'] ?? []);
        if (count($subs) > 5) {
            throw new BusinessException('每个一级菜单下最多 5 个子菜单');
        }
    }

    /* ==================== 素材 ==================== */

    /** 素材文件允许的类型（公众号菜单/关键字回复素材上传） */
    protected const MEDIA_EXT = [
        'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'],
        'voice' => ['mp3', 'amr', 'wav'],
        'video' => ['mp4'],
    ];

    /**
     * 素材文件上传（image/voice 不超 2MB，video 不超 10MB，对标微信素材限制）
     *
     * @return array{url: string} 站点相对路径
     */
    public function uploadMediaFile(UploadedFile $file, int $aid, string $type): array
    {
        if ($aid <= 0) {
            throw new BusinessException('非法的租户标识', 403, 403);
        }
        if (!isset(self::MEDIA_EXT[$type])) {
            throw new BusinessException('不支持的素材类型：' . $type);
        }

        $extension = strtolower($file->getOriginalExtension());
        if (!in_array($extension, self::MEDIA_EXT[$type], true)) {
            throw new BusinessException('仅支持 ' . implode('/', self::MEDIA_EXT[$type]) . ' 格式的' . ('image' === $type ? '图片' : ('voice' === $type ? '语音' : '视频')) . '素材');
        }

        $maxSize = 'video' === $type ? 10 * 1024 * 1024 : 2 * 1024 * 1024;
        if ($file->getSize() > $maxSize) {
            throw new BusinessException('素材大小不能超过 ' . ('video' === $type ? '10MB' : '2MB'));
        }

        $dir      = app()->getRootPath() . 'public/uploads/tenant_' . $aid . '/mp';
        $saveName = date('Ymd') . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
        $file->move($dir, $saveName);

        return ['url' => '/uploads/tenant_' . $aid . '/mp/' . $saveName];
    }

    /**
     * 本地文件换取微信 media_id（永久素材；mp_material 做 url→media_id 缓存）
     */
    public function getMediaId(int $aid, string $url, string $type = 'image', string $description = ''): string
    {
        $url = trim($url);
        if ('' === $url) {
            throw new BusinessException('素材地址不能为空');
        }
        if (!in_array($type, ['image', 'voice', 'video', 'thumb'], true)) {
            throw new BusinessException('不支持的素材类型：' . $type);
        }

        $row = ScopeService::query('mp_material', $aid)->where('url', $url)->where('type', $type)->find();
        if (!empty($row) && '' !== (string) $row['media_id']) {
            return (string) $row['media_id'];
        }

        if ($this->open->isMock()) {
            $mediaId = 'MOCK_MEDIA_' . substr(md5($aid . $type . $url), 0, 16);
        } else {
            $path = $this->resolveLocalPath($url);
            $rs   = $this->open->callWithAuthorizerToken($this->resolveAppid($aid), fn (string $token) => $this->open->api()->addMaterial($token, $type, $path));
            $this->checkErr($rs, 'add_material');
            $mediaId = (string) ($rs['media_id'] ?? '');
            if ('' === $mediaId) {
                throw new BusinessException('add_material 未返回 media_id');
            }
        }

        Db::name('mp_material')->insert([
            'aid'         => $aid,
            'url'         => $url,
            'media_id'    => $mediaId,
            'createtime'  => time(),
            'type'        => $type,
            'description' => mb_substr($description, 0, 250),
        ]);

        return $mediaId;
    }

    /**
     * 图文素材列表（关键字回复 news 选择器；mock 返回预置素材）
     *
     * @return array{count: int, data: array<int, mixed>}
     */
    public function newsList(int $aid, int $page = 1, int $limit = 20): array
    {
        $this->resolveAppid($aid);

        if ($this->open->isMock()) {
            $items = [];
            for ($i = 1; $i <= 3; $i++) {
                $items[] = [
                    'media_id'    => 'MOCK_NEWS_MEDIA_' . $i,
                    'update_time' => time(),
                    'content'     => ['news_item' => [[
                        'title'     => 'Mock图文素材' . $i,
                        'digest'    => '图文素材摘要' . $i,
                        'thumb_url' => 'https://mock.dxcx.local/news/' . $i . '.png',
                        'url'       => 'https://mock.dxcx.local/news/' . $i . '.html',
                    ]]],
                ];
            }

            return ['count' => count($items), 'data' => $items];
        }

        $rs = $this->open->callWithAuthorizerToken($this->resolveAppid($aid), fn (string $token) => $this->open->api()->batchGetMaterial($token, 'news', max(0, ($page - 1) * $limit), $limit));
        $this->checkErr($rs, 'batchget_material');

        return ['count' => (int) ($rs['total_count'] ?? 0), 'data' => array_values((array) ($rs['item'] ?? []))];
    }

    /** url → 本地文件路径（站内 /uploads 直取，外链临时下载） */
    protected function resolveLocalPath(string $url): string
    {
        if (preg_match('#^https?://#i', $url)) {
            $tmp = app()->getRuntimePath() . 'mp_media/' . md5($url) . '_' . basename(parse_url($url, PHP_URL_PATH) ?: 'media');
            if (!is_file($tmp)) {
                $bin = @file_get_contents($url);
                if (false === $bin) {
                    throw new BusinessException('素材文件下载失败：' . $url);
                }
                if (!is_dir(dirname($tmp))) {
                    mkdir(dirname($tmp), 0755, true);
                }
                file_put_contents($tmp, $bin);
            }

            return $tmp;
        }

        $path = app()->getRootPath() . 'public/' . ltrim($url, '/');
        if (!is_file($path)) {
            throw new BusinessException('素材文件不存在：' . $url);
        }

        return $path;
    }

    /* ==================== 关键字回复 ==================== */

    /**
     * 关键字回复列表（ktype 0/1，分页 + 关键字检索）
     *
     * @param array<string, mixed> $params page/limit/keyword
     * @return array{count: int, data: array<int, array<string, mixed>>}
     */
    public function keywordList(int $aid, array $params): array
    {
        $page  = max(1, (int) ($params['page'] ?? 1));
        $limit = min(100, max(1, (int) ($params['limit'] ?? 15)));

        $query = ScopeService::query('mp_keyword', $aid)->whereIn('ktype', [0, 1]);
        $kw    = trim((string) ($params['keyword'] ?? ''));
        if ('' !== $kw) {
            $query->where('keyword', 'like', '%' . $kw . '%');
        }

        $count = (clone $query)->count();
        $data  = $query->page($page, $limit)->order('sort desc, id desc')->select()->toArray();

        return ['count' => $count, 'data' => $data];
    }

    /**
     * 关键字回复保存（新增/编辑；素材类自动换取 MediaId）
     *
     * @param array<string, mixed> $data id/keyword/ktype/msgtype/sort + 各类型内容字段
     * @return int 记录 ID
     */
    public function keywordSave(int $aid, array $data): int
    {
        $ktype = (int) ($data['ktype'] ?? 0);
        if (!in_array($ktype, [0, 1, 2], true)) {
            throw new BusinessException('非法的匹配类型');
        }

        return $this->saveKeywordRow($aid, $data, $ktype);
    }

    /**
     * 关键字回复删除
     *
     * @param array<int, int> $ids
     */
    public function keywordDelete(int $aid, array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids), fn ($v) => $v > 0));
        if (empty($ids)) {
            throw new BusinessException('请选择要删除的记录');
        }
        ScopeService::query('mp_keyword', $aid)->whereIn('id', $ids)->delete();
    }

    /* ==================== 被关注回复 ==================== */

    /**
     * 被关注回复列表（ktype=2，按创建时间升序；最多3条）
     *
     * @return array<int, array<string, mixed>>
     */
    public function subscribeList(int $aid): array
    {
        return ScopeService::query('mp_keyword', $aid)->where('ktype', 2)
            ->order('createtime asc, id asc')->select()->toArray();
    }

    /**
     * 被关注回复保存（新增时校验 3 条上限）
     *
     * @param array<string, mixed> $data
     * @return int 记录 ID
     */
    public function subscribeSave(int $aid, array $data): int
    {
        $id = (int) ($data['id'] ?? 0);
        if ($id <= 0) {
            $count = ScopeService::query('mp_keyword', $aid)->where('ktype', 2)->count();
            if ($count >= self::SUBSCRIBE_MAX) {
                throw new BusinessException('被关注回复最多设置 ' . self::SUBSCRIBE_MAX . ' 条');
            }
        }

        return $this->saveKeywordRow($aid, $data, 2);
    }

    /**
     * 被关注回复删除
     *
     * @param array<int, int> $ids
     */
    public function subscribeDelete(int $aid, array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids), fn ($v) => $v > 0));
        if (empty($ids)) {
            throw new BusinessException('请选择要删除的记录');
        }
        ScopeService::query('mp_keyword', $aid)->where('ktype', 2)->whereIn('id', $ids)->delete();
    }

    /* ==================== 内部工具 ==================== */

    /**
     * 落库单条回复记录（ktype 由调用方定；content 按 msgtype 组装）
     *
     * @param array<string, mixed> $data
     */
    protected function saveKeywordRow(int $aid, array $data, int $ktype): int
    {
        $msgtype = (string) ($data['msgtype'] ?? '');
        if (!in_array($msgtype, self::MSGTYPES, true)) {
            throw new BusinessException('不支持的消息类型：' . $msgtype);
        }

        $row = [
            'ktype'    => $ktype,
            'msgtype'  => $msgtype,
            'content'  => $this->buildReplyContent($aid, $msgtype, $data),
            'sort'     => (int) ($data['sort'] ?? 0),
            'status'   => 1,
        ];
        if (2 !== $ktype) {
            $keyword = trim((string) ($data['keyword'] ?? ''));
            if ('' === $keyword) {
                throw new BusinessException('请填写关键字');
            }
            $row['keyword'] = mb_substr($keyword, 0, 250);
        } else {
            $row['keyword'] = '';
        }

        $id = (int) ($data['id'] ?? 0);
        if ($id > 0) {
            $exists = ScopeService::query('mp_keyword', $aid)->where('id', $id)->find();
            if (empty($exists)) {
                throw new BusinessException('记录不存在');
            }
            ScopeService::query('mp_keyword', $aid)->where('id', $id)->update($row);

            return $id;
        }

        $row['aid']        = $aid;
        $row['createtime'] = time();

        return (int) Db::name('mp_keyword')->insertGetId($row);
    }

    /**
     * 按消息类型组装 content（文本直存；素材类 JSON 含 MediaId）
     *
     * @param array<string, mixed> $d
     */
    protected function buildReplyContent(int $aid, string $msgtype, array $d): string
    {
        switch ($msgtype) {
            case 'text':
                $text = trim((string) ($d['text'] ?? ''));
                if ('' === $text) {
                    throw new BusinessException('请填写回复文本内容');
                }

                return $text;

            case 'image':
                $url = trim((string) ($d['url'] ?? ''));
                if ('' === $url) {
                    throw new BusinessException('请上传图片素材');
                }

                return (string) json_encode(['url' => $url, 'MediaId' => $this->getMediaId($aid, $url, 'image')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            case 'voice':
                $url = trim((string) ($d['url'] ?? ''));
                if ('' === $url) {
                    throw new BusinessException('请上传语音素材');
                }

                return (string) json_encode(['url' => $url, 'MediaId' => $this->getMediaId($aid, $url, 'voice')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            case 'video':
                $url = trim((string) ($d['url'] ?? ''));
                if ('' === $url) {
                    throw new BusinessException('请上传视频素材');
                }
                $video = [
                    'url'         => $url,
                    'title'       => (string) ($d['title'] ?? ''),
                    'description' => (string) ($d['description'] ?? ''),
                ];
                $video['MediaId'] = $this->getMediaId($aid, $url, 'video', (string) json_encode([
                    'title'        => $video['title'],
                    'introduction' => $video['description'],
                ], JSON_UNESCAPED_UNICODE));

                return (string) json_encode($video, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            case 'music':
                $music = [
                    'title'       => (string) ($d['title'] ?? ''),
                    'description' => (string) ($d['description'] ?? ''),
                    'musicurl'    => (string) ($d['musicurl'] ?? ''),
                    'hqmusicurl'  => (string) ($d['hqmusicurl'] ?? ''),
                    'thumburl'    => (string) ($d['thumburl'] ?? ''),
                ];
                if ('' === $music['title'] || '' === $music['musicurl']) {
                    throw new BusinessException('请填写音乐标题与音乐链接');
                }

                return (string) json_encode($music, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            case 'news':
                $mediaId = trim((string) ($d['media_id'] ?? ''));
                if ('' === $mediaId) {
                    throw new BusinessException('请选择图文素材');
                }

                return (string) json_encode([
                    'media_id' => $mediaId,
                    'title'    => (string) ($d['title'] ?? ''),
                    'url'      => (string) ($d['url'] ?? ''),
                    'digest'   => (string) ($d['digest'] ?? ''),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            case 'miniprogrampage':
                $pic = trim((string) ($d['pic'] ?? ''));
                $app = trim((string) ($d['appid'] ?? ''));
                $pagepath = trim((string) ($d['pagepath'] ?? ''));
                if ('' === $app || '' === $pagepath || '' === $pic) {
                    throw new BusinessException('请完整填写小程序 appid、页面路径并上传封面图');
                }

                return (string) json_encode([
                    'appid'    => $app,
                    'pagepath' => $pagepath,
                    'pic'      => $pic,
                    'MediaId'  => $this->getMediaId($aid, $pic, 'image'),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        throw new BusinessException('不支持的消息类型：' . $msgtype);
    }

    /** 微信错误码检查 */
    protected function checkErr(array $rs, string $api): void
    {
        $errcode = (int) ($rs['errcode'] ?? 0);
        if (0 !== $errcode) {
            throw new BusinessException($api . ' 失败[' . $errcode . '] ' . (string) ($rs['errmsg'] ?? ''));
        }
    }
}
