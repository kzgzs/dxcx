<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\AdminAuthService;

/**
 * 租户后台 - 登录
 */
class Login extends BaseController
{
    public function __construct(\think\App $app, protected AdminAuthService $authService)
    {
        parent::__construct($app);
    }

    /**
     * 租户管理员账号密码登录
     * POST /admin/login body: {username, password}
     */
    public function index()
    {
        $username = trim((string) $this->request->post('username', ''));
        $password = (string) $this->request->post('password', '');

        $this->validate(
            ['username' => $username, 'password' => $password],
            ['username' => 'require', 'password' => 'require']
        );

        return $this->success($this->authService->login($username, $password));
    }
}
