<?php

declare(strict_types=1);

// open 应用中间件（按数组顺序执行）
return [
    // 跨域（对外回调/开放接口）
    \app\common\middleware\CrossDomain::class,
];
