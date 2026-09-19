<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\AdminAuthService;
use think\facade\Db;

/**
 * 租户后台 - 快捷菜单（对标参考系统 ShortcutMenu.php）
 *
 * shortcut_menu 每用户一行：aid + uid 唯一定位，menus 存 JSON
 * 结构：[{ name: '菜单名', key: '菜单key' }, ...]
 * 读取时按当前账号权限 key 集合过滤（无权限的菜单不返回）
 */
class ShortcutMenu extends BaseController
{
    public function __construct(\think\App $app, protected AdminAuthService $authService)
    {
        parent::__construct($app);
    }

    /**
     * 读取当前用户的快捷菜单（按权限过滤）
     * GET /admin/shortcutMenu/index
     */
    public function index()
    {
        $aid = (int) ($this->request->aid ?? 0);
        $uid = (int) ($this->request->authUid ?? 0);

        $row = Db::name('shortcut_menu')
            ->where('aid', $aid)
            ->where('uid', $uid)
            ->find();

        $menus = [];
        if (!empty($row['menus'])) {
            $decoded = json_decode((string) $row['menus'], true);
            if (is_array($decoded)) {
                $menus = $decoded;
            }
        }

        // 按权限过滤
        $scope = $this->authService->authScope((array) ($this->request->authPayload ?? []));
        if ('all' !== $scope['mode']) {
            $allowed = array_flip($scope['keys']);
            $menus   = array_values(array_filter($menus, function ($item) use ($allowed) {
                $key = is_array($item) ? (string) ($item['key'] ?? ($item['value'] ?? '')) : (string) $item;
                return isset($allowed[$key]) || isset($allowed['*']);
            }));
        }

        return $this->success(['menus' => $menus]);
    }

    /**
     * 保存当前用户的快捷菜单（upsert）
     * POST /admin/shortcutMenu/save body: { menus: [{name,key}] }
     */
    public function save()
    {
        $aid   = (int) ($this->request->aid ?? 0);
        $uid   = (int) ($this->request->authUid ?? 0);
        $menus = $this->request->post('menus/a', []);

        // 规范化：只保留 name/key 两个字段
        $clean = [];
        foreach ($menus as $item) {
            if (!is_array($item)) {
                continue;
            }
            $key  = trim((string) ($item['key'] ?? ($item['value'] ?? '')));
            $name = trim((string) ($item['name'] ?? ($item['label'] ?? '')));
            if ('' === $key) {
                continue;
            }
            $clean[] = ['name' => $name, 'key' => $key];
        }

        $json = json_encode($clean, JSON_UNESCAPED_UNICODE);

        $row = Db::name('shortcut_menu')
            ->where('aid', $aid)
            ->where('uid', $uid)
            ->find();

        if (empty($row)) {
            Db::name('shortcut_menu')->insert([
                'aid'   => $aid,
                'bid'   => 0,
                'uid'   => $uid,
                'menus' => $json,
            ]);
        } else {
            Db::name('shortcut_menu')
                ->where('aid', $aid)
                ->where('uid', $uid)
                ->update(['menus' => $json]);
        }

        return $this->success([], '保存成功');
    }
}
