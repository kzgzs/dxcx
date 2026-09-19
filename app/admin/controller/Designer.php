<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use think\facade\Db;
use think\facade\Request;

/**
 * 租户后台 - 页面设计器（MVP）
 *
 * 数据结构（对齐参考系统口径，表述自有）：
 * - designerpage：pageinfo 固定首项 topbar（title/bgcolor/needlogin/quanxian），content 为组件数组
 * - 组件统一结构 {id:'M'+时间戳, temp, params, data}
 * - 保存即发布，无草稿态；设首页先清零同租户同标记的其他页，保证唯一
 */
class Designer extends BaseController
{
    /** 底部导航八端枚举（对齐参考系统口径） */
    private const NAV_PLATFORMS = ['mp', 'wx', 'alipay', 'baidu', 'toutiao', 'qq', 'h5', 'app'];

    /** 底部导航 tab 数量限制（微信 tabBar 规范：2~5 项） */
    private const NAV_ITEM_MIN = 2;
    private const NAV_ITEM_MAX = 5;

    /**
     * 页面列表
     * GET /admin/designer/page/list?type=0&page=1&limit=50&name=标题搜索
     */
    public function pageList()
    {
        $aid    = (int) ($this->request->aid ?? 0);
        $type   = (int) Request::get('type', 0);
        $page   = max(1, (int) Request::get('page', 1));
        $limit  = min(100, max(1, (int) Request::get('limit', 50)));
        $ishome = Request::get('ishome', '');
        $name   = trim((string) Request::get('name', ''));

        $query = Db::name('designerpage')->where('aid', $aid)->where('type', $type);
        if ($ishome !== '' && $ishome !== null) {
            $query->where('ishome', (int) $ishome);
        }
        if ($name !== '') {
            $query->where('name', 'like', '%' . $name . '%');
        }

        $count = (clone $query)->count();
        $list  = $query
            ->orderRaw('if(ishome=0,999,ishome), id desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        foreach ($list as &$item) {
            $info           = json_decode((string) ($item['pageinfo'] ?? ''), true) ?: [];
            $item['title']  = $info[0]['params']['title'] ?? ($item['name'] ?? '未命名页面');
            $item['ishome'] = (int) ($item['ishome'] ?? 0);
            $item['create_time'] = $item['createtime'] ? date('Y-m-d H:i', (int) $item['createtime']) : '—';
            $item['update_time'] = $item['updatetime'] ? date('Y-m-d H:i', (int) $item['updatetime']) : '—';
            unset($item['pageinfo'], $item['content']);
        }
        unset($item);

        return $this->success(['count' => $count, 'list' => $list]);
    }

    /**
     * 页面详情（编辑器回显 pageinfo + content）
     * GET /admin/designer/page/detail?id=
     */
    public function pageDetail()
    {
        $aid = (int) ($this->request->aid ?? 0);
        $id  = (int) Request::get('id', 0);
        if ($id <= 0) {
            return $this->error('参数错误');
        }

        $info = Db::name('designerpage')->where('aid', $aid)->where('id', $id)->find();
        if (empty($info)) {
            return $this->error('页面不存在');
        }

        return $this->success([
            'id'       => (int) $info['id'],
            'name'     => (string) ($info['name'] ?? ''),
            'ishome'   => (int) ($info['ishome'] ?? 0),
            'pageinfo' => json_decode((string) ($info['pageinfo'] ?? ''), true) ?: [],
            'content'  => json_decode((string) ($info['content'] ?? ''), true) ?: [],
        ]);
    }

    /**
     * 保存页面（保存即发布，无草稿）
     * POST /admin/designer/page/save  {id?, pageinfo:[], content:[]}
     */
    public function pageSave()
    {
        $aid      = (int) ($this->request->aid ?? 0);
        $id       = (int) Request::post('id', 0);
        $pageinfo = Request::post('pageinfo/a', []);
        $content  = Request::post('content/a', []);

        if (empty($pageinfo) || !is_array($pageinfo)) {
            return $this->error('页面信息不能为空');
        }
        $topbar = $pageinfo[0] ?? [];
        if (($topbar['temp'] ?? '') !== 'topbar') {
            return $this->error('页面信息首项必须为 topbar');
        }
        $title = trim((string) ($topbar['params']['title'] ?? ''));
        if ($title === '') {
            return $this->error('请填写页面标题');
        }
        if (!is_array($content)) {
            return $this->error('组件内容格式错误');
        }

        $data = [
            'name'       => $title,
            'pageinfo'   => json_encode($pageinfo, JSON_UNESCAPED_UNICODE),
            'content'    => json_encode($content, JSON_UNESCAPED_UNICODE),
            'updatetime' => time(),
        ];

        if ($id > 0) {
            $exists = Db::name('designerpage')->where('aid', $aid)->where('id', $id)->find();
            if (empty($exists)) {
                return $this->error('页面不存在');
            }
            Db::name('designerpage')->where('aid', $aid)->where('id', $id)->update($data);
            $pageId = $id;
        } else {
            // type 白名单：0常规页面 2外卖页 3点餐页（餐饮设计页双挂，P24-6 任务226）
            $type = (int) Request::post('type', 0);
            if (!in_array($type, [0, 2, 3], true)) {
                $type = 0;
            }
            $data['aid']        = $aid;
            $data['bid']        = 0;
            $data['type']       = $type;
            $data['ishome']     = 0;
            $data['createtime'] = time();
            $pageId = (int) Db::name('designerpage')->insertGetId($data);
        }

        return $this->success(['id' => $pageId], '保存成功，已发布');
    }

    /**
     * 删除页面
     * POST /admin/designer/page/del  {id}
     */
    public function pageDel()
    {
        $aid = (int) ($this->request->aid ?? 0);
        $id  = (int) Request::post('id', 0);
        if ($id <= 0) {
            return $this->error('参数错误');
        }

        $info = Db::name('designerpage')->where('aid', $aid)->where('id', $id)->find();
        if (empty($info)) {
            return $this->error('页面不存在');
        }

        // 首页 / 会员中心页禁止删除（对照参考系统口径，任务275）
        if ((int) ($info['ishome'] ?? 0) === 1) {
            return $this->error('首页页面不可删除，请先取消首页设置');
        }
        if ((int) ($info['ishome'] ?? 0) === 2) {
            return $this->error('会员中心页面不可删除，请先取消会员中心设置');
        }

        Db::name('designerpage')->where('aid', $aid)->where('id', $id)->delete();

        return $this->success([], '删除成功');
    }

    /**
     * 复制页面（新页不继承首页标记）
     * POST /admin/designer/page/copy  {id}
     */
    public function pageCopy()
    {
        $aid = (int) ($this->request->aid ?? 0);
        $id  = (int) Request::post('id', 0);
        if ($id <= 0) {
            return $this->error('参数错误');
        }

        $info = Db::name('designerpage')->where('aid', $aid)->where('id', $id)->find();
        if (empty($info)) {
            return $this->error('页面不存在');
        }

        $now = time();
        $newId = (int) Db::name('designerpage')->insertGetId([
            'aid'        => $aid,
            'bid'        => 0,
            'type'       => (int) ($info['type'] ?? 0),
            'name'       => ($info['name'] ?? '页面') . '（副本）',
            'ishome'     => 0,
            'gid'        => (int) ($info['gid'] ?? 0),
            'pageinfo'   => (string) $info['pageinfo'],
            'content'    => (string) $info['content'],
            'createtime' => $now,
            'updatetime' => $now,
        ]);

        return $this->success(['id' => $newId], '复制成功');
    }

    /**
     * 设为首页（先把同租户同标记的其他页清零，保证唯一）
     * POST /admin/designer/page/sethome  {id, ishome=1}
     */
    public function pageSethome()
    {
        $aid    = (int) ($this->request->aid ?? 0);
        $id     = (int) Request::post('id', 0);
        $ishome = (int) Request::post('ishome', 1);
        if ($id <= 0 || $ishome <= 0) {
            return $this->error('参数错误');
        }

        $info = Db::name('designerpage')->where('aid', $aid)->where('id', $id)->find();
        if (empty($info)) {
            return $this->error('页面不存在');
        }

        Db::name('designerpage')->where('aid', $aid)->where('ishome', $ishome)->where('type', (int) ($info['type'] ?? 0))->update(['ishome' => 0]);
        Db::name('designerpage')->where('aid', $aid)->where('id', $id)->update(['ishome' => $ishome]);

        return $this->success([], $ishome === 1 ? '已设为首页' : '已设为会员中心页');
    }

    /**
     * 取消首页
     * POST /admin/designer/page/rehome  {id}
     */
    public function pageRehome()
    {
        $aid = (int) ($this->request->aid ?? 0);
        $id  = (int) Request::post('id', 0);
        if ($id <= 0) {
            return $this->error('参数错误');
        }

        $info = Db::name('designerpage')->where('aid', $aid)->where('id', $id)->find();
        if (empty($info)) {
            return $this->error('页面不存在');
        }

        Db::name('designerpage')->where('aid', $aid)->where('id', $id)->update(['ishome' => 0]);

        return $this->success([], '已取消首页');
    }

    /**
     * 模板库列表
     * GET /admin/designer/template/list
     * 任务250（P27-6）补 aid 过滤口径：返回 平台模板(aid=0) + 本租户模板(aid=当前租户)，并下发 aid 供前端区分可否删除
     */
    public function templateList()
    {
        $aid = (int) ($this->request->aid ?? 0);

        $list = Db::name('designer_template')
            ->whereIn('aid', [0, $aid])
            ->field('id,aid,name,type,remark,sort,createtime,updatetime,pageinfo,content')
            ->order('aid', 'asc')
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        foreach ($list as &$item) {
            $item['aid']      = (int) ($item['aid'] ?? 0);
            $info             = json_decode((string) ($item['pageinfo'] ?? ''), true) ?: [];
            $item['title']    = $info[0]['params']['title'] ?? $item['name'];
            // 任务276：卡片真实预览需下发设计JSON（解析后数组，前端直接渲染）
            $item['pageinfo'] = $info;
            $item['content']  = json_decode((string) ($item['content'] ?? ''), true) ?: [];
            $item['update_time'] = $item['updatetime'] ? date('Y-m-d H:i', (int) $item['updatetime']) : '—';
        }
        unset($item);

        return $this->success(['list' => $list]);
    }

    /**
     * 同步模板（任务276）：清空平台内置模板（aid=0）重插内置两套，保留租户自建模板
     * 内置集口径参照种子迁移 20260816000102（基础首页模板/营销活动模板）
     * POST /admin/designer/template/sync
     */
    public function templateSync()
    {
        Db::name('designer_template')->where('aid', 0)->delete();

        $now = time();
        // 模板一：基础首页（搜索+轮播+宫格导航+公告+标题+商品组）
        $tpl1Pageinfo = [[
            'id' => 'M0000000000000', 'temp' => 'topbar',
            'params' => ['title' => '基础首页模板', 'bgcolor' => '#F5F5F5', 'needlogin' => '0', 'quanxian' => ['all' => true]],
        ]];
        $tpl1Content = [
            ['id' => 'MTPL10000000001', 'temp' => 'search', 'params' => ['placeholder' => '输入关键字搜索您感兴趣的商品', 'color' => '#666666', 'bgcolor' => '#FFFFFF', 'borderradius' => 5, 'margin_x' => '0', 'margin_y' => '0', 'padding_x' => '6', 'padding_y' => '6'], 'data' => []],
            ['id' => 'MTPL10000000002', 'temp' => 'banner', 'params' => ['shape' => '', 'align' => 'center', 'bgcolor' => '#FFFFFF', 'height' => '200', 'borderradius' => '0', 'indicatordots' => '1', 'interval' => 5, 'margin_x' => '0', 'margin_y' => '0', 'padding_x' => '0', 'padding_y' => '0'], 'data' => [['imgurl' => '', 'hrefurl' => ''], ['imgurl' => '', 'hrefurl' => '']]],
            ['id' => 'MTPL10000000003', 'temp' => 'menu', 'params' => ['num' => '5', 'pernum' => '5', 'fontsize' => '14', 'iconsize' => '40', 'showicon' => '1', 'showtitle' => '1', 'bgcolor' => '#FFFFFF', 'margin_x' => '0', 'margin_y' => '0', 'padding_x' => '10', 'padding_y' => '5'], 'data' => [
                ['imgurl' => '', 'title' => '菜单一', 'hrefurl' => ''],
                ['imgurl' => '', 'title' => '菜单二', 'hrefurl' => ''],
                ['imgurl' => '', 'title' => '菜单三', 'hrefurl' => ''],
                ['imgurl' => '', 'title' => '菜单四', 'hrefurl' => ''],
                ['imgurl' => '', 'title' => '菜单五', 'hrefurl' => ''],
            ]],
            ['id' => 'MTPL10000000004', 'temp' => 'notice', 'params' => ['showicon' => 1, 'color' => '#666666', 'bgcolor' => '#FFFFFF', 'scroll' => 3, 'fontsize' => '14', 'margin_x' => '0', 'margin_y' => '0', 'padding_x' => '5', 'padding_y' => '7'], 'data' => [['title' => '这里是第一条公告的标题', 'hrefurl' => '']]],
            ['id' => 'MTPL10000000005', 'temp' => 'title', 'params' => ['title' => '猜你喜欢', 'fontsize' => '14', 'color' => '#333333', 'bgcolor' => '#F6F6F6', 'margin_x' => '0', 'margin_y' => '0', 'padding_x' => '10', 'padding_y' => '5'], 'data' => []],
            ['id' => 'MTPL10000000006', 'temp' => 'product', 'params' => ['style' => '2', 'showname' => '1', 'showprice' => '1', 'showcart' => '1', 'showsales' => '1', 'proshownum' => 6, 'productfrom' => '1', 'sortby' => 'sort', 'bgcolor' => '#FFFFFF', 'margin_x' => '0', 'margin_y' => '0', 'padding_x' => '8', 'padding_y' => '8'], 'data' => []],
        ];
        // 模板二：营销页（轮播+优惠券+标题+多图+商品组）
        $tpl2Pageinfo = [[
            'id' => 'M0000000000000', 'temp' => 'topbar',
            'params' => ['title' => '营销活动模板', 'bgcolor' => '#F5F5F5', 'needlogin' => '0', 'quanxian' => ['all' => true]],
        ]];
        $tpl2Content = [
            ['id' => 'MTPL20000000001', 'temp' => 'banner', 'params' => ['shape' => '', 'align' => 'center', 'bgcolor' => '#FFFFFF', 'height' => '200', 'borderradius' => '0', 'indicatordots' => '1', 'interval' => 5, 'margin_x' => '0', 'margin_y' => '0', 'padding_x' => '0', 'padding_y' => '0'], 'data' => [['imgurl' => '', 'hrefurl' => '']]],
            ['id' => 'MTPL20000000002', 'temp' => 'coupon', 'params' => ['style' => '1', 'couponfrom' => '1', 'sortby' => 'sort', 'shownum' => 6, 'bgcolor' => '#FFFFFF', 'margin_x' => '0', 'margin_y' => '0', 'padding_x' => '0', 'padding_y' => '4'], 'data' => []],
            ['id' => 'MTPL20000000003', 'temp' => 'title', 'params' => ['title' => '热销专区', 'fontsize' => '14', 'color' => '#333333', 'bgcolor' => '#F6F6F6', 'margin_x' => '0', 'margin_y' => '0', 'padding_x' => '10', 'padding_y' => '5'], 'data' => []],
            ['id' => 'MTPL20000000004', 'temp' => 'pictures', 'params' => ['style' => '2', 'imageRadius' => '5', 'bgcolor' => '#FFFFFF', 'margin_x' => '0', 'margin_y' => '0', 'padding_x' => '0', 'padding_y' => '0'], 'data' => [
                ['imgurl' => '', 'hrefurl' => ''],
                ['imgurl' => '', 'hrefurl' => ''],
                ['imgurl' => '', 'hrefurl' => ''],
                ['imgurl' => '', 'hrefurl' => ''],
            ]],
            ['id' => 'MTPL20000000005', 'temp' => 'product', 'params' => ['style' => '2', 'showname' => '1', 'showprice' => '1', 'showcart' => '1', 'showsales' => '1', 'proshownum' => 6, 'productfrom' => '1', 'sortby' => 'sort', 'bgcolor' => '#FFFFFF', 'margin_x' => '0', 'margin_y' => '0', 'padding_x' => '8', 'padding_y' => '8'], 'data' => []],
        ];

        Db::name('designer_template')->insert([
            'aid' => 0, 'name' => '基础首页模板', 'type' => 1,
            'pageinfo' => json_encode($tpl1Pageinfo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'content' => json_encode($tpl1Content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'createtime' => $now, 'updatetime' => $now, 'sort' => 1,
            'remark' => '搜索/轮播/宫格导航/公告/商品组的通用首页结构',
        ]);
        Db::name('designer_template')->insert([
            'aid' => 0, 'name' => '营销活动模板', 'type' => 0,
            'pageinfo' => json_encode($tpl2Pageinfo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'content' => json_encode($tpl2Content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'createtime' => $now, 'updatetime' => $now, 'sort' => 2,
            'remark' => '轮播/优惠券/多图/商品组的促销活动页结构',
        ]);

        return $this->success([], '同步成功');
    }

    /**
     * 套用模板（复制模板 pageinfo/content 生成新页面）
     * POST /admin/designer/template/apply  {id}
     */
    public function templateApply()
    {
        $aid = (int) ($this->request->aid ?? 0);
        $id  = (int) Request::post('id', 0);
        if ($id <= 0) {
            return $this->error('参数错误');
        }

        $tpl = Db::name('designer_template')->where('id', $id)->find();
        if (empty($tpl)) {
            return $this->error('模板不存在');
        }

        $now = time();
        // type 白名单：0常规页面 2外卖页 3点餐页（餐饮设计页双挂，P24-6 任务226）
        $type = (int) Request::post('type', 0);
        if (!in_array($type, [0, 2, 3], true)) {
            $type = 0;
        }
        $newId = (int) Db::name('designerpage')->insertGetId([
            'aid'        => $aid,
            'bid'        => 0,
            'type'       => $type,
            'name'       => $tpl['name'] ?? '模板页面',
            'ishome'     => 0,
            'pageinfo'   => (string) $tpl['pageinfo'],
            'content'    => (string) $tpl['content'],
            'createtime' => $now,
            'updatetime' => $now,
        ]);

        return $this->success(['id' => $newId], '模板已套用');
    }

    // ==================== 底部导航（designer_menu，按 platform 分行，tongbu=1 全平台同步） ====================

    /**
     * 底部导航默认结构（首次进入无数据时下发；内置线性图标 key，任务279 对照参考口径）
     */
    private function defaultNavMenudata(): array
    {
        return [
            'color'           => '#1C2438',
            'selectedColor'   => '#1296db',
            'backgroundColor' => '#ffffff',
            'borderStyle'     => 'black',
            'position'        => 'bottom',
            'midbutton'       => 0,
            'list'            => [
                ['text' => '首页', 'pagePath' => '/pages/index/index', 'iconPath' => 'home', 'selectedIconPath' => 'home', 'pagePathname' => '基础功能>首页'],
                ['text' => '分类', 'pagePath' => '/pages/category/category', 'iconPath' => 'category', 'selectedIconPath' => 'category', 'pagePathname' => '基础功能>分类'],
                ['text' => '购物车', 'pagePath' => '/pages/cart/cart', 'iconPath' => 'cart', 'selectedIconPath' => 'cart', 'pagePathname' => '基础功能>购物车'],
                ['text' => '会员中心', 'pagePath' => '/pages/my/usercenter', 'iconPath' => 'user', 'selectedIconPath' => 'user', 'pagePathname' => '基础功能>会员中心'],
            ],
        ];
    }

    /**
     * 读取底部导航
     * GET /admin/designer/menu/get?platform=mp
     */
    public function menuGet()
    {
        $aid      = (int) ($this->request->aid ?? 0);
        $platform = (string) Request::get('platform', 'mp');
        if (!in_array($platform, self::NAV_PLATFORMS, true)) {
            return $this->error('平台参数错误');
        }

        $info = Db::name('designer_menu')->where('aid', $aid)->where('platform', $platform)->find();
        if (empty($info)) {
            $menudata = $this->defaultNavMenudata();

            return $this->success([
                'id'        => 0,
                'platform'  => $platform,
                'tongbu'    => 0,
                'indexurl'  => '/pages/index/index',
                'menucount' => count($menudata['list']),
                'menudata'  => $menudata,
            ]);
        }

        return $this->success([
            'id'        => (int) $info['id'],
            'platform'  => (string) $info['platform'],
            'tongbu'    => (int) ($info['tongbu'] ?? 0),
            'indexurl'  => (string) ($info['indexurl'] ?? '/pages/index/index'),
            'menucount' => (int) ($info['menucount'] ?? 0),
            'menudata'  => json_decode((string) ($info['menudata'] ?? ''), true) ?: $this->defaultNavMenudata(),
        ]);
    }

    /**
     * 保存底部导航（tongbu=1 时同步覆盖该租户全部八端行）
     * POST /admin/designer/menu/save  {info:{platform, tongbu, indexurl, menudata}}
     */
    public function menuSave()
    {
        $aid  = (int) ($this->request->aid ?? 0);
        $info = Request::post('info/a', []);

        $platform = (string) ($info['platform'] ?? 'mp');
        if (!in_array($platform, self::NAV_PLATFORMS, true)) {
            return $this->error('平台参数错误');
        }

        // 任务279：菜单个数支持「无」（0 项），上限 5 项
        $menudata = $info['menudata'] ?? [];
        if (!is_array($menudata)) {
            return $this->error('参数错误');
        }
        $list = $menudata['list'] ?? [];
        if (!is_array($list)) {
            return $this->error('参数错误');
        }
        $count = count($list);
        if ($count > self::NAV_ITEM_MAX) {
            return $this->error('菜单项最多 ' . self::NAV_ITEM_MAX . ' 个');
        }

        $tongbu = !empty($info['tongbu']) ? 1 : 0;
        $data   = [
            'indexurl'   => (string) ($info['indexurl'] ?? '/pages/index/index'),
            'menucount'  => $count,
            'menudata'   => json_encode($menudata, JSON_UNESCAPED_UNICODE),
            'tongbu'     => $tongbu,
            'updatetime' => time(),
        ];

        // tongbu=1：八端同写；否则仅写当前平台行
        $platforms = $tongbu ? self::NAV_PLATFORMS : [$platform];
        foreach ($platforms as $p) {
            $this->upsertPlatformRow('designer_menu', $aid, $p, $data);
        }

        return $this->success([], $tongbu ? '保存成功，已同步全部平台' : '保存成功');
    }

    /**
     * 平台行 upsert（按 aid+platform 定位，存在更新，不存在插入）
     */
    private function upsertPlatformRow(string $table, int $aid, string $platform, array $data): void
    {
        $exists = Db::name($table)->where('aid', $aid)->where('platform', $platform)->find();
        if (!empty($exists)) {
            Db::name($table)->where('aid', $aid)->where('platform', $platform)->update($data);

            return;
        }

        $data['aid']      = $aid;
        $data['platform'] = $platform;
        Db::name($table)->insert($data);
    }

    // ==================== 内页导航（designer_menu2，多条 CRUD + 复制 + 启停） ====================

    /**
     * 内页导航列表
     * GET /admin/designer/menu2/list?page=&limit=&indexurlname=&indexurl=&platform=&status=
     */
    public function menu2List()
    {
        $aid   = (int) ($this->request->aid ?? 0);
        $page  = max(1, (int) Request::get('page', 1));
        $limit = min(100, max(1, (int) Request::get('limit', 20)));

        $query = Db::name('designer_menu2')->where('aid', $aid);
        $name  = trim((string) Request::get('indexurlname', ''));
        $url   = trim((string) Request::get('indexurl', ''));
        $plat  = trim((string) Request::get('platform', ''));
        $st    = Request::get('status', '');
        if ($name !== '') {
            $query->whereLike('indexurlname', '%' . $name . '%');
        }
        if ($url !== '') {
            $query->whereLike('indexurl', '%' . $url . '%');
        }
        if ($plat !== '') {
            $query->where('platform', $plat);
        }
        if ($st !== '' && $st !== null) {
            $query->where('status', (int) $st);
        }

        $count = (clone $query)->count();
        $list  = $query->order('id', 'desc')->page($page, $limit)->select()->toArray();

        foreach ($list as &$item) {
            $item['status']      = (int) ($item['status'] ?? 1);
            $item['menucount']   = (int) ($item['menucount'] ?? 0);
            $item['create_time'] = $item['createtime'] ? date('Y-m-d H:i', (int) $item['createtime']) : '—';
            unset($item['menudata']);
        }
        unset($item);

        return $this->success(['count' => $count, 'list' => $list]);
    }

    /**
     * 内页导航详情（编辑回显）
     * GET /admin/designer/menu2/detail?id=
     */
    public function menu2Detail()
    {
        $aid = (int) ($this->request->aid ?? 0);
        $id  = (int) Request::get('id', 0);
        if ($id <= 0) {
            return $this->error('参数错误');
        }

        $info = Db::name('designer_menu2')->where('aid', $aid)->where('id', $id)->find();
        if (empty($info)) {
            return $this->error('导航不存在');
        }

        $info['menudata'] = json_decode((string) ($info['menudata'] ?? ''), true) ?: [];

        return $this->success($info);
    }

    /**
     * 内页导航保存（新增/编辑）
     * POST /admin/designer/menu2/save  {info:{id?, indexurlname, indexurl, platform, backgroundColor, menucount, menudata[], status}}
     */
    public function menu2Save()
    {
        $aid  = (int) ($this->request->aid ?? 0);
        $info = Request::post('info/a', []);

        $name = trim((string) ($info['indexurlname'] ?? ''));
        if ($name === '') {
            return $this->error('请填写导航名称');
        }
        $url = trim((string) ($info['indexurl'] ?? ''));
        if ($url === '') {
            return $this->error('请选择生效页面链接');
        }
        $menudata = $info['menudata'] ?? [];
        if (!is_array($menudata) || empty($menudata)) {
            return $this->error('菜单项不能为空');
        }

        $data = [
            'indexurlname'    => $name,
            'indexurl'        => $url,
            'platform'        => (string) ($info['platform'] ?? 'all'),
            'backgroundColor' => (string) ($info['backgroundColor'] ?? '#ffffff'),
            'menucount'       => count($menudata),
            'menudata'        => json_encode($menudata, JSON_UNESCAPED_UNICODE),
            'status'          => !empty($info['status']) ? 1 : 0,
        ];

        $id = (int) ($info['id'] ?? 0);
        if ($id > 0) {
            $exists = Db::name('designer_menu2')->where('aid', $aid)->where('id', $id)->find();
            if (empty($exists)) {
                return $this->error('导航不存在');
            }
            Db::name('designer_menu2')->where('aid', $aid)->where('id', $id)->update($data);
        } else {
            $data['aid']        = $aid;
            $data['bid']        = 0;
            $data['createtime'] = time();
            $id = (int) Db::name('designer_menu2')->insertGetId($data);
        }

        return $this->success(['id' => $id], '保存成功');
    }

    /**
     * 内页导航启停
     * POST /admin/designer/menu2/setst  {ids:[], st:0|1}
     */
    public function menu2Setst()
    {
        $aid = (int) ($this->request->aid ?? 0);
        $ids = Request::post('ids/a', []);
        $st  = (int) Request::post('st', 1);
        if (empty($ids)) {
            return $this->error('参数错误');
        }

        Db::name('designer_menu2')->where('aid', $aid)->whereIn('id', array_map('intval', $ids))->update(['status' => $st ? 1 : 0]);

        return $this->success([], '操作成功');
    }

    /**
     * 内页导航删除
     * POST /admin/designer/menu2/del  {ids:[]}
     */
    public function menu2Del()
    {
        $aid = (int) ($this->request->aid ?? 0);
        $ids = Request::post('ids/a', []);
        if (empty($ids)) {
            return $this->error('参数错误');
        }

        Db::name('designer_menu2')->where('aid', $aid)->whereIn('id', array_map('intval', $ids))->delete();

        return $this->success([], '删除成功');
    }

    /**
     * 内页导航复制
     * POST /admin/designer/menu2/copy  {id}
     */
    public function menu2Copy()
    {
        $aid = (int) ($this->request->aid ?? 0);
        $id  = (int) Request::post('id', 0);
        if ($id <= 0) {
            return $this->error('参数错误');
        }

        $row = Db::name('designer_menu2')->where('aid', $aid)->where('id', $id)->find();
        if (empty($row)) {
            return $this->error('导航不存在，请重新选择');
        }

        unset($row['id']);
        $row['indexurlname'] = ((string) ($row['indexurlname'] ?? '')) . '（副本）';
        $row['createtime']   = time();
        $newId = (int) Db::name('designer_menu2')->insertGetId($row);

        return $this->success(['id' => $newId], '复制成功');
    }

    // ==================== 商品详情导航（designer_shopdetail，bid=0 主配置，三固定槽位） ====================

    /**
     * 商品详情导航默认结构（三槽位：1客服/2购物车/3收藏）
     */
    private function defaultShopdetailMenudata(string $kfurl): array
    {
        return [
            'color'          => '#BBBBBB',
            'selectedColor'  => '#FD4A46',
            'backgroundColor' => '#ffffff',
            'borderStyle'    => 'black',
            'position'       => 'bottom',
            'list'           => [
                ['text' => '客服', 'pagePath' => $kfurl, 'iconPath' => '', 'selectedIconPath' => '', 'pagePathname' => '功能>客服', 'isShow' => 1, 'menuType' => 1, 'useSystem' => $kfurl === '' ? 1 : 0],
                ['text' => '购物车', 'pagePath' => '/pages/cart/cart', 'iconPath' => '', 'selectedIconPath' => '', 'pagePathname' => '基础功能>购物车', 'isShow' => 1, 'menuType' => 2, 'useSystem' => 0],
                ['text' => '收藏', 'pagePath' => 'addfavorite::', 'iconPath' => '', 'selectedIconPath' => '', 'pagePathname' => '基础功能>收藏', 'isShow' => 1, 'menuType' => 3, 'useSystem' => 0, 'selectedtext' => '已收藏'],
            ],
        ];
    }

    /**
     * 读取商品详情导航（无数据时按 admin_set.kfurl 初始化并落库）
     * GET /admin/designer/shopdetail/get
     */
    public function shopdetailGet()
    {
        $aid = (int) ($this->request->aid ?? 0);

        $info = Db::name('designer_shopdetail')->where('aid', $aid)->where('bid', 0)->find();
        if (empty($info)) {
            $kfurl = (string) Db::name('admin_set')->where('aid', $aid)->value('kfurl');
            $insert = [
                'aid'                          => $aid,
                'bid'                          => 0,
                'indexurl'                     => '/pages/index/index',
                'menucount'                    => 3,
                'menudata'                     => json_encode($this->defaultShopdetailMenudata($kfurl), JSON_UNESCAPED_UNICODE),
                'navigationBarBackgroundColor' => '#333333',
                'navigationBarTextStyle'       => 'white',
                'platform'                     => 'all',
                'tongbu'                       => 1,
                'updatetime'                   => time(),
            ];
            $id   = (int) Db::name('designer_shopdetail')->insertGetId($insert);
            $info = $insert;
            $info['id'] = $id;
        }

        return $this->success([
            'id'       => (int) $info['id'],
            'menudata' => json_decode((string) ($info['menudata'] ?? ''), true) ?: $this->defaultShopdetailMenudata(''),
            'navigationBarBackgroundColor' => (string) ($info['navigationBarBackgroundColor'] ?? '#333333'),
            'navigationBarTextStyle'       => (string) ($info['navigationBarTextStyle'] ?? 'white'),
            'description'     => (string) ($info['description'] ?? ''),
            'description_img' => (string) ($info['description_img'] ?? ''),
            'share_login'     => (int) ($info['share_login'] ?? 1),
        ]);
    }

    /**
     * 保存商品详情导航
     * POST /admin/designer/shopdetail/save  {info:{menudata, navigationBarBackgroundColor, navigationBarTextStyle, description, description_img, share_login}}
     */
    public function shopdetailSave()
    {
        $aid  = (int) ($this->request->aid ?? 0);
        $info = Request::post('info/a', []);

        $menudata = $info['menudata'] ?? [];
        if (!is_array($menudata) || empty($menudata['list']) || !is_array($menudata['list'])) {
            return $this->error('导航槽位数据不能为空');
        }

        $data = [
            'menudata'                     => json_encode($menudata, JSON_UNESCAPED_UNICODE),
            'menucount'                    => count($menudata['list']),
            'navigationBarBackgroundColor' => (string) ($info['navigationBarBackgroundColor'] ?? '#333333'),
            'navigationBarTextStyle'       => (string) ($info['navigationBarTextStyle'] ?? 'white'),
            'description'                  => (string) ($info['description'] ?? ''),
            'description_img'              => trim((string) ($info['description_img'] ?? '')),
            'share_login'                  => (int) ($info['share_login'] ?? 1),
            'updatetime'                   => time(),
        ];

        Db::name('designer_shopdetail')->where('aid', $aid)->where('bid', 0)->update($data);

        return $this->success([], '保存成功');
    }

    // ==================== 登录页装修（designer_login，对照参考口径：页面样式一/二 + 主题字段） ====================

    /**
     * 读取登录页装修（无数据时从 admin_set 取 logo/name/color1 初始化并落库）
     * GET /admin/designer/login/get
     * 返回：{id, type(页面样式1样式一/2样式二), updatetime, data(主题字段JSON), themecolor(系统主题色，btntype=1跟随用)}
     */
    public function loginGet()
    {
        $aid = (int) ($this->request->aid ?? 0);

        $sysset    = Db::name('admin_set')->where('aid', $aid)->field('name,logo,color1')->find();
        $logo      = (string) ($sysset['logo'] ?? '');
        $sysname   = (string) ($sysset['name'] ?? '');
        $themecolor = (string) ($sysset['color1'] ?? '');

        $info = Db::name('designer_login')->where('aid', $aid)->find();
        if (empty($info)) {
            $bgcolor = $themecolor;

            $data = [
                'logo'           => $logo,
                'bgtype'         => 1,
                'bgcolor'        => $bgcolor,
                'bgimg'          => '',
                'cardcolor'      => '#FFFFFF',
                'titletype'      => 'center',
                'title'          => '欢迎使用' . $sysname,
                'titlecolor'     => '#000000',
                'subhead'        => '推荐使用手机号验证码登录',
                'subheadcolor'   => '#A8B5D3',
                'btntype'        => 1,
                'btncolor'       => $bgcolor !== '' ? $bgcolor : '#0256FF',
                'btnwordcolor'   => '#FFFFFF',
                'codecolor'      => $bgcolor !== '' ? $bgcolor : '#0256FF',
                'regtipcolor'    => '#5E6066',
                'regpwdbtncolor' => '#666666',
                'regbtncolor'    => '#666666',
                'wxicon'         => '',
                'wxtext'         => '授权登录',
                'xytipword'      => '我已阅读并同意',
                'xytipcolor'     => '#D8D8D8',
                'xycolor'        => '#0256FF',
            ];

            $id = (int) Db::name('designer_login')->insertGetId([
                'aid'        => $aid,
                'type'       => 1,
                'updatetime' => time(),
                'data'       => json_encode($data, JSON_UNESCAPED_UNICODE),
            ]);

            return $this->success(['id' => $id, 'type' => 1, 'updatetime' => time(), 'data' => $data, 'themecolor' => $themecolor]);
        }

        return $this->success([
            'id'         => (int) $info['id'],
            'type'       => (int) ($info['type'] ?? 1),
            'updatetime' => (int) ($info['updatetime'] ?? 0),
            'data'       => json_decode((string) ($info['data'] ?? ''), true) ?: [],
            'themecolor' => $themecolor,
        ]);
    }

    /**
     * 保存登录页装修
     * POST /admin/designer/login/save  {info:{id?, type?(页面样式1/2), data:{...主题字段}}}
     */
    public function loginSave()
    {
        $aid  = (int) ($this->request->aid ?? 0);
        $info = Request::post('info/a', []);

        $data = $info['data'] ?? [];
        if (!is_array($data) || empty($data)) {
            return $this->error('装修数据不能为空');
        }

        $type = (int) ($info['type'] ?? 1);
        if (!in_array($type, [1, 2], true)) {
            $type = 1;
        }

        $id   = (int) ($info['id'] ?? 0);
        $save = [
            'aid'        => $aid,
            'type'       => $type,
            'data'       => json_encode($data, JSON_UNESCAPED_UNICODE),
            'updatetime' => time(),
        ];

        if ($id > 0 && Db::name('designer_login')->where('aid', $aid)->where('id', $id)->find()) {
            Db::name('designer_login')->where('aid', $aid)->where('id', $id)->update($save);
        } else {
            $id = (int) Db::name('designer_login')->insertGetId($save);
        }

        return $this->success(['id' => $id], '保存成功');
    }

    // ==================== 分享设置（designer_share，CRUD + 启停） ====================

    /**
     * 分享设置列表
     * GET /admin/designer/share/list?page=&limit=&indexurlname=&indexurl=&platform=&status=
     */
    public function shareList()
    {
        $aid   = (int) ($this->request->aid ?? 0);
        $page  = max(1, (int) Request::get('page', 1));
        $limit = min(100, max(1, (int) Request::get('limit', 20)));

        $query = Db::name('designer_share')->where('aid', $aid);
        $name  = trim((string) Request::get('indexurlname', ''));
        $url   = trim((string) Request::get('indexurl', ''));
        $plat  = trim((string) Request::get('platform', ''));
        $st    = Request::get('status', '');
        if ($name !== '') {
            $query->whereLike('indexurlname', '%' . $name . '%');
        }
        if ($url !== '') {
            $query->whereLike('indexurl', '%' . $url . '%');
        }
        if ($plat !== '') {
            $query->where('platform', $plat);
        }
        if ($st !== '' && $st !== null) {
            $query->where('status', (int) $st);
        }

        $count = (clone $query)->count();
        $list  = $query->order('id', 'desc')->page($page, $limit)->select()->toArray();

        foreach ($list as &$item) {
            $item['status']      = (int) ($item['status'] ?? 1);
            $item['is_rootpath'] = (int) ($item['is_rootpath'] ?? 0);
            $item['create_time'] = $item['createtime'] ? date('Y-m-d H:i', (int) $item['createtime']) : '—';
        }
        unset($item);

        return $this->success(['count' => $count, 'list' => $list]);
    }

    /**
     * 分享设置详情（编辑回显）
     * GET /admin/designer/share/detail?id=
     */
    public function shareDetail()
    {
        $aid = (int) ($this->request->aid ?? 0);
        $id  = (int) Request::get('id', 0);
        if ($id <= 0) {
            return $this->error('参数错误');
        }

        $info = Db::name('designer_share')->where('aid', $aid)->where('id', $id)->find();
        if (empty($info)) {
            return $this->error('分享设置不存在');
        }

        return $this->success($info);
    }

    /**
     * 分享设置保存（新增/编辑）
     * POST /admin/designer/share/save  {info:{id?, indexurlname, indexurl, title, desc, pic, url, platform, is_rootpath, status}}
     */
    public function shareSave()
    {
        $aid  = (int) ($this->request->aid ?? 0);
        $info = Request::post('info/a', []);

        $title = trim((string) ($info['title'] ?? ''));
        if ($title === '') {
            return $this->error('请填写分享标题');
        }

        $data = [
            'indexurlname' => trim((string) ($info['indexurlname'] ?? '')),
            'indexurl'     => trim((string) ($info['indexurl'] ?? '')),
            'title'        => $title,
            'desc'         => trim((string) ($info['desc'] ?? '')),
            'pic'          => trim((string) ($info['pic'] ?? '')),
            'url'          => trim((string) ($info['url'] ?? '')),
            'platform'     => (string) ($info['platform'] ?? 'all'),
            'is_rootpath'  => !empty($info['is_rootpath']) ? 1 : 0,
            'status'       => !empty($info['status']) ? 1 : 0,
        ];

        $id = (int) ($info['id'] ?? 0);
        if ($id > 0) {
            $exists = Db::name('designer_share')->where('aid', $aid)->where('id', $id)->find();
            if (empty($exists)) {
                return $this->error('分享设置不存在');
            }
            Db::name('designer_share')->where('aid', $aid)->where('id', $id)->update($data);
        } else {
            $data['aid']        = $aid;
            $data['bid']        = 0;
            $data['createtime'] = time();
            $id = (int) Db::name('designer_share')->insertGetId($data);
        }

        return $this->success(['id' => $id], '保存成功');
    }

    /**
     * 分享设置启停
     * POST /admin/designer/share/setst  {ids:[], st:0|1}
     */
    public function shareSetst()
    {
        $aid = (int) ($this->request->aid ?? 0);
        $ids = Request::post('ids/a', []);
        $st  = (int) Request::post('st', 1);
        if (empty($ids)) {
            return $this->error('参数错误');
        }

        Db::name('designer_share')->where('aid', $aid)->whereIn('id', array_map('intval', $ids))->update(['status' => $st ? 1 : 0]);

        return $this->success([], '操作成功');
    }

    /**
     * 分享设置删除
     * POST /admin/designer/share/del  {ids:[]}
     */
    public function shareDel()
    {
        $aid = (int) ($this->request->aid ?? 0);
        $ids = Request::post('ids/a', []);
        if (empty($ids)) {
            return $this->error('参数错误');
        }

        Db::name('designer_share')->where('aid', $aid)->whereIn('id', array_map('intval', $ids))->delete();

        return $this->success([], '删除成功');
    }

    // ==================== 任务227（P24-7 批次8）：移动端后台 + 链接地址选择器 ====================
    // 本段仅含 mobile 与 chooseurl 方法；business 相关方法由任务221另行追加，互不冲突。

    /**
     * 移动端后台页面配置读取（designer_mobile 表，aid 单行）
     * 口径对照参考系统 DesignerMobile/index：无记录时自动初始化空配置行
     * GET /admin/designer/mobile/get
     */
    public function mobileGet()
    {
        $aid = (int) ($this->request->aid ?? 0);

        $info = Db::name('designer_mobile')->where('aid', $aid)->find();

        if (empty($info)) {
            $info = [
                'aid'        => $aid,
                'data'       => json_encode(['bgimg' => ''], JSON_UNESCAPED_UNICODE),
                'updatetime' => time(),
            ];

            $info['id'] = (int) Db::name('designer_mobile')->insertGetId($info);
        }

        $data = json_decode((string) $info['data'], true);

        return $this->success([
            'id'         => (int) $info['id'],
            'data'       => is_array($data) ? $data : ['bgimg' => ''],
            'updatetime' => (int) ($info['updatetime'] ?? 0),
        ]);
    }

    /**
     * 移动端后台页面配置保存（data 整体 JSON 落库）
     * POST /admin/designer/mobile/save {id?, data:{bgimg,...}}
     */
    public function mobileSave()
    {
        $aid = (int) ($this->request->aid ?? 0);

        $id   = (int) Request::post('id', 0);
        $data = Request::post('data/a', []);

        if (empty($data)) {
            return $this->error('配置内容不能为空');
        }

        $row = [
            'data'       => json_encode($data, JSON_UNESCAPED_UNICODE),
            'updatetime' => time(),
        ];

        if ($id > 0) {
            $exists = Db::name('designer_mobile')->where('aid', $aid)->where('id', $id)->find();

            if (empty($exists)) {
                return $this->error('配置不存在');
            }

            Db::name('designer_mobile')->where('aid', $aid)->where('id', $id)->update($row);
        } else {
            $row['aid'] = $aid;
            $id         = (int) Db::name('designer_mobile')->insertGetId($row);
        }

        return $this->success(['id' => $id], '保存成功');
    }

    /**
     * 链接地址选择器数据（DesignerPage chooseurl 工具页）
     * 口径对照参考系统 DesignerPage/chooseurl：固定链接组 + 商品分类树 + 文章分类树 + 课程分类树 + 自定义页面
     * GET /admin/designer/chooseurl
     */
    public function chooseUrl()
    {
        $aid = (int) ($this->request->aid ?? 0);

        // designerpage 表无 indexurl 列，改用实际存在的列：ishome=1 首页对应 /pages/index/index，
        // 其余自定义页按 pageid 参数路由；type=1 模板库页不下发（对齐 DesignerService 口径，任务234 Bug5 修复）
        $pages = Db::name('designerpage')
            ->where('aid', $aid)
            ->where('type', 0)
            ->field('id, name, ishome')
            ->order('id', 'desc')
            ->select()
            ->toArray();
        foreach ($pages as &$page) {
            $page['id']       = (int) $page['id'];
            $page['ishome']   = (int) $page['ishome'];
            $page['indexurl'] = 1 === $page['ishome']
                ? '/pages/index/index'
                : '/pages/index/index?pageid=' . $page['id'];
        }
        unset($page);

        return $this->success([
            'fixed'            => $this->chooseUrlFixedLinks(),
            'shop_category'    => $this->chooseUrlCategoryTree('shop_category', $aid, 3),
            'article_category' => $this->chooseUrlCategoryTree('article_category', $aid, 2),
            'kecheng_category' => $this->chooseUrlCategoryTree('kecheng_category', $aid, 2),
            'pages'            => $pages,
        ]);
    }

    /**
     * 固定链接组（基础功能/营销/会员，路径对齐底部导航口径）
     *
     * @return array<int, array{name: string, url: string}>
     */
    private function chooseUrlFixedLinks(): array
    {
        return [
            ['name' => '基础功能>首页', 'url' => '/pages/index/index'],
            ['name' => '基础功能>商品分类', 'url' => '/pages/category/category'],
            ['name' => '基础功能>购物车', 'url' => '/pages/cart/cart'],
            ['name' => '基础功能>会员中心', 'url' => '/pages/my/my'],
            ['name' => '基础功能>我的订单', 'url' => '/pages/order/order'],
            ['name' => '基础功能>收货地址', 'url' => '/pages/address/address'],
            ['name' => '基础功能>联系客服', 'url' => 'kefu::'],
            ['name' => '营销功能>优惠券中心', 'url' => '/pages/coupon/coupon'],
            ['name' => '营销功能>签到', 'url' => '/pages/sign/sign'],
            ['name' => '营销功能>积分商城', 'url' => '/pages/scoreshop/index'],
            ['name' => '营销功能>多人拼团', 'url' => '/pages/collage/index'],
            ['name' => '营销功能>幸运拼团', 'url' => '/pages/luckyCollage/index'],
            ['name' => '营销功能>砍价活动', 'url' => '/pages/kanjia/index'],
            ['name' => '营销功能>团购活动', 'url' => '/pages/tuangou/index'],
            ['name' => '营销功能>秒杀活动', 'url' => '/pages/seckill/index'],
            ['name' => '营销功能>周期购', 'url' => '/pages/cycle/index'],
            ['name' => '营销功能>活动日历', 'url' => '/pagesD/calendar/index'],
            ['name' => '会员功能>会员等级', 'url' => '/pages/level/level'],
            ['name' => '会员功能>余额明细', 'url' => '/pages/money/log'],
            ['name' => '会员功能>佣金明细', 'url' => '/pages/commission/log'],
        ];
    }

    /**
     * 分类树（pid 层级，限深度；口径对照 chooseurl 的三级/两级树）
     *
     * @return array<int, array<string, mixed>>
     */
    private function chooseUrlCategoryTree(string $table, int $aid, int $depth): array
    {
        $rows = Db::name($table)
            ->where('aid', $aid)
            ->field('id, pid, name')
            ->order('sort', 'desc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        $byPid = [];

        foreach ($rows as $row) {
            $byPid[(int) $row['pid']][] = [
                'id'    => (int) $row['id'],
                'name'  => (string) $row['name'],
                'child' => [],
            ];
        }

        $build = function (int $pid, int $level) use (&$build, &$byPid): array {
            $list = $byPid[$pid] ?? [];

            if ($level > 1) {
                foreach ($list as &$item) {
                    $item['child'] = $build((int) $item['id'], $level - 1);
                }

                unset($item);
            }

            return $list;
        };

        return $build(0, $depth);
    }

    // ==================== 任务221（P24-1 批次8）：多商户默认底部导航 ====================
    // 本段仅含 business/businesssave 方法；mobile/chooseurl 方法由任务227另行追加，互不冲突。
    // 口径对照参考系统 DesignerMenu.php business()/businesssave()（128-192 行）：
    // designer_menu_business 按 platform 分行，默认导航路径带 [bid] 占位，tongbu=1 八端同步。

    /**
     * 多商户默认底部导航默认结构（首次进入无数据时下发，固定5槽，图标留空由租户上传）
     */
    private function defaultBusinessNavMenudata(): array
    {
        return [
            'color'           => '#BBBBBB',
            'selectedColor'   => '#FD4A46',
            'backgroundColor' => '#ffffff',
            'borderStyle'     => 'black',
            'position'        => 'bottom',
            'list'            => [
                ['text' => '店铺', 'pagePath' => '/pagesExt/business/index?id=[bid]', 'iconPath' => '', 'selectedIconPath' => '', 'pagePathname' => '基础功能>店铺首页'],
                ['text' => '商品分类', 'pagePath' => '/pagesM/shop/prolist?bid=[bid]', 'iconPath' => '', 'selectedIconPath' => '', 'pagePathname' => '基础功能>商品分类'],
                ['text' => '购物车', 'pagePath' => '/pagesM/shop/cart?bid=[bid]', 'iconPath' => '', 'selectedIconPath' => '', 'pagePathname' => '基础功能>购物车'],
                ['text' => '订单', 'pagePath' => '/pagesM/order/list?bid=[bid]', 'iconPath' => '', 'selectedIconPath' => '', 'pagePathname' => '基础功能>订单列表'],
                ['text' => '导航名称', 'pagePath' => '', 'iconPath' => '', 'selectedIconPath' => '', 'pagePathname' => ''],
            ],
        ];
    }

    /**
     * 把导航 list 规整为固定 5 槽（多截少补，按默认槽位兜底）；menucount 与 list 长度解耦，切换个数不丢已填数据
     */
    private function normalizeBusinessNavList(array $list): array
    {
        $defaults = $this->defaultBusinessNavMenudata()['list'];
        $out = [];
        for ($i = 0; $i < self::NAV_ITEM_MAX; $i++) {
            $cur  = isset($list[$i]) && is_array($list[$i]) ? $list[$i] : [];
            $base = $defaults[$i];
            $out[] = [
                'text'             => (string) ($cur['text'] ?? $base['text']),
                'pagePath'         => (string) ($cur['pagePath'] ?? $base['pagePath']),
                'iconPath'         => (string) ($cur['iconPath'] ?? ''),
                'selectedIconPath' => (string) ($cur['selectedIconPath'] ?? ''),
                'pagePathname'     => (string) ($cur['pagePathname'] ?? $base['pagePathname']),
            ];
        }

        return $out;
    }

    /**
     * 菜单个数白名单：无(0)/两个(2)/三个(3)/四个(4)/五个(5)，不设“单个”；非法值归一为 0
     */
    private function normalizeBusinessMenuCount($v): int
    {
        $v = (int) $v;

        return in_array($v, [0, 2, 3, 4, 5], true) ? $v : 0;
    }

    /**
     * 读取多商户默认底部导航
     * GET /admin/designer/business/get?platform=mp
     */
    public function businessGet()
    {
        $aid      = (int) ($this->request->aid ?? 0);
        $platform = (string) Request::get('platform', 'mp');
        if (!in_array($platform, self::NAV_PLATFORMS, true)) {
            return $this->error('平台参数错误');
        }

        $default = $this->defaultBusinessNavMenudata();
        $info = Db::name('designer_menu_business')->where('aid', $aid)->where('platform', $platform)->find();
        if (empty($info)) {
            // 首次进入：菜单个数初始为“无”，但 menudata 仍下发固定 5 槽默认，供选择个数后直接编辑
            return $this->success([
                'id'        => 0,
                'platform'  => $platform,
                'tongbu'    => 1,
                'indexurl'  => '/pagesExt/business/index?bid=[bid]',
                'menucount' => 0,
                'menudata'  => $default,
            ]);
        }

        $menudata = json_decode((string) ($info['menudata'] ?? ''), true);
        if (!is_array($menudata)) {
            $menudata = $default;
        }
        $rawList = isset($menudata['list']) && is_array($menudata['list']) ? $menudata['list'] : [];
        $menudata['list'] = $this->normalizeBusinessNavList($rawList);
        $menudata['color'] = (string) ($menudata['color'] ?? $default['color']);
        $menudata['selectedColor'] = (string) ($menudata['selectedColor'] ?? $default['selectedColor']);
        $menudata['backgroundColor'] = (string) ($menudata['backgroundColor'] ?? $default['backgroundColor']);
        $menudata['borderStyle'] = (string) ($menudata['borderStyle'] ?? 'black');
        $menudata['position'] = 'bottom';

        return $this->success([
            'id'        => (int) $info['id'],
            'platform'  => (string) $info['platform'],
            'tongbu'    => (int) ($info['tongbu'] ?? 1),
            'indexurl'  => (string) ($info['indexurl'] ?? '/pagesExt/business/index?bid=[bid]'),
            'menucount' => $this->normalizeBusinessMenuCount($info['menucount'] ?? 0),
            'menudata'  => $menudata,
        ]);
    }

    /**
     * 保存多商户默认底部导航（tongbu=1 时同步覆盖该租户全部八端行）
     * POST /admin/designer/business/save  {info:{platform, tongbu, menucount, indexurl, menudata}}
     * menudata.list 固定 5 槽全量保存，menucount 决定前端/端上显示前几个（可为 0=无底部导航）
     */
    public function businessSave()
    {
        $aid  = (int) ($this->request->aid ?? 0);
        $info = Request::post('info/a', []);

        $platform = (string) ($info['platform'] ?? 'mp');
        if (!in_array($platform, self::NAV_PLATFORMS, true)) {
            return $this->error('平台参数错误');
        }

        $menucount = $this->normalizeBusinessMenuCount($info['menucount'] ?? 0);
        $menudata  = isset($info['menudata']) && is_array($info['menudata']) ? $info['menudata'] : [];
        $rawList   = isset($menudata['list']) && is_array($menudata['list']) ? $menudata['list'] : [];
        // 固定 5 槽：多截少补，切换菜单个数不丢弃其它槽位已填内容
        $menudata['list'] = $this->normalizeBusinessNavList($rawList);
        $menudata['color'] = (string) ($menudata['color'] ?? '#BBBBBB');
        $menudata['selectedColor'] = (string) ($menudata['selectedColor'] ?? '#FD4A46');
        $menudata['backgroundColor'] = (string) ($menudata['backgroundColor'] ?? '#ffffff');
        $menudata['borderStyle'] = (string) ($menudata['borderStyle'] ?? 'black');
        $menudata['position'] = 'bottom';

        $tongbu = !empty($info['tongbu']) ? 1 : 0;
        $data   = [
            'indexurl'   => (string) ($info['indexurl'] ?? '/pagesExt/business/index?bid=[bid]'),
            'menucount'  => $menucount,
            'menudata'   => json_encode($menudata, JSON_UNESCAPED_UNICODE),
            'tongbu'     => $tongbu,
            'updatetime' => time(),
        ];

        // tongbu=1：八端同写；否则仅写当前平台行（参考系统 businesssave 同口径）
        $platforms = $tongbu ? self::NAV_PLATFORMS : [$platform];
        foreach ($platforms as $p) {
            $this->upsertPlatformRow('designer_menu_business', $aid, $p, $data);
        }

        return $this->success([], $tongbu ? '保存成功，已同步全部平台' : '保存成功');
    }

    // ==================== 任务250（P27-6）：模板库深化与选项卡后端 ====================
    // 本段仅含 templateSave/templateDel/tabGet/tabSave 方法；
    // templateList 的 aid 过滤口径为任务250在既有方法内最小补充（返回平台模板 aid=0 + 本租户模板）。

    /**
     * 存为模板（把指定 designerpage 复制为当前租户模板：pageinfo/content/name，aid=当前租户）
     * POST /admin/designer/template/save  {pageid, name}
     */
    public function templateSave()
    {
        $aid    = (int) ($this->request->aid ?? 0);
        $pageid = (int) Request::post('pageid', 0);
        $name   = trim((string) Request::post('name', ''));
        if ($pageid <= 0) {
            return $this->error('参数错误');
        }
        if ($name === '') {
            return $this->error('请填写模板名称');
        }
        if (mb_strlen($name) > 50) {
            return $this->error('模板名称过长（最多50字）');
        }

        $page = Db::name('designerpage')->where('aid', $aid)->where('id', $pageid)->find();
        if (empty($page)) {
            return $this->error('页面不存在');
        }

        $now   = time();
        $tplId = (int) Db::name('designer_template')->insertGetId([
            'aid'        => $aid,
            'name'       => $name,
            'type'       => 0,
            'pageinfo'   => (string) $page['pageinfo'],
            'content'    => (string) $page['content'],
            'createtime' => $now,
            'updatetime' => $now,
            'sort'       => 0,
            'remark'     => '租户自建模板（存为模板）',
        ]);

        return $this->success(['id' => $tplId], '已存为模板');
    }

    /**
     * 删除模板（仅可删本租户 aid 模板；平台模板 aid=0 禁删）
     * POST /admin/designer/template/del  {id}
     */
    public function templateDel()
    {
        $aid = (int) ($this->request->aid ?? 0);
        $id  = (int) Request::post('id', 0);
        if ($id <= 0) {
            return $this->error('参数错误');
        }

        $tpl = Db::name('designer_template')->where('id', $id)->find();
        if (empty($tpl)) {
            return $this->error('模板不存在');
        }
        if ((int) ($tpl['aid'] ?? 0) === 0) {
            return $this->error('平台模板不允许删除');
        }
        if ((int) $tpl['aid'] !== $aid) {
            return $this->error('仅可删除本租户的模板');
        }

        Db::name('designer_template')->where('id', $id)->delete();

        return $this->success([], '删除成功');
    }

    /**
     * 选项卡子内容读取（designerpage_tab：tabid=tab组件id，tabindexid=选项项id）
     * 口径对照参考系统 dp-tab gettabcontent：按 tabid+tabindexid 取子内容组件数组
     * GET /admin/designer/tab/get?tabid=&tabindexid=&pageid=
     */
    public function tabGet()
    {
        $aid        = (int) ($this->request->aid ?? 0);
        $tabid      = trim((string) Request::get('tabid', ''));
        $tabindexid = trim((string) Request::get('tabindexid', ''));
        if ($tabid === '' || $tabindexid === '') {
            return $this->error('参数错误');
        }

        $row = Db::name('designerpage_tab')
            ->where('aid', $aid)
            ->where('tabid', $tabid)
            ->where('tabindexid', $tabindexid)
            ->find();

        return $this->success([
            'tabid'      => $tabid,
            'tabindexid' => $tabindexid,
            'content'    => $row ? (json_decode((string) ($row['content'] ?? ''), true) ?: []) : [],
        ]);
    }

    /**
     * 选项卡子内容保存（按 aid+tabid+tabindexid upsert，content 为子内容组件数组 JSON）
     * POST /admin/designer/tab/save  {tabid, tabindexid, content:[], pageid?}
     */
    public function tabSave()
    {
        $aid        = (int) ($this->request->aid ?? 0);
        $tabid      = trim((string) Request::post('tabid', ''));
        $tabindexid = trim((string) Request::post('tabindexid', ''));
        $content    = Request::post('content/a', []);
        if ($tabid === '' || $tabindexid === '') {
            return $this->error('参数错误');
        }
        if (!is_array($content)) {
            return $this->error('选项内容格式错误');
        }

        $data = [
            'content'    => json_encode($content, JSON_UNESCAPED_UNICODE),
            'updatetime' => time(),
        ];

        $row = Db::name('designerpage_tab')
            ->where('aid', $aid)
            ->where('tabid', $tabid)
            ->where('tabindexid', $tabindexid)
            ->find();

        if (!empty($row)) {
            Db::name('designerpage_tab')->where('id', (int) $row['id'])->update($data);
        } else {
            $data['aid']        = $aid;
            $data['tabid']      = $tabid;
            $data['tabindexid'] = $tabindexid;
            $data['createtime'] = time();
            Db::name('designerpage_tab')->insert($data);
        }

        return $this->success([], '保存成功');
    }
}