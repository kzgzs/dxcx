<?php

declare(strict_types=1);

namespace app\common\middleware;

use app\common\service\JwtService;
use app\common\service\Result;
use Closure;
use think\Http;
use think\Request;
use think\Response;

/**
 * JWT 鉴权中间件
 *
 * 校验请求头 Authorization: Bearer <token>，解码后将载荷注入请求上下文：
 * $request->authUid / $request->authPayload
 *
 * 白名单机制：按应用（console/admin/api）配置免登录的 控制器/方法（小写）
 * 角色校验：console 应用仅放行 role='console'，admin 应用仅放行 role='admin'，api 应用仅放行 role='member'
 * aid 注入：token 载荷中的 aid 优先于 Header/参数解析，覆盖写入 $request->aid（防伪造）
 * 说明：应用级中间件先于路由解析执行，故基于 pathinfo 匹配
 * 条目语法：普通条目 controller/action 按 pathinfo 前两段归一匹配；
 * "get:"/"post:" 前缀条目为三段全路径精确匹配且限定请求方法（任务243：
 * restaurant/booking 下仅 add/tableList/tableCategory 的 GET 匿名，下单/订单接口走鉴权）
 */
class AuthMiddleware
{
    /**
     * 各应用免登录白名单（controller/action 全小写）
     * @var array<string, array<int, string>>
     */
    protected array $whitelist = [
        'console' => ['login/index', 'captcha/index', 'apiauto/index', 'index/index', 'index/help', 'index/message', 'register/config', 'register/sendsms', 'register/submit'],
        'admin'   => ['index/index', 'login/index'],
        'api'     => ['index/index', 'index/home', 'index/sysset', 'index/recentorders', 'designer/page', 'designer/tab/get', 'designer/template', 'designer/login', 'member/login', 'member/wxlogin', 'member/sendsms', 'member/smslogin', 'goods/category', 'goods/list', 'goods/detail', 'promotion/list', 'cuxiao/list', 'pay/notify', 'carhailing/list', 'carhailing/detail', 'hotel/list', 'hotel/detail', 'hotel/room', 'yuyue/product', 'yuyue/worker', 'yuyue/slot', 'restaurant/info', 'restaurant/product', 'restaurant/takefood', 'article/getartlist', 'article/detail', 'kecheng/list', 'kecheng/detail', 'kecheng/category3', 'kecheng/downclist3', 'kecheng/classify', 'restaurantqueue/getqueuelist', 'designercomponent/fetch', 'get:restaurant/booking/add', 'get:restaurant/booking/tablelist', 'get:restaurant/booking/tablecategory', 'wxurl/go', 'adminidx/login', 'adminidx/autologin', 'adminidx/stats', 'adminidx/orderlist', 'adminidx/productlist', 'adminidx/productstatus', 'adminidx/memberlist', 'adminidx/finances', 'adminidx/setpwd'],
    ];

    /**
     * 各应用要求的 token 角色（未配置的应用不限制角色）
     * @var array<string, string>
     */
    protected array $roleMap = [
        'console' => 'console',
        'admin'   => 'admin',
        'api'     => 'member',
    ];

    public function __construct(protected Http $http)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $appName = strtolower($this->http->getName());
        $route   = $this->normalizeRoute((string) $request->pathinfo());

        // 白名单放行
        if ($this->isWhitelisted($this->whitelist[$appName] ?? [], $route, $request)) {
            return $next($request);
        }

        $token = $this->resolveToken($request);

        if ('' === $token) {
            return json(Result::error('未登录或登录已过期', 401), 401);
        }

        $decoded = JwtService::decode($token);

        if (null === $decoded) {
            return json(Result::error('登录凭证无效', 401), 401);
        }

        // 角色校验：console 不接受 role='admin' 的代登录 token，反之亦然
        $requiredRole = $this->roleMap[$appName] ?? null;

        if (null !== $requiredRole && (string) ($decoded['role'] ?? '') !== $requiredRole) {
            return json(Result::error('无权访问该应用', 403), 403);
        }

        $request->authUid     = (int) ($decoded['uid'] ?? 0);
        $request->authPayload = $decoded;

        // token 中的 aid 不可伪造，优先于 TenantMiddleware 的 Header/参数解析
        if (isset($decoded['aid'])) {
            $request->aid = (int) $decoded['aid'];
        }

        return $next($request);
    }

    /**
     * 从请求头解析 Bearer Token
     */
    protected function resolveToken(Request $request): string
    {
        $authorization = (string) $request->header('authorization', '');

        if (str_starts_with($authorization, 'Bearer ')) {
            return trim(substr($authorization, 7));
        }

        return '';
    }

    /**
     * 判定是否命中白名单
     *
     * 普通条目按 pathinfo 前两段归一匹配；"get:"/"post:" 前缀条目为
     * 三段全路径精确匹配且限定请求方法，避免同一控制器下需鉴权的
     * 动作（如 restaurant/booking/orderlist）被两段归一误伤放行
     */
    protected function isWhitelisted(array $entries, string $route, Request $request): bool
    {
        $segments = explode('/', strtolower(trim((string) $request->pathinfo(), '/')));
        $segments = array_values(array_filter($segments, static fn ($v) => '' !== $v));
        $fullPath = implode('/', $segments);

        foreach ($entries as $entry) {
            [$method, $target] = str_contains($entry, ':') ? explode(':', $entry, 2) : ['', $entry];

            if ('get' === $method && !$request->isGet()) {
                continue;
            }

            if ('post' === $method && !$request->isPost()) {
                continue;
            }

            $matched = substr_count($target, '/') >= 2 ? $fullPath === $target : $route === $target;

            if ($matched) {
                return true;
            }
        }

        return false;
    }

    /**
     * 将 pathinfo 规范化为 controller/action（全小写）
     * 空 → index/index；单段 foo → foo/index；多段取前两段
     */
    protected function normalizeRoute(string $pathinfo): string
    {
        $pathinfo = strtolower(trim($pathinfo, '/'));

        if ('' === $pathinfo) {
            return 'index/index';
        }

        $segments = explode('/', $pathinfo);
        $segments = array_values(array_filter($segments, static fn ($v) => '' !== $v));

        $controller = $segments[0] ?? 'index';
        $action     = $segments[1] ?? 'index';

        return $controller . '/' . $action;
    }
}
