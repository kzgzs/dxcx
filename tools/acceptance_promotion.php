<?php
// P5 满减促销验收脚本（mock 模式）
// 用法：先 php think run -p 8080，再 php tools/acceptance_promotion.php
// 覆盖：CRUD 校验拦截 → 前端传0容错（未选类型字段/id:0）→ 匿名活动列表
//       → 下单自动择优（满额减/满件折/门槛）→ 与优惠券叠加计价
//       → status/delete/edit 拦截（被订单使用）→ 后台订单详情带出促销字段

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

/** 后台活动列表里找指定活动行 */
function promoRow(string $token, string $keyword, int $promoId): ?array
{
    $r = req('GET', '/admin/promotion/list?pagesize=100&keyword=' . rawurlencode($keyword), null, $token);
    foreach ($r['json']['data']['list'] ?? [] as $row) {
        if ((int) $row['id'] === $promoId) {
            return $row;
        }
    }
    return null;
}

$TS    = time();
$winS  = $TS - 3600;
$winE  = $TS + 86400;
$cStart = date('Y-m-d H:i:s', $TS - 3600);
$cEnd   = date('Y-m-d H:i:s', $TS + 86400);

// ---- 0. 环境清理：避免历史活动干扰择优 ----
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

$r = req('POST', '/admin/goods/category/add', ['name' => '促销验收分类_' . $TS, 'pid' => 0], $atoken);
$catId = (int) ($r['json']['data']['id'] ?? 0);

$r = req('POST', '/admin/goods/add', [
    'name' => '促销验收商品_' . $TS, 'category_id' => $catId, 'price' => '100.00', 'stock' => 100,
    'image' => '/uploads/tenant_1/test.png', 'status' => 1,
], $atoken);
check('建商品（单价100 库存100）', ($r['json']['code'] ?? 1) === 0 && ($r['json']['data']['id'] ?? 0) > 0, $r['raw']);
$goodsId = (int) ($r['json']['data']['id'] ?? 0);

$r = req('POST', '/api/address/add', [
    'name' => '促销验收人', 'phone' => '13800138000',
    'province' => '广东省', 'city' => '深圳市', 'district' => '南山区',
    'detail' => '科技路1号', 'is_default' => 1,
], $mtoken);
$addrId = (int) ($r['json']['data']['id'] ?? 0);
check('新增地址', $addrId > 0, $r['raw']);

// ---- 2. 新建校验拦截 ----
$base1 = ['type' => 1, 'full_money' => 100, 'cut_money' => 10, 'full_num' => 0, 'discount' => 0, 'start_time' => $winS, 'end_time' => $winE];

$r = req('POST', '/admin/promotion/add', $base1, $atoken);
check('缺名称被拒', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

$r = req('POST', '/admin/promotion/add', array_merge($base1, ['name' => 'x', 'type' => 9]), $atoken);
check('类型无效被拒', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

$r = req('POST', '/admin/promotion/add', array_merge($base1, ['name' => 'x', 'full_money' => 0]), $atoken);
check('满额减缺门槛被拒', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

$r = req('POST', '/admin/promotion/add', array_merge($base1, ['name' => 'x', 'cut_money' => 200]), $atoken);
check('立减大于门槛被拒', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

$r = req('POST', '/admin/promotion/add', ['name' => 'x', 'type' => 2, 'full_money' => 0, 'cut_money' => 0, 'full_num' => 0, 'discount' => 8, 'start_time' => $winS, 'end_time' => $winE], $atoken);
check('满件折缺满件门槛被拒', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

$r = req('POST', '/admin/promotion/add', ['name' => 'x', 'type' => 2, 'full_money' => 0, 'cut_money' => 0, 'full_num' => 3, 'discount' => 0, 'start_time' => $winS, 'end_time' => $winE], $atoken);
check('折扣值低于1被拒', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

$r = req('POST', '/admin/promotion/add', ['name' => 'x', 'type' => 2, 'full_money' => 0, 'cut_money' => 0, 'full_num' => 3, 'discount' => 10, 'start_time' => $winS, 'end_time' => $winE], $atoken);
check('折扣值超过9.9被拒', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

$r = req('POST', '/admin/promotion/add', array_merge($base1, ['name' => 'x', 'start_time' => $winE, 'end_time' => $winS]), $atoken);
check('结束早于开始被拒', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

$r = req('POST', '/admin/promotion/add', array_merge($base1, ['name' => 'x', 'start_time' => 0, 'end_time' => 0]), $atoken);
check('缺起止时间被拒', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

// ---- 3. 新建成功（前端容错：未选类型字段传0 + id:0 忽略） ----
$r = req('POST', '/admin/promotion/add', array_merge($base1, ['id' => 0, 'name' => '促P1_' . $TS]), $atoken);
check('建满额减P1（满100减10，full_num/discount传0+id:0容忍）', ($r['json']['code'] ?? 1) === 0 && ($r['json']['data']['id'] ?? 0) > 0, $r['raw']);
$p1 = (int) ($r['json']['data']['id'] ?? 0);

$row = promoRow($atoken, '促P1_' . $TS, $p1);
check('P1契约字段（时间Unix秒/无关字段归零）', null !== $row
    && ($row['type'] ?? 0) === 1
    && abs((float) ($row['full_money'] ?? 0) - 100.0) < 0.001
    && abs((float) ($row['cut_money'] ?? 0) - 10.0) < 0.001
    && ($row['full_num'] ?? -1) === 0
    && ($row['start_time'] ?? 0) === $winS && ($row['end_time'] ?? 0) === $winE
    && ($row['status'] ?? -1) === 1, json_encode($row, JSON_UNESCAPED_UNICODE));

$r = req('POST', '/admin/promotion/add', ['id' => 0, 'name' => '促P2_' . $TS, 'type' => 1, 'full_money' => 100, 'cut_money' => 30, 'full_num' => 0, 'discount' => 0, 'start_time' => $winS, 'end_time' => $winE], $atoken);
check('建满额减P2（满100减30）', ($r['json']['code'] ?? 1) === 0, $r['raw']);
$p2 = (int) ($r['json']['data']['id'] ?? 0);

$r = req('POST', '/admin/promotion/add', ['id' => 0, 'name' => '促P3_' . $TS, 'type' => 2, 'full_money' => 0, 'cut_money' => 0, 'full_num' => 3, 'discount' => 8, 'start_time' => $winS, 'end_time' => $winE], $atoken);
check('建满件折P3（满3件8折）', ($r['json']['code'] ?? 1) === 0, $r['raw']);
$p3 = (int) ($r['json']['data']['id'] ?? 0);

$r = req('POST', '/admin/promotion/add', ['id' => 0, 'name' => '促P4_' . $TS, 'type' => 1, 'full_money' => 100, 'cut_money' => 5, 'full_num' => 0, 'discount' => 0, 'start_time' => $winS, 'end_time' => $winE], $atoken);
$p4 = (int) ($r['json']['data']['id'] ?? 0);
$r = req('POST', '/admin/promotion/add', ['id' => 0, 'name' => '促P5_' . $TS, 'type' => 1, 'full_money' => 100, 'cut_money' => 8, 'full_num' => 0, 'discount' => 0, 'start_time' => $TS + 3600, 'end_time' => $TS + 7200], $atoken);
$p5 = (int) ($r['json']['data']['id'] ?? 0);
$r = req('POST', '/admin/promotion/add', ['id' => 0, 'name' => '促P6_' . $TS, 'type' => 1, 'full_money' => 100, 'cut_money' => 8, 'full_num' => 0, 'discount' => 0, 'start_time' => $TS - 7200, 'end_time' => $TS - 3600], $atoken);
$p6 = (int) ($r['json']['data']['id'] ?? 0);
check('建P4待停用/P5未开始/P6已结束', $p4 > 0 && $p5 > 0 && $p6 > 0);

$r = req('POST', '/admin/promotion/status', ['id' => $p4, 'status' => 0], $atoken);
check('停用P4成功', ($r['json']['code'] ?? 1) === 0, $r['raw']);

// ---- 4. 列表筛选与匿名接口 ----
$r = req('GET', '/admin/promotion/list?keyword=' . rawurlencode('促P1_' . $TS), null, $atoken);
check('列表按名称搜索命中P1', ($r['json']['data']['total'] ?? 0) >= 1
    && ($r['json']['data']['list'][0]['id'] ?? 0) === $p1, $r['raw']);

$r = req('GET', '/admin/promotion/list?status=1&pagesize=100', null, $atoken);
$okStatus = ($r['json']['code'] ?? 1) === 0;
foreach ($r['json']['data']['list'] ?? [] as $row) {
    if (1 !== ($row['status'] ?? -1)) $okStatus = false;
}
check('列表按状态筛选', $okStatus, $r['raw']);

$r = req('GET', '/api/promotion/list?aid=1'); // 匿名，不带 token
$ids = array_column($r['json']['data']['list'] ?? [], 'id');
check('匿名活动列表含P1/P2/P3', ($r['json']['code'] ?? 1) === 0
    && in_array($p1, $ids, true) && in_array($p2, $ids, true) && in_array($p3, $ids, true), $r['raw']);
check('匿名列表不含停用P4/未开始P5/已结束P6', !in_array($p4, $ids, true)
    && !in_array($p5, $ids, true) && !in_array($p6, $ids, true), $r['raw']);

// ---- 5. 下单自动择优 ----
$r = req('POST', '/api/order/create', ['goods_id' => $goodsId, 'num' => 1, 'address_id' => $addrId], $mtoken);
check('买1件择优P2（减30）实付70', ($r['json']['code'] ?? 1) === 0
    && ($r['json']['data']['promotion_id'] ?? 0) === $p2
    && abs((float) ($r['json']['data']['promotion_money'] ?? 0) - 30.0) < 0.001
    && abs((float) ($r['json']['data']['pay_amount'] ?? 0) - 70.0) < 0.001, $r['raw']);
$order1 = (int) ($r['json']['data']['id'] ?? 0);

$r = req('POST', '/api/order/create', ['goods_id' => $goodsId, 'num' => 3, 'address_id' => $addrId], $mtoken);
check('买3件择优P3满件折（300×0.2=60）实付240', ($r['json']['code'] ?? 1) === 0
    && ($r['json']['data']['promotion_id'] ?? 0) === $p3
    && abs((float) ($r['json']['data']['promotion_money'] ?? 0) - 60.0) < 0.001
    && abs((float) ($r['json']['data']['pay_amount'] ?? 0) - 240.0) < 0.001, $r['raw']);

$r = req('POST', '/api/order/create', ['goods_id' => $goodsId, 'num' => 2, 'address_id' => $addrId], $mtoken);
check('买2件不达P3门槛→择优P2（减30）实付170', ($r['json']['code'] ?? 1) === 0
    && ($r['json']['data']['promotion_id'] ?? 0) === $p2
    && abs((float) ($r['json']['data']['pay_amount'] ?? 0) - 170.0) < 0.001, $r['raw']);

// ---- 6. 与优惠券叠加计价 ----
$r = req('POST', '/admin/coupon/add', ['name' => '促验券低槛_' . $TS, 'type' => 1, 'money' => '20.00', 'full_money' => '50.00', 'total' => 5, 'per_limit' => 1, 'start_time' => $cStart, 'end_time' => $cEnd], $atoken);
check('建券（满50减20）', ($r['json']['code'] ?? 1) === 0, $r['raw']);
$couponLow = (int) ($r['json']['data']['id'] ?? 0);

$r = req('POST', '/admin/coupon/add', ['name' => '促验券高槛_' . $TS, 'type' => 1, 'money' => '10.00', 'full_money' => '100.00', 'total' => 5, 'per_limit' => 1, 'start_time' => $cStart, 'end_time' => $cEnd], $atoken);
$couponHigh = (int) ($r['json']['data']['id'] ?? 0);
check('建券（满100减10）', $couponHigh > 0, $r['raw']);

$r = req('POST', '/api/coupon/receive', ['coupon_id' => $couponLow], $mtoken);
check('领取低槛券', ($r['json']['code'] ?? 1) === 0, $r['raw']);
$r = req('POST', '/api/coupon/receive', ['coupon_id' => $couponHigh], $mtoken);
check('领取高槛券', ($r['json']['code'] ?? 1) === 0, $r['raw']);

$r = req('POST', '/api/order/create', ['goods_id' => $goodsId, 'num' => 1, 'address_id' => $addrId, 'coupon_id' => $couponLow], $mtoken);
check('叠加计价：促销减30→70满足满50→券减20 实付50', ($r['json']['code'] ?? 1) === 0
    && abs((float) ($r['json']['data']['promotion_money'] ?? 0) - 30.0) < 0.001
    && abs((float) ($r['json']['data']['coupon_money'] ?? 0) - 20.0) < 0.001
    && abs((float) ($r['json']['data']['pay_amount'] ?? 0) - 50.0) < 0.001, $r['raw']);

$r = req('POST', '/api/order/create', ['goods_id' => $goodsId, 'num' => 1, 'address_id' => $addrId, 'coupon_id' => $couponHigh], $mtoken);
check('券门槛按促销后金额判断（70<100 拒绝）', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

// ---- 7. 后台订单带出促销字段 ----
$r = req('GET', '/api/order/detail?id=' . $order1, null, $mtoken);
check('api订单详情带出 promotion_id/promotion_money', ($r['json']['data']['promotion_id'] ?? 0) === $p2
    && abs((float) ($r['json']['data']['promotion_money'] ?? 0) - 30.0) < 0.001, $r['raw']);

$r = req('GET', '/admin/order/detail?id=' . $order1, null, $atoken);
check('后台订单详情带出 promotion_id/promotion_money', ($r['json']['data']['promotion_id'] ?? 0) === $p2
    && abs((float) ($r['json']['data']['promotion_money'] ?? 0) - 30.0) < 0.001, $r['raw']);

// ---- 8. status 切换影响择优 ----
$r = req('POST', '/admin/promotion/status', ['id' => $p2, 'status' => 0], $atoken);
check('停用P2成功', ($r['json']['code'] ?? 1) === 0, $r['raw']);

$r = req('POST', '/api/order/create', ['goods_id' => $goodsId, 'num' => 1, 'address_id' => $addrId], $mtoken);
check('停用P2后择优P1（减10）实付90', ($r['json']['code'] ?? 1) === 0
    && ($r['json']['data']['promotion_id'] ?? 0) === $p1
    && abs((float) ($r['json']['data']['pay_amount'] ?? 0) - 90.0) < 0.001, $r['raw']);

$r = req('POST', '/admin/promotion/status', ['id' => $p2, 'status' => 1], $atoken);
check('重新启用P2成功', ($r['json']['code'] ?? 1) === 0, $r['raw']);

// ---- 9. edit/delete 拦截（被订单使用） ----
$r = req('POST', '/admin/promotion/edit', ['id' => $p2, 'type' => 1, 'full_money' => 100, 'cut_money' => 40, 'full_num' => 0, 'discount' => 0], $atoken);
check('已使用P2改立减金额被拒', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

$r = req('POST', '/admin/promotion/edit', ['id' => $p2, 'name' => '改名_' . $TS], $atoken);
check('已使用P2改名称被拒', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

$r = req('POST', '/admin/promotion/edit', ['id' => $p2, 'name' => '促P2_' . $TS, 'type' => 1, 'full_money' => 100, 'cut_money' => 30, 'full_num' => 0, 'discount' => 0], $atoken);
check('已使用活动全量原值提交容忍（传0忽略）', ($r['json']['code'] ?? 1) === 0, $r['raw']);

$r = req('POST', '/admin/promotion/edit', ['id' => $p2, 'end_time' => $winE + 3600], $atoken);
$row = promoRow($atoken, '促P2_' . $TS, $p2);
check('已使用活动可改结束时间', ($r['json']['code'] ?? 1) === 0
    && null !== $row && ($row['end_time'] ?? 0) === $winE + 3600, $r['raw']);

$r = req('POST', '/admin/promotion/edit', ['id' => $p6, 'name' => '促P6改_' . $TS, 'type' => 1, 'full_money' => 100, 'cut_money' => 8, 'full_num' => 0, 'discount' => 0], $atoken);
check('未使用P6可改核心字段', ($r['json']['code'] ?? 1) === 0
    && null !== promoRow($atoken, '促P6改_' . $TS, $p6), $r['raw']);

$r = req('POST', '/admin/promotion/delete', ['id' => $p2], $atoken);
check('已使用P2删除被拒', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

$r = req('POST', '/admin/promotion/delete', ['id' => $p4], $atoken);
check('未使用P4软删成功', ($r['json']['code'] ?? 1) === 0, $r['raw']);
check('软删后列表不可见', null === promoRow($atoken, '促P4_' . $TS, $p4));

// ---- 10. 未登录拦截 ----
$r = req('GET', '/admin/promotion/list');
check('未登录访问后台促销列表 401', 401 === $r['status'], $r['raw']);

echo "\n========== 促销验收结果：{$pass} PASS / {$fail} FAIL ==========\n";
exit($fail > 0 ? 1 : 0);
