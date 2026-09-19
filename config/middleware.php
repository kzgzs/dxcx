<?php

declare(strict_types=1);

// 中间件配置
return [
    // 别名或分组
    'alias'    => [
        'tenant' => \app\common\middleware\TenantMiddleware::class,
        'auth'   => \app\common\middleware\AuthMiddleware::class,
        'cross'  => \app\common\middleware\CrossDomain::class,
    ],
    // 优先级设置，此数组中的中间件会按照数组中的顺序优先执行
    'priority' => [],
];
