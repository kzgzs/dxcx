<?php

declare(strict_types=1);

namespace app\open\controller;

use app\BaseController;
use app\common\service\OpenPlatformService;
use app\common\service\WxBizCrypt;

/**
 * 开放平台 - Mock 授权页（仅 mock 模式生效）
 *
 * GET /open/mock/authpage?pre_auth_code=&auth_type=[&tenant_id=]
 * 展示「模拟授权确认」按钮，点击携带生成的 auth_code 跳转 /open/auth/callback
 */
class Mock extends BaseController
{
    public function __construct(\think\App $app, protected OpenPlatformService $open)
    {
        parent::__construct($app);
    }

    public function authpage()
    {
        if (!$this->open->isMock()) {
            return response('mock mode disabled', 404)->contentType('text/plain');
        }

        $preAuthCode = htmlspecialchars((string) $this->request->param('pre_auth_code', ''), ENT_QUOTES);
        $authType    = (int) $this->request->param('auth_type', 2);
        $tenantId    = (int) $this->request->param('tenant_id', 0);
        $state       = trim((string) $this->request->param('state', ''));
        $authCode    = 'MOCKCODE_' . WxBizCrypt::randomStr(16);

        $callback = '/open/auth/callback?auth_code=' . urlencode($authCode)
            . '&expires_in=600'
            . ($tenantId > 0 ? '&tenant_id=' . $tenantId : '')
            . ('' !== $state ? '&state=' . urlencode($state) : '');

        $html = <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>模拟授权确认</title>
<style>
body{margin:0;font-family:-apple-system,"PingFang SC","Microsoft YaHei",sans-serif;background:#f5f6f8;display:flex;align-items:center;justify-content:center;min-height:100vh}
.card{background:#fff;border-radius:12px;padding:40px 48px;max-width:460px;text-align:center;box-shadow:0 4px 24px rgba(0,0,0,.06)}
.badge{display:inline-block;background:#fff3e0;color:#e65100;font-size:12px;border-radius:999px;padding:4px 12px;margin-bottom:16px}
h1{font-size:20px;margin:0 0 8px;color:#1f2329}
p{font-size:14px;color:#646a73;margin:0 0 24px;line-height:1.7}
.btn{display:inline-block;background:#07c160;color:#fff;text-decoration:none;font-size:15px;border-radius:8px;padding:12px 36px}
.btn:hover{background:#06ad56}
.meta{margin-top:20px;font-size:12px;color:#9aa0a6;word-break:break-all}
</style>
</head>
<body>
<div class="card">
<div class="badge">MOCK 模式</div>
<h1>模拟授权确认</h1>
<p>这是本地模拟的微信授权页。<br>点击下方按钮将模拟「小程序：Mock小程序」授权给第三方平台。</p>
<a class="btn" href="{$callback}">模拟授权确认</a>
<div class="meta">pre_auth_code: {$preAuthCode}<br>auth_type: {$authType} ｜ auth_code: {$authCode}</div>
</div>
</body>
</html>
HTML;

        return response($html)->contentType('text/html');
    }
}
