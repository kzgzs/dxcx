<?php

declare(strict_types=1);

// console 应用中间件（按数组顺序执行）
return [
    // 租户解析（注入 aid）
    \app\common\middleware\TenantMiddleware::class,
    // JWT 鉴权（白名单见类内定义）
    \app\common\middleware\AuthMiddleware::class,
];
