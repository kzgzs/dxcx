<?php
// P4 订单与支付模块验收脚本（mock 模式）
// 用法：先 php think run -p 8080，再 php tools/acceptance_order.php
// 覆盖：会员地址 CRUD → 下单扣库存 → mock 支付 → 幂等回调 → 后台列表/详情/发货/统计
//       → 确认收货 → 取消回补库存 → order:cancel 超时取消 → 跨租户隔离

declare(strict_types=1);

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

function goodsStock(string $token, int $goodsId): int
{
    $r = req('GET', '/admin/goods/list?pagesize=100&keyword=' . rawurlencode('验收订单商品'), null, $token);
    foreach ($r['json']['data']['list'] ?? [] as $g) {
        if ((int) $g['id'] === $goodsId) {
            return (int) ($g['stock'] ?? -1);
        }
    }
    return -1;
}

// ---- 0. 环境清理：避免残留满减促销自动参与计价干扰断言 ----
$pdo = db();
$pdo->exec('UPDATE promotion SET deleted = 1 WHERE aid = 1');
$pdo = null;

// ---- 1. 会员登录 ----
$r = req('POST', '/api/member/login', ['phone' => '13800138000', 'password' => '123456']);
check('会员登录', ($r['json']['code'] ?? 1) === 0 && !empty($r['json']['data']['token']), $r['raw']);
$mtoken = (string) ($r['json']['data']['token'] ?? '');

// ---- 2. 收货地址 CRUD ----
$r = req('POST', '/api/address/add', [
    'name' => '张验收', 'phone' => '13800138000',
    'province' => '广东省', 'city' => '深圳市', 'district' => '南山区',
    'detail' => '科技园路1号', 'is_default' => 1,
], $mtoken);
check('新增地址', ($r['json']['code'] ?? 1) === 0 && ($r['json']['data']['id'] ?? 0) > 0, $r['raw']);
$addrId = (int) ($r['json']['data']['id'] ?? 0);

$r = req('POST', '/api/address/add', [
    'name' => '李备用', 'phone' => '13900139000',
    'province' => '北京市', 'city' => '北京市', 'district' => '朝阳区',
    'detail' => '望京SOHO',
], $mtoken);
$addrId2 = (int) ($r['json']['data']['id'] ?? 0);

$r = req('GET', '/api/address/list', null, $mtoken);
$names = array_column($r['json']['data'] ?? [], 'name');
$defaultOk = false;
foreach ($r['json']['data'] ?? [] as $a) {
    if ((int) $a['id'] === $addrId && (int) $a['is_default'] === 1) {
        $defaultOk = true;
    }
}
check('地址列表（含默认标记）', in_array('张验收', $names, true) && in_array('李备用', $names, true) && $defaultOk, $r['raw']);

// 设置第二个为默认 → 第一个自动取消默认
req('POST', '/api/address/edit', ['id' => $addrId2, 'is_default' => 1], $mtoken);
$r = req('GET', '/api/address/list', null, $mtoken);
$flag = ['a1' => -1, 'a2' => -1];
foreach ($r['json']['data'] ?? [] as $a) {
    if ((int) $a['id'] === $addrId) $flag['a1'] = (int) $a['is_default'];
    if ((int) $a['id'] === $addrId2) $flag['a2'] = (int) $a['is_default'];
}
check('编辑默认地址互斥', 0 === $flag['a1'] && 1 === $flag['a2'], $r['raw']);

// 改回第一个为默认（下单用）
req('POST', '/api/address/edit', ['id' => $addrId, 'is_default' => 1, 'detail' => '科技园路1号A座'], $mtoken);

// 软删备用地址
req('POST', '/api/address/delete', ['id' => $addrId2], $mtoken);
$r = req('GET', '/api/address/list', null, $mtoken);
$ids = array_column($r['json']['data'] ?? [], 'id');
check('软删地址不再出现', !in_array($addrId2, array_map('intval', $ids), true), $r['raw']);

// ---- 3. 后台建商品（tenant1） ----
$r = req('POST', '/admin/login', ['username' => 'tenant1', 'password' => '123456']);
check('tenant1 登录', ($r['json']['code'] ?? 1) === 0, $r['raw']);
$atoken = (string) ($r['json']['data']['token'] ?? '');

$r = req('POST', '/admin/goods/category/add', ['name' => '订单验收分类_' . time(), 'pid' => 0], $atoken);
$catId = (int) ($r['json']['data']['id'] ?? 0);

$r = req('POST', '/admin/goods/add', [
    'name'        => '验收订单商品_' . time(),
    'category_id' => $catId,
    'price'       => '19.90',
    'stock'       => 5,
    'image'       => '/uploads/tenant_1/test.png',
    'images'      => '/uploads/tenant_1/test.png',
    'status'      => 1,
], $atoken);
check('建商品（库存5）', ($r['json']['code'] ?? 1) === 0 && ($r['json']['data']['id'] ?? 0) > 0, $r['raw']);
$goodsId = (int) ($r['json']['data']['id'] ?? 0);

$r = req('POST', '/admin/goods/add', [
    'name' => '验收订单下架商品_' . time(), 'category_id' => $catId,
    'price' => '1.00', 'stock' => 9, 'status' => 0,
], $atoken);
$offGoodsId = (int) ($r['json']['data']['id'] ?? 0);

// ---- 4. 下单（扣库存） ----
$r = req('POST', '/api/order/create', ['goods_id' => $goodsId, 'num' => 2, 'address_id' => $addrId], $mtoken);
check('下单成功', ($r['json']['code'] ?? 1) === 0
    && ($r['json']['data']['id'] ?? 0) > 0
    && '' !== ($r['json']['data']['order_sn'] ?? '')
    && abs((float) ($r['json']['data']['pay_amount'] ?? 0) - 39.8) < 0.001, $r['raw']);
$order1   = (int) ($r['json']['data']['id'] ?? 0);
$orderSn1 = (string) ($r['json']['data']['order_sn'] ?? '');

check('下单后库存扣减 5→3', goodsStock($atoken, $goodsId) === 3);

// 超库存下单拒绝
$r = req('POST', '/api/order/create', ['goods_id' => $goodsId, 'num' => 99, 'address_id' => $addrId], $mtoken);
check('超库存下单被拒', ($r['json']['code'] ?? 1) !== 0 && str_contains((string) ($r['json']['msg'] ?? ''), '库存'), $r['raw']);

// 下架商品下单拒绝
$r = req('POST', '/api/order/create', ['goods_id' => $offGoodsId, 'num' => 1, 'address_id' => $addrId], $mtoken);
check('下架商品下单被拒', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

// 无效地址下单拒绝
$r = req('POST', '/api/order/create', ['goods_id' => $goodsId, 'num' => 1, 'address_id' => 999999], $mtoken);
check('无效地址下单被拒', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

// ---- 5. 我的订单列表/详情（待支付） ----
$r = req('GET', '/api/order/list?status=0', null, $mtoken);
$one = null;
foreach ($r['json']['data']['list'] ?? [] as $o) {
    if ((int) $o['id'] === $order1) $one = $o;
}
check('我的订单列表（含商品快照）', null !== $one && ($one['status'] ?? -1) === 0
    && !empty($one['goods']) && ($one['goods'][0]['num'] ?? 0) === 2
    && abs((float) ($one['goods'][0]['price'] ?? 0) - 19.9) < 0.001, $r['raw']);

$r = req('GET', '/api/order/detail?id=' . $order1, null, $mtoken);
check('订单详情（地址快照）', ($r['json']['code'] ?? 1) === 0
    && ($r['json']['data']['address']['name'] ?? '') === '张验收'
    && str_contains((string) ($r['json']['data']['address']['detail'] ?? ''), '科技园路1号'), $r['raw']);

// ---- 6. mock 支付 ----
$r = req('POST', '/api/order/pay', ['order_id' => $order1, 'pay_type' => 'wechat'], $mtoken);
check('pay mock 返回 {mock:true,status:1}', ($r['json']['code'] ?? 1) === 0
    && true === ($r['json']['data']['mock'] ?? null)
    && 1 === ($r['json']['data']['status'] ?? -1), $r['raw']);

$r = req('GET', '/api/order/detail?id=' . $order1, null, $mtoken);
check('支付后状态1 且记录 pay_time', ($r['json']['data']['status'] ?? -1) === 1
    && '' !== ($r['json']['data']['pay_time'] ?? ''), $r['raw']);
$payTime1 = (string) ($r['json']['data']['pay_time'] ?? '');

// ---- 7. 回调幂等 ----
$r = req('POST', '/api/pay/notify/mock', ['order_sn' => $orderSn1]);
check('mock 重复回调幂等（handled=false）', ($r['json']['code'] ?? 1) === 0 && false === ($r['json']['data']['handled'] ?? null), $r['raw']);

// 微信回调端点（两次都应成功返回 code=SUCCESS，且不重复处理）
$r = req('POST', '/api/pay/notify/wechat', ['out_trade_no' => $orderSn1, 'transaction_id' => 'T123']);
check('微信回调返回 code=SUCCESS', is_array($r['json']) && 'SUCCESS' === ($r['json']['code'] ?? ''), $r['raw']);
$r = req('POST', '/api/pay/notify/wechat', ['out_trade_no' => $orderSn1, 'transaction_id' => 'T123']);
check('微信重复回调仍返回 SUCCESS（幂等）', is_array($r['json']) && 'SUCCESS' === ($r['json']['code'] ?? ''), $r['raw']);

// 支付宝回调端点
$r = req('POST', '/api/pay/notify/alipay', ['out_trade_no' => $orderSn1, 'trade_no' => 'A123']);
check('支付宝回调返回 success', str_contains((string) $r['raw'], 'success'), $r['raw']);

$r = req('GET', '/api/order/detail?id=' . $order1, null, $mtoken);
check('重复回调后 pay_time 不变', ($r['json']['data']['pay_time'] ?? '') === $payTime1, $r['raw']);

// ---- 8. 后台列表/详情/统计/发货 ----
$r = req('GET', '/admin/order/list?page=1&pagesize=20', null, $atoken);
$one = null;
foreach ($r['json']['data']['list'] ?? [] as $o) {
    if ((int) $o['id'] === $order1) $one = $o;
}
check('后台订单列表（字段契约）', null !== $one
    && ($one['order_sn'] ?? '') === $orderSn1
    && ($one['member_phone'] ?? '') === '13800138000'
    && !empty($one['goods']) && isset($one['goods'][0]['name'], $one['goods'][0]['pic'], $one['goods'][0]['num'], $one['goods'][0]['price'])
    && isset($one['address']['name'], $one['address']['phone'], $one['address']['detail'])
    && isset($one['status_text'], $one['create_time']), $r['raw']);

$r = req('GET', '/admin/order/list?keyword=' . $orderSn1, null, $atoken);
check('后台按订单号搜索', ($r['json']['data']['total'] ?? -1) >= 1
    && (int) (($r['json']['data']['list'] ?? [])[0]['id'] ?? 0) === $order1, $r['raw']);

$r = req('GET', '/admin/order/list?keyword=' . rawurlencode('张验收'), null, $atoken);
check('后台按收件人搜索', ($r['json']['data']['total'] ?? 0) >= 1, $r['raw']);

$r = req('GET', '/admin/order/list?status=1', null, $atoken);
$allStatus1 = true;
$found = false;
foreach ($r['json']['data']['list'] ?? [] as $o) {
    if ((int) $o['status'] !== 1) $allStatus1 = false;
    if ((int) $o['id'] === $order1) $found = true;
}
check('后台按状态筛选', $allStatus1 && $found, $r['raw']);

$r = req('GET', '/admin/order/detail?id=' . $order1, null, $atoken);
check('后台订单详情（支付信息）', ($r['json']['code'] ?? 1) === 0
    && isset($r['json']['data']['pay_info']['pay_time'], $r['json']['data']['ship_info'])
    && ($r['json']['data']['pay_info']['pay_type'] ?? '') === 'wechat', $r['raw']);

$r = req('GET', '/admin/order/stat', null, $atoken);
check('订单统计（wait_ship>=1 且含 total）', ($r['json']['code'] ?? 1) === 0
    && ($r['json']['data']['wait_ship'] ?? 0) >= 1
    && isset($r['json']['data']['wait_pay'], $r['json']['data']['shipped'], $r['json']['data']['done'], $r['json']['data']['cancelled'], $r['json']['data']['total']), $r['raw']);

// 未支付订单不可发货（先造一个待支付单）
$r = req('POST', '/api/order/create', ['goods_id' => $goodsId, 'num' => 1, 'address_id' => $addrId], $mtoken);
$order2 = (int) ($r['json']['data']['id'] ?? 0);
$r = req('POST', '/admin/order/ship', ['id' => $order2, 'ship_company' => '顺丰', 'ship_sn' => 'SF123'], $atoken);
check('未支付订单不可发货', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

$r = req('POST', '/admin/order/ship', ['id' => $order1, 'ship_company' => '顺丰速运', 'ship_sn' => 'SF' . time()], $atoken);
check('后台发货成功', ($r['json']['code'] ?? 1) === 0, $r['raw']);

$r = req('GET', '/admin/order/detail?id=' . $order1, null, $atoken);
check('发货后状态2+物流信息', ($r['json']['data']['status'] ?? -1) === 2
    && ($r['json']['data']['ship_info']['ship_company'] ?? '') === '顺丰速运'
    && '' !== ($r['json']['data']['ship_info']['ship_sn'] ?? '')
    && '' !== ($r['json']['data']['ship_time'] ?? ''), $r['raw']);

// ---- 9. 确认收货 ----
$r = req('POST', '/api/order/confirm', ['id' => $order1], $mtoken);
check('确认收货成功', ($r['json']['code'] ?? 1) === 0, $r['raw']);
$r = req('POST', '/api/order/confirm', ['id' => $order1], $mtoken);
check('重复确认收货被拒', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

$r = req('GET', '/api/order/detail?id=' . $order1, null, $mtoken);
check('订单状态3 已完成', ($r['json']['data']['status'] ?? -1) === 3 && ($r['json']['data']['status_text'] ?? '') === '已完成', $r['raw']);

// ---- 10. 取消路径（回补库存） ----
check('取消前库存为 2', goodsStock($atoken, $goodsId) === 2);
$r = req('POST', '/api/order/cancel', ['id' => $order2], $mtoken);
check('取消待支付订单', ($r['json']['code'] ?? 1) === 0, $r['raw']);
check('取消后库存回补 2→3', goodsStock($atoken, $goodsId) === 3);
$r = req('POST', '/api/order/cancel', ['id' => $order2], $mtoken);
check('重复取消被拒（幂等）', ($r['json']['code'] ?? 1) !== 0, $r['raw']);
$r = req('GET', '/api/order/detail?id=' . $order2, null, $mtoken);
check('取消后状态4', ($r['json']['data']['status'] ?? -1) === 4, $r['raw']);

// 已支付订单不可取消
$r = req('POST', '/api/order/cancel', ['id' => $order1], $mtoken);
check('已完成订单不可取消', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

// ---- 11. order:cancel 超时取消命令 ----
$r = req('POST', '/api/order/create', ['goods_id' => $goodsId, 'num' => 1, 'address_id' => $addrId], $mtoken);
$order3 = (int) ($r['json']['data']['id'] ?? 0);
$orderSn3 = (string) ($r['json']['data']['order_sn'] ?? '');
check('下单3 库存 3→2', goodsStock($atoken, $goodsId) === 2);

// 将该订单改为已超时
$pdo = db();
$pdo->exec("UPDATE shop_order SET pay_expire_time = " . (time() - 10) . " WHERE id = " . $order3);
$pdo = null;

exec('"' . PHP_BIN . '" "' . dirname(__DIR__) . '\\think" order:cancel 2>&1', $out, $code);
$outStr = implode("\n", $out);
check('order:cancel 命令执行成功', 0 === $code && str_contains($outStr, '取消'), $outStr);

$r = req('GET', '/api/order/detail?id=' . $order3, null, $mtoken);
check('超时单被自动取消（状态4）', ($r['json']['data']['status'] ?? -1) === 4, $r['raw']);
check('超时取消后库存回补 2→3', goodsStock($atoken, $goodsId) === 3);

// 幂等：重复执行命令不再取消
exec('"' . PHP_BIN . '" "' . dirname(__DIR__) . '\\think" order:cancel 2>&1', $out2);
check('order:cancel 重复执行幂等（0笔）', str_contains(implode("\n", $out2), '取消 0 笔'));

// ---- 12. 跨租户隔离 ----
$pdo = db();
// 直接向 aid=2 插一笔订单，admin(aid=1) 不可见
$pdo->exec("INSERT INTO shop_order (aid, mid, ordernum, totalprice, createtime, status, linkman, tel) VALUES (2, 0, 'ISOLATE" . time() . "', 1.00, " . time() . ", 1, '隔离测试', '13800000000')");
$foreignOrderId = (int) $pdo->lastInsertId();
$pdo = null;

$r = req('GET', '/admin/order/list?keyword=' . rawurlencode('隔离测试'), null, $atoken);
check('跨租户订单列表不可见', 0 === ($r['json']['data']['total'] ?? -1), $r['raw']);
$r = req('GET', '/admin/order/detail?id=' . $foreignOrderId, null, $atoken);
check('跨租户订单详情 404', ($r['json']['code'] ?? 0) !== 0, $r['raw']);
$r = req('POST', '/admin/order/ship', ['id' => $foreignOrderId, 'ship_company' => 'x', 'ship_sn' => 'y'], $atoken);
check('跨租户发货被拒', ($r['json']['code'] ?? 0) !== 0, $r['raw']);

// 清理隔离测试订单
$pdo = db();
$pdo->exec("DELETE FROM shop_order WHERE id = " . $foreignOrderId);
$pdo = null;

// ---- 13. 未登录/越权 ----
$r = req('GET', '/api/order/list');
check('未登录访问订单 401', 401 === $r['status'], $r['raw']);
$r = req('GET', '/admin/order/list', null, $mtoken);
check('member token 访问 admin 订单 403', 403 === $r['status'], $r['raw']);

echo "\n========== 订单验收结果：{$pass} PASS / {$fail} FAIL ==========\n";
exit($fail > 0 ? 1 : 0);
