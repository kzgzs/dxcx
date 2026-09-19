<?php

declare(strict_types=1);

namespace app\api\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\ScopeService;

/**
 * api 应用控制器基类：会员上下文解析（aid/mid 均取自 JWT，不可伪造）
 */
abstract class Base extends BaseController
{
    /**
     * 解析会员上下文
     *
     * @return array{0: int, 1: int} [aid, mid]
     */
    protected function memberContext(): array
    {
        $payload = (array) ($this->request->authPayload ?? []);
        $mid     = (int) ($payload['mid'] ?? ($payload['uid'] ?? 0));
        $aid     = ScopeService::aidOf($this->request);

        if ($mid <= 0) {
            throw new BusinessException('未登录或登录已过期', 401, 401);
        }

        return [$aid, $mid];
    }
}
