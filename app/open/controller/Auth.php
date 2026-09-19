<?php

declare(strict_types=1);

namespace app\open\controller;

use app\BaseController;
use app\common\service\BindingService;
use app\common\service\OpenPlatformService;
use think\facade\Log;

/**
 * 开放平台 - 授权回调
 *
 * GET /open/auth/callback?auth_code=&expires_in=[&tenant_id=][&state=]
 * - 带 tenant_id/state（租户端发起，state=aid）：同事务写 admin_setapp_wx|mp(authtype=1) + open_authorizer.aid
 * - 不带：仅落 open_authorizer（超管侧 bindUrl 流程）
 * 成功返回 HTML 提示页；失败返回错误提示页
 */
class Auth extends BaseController
{
    public function __construct(\think\App $app, protected OpenPlatformService $open, protected BindingService $binding)
    {
        parent::__construct($app);
    }

    public function callback()
    {
        $authCode = trim((string) $this->request->param('auth_code', ''));
        $tenantId = (int) $this->request->param('tenant_id', 0);
        $state    = trim((string) $this->request->param('state', ''));
        // 租户端授权链接 state=aid；兼容历史 tenant_id 参数
        $aid      = $tenantId > 0 ? $tenantId : (int) $state;

        if ('' === $authCode) {
            return $this->page('授权失败', '缺少授权码 auth_code，请重新发起授权。');
        }

        try {
            if ($aid > 0) {
                // 租户绑定：同事务写 绑定表(authtype=1) + open_authorizer.aid + access_token
                $row = $this->binding->bindByAuthCode($authCode, $aid);
            } else {
                $row = $this->open->saveAuthorizerByAuthCode($authCode);
            }

            $nick = htmlspecialchars((string) ($row['nick_name'] ?? ''), ENT_QUOTES);
            $app  = htmlspecialchars((string) ($row['authorizer_appid'] ?? ''), ENT_QUOTES);

            return $this->page('授权成功', "「{$nick}」({$app}) 授权成功，请返回控制台查看。");
        } catch (\Throwable $e) {
            Log::error('open.auth.callback 失败: ' . $e->getMessage());

            return $this->page('授权失败', htmlspecialchars($e->getMessage(), ENT_QUOTES) . '，请返回控制台重试。');
        }
    }

    /**
     * 简单 HTML 提示页
     */
    protected function page(string $title, string $message): \think\Response
    {
        $html = <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$title}</title>
<style>
body{margin:0;font-family:-apple-system,"PingFang SC","Microsoft YaHei",sans-serif;background:#f5f6f8;display:flex;align-items:center;justify-content:center;min-height:100vh}
.card{background:#fff;border-radius:12px;padding:40px 48px;max-width:420px;text-align:center;box-shadow:0 4px 24px rgba(0,0,0,.06)}
h1{font-size:20px;margin:0 0 12px;color:#1f2329}
p{font-size:14px;color:#646a73;margin:0;line-height:1.7}
</style>
</head>
<body><div class="card"><h1>{$title}</h1><p>{$message}</p></div></body>
</html>
HTML;

        return response($html)->contentType('text/html');
    }
}
