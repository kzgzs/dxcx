<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;

/**
 * 租户后台（admin 应用）认证与基础信息服务
 *
 * 约定：
 * - admin_user 表 = 登录账号表（un/pwd），isadmin：2 平台超管 / 1 租户主账号 / 0 租户子账号
 * - 租户后台登录仅放行 isadmin<=1 且绑定租户（aid>0）的账号
 * - 租户到期（endtime 已过）允许登录，但返回 expired=true 由前端提示
 * - 密码校验复用 TenantService::verifyPassword（bcrypt / 旧 md5 双格式兼容）
 */
class AdminAuthService
{
    /**
     * 租户后台账号密码登录
     *
     * @return array{token: string, userinfo: array{id: int, username: string, isadmin: int, aid: int, expired: bool}}
     */
    public function login(string $username, string $password): array
    {
        $user = Db::name('admin_user')->where('un', $username)->find();

        if (empty($user) || !TenantService::verifyPassword($password, (string) $user['pwd'])) {
            throw new BusinessException('账号或密码错误', 401, 401);
        }

        if ((int) $user['isadmin'] === 2) {
            throw new BusinessException('超管账号请走超管入口登录', 403, 403);
        }

        if ((int) $user['status'] !== 1) {
            throw new BusinessException('账号已被禁用', 401, 401);
        }

        $aid = (int) ($user['aid'] ?? 0);
        if ($aid <= 0) {
            throw new BusinessException('账号未绑定租户，无法登录租户后台', 403, 403);
        }

        $tenant = Db::name('admin')->where('id', $aid)->where('deleted', 0)->find();

        if (empty($tenant)) {
            throw new BusinessException('所属租户不存在或已被删除', 403, 403);
        }

        if ((int) $tenant['status'] !== TenantService::STATUS_NORMAL) {
            throw new BusinessException('所属租户已被关闭，请联系平台', 403, 403);
        }

        // 到期账号：允许登录，仅标记 expired 由前端提示
        $endtime = (int) ($tenant['endtime'] ?? 0);
        $expired = $endtime > 0 && $endtime < time();

        $token = JwtService::sign([
            'uid'     => (int) $user['id'],
            'aid'     => $aid,
            'un'      => (string) $user['un'],
            'isadmin' => (int) $user['isadmin'],
            'role'    => 'admin',
        ]);

        return [
            'token'    => $token,
            'userinfo' => [
                'id'       => (int) $user['id'],
                'username' => (string) $user['un'],
                'isadmin'  => (int) $user['isadmin'],
                'aid'      => $aid,
                'expired'  => $expired,
            ],
        ];
    }

    /**
     * 当前登录账号信息（含所属租户摘要）
     *
     * 超管代登录（token 携带 super=1）：返回 super=1 且 isadmin 固定为 2，
     * 租户壳识别该标记后放行全部菜单（最高权限，不受租户权限配置限制）。
     * 注：仅认 token 中的 super 标记，不单独认 isadmin=2（旧数据租户主账号可能存在 isadmin=2）。
     *
     * @param array $payload JWT 载荷（uid/aid/un/isadmin/super 等）
     * @return array{id: int, username: string, isadmin: int, aid: int, super: int, tenant: array{id: int, username: string, company: string, endtime: int, expired: bool}}
     */
    public function profile(array $payload): array
    {
        $aid    = (int) ($payload['aid'] ?? 0);
        $tenant = Db::name('admin')->where('id', $aid)->where('deleted', 0)->find();

        if (empty($tenant)) {
            throw new BusinessException('所属租户不存在或已被删除', 403, 403);
        }

        $endtime = (int) ($tenant['endtime'] ?? 0);

        // 仅认 super 代登录标记：旧数据租户主账号也可能 isadmin=2，不能误判为平台超管
        $isSuper = 1 === (int) ($payload['super'] ?? 0);

        return [
            'id'       => (int) ($payload['uid'] ?? 0),
            'username' => (string) ($payload['un'] ?? ''),
            'isadmin'  => $isSuper ? 2 : (int) ($payload['isadmin'] ?? 0),
            'aid'      => $aid,
            'super'    => $isSuper ? 1 : 0,
            'tenant'   => [
                'id'       => (int) $tenant['id'],
                'username' => (string) ($tenant['username'] ?? ''),
                'company'  => (string) ($tenant['company'] ?? ''),
                'endtime'  => $endtime,
                'expired'  => $endtime > 0 && $endtime < time(),
            ],
        ];
    }

    /**
     * 解析当前登录账号的菜单权限范围（子账号按权限组层级裁剪，对标参考系统 Common::initialize）
     *
     * 规则（优先级从高到低）：
     * - 超管代登录（token super=1）→ all
     * - 租户主账号（isadmin>=1）→ all
     * - 子账号 auth_type=1 → all
     * - 子账号挂权限组（groupid>0）→ 取权限组 auth_data
     * - 子账号未挂权限组 → 取自身 auth_data
     * auth_data 取值：字符串 'all' 或 JSON 数组（租户客户端菜单 key 集合）
     *
     * @param array $payload JWT 载荷
     * @return array{mode: string, keys: array<int, string>}
     */
    public function authScope(array $payload): array
    {
        // 超管代登录：最高权限，不受租户权限配置限制
        if (1 === (int) ($payload['super'] ?? 0)) {
            return ['mode' => 'all', 'keys' => []];
        }

        // 租户主账号：全部菜单
        if ((int) ($payload['isadmin'] ?? 0) >= 1) {
            return ['mode' => 'all', 'keys' => []];
        }

        $uid  = (int) ($payload['uid'] ?? 0);
        $user = Db::name('admin_user')->where('id', $uid)->find();

        if (empty($user)) {
            return ['mode' => 'keys', 'keys' => []];
        }

        // auth_type=1 所有权限
        if ((int) ($user['auth_type'] ?? 0) === 1) {
            return ['mode' => 'all', 'keys' => []];
        }

        $authData = (string) ($user['auth_data'] ?? '');

        // 子账号挂权限组：权限以组为准（对标参考系统：组的 auth_data 覆盖账号自身）
        if ((int) ($user['groupid'] ?? 0) > 0) {
            $group = Db::name('admin_user_group')->where('id', (int) $user['groupid'])->find();
            $authData = $group ? (string) ($group['auth_data'] ?? '') : '';
        }

        return ['mode' => 'keys', 'keys' => self::parseAuthData($authData)];
    }

    /**
     * 解析 auth_data 存储内容 → 菜单 key 数组（'all' 返回 ['*'] 由调用方判断）
     *
     * @return array<int, string>
     */
    public static function parseAuthData(string $authData): array
    {
        $authData = trim($authData);

        if ('' === $authData) {
            return [];
        }

        $decoded = json_decode($authData, true);

        if ('all' === $authData || 'all' === $decoded) {
            return ['*'];
        }

        if (!is_array($decoded)) {
            return [];
        }

        if (in_array('all', $decoded, true)) {
            return ['*'];
        }

        return array_values(array_unique(array_filter(array_map('strval', $decoded))));
    }

    /**
     * 租户后台菜单树（按当前账号 auth_data 过滤返回）
     *
     * 返回结构：
     * - mode：all 全量 / keys 按 key 集合裁剪
     * - keys：授权的菜单 key 集合（all 时为空数组）
     * - menus：过滤后的菜单树（镜像 tenantMenus 结构：name/key/hide/child）
     *
     * @param array $payload JWT 载荷
     * @return array{mode: string, keys: array<int, string>, menus: array}
     */
    public function menuAuthed(array $payload): array
    {
        $scope = $this->authScope($payload);

        if ('all' === $scope['mode'] || in_array('*', $scope['keys'], true)) {
            return ['mode' => 'all', 'keys' => [], 'menus' => self::tenantMenuTree()];
        }

        $allowed = array_flip($scope['keys']);

        return [
            'mode'  => 'keys',
            'keys'  => $scope['keys'],
            'menus' => self::filterMenuTree(self::tenantMenuTree(), $allowed),
        ];
    }

    /**
     * 租户客户端菜单树镜像（frontend/console-admin tenantMenus.ts 的结构投影：name/key/hide/child）
     * 由脚本自 tenantMenus.ts 生成，作为后端权限树/菜单过滤的唯一数据源
     *
     * @return array<int, array<string, mixed>>
     */
    public static function tenantMenuTree(): array
    {
        static $tree = null;

        if (null === $tree) {
            $file = __DIR__ . '/../menu/tenant_menu.json';
            $tree = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
        }

        return $tree;
    }

    /**
     * 按授权 key 集合裁剪菜单树：节点自身在集合中则整棵保留，否则递归保留命中子孙
     *
     * @param array<int, array<string, mixed>> $nodes
     * @param array<string, int>               $allowed key => 1
     * @return array<int, array<string, mixed>>
     */
    protected static function filterMenuTree(array $nodes, array $allowed): array
    {
        $result = [];

        foreach ($nodes as $node) {
            $key = (string) ($node['key'] ?? '');

            // 节点自身已授权：整棵子树保留
            if (isset($allowed[$key])) {
                $result[] = $node;
                continue;
            }

            // 未整体授权：递归看子孙是否有命中项
            if (!empty($node['child']) && is_array($node['child'])) {
                $kids = self::filterMenuTree($node['child'], $allowed);
                if ($kids) {
                    $node['child'] = $kids;
                    $result[] = $node;
                }
            }
        }

        return $result;
    }

    /**
     * 租户后台菜单树（对齐参考系统原系统一级分组：简称 name + 全称 fullname + 路径 path）
     *
     * @return array<int, array{name: string, fullname?: string, path: string, icon?: string, children?: array}>
     */
    public function menu(): array
    {
        return [
            ['name' => '首页', 'fullname' => '首页', 'path' => '/admin/dashboard', 'icon' => 'home'],
            [
                'name'     => '商城',
                'fullname' => '商城系统',
                'path'     => '/admin/goods',
                'icon'     => 'shop',
                'children' => [
                    [
                        'name'     => '商品管理',
                        'path'     => '/admin/goods',
                        'children' => [
                            ['name' => '商品列表', 'path' => '/admin/goods'],
                            ['name' => '商品分类', 'path' => '/admin/goods/category'],
                        ],
                    ],
                    [
                        'name'     => '订单管理',
                        'path'     => '/admin/order',
                        'children' => [
                            ['name' => '订单列表', 'path' => '/admin/order'],
                            ['name' => '退款申请', 'path' => '/admin/refund'],
                        ],
                    ],
                ],
            ],
            [
                'name'     => '会员',
                'fullname' => '会员管理',
                'path'     => '/admin/member',
                'icon'     => 'member',
                'children' => [
                    ['name' => '会员列表', 'path' => '/admin/member'],
                ],
            ],
            [
                'name'     => '财务',
                'fullname' => '财务管理',
                'path'     => '/admin/finance',
                'icon'     => 'finance',
            ],
            [
                'name'     => '商户',
                'fullname' => '多商户',
                'path'     => '/admin/merchant',
                'icon'     => 'merchant',
                'children' => [
                    ['name' => '商户列表', 'path' => '/admin/merchant'],
                    ['name' => '门店管理', 'path' => '/admin/store'],
                ],
            ],
            [
                'name'     => '营销',
                'fullname' => '营销活动',
                'path'     => '/admin/coupon',
                'icon'     => 'marketing',
                'children' => [
                    ['name' => '优惠券', 'path' => '/admin/coupon'],
                    ['name' => '积分设置', 'path' => '/admin/score'],
                    ['name' => '满减促销', 'path' => '/admin/promotion'],
                ],
            ],
            [
                'name'     => '扩展',
                'fullname' => '扩展功能',
                'path'     => '/admin/carhailing/category',
                'icon'     => 'extend',
                'children' => [
                    [
                        'name'     => '网约车管理',
                        'path'     => '/admin/carhailing',
                        'children' => [
                            ['name' => '车辆分类', 'path' => '/admin/carhailing/category'],
                            ['name' => '车辆管理', 'path' => '/admin/carhailing/product'],
                            ['name' => '用车订单', 'path' => '/admin/carhailing/order'],
                        ],
                    ],
                    [
                        'name'     => '酒店管理',
                        'path'     => '/admin/hotel',
                        'children' => [
                            ['name' => '酒店列表', 'path' => '/admin/hotel/list'],
                            ['name' => '房型管理', 'path' => '/admin/hotel/room'],
                            ['name' => '酒店订单', 'path' => '/admin/hotel/order'],
                        ],
                    ],
                    [
                        'name'     => '预约管理',
                        'path'     => '/admin/yuyue',
                        'children' => [
                            ['name' => '预约项目', 'path' => '/admin/yuyue/product'],
                            ['name' => '人员管理', 'path' => '/admin/yuyue/worker'],
                            ['name' => '预约订单', 'path' => '/admin/yuyue/order'],
                        ],
                    ],
                    [
                        'name'     => '餐饮管理',
                        'path'     => '/admin/restaurant',
                        'children' => [
                            ['name' => '区域桌台', 'path' => '/admin/restaurant/table'],
                            ['name' => '菜品管理', 'path' => '/admin/restaurant/product'],
                            ['name' => '堂食订单', 'path' => '/admin/restaurant/order'],
                        ],
                    ],
                ],
            ],
            [
                'name'     => '系统',
                'fullname' => '系统设置',
                'path'     => '/admin/setting',
                'icon'     => 'setting',
            ],
        ];
    }
}
