<?php

declare(strict_types=1);

// api 应用中间件（按数组顺序执行）
return [
    // 跨域（最前，保证 OPTIONS 预检优先返回）
    \app\common\middleware\CrossDomain::class,
    // 租户解析（注入 aid）
    \app\common\middleware\TenantMiddleware::class,
    // JWT 鉴权（白名单见类内定义）
    \app\common\middleware\AuthMiddleware::class,
    // 分销推荐关系绑定（fromid 参数，任务179）
    \app\common\middleware\FenxiaoBindMiddleware::class,
];
