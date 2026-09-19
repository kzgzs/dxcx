<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;

/**
 * 租户绑定端 access_token 惰性刷新
 *
 * - 代授权（authtype=1）：走开放平台 api_authorizer_token 刷新（refresh_token 回写
 *   open_authorizer 由 OpenPlatformService 完成），结果写 binding:* Redis + access_token 表
 * - 手动配置（authtype=0）：走 /cgi-bin/token，结果写表
 * - 缓存：Redis 主存（binding:access_token:{appid}，7000s），access_token 表兜底
 */
class BindingTokenService
{
    /** 提前量：剩余有效期低于该值视为过期 */
    private const SAFE_GAP = 200;

    public function __construct(
        protected OpenPlatformService $open,
        protected WxApiClient $api,
    ) {
    }

    /**
     * 取租户绑定端的 access_token（惰性刷新）
     *
     * @param string $platform wx|mp
     */
    public function accessToken(string $platform, int $aid): string
    {
        if (!in_array($platform, ['wx', 'mp'], true)) {
            throw new BusinessException('仅微信端提供 access_token 托管');
        }

        $def = BindingService::PLATFORMS[$platform];
        $row = ScopeService::query($def['table'], $aid)->find();

        $appid = (string) ($row['appid'] ?? '');
        if ('' === $appid) {
            throw new BusinessException('请先完成微信端绑定');
        }

        if (1 === (int) ($row['authtype'] ?? 0)) {
            return $this->authorizedToken($appid);
        }

        return $this->manualToken($appid, (string) ($row['appsecret'] ?? ''));
    }

    /**
     * 代授权 token：OpenPlatformService 负责 refresh_token 刷新与回写
     */
    protected function authorizedToken(string $appid): string
    {
        $token = $this->open->authorizerAccessToken($appid);
        $this->cache($appid, $token, time() + 7000);

        return $token;
    }

    /**
     * 手动配置 token：Redis → access_token 表 → /cgi-bin/token
     */
    protected function manualToken(string $appid, string $secret): string
    {
        // 1. Redis 主存
        $token = (string) ($this->open->redis()->get(BindingService::KEY_TOKEN . $appid) ?: '');
        if ('' !== $token) {
            return $token;
        }

        // 2. 表兜底
        $row = Db::name('access_token')->where('appid', $appid)->find();
        if (!empty($row) && (int) $row['expires_time'] > time() + self::SAFE_GAP && '' !== (string) $row['access_token']) {
            $ttl = (int) $row['expires_time'] - time();
            $this->open->redis()->setex(BindingService::KEY_TOKEN . $appid, min(7000, $ttl), (string) $row['access_token']);

            return (string) $row['access_token'];
        }

        // 3. 惰性刷新
        if ($this->open->isMock()) {
            $token  = 'MOCK_BINDING_TOKEN_' . md5($appid);
            $expire = time() + 7000;
        } else {
            if ('' === $secret) {
                throw new BusinessException('手动绑定缺少 AppSecret，无法刷新 access_token');
            }
            $rs = $this->api->apiCgiToken($appid, $secret);
            if (empty($rs['access_token'])) {
                throw new BusinessException('access_token 刷新失败：' . ($rs['errmsg'] ?? 'unknown'));
            }
            $token  = (string) $rs['access_token'];
            $expire = time() + min(7000, (int) ($rs['expires_in'] ?? 7200));
        }

        $this->cache($appid, $token, $expire);

        return $token;
    }

    /**
     * 双写：Redis（主存）+ access_token 表（兜底，appid 唯一键 upsert）
     */
    protected function cache(string $appid, string $token, int $expire): void
    {
        $ttl = max(60, $expire - time());
        $this->open->redis()->setex(BindingService::KEY_TOKEN . $appid, min(7000, $ttl), $token);

        $row = Db::name('access_token')->where('appid', $appid)->find();
        if (empty($row)) {
            Db::name('access_token')->insert(['appid' => $appid, 'access_token' => $token, 'expires_time' => $expire]);
        } else {
            Db::name('access_token')->where('id', $row['id'])->update(['access_token' => $token, 'expires_time' => $expire]);
        }
    }
}
