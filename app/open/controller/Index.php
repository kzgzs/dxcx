<?php

declare(strict_types=1);

namespace app\open\controller;

use app\BaseController;

/**
 * 开放平台应用入口（对外回调/开放接口）
 */
class Index extends BaseController
{
    public function index()
    {
        return $this->success([
            'app' => 'open',
        ]);
    }
}
