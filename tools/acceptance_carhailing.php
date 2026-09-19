<?php
// P6 网约车（租车预约）验收脚本（mock 模式）
// 用法：先 php think run -p 8080，再 php tools/acceptance_carhailing.php
// 覆盖：分类/车辆 CRUD 与删除保护 → 匿名列表详情 → 计价边界（<4h 拒绝 / 4h~24h 半天价 / ≥24h 按天 ceil）
//       → 车辆并发校验 → 支付（pay_order + 回调路由 + 幂等）→ 券积分叠加 → 取消返还 → 退款 → 后台完成 → 菜单

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
$pdo->exec("UPDATE carhailing_product SET deleted = 1 WHERE name LIKE '网约车验收%'");
$pdo->exec("UPDATE carhailing_category SET deleted = 1 WHERE name LIKE '网约车验收%'");
$pdo->exec("DELETE po FROM pay_order po INNER JOIN carhailing_order co ON co.id = po.biz_order_id WHERE po.order_type = 'carhailing'");
$pdo->exec("DELETE FROM carhailing_order WHERE product_name LIKE '网约车验收%'");
$pdo->exec("UPDATE coupon SET deleted = 1 WHERE name LIKE '网约车验收券%'");
$pdo->exec("DELETE cr FROM coupon_record cr LEFT JOIN coupon c ON c.id = cr.couponid WHERE c.name LIKE '网约车验收券%'");
$pdo->exec("UPDATE member SET score = 0 WHERE aid = 1 AND tel = '13800138000'");
$pdo = null;

// ---- 1. 登录 ----
$r = req('POST', '/api/member/login', ['phone' => '13800138000', 'password' => '123456']);
check('会员登录', ($r['json']['code'] ?? 1) === 0, $r['raw']);
$mtoken = (string) ($r['json']['data']['token'] ?? '');

$r = req('POST', '/admin/login', ['username' => 'tenant1', 'password' => '123456']);
check('tenant1 登录', ($r['json']['code'] ?? 1) === 0, $r['raw']);
$atoken = (string) ($r['json']['data']['token'] ?? '');

// ---- 2. 分类 CRUD ----
$r = req('POST', '/admin/carhailing/category/add', ['name' => '网约车验收分类_' . $TS, 'sort' => 9], $atoken);
check('新建分类', ($r['json']['code'] ?? 1) === 0 && ($r['json']['data']['id'] ?? 0) > 0, $r['raw']);
$catId = (int) ($r['json']['data']['id'] ?? 0);

$r = req('POST', '/admin/carhailing/category/add', ['name' => ''], $atoken);
check('分类空名称拒绝', ($r['json']['code'] ?? 0) !== 0, $r['raw']);

$r = req('POST', '/admin/carhailing/category/edit', ['id' => $catId, 'name' => '网约车验收分类改_' . $TS], $atoken);
check('编辑分类', ($r['json']['code'] ?? 1) === 0, $r['raw']);

$r = req('GET', '/admin/carhailing/category/list', null, $atoken);
$found = false;
foreach ($r['json']['data']['list'] ?? [] as $row) {
    if ((int) ($row['id'] ?? 0) === $catId && str_contains((string) ($row['name'] ?? ''), '改')) {
        $found = true;
    }
}
check('分类列表含改名后记录', $found, $r['raw']);

// ---- 3. 车辆 CRUD ----
$r = req('POST', '/admin/carhailing/product/add', [
    'name' => '网约车验收车A_' . $TS, 'category_id' => $catId, 'pic' => '/uploads/car_a.png',
    'price_day' => '200.00', 'price_half' => '120.00', 'car_num' => 1, 'desc' => '验收车A', 'status' => 1,
], $atoken);
check('新建车辆A（日租200 半天120 数量1）', ($r['json']['code'] ?? 1) === 0 && ($r['json']['data']['id'] ?? 0) > 0, $r['raw']);
$pidA = (int) ($r['json']['data']['id'] ?? 0);

$r = req('POST', '/admin/carhailing/product/add', [
    'name' => '网约车验收车B_' . $TS, 'category_id' => $catId,
    'price_day' => '300.00', 'price_half' => '180.00', 'car_num' => 2, 'status' => 1,
], $atoken);
$pidB = (int) ($r['json']['data']['id'] ?? 0);
check('新建车辆B（日租300 半天180 数量2）', $pidB > 0, $r['raw']);

$r = req('POST', '/admin/carhailing/category/delete', ['id' => $catId], $atoken);
check('删除有车辆的分类被拒绝', ($r['json']['code'] ?? 0) !== 0, $r['raw']);

$r = req('POST', '/admin/carhailing/product/edit', ['id' => $pidA, 'desc' => '验收车A描述改'], $atoken);
check('编辑车辆（desc）', ($r['json']['code'] ?? 1) === 0, $r['raw']);

$r = req('GET', '/admin/carhailing/product/list?keyword=' . rawurlencode('网约车验收车') . '&category_id=' . $catId, null, $atoken);
check('车辆列表筛选（keyword+category_id）', ($r['json']['data']['total'] ?? 0) === 2, $r['raw']);

$r = req('POST', '/admin/carhailing/product/status', ['id' => $pidB, 'status' => 0], $atoken);
check('下架车辆B', ($r['json']['code'] ?? 1) === 0, $r['raw']);

$r = req('GET', '/api/carhailing/list?aid=1&category_id=' . $catId);
$names = array_map(static fn ($x) => (string) ($x['name'] ?? ''), $r['json']['data']['list'] ?? []);
check('匿名列表只见上架车辆A', ($r['json']['code'] ?? 1) === 0 && in_array('网约车验收车A_' . $TS, $names, true)
    && !in_array('网约车验收车B_' . $TS, $names, true), $r['raw']);

req('POST', '/admin/carhailing/product/status', ['id' => $pidB, 'status' => 1], $atoken);

$r = req('GET', '/api/carhailing/detail?aid=1&id=' . $pidA);
check('匿名详情（契约键 desc/price_day/price_half/car_num）', ($r['json']['code'] ?? 1) === 0
    && abs((float) ($r['json']['data']['price_day'] ?? 0) - 200.0) < 0.001
    && abs((float) ($r['json']['data']['price_half'] ?? 0) - 120.0) < 0.001
    && (int) ($r['json']['data']['car_num'] ?? -1) === 1
    && str_contains((string) ($r['json']['data']['desc'] ?? ''), '改'), $r['raw']);

// ---- 4. 计价边界 ----
$t0 = $TS + 86400; // 明天起租

$r = req('POST', '/api/carhailing/order/create', ['product_id' => $pidA, 'start_time' => $t0, 'end_time' => $t0 + 3 * 3600], $mtoken);
check('计价：<4h 拒绝', ($r['json']['code'] ?? 0) !== 0, $r['raw']);

$r = req('POST', '/api/carhailing/order/create', ['product_id' => $pidA, 'start_time' => $t0, 'end_time' => $t0 + 4 * 3600], $mtoken);
check('计价：4h=半天价120（边界）', ($r['json']['code'] ?? 1) === 0
    && abs((float) ($r['json']['data']['amount'] ?? 0) - 120.0) < 0.001
    && abs((float) ($r['json']['data']['days'] ?? 0) - 0.5) < 0.001, $r['raw']);
$orderHalf = (int) ($r['json']['data']['id'] ?? 0);
req('POST', '/api/carhailing/order/cancel', ['order_id' => $orderHalf], $mtoken); // 释放车辆占位

$r = req('POST', '/api/carhailing/order/create', ['product_id' => $pidA, 'start_time' => $t0, 'end_time' => $t0 + 25 * 3600], $mtoken);
check('计价：25h 按2天 ceil×200=400', ($r['json']['code'] ?? 1) === 0
    && abs((float) ($r['json']['data']['amount'] ?? 0) - 400.0) < 0.001
    && abs((float) ($r['json']['data']['days'] ?? 0) - 2.0) < 0.001, $r['raw']);
$order25h = (int) ($r['json']['data']['id'] ?? 0);
req('POST', '/api/carhailing/order/cancel', ['order_id' => $order25h], $mtoken);

$r = req('POST', '/api/carhailing/order/create', ['product_id' => $pidA, 'start_time' => $t0, 'end_time' => $t0 + 48 * 3600], $mtoken);
check('计价：48h=2天400', ($r['json']['code'] ?? 1) === 0
    && abs((float) ($r['json']['data']['amount'] ?? 0) - 400.0) < 0.001, $r['raw']);
$order48h = (int) ($r['json']['data']['id'] ?? 0);
req('POST', '/api/carhailing/order/cancel', ['order_id' => $order48h], $mtoken);

// ---- 5. 车辆并发校验（车辆A car_num=1） ----
$r = req('POST', '/api/carhailing/order/create', ['product_id' => $pidA, 'start_time' => $t0, 'end_time' => $t0 + 24 * 3600], $mtoken);
check('并发：第一单占用（未支付也算占位）', ($r['json']['code'] ?? 1) === 0, $r['raw']);
$orderBusy = (int) ($r['json']['data']['id'] ?? 0);

$r = req('POST', '/api/carhailing/order/create', ['product_id' => $pidA, 'start_time' => $t0 + 3600, 'end_time' => $t0 + 25 * 3600], $mtoken);
check('并发：同期重叠第二单拒绝', ($r['json']['code'] ?? 0) !== 0, $r['raw']);

$r = req('POST', '/api/carhailing/order/create', ['product_id' => $pidA, 'start_time' => $t0 + 24 * 3600, 'end_time' => $t0 + 48 * 3600], $mtoken);
check('并发：不重叠时段可下单', ($r['json']['code'] ?? 1) === 0, $r['raw']);
$orderNoOverlap = (int) ($r['json']['data']['id'] ?? 0);
req('POST', '/api/carhailing/order/cancel', ['order_id' => $orderNoOverlap], $mtoken);

// ---- 6. 券与积分备货 ----
$r = req('POST', '/admin/coupon/add', [
    'name' => '网约车验收券_' . $TS, 'type' => 1, 'money' => '20.00', 'full_money' => '100.00',
    'total' => 5, 'per_limit' => 3, 'start_time' => $TS - 3600, 'end_time' => $TS + 86400 * 7,
], $atoken);
check('建满减券（满100减20）', ($r['json']['code'] ?? 1) === 0, $r['raw']);
$couponId = (int) ($r['json']['data']['id'] ?? 0);

$r = req('POST', '/api/coupon/receive', ['coupon_id' => $couponId], $mtoken);
check('领取券', ($r['json']['code'] ?? 1) === 0, $r['raw']);

$pdo = db();
$pdo->exec("INSERT INTO tenant_config (aid, `key`, value) VALUES (1,'score_switch','1'),(1,'score_ratio','100'),(1,'pay_score','1')
    ON DUPLICATE KEY UPDATE value = VALUES(value)");
$pdo->exec("UPDATE member SET score = 500 WHERE aid = 1 AND tel = '13800138000'");
$pdo = null;

// ---- 7. 券+积分叠加下单（租金200 → 券20 → 积分50抵0.5 → 实付179.5） ----
$r = req('POST', '/api/carhailing/order/cancel', ['order_id' => $orderBusy], $mtoken); // 释放车辆
check('取消未支付订单（释放占位）', ($r['json']['code'] ?? 1) === 0, $r['raw']);

$r = req('POST', '/api/carhailing/order/create', [
    'product_id' => $pidA, 'start_time' => $t0, 'end_time' => $t0 + 24 * 3600,
    'coupon_id' => $couponId, 'use_score' => 50,
], $mtoken);
check('券+积分叠加下单', ($r['json']['code'] ?? 1) === 0
    && abs((float) ($r['json']['data']['coupon_money'] ?? 0) - 20.0) < 0.001
    && abs((float) ($r['json']['data']['score_money'] ?? 0) - 0.5) < 0.001
    && abs((float) ($r['json']['data']['pay_amount'] ?? 0) - 179.5) < 0.001, $r['raw']);
$orderStack = (int) ($r['json']['data']['id'] ?? 0);

$r = req('POST', '/api/carhailing/order/cancel', ['order_id' => $orderStack], $mtoken);
check('取消券+积分订单', ($r['json']['code'] ?? 1) === 0, $r['raw']);

$pdo = db();
$score = (int) $pdo->query("SELECT score FROM member WHERE aid = 1 AND tel = '13800138000'")->fetchColumn();
$rid = (int) $pdo->query("SELECT id FROM coupon_record WHERE couponid = {$couponId} ORDER BY id DESC LIMIT 1")->fetchColumn();
$ridStatus = (int) $pdo->query("SELECT status FROM coupon_record WHERE id = {$rid}")->fetchColumn();
$ridOrder = (int) $pdo->query("SELECT order_id FROM coupon_record WHERE id = {$rid}")->fetchColumn();
$pdo = null;
check('取消返还积分（500 复原）', 500 === $score, 'score=' . $score);
check('取消释放券（未使用且解绑订单）', 0 === $ridStatus && 0 === $ridOrder, "status={$ridStatus} order_id={$ridOrder}");

// 再下单用于支付链路
$r = req('POST', '/api/carhailing/order/create', [
    'product_id' => $pidA, 'start_time' => $t0, 'end_time' => $t0 + 24 * 3600, 'coupon_id' => $couponId,
], $mtoken);
check('再次下单（用券，租金200 减20=180）', ($r['json']['code'] ?? 1) === 0
    && abs((float) ($r['json']['data']['pay_amount'] ?? 0) - 180.0) < 0.001, $r['raw']);
$orderPay = (int) ($r['json']['data']['id'] ?? 0);

// ---- 8. 支付与回调路由 ----
$r = req('POST', '/api/carhailing/order/pay', ['order_id' => $orderPay, 'pay_type' => 'wechat'], $mtoken);
check('mock 支付（pay_order 统一支付单）', ($r['json']['code'] ?? 1) === 0
    && true === ($r['json']['data']['mock'] ?? false)
    && str_starts_with((string) ($r['json']['data']['out_trade_no'] ?? ''), 'PT'), $r['raw']);
$outTradeNo = (string) ($r['json']['data']['out_trade_no'] ?? '');

$pdo = db();
$status = (int) $pdo->query("SELECT status FROM carhailing_order WHERE id = {$orderPay}")->fetchColumn();
$poStatus = (int) $pdo->query("SELECT status FROM pay_order WHERE out_trade_no = '{$outTradeNo}'")->fetchColumn();
$ridStatus = (int) $pdo->query("SELECT status FROM coupon_record WHERE id = {$rid}")->fetchColumn();
$pdo = null;
check('支付成功：订单状态1', 1 === $status, 'status=' . $status);
check('pay_order 状态1', 1 === $poStatus, 'status=' . $poStatus);
check('券核销（status=1）', 1 === $ridStatus, 'status=' . $ridStatus);

$r = req('POST', '/api/pay/notify/mock', ['order_sn' => $outTradeNo]);
check('重复回调幂等（handled=false）', ($r['json']['code'] ?? 1) === 0 && false === ($r['json']['data']['handled'] ?? true), $r['raw']);

$r = req('POST', '/api/carhailing/order/pay', ['order_id' => $orderPay, 'pay_type' => 'wechat'], $mtoken);
check('重复支付幂等（status=1）', ($r['json']['code'] ?? 1) === 0 && 1 === (int) ($r['json']['data']['status'] ?? 0), $r['raw']);

// ---- 9. 退款规则 ----
$r = req('POST', '/api/carhailing/order/refund', ['order_id' => $orderPay], $mtoken);
check('退款成功（已支付且未开始，mock）', ($r['json']['code'] ?? 1) === 0
    && true === ($r['json']['data']['success'] ?? false), $r['raw']);

$pdo = db();
$status = (int) $pdo->query("SELECT status FROM carhailing_order WHERE id = {$orderPay}")->fetchColumn();
$pdo = null;
check('退款后状态5', 5 === $status, 'status=' . $status);

$r = req('POST', '/api/carhailing/order/refund', ['order_id' => $orderPay], $mtoken);
check('已退款订单再退拒绝', ($r['json']['code'] ?? 0) !== 0, $r['raw']);

// ---- 10. 后台订单操作 ----
$r = req('POST', '/api/carhailing/order/create', ['product_id' => $pidB, 'start_time' => $t0, 'end_time' => $t0 + 24 * 3600], $mtoken);
$orderFinish = (int) ($r['json']['data']['id'] ?? 0);
req('POST', '/api/carhailing/order/pay', ['order_id' => $orderFinish, 'pay_type' => 'alipay'], $mtoken);

$r = req('GET', '/admin/carhailing/order/list?status=1&keyword=' . rawurlencode('网约车验收车'), null, $atoken);
$row0 = $r['json']['data']['list'][0] ?? [];
check('后台订单列表（含会员手机）', ($r['json']['data']['total'] ?? 0) >= 1
    && '13800138000' === (string) ($row0['member_phone'] ?? ''), $r['raw']);

$r = req('POST', '/admin/carhailing/order/finish', ['id' => $orderFinish], $atoken);
check('后台完成订单（已支付→完成）', ($r['json']['code'] ?? 1) === 0, $r['raw']);

$pdo = db();
$status = (int) $pdo->query("SELECT status FROM carhailing_order WHERE id = {$orderFinish}")->fetchColumn();
$pdo = null;
check('完成后状态3', 3 === $status, 'status=' . $status);

$r = req('POST', '/api/carhailing/order/create', ['product_id' => $pidB, 'start_time' => $t0 + 72 * 3600, 'end_time' => $t0 + 96 * 3600], $mtoken);
check('为车辆B补一单未支付', ($r['json']['code'] ?? 1) === 0, $r['raw']);
$orderOpen = (int) ($r['json']['data']['id'] ?? 0);

$r = req('POST', '/admin/carhailing/product/delete', ['id' => $pidB], $atoken);
check('删除有未完结订单的车辆被拒绝', ($r['json']['code'] ?? 0) !== 0, $r['raw']);

req('POST', '/api/carhailing/order/cancel', ['order_id' => $orderOpen], $mtoken);

$r = req('POST', '/admin/carhailing/product/delete', ['id' => $pidB], $atoken);
check('取消后可删除车辆（软删）', ($r['json']['code'] ?? 1) === 0, $r['raw']);

// ---- 11. 会员订单列表与鉴权 ----
$r = req('GET', '/api/carhailing/order/list?status=5', null, $mtoken);
check('会员订单列表（按状态筛选）', ($r['json']['code'] ?? 1) === 0
    && ($r['json']['data']['total'] ?? 0) >= 1, $r['raw']);

$r = req('GET', '/api/carhailing/order/list');
check('未登录访问订单列表拦截（401）', 401 === (int) ($r['status'] ?? 0), $r['raw']);

// ---- 12. 菜单 ----
$r = req('GET', '/admin/menu', null, $atoken);
$menuHit = false;
foreach ($r['json']['data'] ?? [] as $group) {
    if ('扩展' === ($group['name'] ?? '')) {
        // 三层嵌套：扩展 → 网约车管理/酒店管理/预约管理/餐饮管理 → 叶子项
        $subNames = array_map(static fn ($c) => (string) ($c['name'] ?? ''), $group['children'] ?? []);
        $leafPaths = [];
        foreach ($group['children'] ?? [] as $sub) {
            foreach ($sub['children'] ?? [] as $leaf) {
                $leafPaths[] = (string) ($leaf['path'] ?? '');
            }
        }
        $menuHit = ['网约车管理', '酒店管理', '预约管理', '餐饮管理'] === $subNames
            && in_array('/admin/carhailing/category', $leafPaths, true)
            && in_array('/admin/carhailing/product', $leafPaths, true)
            && in_array('/admin/carhailing/order', $leafPaths, true)
            && in_array('/admin/hotel/list', $leafPaths, true);
    }
}
check('菜单「行业应用」嵌套子组（网约车/酒店/预约/餐饮）', $menuHit, $r['raw']);

echo "\n==== 网约车验收结果：PASS={$pass} FAIL={$fail} ====\n";
exit($fail > 0 ? 1 : 0);
