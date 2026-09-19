<?php

declare(strict_types=1);

namespace app\common\middleware;

use Closure;
use think\Request;
use think\Response;

/**
 * 跨域请求中间件（api/open 应用使用）
 *
 * 处理 OPTIONS 预检请求并附加 CORS 响应头
 */
class CrossDomain
{
    /**
     * 允许跨域的请求头
     * @var string
     */
    protected string $allowHeaders = 'Content-Type, Authorization, X-Requested-With, X-Tenant-Id, Accept, Origin';

    public function handle(Request $request, Closure $next): Response
    {
        $origin = (string) $request->header('origin', '*');

        $header = [
            'Access-Control-Allow-Origin'  => '' !== $origin ? $origin : '*',
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, PATCH, OPTIONS',
            'Access-Control-Allow-Headers' => $this->allowHeaders,
            'Access-Control-Max-Age'       => '86400',
        ];

        // 预检请求直接返回
        if ('OPTIONS' === strtoupper($request->method())) {
            return Response::create()->code(204)->header($header);
        }

        return $next($request)->header($header);
    }
}
