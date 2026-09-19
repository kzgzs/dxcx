<?php

declare(strict_types=1);

namespace app\common\service;

use think\facade\Db;

/**
 * C 端设计器消费与系统配置下发服务
 *
 * 数据口径（对齐参考系统，表述自有）：
 * - designerpage：pageinfo 固定首项 topbar（title/bgcolor/needlogin/quanxian），content 为组件数组 {id,temp,params,data}
 * - 仅消费 type=0 常规设计页（type=1 为模板库，不下发 C 端）
 * - 系统配置下发：租户级 admin_set（店名/客服/主题色）+ shop_sysset（商城开关）
 *   + 平台级 tenant_config（aid=0，sys_system_ 前缀）
 */
class DesignerService
{
    /** 平台级配置统一用 aid=0 */
    public const PLATFORM_AID = 0;

    /** 平台级（sys_system_ 前缀）下发 C 端的字段白名单 */
    private const PLATFORM_FIELDS = [
        'system_name', 'logo', 'ico', 'copyright', 'beian',
        'tel', 'weixin', 'company', 'address', 'reg_open',
    ];

    /** 租户 admin_set 下发 C 端的字段 => 兜底默认值 */
    private const ADMIN_SET_FIELDS = [
        'name'      => '',
        'logo'      => '',
        'desc'      => '',
        'tel'       => '',
        'province'  => '',
        'city'      => '',
        'district'  => '',
        'address'   => '',
        'longitude' => '',
        'latitude'  => '',
        'kfurl'     => '',
        'wxkf'      => 0,
        'wxkfurl'   => '',
        'color1'    => '#FD4A46',
        'color2'    => '#7E71F6',
        'moneypay'  => 1,
        'recharge'  => 1,
        'score2money' => '0.01',
        'invoice'   => 0,
        'status'    => 1,
        'close_tip' => '',
        // 任务308：基础设置增量下发（公众号关注/关注提示/订单播报/导航色/前端访问条件）
        'official_account_status' => 0,
        'guanzhu_tip'     => '',
        'order_broadcast' => '1,2',
        'navbgmode'       => 0,
        'navbgcolor'      => '',
        'navtextcolor'    => 0,
        'visitperm'       => 'all',
    ];

    /** 租户 shop_sysset 下发 C 端的字段 => 兜底默认值 */
    private const SHOP_SYSSET_FIELDS = [
        'autoclose'         => 60,
        'comment'           => 1,
        'comment_check'     => 1,
        'showjd'            => 1,
        'gwctj'             => 1,
        'cancod'            => 0,
        'codtxt'            => '货到付款',
        'hide_sales'        => 0,
        'hide_stock'        => 0,
        'canrefund'         => 1,
        'refundpic'         => 0,
        'price_show_type'   => 0,
        'coupon_peruselimit' => 1,
    ];

    /**
     * 取设计页（aid 隔离，仅消费 type=0 常规设计页）
     *
     * @param int $aid 租户 ID
     * @param int $id  页面 ID；<=0 时按 $ishome 口径取标记页
     * @param int $ishome 无 id 时的取页口径：1 首页（默认）/ 2 会员中心页（P27-4 任务248 增量）
     * @return array 命中返回 {id,name,ishome,pageinfo,content}；未命中返回空数组
     */
    public function fetchPage(int $aid, int $id = 0, int $ishome = 1): array
    {
        if ($aid <= 0) {
            return [];
        }

        // 口径白名单：仅 1 首页 / 2 会员中心页，其余回退首页口径（不动既有首页口径）
        $homeFlag = $ishome === 2 ? 2 : 1;

        $query = Db::name('designerpage')->where('aid', $aid)->where('type', 0);
        $query = $id > 0
            ? $query->where('id', $id)
            : $query->where('ishome', $homeFlag)->order('id', 'desc');

        $row = $query->find();
        if (empty($row)) {
            return [];
        }

        $pageinfo = self::decodeJson((string) ($row['pageinfo'] ?? ''));
        $content  = self::decodeJson((string) ($row['content'] ?? ''));

        // 页面名：topbar 标题优先，兜底页面 name
        $name = (string) ($pageinfo[0]['params']['title'] ?? '');
        if ('' === trim($name)) {
            $name = (string) ($row['name'] ?? '');
        }

        return [
            'id'       => (int) $row['id'],
            'name'     => $name,
            'ishome'   => (int) ($row['ishome'] ?? 0),
            'pageinfo' => $pageinfo,
            'content'  => $content,
        ];
    }

    /**
     * 模板消费（任务278：B端模板卡二维码 → pages/index/main?tid=xx 预览）
     *
     * 取数口径：平台模板（aid=0）+ 当前租户自建模板（aid=$aid）；
     * 返回结构与 fetchPage 对齐（id/name/ishome/pageinfo/content），不存在返回 []。
     */
    public function fetchTemplate(int $aid, int $tid): array
    {
        if ($tid <= 0) {
            return [];
        }

        $row = Db::name('designer_template')->where('id', $tid)->whereIn('aid', [0, $aid])->find();
        if (empty($row)) {
            return [];
        }

        $pageinfo = self::decodeJson((string) ($row['pageinfo'] ?? ''));
        $content  = self::decodeJson((string) ($row['content'] ?? ''));

        // 模板名：topbar 标题优先，兜底模板 name
        $name = (string) ($pageinfo[0]['params']['title'] ?? '');
        if ('' === trim($name)) {
            $name = (string) ($row['name'] ?? '');
        }

        return [
            'id'       => (int) $row['id'],
            'name'     => $name,
            'ishome'   => 0,
            'pageinfo' => $pageinfo,
            'content'  => $content,
        ];
    }

    /**
     * 会员可见性过滤（下发前剔除，独立方法，不破坏 fetchPage/fetchNav 既有返回结构）
     *
     * 规则（对齐参考系统 System::initpagecontent 口径，表述自有）：
     * - topbar needlogin=1 且未登录：整页 content 不下发（前端另有跳登录兜底，数据侧双保险）
     * - 组件 params.quanxian 缺省/空/非数组：视为全员可见（老页面大多无此字段，零回归保险）
     * - quanxian.all 为真：全员可见
     * - 其余按等级键匹配：未登录剔除；已登录且 levelid 命中保留，不满足剔除
     *
     * @param array $page    fetchPage 返回结构 {id,name,ishome,pageinfo,content}
     * @param int   $mid     当前会员 ID；<=0 表示未登录（匿名公开访问）
     * @param int   $levelid 当前会员等级 ID（member.levelid）
     * @return array 过滤后的页面结构（键名与 fetchPage 完全一致）
     */
    public function applyMemberFilter(array $page, int $mid, int $levelid): array
    {
        if ([] === $page) {
            return $page;
        }

        $loggedIn = $mid > 0;

        // topbar needlogin=1：未登录整页 content 不下发
        $topbar = $page['pageinfo'][0]['params'] ?? [];
        if (is_array($topbar) && 1 === (int) ($topbar['needlogin'] ?? 0) && !$loggedIn) {
            $page['content'] = [];

            return $page;
        }

        $kept = [];
        foreach (($page['content'] ?? []) as $node) {
            if (!is_array($node)) {
                $kept[] = $node; // 非数组节点原样保留，交由前端容错
                continue;
            }
            $params = is_array($node['params'] ?? null) ? $node['params'] : [];
            if (self::nodeVisible($params, $loggedIn, $levelid)) {
                $kept[] = $node;
            }
        }
        $page['content'] = $kept;

        return $page;
    }

    /**
     * 单节点会员可见性判定（quanxian：all 全员 / 等级键匹配；缺省全员可见）
     */
    public static function nodeVisible(array $params, bool $loggedIn, int $levelid): bool
    {
        $qx = $params['quanxian'] ?? null;

        // 无权限配置 → 全员可见（老页面兼容）
        if (!is_array($qx) || [] === $qx) {
            return true;
        }

        if (!empty($qx['all'])) {
            return true;
        }

        // 限定等级：未登录一律不可见
        if (!$loggedIn) {
            return false;
        }

        // 等级键匹配（JSON 数字键解码后为 int，PHP 数组键自动归一）
        return !empty($qx[$levelid]);
    }

    /**
     * 页面级访问判定（任务305：强制登录/查看权限/付费查看）
     *
     * topbar params 口径：
     * - needlogin '1' 强制登录：未登录拦截
     * - viewperm {all:true}|{member:true}|{dealer:true} 查看权限复选（缺省/{all} 全员）
     * - payview '1' 付费查看 + payviewprice 金额（元）：未购买拦截，已购买放行
     *
     * @return array{needlogin_ok:bool, perm_ok:bool, payview:int, price:string, paid:bool, levelid:int, ggcrowd:array, ggmode:string}
     */
    public function pageAccess(array $page, int $mid): array
    {
        $topbar = $page['pageinfo'][0]['params'] ?? [];
        $topbar = is_array($topbar) ? $topbar : [];
        
        $loggedIn = $mid > 0;
        $levelid  = 0;
        if ($loggedIn) {
            $levelid = (int) (Db::name('member')->where('id', $mid)->value('levelid') ?: 0);
        }
    
        // 强制登录
        $needloginOk = !(1 === (int) ($topbar['needlogin'] ?? 0) && !$loggedIn);
    
        // 查看权限：缺省/all 全员；member 任意登录会员；dealer 登录且命中钻石及以上等级（5）
        $vp = $topbar['viewperm'] ?? null;
        $permOk = true;
        if (is_array($vp) && [] !== $vp && empty($vp['all'])) {
            if (!$loggedIn) {
                $permOk = false;
            } else {
                $permOk = (!empty($vp['member'])) || (!empty($vp['dealer']) && $levelid >= 5);
            }
        }
    
        // 付费查看：payview=1 时查购买记录（status=1 放行）
        $payview = 1 === (int) ($topbar['payview'] ?? 0) ? 1 : 0;
        $price   = (string) ($topbar['payviewprice'] ?? '');
        $paid    = false;
        if ($payview && $loggedIn && (int) ($page['id'] ?? 0) > 0) {
            $paid = (bool) Db::name('designer_page_buy')
                ->where('mid', $mid)
                ->where('page_id', (int) $page['id'])
                ->where('status', 1)
                ->find();
        }
    
        return [
            'needlogin_ok' => $needloginOk,
            'perm_ok'      => $permOk,
            'payview'      => $payview,
            'price'        => $price,
            'paid'         => $paid,
            // 任务306：开屏广告展示人群/展示方式 + 会员等级（C 端本地判定用）
            'levelid'      => $levelid,
            'ggcrowd'      => is_array($topbar['ggcrowd'] ?? null) ? $topbar['ggcrowd'] : ['all' => true],
            'ggmode'       => (string) ($topbar['ggmode'] ?? '0'),
        ];
    }

    /**
     * 付费查看购买入账（PayService 分发，幂等：行锁 + status=0 条件更新）
     */
    public function onPageBuyPaid(int $buyId, string $payType, string $payNum): bool
    {
        return (bool) Db::transaction(function () use ($buyId, $payType) {
            $row = Db::name('designer_page_buy')->where('id', $buyId)->lock(true)->find();

            if (empty($row) || 0 !== (int) $row['status']) {
                return false; // 已支付：幂等不重复处理
            }

            Db::name('designer_page_buy')
                ->where('id', $buyId)
                ->where('status', 0)
                ->update([
                    'status'   => 1,
                    'pay_type' => $payType,
                    'paytime'  => time(),
                ]);

            return true;
        });
    }
    
    /**
     * 空页面结构（无首页时下发，前端走默认首页兑底）
     */
    public function emptyPage(): array
    {
        return [
            'id'       => 0,
            'name'     => '',
            'ishome'   => 0,
            'pageinfo' => [],
            'content'  => [],
        ];
    }

    /**
     * 系统配置下发（C 端所需字段）
     *
     * @return array{
     *   name:string, logo:string, desc:string, tel:string, kfurl:string, wxkf:int, wxkfurl:string,
     *   color1:string, color2:string, status:int, close_tip:string,
     *   province:string, city:string, district:string, address:string, longitude:string, latitude:string,
     *   moneypay:int, recharge:int, score2money:string, invoice:int,
     *   shop:array, platform:array, nav:array, shopdetail:array
     * }
     */
    public function sysset(int $aid): array
    {
        // 租户级商城设置（admin_set 单行表，行不存在给表默认值）
        $data = self::ADMIN_SET_FIELDS;
        if ($aid > 0) {
            $row = Db::name('admin_set')->where('aid', $aid)->find();
            if (!empty($row)) {
                foreach (self::ADMIN_SET_FIELDS as $field => $default) {
                    $value = $row[$field] ?? null;
                    $data[$field] = (null === $value || '' === $value) ? $default : $value;
                }
            }
        }

        // 租户级商城开关（shop_sysset）
        $shop = self::SHOP_SYSSET_FIELDS;
        if ($aid > 0) {
            $row = Db::name('shop_sysset')->where('aid', $aid)->find();
            if (!empty($row)) {
                foreach (self::SHOP_SYSSET_FIELDS as $field => $default) {
                    $value = $row[$field] ?? null;
                    $shop[$field] = (null === $value || '' === $value) ? $default : $value;
                }
            }
        }

        // 平台级系统设置（tenant_config aid=0，sys_system_ 前缀）
        $platform = [];
        foreach (self::PLATFORM_FIELDS as $field) {
            $platform[$field] = TenantConfigService::get(self::PLATFORM_AID, 'sys_system_' . $field, '');
        }

        $data['shop']     = $shop;
        $data['platform'] = $platform;
        // 底部导航：designer_menu 真实数据下发（h5 行优先，无则取首行；均无返回空数组前端走默认 tabBar）
        $data['nav']      = $this->fetchNav($aid);
        // 商品详情底部配置：designer_shopdetail（三菜单 + 公共底部描述，任务281）
        $data['shopdetail'] = $this->fetchShopdetail($aid);

        return $data;
    }

    /**
     * C 端前端门控判定（任务308：状态 + 系统前端访问条件）
     *
     * 口径：status=0 关停；visitperm CSV 含 all 或为空 → 放行；
     * 否则 member=已登录 / dealer=已登录且 levelid>=5，命中任一勾选即放行。
     *
     * @param array<string, mixed> $sysset sysset() 下发结构
     * @return array{status:int, close_tip:string, logged:bool, levelid:int, perm_ok:bool}
     */
    public function syssetGate(array $sysset, int $mid, int $levelid): array
    {
        $perms  = array_filter(array_map('trim', explode(',', (string) ($sysset['visitperm'] ?? 'all'))));
        $logged = $mid > 0;
        if (empty($perms) || in_array('all', $perms, true)) {
            $permOk = true;
        } else {
            $permOk = (in_array('member', $perms, true) && $logged)
                || (in_array('dealer', $perms, true) && $logged && $levelid >= 5);
        }

        return [
            'status'    => (int) ($sysset['status'] ?? 1),
            'close_tip' => (string) ($sysset['close_tip'] ?? ''),
            'logged'    => $logged,
            'levelid'   => $levelid,
            'perm_ok'   => $permOk,
        ];
    }

    /**
     * 底部导航消费（designer_menu：h5 行优先，无则取任意首行）
     *
     * @return array menudata 结构 {color,selectedColor,backgroundColor,borderStyle,position,list[]}；无配置返回 []
     */
    public function fetchNav(int $aid): array
    {
        if ($aid <= 0) {
            return [];
        }

        $row = Db::name('designer_menu')->where('aid', $aid)->where('platform', 'h5')->find();
        if (empty($row)) {
            $row = Db::name('designer_menu')->where('aid', $aid)->order('id', 'asc')->find();
        }
        if (empty($row)) {
            return [];
        }

        $menudata = self::decodeJson((string) ($row['menudata'] ?? ''));

        return empty($menudata['list']) ? [] : $menudata;
    }

    /**
     * 商品详情底部配置消费（designer_shopdetail bid=0 主配置，任务281）
     *
     * @return array {list:槽位数组, description:公共描述富文本, description_img:补充图, share_login:分享海报登录口径}；无配置返回 []
     */
    public function fetchShopdetail(int $aid): array
    {
        if ($aid <= 0) {
            return [];
        }

        $row = Db::name('designer_shopdetail')->where('aid', $aid)->where('bid', 0)->find();
        if (empty($row)) {
            return [];
        }

        $menudata = self::decodeJson((string) ($row['menudata'] ?? ''));

        return [
            'list'            => isset($menudata['list']) && is_array($menudata['list']) ? $menudata['list'] : [],
            'description'     => (string) ($row['description'] ?? ''),
            'description_img' => (string) ($row['description_img'] ?? ''),
            'share_login'     => (int) ($row['share_login'] ?? 1),
        ];
    }

    /**
     * 登录页装修消费（designer_login）
     *
     * @return array 19 主题字段结构；未配置返回 []（C 端走默认样式）
     */
    public function fetchLogin(int $aid): array
    {
        if ($aid <= 0) {
            return [];
        }

        $row = Db::name('designer_login')->where('aid', $aid)->order('id', 'desc')->find();
        if (empty($row)) {
            return [];
        }

        return self::decodeJson((string) ($row['data'] ?? ''));
    }

    /**
     * JSON 容错解析（非数组一律回空数组）
     */
    public static function decodeJson(string $raw): array
    {
        if ('' === trim($raw)) {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
