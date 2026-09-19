<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;
use think\facade\Log;

/**
 * 微信开放平台第三方平台核心服务
 *
 * - token 缓存走 Redis（键前缀 open:），open_component 扩展列做 DB 兜底
 * - mock 模式（.env OPEN_PLATFORM_MOCK=1 或 open_component.mock_mode=1）：
 *   所有微信 API 调用返回预置成功报文，无资质可模拟闭环
 * - WxApiClient 可注入（构造参数），便于替换/单测
 */
class OpenPlatformService
{
    /** Redis 键（原生 Redis 存取，键/值为原始字符串，无额外前缀） */
    public const KEY_TICKET   = 'open:component_verify_ticket';
    public const KEY_CATOKEN  = 'open:component_access_token';
    public const KEY_PREAUTH  = 'open:pre_auth_code';

    /** Mock 预置授权方 */
    public const MOCK_APPID = 'wxMOCK0000000';
    public const MOCK_NICK  = 'Mock小程序';

    /** 全网发布测试号/关键词常量见 OpenMsgService */

    public function __construct(protected WxApiClient $api)
    {
    }

    /** 暴露 API 客户端（供消息服务/测试注入使用） */
    public function api(): WxApiClient
    {
        return $this->api;
    }

    /**
     * 原生 Redis 连接（保证 open:* 键值为原始字符串，便于跨进程读取）
     */
    public function redis(): \Redis
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

    /* ==================== 组件配置 ==================== */

    /**
     * 组件配置（单行，无记录返回默认结构）
     *
     * @return array<string, mixed>
     */
    public function config(): array
    {
        $row = Db::name('open_component')->order('id', 'asc')->find();

        return $row ?: [
            'id' => 0, 'status' => 0, 'component_appid' => '', 'component_appsecret' => '',
            'token' => '', 'encoding_aes_key' => '', 'ip_whitelist' => '', 'auth_domain' => '', 'kfappid' => '', 'mock_mode' => 0, 'autofb' => 0,
        ];
    }

    /**
     * 保存组件配置（单行 upsert；appsecret 空串=不修改）
     *
     * @param array<string, mixed> $data
     */
    public function saveConfig(array $data): void
    {
        $row  = $this->config();
        $save = [
            'status'           => !empty($data['status']) ? 1 : 0,
            'component_appid'  => trim((string) ($data['component_appid'] ?? '')),
            'token'            => trim((string) ($data['token'] ?? '')),
            'encoding_aes_key' => trim((string) ($data['encoding_aes_key'] ?? '')),
            'ip_whitelist'     => trim((string) ($data['ip_whitelist'] ?? '')),
            'auth_domain'      => trim((string) ($data['auth_domain'] ?? '')),
            'kfappid'          => trim((string) ($data['kfappid'] ?? '')),
            'mock_mode'        => !empty($data['mock_mode']) ? 1 : 0,
            'autofb'           => !empty($data['autofb']) ? 1 : 0,
            'update_time'      => time(),
        ];

        // 兼容 component_appsecret / appsecret 两种入参，空串=不修改
        $secret = (string) ($data['component_appsecret'] ?? $data['appsecret'] ?? '');
        if ('' !== $secret) {
            $save['component_appsecret'] = $secret;
        }

        if (!empty($row['id'])) {
            Db::name('open_component')->where('id', $row['id'])->update($save);
        } else {
            $save['component_appsecret'] = $secret;
            $save['create_time']         = time();
            Db::name('open_component')->insert($save);
        }
    }

    /** mock 模式：.env 优先，其次 DB 配置 */
    public function isMock(): bool
    {
        if ((int) env('OPEN_PLATFORM.MOCK', 0) === 1) {
            return true;
        }

        return (int) ($this->config()['mock_mode'] ?? 0) === 1;
    }

    /** 配置完整性检查（开关 + 关键字段），不满足抛业务异常 */
    public function requireEnabled(): array
    {
        $config = $this->config();

        if ((int) $config['status'] !== 1) {
            throw new BusinessException('第三方平台组件未开启');
        }
        if ('' === (string) $config['component_appid'] || '' === (string) $config['token'] || '' === (string) $config['encoding_aes_key']) {
            throw new BusinessException('第三方平台组件配置不完整');
        }

        return $config;
    }

    /** 加解密器 */
    public function crypt(?array $config = null): WxBizCrypt
    {
        $config ??= $this->config();

        return new WxBizCrypt((string) $config['token'], (string) $config['encoding_aes_key'], (string) $config['component_appid']);
    }

    /* ==================== verify_ticket / component_access_token ==================== */

    /** 接收 component_verify_ticket：Redis + DB 流水 */
    public function receiveTicket(string $ticket, string $ip): void
    {
        $this->redis()->set(self::KEY_TICKET, $ticket);
        Db::name('open_ticket_log')->insert(['ticket' => $ticket, 'ip' => $ip, 'create_time' => time()]);
    }

    /** component_access_token（Redis 缓存 7000s，DB 兜底，mock 直出） */
    public function componentAccessToken(): string
    {
        $token = (string) ($this->redis()->get(self::KEY_CATOKEN) ?: '');
        if ('' !== $token) {
            return $token;
        }

        $config = $this->requireEnabled();

        if ($this->isMock()) {
            $token = 'MOCK_COMPONENT_TOKEN_' . md5((string) $config['component_appid']);
        } else {
            $ticket = (string) ($this->redis()->get(self::KEY_TICKET) ?: '');
            if ('' === $ticket) {
                throw new BusinessException('未收到 component_verify_ticket，无法获取 component_access_token');
            }
            $rs = $this->api->apiComponentToken((string) $config['component_appid'], (string) $config['component_appsecret'], $ticket);
            if (empty($rs['component_access_token'])) {
                throw new BusinessException('获取 component_access_token 失败：' . ($rs['errmsg'] ?? 'unknown'));
            }
            $token = (string) $rs['component_access_token'];
        }

        $this->redis()->setex(self::KEY_CATOKEN, 7000, $token);
        // DB 兜底
        if (!empty($config['id'])) {
            Db::name('open_component')->where('id', $config['id'])
                ->update(['component_access_token' => $token, 'token_expire' => time() + 7000, 'update_time' => time()]);
        }

        return $token;
    }

    /* ==================== 预授权码 / 授权页 ==================== */

    /**
     * 预授权码（附带租户映射），mock 直出
     *
     * @return array{pre_auth_code: string, expires_in: int}
     */
    public function createPreAuthCode(?int $tenantId = null): array
    {
        $config = $this->requireEnabled();

        if ($this->isMock()) {
            $code   = 'MOCK_PRE_AUTH_CODE_' . WxBizCrypt::randomStr(16);
            $expire = 1800;
        } else {
            $rs = $this->api->apiCreatePreAuthCode($this->componentAccessToken(), (string) $config['component_appid']);
            if (empty($rs['pre_auth_code'])) {
                throw new BusinessException('获取预授权码失败：' . ($rs['errmsg'] ?? 'unknown'));
            }
            $code   = (string) $rs['pre_auth_code'];
            $expire = (int) ($rs['expires_in'] ?? 1800);
        }

        $this->redis()->setex(self::KEY_PREAUTH, $expire, $code);
        if (null !== $tenantId && $tenantId > 0) {
            $this->redis()->setex('open:preauth_tenant:' . $code, 86400, (string) $tenantId);
        }

        return ['pre_auth_code' => $code, 'expires_in' => $expire];
    }

    /**
     * 授权页 URL（PC 扫码页；mock 模式返回本地模拟授权页）
     *
     * @param int      $authType 1公众号 2小程序 3都有
     * @param int|null $tenantId 预绑定租户（回调时落 aid）
     * @param string   $state    透传参数（租户端传 aid，回调原样带回）
     */
    public function buildAuthUrl(int $authType, ?int $tenantId = null, string $state = ''): string
    {
        $config = $this->requireEnabled();
        $pre    = $this->createPreAuthCode($tenantId);

        $host    = request()->domain();
        $domain  = (string) $config['auth_domain'];
        $base    = '' !== $domain ? (str_contains($domain, '://') ? rtrim($domain, '/') : 'https://' . $domain) : $host;
        $tenantQ = null !== $tenantId && $tenantId > 0 ? '&tenant_id=' . $tenantId : '';
        $stateQ  = '' !== $state ? '&state=' . urlencode($state) : '';

        if ($this->isMock()) {
            return $host . '/open/mock/authpage?pre_auth_code=' . $pre['pre_auth_code']
                . '&auth_type=' . $authType . $tenantQ . $stateQ;
        }

        return 'https://mp.weixin.qq.com/cgi-bin/componentloginpage'
            . '?component_appid=' . urlencode((string) $config['component_appid'])
            . '&pre_auth_code=' . urlencode($pre['pre_auth_code'])
            . '&auth_type=' . $authType
            . '&redirect_uri=' . urlencode($base . '/open/auth/callback')
            . $tenantQ . $stateQ;
    }

    /* ==================== 授权换取 / 授权方落库 ==================== */

    /**
     * api_query_auth（mock 返回预置授权信息）
     *
     * @return array<string, mixed> authorization_info
     */
    public function queryAuth(string $authCode): array
    {
        if ('' === trim($authCode)) {
            throw new BusinessException('auth_code 不能为空');
        }

        if ($this->isMock()) {
            return [
                'authorizer_appid'         => self::MOCK_APPID,
                'authorizer_access_token'  => 'MOCK_AUTHORIZER_TOKEN_' . md5($authCode),
                'expires_in'               => 7200,
                'authorizer_refresh_token' => 'MOCK_REFRESH_TOKEN_' . md5($authCode),
                'func_info'                => [['funcscope_category' => ['id' => 17, 'name' => '小程序开发管理与权限', 'status' => 1]]],
            ];
        }

        $config = $this->config();
        $rs     = $this->api->apiQueryAuth($this->componentAccessToken(), (string) $config['component_appid'], $authCode);
        if (empty($rs['authorization_info'])) {
            throw new BusinessException('api_query_auth 失败：' . ($rs['errmsg'] ?? 'unknown'));
        }

        return (array) $rs['authorization_info'];
    }

    /**
     * api_get_authorizer_info（mock 返回预置资料）
     *
     * @return array<string, mixed> authorizer_info
     */
    public function getAuthorizerInfo(string $authorizerAppid): array
    {
        if ($this->isMock()) {
            return [
                'nick_name'        => self::MOCK_NICK,
                'head_img'         => 'https://mock.dxcx.local/head.png',
                'user_name'        => 'gh_mock0000000000',
                'qrcode_url'       => 'https://mock.dxcx.local/qrcode.png',
                'principal_name'   => 'Mock科技有限公司',
                'service_type_info' => ['id' => 2],
                'verify_type_info'  => ['id' => 0],
            ];
        }

        $config = $this->config();
        $rs     = $this->api->apiGetAuthorizerInfo($this->componentAccessToken(), (string) $config['component_appid'], $authorizerAppid);
        if (empty($rs['authorizer_info'])) {
            throw new BusinessException('api_get_authorizer_info 失败：' . ($rs['errmsg'] ?? 'unknown'));
        }

        return (array) $rs['authorizer_info'];
    }

    /**
     * 授权码换 token + 资料，upsert open_authorizer
     *
     * @return array<string, mixed> 落库后的记录
     */
    public function saveAuthorizerByAuthCode(string $authCode, ?int $tenantId = null): array
    {
        $authInfo = $this->queryAuth($authCode);
        $appid    = (string) ($authInfo['authorizer_appid'] ?? '');
        if ('' === $appid) {
            throw new BusinessException('授权信息缺少 authorizer_appid');
        }
        $info = $this->getAuthorizerInfo($appid);

        return $this->saveAuthorizerByAuthInfo($authInfo, $info, $tenantId, $authCode);
    }

    /**
     * 用已换取的授权信息 + 资料 upsert open_authorizer
     * （供外部在单事务内复用，避免 auth_code 二次调用）
     *
     * @param array<string, mixed> $authInfo api_query_auth 的 authorization_info
     * @param array<string, mixed> $info     api_get_authorizer_info 的 authorizer_info
     * @return array<string, mixed> 落库后的记录
     */
    public function saveAuthorizerByAuthInfo(array $authInfo, array $info, ?int $tenantId = null, string $authCode = ''): array
    {
        $appid = (string) ($authInfo['authorizer_appid'] ?? '');
        if ('' === $appid) {
            throw new BusinessException('授权信息缺少 authorizer_appid');
        }

        $now      = time();
        $existing = Db::name('open_authorizer')->where('authorizer_appid', $appid)->find();

        $save = [
            'nick_name'         => (string) ($info['nick_name'] ?? ''),
            'head_img'          => (string) ($info['head_img'] ?? ''),
            'user_name'         => (string) ($info['user_name'] ?? ''),
            'qrcode_url'        => (string) ($info['qrcode_url'] ?? ''),
            'principal_name'    => (string) ($info['principal_name'] ?? ''),
            'service_type_id'   => (int) ($info['service_type_info']['id'] ?? 0),
            'verify_type_id'    => (int) ($info['verify_type_info']['id'] ?? 0),
            'func_info'         => json_encode($authInfo['func_info'] ?? [], JSON_UNESCAPED_UNICODE),
            'refresh_token'     => (string) ($authInfo['authorizer_refresh_token'] ?? ''),
            'auth_status'       => 1,
            'auth_time'         => $now,
            'refresh_alert'     => '',
            'update_time'       => $now,
        ];
        // refresh_token 备份：旧值留存
        if ($existing && '' !== (string) $existing['refresh_token']) {
            $save['refresh_token_backup'] = (string) $existing['refresh_token'];
        }
        // 租户绑定：显式传入 > 预授权码映射 > 已有绑定
        if (null !== $tenantId && $tenantId > 0) {
            $save['aid'] = $tenantId;
        } elseif (!$existing || null === $existing['aid']) {
            $mapped = '' !== $authCode ? (int) ($this->redis()->get('open:preauth_tenant:' . $authCode) ?: 0) : 0;
            if ($mapped > 0) {
                $save['aid'] = $mapped;
            }
        }

        if ($existing) {
            Db::name('open_authorizer')->where('id', $existing['id'])->update($save);
            $save['id'] = $existing['id'];
        } else {
            $save['authorizer_appid'] = $appid;
            $save['create_time']      = $now;
            $save['aid']              = $save['aid'] ?? null;
            $save['id']               = Db::name('open_authorizer')->insertGetId($save);
        }

        // access_token 直接入缓存
        if (!empty($authInfo['authorizer_access_token'])) {
            $ttl = max(60, (int) ($authInfo['expires_in'] ?? 7200) - 200);
            $this->redis()->setex('open:authorizer_access_token:' . $appid, $ttl, (string) $authInfo['authorizer_access_token']);
        }

        return Db::name('open_authorizer')->where('id', $save['id'])->find();
    }

    /* ==================== 授权事件流转 ==================== */

    /** authorized / updateauthorized */
    public function handleAuthorized(array $event): void
    {
        $appid = (string) ($event['AuthorizerAppid'] ?? '');
        if ('' === $appid) {
            return;
        }

        $authCode = (string) ($event['AuthorizationCode'] ?? '');
        if ('' !== $authCode) {
            try {
                $this->saveAuthorizerByAuthCode($authCode);
                return;
            } catch (\Throwable $e) {
                Log::error('open.authorized query_auth 失败: ' . $e->getMessage());
            }
        }

        // 无授权码时仅激活/恢复状态
        Db::name('open_authorizer')->where('authorizer_appid', $appid)->update([
            'auth_status' => 1, 'auth_time' => time(), 'unauth_time' => 0, 'update_time' => time(),
        ]);
    }

    /** unauthorized（软标记，不物理删） */
    public function handleUnauthorized(array $event): void
    {
        $appid = (string) ($event['AuthorizerAppid'] ?? '');
        if ('' === $appid) {
            return;
        }

        Db::name('open_authorizer')->where('authorizer_appid', $appid)->update([
            'auth_status' => 0, 'unauth_time' => time(), 'update_time' => time(),
        ]);
        $this->redis()->del('open:authorizer_access_token:' . $appid);
        // 联动租户绑定表：取消授权即作废对应租户的手动绑定记录
        $this->unbindTenantByAppid($appid);
    }

    /**
     * 取消授权联动：软清除租户侧绑定（admin_setapp_wx|mp 保留行、清绑定字段）
     * 对照参考系统 ApiWechat/auth unauthorized 分支（物理删改软清，配置行保留）
     */
    public function unbindTenantByAppid(string $appid): void
    {
        $clear = [
            'appid'         => null,
            'authtype'      => 0,
            'refresh_token' => null,
            'nickname'      => '',
            'headimg'       => '',
            'qrcode'        => '',
        ];

        Db::name('admin_setapp_wx')->where('appid', $appid)->where('authtype', 1)->update($clear);
        Db::name('admin_setapp_mp')->where('appid', $appid)->where('authtype', 1)->update($clear);
        Db::name('access_token')->where('appid', $appid)->delete();
        $this->redis()->del('binding:access_token:' . $appid);
    }

    /* ==================== authorizer_access_token ==================== */

    /**
     * 授权方 access_token（Redis 缓存；失效时用 refresh_token 刷新并回写）
     */
    public function authorizerAccessToken(string $appid, bool $forceRefresh = false): string
    {
        $key = 'open:authorizer_access_token:' . $appid;

        if (!$forceRefresh) {
            $token = (string) ($this->redis()->get($key) ?: '');
            if ('' !== $token) {
                return $token;
            }
        }

        $row = Db::name('open_authorizer')->where('authorizer_appid', $appid)->find();
        if (empty($row)) {
            // mock 模式容错：无落库记录也签发模拟 token（全网发布测试号等场景）
            if ($this->isMock()) {
                $token = 'MOCK_AUTHORIZER_TOKEN_' . md5($appid);
                $this->redis()->setex($key, 7000, $token);

                return $token;
            }
            throw new BusinessException('授权方不存在：' . $appid);
        }

        if ($this->isMock()) {
            $token = 'MOCK_AUTHORIZER_TOKEN_' . md5($appid . (string) $row['refresh_token']);
        } else {
            $config = $this->config();
            $rs = $this->api->apiAuthorizerToken(
                $this->componentAccessToken(),
                (string) $config['component_appid'],
                $appid,
                (string) $row['refresh_token']
            );

            if (empty($rs['authorizer_access_token'])) {
                $msg = 'refresh_token 刷新失败[' . ($rs['errcode'] ?? '?') . '] ' . ($rs['errmsg'] ?? '');
                Log::error('open.authorizer_token appid=' . $appid . ' ' . $msg);
                Db::name('open_authorizer')->where('id', $row['id'])->update([
                    'refresh_alert' => mb_substr($msg, 0, 200), 'update_time' => time(),
                ]);
                throw new BusinessException($msg);
            }

            $token = (string) $rs['authorizer_access_token'];
            // 回写 refresh_token（旧值转备份）
            $newRefresh = (string) ($rs['authorizer_refresh_token'] ?? '');
            if ('' !== $newRefresh && $newRefresh !== (string) $row['refresh_token']) {
                Db::name('open_authorizer')->where('id', $row['id'])->update([
                    'refresh_token_backup' => (string) $row['refresh_token'],
                    'refresh_token'        => $newRefresh,
                    'refresh_alert'        => '',
                    'update_time'          => time(),
                ]);
            }
            $ttl = max(60, (int) ($rs['expires_in'] ?? 7200) - 200);
        }

        $this->redis()->setex($key, $ttl ?? 7000, $token);

        return $token;
    }

    /**
     * 使用授权方 token 调用 API，42001/40001 自动刷新重试一次
     *
     * @param callable(string): array $fn 入参为 access_token
     */
    public function callWithAuthorizerToken(string $appid, callable $fn): array
    {
        $rs = $fn($this->authorizerAccessToken($appid));
        $errcode = (int) ($rs['errcode'] ?? 0);

        if (in_array($errcode, [40001, 42001, 40014], true)) {
            Log::warning('open.token 失效重试 appid=' . $appid . ' errcode=' . $errcode);
            $rs = $fn($this->authorizerAccessToken($appid, true));
        }

        return $rs;
    }
}
