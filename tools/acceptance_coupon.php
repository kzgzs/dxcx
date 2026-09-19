<?php
// P5 优惠券模块验收脚本（mock 模式）
// 用法：先 php think run -p 8080，再 php tools/acceptance_coupon.php
// 覆盖：建券校验拦截 → 领取（限领/余量/并发防超发）→ 下单用券计价（满减/折扣）
//       → 门槛拒绝 → 券占用拦截 → 支付核销 → 取消释放（含超时命令路径）
//       → 过期实时计算 → records 记录列表 → edit/delete/status 拦截

declare(strict_types=1);

// 与服务端业务口径对齐（TP 默认 Asia/Shanghai，CLI 默认 UTC 会导致时间串差 8 小时）
date_default_timezone_set('Asia/Shanghai');

const BASE = 'http://127.0.0.1:8080';
const PHP_BIN = 'C:\Users\Administrator\AppData\Roaming\Local\lightning-services\php-8.2.27+1\bin\win64\php.exe';

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

/** 后台券列表里找指定券行 */
function couponRow(string $token, string $keyword, int $couponId): ?array
{
    $r = req('GET', '/admin/coupon/list?pagesize=100&keyword=' . rawurlencode($keyword), null, $token);
    foreach ($r['json']['data']['list'] ?? [] as $row) {
        if ((int) $row['id'] === $couponId) {
            return $row;
        }
    }
    return null;
}

$TS    = time();
$start = date('Y-m-d H:i:s', $TS - 3600);
$end   = date('Y-m-d H:i:s', $TS + 86400);

// ---- 0. 环境清理：避免残留满减促销自动参与计价干扰用券断言 ----
$pdo = db();
$pdo->exec('UPDATE promotion SET deleted = 1 WHERE aid = 1');
$pdo = null;

// ---- 1. 登录与备货 ----
$r = req('POST', '/api/member/login', ['phone' => '13800138000', 'password' => '123456']);
check('会员登录', ($r['json']['code'] ?? 1) === 0, $r['raw']);
$mtoken = (string) ($r['json']['data']['token'] ?? '');

$r = req('POST', '/admin/login', ['username' => 'tenant1', 'password' => '123456']);
check('tenant1 登录', ($r['json']['code'] ?? 1) === 0, $r['raw']);
$atoken = (string) ($r['json']['data']['token'] ?? '');

$r = req('POST', '/admin/goods/category/add', ['name' => '券验收分类_' . $TS, 'pid' => 0], $atoken);
$catId = (int) ($r['json']['data']['id'] ?? 0);

$r = req('POST', '/admin/goods/add', [
    'name' => '券验收商品_' . $TS, 'category_id' => $catId, 'price' => '100.00', 'stock' => 30,
    'image' => '/uploads/tenant_1/test.png', 'status' => 1,
], $atoken);
check('建商品（单价100 库存30）', ($r['json']['code'] ?? 1) === 0, $r['raw']);
$goodsId = (int) ($r['json']['data']['id'] ?? 0);

$r = req('POST', '/api/address/add', [
    'name' => '券验收人', 'phone' => '13800138000',
    'province' => '广东省', 'city' => '深圳市', 'district' => '南山区',
    'detail' => '科技路1号', 'is_default' => 1,
], $mtoken);
$addrId = (int) ($r['json']['data']['id'] ?? 0);
check('新增地址', $addrId > 0, $r['raw']);

// ---- 2. 建券校验拦截 ----
$r = req('POST', '/admin/coupon/add', ['type' => 1, 'money' => '20.00', 'full_money' => '100.00', 'total' => 1, 'per_limit' => 1, 'start_time' => $start, 'end_time' => $end], $atoken);
check('建券缺名称被拒', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

$r = req('POST', '/admin/coupon/add', ['name' => 'x', 'type' => 9, 'start_time' => $start, 'end_time' => $end], $atoken);
check('建券类型无效被拒', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

$r = req('POST', '/admin/coupon/add', ['name' => 'x', 'type' => 1, 'full_money' => '100.00', 'total' => 1, 'per_limit' => 1, 'start_time' => $start, 'end_time' => $end], $atoken);
check('满减券缺抵扣金额被拒', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

$r = req('POST', '/admin/coupon/add', ['name' => 'x', 'type' => 2, 'discount' => 0.5, 'total' => 1, 'per_limit' => 1, 'start_time' => $start, 'end_time' => $end], $atoken);
check('折扣值低于1被拒', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

$r = req('POST', '/admin/coupon/add', ['name' => 'x', 'type' => 2, 'discount' => 10, 'total' => 1, 'per_limit' => 1, 'start_time' => $start, 'end_time' => $end], $atoken);
check('折扣值超过9.9被拒', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

$r = req('POST', '/admin/coupon/add', ['name' => 'x', 'type' => 1, 'money' => '20.00', 'full_money' => '100.00', 'total' => 1, 'per_limit' => 1, 'start_time' => $end, 'end_time' => $start], $atoken);
check('结束时间早于开始被拒', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

$r = req('POST', '/admin/coupon/add', ['name' => 'x', 'type' => 1, 'money' => '20.123', 'full_money' => '100.00', 'total' => 1, 'per_limit' => 1, 'start_time' => $start, 'end_time' => $end], $atoken);
check('金额三位小数被拒', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

// ---- 3. 建券成功（A满减 B折扣 C高门槛 E防超发 F待软删） ----
$r = req('POST', '/admin/coupon/add', ['name' => '券A满减_' . $TS, 'type' => 1, 'money' => '20.00', 'full_money' => '100.00', 'total' => 2, 'per_limit' => 1, 'start_time' => $start, 'end_time' => $end], $atoken);
check('建满减券A（满100减20，时间字符串兼容）', ($r['json']['code'] ?? 1) === 0 && ($r['json']['data']['id'] ?? 0) > 0, $r['raw']);
$couponA = (int) ($r['json']['data']['id'] ?? 0);

$r = req('POST', '/admin/coupon/add', ['name' => '券U秒_' . $TS, 'type' => 1, 'money' => '5.00', 'full_money' => '0.00', 'total' => 5, 'per_limit' => 1, 'start_time' => $TS - 3600, 'end_time' => $TS + 86400], $atoken);
check('建券（前端 Unix 秒时间戳口径）', ($r['json']['code'] ?? 1) === 0 && ($r['json']['data']['id'] ?? 0) > 0, $r['raw']);
$couponU = (int) ($r['json']['data']['id'] ?? 0);

$row = couponRow($atoken, '券U秒_' . $TS, $couponU);
check('Unix秒建券回读 start_time/end_time 为整数秒', null !== $row
    && ($row['start_time'] ?? 0) === $TS - 3600
    && ($row['end_time'] ?? 0) === $TS + 86400, json_encode($row, JSON_UNESCAPED_UNICODE));

$r = req('POST', '/admin/coupon/edit', ['id' => $couponU, 'end_time' => $TS + 90000], $atoken);
$row = couponRow($atoken, '券U秒_' . $TS, $couponU);
check('edit 时间用 Unix 秒兼容', ($r['json']['code'] ?? 1) === 0
    && null !== $row && ($row['end_time'] ?? 0) === $TS + 90000, $r['raw']);

$r = req('POST', '/admin/coupon/add', ['name' => 'x', 'type' => 1, 'money' => '1.00', 'full_money' => '0.00', 'total' => 1, 'per_limit' => 1, 'start_time' => 'abc', 'end_time' => $TS], $atoken);
check('非法时间格式被拒', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

$r = req('POST', '/admin/coupon/add', ['name' => '券B折扣_' . $TS, 'type' => 2, 'discount' => 8.5, 'full_money' => '0.00', 'total' => 10, 'per_limit' => 2, 'start_time' => $start, 'end_time' => $end], $atoken);
check('建折扣券B（8.5折）', ($r['json']['code'] ?? 1) === 0, $r['raw']);
$couponB = (int) ($r['json']['data']['id'] ?? 0);

$r = req('POST', '/admin/coupon/add', ['name' => '券C高门槛_' . $TS, 'type' => 1, 'money' => '10.00', 'full_money' => '500.00', 'total' => 5, 'per_limit' => 1, 'start_time' => $start, 'end_time' => $end], $atoken);
check('建高门槛券C（满500减10）', ($r['json']['code'] ?? 1) === 0, $r['raw']);
$couponC = (int) ($r['json']['data']['id'] ?? 0);

$r = req('POST', '/admin/coupon/add', ['name' => '券E防超发_' . $TS, 'type' => 1, 'money' => '5.00', 'full_money' => '0.00', 'total' => 1, 'per_limit' => 2, 'start_time' => $start, 'end_time' => $end], $atoken);
$couponE = (int) ($r['json']['data']['id'] ?? 0);

$r = req('POST', '/admin/coupon/add', ['name' => '券F待删_' . $TS, 'type' => 1, 'money' => '1.00', 'full_money' => '0.00', 'total' => 3, 'per_limit' => 1, 'start_time' => $start, 'end_time' => $end], $atoken);
$couponF = (int) ($r['json']['data']['id'] ?? 0);
check('建券E/F（备余量与软删用例）', $couponE > 0 && $couponF > 0, $r['raw']);

// 后台列表：keyword + 契约字段 + status 筛选
$r = req('GET', '/admin/coupon/list?keyword=' . rawurlencode('券A满减_' . $TS), null, $atoken);
$one = $r['json']['data']['list'][0] ?? null;
check('券列表契约字段', null !== $one
    && $one['name'] === '券A满减_' . $TS
    && ($one['type'] ?? 0) === 1
    && abs((float) ($one['money'] ?? 0) - 20.0) < 0.001
    && abs((float) ($one['full_money'] ?? 0) - 100.0) < 0.001
    && ($one['total'] ?? 0) === 2 && ($one['received'] ?? -1) === 0 && ($one['used'] ?? -1) === 0
    && ($one['per_limit'] ?? 0) === 1 && ($one['status'] ?? -1) === 1
    && ($one['start_time'] ?? 0) === $TS - 3600 && ($one['end_time'] ?? 0) === $TS + 86400
    && ($one['create_time'] ?? 0) > 0, $r['raw']);

$r = req('GET', '/admin/coupon/list?status=1&pagesize=100', null, $atoken);
$statusOk = ($r['json']['code'] ?? 1) === 0;
foreach ($r['json']['data']['list'] ?? [] as $row) {
    if (1 !== ($row['status'] ?? -1)) $statusOk = false;
}
check('券列表按状态筛选', $statusOk, $r['raw']);

// ---- 4. 领取（api） ----
$r = req('GET', '/api/coupon/list', null, $mtoken);
$ids = array_column($r['json']['data']['list'] ?? [], 'id');
check('可领列表含A/B/C/F', in_array($couponA, $ids, true) && in_array($couponB, $ids, true)
    && in_array($couponC, $ids, true) && in_array($couponF, $ids, true), $r['raw']);

$r = req('POST', '/api/coupon/receive', ['coupon_id' => $couponA], $mtoken);
check('领取券A成功', ($r['json']['code'] ?? 1) === 0 && ($r['json']['data']['id'] ?? 0) > 0, $r['raw']);

$r = req('POST', '/api/coupon/receive', ['coupon_id' => $couponA], $mtoken);
check('券A再领被拒（限领1张）', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

$r = req('POST', '/api/coupon/receive', ['coupon_id' => $couponB], $mtoken);
check('领取券B第1张', ($r['json']['code'] ?? 1) === 0, $r['raw']);
$r = req('POST', '/api/coupon/receive', ['coupon_id' => $couponB], $mtoken);
check('领取券B第2张（限领2张）', ($r['json']['code'] ?? 1) === 0, $r['raw']);
$r = req('POST', '/api/coupon/receive', ['coupon_id' => $couponB], $mtoken);
check('券B第3张被拒（已达限领）', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

// 余量防超发：PDO 模拟他人已领完券E（getnum=stock），本人未达限领但无余量
$pdo = db();
$pdo->exec("UPDATE coupon SET getnum = stock WHERE id = {$couponE}");
$pdo = null;

$r = req('POST', '/api/coupon/receive', ['coupon_id' => $couponE], $mtoken);
check('券E已被领完（余量为0）', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

$r = req('GET', '/api/coupon/list', null, $mtoken);
$ids = array_column($r['json']['data']['list'] ?? [], 'id');
check('可领列表隐藏无余量的券E', !in_array($couponE, $ids, true), $r['raw']);

$r = req('GET', '/api/coupon/mine?status=0', null, $mtoken);
$names = array_column($r['json']['data']['list'] ?? [], 'name');
check('我的券（未使用）含A与2张B', in_array('券A满减_' . $TS, $names, true)
    && 2 === count(array_filter($names, static fn ($n) => $n === '券B折扣_' . $TS)), $r['raw']);

// ---- 5. 下单用券计价 ----
$r = req('POST', '/api/order/create', ['goods_id' => $goodsId, 'num' => 1, 'address_id' => $addrId, 'coupon_id' => $couponA], $mtoken);
check('满减券下单计价 100-20=80', ($r['json']['code'] ?? 1) === 0
    && abs((float) ($r['json']['data']['pay_amount'] ?? 0) - 80.0) < 0.001
    && abs((float) ($r['json']['data']['coupon_money'] ?? 0) - 20.0) < 0.001
    && ($r['json']['data']['coupon_id'] ?? 0) === $couponA, $r['raw']);
$order1 = (int) ($r['json']['data']['id'] ?? 0);

$r = req('POST', '/api/order/create', ['goods_id' => $goodsId, 'num' => 1, 'address_id' => $addrId, 'coupon_id' => $couponA], $mtoken);
check('同一券被未支付订单占用（再下单被拒）', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

$r = req('POST', '/api/order/create', ['goods_id' => $goodsId, 'num' => 1, 'address_id' => $addrId, 'coupon_id' => $couponB], $mtoken);
check('折扣券下单计价 100*0.85=85', ($r['json']['code'] ?? 1) === 0
    && abs((float) ($r['json']['data']['pay_amount'] ?? 0) - 85.0) < 0.001
    && abs((float) ($r['json']['data']['coupon_money'] ?? 0) - 15.0) < 0.001, $r['raw']);
$order2 = (int) ($r['json']['data']['id'] ?? 0);

$r = req('POST', '/api/order/create', ['goods_id' => $goodsId, 'num' => 1, 'address_id' => $addrId, 'coupon_id' => $couponC], $mtoken);
check('门槛不满足被拒（100<500）', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

$r = req('POST', '/api/order/create', ['goods_id' => $goodsId, 'num' => 1, 'address_id' => $addrId, 'coupon_id' => $couponF], $mtoken);
check('未领取的券下单被拒', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

// ---- 6. 支付核销 ----
$r = req('POST', '/api/order/pay', ['order_id' => $order1, 'pay_type' => 'wechat'], $mtoken);
check('用券订单支付成功', ($r['json']['code'] ?? 1) === 0 && ($r['json']['data']['status'] ?? 0) === 1, $r['raw']);

$r = req('GET', '/admin/coupon/records?coupon_id=' . $couponA . '&status=1', null, $atoken);
$rec = $r['json']['data']['list'][0] ?? null;
check('核销记录（member_phone/order_sn/use_time 整数秒）', null !== $rec
    && ($rec['member_phone'] ?? '') === '13800138000'
    && '' !== ($rec['order_sn'] ?? '')
    && ($rec['use_time'] ?? 0) > 0
    && ($rec['receive_time'] ?? 0) > 0
    && ($rec['status'] ?? -1) === 1, $r['raw']);

$row = couponRow($atoken, '券A满减_' . $TS, $couponA);
check('券A used=1 received=1', null !== $row && ($row['used'] ?? -1) === 1 && ($row['received'] ?? -1) === 1, json_encode($row, JSON_UNESCAPED_UNICODE));

$r = req('GET', '/api/order/detail?id=' . $order1, null, $mtoken);
check('订单详情返回 coupon_money=20', abs((float) ($r['json']['data']['coupon_money'] ?? 0) - 20.0) < 0.001
    && abs((float) ($r['json']['data']['pay_amount'] ?? 0) - 80.0) < 0.001, $r['raw']);

$r = req('GET', '/admin/order/list?keyword=' . rawurlencode('券验收人'), null, $atoken);
$found = false;
foreach ($r['json']['data']['list'] ?? [] as $o) {
    if ((int) $o['id'] === $order1 && abs((float) ($o['coupon_money'] ?? 0) - 20.0) < 0.001) $found = true;
}
check('后台订单列表返回 coupon_money', $found, $r['raw']);

// ---- 7. 取消释放（手动取消 + 超时命令） ----
$r = req('POST', '/api/order/cancel', ['id' => $order2], $mtoken);
check('取消用券订单2', ($r['json']['code'] ?? 1) === 0, $r['raw']);

$r = req('GET', '/api/coupon/mine?status=0', null, $mtoken);
$bCount = 0;
foreach ($r['json']['data']['list'] ?? [] as $c) {
    if ('券B折扣_' . $TS === ($c['name'] ?? '')) $bCount++;
}
check('取消后券B释放回未使用（2张）', 2 === $bCount, $r['raw']);

$r = req('POST', '/api/order/create', ['goods_id' => $goodsId, 'num' => 1, 'address_id' => $addrId, 'coupon_id' => $couponB], $mtoken);
check('释放后的券B可再次下单', ($r['json']['code'] ?? 1) === 0
    && abs((float) ($r['json']['data']['pay_amount'] ?? 0) - 85.0) < 0.001, $r['raw']);
$order3 = (int) ($r['json']['data']['id'] ?? 0);

// 超时命令路径释放
$pdo = db();
$pdo->exec("UPDATE shop_order SET pay_expire_time = " . (time() - 10) . " WHERE id = {$order3}");
$pdo = null;
exec('"' . PHP_BIN . '" "' . dirname(__DIR__) . '\\think" order:cancel 2>&1', $out, $code);
check('order:cancel 命令执行成功', 0 === $code && str_contains(implode("\n", $out), '取消'), implode("\n", $out));

$r = req('GET', '/api/order/detail?id=' . $order3, null, $mtoken);
check('超时用券单被自动取消（状态4）', ($r['json']['data']['status'] ?? -1) === 4, $r['raw']);

$r = req('GET', '/api/coupon/mine?status=0', null, $mtoken);
$bCount = 0;
foreach ($r['json']['data']['list'] ?? [] as $c) {
    if ('券B折扣_' . $TS === ($c['name'] ?? '')) $bCount++;
}
check('超时取消后券B释放（仍2张未用）', 2 === $bCount, $r['raw']);

// ---- 8. 过期实时计算 ----
$r = req('POST', '/admin/coupon/add', ['name' => '券G短效_' . $TS, 'type' => 1, 'money' => '1.00', 'full_money' => '0.00', 'total' => 5, 'per_limit' => 1, 'start_time' => $start, 'end_time' => date('Y-m-d H:i:s', time() + 10)], $atoken);
$couponG = (int) ($r['json']['data']['id'] ?? 0);
$r = req('POST', '/api/coupon/receive', ['coupon_id' => $couponG], $mtoken);
check('短效券G领取成功（有效期内）', ($r['json']['code'] ?? 1) === 0, $r['raw']);

sleep(12);

$r = req('GET', '/api/coupon/mine?status=2', null, $mtoken);
$expired = false;
foreach ($r['json']['data']['list'] ?? [] as $c) {
    if ('券G短效_' . $TS === ($c['name'] ?? '')) $expired = true;
}
check('过期券实时归入 status=2', $expired, $r['raw']);

$r = req('POST', '/api/order/create', ['goods_id' => $goodsId, 'num' => 1, 'address_id' => $addrId, 'coupon_id' => $couponG], $mtoken);
check('过期券下单被拒', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

$r = req('POST', '/api/coupon/receive', ['coupon_id' => $couponG], $mtoken);
check('过期后领取被拒', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

// ---- 9. edit/delete/status 拦截 ----
$r = req('POST', '/admin/coupon/edit', ['id' => $couponA, 'name' => '券A满减_' . $TS, 'type' => 1, 'money' => '30.00', 'discount' => 0, 'full_money' => '100.00'], $atoken);
check('已领券A改抵扣金额被拒', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

$r = req('POST', '/admin/coupon/edit', ['id' => $couponB, 'name' => '改名_' . $TS], $atoken);
check('已领券B改名称被拒', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

$r = req('POST', '/admin/coupon/edit', ['id' => $couponA, 'name' => '券A满减_' . $TS, 'type' => 1, 'money' => '20.00', 'discount' => 0, 'full_money' => '100.00'], $atoken);
check('已领券全量原值提交容忍', ($r['json']['code'] ?? 1) === 0, $r['raw']);

$r = req('POST', '/admin/coupon/edit', ['id' => $couponA, 'total' => 5], $atoken);
$row = couponRow($atoken, '券A满减_' . $TS, $couponA);
check('已领券可改发行总量 2→5', ($r['json']['code'] ?? 1) === 0 && null !== $row && ($row['total'] ?? -1) === 5, $r['raw']);

$r = req('POST', '/admin/coupon/delete', ['id' => $couponA], $atoken);
check('已领券删除被拒', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

$r = req('POST', '/admin/coupon/delete', ['id' => $couponF], $atoken);
check('未领券软删成功', ($r['json']['code'] ?? 1) === 0, $r['raw']);
check('软删后列表不可见', null === couponRow($atoken, '券F待删_' . $TS, $couponF));

$r = req('POST', '/admin/coupon/status', ['id' => $couponC, 'status' => 0], $atoken);
check('停用券C成功', ($r['json']['code'] ?? 1) === 0, $r['raw']);

$r = req('GET', '/api/coupon/list', null, $mtoken);
$ids = array_column($r['json']['data']['list'] ?? [], 'id');
check('停用券不出现在可领列表', !in_array($couponC, $ids, true), $r['raw']);

$r = req('POST', '/api/coupon/receive', ['coupon_id' => $couponC], $mtoken);
check('停用券领取被拒', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

$r = req('POST', '/admin/coupon/status', ['id' => $couponC, 'status' => 1], $atoken);
check('重新启用券C成功', ($r['json']['code'] ?? 1) === 0, $r['raw']);

// ---- 10. records 筛选 ----
$r = req('GET', '/admin/coupon/records?coupon_id=' . $couponB . '&status=0', null, $atoken);
$okRec = ($r['json']['code'] ?? 1) === 0 && ($r['json']['data']['total'] ?? 0) >= 2;
foreach ($r['json']['data']['list'] ?? [] as $rec) {
    if (0 !== ($rec['status'] ?? -1)) $okRec = false;
}
check('券B未使用记录（>=2条 status=0）', $okRec, $r['raw']);

$r = req('GET', '/admin/coupon/records?status=1&pagesize=100', null, $atoken);
$okRec = ($r['json']['code'] ?? 1) === 0;
$hasA  = false;
foreach ($r['json']['data']['list'] ?? [] as $rec) {
    if (1 !== ($rec['status'] ?? -1)) $okRec = false;
    if ('13800138000' === ($rec['member_phone'] ?? '') && '' !== ($rec['order_sn'] ?? '')) $hasA = true;
}
check('全量已使用记录筛选（含券A核销单）', $okRec && $hasA, $r['raw']);

// ---- 11. 未登录拦截 ----
$r = req('GET', '/api/coupon/list');
check('未登录访问券接口 401', 401 === $r['status'], $r['raw']);

echo "\n========== 优惠券验收结果：{$pass} PASS / {$fail} FAIL ==========\n";
exit($fail > 0 ? 1 : 0);
