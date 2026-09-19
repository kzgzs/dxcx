<?php
// P4 商品模块验收脚本（admin 商品 CRUD + 上传 + api 客户端接口 + 跨租户）
// 用法：先 php think run -p 8080，再 php tools/acceptance_goods.php

declare(strict_types=1);

const BASE = 'http://127.0.0.1:8080';

$pass = 0;
$fail = 0;

function req(string $method, string $path, ?array $body = null, string $token = ''): array
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
        CURLOPT_HTTPHEADER     => $hdrs,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $raw    = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return ['status' => $status, 'json' => json_decode($raw, true), 'raw' => $raw];
}

/** multipart 上传 */
function upload(string $path, array $files, string $token): array
{
    $ch = curl_init(BASE . $path);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_POSTFIELDS     => $files,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
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

// ---- 1. tenant1 登录 ----
$r = req('POST', '/admin/login', ['username' => 'tenant1', 'password' => '123456']);
check('tenant1 登录', ($r['json']['code'] ?? 1) === 0, $r['raw']);
$token = (string) ($r['json']['data']['token'] ?? '');

// ---- 2. 建分类（A 有商品 / B 空分类） ----
$r = req('POST', '/admin/goods/category/add', ['name' => '验收分类A_' . time(), 'pid' => 0, 'sort' => 10], $token);
check('建分类A', ($r['json']['code'] ?? 1) === 0 && ($r['json']['data']['id'] ?? 0) > 0, $r['raw']);
$catA = (int) ($r['json']['data']['id'] ?? 0);

$r = req('POST', '/admin/goods/category/add', ['name' => '验收分类B_' . time(), 'sort' => 1], $token);
check('建分类B', ($r['json']['code'] ?? 1) === 0, $r['raw']);
$catB = (int) ($r['json']['data']['id'] ?? 0);

// 分类列表含 goods_count/create_time 字段
$r = req('GET', '/admin/goods/category/list?page=1&pagesize=50', null, $token);
$row = null;
foreach ($r['json']['data']['list'] ?? [] as $c) {
    if ((int) $c['id'] === $catA) {
        $row = $c;
    }
}
check('分类列表字段完整', null !== $row && array_key_exists('goods_count', $row) && array_key_exists('create_time', $row), $r['raw']);

// ---- 3. 上传图片（先拿 URL 给商品用） ----
$pngPath = __DIR__ . '/tmp_test.png';
// 1x1 透明 PNG
file_put_contents($pngPath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
$r = upload('/admin/upload', ['file' => new CURLFile($pngPath, 'image/png', 'test.png')], $token);
check('上传图片返回 URL', ($r['json']['code'] ?? 1) === 0 && str_starts_with((string) ($r['json']['data']['url'] ?? ''), '/uploads/tenant_1/'), $r['raw']);
$imgUrl = (string) ($r['json']['data']['url'] ?? '');

// 上传的图片可通过静态服务访问
$ch = curl_init(BASE . $imgUrl);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
curl_exec($ch);
check('上传文件可访问 200', curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 200);
curl_close($ch);

// 超限（>2MB）拒绝
$bigPath = __DIR__ . '/tmp_big.png';
file_put_contents($bigPath, str_repeat('A', 2 * 1024 * 1024 + 1024));
$r = upload('/admin/upload', ['file' => new CURLFile($bigPath, 'image/png', 'big.png')], $token);
check('超 2MB 上传被拒', ($r['json']['code'] ?? 1) !== 0 && str_contains((string) ($r['json']['msg'] ?? ''), '2MB'), $r['raw']);

// 类型拒绝
$txtPath = __DIR__ . '/tmp_test.txt';
file_put_contents($txtPath, 'hello');
$r = upload('/admin/upload', ['file' => new CURLFile($txtPath, 'text/plain', 'a.txt')], $token);
check('非图片类型被拒', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

// ---- 4. 建商品（P1 上架 / P2 下架） ----
$r = req('POST', '/admin/goods/add', [
    'name'         => '验收商品P1',
    'category_id'  => $catA,
    'price'        => '19.90',
    'market_price' => '29.90',
    'stock'        => 50,
    'image'        => $imgUrl,
    'images'       => $imgUrl,
    'detail'       => '<p>详情内容</p>',
    'sort'         => 5,
    'status'       => 1,
], $token);
check('建商品P1（上架）', ($r['json']['code'] ?? 1) === 0 && ($r['json']['data']['id'] ?? 0) > 0, $r['raw']);
$p1 = (int) ($r['json']['data']['id'] ?? 0);

$r = req('POST', '/admin/goods/add', [
    'name'        => '验收商品P2',
    'category_id' => $catA,
    'price'       => '9.9',
    'stock'       => 3,
    'image'       => $imgUrl,
    'status'      => 0,
], $token);
check('建商品P2（下架）', ($r['json']['code'] ?? 1) === 0, $r['raw']);
$p2 = (int) ($r['json']['data']['id'] ?? 0);

// 价格非法（三位小数）拒绝
$r = req('POST', '/admin/goods/add', ['name' => 'x', 'category_id' => $catA, 'price' => '1.999', 'stock' => 1, 'status' => 1], $token);
check('价格三位小数被拒', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

// ---- 5. 列表筛选 ----
$r = req('GET', '/admin/goods/list?page=1&pagesize=20&category_id=' . $catA, null, $token);
$ids = array_column($r['json']['data']['list'] ?? [], 'id');
$one = null;
foreach ($r['json']['data']['list'] ?? [] as $g) {
    if ((int) $g['id'] === $p1) {
        $one = $g;
    }
}
check('列表按分类筛选（含分类名/价格字段）', in_array($p1, $ids, true) && in_array($p2, $ids, true)
    && null !== $one && ($one['category_name'] ?? '') !== '' && abs(($one['price'] ?? 0) - 19.9) < 0.001, $r['raw']);

$r = req('GET', '/admin/goods/list?status=1&keyword=' . rawurlencode('验收商品P'), null, $token);
$ids = array_column($r['json']['data']['list'] ?? [], 'id');
check('列表 status+keyword 筛选', in_array($p1, $ids, true) && !in_array($p2, $ids, true), $r['raw']);

// ---- 6. api 侧（aid=1） ----
$r = req('GET', '/api/goods/list?aid=1&pagesize=100');
$ids = array_column($r['json']['data']['list'] ?? [], 'id');
check('api 列表仅上架商品', in_array($p1, $ids, true) && !in_array($p2, $ids, true), $r['raw']);

$r = req('GET', '/api/goods/category/list?aid=1');
$names = array_column($r['json']['data']['list'] ?? [], 'id');
$catRow = null;
foreach ($r['json']['data']['list'] ?? [] as $c) {
    if ((int) $c['id'] === $catA) {
        $catRow = $c;
    }
}
check('api 分类仅含有上架商品的分类（goods_count>=1）', null !== $catRow && ($catRow['goods_count'] ?? 0) >= 1, $r['raw']);

$r = req('GET', '/api/goods/detail?aid=1&id=' . $p1);
$d = $r['json']['data'] ?? [];
check('api 详情字段完整', ($r['json']['code'] ?? 1) === 0 && is_string($d['images'] ?? null)
    && str_contains((string) $d['images'], $imgUrl)
    && ($d['category_name'] ?? '') !== '' && str_contains((string) ($d['detail'] ?? ''), '详情内容'), $r['raw']);

// ---- 7. 上下架 ----
$r = req('POST', '/admin/goods/status', ['id' => $p1, 'status' => 0], $token);
check('下架成功', ($r['json']['code'] ?? 1) === 0, $r['raw']);

$r = req('GET', '/api/goods/list?aid=1&pagesize=100');
$ids = array_column($r['json']['data']['list'] ?? [], 'id');
check('下架后 api 列表不可见', !in_array($p1, $ids, true), $r['raw']);

$r = req('GET', '/api/goods/detail?aid=1&id=' . $p1);
check('下架商品详情返回业务错误', ($r['json']['code'] ?? 1) !== 0 && str_contains((string) ($r['json']['msg'] ?? ''), '下架'), $r['raw']);

req('POST', '/admin/goods/status', ['id' => $p1, 'status' => 1], $token);

// ---- 8. 软删商品 ----
$r = req('POST', '/admin/goods/delete', ['id' => $p1], $token);
check('软删商品（无未完结订单）成功', ($r['json']['code'] ?? 1) === 0, $r['raw']);

$r = req('GET', '/api/goods/detail?aid=1&id=' . $p1);
check('已删商品 api 详情不可见', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

$r = req('GET', '/admin/goods/list?keyword=' . rawurlencode('验收商品P1'), null, $token);
check('已删商品后台列表不可见', ($r['json']['data']['total'] ?? 1) === 0, $r['raw']);

// ---- 9. 删除分类拦截 ----
$r = req('POST', '/admin/goods/category/delete', ['id' => $catA], $token);
check('有商品的分类删除被拦截', ($r['json']['code'] ?? 1) !== 0
    && str_contains((string) ($r['json']['msg'] ?? ''), '请先转移或删除该分类下的商品'), $r['raw']);

req('POST', '/admin/goods/delete', ['id' => $p2], $token);
$r = req('POST', '/admin/goods/category/delete', ['id' => $catA], $token);
check('商品清空后分类可删', ($r['json']['code'] ?? 1) === 0, $r['raw']);

$r = req('POST', '/admin/goods/category/delete', ['id' => $catB], $token);
check('空分类删除成功', ($r['json']['code'] ?? 1) === 0, $r['raw']);

// ---- 10. 跨租户（aid=2 无数据） ----
$r = req('GET', '/api/goods/list?aid=2&pagesize=100');
check('aid=2 商品列表为空', ($r['json']['code'] ?? 1) === 0 && ($r['json']['data']['total'] ?? 1) === 0, $r['raw']);

$r = req('GET', '/api/goods/category/list?aid=2');
check('aid=2 分类列表为空', ($r['json']['code'] ?? 1) === 0 && empty($r['json']['data']['list']), $r['raw']);

$r = req('GET', '/api/goods/detail?aid=2&id=' . $p1);
check('aid=2 查 aid=1 商品被拒', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

// ---- 11. 菜单 path 对齐 ----
$r = req('GET', '/admin/menu', null, $token);
$paths = [];
$collectPaths = static function (array $nodes) use (&$paths, &$collectPaths): void {
    foreach ($nodes as $n) {
        if ('' !== ($n['path'] ?? '')) {
            $paths[] = (string) $n['path'];
        }
        if (!empty($n['children']) && is_array($n['children'])) {
            $collectPaths($n['children']);
        }
    }
};
$collectPaths($r['json']['data'] ?? []);
check('菜单商品路径 /admin/goods 与 /admin/goods/category', in_array('/admin/goods', $paths, true) && in_array('/admin/goods/category', $paths, true), $r['raw']);

@unlink($pngPath);
@unlink($bigPath);
@unlink($txtPath);

echo "\n==== 结果：PASS={$pass} FAIL={$fail} ====\n";
exit($fail > 0 ? 1 : 0);
