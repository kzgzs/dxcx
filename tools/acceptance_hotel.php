<?php
// P6 酒店验收脚本（mock 模式）
// 用法：先 php think run -p 8080，再 php tools/acceptance_hotel.php
// 覆盖：酒店/房型 CRUD 与删除保护 → 匿名列表（min_price）详情 → 房态查询（available/nights/amount/deposit）
//       → 日期校验 → 并发房态（未支付占位/行锁）→ 券积分叠加（押金随单收）→ 支付与回调幂等
//       → 退款（入住日未到可退、当天入住拒退）→ 后台入住/完成 → 鉴权

declare(strict_types=1);

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
$d1 = date('Y-m-d', strtotime('+1 day')); // 明天
$d2 = date('Y-m-d', strtotime('+2 day'));
$d3 = date('Y-m-d', strtotime('+3 day'));
$d0 = date('Y-m-d'); // 今天

// ---- 0. 环境清理（脚本可独立重跑） ----
$pdo = db();
$pdo->exec("UPDATE hotel_room SET deleted = 1 WHERE name LIKE '酒店验收%'");
$pdo->exec("UPDATE hotel SET deleted = 1 WHERE name LIKE '酒店验收%'");
$pdo->exec("DELETE po FROM pay_order po INNER JOIN hotel_order ho ON ho.id = po.biz_order_id WHERE po.order_type = 'hotel'");
$pdo->exec("DELETE FROM hotel_order WHERE hotel_name LIKE '酒店验收%'");
$pdo->exec("UPDATE coupon SET deleted = 1 WHERE name LIKE '酒店验收券%'");
$pdo->exec("DELETE cr FROM coupon_record cr LEFT JOIN coupon c ON c.id = cr.couponid WHERE c.name LIKE '酒店验收券%'");
$pdo->exec("UPDATE member SET score = 0 WHERE aid = 1 AND tel = '13800138000'");
$pdo->exec("INSERT INTO tenant_config (aid, `key`, value) VALUES (1,'score_switch','1'),(1,'score_ratio','100'),(1,'pay_score','1')
    ON DUPLICATE KEY UPDATE value = VALUES(value)");
$pdo = null;

// ---- 1. 登录 ----
$r = req('POST', '/api/member/login', ['phone' => '13800138000', 'password' => '123456']);
check('会员登录', ($r['json']['code'] ?? 1) === 0, $r['raw']);
$mtoken = (string) ($r['json']['data']['token'] ?? '');

$r = req('POST', '/admin/login', ['username' => 'tenant1', 'password' => '123456']);
check('tenant1 登录', ($r['json']['code'] ?? 1) === 0, $r['raw']);
$atoken = (string) ($r['json']['data']['token'] ?? '');

// ---- 2. 酒店 CRUD ----
$r = req('POST', '/admin/hotel/add', [
    'name' => '酒店验收大酒店_' . $TS, 'star' => 5, 'province' => '广东省', 'city' => '深圳市',
    'district' => '南山区', 'address' => '验收路1号', 'lng' => 113.93, 'lat' => 22.53,
    'pic' => '/uploads/hotel.png', 'contacts' => '验收联系人', 'phone' => '13800138000',
    'desc' => '验收酒店描述', 'status' => 1,
], $atoken);
check('新建酒店（5星）', ($r['json']['code'] ?? 1) === 0 && ($r['json']['data']['id'] ?? 0) > 0, $r['raw']);
$hotelId = (int) ($r['json']['data']['id'] ?? 0);

$r = req('POST', '/admin/hotel/add', ['name' => ''], $atoken);
check('酒店空名称拒绝', ($r['json']['code'] ?? 0) !== 0, $r['raw']);

$r = req('POST', '/admin/hotel/edit', ['id' => $hotelId, 'contacts' => '验收联系人改'], $atoken);
check('编辑酒店', ($r['json']['code'] ?? 1) === 0, $r['raw']);

$r = req('GET', '/admin/hotel/list?keyword=' . rawurlencode('酒店验收大酒店') . '&status=1', null, $atoken);
check('酒店列表筛选（keyword+status）', ($r['json']['data']['total'] ?? 0) === 1
    && str_contains((string) ($r['json']['data']['list'][0]['contacts'] ?? ''), '改'), $r['raw']);

// ---- 3. 房型 CRUD ----
$r = req('POST', '/admin/hotel/room/add', [
    'hotel_id' => $hotelId, 'name' => '酒店验收豪华房_' . $TS, 'bed_type' => '大床', 'area' => 40,
    'price' => '200.00', 'deposit' => '50.00', 'room_num' => 1, 'pic' => '/uploads/room.png',
    'desc' => '验收房型', 'status' => 1,
], $atoken);
check('新建房型（200/晚 押金50 数量1）', ($r['json']['code'] ?? 1) === 0 && ($r['json']['data']['id'] ?? 0) > 0, $r['raw']);
$roomId = (int) ($r['json']['data']['id'] ?? 0);

$r = req('POST', '/admin/hotel/room/add', ['hotel_id' => $hotelId, 'name' => 'x', 'price' => '100', 'room_num' => 0], $atoken);
check('房型数量0拒绝', ($r['json']['code'] ?? 0) !== 0, $r['raw']);

$r = req('POST', '/admin/hotel/delete', ['id' => $hotelId], $atoken);
check('删除有房型的酒店被拒绝', ($r['json']['code'] ?? 0) !== 0, $r['raw']);

$r = req('POST', '/admin/hotel/room/edit', ['id' => $roomId, 'bed_type' => '双床'], $atoken);
check('编辑房型', ($r['json']['code'] ?? 1) === 0, $r['raw']);

$r = req('GET', '/admin/hotel/room/list?hotel_id=' . $hotelId, null, $atoken);
check('房型列表（按酒店）', ($r['json']['data']['total'] ?? 0) === 1
    && '双床' === (string) ($r['json']['data']['list'][0]['bed_type'] ?? ''), $r['raw']);

// ---- 4. 匿名接口（列表 min_price / 详情 / 停业过滤） ----
$r = req('GET', '/api/hotel/list?aid=1&star=5&keyword=' . rawurlencode('酒店验收'));
$row0 = $r['json']['data']['list'][0] ?? [];
check('匿名列表（star+keyword，含 min_price=200）', ($r['json']['code'] ?? 1) === 0
    && (int) ($row0['id'] ?? 0) === $hotelId
    && abs((float) ($row0['min_price'] ?? 0) - 200.0) < 0.001, $r['raw']);

req('POST', '/admin/hotel/status', ['id' => $hotelId, 'status' => 0], $atoken);
$r = req('GET', '/api/hotel/list?aid=1&keyword=' . rawurlencode('酒店验收'));
$ids = array_map(static fn ($x) => (int) ($x['id'] ?? 0), $r['json']['data']['list'] ?? []);
check('停业酒店不出现在匿名列表', !in_array($hotelId, $ids, true), $r['raw']);
req('POST', '/admin/hotel/status', ['id' => $hotelId, 'status' => 1], $atoken);

$r = req('GET', '/api/hotel/detail?aid=1&id=' . $hotelId);
check('匿名详情（含房型列表）', ($r['json']['code'] ?? 1) === 0
    && count($r['json']['data']['rooms'] ?? []) === 1
    && abs((float) ($r['json']['data']['rooms'][0]['price'] ?? 0) - 200.0) < 0.001, $r['raw']);

// ---- 5. 房态查询 ----
$r = req('GET', '/api/hotel/room/available?aid=1&room_id=' . $roomId . '&checkin=' . $d1 . '&checkout=' . $d3);
check('房态查询（available=1 nights=2 amount=400 deposit=50）', ($r['json']['code'] ?? 1) === 0
    && 1 === (int) ($r['json']['data']['available'] ?? -1)
    && 2 === (int) ($r['json']['data']['nights'] ?? -1)
    && abs((float) ($r['json']['data']['amount'] ?? 0) - 400.0) < 0.001
    && abs((float) ($r['json']['data']['deposit'] ?? 0) - 50.0) < 0.001, $r['raw']);

$r = req('GET', '/api/hotel/room/available?aid=1&room_id=' . $roomId . '&checkin=' . $d3 . '&checkout=' . $d1);
check('房态：离店早于入住拒绝', ($r['json']['code'] ?? 0) !== 0, $r['raw']);

$r = req('GET', '/api/hotel/room/available?aid=1&room_id=' . $roomId . '&checkin=' . date('Y-m-d', strtotime('-1 day')) . '&checkout=' . $d1);
check('房态：入住早于今天拒绝', ($r['json']['code'] ?? 0) !== 0, $r['raw']);

// ---- 6. 下单日期校验 ----
$r = req('POST', '/api/hotel/order/create', [
    'room_id' => $roomId, 'checkin_date' => $d2, 'checkout_date' => $d1, 'name' => '验收人', 'phone' => '13800138000',
], $mtoken);
check('下单：checkout<=checkin 拒绝', ($r['json']['code'] ?? 0) !== 0, $r['raw']);

$r = req('POST', '/api/hotel/order/create', [
    'room_id' => $roomId, 'checkin_date' => date('Y-m-d', strtotime('-1 day')), 'checkout_date' => $d1, 'name' => '验收人', 'phone' => '13800138000',
], $mtoken);
check('下单：入住早于今天拒绝', ($r['json']['code'] ?? 0) !== 0, $r['raw']);

$r = req('POST', '/api/hotel/order/create', [
    'room_id' => $roomId, 'checkin_date' => $d1, 'checkout_date' => $d3, 'name' => '验收人', 'phone' => '13800138000',
], $mtoken);
check('下单成功（2晚 房费400+押金50=实付450）', ($r['json']['code'] ?? 1) === 0
    && abs((float) ($r['json']['data']['amount'] ?? 0) - 400.0) < 0.001
    && abs((float) ($r['json']['data']['deposit_total'] ?? 0) - 50.0) < 0.001
    && abs((float) ($r['json']['data']['pay_amount'] ?? 0) - 450.0) < 0.001
    && 2 === (int) ($r['json']['data']['nights'] ?? 0), $r['raw']);
$order1 = (int) ($r['json']['data']['id'] ?? 0);

// ---- 7. 并发房态（未支付订单也占房态） ----
$r = req('GET', '/api/hotel/room/available?aid=1&room_id=' . $roomId . '&checkin=' . $d1 . '&checkout=' . $d3);
check('房态：未支付订单占位 available=0', 0 === (int) ($r['json']['data']['available'] ?? -1), $r['raw']);

$r = req('POST', '/api/hotel/order/create', [
    'room_id' => $roomId, 'checkin_date' => $d2, 'checkout_date' => $d3, 'name' => '验收人2', 'phone' => '13800138000',
], $mtoken);
check('并发：重叠日期段第二单拒绝', ($r['json']['code'] ?? 0) !== 0, $r['raw']);

$r = req('POST', '/api/hotel/order/create', [
    'room_id' => $roomId, 'checkin_date' => $d3, 'checkout_date' => date('Y-m-d', strtotime('+4 day')), 'name' => '验收人3', 'phone' => '13800138000',
], $mtoken);
check('并发：不重叠日期段可下单', ($r['json']['code'] ?? 1) === 0, $r['raw']);
$orderNoOverlap = (int) ($r['json']['data']['id'] ?? 0);
req('POST', '/api/hotel/order/cancel', ['order_id' => $orderNoOverlap], $mtoken);

// ---- 8. 取消（释放券+返还积分） ----
$r = req('POST', '/api/hotel/order/cancel', ['order_id' => $order1], $mtoken);
check('取消未支付订单', ($r['json']['code'] ?? 1) === 0, $r['raw']);

$r = req('GET', '/api/hotel/room/available?aid=1&room_id=' . $roomId . '&checkin=' . $d1 . '&checkout=' . $d3);
check('取消后房态恢复 available=1', 1 === (int) ($r['json']['data']['available'] ?? -1), $r['raw']);

// ---- 9. 券+积分叠加（房费400 满300减50 + 积分50抵0.5 + 押金50 = 399.5） ----
$r = req('POST', '/admin/coupon/add', [
    'name' => '酒店验收券_' . $TS, 'type' => 1, 'money' => '50.00', 'full_money' => '300.00',
    'total' => 5, 'per_limit' => 3, 'start_time' => $TS - 3600, 'end_time' => $TS + 86400 * 7,
], $atoken);
check('建满减券（满300减50）', ($r['json']['code'] ?? 1) === 0, $r['raw']);
$couponId = (int) ($r['json']['data']['id'] ?? 0);

$r = req('POST', '/api/coupon/receive', ['coupon_id' => $couponId], $mtoken);
check('领取券', ($r['json']['code'] ?? 1) === 0, $r['raw']);

$pdo = db();
$pdo->exec("UPDATE member SET score = 500 WHERE aid = 1 AND tel = '13800138000'");
$pdo = null;

$r = req('POST', '/api/hotel/order/create', [
    'room_id' => $roomId, 'checkin_date' => $d1, 'checkout_date' => $d3,
    'name' => '验收人', 'phone' => '13800138000', 'coupon_id' => $couponId, 'use_score' => 50,
], $mtoken);
check('券+积分叠加下单（400-50-0.5+50押金=399.5）', ($r['json']['code'] ?? 1) === 0
    && abs((float) ($r['json']['data']['coupon_money'] ?? 0) - 50.0) < 0.001
    && abs((float) ($r['json']['data']['score_money'] ?? 0) - 0.5) < 0.001
    && abs((float) ($r['json']['data']['pay_amount'] ?? 0) - 399.5) < 0.001, $r['raw']);
$orderStack = (int) ($r['json']['data']['id'] ?? 0);

$r = req('POST', '/api/hotel/order/cancel', ['order_id' => $orderStack], $mtoken);
check('取消券+积分订单', ($r['json']['code'] ?? 1) === 0, $r['raw']);

$pdo = db();
$score = (int) $pdo->query("SELECT score FROM member WHERE aid = 1 AND tel = '13800138000'")->fetchColumn();
$rid = (int) $pdo->query("SELECT id FROM coupon_record WHERE couponid = {$couponId} ORDER BY id DESC LIMIT 1")->fetchColumn();
$ridStatus = (int) $pdo->query("SELECT status FROM coupon_record WHERE id = {$rid}")->fetchColumn();
$ridOrder = (int) $pdo->query("SELECT order_id FROM coupon_record WHERE id = {$rid}")->fetchColumn();
$pdo = null;
check('取消返还积分（500 复原）', 500 === $score, 'score=' . $score);
check('取消释放券（未使用且解绑订单）', 0 === $ridStatus && 0 === $ridOrder, "status={$ridStatus} order_id={$ridOrder}");

// ---- 10. 支付与回调路由 ----
$r = req('POST', '/api/hotel/order/create', [
    'room_id' => $roomId, 'checkin_date' => $d1, 'checkout_date' => $d3,
    'name' => '验收人', 'phone' => '13800138000', 'coupon_id' => $couponId,
], $mtoken);
check('再次下单（用券 400-50+50押金=400）', ($r['json']['code'] ?? 1) === 0
    && abs((float) ($r['json']['data']['pay_amount'] ?? 0) - 400.0) < 0.001, $r['raw']);
$orderPay = (int) ($r['json']['data']['id'] ?? 0);

$r = req('POST', '/api/hotel/order/pay', ['order_id' => $orderPay, 'pay_type' => 'wechat'], $mtoken);
check('mock 支付（pay_order 统一支付单）', ($r['json']['code'] ?? 1) === 0
    && true === ($r['json']['data']['mock'] ?? false)
    && str_starts_with((string) ($r['json']['data']['out_trade_no'] ?? ''), 'PT'), $r['raw']);
$outTradeNo = (string) ($r['json']['data']['out_trade_no'] ?? '');

$pdo = db();
$status = (int) $pdo->query("SELECT status FROM hotel_order WHERE id = {$orderPay}")->fetchColumn();
$poStatus = (int) $pdo->query("SELECT status FROM pay_order WHERE out_trade_no = '{$outTradeNo}'")->fetchColumn();
$ridStatus = (int) $pdo->query("SELECT status FROM coupon_record WHERE id = {$rid}")->fetchColumn();
$pdo = null;
check('支付成功：订单状态1（已预订）', 1 === $status, 'status=' . $status);
check('pay_order 状态1', 1 === $poStatus, 'status=' . $poStatus);
check('券核销（status=1）', 1 === $ridStatus, 'status=' . $ridStatus);

$r = req('POST', '/api/pay/notify/mock', ['order_sn' => $outTradeNo]);
check('重复回调幂等（handled=false）', ($r['json']['code'] ?? 1) === 0 && false === ($r['json']['data']['handled'] ?? true), $r['raw']);

// ---- 11. 退款规则 ----
$r = req('POST', '/api/hotel/order/refund', ['order_id' => $orderPay], $mtoken);
check('退款成功（已预订且入住日未到，退实付全额含押金 mock）', ($r['json']['code'] ?? 1) === 0
    && true === ($r['json']['data']['success'] ?? false), $r['raw']);

$pdo = db();
$status = (int) $pdo->query("SELECT status FROM hotel_order WHERE id = {$orderPay}")->fetchColumn();
$pdo = null;
check('退款后状态5', 5 === $status, 'status=' . $status);

// 当天入住订单：支付后不可退
$r = req('POST', '/api/hotel/order/create', [
    'room_id' => $roomId, 'checkin_date' => $d0, 'checkout_date' => $d1, 'name' => '验收人', 'phone' => '13800138000',
], $mtoken);
$orderToday = (int) ($r['json']['data']['id'] ?? 0);
req('POST', '/api/hotel/order/pay', ['order_id' => $orderToday, 'pay_type' => 'alipay'], $mtoken);

$r = req('POST', '/api/hotel/order/refund', ['order_id' => $orderToday], $mtoken);
check('当天入住订单不可退', ($r['json']['code'] ?? 0) !== 0, $r['raw']);

// ---- 12. 后台订单操作 ----
$r = req('GET', '/admin/hotel/order/list?status=1&keyword=' . rawurlencode('验收人'), null, $atoken);
$row0 = $r['json']['data']['list'][0] ?? [];
check('后台订单列表（含入住人/手机）', ($r['json']['data']['total'] ?? 0) >= 1
    && '验收人' === (string) ($row0['name'] ?? ''), $r['raw']);

$r = req('POST', '/admin/hotel/order/checkin', ['id' => $orderToday], $atoken);
check('办理入住（已预订→已入住）', ($r['json']['code'] ?? 1) === 0, $r['raw']);

$pdo = db();
$status = (int) $pdo->query("SELECT status FROM hotel_order WHERE id = {$orderToday}")->fetchColumn();
$pdo = null;
check('入住后状态2', 2 === $status, 'status=' . $status);

$r = req('POST', '/admin/hotel/order/checkin', ['id' => $orderToday], $atoken);
check('已入住重复办理拒绝', ($r['json']['code'] ?? 0) !== 0, $r['raw']);

$r = req('POST', '/admin/hotel/order/finish', ['id' => $orderToday], $atoken);
check('完成订单（已入住→完成）', ($r['json']['code'] ?? 1) === 0, $r['raw']);

$pdo = db();
$status = (int) $pdo->query("SELECT status FROM hotel_order WHERE id = {$orderToday}")->fetchColumn();
$pdo = null;
check('完成后状态3', 3 === $status, 'status=' . $status);

// ---- 13. 房型删除保护 ----
$r = req('POST', '/api/hotel/order/create', [
    'room_id' => $roomId, 'checkin_date' => date('Y-m-d', strtotime('+5 day')), 'checkout_date' => date('Y-m-d', strtotime('+6 day')),
    'name' => '验收人', 'phone' => '13800138000',
], $mtoken);
$orderOpen = (int) ($r['json']['data']['id'] ?? 0);

$r = req('POST', '/admin/hotel/room/delete', ['id' => $roomId], $atoken);
check('删除有未完结订单的房型被拒绝', ($r['json']['code'] ?? 0) !== 0, $r['raw']);

req('POST', '/api/hotel/order/cancel', ['order_id' => $orderOpen], $mtoken);

$r = req('POST', '/admin/hotel/room/delete', ['id' => $roomId], $atoken);
check('取消后可删除房型（软删）', ($r['json']['code'] ?? 1) === 0, $r['raw']);

// ---- 14. 会员订单列表与鉴权 ----
$r = req('GET', '/api/hotel/order/list?status=5', null, $mtoken);
check('会员订单列表（按状态筛选）', ($r['json']['code'] ?? 1) === 0
    && ($r['json']['data']['total'] ?? 0) >= 1, $r['raw']);

$r = req('GET', '/api/hotel/order/list');
check('未登录访问订单列表拦截（401）', 401 === (int) ($r['status'] ?? 0), $r['raw']);

echo "\n==== 酒店验收结果：PASS={$pass} FAIL={$fail} ====\n";
exit($fail > 0 ? 1 : 0);
