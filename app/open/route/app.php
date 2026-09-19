<?php

declare(strict_types=1);

use app\common\service\Result;
use think\facade\Route;

// open 应用显式路由（对外回调/推送，无 JWT 鉴权，签名/加解密自行校验）

// 第三方平台事件推送（服务端 URL）
Route::get('event', 'Event/verify');
Route::post('event', 'Event/receive');

// 授权回调
Route::get('auth/callback', 'Auth/callback');

// 消息路由（全网发布检测），:appid 为授权方 appid
Route::post('msg/:appid', 'Msg/handle');

// Mock 授权页（仅 mock 模式）
Route::get('mock/authpage', 'Mock/authpage');

// 兼容旧入口
Route::get('index', 'Index/index');

// 兜底
Route::miss(function () {
    return json(Result::error('接口不存在', 404), 404);
});
