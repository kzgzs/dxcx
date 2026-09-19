<?php

declare(strict_types=1);

namespace app\common\exception;

/**
 * 业务异常
 *
 * 由全局异常处理器统一转为 JSON：{code, msg, data}
 * code 为业务错误码，httpStatus 为对外 HTTP 状态码（默认 200，鉴权类错误传 401/403/404）
 */
class BusinessException extends \RuntimeException
{
    public function __construct(
        string $message,
        int $code = 1,
        protected int $httpStatus = 200,
    ) {
        parent::__construct($message, $code);
    }

    /**
     * 对外 HTTP 状态码
     */
    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }
}
