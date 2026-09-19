<?php
// P7 客户端（uni-app）后端接口补齐验收脚本（mock 模式）
// 用法：先 php think run -p 8080，再 php tools/acceptance_p7_api.php
// 覆盖：wxlogin（mock 注册幂等）→ sendsms/smslogin（Redis+自动注册）→ 购物车全链路
//       → api 上传（multipart）→ 首页聚合 index/home

declare(strict_types=1);

// 与服务端业务口径对齐（CLI 默认 UTC 会串时区）
date_default_timezone_set('Asia/Shanghai');

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

function upload(string $path, string $tmpFile, string $mime, string $token): array
{
    $ch = curl_init(BASE . $path);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => ['file' => new CURLFile($tmpFile, $mime, basename($tmpFile))],
        CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Authorization: Bearer ' . $token],
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

$TS = time();

// ---- 0. 环境清理（脚本可独立重跑） ----
$pdo = db();
$pdo->exec("DELETE FROM cart WHERE member_id IN (SELECT id FROM member WHERE wxopenid LIKE 'wxmock_%' OR tel IN ('13800138000','13977770001','13977770002'))");
$pdo->exec("DELETE FROM member WHERE wxopenid LIKE 'wxmock_%' OR tel IN ('13977770001','13977770002')");
$pdo->exec("DELETE FROM cart WHERE product_id IN (SELECT id FROM shop_product WHERE name LIKE 'P7验收商品%')");
$pdo->exec("UPDATE shop_product SET deleted = 1 WHERE name LIKE 'P7验收商品%'");
$pdo->exec("UPDATE shop_category SET deleted = 1 WHERE name LIKE 'P7验收分类%'");
$pdo->exec("DELETE FROM promotion WHERE name LIKE 'P7验收促销%'");
$pdo = null;

// 清 redis sms 键
$redis = new Redis();
$redis->connect('127.0.0.1', 6379, 3.0);
foreach ($redis->keys('sms:1:1397777*') as $k) {
    $redis->del($k);
}

// ---- 1. 登录（admin + 既有会员） ----
$r = req('POST', '/admin/login', ['username' => 'tenant1', 'password' => '123456']);
check('tenant1 登录', ($r['json']['code'] ?? 1) === 0, $r['raw']);
$atoken = (string) ($r['json']['data']['token'] ?? '');

$r = req('POST', '/api/member/login', ['phone' => '13800138000', 'password' => '123456']);
check('会员登录', ($r['json']['code'] ?? 1) === 0, $r['raw']);
$mtoken = (string) ($r['json']['data']['token'] ?? '');

// ---- 2. 微信 code 静默登录（mock） ----
$r = req('POST', '/api/member/wxlogin?aid=1', ['code' => '']);
check('wxlogin 空 code 拒绝', ($r['json']['code'] ?? 0) !== 0, $r['raw']);

$codeA = 'p7_wx_code_A_' . $TS;
$r = req('POST', '/api/member/wxlogin?aid=1', ['code' => $codeA]);
$openidA = 'wxmock_' . md5($codeA);
$wxA = $r['json']['data'] ?? [];
check('wxlogin 成功（token+member 结构）', ($r['json']['code'] ?? 1) === 0
    && '' !== ($wxA['token'] ?? '') && (int) ($wxA['member']['id'] ?? 0) > 0, $r['raw']);
check('wxlogin 自动注册昵称=微信用户+openid后4位', '微信用户' . substr($openidA, -4) === ($wxA['member']['nickname'] ?? ''), $r['raw']);
$wxMidA = (int) ($wxA['member']['id'] ?? 0);
$wxTokenA = (string) ($wxA['token'] ?? '');

$r = req('POST', '/api/member/wxlogin?aid=1', ['code' => $codeA]);
check('wxlogin 同 code 幂等（同会员）', ($r['json']['code'] ?? 1) === 0
    && $wxMidA === (int) ($r['json']['data']['member']['id'] ?? 0), $r['raw']);

$r = req('POST', '/api/member/wxlogin?aid=1', ['code' => 'p7_wx_code_B_' . $TS]);
check('wxlogin 不同 code 注册新会员', ($r['json']['code'] ?? 1) === 0
    && (int) ($r['json']['data']['member']['id'] ?? 0) > $wxMidA, $r['raw']);

$r = req('GET', '/api/member/profile', null, $wxTokenA);
check('wxlogin token 可访问 profile', ($r['json']['code'] ?? 1) === 0
    && $wxMidA === (int) ($r['json']['data']['mid'] ?? 0), $r['raw']);

// ---- 3. 短信验证码（Redis + mock 123456） ----
$r = req('POST', '/api/member/sendsms?aid=1', ['mobile' => '123']);
check('sendsms 非法手机号拒绝', ($r['json']['code'] ?? 0) !== 0, $r['raw']);

$r = req('POST', '/api/member/sendsms?aid=1', ['mobile' => '13977770001']);
check('sendsms 成功（mock）', ($r['json']['code'] ?? 1) === 0 && true === ($r['json']['data']['success'] ?? false), $r['raw']);

check('Redis 落键 sms:1:13977770001（600s/123456）', '123456' === $redis->get('sms:1:13977770001')
    && $redis->ttl('sms:1:13977770001') > 0 && $redis->ttl('sms:1:13977770001') <= 600);

$r = req('POST', '/api/member/smslogin?aid=1', ['mobile' => '13977770001', 'code' => '999999']);
check('smslogin 错误验证码拒绝', ($r['json']['code'] ?? 0) !== 0, $r['raw']);

$r = req('POST', '/api/member/smslogin?aid=1', ['mobile' => '13977770001', 'code' => '123456']);
$smsA = $r['json']['data'] ?? [];
check('smslogin 成功（自动注册）', ($r['json']['code'] ?? 1) === 0
    && '' !== ($smsA['token'] ?? '') && (int) ($smsA['member']['id'] ?? 0) > 0
    && '13977770001' === ($smsA['member']['phone'] ?? ''), $r['raw']);
$smsMidA = (int) ($smsA['member']['id'] ?? 0);

check('smslogin 后 Redis 键失效（防重放）', false === $redis->get('sms:1:13977770001'));

$r = req('POST', '/api/member/smslogin?aid=1', ['mobile' => '13977770001', 'code' => '123456']);
check('验证码一次性（复用拒绝）', ($r['json']['code'] ?? 0) !== 0, $r['raw']);

$r = req('POST', '/api/member/sendsms?aid=1', ['mobile' => '13977770001']);
$r = req('POST', '/api/member/smslogin?aid=1', ['mobile' => '13977770001', 'code' => '123456']);
check('重发再登录=同一会员', ($r['json']['code'] ?? 1) === 0
    && $smsMidA === (int) ($r['json']['data']['member']['id'] ?? 0), $r['raw']);

// ---- 4. 购物车 ----
$r = req('GET', '/api/cart/list');
check('未登录访问购物车拦截（401）', 401 === (int) ($r['status'] ?? 0), $r['raw']);

// 建分类+商品（商品A 上架 stock=5 价 19.90；商品B 上架 stock=3）
$r = req('POST', '/admin/goods/category/add', ['name' => 'P7验收分类_' . $TS], $atoken);
$catId = (int) ($r['json']['data']['id'] ?? 0);
check('建商品分类', $catId > 0, $r['raw']);

$r = req('POST', '/admin/goods/add', [
    'name' => 'P7验收商品A_' . $TS, 'category_id' => $catId, 'price' => '19.90', 'stock' => 5,
    'image' => '/uploads/p7_a.png', 'status' => 1,
], $atoken);
$gidA = (int) ($r['json']['data']['id'] ?? 0);
check('建商品A（19.90 stock=5）', $gidA > 0, $r['raw']);

$r = req('POST', '/admin/goods/add', [
    'name' => 'P7验收商品B_' . $TS, 'category_id' => $catId, 'price' => '9.90', 'stock' => 3,
    'image' => '/uploads/p7_b.png', 'status' => 1,
], $atoken);
$gidB = (int) ($r['json']['data']['id'] ?? 0);
check('建商品B（9.90 stock=3）', $gidB > 0, $r['raw']);

$r = req('POST', '/api/cart/add', ['product_id' => $gidA, 'num' => 2], $mtoken);
check('加购商品A×2', ($r['json']['code'] ?? 1) === 0 && (int) ($r['json']['data']['id'] ?? 0) > 0, $r['raw']);
$cartA = (int) ($r['json']['data']['id'] ?? 0);

$r = req('POST', '/api/cart/add', ['product_id' => $gidA, 'num' => 3], $mtoken);
check('加购商品A×3（同行累加）', ($r['json']['code'] ?? 1) === 0 && $cartA === (int) ($r['json']['data']['id'] ?? 0), $r['raw']);

$r = req('GET', '/api/cart/list', null, $mtoken);
$rowOk = false;
foreach ($r['json']['data']['list'] ?? [] as $row) {
    if ((int) ($row['id'] ?? 0) === $cartA) {
        $rowOk = $gidA === (int) ($row['product_id'] ?? 0)
            && 'P7验收商品A_' . $TS === ($row['name'] ?? '')
            && '/uploads/p7_a.png' === ($row['pic'] ?? '')
            && 19.9 === (float) ($row['price'] ?? 0)
            && 5 === (int) ($row['stock'] ?? 0)
            && 5 === (int) ($row['num'] ?? 0)
            && 1 === (int) ($row['selected'] ?? 0);
    }
}
check('购物车列表字段契约（id/product_id/name/pic/price/stock/num/selected）', $rowOk, $r['raw']);

$r = req('POST', '/api/cart/add', ['product_id' => $gidA, 'num' => 1], $mtoken);
check('累加超库存拒绝（5+1>5）', ($r['json']['code'] ?? 0) !== 0, $r['raw']);

$r = req('POST', '/api/cart/add', ['product_id' => 9999999, 'num' => 1], $mtoken);
check('不存在商品加购拒绝', ($r['json']['code'] ?? 0) !== 0, $r['raw']);

$r = req('POST', '/admin/goods/status', ['id' => $gidB, 'status' => 0], $atoken);
$r = req('POST', '/api/cart/add', ['product_id' => $gidB, 'num' => 1], $mtoken);
check('下架商品加购拒绝', ($r['json']['code'] ?? 0) !== 0, $r['raw']);
$r = req('POST', '/admin/goods/status', ['id' => $gidB, 'status' => 1], $atoken);

$r = req('POST', '/api/cart/add', ['product_id' => $gidB, 'num' => 1], $mtoken);
$cartB = (int) ($r['json']['data']['id'] ?? 0);
check('加购商品B×1', $cartB > 0, $r['raw']);

$r = req('POST', '/api/cart/update', ['id' => $cartA, 'num' => 4], $mtoken);
check('更新数量=4', ($r['json']['code'] ?? 1) === 0, $r['raw']);

$r = req('POST', '/api/cart/update', ['id' => $cartA, 'num' => 99], $mtoken);
check('更新超库存拒绝', ($r['json']['code'] ?? 0) !== 0, $r['raw']);

$r = req('POST', '/api/cart/update', ['id' => $cartB, 'selected' => 0], $mtoken);
check('更新 selected=0', ($r['json']['code'] ?? 1) === 0, $r['raw']);

$r = req('GET', '/api/cart/list', null, $mtoken);
$okA = $okB = false;
foreach ($r['json']['data']['list'] ?? [] as $row) {
    if ($cartA === (int) ($row['id'] ?? 0) && 4 === (int) ($row['num'] ?? 0)) {
        $okA = true;
    }
    if ($cartB === (int) ($row['id'] ?? 0) && 0 === (int) ($row['selected'] ?? -1)) {
        $okB = true;
    }
}
check('更新后列表生效（num=4 / selected=0）', $okA && $okB, $r['raw']);

$r = req('POST', '/api/cart/selectAll', ['selected' => 1], $mtoken);
$r = req('GET', '/api/cart/list', null, $mtoken);
$allSel = !empty($r['json']['data']['list'] ?? [])
    && !array_filter($r['json']['data']['list'], static fn ($x) => 1 !== (int) ($x['selected'] ?? -1));
check('selectAll 全选生效', $allSel, $r['raw']);

// 会员隔离：另一个 smslogin 会员看不到/动不了本会员购物车
$r = req('POST', '/api/member/sendsms?aid=1', ['mobile' => '13977770002']);
$r = req('POST', '/api/member/smslogin?aid=1', ['mobile' => '13977770002', 'code' => '123456']);
$token2 = (string) ($r['json']['data']['token'] ?? '');

$r = req('GET', '/api/cart/list', null, $token2);
check('他人购物车不可见（列表为空）', ($r['json']['code'] ?? 1) === 0 && empty($r['json']['data']['list'] ?? []), $r['raw']);

$r = req('POST', '/api/cart/delete', ['ids' => [$cartA]], $token2);
check('他人购物车行删除无效（deleted=0）', ($r['json']['code'] ?? 1) === 0 && 0 === (int) ($r['json']['data']['deleted'] ?? -1), $r['raw']);

$r = req('POST', '/api/cart/delete', ['ids' => [$cartB, 999999]], $mtoken);
check('删除自己的购物车行（deleted=1）', ($r['json']['code'] ?? 1) === 0 && 1 === (int) ($r['json']['data']['deleted'] ?? -1), $r['raw']);

$r = req('POST', '/api/cart/delete', ['ids' => [$cartA]], $mtoken);
check('清空剩余行', ($r['json']['code'] ?? 1) === 0, $r['raw']);

$r = req('GET', '/api/cart/list', null, $mtoken);
check('删除后列表为空', empty($r['json']['data']['list'] ?? []), $r['raw']);

// ---- 5. api 上传 ----
$r = upload('/api/upload', __FILE__, 'text/plain', '');
check('未登录上传拦截（401）', 401 === (int) ($r['status'] ?? 0), $r['raw']);

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$tmpPng = sys_get_temp_dir() . '/p7_test.png';
file_put_contents($tmpPng, $png);

$r = upload('/api/upload', $tmpPng, 'image/png', $mtoken);
$url = (string) ($r['json']['data']['url'] ?? '');
check('上传 png 成功（url 相对路径）', ($r['json']['code'] ?? 1) === 0 && str_starts_with($url, '/uploads/tenant_1/'), $r['raw']);
check('上传文件落盘可访问', '' !== $url && file_exists(__DIR__ . '/../public' . $url));
if ('' !== $url) {
    @unlink(__DIR__ . '/../public' . $url);
}

$tmpTxt = sys_get_temp_dir() . '/p7_test.txt';
file_put_contents($tmpTxt, 'not an image');
$r = upload('/api/upload', $tmpTxt, 'text/plain', $mtoken);
check('非图片扩展名拒绝', ($r['json']['code'] ?? 0) !== 0, $r['raw']);
@unlink($tmpPng);
@unlink($tmpTxt);

// ---- 6. 首页聚合 index/home ----
$r = req('POST', '/admin/promotion/add', [
    'id' => 0, 'name' => 'P7验收促销_' . $TS, 'type' => 1, 'full_money' => 100, 'cut_money' => 10,
    'full_num' => 0, 'discount' => 0, 'start_time' => $TS - 60, 'end_time' => $TS + 86400,
], $atoken);
$promoId = (int) ($r['json']['data']['id'] ?? 0);
check('建进行中满减促销', $promoId > 0, $r['raw']);

$r = req('GET', '/api/index/home?aid=1');
$d = $r['json']['data'] ?? [];
check('home 结构 {banners,goods,promotions}', ($r['json']['code'] ?? 1) === 0
    && is_array($d['banners'] ?? null) && is_array($d['goods'] ?? null) && is_array($d['promotions'] ?? null), $r['raw']);
check('banners 一期为空数组', [] === ($d['banners'] ?? null), json_encode($d['banners'] ?? null, JSON_UNESCAPED_UNICODE));

$names = array_map(static fn ($x) => (string) ($x['name'] ?? ''), $d['goods'] ?? []);
check('goods 含上架商品A 且 ≤10 条', in_array('P7验收商品A_' . $TS, $names, true) && count($d['goods']) <= 10, $r['raw']);

$pnames = array_map(static fn ($x) => (string) ($x['name'] ?? ''), $d['promotions'] ?? []);
check('promotions 含进行中满减', in_array('P7验收促销_' . $TS, $pnames, true), $r['raw']);

// ---- 7. 收尾清理 ----
$pdo = db();
$pdo->exec("DELETE FROM cart WHERE member_id IN (SELECT id FROM member WHERE wxopenid LIKE 'wxmock_%' OR tel IN ('13800138000','13977770001','13977770002'))");
$pdo->exec("DELETE FROM member WHERE wxopenid LIKE 'wxmock_%' OR tel IN ('13977770001','13977770002')");
$pdo->exec("DELETE FROM cart WHERE product_id IN (SELECT id FROM shop_product WHERE name LIKE 'P7验收商品%')");
$pdo->exec("UPDATE shop_product SET deleted = 1 WHERE name LIKE 'P7验收商品%'");
$pdo->exec("UPDATE shop_category SET deleted = 1 WHERE name LIKE 'P7验收分类%'");
$pdo->exec("DELETE FROM promotion WHERE name LIKE 'P7验收促销%'");
$pdo = null;

echo "\nP7 API 验收结果：PASS={$pass} FAIL={$fail}\n";
exit($fail > 0 ? 1 : 0);
