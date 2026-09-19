<?php

declare(strict_types=1);

namespace app\console\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\CaptchaService;
use app\common\service\TenantService;

/**
 * 超管控制台 - 登录
 */
class Login extends BaseController
{
    public function __construct(\think\App $app, protected TenantService $tenantService)
    {
        parent::__construct($app);
    }

    /**
     * 超管登录
     * POST /console/login  body: {username, password, captcha_id, captcha}
     */
    public function login()
    {
        $username  = trim((string) $this->request->post('username', ''));
        $password  = (string) $this->request->post('password', '');
        $captchaId = trim((string) $this->request->post('captcha_id', ''));
        $captcha   = trim((string) $this->request->post('captcha', ''));

        $this->validate(
            ['username' => $username, 'password' => $password],
            ['username' => 'require', 'password' => 'require']
        );

        // 图形验证码校验（不区分大小写，一次性使用，验证后立即删除防重放）
        if (!CaptchaService::verify($captchaId, $captcha)) {
            throw new BusinessException('图形验证码错误');
        }

        return $this->success($this->tenantService->login($username, $password));
    }
}
