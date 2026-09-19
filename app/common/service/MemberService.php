<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;

/**
 * 客户端会员（api 应用）会话服务
 *
 * 约定：
 * - member 表按 aid 隔离，手机号登录取 aid>0 的首条匹配记录
 * - 密码校验复用 TenantService::verifyPassword（bcrypt / 旧 md5 双格式兼容）
 * - JWT 载荷：role='member'，mid=会员ID，aid=所属租户
 * - 微信静默登录：mock 模式（WX.MOCK=1 或无 appid）code 非空即成功，openid='wxmock_'+md5(code)；
 *   无该 openid 会员自动注册（wxopenid 列，nickname='微信用户'+openid后4位）
 * - 短信验证码：Redis 存 600s（key sms:{aid}:{mobile}），SMS.MOCK=1 固定 123456；验证码登录无会员自动注册
 */
class MemberService
{
    /** 短信验证码有效期（秒） */
    protected const SMS_TTL = 600;

    /**
     * 微信 code 静默登录（mock：code 非空即成功；正常模式走 jscode2session）
     *
     * @return array{token: string, member: array<string, mixed>}
     */
    public function wxLogin(int $aid, string $code): array
    {
        if ($aid <= 0) {
            throw new BusinessException('非法的租户标识', 403, 403);
        }

        $appid  = trim((string) env('WX.APPID', ''));
        $mock   = '1' === (string) env('WX.MOCK', '0') || '' === $appid;

        if ($mock) {
            if ('' === $code) {
                throw new BusinessException('缺少登录凭证 code');
            }
            $openid = 'wxmock_' . md5($code);
        } else {
            $openid = $this->jscode2session($appid, trim((string) env('WX.SECRET', '')), $code);
        }

        $member = ScopeService::query('member', $aid)->where('wxopenid', $openid)->find();

        if (empty($member)) {
            // 自动注册：nickname='微信用户'+openid后4位
            $memberId = (int) Db::name('member')->insertGetId([
                'aid'      => $aid,
                'bid'      => 0,
                'wxopenid' => $openid,
                'nickname' => '微信用户' . substr($openid, -4),
                'headimg'  => '',
                'tel'      => '',
                'pwd'      => '',
                'score'    => 0,
                'money'    => 0,
                'createtime' => time(),
            ]);

            $member = ScopeService::query('member', $aid)->where('id', $memberId)->find();

            // 注册赠送（批次4-A 任务186）：余额/积分/券按配置发放，异常不阻断登录
            try {
                app(GiveService::class)->grantOnRegister($aid, $memberId);
            } catch (\Throwable $e) {
                \think\facade\Log::error('register give fail member#' . $memberId . ': ' . $e->getMessage());
            }
        }

        return $this->issueToken((array) $member);
    }

    /**
     * 发送短信验证码（Redis 存 600s；SMS.MOCK=1 固定 123456 且不真实下发）
     *
     * @return array{success: true}
     */
    public function sendSms(int $aid, string $mobile): array
    {
        if ($aid <= 0) {
            throw new BusinessException('非法的租户标识', 403, 403);
        }

        if (1 !== preg_match('/^1\d{10}$/', $mobile)) {
            throw new BusinessException('手机号格式不正确');
        }

        $mock = '1' === (string) env('SMS.MOCK', '0');
        $smsCode = $mock ? '123456' : (string) random_int(100000, 999999);

        if (!$mock) {
            // 本期无真实短信通道，非 mock 模式直接拒绝（避免验证码可被绕过）
            throw new BusinessException('短信通道未开通');
        }

        $this->redis()->setex('sms:' . $aid . ':' . $mobile, self::SMS_TTL, $smsCode);

        return ['success' => true];
    }

    /**
     * 短信验证码登录（校验后登录，无会员自动注册，password 随机）
     *
     * @return array{token: string, member: array<string, mixed>}
     */
    public function smsLogin(int $aid, string $mobile, string $code): array
    {
        if ($aid <= 0) {
            throw new BusinessException('非法的租户标识', 403, 403);
        }

        if (1 !== preg_match('/^1\d{10}$/', $mobile)) {
            throw new BusinessException('手机号格式不正确');
        }

        if ('' === $code) {
            throw new BusinessException('请输入验证码');
        }

        $key    = 'sms:' . $aid . ':' . $mobile;
        $expect = (string) ($this->redis()->get($key) ?: '');

        if ('' === $expect || !hash_equals($expect, $code)) {
            throw new BusinessException('验证码错误或已过期', 401, 401);
        }

        // 校验成功即失效，防重放
        $this->redis()->del($key);

        $member = ScopeService::query('member', $aid)->where('tel', $mobile)->find();

        if (empty($member)) {
            $memberId = (int) Db::name('member')->insertGetId([
                'aid'      => $aid,
                'bid'      => 0,
                'nickname' => '手机用户' . substr($mobile, -4),
                'headimg'  => '',
                'tel'      => $mobile,
                'pwd'      => password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT),
                'score'    => 0,
                'money'    => 0,
                'createtime' => time(),
            ]);

            $member = ScopeService::query('member', $aid)->where('id', $memberId)->find();

            // 注册赠送（批次4-A 任务186）：余额/积分/券按配置发放，异常不阻断登录
            try {
                app(GiveService::class)->grantOnRegister($aid, $memberId);
            } catch (\Throwable $e) {
                \think\facade\Log::error('register give fail member#' . $memberId . ': ' . $e->getMessage());
            }
        }

        return $this->issueToken((array) $member);
    }

    /**
     * 完善会员资料（昵称/头像/姓名/性别/生日；填写齐全触发完善资料送分，幂等）
     *
     * @param array<string, mixed> $data
     * @return array{wanshan_score: int}
     */
    public function editProfile(int $aid, int $mid, array $data): array
    {
        $member = ScopeService::query('member', $aid)->where('id', $mid)->find();

        if (empty($member)) {
            throw new BusinessException('会员不存在', 404, 404);
        }

        $update = [];

        foreach (['nickname', 'headimg', 'realname', 'sex', 'birthday'] as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = trim((string) $data[$field]);
            }
        }

        if (!empty($update)) {
            Db::name('member')->where('id', $mid)->where('aid', $aid)->update($update);
            $member = array_merge($member, $update);
        }

        // 完善资料送分：realname/tel/sex/birthday 全部填写后触发（is_wanshan_score 条件更新只送一次）
        $wanshanScore = 0;

        if ('' !== trim((string) ($member['realname'] ?? ''))
            && '' !== trim((string) ($member['tel'] ?? ''))
            && '' !== trim((string) ($member['sex'] ?? ''))
            && '' !== trim((string) ($member['birthday'] ?? ''))) {
            $wanshanScore = app(GiveService::class)->grantWanshan($aid, $mid);
        }

        return ['wanshan_score' => $wanshanScore];
    }

    /**
     * 微信 jscode2session（正常模式；返回 openid）
     */
    protected function jscode2session(string $appid, string $secret, string $code): string
    {
        if ('' === $code) {
            throw new BusinessException('缺少登录凭证 code');
        }

        $url = 'https://api.weixin.qq.com/sns/jscode2session?appid=' . rawurlencode($appid)
            . '&secret=' . rawurlencode($secret)
            . '&js_code=' . rawurlencode($code)
            . '&grant_type=authorization_code';

        $raw = @file_get_contents($url);
        $data = is_string($raw) ? (json_decode($raw, true) ?: []) : [];

        if (empty($data['openid'])) {
            throw new BusinessException('微信登录失败：' . (string) ($data['errmsg'] ?? '网络异常'), 401, 401);
        }

        return (string) $data['openid'];
    }

    /**
     * 签发 member token + 统一 member 返回结构 {token, member:{id,nickname,headimg,...}}
     *
     * @param array<string, mixed> $member
     * @return array{token: string, member: array<string, mixed>}
     */
    protected function issueToken(array $member): array
    {
        $token = JwtService::sign([
            'uid'  => (int) $member['id'],
            'mid'  => (int) $member['id'],
            'aid'  => (int) $member['aid'],
            'role' => 'member',
        ]);

        return [
            'token'  => $token,
            'member' => [
                'id'       => (int) $member['id'],
                'nickname' => (string) ($member['nickname'] ?? ''),
                'headimg'  => (string) ($member['headimg'] ?? ''),
                'phone'    => (string) ($member['tel'] ?? ''),
                'score'    => (int) ($member['score'] ?? 0),
                'money'    => (float) ($member['money'] ?? 0),
                'aid'      => (int) $member['aid'],
            ],
        ];
    }

    /**
     * 原生 Redis 连接（与 OpenPlatformService 同口径，sms:* 键值为原始字符串）
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

    /**
     * 手机号+密码登录
     *
     * @return array{token: string, userinfo: array{mid: int, phone: string, nickname: string, aid: int}}
     */
    public function login(string $phone, string $password): array
    {
        $member = Db::name('member')
            ->where('tel', $phone)
            ->where('aid', '>', 0)
            ->order('id', 'asc')
            ->find();

        if (empty($member) || !TenantService::verifyPassword($password, (string) $member['pwd'])) {
            throw new BusinessException('手机号或密码错误', 401, 401);
        }

        $token = JwtService::sign([
            'uid'  => (int) $member['id'],
            'mid'  => (int) $member['id'],
            'aid'  => (int) $member['aid'],
            'role' => 'member',
        ]);

        return [
            'token'    => $token,
            'userinfo' => [
                'mid'      => (int) $member['id'],
                'phone'    => (string) ($member['tel'] ?? ''),
                'nickname' => (string) ($member['nickname'] ?? ''),
                'aid'      => (int) $member['aid'],
            ],
        ];
    }

    /**
     * 会员基础信息（租户隔离：强制 aid 条件）
     *
     * @return array{mid: int, phone: string, nickname: string, headimg: string, levelid: int, score: int, money: float, aid: int}
     */
    public function profile(int $mid, int $aid): array
    {
        $member = ScopeService::query('member', $aid)->where('id', $mid)->find();

        if (empty($member)) {
            throw new BusinessException('会员不存在', 404, 404);
        }

        return [
            'mid'      => (int) $member['id'],
            'phone'    => (string) ($member['tel'] ?? ''),
            'nickname' => (string) ($member['nickname'] ?? ''),
            'headimg'  => (string) ($member['headimg'] ?? ''),
            'levelid'  => (int) ($member['levelid'] ?? 0),
            'score'    => (int) ($member['score'] ?? 0),
            'money'    => (float) ($member['money'] ?? 0),
            'aid'      => (int) $member['aid'],
        ];
    }
}
