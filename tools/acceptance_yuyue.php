<?php
// P6 预约模块一期验收脚本（mock 模式）
// 用法：先 php think run -p 8080，再 php tools/acceptance_yuyue.php
// 覆盖：分类/项目/技师 CRUD 与删除保护 → 匿名列表/详情/时段 → 时段双约拦截（容量+技师维度）
//       → 下单支付（pay_order + onPaid 幂等）→ 券积分叠加 → 核销 → 退款拦截（核销/日期已到）→ 菜单

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

$TS       = time();
$tomorrow = date('Y-m-d', strtotime('+1 day'));

// ---- 0. 环境清理（脚本可独立重跑） ----
$pdo = db();
$pdo->exec("DELETE po FROM pay_order po INNER JOIN yuyue_order yo ON yo.id = po.biz_order_id WHERE po.order_type = 'yuyue'");
$pdo->exec("DELETE FROM yuyue_order WHERE proname LIKE '预约验收%'");
$pdo->exec("DELETE FROM yuyue_worker WHERE realname LIKE '预约验收%'");
$pdo->exec("UPDATE yuyue_product SET is_delete = 1 WHERE name LIKE '预约验收%'");
$pdo->exec("DELETE FROM yuyue_category WHERE name LIKE '预约验收%'");
$pdo->exec("UPDATE coupon SET deleted = 1 WHERE name LIKE '预约验收券%'");
$pdo->exec("DELETE cr FROM coupon_record cr LEFT JOIN coupon c ON c.id = cr.couponid WHERE c.name LIKE '预约验收券%'");
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

// ---- 2. 项目分类 CRUD ----
$r = req('POST', '/admin/yuyue/category/add', ['name' => '预约验收分类_' . $TS, 'sort' => 9], $atoken);
check('新建分类', ($r['json']['code'] ?? 1) === 0 && ($r['json']['data']['id'] ?? 0) > 0, $r['raw']);
$catId = (int) ($r['json']['data']['id'] ?? 0);

$r = req('POST', '/admin/yuyue/category/add', ['name' => ''], $atoken);
check('分类空名称拒绝', ($r['json']['code'] ?? 0) !== 0, $r['raw']);

$r = req('POST', '/admin/yuyue/category/edit', ['id' => $catId, 'name' => '预约验收分类改_' . $TS], $atoken);
check('编辑分类', ($r['json']['code'] ?? 1) === 0, $r['raw']);

$r = req('GET', '/admin/yuyue/category/list', null, $atoken);
$found = false;
foreach ($r['json']['data']['list'] ?? [] as $row) {
    if ((int) ($row['id'] ?? 0) === $catId && str_contains((string) ($row['name'] ?? ''), '改')) {
        $found = true;
    }
}
check('分类列表含改名后记录', $found, $r['raw']);

// ---- 3. 预约项目 CRUD ----
$r = req('POST', '/admin/yuyue/product/add', [
    'name' => '预约验收项目A_' . $TS, 'category_id' => $catId, 'pic' => '/uploads/yy_a.png',
    'price' => '88.00', 'desc' => '验收项目A', 'duration' => 60, 'status' => 1,
], $atoken);
check('新建项目A（88元 60分钟）', ($r['json']['code'] ?? 1) === 0 && ($r['json']['data']['id'] ?? 0) > 0, $r['raw']);
$pidA = (int) ($r['json']['data']['id'] ?? 0);

$r = req('POST', '/admin/yuyue/product/add', ['name' => '预约验收项目B_' . $TS, 'category_id' => $catId, 'price' => '66.00', 'duration' => 30], $atoken);
$pidB = (int) ($r['json']['data']['id'] ?? 0);
check('新建项目B（66元 30分钟）', $pidB > 0, $r['raw']);

$r = req('POST', '/admin/yuyue/category/delete', ['id' => $catId], $atoken);
check('删除有项目的分类被拒绝', ($r['json']['code'] ?? 0) !== 0, $r['raw']);

// edit 空值不修改：仅改 desc，name/price 保持
$r = req('POST', '/admin/yuyue/product/edit', ['id' => $pidA, 'desc' => '验收项目A描述改', 'name' => '', 'price' => ''], $atoken);
check('编辑项目（空值不修改）', ($r['json']['code'] ?? 1) === 0, $r['raw']);

$r = req('GET', '/admin/yuyue/product/list?keyword=' . rawurlencode('预约验收项目') . '&category_id=' . $catId, null, $atoken);
$listOk = ($r['json']['data']['total'] ?? 0) === 2;
$editOk = false;
foreach ($r['json']['data']['list'] ?? [] as $row) {
    if ((int) ($row['id'] ?? 0) === $pidA) {
        $editOk = '预约验收项目A_' . $TS === ($row['name'] ?? '')
            && '验收项目A描述改' === ($row['desc'] ?? '')
            && 88.0 === (float) ($row['price'] ?? 0)
            && 60 === (int) ($row['duration'] ?? 0);
    }
}
check('项目列表筛选（keyword+category_id）', $listOk, $r['raw']);
check('编辑后 name/price/duration 未被空值覆盖', $editOk, $r['raw']);

$r = req('POST', '/admin/yuyue/product/status', ['id' => $pidB, 'status' => 0], $atoken);
check('下架项目B', ($r['json']['code'] ?? 1) === 0, $r['raw']);

// ---- 4. 技师 CRUD ----
$r = req('POST', '/admin/yuyue/worker/add', [
    'name' => '预约验收技师_' . $TS, 'pic' => '/uploads/w1.png', 'phone' => '13900001111', 'desc' => '金牌技师', 'status' => 1,
], $atoken);
check('新建技师', ($r['json']['code'] ?? 1) === 0 && ($r['json']['data']['id'] ?? 0) > 0, $r['raw']);
$wid = (int) ($r['json']['data']['id'] ?? 0);

$r = req('POST', '/admin/yuyue/worker/add', ['name' => '预约验收技师2_' . $TS], $atoken);
$wid2 = (int) ($r['json']['data']['id'] ?? 0);
check('新建技师2（无分类可跳过）', $wid2 > 0, $r['raw']);

$r = req('POST', '/admin/yuyue/worker/edit', ['id' => $wid, 'desc' => '金牌技师改'], $atoken);
check('编辑技师', ($r['json']['code'] ?? 1) === 0, $r['raw']);

$r = req('GET', '/admin/yuyue/worker/list?keyword=' . rawurlencode('预约验收技师'), null, $atoken);
check('技师列表筛选', ($r['json']['data']['total'] ?? 0) === 2, $r['raw']);

$r = req('POST', '/admin/yuyue/worker/delete', ['id' => $wid2], $atoken);
check('删除空闲技师', ($r['json']['code'] ?? 1) === 0, $r['raw']);

// ---- 5. 匿名浏览（白名单） ----
$r = req('GET', '/api/yuyue/product/list?aid=1&category_id=' . $catId);
$names = array_map(static fn ($x) => (string) ($x['name'] ?? ''), $r['json']['data']['list'] ?? []);
check('匿名项目列表只见上架项目A', ($r['json']['code'] ?? 1) === 0 && in_array('预约验收项目A_' . $TS, $names, true)
    && !in_array('预约验收项目B_' . $TS, $names, true), $r['raw']);

$r = req('GET', '/api/yuyue/product/detail?aid=1&id=' . $pidA);
check('匿名项目详情', ($r['json']['code'] ?? 1) === 0 && 88.0 === (float) ($r['json']['data']['price'] ?? 0)
    && 60 === (int) ($r['json']['data']['duration'] ?? 0), $r['raw']);

$r = req('GET', '/api/yuyue/worker/list?aid=1');
$wnames = array_map(static fn ($x) => (string) ($x['name'] ?? ''), $r['json']['data']['list'] ?? []);
check('匿名技师列表', in_array('预约验收技师_' . $TS, $wnames, true), $r['raw']);

$r = req('GET', '/api/yuyue/slot?aid=1&product_id=' . $pidA . '&date=' . $tomorrow);
$slots = $r['json']['data']['slots'] ?? [];
check('时段表12档（09:00-20:00）', 12 === count($slots) && '09:00' === ($slots[0]['time'] ?? '') && '20:00' === ($slots[11]['time'] ?? ''), $r['raw']);
$allFree = !empty($slots) && !array_filter($slots, static fn ($s) => 1 !== (int) ($s['available'] ?? -1));
check('明天时段全部可约', (bool) $allFree, $r['raw']);

// ---- 6. 下单 + 时段双约拦截 ----
$r = req('POST', '/api/yuyue/order/create', ['product_id' => $pidA, 'book_date' => $tomorrow, 'book_time' => '08:30'], $mtoken);
check('非法时段拒绝', ($r['json']['code'] ?? 0) !== 0, $r['raw']);

$r = req('POST', '/api/yuyue/order/create', ['product_id' => $pidA, 'book_date' => date('Y-m-d', strtotime('-1 day')), 'book_time' => '10:00'], $mtoken);
check('过去日期拒绝', ($r['json']['code'] ?? 0) !== 0, $r['raw']);

$r = req('POST', '/api/yuyue/order/create', [
    'product_id' => $pidA, 'worker_id' => $wid, 'book_date' => $tomorrow, 'book_time' => '10:00',
], $mtoken);
check('下单成功（明天10:00 指定技师）', ($r['json']['code'] ?? 1) === 0 && 88.0 === (float) ($r['json']['data']['pay_amount'] ?? 0), $r['raw']);
$oid1  = (int) ($r['json']['data']['id'] ?? 0);
$osn1  = (string) ($r['json']['data']['order_sn'] ?? '');
$hex1  = (string) ($r['json']['data']['hexiao_code'] ?? '');
check('订单号YY前缀+核销码非空', str_starts_with($osn1, 'YY') && '' !== $hex1, $r['raw']);

$r = req('POST', '/api/yuyue/order/create', [
    'product_id' => $pidA, 'book_date' => $tomorrow, 'book_time' => '10:00',
], $mtoken);
check('同时段双约拦截（容量1）', ($r['json']['code'] ?? 0) !== 0, $r['raw']);

$r = req('GET', '/api/yuyue/slot?aid=1&product_id=' . $pidA . '&date=' . $tomorrow);
$slot10 = null;
foreach ($r['json']['data']['slots'] ?? [] as $s) {
    if ('10:00' === ($s['time'] ?? '')) {
        $slot10 = (int) ($s['available'] ?? -1);
    }
}
check('时段占用后 available=0', 0 === $slot10, $r['raw']);

// 容量提到2后：无技师可再约；但指定同技师被拦（技师维度占用）
$pdo = db();
$pdo->exec("UPDATE yuyue_product SET yynum = 2 WHERE id = {$pidA}");
$pdo = null;

$r = req('POST', '/api/yuyue/order/create', [
    'product_id' => $pidA, 'book_date' => $tomorrow, 'book_time' => '10:00',
], $mtoken);
check('容量2后同时段可再约（无技师）', ($r['json']['code'] ?? 1) === 0, $r['raw']);
$oid2 = (int) ($r['json']['data']['id'] ?? 0);

$r = req('POST', '/api/yuyue/order/create', [
    'product_id' => $pidA, 'worker_id' => $wid, 'book_date' => $tomorrow, 'book_time' => '10:00',
], $mtoken);
check('技师同时段占用拦截', ($r['json']['code'] ?? 0) !== 0, $r['raw']);

$r = req('GET', '/api/yuyue/slot?aid=1&product_id=' . $pidA . '&worker_id=' . $wid . '&date=' . $tomorrow);
$slot10w = null;
foreach ($r['json']['data']['slots'] ?? [] as $s) {
    if ('10:00' === ($s['time'] ?? '')) {
        $slot10w = (int) ($s['available'] ?? -1);
    }
}
check('技师维度时段查询 available=0', 0 === $slot10w, $r['raw']);

// ---- 7. 支付（pay_order + 幂等） ----
$r = req('POST', '/api/yuyue/order/pay', ['order_id' => $oid1, 'pay_type' => 'wechat'], $mtoken);
check('支付成功（mock 直接入账）', ($r['json']['code'] ?? 1) === 0 && 1 === (int) ($r['json']['data']['status'] ?? -1), $r['raw']);

$r = req('POST', '/api/yuyue/order/pay', ['order_id' => $oid1, 'pay_type' => 'wechat'], $mtoken);
check('重复支付幂等', ($r['json']['code'] ?? 1) === 0 && 1 === (int) ($r['json']['data']['status'] ?? -1), $r['raw']);

$pdo = db();
$po = $pdo->query("SELECT out_trade_no FROM pay_order WHERE order_type='yuyue' AND biz_order_id={$oid1} AND status=1")->fetch(PDO::FETCH_ASSOC);
check('pay_order 落库且 PT+时间戳+Y 前缀', false !== $po && 1 === preg_match('/^PT\d{14}Y\d{8}$/', (string) $po['out_trade_no']), var_export($po, true));

$st = $pdo->query("SELECT status, paytime FROM yuyue_order WHERE id={$oid1}")->fetch(PDO::FETCH_ASSOC);
check('订单入账 status=1 且 paytime>0', 1 === (int) $st['status'] && (int) $st['paytime'] > 0, var_export($st, true));
$pdo = null;

// ---- 8. 核销 ----
$r = req('POST', '/admin/yuyue/order/verify', ['id' => $oid1, 'hexiao_code' => '0000000000'], $atoken);
check('错误核销码拒绝', ($r['json']['code'] ?? 0) !== 0, $r['raw']);

$r = req('POST', '/admin/yuyue/order/verify', ['id' => $oid1, 'hexiao_code' => $hex1], $atoken);
check('正确核销码核销成功', ($r['json']['code'] ?? 1) === 0, $r['raw']);

$r = req('POST', '/admin/yuyue/order/verify', ['id' => $oid1, 'hexiao_code' => $hex1], $atoken);
check('核销后再次核销拒绝（已完成）', ($r['json']['code'] ?? 0) !== 0, $r['raw']);

$r = req('POST', '/api/yuyue/order/refund', ['order_id' => $oid1], $mtoken);
check('核销成功不可退款', ($r['json']['code'] ?? 0) !== 0, $r['raw']);

// ---- 9. 券叠加下单 + 退款 ----
$r = req('POST', '/admin/coupon/add', [
    'name' => '预约验收券_' . $TS, 'type' => 1, 'money' => '10.00', 'full_money' => '50.00',
    'total' => 5, 'per_limit' => 2, 'start_time' => (string) (time() - 3600), 'end_time' => (string) (time() + 86400),
], $atoken);
$couponId = (int) ($r['json']['data']['id'] ?? 0);
check('创建满减券（满50减10）', $couponId > 0, $r['raw']);

$r = req('POST', '/api/coupon/receive', ['coupon_id' => $couponId], $mtoken);
check('领取优惠券', ($r['json']['code'] ?? 1) === 0, $r['raw']);

$r = req('POST', '/api/yuyue/order/create', [
    'product_id' => $pidA, 'book_date' => $tomorrow, 'book_time' => '13:00', 'coupon_id' => $couponId,
], $mtoken);
check('用券下单（88-10=78）', ($r['json']['code'] ?? 1) === 0
    && 10.0 === (float) ($r['json']['data']['coupon_money'] ?? 0)
    && 78.0 === (float) ($r['json']['data']['pay_amount'] ?? 0), $r['raw']);
$oid3 = (int) ($r['json']['data']['id'] ?? 0);

$r = req('POST', '/api/yuyue/order/pay', ['order_id' => $oid3, 'pay_type' => 'alipay'], $mtoken);
check('券单支付成功', ($r['json']['code'] ?? 1) === 0, $r['raw']);

$pdo = db();
$cst = $pdo->query("SELECT r.status FROM coupon_record r LEFT JOIN coupon c ON c.id=r.couponid WHERE c.name LIKE '预约验收券%' ORDER BY r.id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
check('支付后券核销', 1 === (int) ($cst['status'] ?? -1), var_export($cst, true));
$pdo = null;

$r = req('POST', '/api/yuyue/order/refund', ['order_id' => $oid3], $mtoken);
check('已支付且预约日期未到可退款', ($r['json']['code'] ?? 1) === 0 && true === ($r['json']['data']['success'] ?? false), $r['raw']);

$pdo = db();
$st = $pdo->query("SELECT status, refund_money FROM yuyue_order WHERE id={$oid3}")->fetch(PDO::FETCH_ASSOC);
check('退款后 status=5 且退款金额=实付', 5 === (int) $st['status'] && 78.0 === (float) $st['refund_money'], var_export($st, true));
$pdo = null;

// ---- 10. 积分叠加下单 ----
$pdo = db();
$pdo->exec("UPDATE member SET score = 500 WHERE aid = 1 AND tel = '13800138000'");
$pdo = null;

$r = req('POST', '/api/yuyue/order/create', [
    'product_id' => $pidA, 'book_date' => $tomorrow, 'book_time' => '14:00', 'use_score' => 200,
], $mtoken);
check('积分下单（200分抵2元 实付86）', ($r['json']['code'] ?? 1) === 0
    && 2.0 === (float) ($r['json']['data']['score_money'] ?? 0)
    && 86.0 === (float) ($r['json']['data']['pay_amount'] ?? 0), $r['raw']);
$oid4 = (int) ($r['json']['data']['id'] ?? 0);

$pdo = db();
$score = (int) $pdo->query("SELECT score FROM member WHERE aid = 1 AND tel = '13800138000'")->fetchColumn();
check('下单预扣积分（500-200=300）', 300 === $score, (string) $score);
$pdo = null;

$r = req('POST', '/api/yuyue/order/pay', ['order_id' => $oid4, 'pay_type' => 'wechat'], $mtoken);
check('积分单支付成功', ($r['json']['code'] ?? 1) === 0, $r['raw']);

// ---- 11. 待支付订单退款拒绝 + 后台订单列表 ----
$r = req('POST', '/api/yuyue/order/refund', ['order_id' => $oid2], $mtoken);
check('待支付订单不可退款', ($r['json']['code'] ?? 0) !== 0, $r['raw']);

$r = req('GET', '/admin/yuyue/order/list?keyword=' . rawurlencode('预约验收项目A'), null, $atoken);
$ok = ($r['json']['data']['total'] ?? 0) >= 4;
$rowOk = false;
foreach ($r['json']['data']['list'] ?? [] as $row) {
    if ((int) ($row['id'] ?? 0) === $oid1) {
        $rowOk = '' !== ($row['order_sn'] ?? '')
            && '13800138000' === ($row['member_phone'] ?? '')
            && '预约验收项目A_' . $TS === ($row['product_name'] ?? '')
            && '预约验收技师_' . $TS === ($row['worker_name'] ?? '')
            && $tomorrow === ($row['book_date'] ?? '')
            && '10:00' === ($row['book_time'] ?? '')
            && 2 === (int) ($row['status'] ?? -1)
            && '已完成' === ($row['status_text'] ?? '')
            && '' !== ($row['hexiao_code'] ?? '');
    }
}
check('后台订单列表（含手机/项目/技师/时段/核销码）', $ok && $rowOk, $r['raw']);

$r = req('GET', '/admin/yuyue/order/list?status=5', null, $atoken);
$allRefunded = true;
foreach ($r['json']['data']['list'] ?? [] as $row) {
    if (5 !== (int) ($row['status'] ?? -1)) {
        $allRefunded = false;
    }
}
check('后台订单状态筛选', ($r['json']['data']['total'] ?? 0) >= 1 && $allRefunded, $r['raw']);

$r = req('GET', '/api/yuyue/order/list?status=1', null, $mtoken);
$paidOk = ($r['json']['data']['total'] ?? 0) >= 1;
foreach ($r['json']['data']['list'] ?? [] as $row) {
    if (1 !== (int) ($row['status'] ?? -1)) {
        $paidOk = false;
    }
}
check('我的预约订单筛选', $paidOk, $r['raw']);

// ---- 12. 删除保护 ----
$r = req('POST', '/admin/yuyue/product/delete', ['id' => $pidA], $atoken);
check('删除有未完结订单的项目被拒绝', ($r['json']['code'] ?? 0) !== 0, $r['raw']);

// 把未支付订单 oid2 指派给技师，构造未完结占用
$pdo = db();
$pdo->exec("UPDATE yuyue_order SET worker_id = {$wid} WHERE id = {$oid2}");
$pdo = null;

$r = req('POST', '/admin/yuyue/worker/delete', ['id' => $wid], $atoken);
check('删除有未完结订单的技师被拒绝', ($r['json']['code'] ?? 0) !== 0, $r['raw']);

// ---- 13. 菜单 ----
$r = req('GET', '/admin/menu', null, $atoken);
$menuOk = false;
foreach ($r['json']['data'] ?? [] as $group) {
    if ('扩展' === ($group['name'] ?? '')) {
        // 三层嵌套：预约管理/餐饮管理子组各含 3 叶子项
        $subMap = [];
        foreach ($group['children'] ?? [] as $sub) {
            $subMap[(string) ($sub['name'] ?? '')] = array_map(
                static fn ($c) => (string) ($c['path'] ?? ''),
                $sub['children'] ?? []
            );
        }
        $menuOk = ['/admin/yuyue/product', '/admin/yuyue/worker', '/admin/yuyue/order'] === ($subMap['预约管理'] ?? [])
            && ['/admin/restaurant/table', '/admin/restaurant/product', '/admin/restaurant/order'] === ($subMap['餐饮管理'] ?? []);
    }
}
check('菜单「扩展」嵌套子组含预约3项+餐饮3项', $menuOk, $r['raw']);

// ---- 14. 收尾清理 ----
$pdo = db();
$pdo->exec("DELETE po FROM pay_order po INNER JOIN yuyue_order yo ON yo.id = po.biz_order_id WHERE po.order_type = 'yuyue'");
$pdo->exec("DELETE FROM yuyue_order WHERE proname LIKE '预约验收%'");
$pdo->exec("DELETE FROM yuyue_worker WHERE realname LIKE '预约验收%'");
$pdo->exec("UPDATE yuyue_product SET is_delete = 1 WHERE name LIKE '预约验收%'");
$pdo->exec("DELETE FROM yuyue_category WHERE name LIKE '预约验收%'");
$pdo->exec("UPDATE coupon SET deleted = 1 WHERE name LIKE '预约验收券%'");
$pdo->exec("DELETE cr FROM coupon_record cr LEFT JOIN coupon c ON c.id = cr.couponid WHERE c.name LIKE '预约验收券%'");
$pdo = null;

echo "\n预约验收结果：PASS={$pass} FAIL={$fail}\n";
exit($fail > 0 ? 1 : 0);
