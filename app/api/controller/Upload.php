<?php

declare(strict_types=1);

namespace app\api\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\ScopeService;
use app\common\service\UploadService;

/**
 * 客户端 - 通用图片上传（api 应用，鉴权 role=member，aid 取自 token）
 *
 * POST /api/upload（multipart，字段 file；复用 common UploadService，返回 {url} 相对路径同 admin 口径）
 */
class Upload extends BaseController
{
    public function __construct(\think\App $app, protected UploadService $uploadService)
    {
        parent::__construct($app);
    }

    public function index()
    {
        // PHP/框架层上传错误（超 upload_max_filesize 等）转为友好业务提示
        try {
            $file = $this->request->file('file');
        } catch (\Throwable $e) {
            throw new BusinessException('图片大小不能超过10MB', 1);
        }

        if (null === $file) {
            throw new BusinessException('请选择要上传的文件', 1);
        }

        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->uploadService->image($file, $aid));
    }
}
