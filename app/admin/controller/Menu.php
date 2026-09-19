<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\AdminAuthService;

/**
 * 租户后台 - 菜单
 */
class Menu extends BaseController
{
    public function __construct(\think\App $app, protected AdminAuthService $authService)
    {
        parent::__construct($app);
    }

    /**
     * 租户后台菜单树（按当前登录账号 auth_data 过滤返回）
     * GET /admin/menu
     *
     * 返回：{ mode: all|keys, keys: 授权key集合, menus: 过滤后菜单树 }
     */
    public function index()
    {
        return $this->success($this->authService->menuAuthed((array) ($this->request->authPayload ?? [])));
    }
}
