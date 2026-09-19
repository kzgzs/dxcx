<?php
// P4 商户/门店基础验收脚本
// 用法：先 php think run -p 8080，再 php tools/acceptance_merchant.php
// 覆盖：商户 CRUD、门店 CRUD（merchant_name）、删商户拦截、停用商户拦截、跨 aid 隔离、菜单

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

function db(): PDO
{
    return new PDO('mysql:host=127.0.0.1;port=3306;dbname=dxcx;charset=utf8mb4', 'dxcx', 'dxcx123456');
}

$SUFFIX = '_' . time();

// ---- 1. 登录 ----
$r = req('POST', '/admin/login', ['username' => 'tenant1', 'password' => '123456']);
check('tenant1 登录', ($r['json']['code'] ?? 1) === 0, $r['raw']);
$token = (string) ($r['json']['data']['token'] ?? '');

// ---- 2. 商户 CRUD ----
$r = req('POST', '/admin/merchant/add', [
    'name' => '验收商户A' . $SUFFIX, 'contacts' => '张三', 'phone' => '13700137000',
    'remark' => '主力商户', 'status' => 1,
], $token);
check('新增商户A', ($r['json']['code'] ?? 1) === 0 && ($r['json']['data']['id'] ?? 0) > 0, $r['raw']);
$mA = (int) ($r['json']['data']['id'] ?? 0);

$r = req('POST', '/admin/merchant/add', ['name' => '验收商户B' . $SUFFIX, 'contacts' => '李四', 'status' => 0], $token);
$mB = (int) ($r['json']['data']['id'] ?? 0);
check('新增商户B（停用）', $mB > 0, $r['raw']);

$r = req('POST', '/admin/merchant/add', ['name' => ''], $token);
check('商户名为空被拒', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

$r = req('POST', '/admin/merchant/add', ['name' => 'x', 'phone' => '123'], $token);
check('商户手机号格式被拒', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

$r = req('POST', '/admin/merchant/edit', ['id' => $mA, 'remark' => '已更新', 'contacts' => '张三丰'], $token);
check('编辑商户', ($r['json']['code'] ?? 1) === 0, $r['raw']);

$r = req('GET', '/admin/merchant/list?keyword=' . rawurlencode('验收商户A' . $SUFFIX), null, $token);
$one = null;
foreach ($r['json']['data']['list'] ?? [] as $m) {
    if ((int) $m['id'] === $mA) $one = $m;
}
check('商户列表字段契约', null !== $one
    && ($one['contacts'] ?? '') === '张三丰'
    && ($one['remark'] ?? '') === '已更新'
    && array_key_exists('store_count', $one)
    && array_key_exists('create_time', $one), $r['raw']);

$r = req('GET', '/admin/merchant/list?status=0', null, $token);
$foundB = false;
$allDisabled = true;
foreach ($r['json']['data']['list'] ?? [] as $m) {
    if ((int) $m['status'] !== 0) $allDisabled = false;
    if ((int) $m['id'] === $mB) $foundB = true;
}
check('商户按状态筛选', $allDisabled && $foundB, $r['raw']);

// ---- 3. 门店 CRUD ----
$r = req('POST', '/admin/store/add', [
    'merchant_id' => $mA, 'name' => '验收门店一店' . $SUFFIX, 'contacts' => '王五',
    'phone' => '13600136000', 'province' => '广东省', 'city' => '深圳市', 'district' => '南山区',
    'address' => '深南大道1号', 'lng' => '113.93', 'lat' => '22.53', 'status' => 1,
], $token);
check('新增门店', ($r['json']['code'] ?? 1) === 0 && ($r['json']['data']['id'] ?? 0) > 0, $r['raw']);
$s1 = (int) ($r['json']['data']['id'] ?? 0);

$r = req('POST', '/admin/store/add', ['merchant_id' => $mB, 'name' => '停用商户的门店'], $token);
check('停用商户下新增门店被拒', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

$r = req('POST', '/admin/store/add', ['merchant_id' => 999999, 'name' => 'x'], $token);
check('商户不存在时新增门店被拒', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

$r = req('GET', '/admin/store/list?merchant_id=' . $mA, null, $token);
$one = null;
foreach ($r['json']['data']['list'] ?? [] as $s) {
    if ((int) $s['id'] === $s1) $one = $s;
}
check('门店列表带 merchant_name', null !== $one
    && ($one['merchant_name'] ?? '') === '验收商户A' . $SUFFIX
    && ($one['lng'] ?? '') === '113.93'
    && ($one['phone'] ?? '') === '13600136000', $r['raw']);

$r = req('GET', '/admin/store/list?keyword=' . rawurlencode('验收门店一店' . $SUFFIX), null, $token);
check('门店按关键字搜索', ($r['json']['data']['total'] ?? 0) >= 1, $r['raw']);

$r = req('POST', '/admin/store/edit', ['id' => $s1, 'address' => '深南大道2号', 'status' => 0], $token);
check('编辑门店', ($r['json']['code'] ?? 1) === 0, $r['raw']);

$r = req('GET', '/admin/store/list?merchant_id=' . $mA, null, $token);
$edited = null;
foreach ($r['json']['data']['list'] ?? [] as $s) {
    if ((int) $s['id'] === $s1) $edited = $s;
}
check('门店编辑生效', null !== $edited && ($edited['address'] ?? '') === '深南大道2号'
    && ($edited['status'] ?? -1) === 0, $r['raw']);

// 空经纬度（空串/null）编辑视为不修改，不覆盖已有值（前端全量提交场景）
$r = req('POST', '/admin/store/edit', ['id' => $s1, 'lng' => '', 'lat' => null, 'name' => '验收门店一店' . $SUFFIX], $token);
check('空经纬度编辑不报错', ($r['json']['code'] ?? 1) === 0, $r['raw']);
$r = req('GET', '/admin/store/list?merchant_id=' . $mA, null, $token);
$keep = null;
foreach ($r['json']['data']['list'] ?? [] as $s) {
    if ((int) $s['id'] === $s1) $keep = $s;
}
check('空经纬度不覆盖已有值', null !== $keep && ($keep['lng'] ?? '') === '113.93'
    && ($keep['lat'] ?? '') === '22.53', $r['raw']);

// ---- 4. 删商户拦截与放行 ----
$r = req('POST', '/admin/merchant/delete', ['id' => $mA], $token);
check('有门店的商户删除被拦截', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

$r = req('POST', '/admin/store/delete', ['id' => $s1], $token);
check('门店软删成功', ($r['json']['code'] ?? 1) === 0, $r['raw']);

$r = req('GET', '/admin/store/list?merchant_id=' . $mA, null, $token);
check('软删门店不再出现', 0 === ($r['json']['data']['total'] ?? -1), $r['raw']);

$r = req('POST', '/admin/merchant/delete', ['id' => $mA], $token);
check('门店清空后商户可删', ($r['json']['code'] ?? 1) === 0, $r['raw']);

$r = req('GET', '/admin/merchant/list?keyword=' . rawurlencode('验收商户A' . $SUFFIX), null, $token);
check('软删商户不再出现', 0 === ($r['json']['data']['total'] ?? -1), $r['raw']);

$r = req('POST', '/admin/merchant/delete', ['id' => $mB], $token);
check('无门店商户直接删除', ($r['json']['code'] ?? 1) === 0, $r['raw']);

// ---- 5. 跨租户隔离 ----
$pdo = db();
$pdo->exec("INSERT INTO merchant (aid, name, contacts, status, deleted, createtime) VALUES (2, '隔离商户" . time() . "', 'x', 1, 0, " . time() . ")");
$foreignId = (int) $pdo->lastInsertId();
$pdo = null;

$r = req('GET', '/admin/merchant/list?keyword=' . rawurlencode('隔离商户'), null, $token);
check('跨租户商户列表不可见', 0 === ($r['json']['data']['total'] ?? -1), $r['raw']);
$r = req('POST', '/admin/merchant/edit', ['id' => $foreignId, 'name' => 'hack'], $token);
check('跨租户商户编辑被拒', ($r['json']['code'] ?? 0) !== 0, $r['raw']);
$r = req('POST', '/admin/merchant/delete', ['id' => $foreignId], $token);
check('跨租户商户删除被拒', ($r['json']['code'] ?? 0) !== 0, $r['raw']);

$pdo = db();
$pdo->exec("DELETE FROM merchant WHERE id = " . $foreignId);
$pdo = null;

// ---- 6. 菜单契约 ----
$r = req('GET', '/admin/menu', null, $token);
$menuJson = json_encode($r['json']['data'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
check('菜单含退款管理 /admin/refund', str_contains($menuJson, '/admin/refund'), $menuJson);
check('菜单含商户列表 /admin/merchant', str_contains($menuJson, '/admin/merchant'), $menuJson);
check('菜单含门店列表 /admin/store', str_contains($menuJson, '/admin/store'), $menuJson);

// ---- 7. 鉴权 ----
$r = req('GET', '/admin/merchant/list');
check('未登录访问商户列表 401', 401 === $r['status'], $r['raw']);

echo "\n========== 商户/门店验收结果：{$pass} PASS / {$fail} FAIL ==========\n";
exit($fail > 0 ? 1 : 0);
