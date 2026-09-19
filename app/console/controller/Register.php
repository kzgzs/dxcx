<?php

declare(strict_types=1);

namespace app\console\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\CaptchaService;
use app\common\service\RegisterService;

/**
 * 超管控制台 - 官网会员注册（公开接口，免登录，见 AuthMiddleware 白名单）
 */
class Register extends BaseController
{
    public function __construct(\think\App $app, protected RegisterService $registerService)
    {
        parent::__construct($app);
    }

    /**
     * 注册页公开配置
     * GET /console/register/config → {reg_open, sms_verify, reg_agreement_url}
     */
    public function config()
    {
        return $this->success($this->registerService->config());
    }

    /**
     * 发送注册短信验证码（MOCK 模式固定 123456）
     * POST /console/register/sendsms body: {mobile}
     */
    public function sendsms()
    {
        $mobile = trim((string) $this->request->post('mobile', ''));

        return $this->success($this->registerService->sendSms($mobile));
    }

    /**
     * 提交注册（创建会员，成功返回会员ID与手机号）
     * POST /console/register/submit body: {mobile, password, code, agreement, captcha_id, captcha}
     */
    public function submit()
    {
        $mobile    = trim((string) $this->request->post('mobile', ''));
        $password  = (string) $this->request->post('password', '');
        $code      = trim((string) $this->request->post('code', ''));
        $agreement = !empty($this->request->post('agreement', 0));
        $captchaId = trim((string) $this->request->post('captcha_id', ''));
        $captcha   = trim((string) $this->request->post('captcha', ''));

        // 图形验证码校验（不区分大小写，一次性使用，验证后立即删除防重放）
        if (!CaptchaService::verify($captchaId, $captcha)) {
            throw new BusinessException('图形验证码错误');
        }

        return $this->success(
            $this->registerService->submit($mobile, $password, $code, $agreement),
            '注册成功'
        );
    }
}
