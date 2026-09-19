<?php

declare(strict_types=1);

namespace app;

use app\common\exception\BusinessException;
use app\common\service\Result;
use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\exception\Handle;
use think\exception\HttpException;
use think\exception\HttpResponseException;
use think\exception\ValidateException;
use think\Response;
use Throwable;

/**
 * 应用异常处理类
 *
 * 全部异常统一返回 JSON：{code, msg, data}（code=0 成功，异常均为非 0）
 */
class ExceptionHandle extends Handle
{
    /**
     * 不需要记录信息（日志）的异常类列表
     * @var array
     */
    protected $ignoreReport = [
        HttpException::class,
        HttpResponseException::class,
        ModelNotFoundException::class,
        DataNotFoundException::class,
        ValidateException::class,
        BusinessException::class,
    ];

    /**
     * 记录异常信息（包括日志或者其它方式记录）
     *
     * @access public
     * @param  Throwable $exception
     * @return void
     */
    public function report(Throwable $exception): void
    {
        // 使用内置的方式记录异常日志
        parent::report($exception);
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @access public
     * @param \think\Request   $request
     * @param Throwable $e
     * @return Response
     */
    public function render($request, Throwable $e): Response
    {
        // 交由 HttpResponseException 自行处理（如重定向、主动抛出的响应）
        if ($e instanceof HttpResponseException) {
            return parent::render($request, $e);
        }

        // 业务异常（登录失败/重复数据/租户不存在等）
        if ($e instanceof BusinessException) {
            $httpStatus = $e->getHttpStatus();

            return json(Result::error($e->getMessage(), $e->getCode()), $httpStatus);
        }

        // 参数验证异常
        if ($e instanceof ValidateException) {
            return json(Result::error((string) $e->getMessage(), 422), 200);
        }

        // 数据未找到
        if ($e instanceof DataNotFoundException || $e instanceof ModelNotFoundException) {
            return json(Result::error('数据不存在', 404), 404);
        }

        // HTTP 异常（404/405 等）
        if ($e instanceof HttpException) {
            $statusCode = $e->getStatusCode();
            $message    = $e->getMessage() ?: $this->httpStatusMessage($statusCode);

            return json(Result::error($message, $statusCode), $statusCode);
        }

        // Throwable 兜底
        $debug = $this->app->isDebug();
        $data  = [];

        if ($debug) {
            $data = [
                'exception' => get_class($e),
                'file'      => $e->getFile() . ':' . $e->getLine(),
                'trace'     => $this->traceSummary($e),
            ];
        }

        $message = $debug ? $e->getMessage() : '服务器内部错误';

        return json(Result::error($message ?: '服务器内部错误', 500, $data), 500);
    }

    /**
     * 提取异常调用栈摘要（仅 debug 模式输出）
     *
     * @param Throwable $e
     * @return array<int, string>
     */
    protected function traceSummary(Throwable $e): array
    {
        $lines = [];

        foreach (array_slice($e->getTrace(), 0, 8) as $item) {
            $lines[] = sprintf(
                '%s%s%s()',
                $item['file'] ?? '[internal]',
                isset($item['line']) ? ':' . $item['line'] : '',
                ($item['class'] ?? '') . ($item['type'] ?? '') . ($item['function'] ?? '')
            );
        }

        return $lines;
    }

    /**
     * 常见 HTTP 状态码文案
     */
    protected function httpStatusMessage(int $statusCode): string
    {
        return match ($statusCode) {
            400     => '请求参数错误',
            401     => '未授权',
            403     => '禁止访问',
            404     => '资源不存在',
            405     => '请求方法不允许',
            default => 'HTTP 错误 ' . $statusCode,
        };
    }
}
