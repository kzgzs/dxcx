<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\file\UploadedFile;

/**
 * 通用图片/视频上传服务
 *
 * 约定：按租户隔离目录 public/uploads/tenant_{aid}/，文件名 日期+随机串
 * 校验：图片扩展名白名单，图片 ≤ 10MB（任务301 由 2MB 放宽）；视频扩展名白名单，视频 ≤ 100MB（任务296）
 */
class UploadService
{
    /** 允许的扩展名 */
    protected const EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

    /** 大小上限：10MB（任务301 由 2MB 放宽） */
    protected const MAX_SIZE = 10 * 1024 * 1024;

    /** 视频允许的扩展名（任务296：上传视频不再误报图片 2MB 限制） */
    public const VIDEO_EXTENSIONS = ['mp4', 'mov', 'avi', 'wmv', 'flv', 'mkv', 'webm', 'm4v'];

    /** 视频大小上限：100MB（仍受 PHP upload_max_filesize/post_max_size 约束） */
    protected const VIDEO_MAX_SIZE = 100 * 1024 * 1024;

    /** 音频允许的扩展名（任务311：音乐控制） */
    public const AUDIO_EXTENSIONS = ['mp3', 'wav', 'm4a', 'aac'];

    /** 音频大小上限：20MB */
    protected const AUDIO_MAX_SIZE = 20 * 1024 * 1024;

    /**
     * 图片上传
     *
     * @return array{url: string} 站点相对路径（如 /uploads/tenant_1/xxx.png）
     */
    public function image(UploadedFile $file, int $aid): array
    {
        if ($aid <= 0) {
            throw new BusinessException('非法的租户标识', 403, 403);
        }

        if ($file->getSize() > self::MAX_SIZE) {
            throw new BusinessException('图片大小不能超过10MB', 1);
        }

        $extension = strtolower($file->getOriginalExtension());
        if (!in_array($extension, self::EXTENSIONS, true)) {
            throw new BusinessException('仅支持 ' . implode('/', self::EXTENSIONS) . ' 格式的图片', 1);
        }

        $dir    = app()->getRootPath() . 'public/uploads/tenant_' . $aid;
        $saveName = date('Ymd') . '_' . bin2hex(random_bytes(8)) . '.' . $extension;

        $file->move($dir, $saveName);

        return ['url' => '/uploads/tenant_' . $aid . '/' . $saveName];
    }

    /**
     * 视频上传（任务296）：目录与图片同源 public/uploads/tenant_{aid}/，上限 100MB
     *
     * @return array{url: string} 站点相对路径（如 /uploads/tenant_1/xxx.mp4）
     */
    public function video(UploadedFile $file, int $aid): array
    {
        if ($aid <= 0) {
            throw new BusinessException('非法的租户标识', 403, 403);
        }

        if ($file->getSize() > self::VIDEO_MAX_SIZE) {
            throw new BusinessException('视频大小不能超过100MB', 1);
        }

        $extension = strtolower($file->getOriginalExtension());
        if (!in_array($extension, self::VIDEO_EXTENSIONS, true)) {
            throw new BusinessException('仅支持 ' . implode('/', self::VIDEO_EXTENSIONS) . ' 格式的视频', 1);
        }

        $dir      = app()->getRootPath() . 'public/uploads/tenant_' . $aid;
        $saveName = date('Ymd') . '_' . bin2hex(random_bytes(8)) . '.' . $extension;

        $file->move($dir, $saveName);

        return ['url' => '/uploads/tenant_' . $aid . '/' . $saveName];
    }

    /**
     * 音频上传（任务311）：目录与图片/视频同源 public/uploads/tenant_{aid}/，上限 20MB
     * 任务315：aid=0 为平台级口径，落 public/uploads/platform/（超管控制台音乐上传）
     *
     * @return array{url: string, size: string} 站点相对路径 + 大小展示值
     */
    public function audio(UploadedFile $file, int $aid): array
    {
        if ($aid < 0) {
            throw new BusinessException('非法的租户标识', 403, 403);
        }

        if ($file->getSize() > self::AUDIO_MAX_SIZE) {
            throw new BusinessException('音频大小不能超过20MB', 1);
        }

        $extension = strtolower($file->getOriginalExtension());
        if (!in_array($extension, self::AUDIO_EXTENSIONS, true)) {
            throw new BusinessException('仅支持 ' . implode('/', self::AUDIO_EXTENSIONS) . ' 格式的音频', 1);
        }

        $dirName  = $aid > 0 ? 'tenant_' . $aid : 'platform';
        $dir      = app()->getRootPath() . 'public/uploads/' . $dirName;
        $saveName = date('Ymd') . '_' . bin2hex(random_bytes(8)) . '.' . $extension;

        $bytes = (int) $file->getSize();
        $file->move($dir, $saveName);

        return [
            'url'  => '/uploads/' . $dirName . '/' . $saveName,
            'size' => $this->formatSize($bytes),
        ];
    }

    /** 字节数格式化展示值（KB/MB） */
    public function formatSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . 'MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 2) . 'KB';
        }

        return $bytes . 'B';
    }

    /** 证书允许的扩展名 */
    protected const CERT_EXTENSIONS = ['pem', 'key'];

    /** 证书大小上限：1MB */
    protected const CERT_MAX_SIZE = 1 * 1024 * 1024;

    /**
     * 微信支付证书上传（apiclient_cert.pem / apiclient_key.pem）
     *
     * 存储目录 public/uploads/cert/，平台级文件不按租户隔离
     *
     * @return array{url: string} 站点相对路径（如 /uploads/cert/xxx.pem）
     */
    public function certificate(UploadedFile $file): array
    {
        if ($file->getSize() > self::CERT_MAX_SIZE) {
            throw new BusinessException('证书文件不能超过1MB', 1);
        }

        $extension = strtolower($file->getOriginalExtension());
        if (!in_array($extension, self::CERT_EXTENSIONS, true)) {
            throw new BusinessException('仅支持 .pem / .key 格式的证书文件', 1);
        }

        $dir      = app()->getRootPath() . 'public/uploads/cert';
        $saveName = date('Ymd') . '_' . bin2hex(random_bytes(8)) . '.' . $extension;

        $file->move($dir, $saveName);

        return ['url' => '/uploads/cert/' . $saveName];
    }
}
