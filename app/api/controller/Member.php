<?php

declare(strict_types=1);

namespace app\api\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\MemberService;
use app\common\service\ScopeService;
use app\common\service\ScoreService;

/**
 * 客户端 - 会员会话（api 应用）
 */
class Member extends BaseController
{
    public function __construct(
        \think\App $app,
        protected MemberService $memberService,
        protected ScoreService $scoreService
    ) {
        parent::__construct($app);
    }

    /**
     * 会员手机号+密码登录
     * POST /api/member/login body: {phone, password}
     */
    public function login()
    {
        $phone    = trim((string) $this->request->post('phone', ''));
        $password = (string) $this->request->post('password', '');

        $this->validate(
            ['phone' => $phone, 'password' => $password],
            ['phone' => 'require', 'password' => 'require']
        );

        return $this->success($this->memberService->login($phone, $password));
    }

    /**
     * 微信 code 静默登录（白名单；aid 走 TenantMiddleware 解析）
     * POST /api/member/wxlogin body: {code}
     */
    public function wxlogin()
    {
        $code = trim((string) $this->request->post('code', ''));
        $aid  = (int) $this->request->aid;

        return $this->success($this->memberService->wxLogin($aid, $code));
    }

    /**
     * 发送短信验证码（白名单；Redis 600s，SMS mock 固定 123456）
     * POST /api/member/sendsms body: {mobile}
     */
    public function sendsms()
    {
        $mobile = trim((string) $this->request->post('mobile', ''));
        $aid    = (int) $this->request->aid;

        return $this->success($this->memberService->sendSms($aid, $mobile));
    }

    /**
     * 短信验证码登录（白名单；无会员自动注册）
     * POST /api/member/smslogin body: {mobile, code}
     */
    public function smslogin()
    {
        $mobile = trim((string) $this->request->post('mobile', ''));
        $code   = trim((string) $this->request->post('code', ''));
        $aid    = (int) $this->request->aid;

        return $this->success($this->memberService->smsLogin($aid, $mobile, $code));
    }

    /**
     * 会员基础信息
     * GET /api/member/profile
     */
    public function profile()
    {
        $payload = (array) ($this->request->authPayload ?? []);
        $mid     = (int) ($payload['mid'] ?? ($payload['uid'] ?? 0));
        $aid     = ScopeService::aidOf($this->request);

        return $this->success($this->memberService->profile($mid, $aid));
    }

    /**
     * 我的积分余额
     * GET /api/member/score
     */
    public function score()
    {
        $payload = (array) ($this->request->authPayload ?? []);
        $mid     = (int) ($payload['mid'] ?? ($payload['uid'] ?? 0));
        $aid     = ScopeService::aidOf($this->request);

        if ($mid <= 0) {
            throw new BusinessException('未登录或登录已过期', 401, 401);
        }

        return $this->success($this->scoreService->balance($aid, $mid));
    }

    /**
     * 编辑个人资料（批次4-A：四字段齐全时触发完善资料送分，只送一次）
     * POST /api/member/editProfile {nickname?,headimg?,realname?,sex?,birthday?}
     */
    public function editProfile()
    {
        $payload = (array) ($this->request->authPayload ?? []);
        $mid     = (int) ($payload['mid'] ?? ($payload['uid'] ?? 0));
        $aid     = ScopeService::aidOf($this->request);

        if ($mid <= 0) {
            throw new BusinessException('未登录或登录已过期', 401, 401);
        }

        return $this->success($this->memberService->editProfile($aid, $mid, $this->request->post()), '保存成功');
    }
}
