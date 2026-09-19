<?php

declare(strict_types=1);

namespace app\common\service;

/**
 * 统一响应结果封装
 *
 * 约定：code = 0 表示成功，非 0 表示各类业务/系统错误
 * 输出结构：{code, msg, data}
 */
class Result
{
    /**
     * 成功响应
     *
     * @param mixed  $data 响应数据
     * @param string $msg  提示信息
     * @return array{code: int, msg: string, data: mixed}
     */
    public static function success(mixed $data = [], string $msg = 'success'): array
    {
        return [
            'code' => 0,
            'msg'  => $msg,
            'data' => $data,
        ];
    }

    /**
     * 失败响应
     *
     * @param string $msg  错误信息
     * @param int    $code 错误码
     * @param mixed  $data 附加数据
     * @return array{code: int, msg: string, data: mixed}
     */
    public static function error(string $msg = 'error', int $code = 1, mixed $data = []): array
    {
        return [
            'code' => $code,
            'msg'  => $msg,
            'data' => $data,
        ];
    }
}
