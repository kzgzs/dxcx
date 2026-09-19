<?php

declare(strict_types=1);

namespace app\common\middleware;

use app\common\service\Result;
use Closure;
use think\Request;
use think\Response;

/**
 * 租户解析中间件
 *
 * 从请求中解析租户 ID（aid），注入请求上下文：
 * 优先级：Header X-Tenant-Id > 请求参数 aid
 * 控制器内通过 $request->middleware('aid')（或 $request->aid）获取
 */
class TenantMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $aid = $request->header('x-tenant-id');

        if (null === $aid || '' === $aid) {
            $aid = $request->param('aid', 0);
        }

        $aid = (int) $aid;

        // 骨架阶段：console/admin/api 要求必须携带租户标识（超管控制台 aid=0 表示平台级）
        if ($aid < 0) {
            return json(Result::error('非法的租户标识', 403), 403);
        }

        $request->aid = $aid;

        return $next($request);
    }
}
