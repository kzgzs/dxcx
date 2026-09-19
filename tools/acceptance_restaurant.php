<?php
// P6 餐饮堂食一期验收脚本（mock 模式）
// 用法：先 php think run -p 8080，再 php tools/acceptance_restaurant.php
// 覆盖：区域/桌台/分类/菜品 CRUD 与删除保护 → 匿名 info(店铺+区域桌台树)/菜品列表
//       → 堂食下单多商品计价（下架拒绝/不存在拒绝）→ 桌台 busy 推导 → 支付（pay_order）
//       → 完成/取消（释放桌台）→ 券积分叠加 → 后台订单列表 goods 数组 → 菜单无关（yuyue 脚本已断言）

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
$pdo->exec("DELETE po FROM pay_order po INNER JOIN restaurant_shop_order o ON o.id = po.biz_order_id WHERE po.order_type = 'restaurant'");
$pdo->exec("DELETE g FROM restaurant_shop_order_goods g WHERE g.name LIKE '堂食验收%'");
$pdo->exec("DELETE FROM restaurant_shop_order WHERE ordernum LIKE 'RS%' AND tableid IN (SELECT id FROM restaurant_table WHERE name LIKE '堂食验收%')");
$pdo->exec("DELETE FROM restaurant_table WHERE name LIKE '堂食验收%'");
$pdo->exec("DELETE FROM restaurant_area WHERE name LIKE '堂食验收%'");
$pdo->exec("DELETE FROM restaurant_product_category WHERE name LIKE '堂食验收%'");
$pdo->exec("UPDATE restaurant_product SET is_delete = 1 WHERE name LIKE '堂食验收%'");
$pdo->exec("UPDATE coupon SET deleted = 1 WHERE name LIKE '堂食验收券%'");
$pdo->exec("DELETE cr FROM coupon_record cr LEFT JOIN coupon c ON c.id = cr.couponid WHERE c.name LIKE '堂食验收券%'");
$pdo->exec("UPDATE member SET score = 0 WHERE aid = 1 AND tel = '13800138000'");
$pdo->exec("INSERT INTO tenant_config (aid,`key`,value) VALUES (1,'score_switch','1'),(1,'score_ratio','100'),(1,'pay_score','1') ON DUPLICATE KEY UPDATE value = VALUES(value)");
$pdo = null;

// ---- 1. 登录 ----
$r = req('POST', '/api/member/login', ['phone' => '13800138000', 'password' => '123456']);
check('会员登录', ($r['json']['code'] ?? 1) === 0, $r['raw']);
$mtoken = (string) ($r['json']['data']['token'] ?? '');

$r = req('POST', '/admin/login', ['username' => 'tenant1', 'password' => '123456']);
check('tenant1 登录', ($r['json']['code'] ?? 1) === 0, $r['raw']);
$atoken = (string) ($r['json']['data']['token'] ?? '');

// ---- 2. 区域 CRUD ----
$r = req('POST', '/admin/restaurant/area/add', ['name' => '堂食验收大厅_' . $TS, 'sort' => 9], $atoken);
check('新建区域', ($r['json']['code'] ?? 1) === 0 && ($r['json']['data']['id'] ?? 0) > 0, $r['raw']);
$areaId = (int) ($r['json']['data']['id'] ?? 0);

$r = req('POST', '/admin/restaurant/area/add', ['name' => ''], $atoken);
check('区域空名称拒绝', ($r['json']['code'] ?? 0) !== 0, $r['raw']);

$r = req('POST', '/admin/restaurant/area/edit', ['id' => $areaId, 'name' => '堂食验收大厅改_' . $TS], $atoken);
check('编辑区域', ($r['json']['code'] ?? 1) === 0, $r['raw']);

$r = req('GET', '/admin/restaurant/area/list', null, $atoken);
$found = false;
foreach ($r['json']['data']['list'] ?? [] as $row) {
    if ((int) ($row['id'] ?? 0) === $areaId && str_contains((string) ($row['name'] ?? ''), '改')) {
        $found = true;
    }
}
check('区域列表含改名后记录', $found, $r['raw']);

// ---- 3. 桌台 CRUD ----
$r = req('POST', '/admin/restaurant/table/add', ['area_id' => $areaId, 'name' => '堂食验收桌A_' . $TS, 'persons' => 4, 'sort' => 1], $atoken);
check('新建桌台A', ($r['json']['code'] ?? 1) === 0 && ($r['json']['data']['id'] ?? 0) > 0, $r['raw']);
$tidA = (int) ($r['json']['data']['id'] ?? 0);

$r = req('POST', '/admin/restaurant/table/add', ['area_id' => $areaId, 'name' => '堂食验收桌B_' . $TS, 'persons' => 2], $atoken);
$tidB = (int) ($r['json']['data']['id'] ?? 0);
check('新建桌台B', $tidB > 0, $r['raw']);

$r = req('POST', '/admin/restaurant/table/add', ['name' => ''], $atoken);
check('桌台空桌号拒绝', ($r['json']['code'] ?? 0) !== 0, $r['raw']);

// edit 空值不修改：仅改 persons
$r = req('POST', '/admin/restaurant/table/edit', ['id' => $tidA, 'persons' => 6, 'name' => ''], $atoken);
check('编辑桌台（空值不修改）', ($r['json']['code'] ?? 1) === 0, $r['raw']);

$r = req('GET', '/admin/restaurant/table/list?area_id=' . $areaId, null, $atoken);
$tableOk = ($r['json']['data']['total'] ?? 0) === 2;
$rowOk = false;
foreach ($r['json']['data']['list'] ?? [] as $row) {
    if ((int) ($row['id'] ?? 0) === $tidA) {
        $rowOk = '堂食验收桌A_' . $TS === ($row['name'] ?? '')
            && $areaId === (int) ($row['area_id'] ?? 0)
            && str_contains((string) ($row['area_name'] ?? ''), '堂食验收大厅改')
            && 6 === (int) ($row['persons'] ?? 0)
            && array_key_exists('qrcode_url', $row)
            && array_key_exists('sort', $row)
            && 0 === (int) ($row['busy'] ?? -1);
    }
}
check('桌台列表筛选（area_id）+字段齐全+busy=0', $tableOk && $rowOk, $r['raw']);

// ---- 4. 菜品分类 CRUD ----
$r = req('POST', '/admin/restaurant/category/add', ['name' => '堂食验收分类_' . $TS, 'sort' => 5], $atoken);
check('新建菜品分类', ($r['json']['code'] ?? 1) === 0 && ($r['json']['data']['id'] ?? 0) > 0, $r['raw']);
$catId = (int) ($r['json']['data']['id'] ?? 0);

$r = req('POST', '/admin/restaurant/category/add', ['name' => ''], $atoken);
check('分类空名称拒绝', ($r['json']['code'] ?? 0) !== 0, $r['raw']);

$r = req('POST', '/admin/restaurant/category/edit', ['id' => $catId, 'name' => '堂食验收分类改_' . $TS], $atoken);
check('编辑菜品分类', ($r['json']['code'] ?? 1) === 0, $r['raw']);

// ---- 5. 菜品 CRUD ----
$r = req('POST', '/admin/restaurant/product/add', [
    'name' => '堂食验收菜A_' . $TS, 'category_id' => $catId, 'pic' => '/uploads/rs_a.png',
    'price' => '68.00', 'desc' => '验收菜A', 'status' => 1,
], $atoken);
check('新建菜品A（68元）', ($r['json']['code'] ?? 1) === 0 && ($r['json']['data']['id'] ?? 0) > 0, $r['raw']);
$pidA = (int) ($r['json']['data']['id'] ?? 0);

$r = req('POST', '/admin/restaurant/product/add', ['name' => '堂食验收菜B_' . $TS, 'category_id' => $catId, 'price' => '32.00'], $atoken);
$pidB = (int) ($r['json']['data']['id'] ?? 0);
check('新建菜品B（32元）', $pidB > 0, $r['raw']);

$r = req('POST', '/admin/restaurant/category/delete', ['id' => $catId], $atoken);
check('删除有菜品的分类被拒绝', ($r['json']['code'] ?? 0) !== 0, $r['raw']);

// edit 空值不修改：仅改 desc
$r = req('POST', '/admin/restaurant/product/edit', ['id' => $pidA, 'desc' => '验收菜A描述改', 'name' => '', 'price' => ''], $atoken);
check('编辑菜品（空值不修改）', ($r['json']['code'] ?? 1) === 0, $r['raw']);

$r = req('GET', '/admin/restaurant/product/list?keyword=' . rawurlencode('堂食验收菜') . '&category_id=' . $catId, null, $atoken);
$listOk = ($r['json']['data']['total'] ?? 0) === 2;
$editOk = false;
foreach ($r['json']['data']['list'] ?? [] as $row) {
    if ((int) ($row['id'] ?? 0) === $pidA) {
        $editOk = '堂食验收菜A_' . $TS === ($row['name'] ?? '')
            && '验收菜A描述改' === ($row['desc'] ?? '')
            && 68.0 === (float) ($row['price'] ?? 0);
    }
}
check('菜品列表筛选（keyword+category_id）', $listOk, $r['raw']);
check('编辑后 name/price 未被空值覆盖', $editOk, $r['raw']);

$r = req('POST', '/admin/restaurant/product/status', ['id' => $pidB, 'status' => 0], $atoken);
check('下架菜品B', ($r['json']['code'] ?? 1) === 0, $r['raw']);

// ---- 6. 匿名浏览（白名单） ----
$r = req('GET', '/api/restaurant/info?aid=1');
$infoOk = '' !== ($r['json']['data']['shop']['name'] ?? '');
$treeOk = false;
foreach ($r['json']['data']['areas'] ?? [] as $area) {
    if ((int) ($area['id'] ?? 0) === $areaId) {
        $names = array_map(static fn ($t) => (string) ($t['name'] ?? ''), $area['tables'] ?? []);
        $treeOk = in_array('堂食验收桌A_' . $TS, $names, true) && in_array('堂食验收桌B_' . $TS, $names, true);
    }
}
check('店铺信息+区域桌台树', $infoOk && $treeOk, $r['raw']);

$r = req('GET', '/api/restaurant/product/list?aid=1&category_id=' . $catId);
$names = array_map(static fn ($x) => (string) ($x['name'] ?? ''), $r['json']['data']['list'] ?? []);
check('匿名菜品列表只见上架菜品A', ($r['json']['code'] ?? 1) === 0 && in_array('堂食验收菜A_' . $TS, $names, true)
    && !in_array('堂食验收菜B_' . $TS, $names, true), $r['raw']);

// ---- 7. 下单校验 + 多商品计价 ----
$r = req('POST', '/api/restaurant/order/create', [
    'table_id' => $tidA, 'goods' => [['product_id' => $pidA, 'num' => 1], ['product_id' => $pidB, 'num' => 1]],
], $mtoken);
check('下架菜品下单拒绝', ($r['json']['code'] ?? 0) !== 0, $r['raw']);

$r = req('POST', '/api/restaurant/order/create', [
    'table_id' => $tidA, 'goods' => [['product_id' => 9999999, 'num' => 1]],
], $mtoken);
check('不存在菜品下单拒绝', ($r['json']['code'] ?? 0) !== 0, $r['raw']);

$r = req('POST', '/api/restaurant/order/create', ['table_id' => $tidA, 'goods' => []], $mtoken);
check('空购物车下单拒绝', ($r['json']['code'] ?? 0) !== 0, $r['raw']);

$r = req('POST', '/admin/restaurant/product/status', ['id' => $pidB, 'status' => 1], $atoken);
check('重新上架菜品B', ($r['json']['code'] ?? 1) === 0, $r['raw']);

$r = req('POST', '/api/restaurant/order/create', [
    'table_id' => $tidA, 'goods' => [['product_id' => $pidA, 'num' => 2], ['product_id' => $pidB, 'num' => 1]],
], $mtoken);
check('多商品下单（68×2+32=168）', ($r['json']['code'] ?? 1) === 0
    && 168.0 === (float) ($r['json']['data']['amount'] ?? 0)
    && 168.0 === (float) ($r['json']['data']['pay_amount'] ?? 0), $r['raw']);
$oid1 = (int) ($r['json']['data']['id'] ?? 0);
$osn1 = (string) ($r['json']['data']['order_sn'] ?? '');
check('订单号RS前缀', str_starts_with($osn1, 'RS'), $r['raw']);

// ---- 8. 桌台 busy 推导 ----
$r = req('GET', '/api/restaurant/info?aid=1');
$busyA = $busyB = -1;
foreach ($r['json']['data']['areas'] ?? [] as $area) {
    foreach ($area['tables'] ?? [] as $t) {
        if ((int) ($t['id'] ?? 0) === $tidA) {
            $busyA = (int) ($t['busy'] ?? -1);
        }
        if ((int) ($t['id'] ?? 0) === $tidB) {
            $busyB = (int) ($t['busy'] ?? -1);
        }
    }
}
check('待支付订单占用桌台A busy=1', 1 === $busyA, $r['raw']);
check('桌台B空闲 busy=0', 0 === $busyB, $r['raw']);

$r = req('GET', '/admin/restaurant/table/list?area_id=' . $areaId, null, $atoken);
$adminBusy = -1;
foreach ($r['json']['data']['list'] ?? [] as $row) {
    if ((int) ($row['id'] ?? 0) === $tidA) {
        $adminBusy = (int) ($row['busy'] ?? -1);
    }
}
check('后台桌台列表同步 busy=1', 1 === $adminBusy, $r['raw']);

// ---- 9. 支付（pay_order + 幂等） ----
$r = req('POST', '/api/restaurant/order/pay', ['order_id' => $oid1, 'pay_type' => 'wechat'], $mtoken);
check('支付成功（mock 直接入账）', ($r['json']['code'] ?? 1) === 0 && 1 === (int) ($r['json']['data']['status'] ?? -1), $r['raw']);

$r = req('POST', '/api/restaurant/order/pay', ['order_id' => $oid1, 'pay_type' => 'wechat'], $mtoken);
check('重复支付幂等', ($r['json']['code'] ?? 1) === 0 && 1 === (int) ($r['json']['data']['status'] ?? -1), $r['raw']);

$pdo = db();
$po = $pdo->query("SELECT out_trade_no FROM pay_order WHERE order_type='restaurant' AND biz_order_id={$oid1} AND status=1")->fetch(PDO::FETCH_ASSOC);
check('pay_order 落库且 PT+时间戳+R 前缀', false !== $po && 1 === preg_match('/^PT\d{14}R\d{8}$/', (string) $po['out_trade_no']), var_export($po, true));

$st = $pdo->query("SELECT status, paytime FROM restaurant_shop_order WHERE id={$oid1}")->fetch(PDO::FETCH_ASSOC);
check('订单入账 status=1 且 paytime>0', 1 === (int) $st['status'] && (int) $st['paytime'] > 0, var_export($st, true));

$gst = $pdo->query("SELECT count(*) FROM restaurant_shop_order_goods WHERE orderid={$oid1} AND status=1")->fetchColumn();
check('支付后商品行状态同步=1', 2 === (int) $gst, (string) $gst);
$pdo = null;

// ---- 10. 已支付不可取消 + 完成释放桌台 ----
$r = req('POST', '/api/restaurant/order/cancel', ['order_id' => $oid1], $mtoken);
check('已支付订单 api 取消拒绝', ($r['json']['code'] ?? 0) !== 0, $r['raw']);

$r = req('POST', '/admin/restaurant/order/cancel', ['id' => $oid1], $atoken);
check('已支付订单后台取消拒绝', ($r['json']['code'] ?? 0) !== 0, $r['raw']);

$r = req('POST', '/admin/restaurant/order/finish', ['id' => $oid1], $atoken);
check('完成订单（已支付→已完成）', ($r['json']['code'] ?? 1) === 0, $r['raw']);

$r = req('POST', '/admin/restaurant/order/finish', ['id' => $oid1], $atoken);
check('重复完成拒绝', ($r['json']['code'] ?? 0) !== 0, $r['raw']);

$r = req('GET', '/api/restaurant/info?aid=1');
$busyA = -1;
foreach ($r['json']['data']['areas'] ?? [] as $area) {
    foreach ($area['tables'] ?? [] as $t) {
        if ((int) ($t['id'] ?? 0) === $tidA) {
            $busyA = (int) ($t['busy'] ?? -1);
        }
    }
}
check('完成后桌台释放 busy=0', 0 === $busyA, $r['raw']);

// ---- 11. 券叠加下单 ----
$r = req('POST', '/admin/coupon/add', [
    'name' => '堂食验收券_' . $TS, 'type' => 1, 'money' => '30.00', 'full_money' => '150.00',
    'total' => 5, 'per_limit' => 2, 'start_time' => (string) (time() - 3600), 'end_time' => (string) (time() + 86400),
], $atoken);
$couponId = (int) ($r['json']['data']['id'] ?? 0);
check('创建满减券（满150减30）', $couponId > 0, $r['raw']);

$r = req('POST', '/api/coupon/receive', ['coupon_id' => $couponId], $mtoken);
check('领取优惠券', ($r['json']['code'] ?? 1) === 0, $r['raw']);

$r = req('POST', '/api/restaurant/order/create', [
    'table_id' => $tidB, 'goods' => [['product_id' => $pidA, 'num' => 2], ['product_id' => $pidB, 'num' => 1]], 'coupon_id' => $couponId,
], $mtoken);
check('用券下单（168-30=138）', ($r['json']['code'] ?? 1) === 0
    && 30.0 === (float) ($r['json']['data']['coupon_money'] ?? 0)
    && 138.0 === (float) ($r['json']['data']['pay_amount'] ?? 0), $r['raw']);
$oid2 = (int) ($r['json']['data']['id'] ?? 0);

$r = req('POST', '/api/restaurant/order/pay', ['order_id' => $oid2, 'pay_type' => 'alipay'], $mtoken);
check('券单支付成功', ($r['json']['code'] ?? 1) === 0, $r['raw']);

$pdo = db();
$cst = $pdo->query("SELECT r.status FROM coupon_record r LEFT JOIN coupon c ON c.id=r.couponid WHERE c.name LIKE '堂食验收券%' ORDER BY r.id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
check('支付后券核销', 1 === (int) ($cst['status'] ?? -1), var_export($cst, true));
$pdo = null;

$r = req('POST', '/admin/restaurant/order/finish', ['id' => $oid2], $atoken);
check('券单完成', ($r['json']['code'] ?? 1) === 0, $r['raw']);

// ---- 12. 积分叠加下单 ----
$pdo = db();
$pdo->exec("UPDATE member SET score = 500 WHERE aid = 1 AND tel = '13800138000'");
$pdo = null;

$r = req('POST', '/api/restaurant/order/create', [
    'table_id' => $tidB, 'goods' => [['product_id' => $pidA, 'num' => 2], ['product_id' => $pidB, 'num' => 1]], 'use_score' => 200,
], $mtoken);
check('积分下单（200分抵2元 实付166）', ($r['json']['code'] ?? 1) === 0
    && 2.0 === (float) ($r['json']['data']['score_money'] ?? 0)
    && 166.0 === (float) ($r['json']['data']['pay_amount'] ?? 0), $r['raw']);
$oid3 = (int) ($r['json']['data']['id'] ?? 0);

$pdo = db();
$score = (int) $pdo->query("SELECT score FROM member WHERE aid = 1 AND tel = '13800138000'")->fetchColumn();
check('下单预扣积分（500-200=300）', 300 === $score, (string) $score);
$pdo = null;

$r = req('POST', '/api/restaurant/order/pay', ['order_id' => $oid3, 'pay_type' => 'wechat'], $mtoken);
check('积分单支付成功', ($r['json']['code'] ?? 1) === 0, $r['raw']);

$r = req('POST', '/admin/restaurant/order/finish', ['id' => $oid3], $atoken);
check('积分单完成', ($r['json']['code'] ?? 1) === 0, $r['raw']);

// ---- 13. 取消流程（释放桌台+返券退分） ----
$r = req('POST', '/api/restaurant/order/create', [
    'table_id' => $tidA, 'goods' => [['product_id' => $pidA, 'num' => 1]], 'use_score' => 100,
], $mtoken);
check('待取消订单创建（100分抵1元 实付67）', ($r['json']['code'] ?? 1) === 0
    && 67.0 === (float) ($r['json']['data']['pay_amount'] ?? 0), $r['raw']);
$oid4 = (int) ($r['json']['data']['id'] ?? 0);

$pdo = db();
$score = (int) $pdo->query("SELECT score FROM member WHERE aid = 1 AND tel = '13800138000'")->fetchColumn();
// 366 = 500(初始) -200(积分单抵扣) +166(积分单支付赠送 floor(166)*1) -100(待取消单预扣)
check('预扣后积分=366（含支付赠送）', 366 === $score, (string) $score);
$scoreBeforeCancel = $score;
$pdo = null;

$r = req('POST', '/api/restaurant/order/cancel', ['order_id' => $oid4], $mtoken);
check('待支付订单取消成功', ($r['json']['code'] ?? 1) === 0, $r['raw']);

$r = req('POST', '/api/restaurant/order/cancel', ['order_id' => $oid4], $mtoken);
check('重复取消拒绝', ($r['json']['code'] ?? 0) !== 0, $r['raw']);

$pdo = db();
$st = $pdo->query("SELECT status FROM restaurant_shop_order WHERE id={$oid4}")->fetch(PDO::FETCH_ASSOC);
check('取消后 status=4', 4 === (int) $st['status'], var_export($st, true));
$score = (int) $pdo->query("SELECT score FROM member WHERE aid = 1 AND tel = '13800138000'")->fetchColumn();
check('取消返还积分（+100）', $scoreBeforeCancel + 100 === $score, (string) $score);
$pdo = null;

$r = req('GET', '/api/restaurant/info?aid=1');
$busyA = -1;
foreach ($r['json']['data']['areas'] ?? [] as $area) {
    foreach ($area['tables'] ?? [] as $t) {
        if ((int) ($t['id'] ?? 0) === $tidA) {
            $busyA = (int) ($t['busy'] ?? -1);
        }
    }
}
check('取消后桌台释放 busy=0', 0 === $busyA, $r['raw']);

// 后台取消（仅待支付）
$r = req('POST', '/api/restaurant/order/create', [
    'table_id' => $tidB, 'goods' => [['product_id' => $pidB, 'num' => 1]],
], $mtoken);
$oid5 = (int) ($r['json']['data']['id'] ?? 0);
$r = req('POST', '/admin/restaurant/order/cancel', ['id' => $oid5], $atoken);
check('后台取消待支付订单', ($r['json']['code'] ?? 1) === 0, $r['raw']);

// ---- 14. 后台订单列表（goods 数组 + 状态筛选） ----
$r = req('GET', '/admin/restaurant/order/list?keyword=' . rawurlencode('堂食验收桌'), null, $atoken);
$ok = ($r['json']['data']['total'] ?? 0) >= 5;
$rowOk = false;
foreach ($r['json']['data']['list'] ?? [] as $row) {
    if ((int) ($row['id'] ?? 0) === $oid1) {
        $goodsOk = is_array($row['goods'] ?? null) && 2 === count($row['goods']);
        $gOk = true;
        foreach ($row['goods'] ?? [] as $g) {
            if ('' === ($g['name'] ?? '') || (int) ($g['num'] ?? 0) <= 0 || (float) ($g['price'] ?? 0) <= 0) {
                $gOk = false;
            }
        }
        $rowOk = str_starts_with((string) ($row['order_sn'] ?? ''), 'RS')
            && str_contains((string) ($row['table_name'] ?? ''), '堂食验收桌A')
            && $goodsOk && $gOk
            && 168.0 === (float) ($row['amount'] ?? 0)
            && 168.0 === (float) ($row['pay_amount'] ?? 0)
            && 2 === (int) ($row['status'] ?? -1)
            && '已完成' === ($row['status_text'] ?? '')
            && (int) ($row['pay_time'] ?? 0) > 0;
    }
}
check('后台订单列表（含goods数组/桌号/金额/状态文案）', $ok && $rowOk, $r['raw']);

$r = req('GET', '/admin/restaurant/order/list?status=4', null, $atoken);
$allCancelled = ($r['json']['data']['total'] ?? 0) >= 1;
foreach ($r['json']['data']['list'] ?? [] as $row) {
    if (4 !== (int) ($row['status'] ?? -1)) {
        $allCancelled = false;
    }
}
check('后台订单状态筛选（已取消）', $allCancelled, $r['raw']);

$r = req('GET', '/api/restaurant/order/list?status=2', null, $mtoken);
$mineOk = ($r['json']['data']['total'] ?? 0) >= 1;
foreach ($r['json']['data']['list'] ?? [] as $row) {
    if (2 !== (int) ($row['status'] ?? -1) || empty($row['goods'])) {
        $mineOk = false;
    }
}
check('我的堂食订单筛选（含goods）', $mineOk, $r['raw']);

// ---- 15. 删除保护 ----
$r = req('POST', '/api/restaurant/order/create', [
    'table_id' => $tidA, 'goods' => [['product_id' => $pidA, 'num' => 1]],
], $mtoken);
$oid6 = (int) ($r['json']['data']['id'] ?? 0);

$r = req('POST', '/admin/restaurant/table/delete', ['id' => $tidA], $atoken);
check('删除有未完结订单的桌台被拒绝', ($r['json']['code'] ?? 0) !== 0, $r['raw']);

$r = req('POST', '/admin/restaurant/product/delete', ['id' => $pidA], $atoken);
check('删除有未完结订单的菜品被拒绝', ($r['json']['code'] ?? 0) !== 0, $r['raw']);

$r = req('POST', '/admin/restaurant/order/cancel', ['id' => $oid6], $atoken);
check('清理占用订单（后台取消）', ($r['json']['code'] ?? 1) === 0, $r['raw']);

$r = req('POST', '/admin/restaurant/category/delete', ['id' => $catId], $atoken);
check('删除有菜品的分类仍被拒绝', ($r['json']['code'] ?? 0) !== 0, $r['raw']);

$r = req('POST', '/admin/restaurant/area/delete', ['id' => $areaId], $atoken);
check('删除有桌台的区域被拒绝', ($r['json']['code'] ?? 0) !== 0, $r['raw']);

// ---- 16. 收尾清理 ----
$pdo = db();
$pdo->exec("DELETE po FROM pay_order po INNER JOIN restaurant_shop_order o ON o.id = po.biz_order_id WHERE po.order_type = 'restaurant'");
$pdo->exec("DELETE g FROM restaurant_shop_order_goods g WHERE g.name LIKE '堂食验收%'");
$pdo->exec("DELETE FROM restaurant_shop_order WHERE ordernum LIKE 'RS%' AND tableid IN (SELECT id FROM restaurant_table WHERE name LIKE '堂食验收%')");
$pdo->exec("DELETE FROM restaurant_table WHERE name LIKE '堂食验收%'");
$pdo->exec("DELETE FROM restaurant_area WHERE name LIKE '堂食验收%'");
$pdo->exec("DELETE FROM restaurant_product_category WHERE name LIKE '堂食验收%'");
$pdo->exec("UPDATE restaurant_product SET is_delete = 1 WHERE name LIKE '堂食验收%'");
$pdo->exec("UPDATE coupon SET deleted = 1 WHERE name LIKE '堂食验收券%'");
$pdo->exec("DELETE cr FROM coupon_record cr LEFT JOIN coupon c ON c.id = cr.couponid WHERE c.name LIKE '堂食验收券%'");
$pdo = null;

echo "\n餐饮验收结果：PASS={$pass} FAIL={$fail}\n";
exit($fail > 0 ? 1 : 0);
