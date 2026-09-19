<?php

declare(strict_types=1);

namespace app\common\service;

use think\facade\Cache;
use think\facade\Db;

/**
 * 租户级键值配置服务（tenant_config 表，aid+key 唯一）
 *
 * 基线 admin_set 为超宽单行表且积分列语义不合，故采用独立键值表。
 * 读取采用 cache-aside：Redis 命中直接返回，未命中回源 DB 并写缓存；
 * 写入为 write-through：落库同时刷新缓存，保证缓存与库一致。
 * 控制台「清理缓存」页可按 aid+key 批量删除配置缓存。
 */
class TenantConfigService
{
    /**
     * 读取配置（无记录返回默认值；优先读缓存）
     */
    public static function get(int $aid, string $key, string $default = ''): string
    {
        // aid=0 为平台级（超管侧）配置，合法；仅 aid<0 视为无效
        if ($aid < 0) {
            return $default;
        }

        $cached = self::cachedValue($aid, $key);
        if (null !== $cached) {
            return $cached;
        }

        $value = self::dbValue($aid, $key);
        if (null === $value) {
            return $default;
        }

        // 仅缓存真实落库的值（set 时主动刷新，Cache::clear 时整体失效）
        try {
            Cache::set(self::cacheKey($aid, $key), $value);
        } catch (\Throwable $e) {
            // 缓存不可用时降级为直读 DB，不影响业务
        }

        return $value;
    }

    /**
     * 写入配置（aid+key 唯一，存在则更新；同时刷新缓存）
     */
    public static function set(int $aid, string $key, string $value): void
    {
        $exists = Db::name('tenant_config')
            ->where('aid', $aid)
            ->where('key', $key)
            ->find();

        if (empty($exists)) {
            Db::name('tenant_config')->insert([
                'aid'   => $aid,
                'key'   => $key,
                'value' => $value,
            ]);
        } else {
            Db::name('tenant_config')->where('id', (int) $exists['id'])->update(['value' => $value]);
        }

        try {
            Cache::set(self::cacheKey($aid, $key), $value);
        } catch (\Throwable $e) {
            // 缓存不可用时忽略，下次读取自动回源
        }
    }

    /**
     * 读取缓存中的配置值（未命中返回 null，供「测试获取缓存数据」与调试使用）
     */
    public static function cachedValue(int $aid, string $key): ?string
    {
        if ($aid < 0) {
            return null;
        }

        try {
            $cached = Cache::get(self::cacheKey($aid, $key));
        } catch (\Throwable $e) {
            return null;
        }

        return null === $cached ? null : (string) $cached;
    }

    /**
     * 直读 DB 的配置值（无记录返回 null）
     */
    public static function dbValue(int $aid, string $key): ?string
    {
        if ($aid < 0) {
            return null;
        }

        $value = Db::name('tenant_config')
            ->where('aid', $aid)
            ->where('key', $key)
            ->value('value');

        return null === $value ? null : (string) $value;
    }

    /**
     * 删除单条配置缓存
     */
    public static function forget(int $aid, string $key): void
    {
        try {
            Cache::delete(self::cacheKey($aid, $key));
        } catch (\Throwable $e) {
            // 缓存不可用时忽略
        }
    }

    /**
     * 批量删除配置缓存，返回实际删除条数
     * @param array<int, array{0:int,1:string}> $pairs [[aid, key], ...]
     */
    public static function forgetPairs(array $pairs): int
    {
        $count = 0;
        foreach ($pairs as [$aid, $key]) {
            if (null !== self::cachedValue((int) $aid, (string) $key)) {
                ++$count;
            }
            self::forget((int) $aid, (string) $key);
        }

        return $count;
    }

    /**
     * 配置缓存键：tcfg_{aid}_{key}
     */
    public static function cacheKey(int $aid, string $key): string
    {
        return 'tcfg_' . $aid . '_' . $key;
    }
}
