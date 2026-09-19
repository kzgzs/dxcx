<?php

declare(strict_types=1);

namespace app\common\service;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * JWT 签发/校验服务
 *
 * 统一算法 HS256，密钥取 .env 的 JWT_SECRET
 */
class JwtService
{
    /** 默认有效期：24 小时 */
    public const TTL = 86400;

    /**
     * 签发 token
     *
     * @param array $payload 业务载荷（uid/aid/un/isadmin/role 等）
     * @param int   $ttl     有效期（秒）
     */
    public static function sign(array $payload, int $ttl = self::TTL): string
    {
        $now = time();

        $payload = array_merge($payload, [
            'iat' => $now,
            'exp' => $now + $ttl,
        ]);

        return JWT::encode($payload, self::secret(), 'HS256');
    }

    /**
     * 校验并解码 token，失败返回 null
     *
     * @return array|null 成功返回载荷数组
     */
    public static function decode(string $token): ?array
    {
        try {
            return (array) JWT::decode($token, new Key(self::secret(), 'HS256'));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 签名密钥
     */
    protected static function secret(): string
    {
        return (string) env('JWT_SECRET', 'dxcx-jwt-secret');
    }
}
