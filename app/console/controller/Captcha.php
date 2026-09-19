<?php

declare(strict_types=1);

namespace app\console\controller;

use app\BaseController;
use app\common\service\CaptchaService;

/**
 * 超管控制台 - 图形验证码（公开接口，免登录，见 AuthMiddleware 白名单）
 */
class Captcha extends BaseController
{
    /**
     * 获取图形验证码
     * GET /console/captcha → {captcha_id, image(base64 PNG)}，有效期 5 分钟，一次性使用
     */
    public function index()
    {
        return $this->success(CaptchaService::create());
    }
}
