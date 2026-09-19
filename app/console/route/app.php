<?php

declare(strict_types=1);

use app\common\service\Result;
use think\facade\Route;

// console 应用使用显式路由（未定义的路由统一走 miss 返回 JSON 404）
// 鉴权：除 login 外均要求 Authorization: Bearer <jwt>（role=console）

// 登录
Route::post('login', 'Login/login');

// 图形验证码（免登录公开接口，白名单见 AuthMiddleware）
Route::get('captcha', 'Captcha/index');

// 官网会员注册（免登录公开接口，白名单见 AuthMiddleware）
Route::get('register/config', 'Register/config');
Route::post('register/sendsms', 'Register/sendsms');
Route::post('register/submit', 'Register/submit');

// 纯域名官网（免登录公开页，对照参考系统 index/index2 官网模板）
Route::get('/', 'Website/index');
Route::get('index', 'Website/index');
Route::get('index/help', 'Website/help');
Route::post('index/message', 'Website/message');

// 计划任务访问入口（对照参考系统 ApiAuto，免登录，key 校验见控制器）
Route::get('ApiAuto/index/key/<key>', 'ApiAuto/index');

// 当前超管账号信息
Route::get('index/profile', 'Index/profile');
Route::post('index/editPassword', 'Index/editPassword');

// 仪表盘
Route::get('dashboard', 'Dashboard/index');

// 租户管理
Route::get('tenant/list', 'Tenant/list');
Route::post('tenant/add', 'Tenant/add');
Route::post('tenant/edit', 'Tenant/edit');
Route::post('tenant/delete', 'Tenant/delete');
Route::post('tenant/status', 'Tenant/status');
Route::post('tenant/renew', 'Tenant/renew');
Route::post('tenant/proxyLogin', 'Tenant/proxyLogin');
Route::get('tenant/uniappZip', 'Tenant/uniappZip');

// 微信开放平台第三方平台（契约：前端并行开发）
Route::get('open/config', 'Open/config');
Route::post('open/config', 'Open/saveConfig');
Route::post('open/uploadTemplate', 'Open/uploadTemplate');
Route::post('open/uploadVerifyFile', 'Open/uploadVerifyFile');
Route::get('open/authorizer/list', 'Open/authorizerList');
Route::get('open/authorizer/detail', 'Open/authorizerDetail');
Route::post('open/authorizer/refresh', 'Open/refreshToken');
Route::post('open/bindUrl', 'Open/bindUrl');
Route::post('open/unbind', 'Open/unbind');

// 代小程序代码管理与发布
Route::get('open/template/list', 'OpenCode/templateList');
Route::get('open/template/drafts', 'OpenCode/drafts');
Route::post('open/template/addFromDraft', 'OpenCode/addFromDraft');
Route::post('open/code/commit', 'OpenCode/commit');
Route::post('open/code/submitAudit', 'OpenCode/submitAudit');
Route::get('open/code/auditStatus', 'OpenCode/auditStatus');
Route::post('open/code/undoAudit', 'OpenCode/undoAudit');
Route::post('open/code/speedupAudit', 'OpenCode/speedupAudit');
Route::post('open/code/release', 'OpenCode/release');
Route::post('open/code/revert', 'OpenCode/revert');
Route::get('open/code/qrcode', 'OpenCode/qrcode');
Route::post('open/code/domain', 'OpenCode/domain');
Route::get('open/wxalog', 'OpenCode/wxalog');

// 系统设置 / 版本 / 缓存（超管侧基础设置）
Route::get('system/config', 'System/config');
Route::post('system/config', 'System/saveConfig');
Route::get('system/revealSecret', 'System/revealSecret');
Route::post('system/uploadCert', 'System/uploadCert');
Route::get('system/version', 'System/version');
Route::get('system/cacheStat', 'System/cacheStat');
Route::post('system/clearCache', 'System/clearCache');
Route::post('system/clearCacheItem', 'System/clearCacheItem');
Route::post('system/clearCacheByItems', 'System/clearCacheByItems');
Route::get('system/cacheGroups', 'System/cacheGroups');
Route::get('system/cacheKeys', 'System/cacheKeys');
Route::get('system/cacheQuery', 'System/cacheQuery');

// 系统升级（对照参考系统 WebUpgrade：版本对比 / 检测更新 / 执行升级 / 升级日志）
Route::get('upgrade/info', 'Upgrade/info');
Route::post('upgrade/check', 'Upgrade/check');
Route::post('upgrade/doup', 'Upgrade/doup');
Route::get('upgrade/log', 'Upgrade/log');

// 音乐控制（任务315：平台级音乐库 aid=0，控制台维护；租户编辑器经 /admin/music/list 透传）
Route::get('music/list', 'Music/list');
Route::post('music/add', 'Music/add');
Route::post('music/del', 'Music/del');
Route::post('music/clean', 'Music/clean');
Route::post('music/upload', 'Music/upload');

// 微信支付记录（只读）
Route::get('wxpaylog/list', 'WxpayLog/list');
Route::get('wxpaylog/detail', 'WxpayLog/detail');

// 网站留言（对照参考系统 WebMessage）
Route::get('webmessage/list', 'WebMessage/list');
Route::post('webmessage/setst', 'WebMessage/setst');
Route::post('webmessage/delete', 'WebMessage/delete');

// 帮助中心（文章管理，对照参考系统 WebHelp）
Route::get('help/list', 'Help/list');
Route::get('help/detail', 'Help/detail');
Route::post('help/save', 'Help/save');
Route::post('help/setst', 'Help/setst');
Route::post('help/delete', 'Help/delete');

// 通知公告（对照参考系统 WebNotice）
Route::get('webnotice/list', 'WebNotice/list');
Route::get('webnotice/detail', 'WebNotice/detail');
Route::get('webnotice/userList', 'WebNotice/userList');
Route::get('webnotice/receivers', 'WebNotice/receivers');
Route::post('webnotice/save', 'WebNotice/save');
Route::post('webnotice/delete', 'WebNotice/delete');

// 兜底：等效于该应用强制路由
Route::miss(function () {
    return json(Result::error('接口不存在', 404), 404);
});
