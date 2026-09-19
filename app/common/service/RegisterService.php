<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;

/**
 * 官网会员注册服务（console 应用公开接口，平台级 aid=0）
 *
 * 约定：
 * - 注册开关/短信开关/协议链接读平台级系统设置 tenant_config（aid=0，key 前缀 sys_system_）
 * - 短信验证码复用 MemberService 口径：Redis 存 600s（key sms:reg:{mobile}），SMS.MOCK=1 固定 123456
 * - 注册会员落 member 表 aid=0（平台级会员），密码 password_hash 加密，兼容 verifyPassword 双格式
 * - reg_valid_days>0 时写入 levelendtime（会员有效期截止时间戳），0 表示永久
 */
class RegisterService
{
    /** 短信验证码有效期（秒） */
    protected const SMS_TTL = 600;

    /** 平台级系统设置 aid */
    protected const PLATFORM_AID = 0;

    /** 注册验证码缓存键前缀（与租户短信登录 sms:{aid}:{mobile} 区分） */
    protected const SMS_KEY_PREFIX = 'sms:reg:';

    /**
     * 注册页公开配置：{reg_open, sms_verify, reg_agreement_url}
     *
     * @return array{reg_open: int, sms_verify: int, reg_agreement_url: string}
     */
    public function config(): array
    {
        return [
            'reg_open'        => (int) TenantConfigService::get(self::PLATFORM_AID, 'sys_system_reg_open', '0'),
            'sms_verify'      => (int) TenantConfigService::get(self::PLATFORM_AID, 'sys_system_sms_verify', '0'),
            'reg_agreement_url' => (string) TenantConfigService::get(self::PLATFORM_AID, 'sys_system_reg_agreement_url', ''),
        ];
    }

    /**
     * 发送注册短信验证码（MOCK 模式固定 123456 存 Redis）
     *
     * @return array{success: true}
     */
    public function sendSms(string $mobile): array
    {
        if ('1' !== TenantConfigService::get(self::PLATFORM_AID, 'sys_system_reg_open', '0')) {
            throw new BusinessException('暂未开放注册');
        }

        if ('1' !== TenantConfigService::get(self::PLATFORM_AID, 'sys_system_sms_verify', '0')) {
            throw new BusinessException('注册无需短信验证');
        }

        if (1 !== preg_match('/^1\d{10}$/', $mobile)) {
            throw new BusinessException('手机号格式不正确');
        }

        $mock = '1' === (string) env('SMS.MOCK', '0');

        if (!$mock) {
            // 本期无真实短信通道，非 mock 模式直接拒绝（避免验证码可被绕过）
            throw new BusinessException('短信通道未开通');
        }

        $smsCode = $mock ? '123456' : (string) random_int(100000, 999999);

        $this->redis()->setex(self::SMS_KEY_PREFIX . $mobile, self::SMS_TTL, $smsCode);

        return ['success' => true];
    }

    /**
     * 提交注册：校验开关/手机号唯一/密码/验证码/协议，创建平台级会员
     *
     * @return array{mid: int, phone: string}
     */
    public function submit(string $mobile, string $password, string $code, bool $agreement): array
    {
        if ('1' !== TenantConfigService::get(self::PLATFORM_AID, 'sys_system_reg_open', '0')) {
            throw new BusinessException('暂未开放注册');
        }

        if (1 !== preg_match('/^1\d{10}$/', $mobile)) {
            throw new BusinessException('手机号格式不正确');
        }

        if (strlen($password) < 6 || strlen($password) > 32) {
            throw new BusinessException('密码长度需为 6-32 位');
        }

        $smsVerify = '1' === TenantConfigService::get(self::PLATFORM_AID, 'sys_system_sms_verify', '0');

        if ($smsVerify) {
            if ('' === $code) {
                throw new BusinessException('请输入短信验证码');
            }

            $key    = self::SMS_KEY_PREFIX . $mobile;
            $expect = (string) ($this->redis()->get($key) ?: '');

            if ('' === $expect || !hash_equals($expect, $code)) {
                throw new BusinessException('验证码错误或已过期');
            }
        }

        if (!$agreement) {
            throw new BusinessException('请先阅读并同意注册协议');
        }

        $exists = Db::name('member')
            ->where('aid', self::PLATFORM_AID)
            ->where('tel', $mobile)
            ->find();

        if (!empty($exists)) {
            throw new BusinessException('该手机号已注册');
        }

        // 全部校验通过后才失效验证码，避免协议未勾选等场景误删验证码（防重放）
        if ($smsVerify) {
            $this->redis()->del(self::SMS_KEY_PREFIX . $mobile);
        }

        // 会员有效期：reg_valid_days>0 写入 levelendtime，0 表示永久
        $validDays = (int) TenantConfigService::get(self::PLATFORM_AID, 'sys_system_reg_valid_days', '0');
        $levelEnd  = $validDays > 0 ? time() + $validDays * 86400 : 0;

        $memberId = (int) Db::name('member')->insertGetId([
            'aid'          => self::PLATFORM_AID,
            'bid'          => 0,
            'nickname'     => '手机用户' . substr($mobile, -4),
            'headimg'      => '',
            'tel'          => $mobile,
            'pwd'          => password_hash($password, PASSWORD_DEFAULT),
            'score'        => 0,
            'money'        => 0,
            'levelstarttime' => time(),
            'levelendtime' => $levelEnd,
            'createtime'   => time(),
        ]);

        // 注册赠送（批次4-A 任务186）：平台级会员同样走配置钩子（无配置时空操作），异常不阻断注册
        try {
            app(GiveService::class)->grantOnRegister(self::PLATFORM_AID, $memberId);
        } catch (\Throwable $e) {
            \think\facade\Log::error('register give fail member#' . $memberId . ': ' . $e->getMessage());
        }

        return ['mid' => $memberId, 'phone' => $mobile];
    }

    /**
     * 原生 Redis 连接（与 MemberService 同口径，sms:* 键值为原始字符串）
     */
    protected function redis(): \Redis
    {
        static $client = null;

        if (null === $client) {
            $client = new \Redis();
            $client->connect((string) env('REDIS.HOST', '127.0.0.1'), (int) env('REDIS.PORT', 6379), 3.0);

            $password = (string) env('REDIS.PASSWORD', '');
            if ('' !== $password) {
                $client->auth($password);
            }
            $client->select((int) env('REDIS.SELECT', 0));
        }

        return $client;
    }
}
