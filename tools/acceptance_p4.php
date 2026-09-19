<?php
// P4 租户后台登录与 RBAC 地基 —— 全链路验收脚本
// 用法：先 php think run -p 8080，再 php tools/acceptance_p4.php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Firebase\JWT\JWT;

const BASE = 'http://127.0.0.1:8080';
const SECRET = 'dxcx-2026-jwt-secret-key';

$pass = 0;
$fail = 0;

function req(string $method, string $path, ?array $body = null, string $token = '', array $headers = []): array
{
    $ch = curl_init(BASE . $path);
    $hdrs = ['Accept: application/json'];
    if (null !== $body) {
        $hdrs[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    }
    if ('' !== $token) {
        $hdrs[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => array_merge($hdrs, $headers),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $raw    = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return ['status' => $status, 'json' => json_decode($raw, true), 'raw' => $raw];
}

function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "[PASS] {$name}\n";
    } else {
        $fail++;
        echo "[FAIL] {$name} " . $detail . "\n";
    }
}

// ---- 1. 超管登录 ----
$r = req('POST', '/console/login', ['username' => 'admin', 'password' => 'admin888']);
check('超管登录', ($r['json']['code'] ?? 1) === 0 && !empty($r['json']['data']['token']), $r['raw']);
$consoleToken = (string) ($r['json']['data']['token'] ?? '');

// ---- 2. 超管 proxyLogin 租户1 ----
$r = req('POST', '/console/tenant/proxyLogin', ['id' => 1], $consoleToken);
check('超管 proxyLogin 租户1', ($r['json']['code'] ?? 1) === 0 && !empty($r['json']['data']['token']), $r['raw']);
$proxyToken = (string) ($r['json']['data']['token'] ?? '');

// ---- 3. proxy token 访问 /admin/index/profile，aid 匹配 ----
$r = req('GET', '/admin/index/profile', null, $proxyToken);
$d = $r['json']['data'] ?? [];
check('proxy token → admin profile aid=1', ($r['json']['code'] ?? 1) === 0 && ($d['aid'] ?? 0) === 1 && ($d['tenant']['id'] ?? 0) === 1, $r['raw']);

// ---- 4. 租户账号密码登录 ----
$r = req('POST', '/admin/login', ['username' => 'tenant1', 'password' => '123456']);
$d = $r['json']['data'] ?? [];
$ok = ($r['json']['code'] ?? 1) === 0 && !empty($d['token'])
    && ($d['userinfo']['aid'] ?? 0) === 1 && ($d['userinfo']['isadmin'] ?? 0) === 1
    && ($d['userinfo']['expired'] ?? true) === false;
check('租户账号 tenant1 密码登录', $ok, $r['raw']);
$adminToken = (string) ($d['token'] ?? '');

// ---- 5. 租户 token 访问 profile + menu ----
$r = req('GET', '/admin/index/profile', null, $adminToken);
$d = $r['json']['data'] ?? [];
check('租户 token → profile 正确', ($r['json']['code'] ?? 1) === 0 && ($d['username'] ?? '') === 'tenant1' && ($d['tenant']['username'] ?? '') === 'tenant1', $r['raw']);

$r = req('GET', '/admin/menu', null, $adminToken);
$menus = $r['json']['data'] ?? [];
$names = array_column(is_array($menus) ? $menus : [], 'name');
check('菜单树（8项含首页/商城/会员/财务/商户/营销/扩展/系统）', ($r['json']['code'] ?? 1) === 0 && count($menus) === 8 && in_array('商城', $names, true) && in_array('会员', $names, true) && in_array('营销', $names, true) && in_array('扩展', $names, true), $r['raw']);

// ---- 6. 超管账号走 /admin/login 被拒 ----
$r = req('POST', '/admin/login', ['username' => 'admin', 'password' => 'admin888']);
check('超管账号走 /admin/login 被拒', ($r['json']['code'] ?? 1) !== 0 && str_contains((string) ($r['json']['msg'] ?? ''), '超管'), $r['raw']);

// ---- 7. 未登录访问 admin 接口 401 ----
$r = req('GET', '/admin/index/profile');
check('未登录访问 admin 接口 401', $r['status'] === 401, $r['raw']);

// ---- 8. 超管 profile ----
$r = req('GET', '/console/index/profile', null, $consoleToken);
$d = $r['json']['data'] ?? [];
check('超管 profile {id,username,isadmin=2}', ($r['json']['code'] ?? 1) === 0 && ($d['isadmin'] ?? 0) === 2 && ($d['username'] ?? '') === 'admin', $r['raw']);

// ---- 9. 会员登录 ----
$r = req('POST', '/api/member/login', ['phone' => '13800138000', 'password' => '123456'], '', ['X-Tenant-Id: 1']);
$d = $r['json']['data'] ?? [];
check('会员登录 13800138000/123456', ($r['json']['code'] ?? 1) === 0 && !empty($d['token']) && ($d['userinfo']['aid'] ?? 0) === 1, $r['raw']);
$memberToken = (string) ($d['token'] ?? '');

// ---- 10. 会员 profile ----
$r = req('GET', '/api/member/profile', null, $memberToken, ['X-Tenant-Id: 1']);
$d = $r['json']['data'] ?? [];
check('会员 profile', ($r['json']['code'] ?? 1) === 0 && ($d['phone'] ?? '') === '13800138000' && ($d['aid'] ?? 0) === 1, $r['raw']);

// ---- 11. 跨租户模拟：伪造 aid=2 的 admin token ----
$forgedAdmin = JWT::encode([
    'uid' => 1, 'aid' => 2, 'un' => 'tenant1', 'isadmin' => 1, 'role' => 'admin',
    'iat' => time(), 'exp' => time() + 3600,
], SECRET, 'HS256');
$r = req('GET', '/admin/index/profile', null, $forgedAdmin);
check('伪造 aid=2 admin token 被租户隔离拦截', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

// aid 注入优先于 Header：带 aid=1 的合法 token + X-Tenant-Id: 99 → 仍返回 aid=1
$r = req('GET', '/admin/index/profile', null, $adminToken, ['X-Tenant-Id: 99']);
$d = $r['json']['data'] ?? [];
check('token aid 优先于 Header（X-Tenant-Id:99 无效）', ($r['json']['code'] ?? 1) === 0 && ($d['aid'] ?? 0) === 1, $r['raw']);

// 伪造 aid=2 的 member token 查 profile（mid=1 属于 aid=1）→ 查不到
$forgedMember = JWT::encode([
    'uid' => 1, 'mid' => 1, 'aid' => 2, 'role' => 'member',
    'iat' => time(), 'exp' => time() + 3600,
], SECRET, 'HS256');
$r = req('GET', '/api/member/profile', null, $forgedMember, ['X-Tenant-Id: 2']);
check('伪造 aid=2 member token 查不到 aid=1 会员', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

// member token 访问 admin 应用 → 403 角色不符
$r = req('GET', '/admin/index/profile', null, $memberToken);
check('member token 访问 admin 应用 403', $r['status'] === 403, $r['raw']);

echo "\n==== 结果：PASS={$pass} FAIL={$fail} ====\n";
exit($fail > 0 ? 1 : 0);
