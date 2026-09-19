<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use think\facade\Cache;

/**
 * 租户后台 - 系统工具（清理缓存等）
 *
 * 实现对齐超管控制台 app\console\controller\System::clearCache：
 * 清默认缓存驱动（redis）+ runtime 各应用 cache 目录，返回清理明细。
 */
class System extends BaseController
{
    /**
     * 一键清理缓存
     * POST /admin/system/clearCache
     */
    public function clearCache()
    {
        $results = [];

        // 1) 缓存驱动（默认 redis）
        try {
            Cache::clear();
            $results[] = '缓存驱动清理完成';
        } catch (\Throwable $e) {
            $results[] = '缓存驱动清理失败：' . $e->getMessage();
        }

        // 2) runtime 各应用下的 cache 文件目录
        $fileCount = 0;
        $appDirs   = glob($this->app->getRuntimePath() . '*') ?: [];
        foreach ($appDirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $cacheDir = $dir . DIRECTORY_SEPARATOR . 'cache';
            if (is_dir($cacheDir)) {
                $fileCount += $this->clearDir($cacheDir);
            }
        }
        $results[] = '已清理缓存文件 ' . $fileCount . ' 个';

        return $this->success([
            'results'    => $results,
            'file_count' => $fileCount,
        ]);
    }

    /**
     * 递归删除目录下的文件（保留目录本身），返回删除文件数
     */
    private function clearDir(string $dir): int
    {
        $count = 0;
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $count += $this->clearDir($path);
                @rmdir($path);
            } elseif (@unlink($path)) {
                ++$count;
            }
        }

        return $count;
    }
}
