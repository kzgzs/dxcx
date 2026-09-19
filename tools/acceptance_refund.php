<?php
// P4 退款模块验收脚本（mock 模式）
// 用法：先 php think run -p 8080，再 php tools/acceptance_refund.php
// 覆盖：申请退款 → 审核通过（退款+库存回补+订单关闭）→ 驳回路径 → 重复申请拦截
//       → 已发货退款不回补库存 → 幂等审核 → 统计/搜索/详情（商品快照）

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

function goodsStock(string $token, int $goodsId, string $kw): int
{
    $r = req('GET', '/admin/goods/list?pagesize=100&keyword=' . rawurlencode($kw), null, $token);
    foreach ($r['json']['data']['list'] ?? [] as $g) {
        if ((int) $g['id'] === $goodsId) {
            return (int) ($g['stock'] ?? -1);
        }
    }
    return -1;
}

$KW = '退款验收商品_' . time();

// ---- 0. 环境清理：避免残留满减促销自动参与计价干扰断言 ----
$pdoClean = new PDO('mysql:host=127.0.0.1;port=3306;dbname=dxcx;charset=utf8mb4', 'dxcx', 'dxcx123456');
$pdoClean->exec('UPDATE promotion SET deleted = 1 WHERE aid = 1');
$pdoClean = null;

// ---- 1. 登录与备货 ----
$r = req('POST', '/api/member/login', ['phone' => '13800138000', 'password' => '123456']);
check('会员登录', ($r['json']['code'] ?? 1) === 0, $r['raw']);
$mtoken = (string) ($r['json']['data']['token'] ?? '');

$r = req('POST', '/admin/login', ['username' => 'tenant1', 'password' => '123456']);
check('tenant1 登录', ($r['json']['code'] ?? 1) === 0, $r['raw']);
$atoken = (string) ($r['json']['data']['token'] ?? '');

$r = req('POST', '/admin/goods/category/add', ['name' => '退款验收分类_' . time(), 'pid' => 0], $atoken);
$catId = (int) ($r['json']['data']['id'] ?? 0);

$r = req('POST', '/admin/goods/add', [
    'name' => $KW, 'category_id' => $catId, 'price' => '25.00', 'stock' => 6,
    'image' => '/uploads/tenant_1/test.png', 'status' => 1,
], $atoken);
check('建商品（库存6）', ($r['json']['code'] ?? 1) === 0, $r['raw']);
$goodsId = (int) ($r['json']['data']['id'] ?? 0);

$r = req('POST', '/api/address/add', [
    'name' => '退款人', 'phone' => '13800138000',
    'province' => '广东省', 'city' => '广州市', 'district' => '天河区',
    'detail' => '天河路100号', 'is_default' => 1,
], $mtoken);
$addrId = (int) ($r['json']['data']['id'] ?? 0);
check('新增地址', $addrId > 0, $r['raw']);

// ---- 2. 流程A：未发货订单退款通过（回补库存） ----
$r = req('POST', '/api/order/create', ['goods_id' => $goodsId, 'num' => 2, 'address_id' => $addrId], $mtoken);
$orderA = (int) ($r['json']['data']['id'] ?? 0);
req('POST', '/api/order/pay', ['order_id' => $orderA, 'pay_type' => 'wechat'], $mtoken);
check('下单A并支付（库存6→4）', goodsStock($atoken, $goodsId, $KW) === 4);

$r = req('POST', '/api/order/refund', ['order_id' => $orderA, 'reason' => '不想要了'], $mtoken);
check('申请退款成功', ($r['json']['code'] ?? 1) === 0
    && ($r['json']['data']['id'] ?? 0) > 0
    && '' !== ($r['json']['data']['refund_sn'] ?? '')
    && abs((float) ($r['json']['data']['refund_amount'] ?? 0) - 50.0) < 0.001, $r['raw']);
$refundA = (int) ($r['json']['data']['id'] ?? 0);
$refundSnA = (string) ($r['json']['data']['refund_sn'] ?? '');

$r = req('POST', '/api/order/refund', ['order_id' => $orderA, 'reason' => '再申请一次'], $mtoken);
check('进行中重复申请被拦截', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

// 后台列表/统计/搜索
$r = req('GET', '/admin/refund/list?status=0', null, $atoken);
$one = null;
foreach ($r['json']['data']['list'] ?? [] as $x) {
    if ((int) $x['id'] === $refundA) $one = $x;
}
check('后台退款列表（契约字段）', null !== $one
    && ($one['ordernum'] ?? '') !== ''
    && ($one['member_phone'] ?? '') === '13800138000'
    && abs((float) ($one['pay_amount'] ?? 0) - 50.0) < 0.001
    && ($one['status'] ?? -1) === 0
    && isset($one['reason'], $one['apply_time'], $one['audit_time']), $r['raw']);

$r = req('GET', '/admin/refund/list?keyword=' . rawurlencode('退款人'), null, $atoken);
check('后台按收件人搜索退款单', ($r['json']['data']['total'] ?? 0) >= 1, $r['raw']);

$r = req('GET', '/admin/refund/stat', null, $atoken);
check('退款统计 pending>=1', ($r['json']['code'] ?? 1) === 0
    && ($r['json']['data']['pending'] ?? 0) >= 1
    && isset($r['json']['data']['done'], $r['json']['data']['rejected']), $r['raw']);

$r = req('GET', '/admin/refund/detail?id=' . $refundA, null, $atoken);
check('退款详情含订单商品快照', ($r['json']['code'] ?? 1) === 0
    && !empty($r['json']['data']['goods'])
    && ($r['json']['data']['goods'][0]['num'] ?? 0) === 2
    && isset($r['json']['data']['order']['address']['name']), $r['raw']);

// 审核通过（mock 即时退款）
$r = req('POST', '/admin/refund/audit', ['id' => $refundA, 'pass' => 1, 'remark' => '同意退款'], $atoken);
check('审核通过退款成功', ($r['json']['code'] ?? 1) === 0, $r['raw']);

$r = req('GET', '/api/refund/list', null, $mtoken);
$one = null;
foreach ($r['json']['data']['list'] ?? [] as $x) {
    if ((int) $x['id'] === $refundA) $one = $x;
}
check('我的退款单状态=已退款', null !== $one && 1 === ($one['status'] ?? -1)
    && ($one['status_text'] ?? '') === '已退款' && '' !== ($one['audit_time'] ?? ''), $r['raw']);

$r = req('GET', '/api/order/detail?id=' . $orderA, null, $mtoken);
check('退款后订单关闭（状态4）', ($r['json']['data']['status'] ?? -1) === 4, $r['raw']);
check('未发货退款回补库存 4→6', goodsStock($atoken, $goodsId, $KW) === 6);

$r = req('POST', '/admin/refund/audit', ['id' => $refundA, 'pass' => 1, 'remark' => '再来一次'], $atoken);
check('重复审核被拦截（幂等）', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

$r = req('POST', '/api/order/refund', ['order_id' => $orderA, 'reason' => '退过了再退'], $mtoken);
check('已退款订单再申请被拦截', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

// ---- 3. 流程B：驳回路径 ----
$r = req('POST', '/api/order/create', ['goods_id' => $goodsId, 'num' => 1, 'address_id' => $addrId], $mtoken);
$orderB = (int) ($r['json']['data']['id'] ?? 0);
req('POST', '/api/order/pay', ['order_id' => $orderB, 'pay_type' => 'wechat'], $mtoken);

$r = req('POST', '/api/order/refund', ['order_id' => $orderB, 'reason' => '测试驳回'], $mtoken);
$refundB = (int) ($r['json']['data']['id'] ?? 0);

$r = req('POST', '/admin/refund/audit', ['id' => $refundB, 'pass' => 2, 'remark' => '不符合退款条件'], $atoken);
check('审核驳回成功', ($r['json']['code'] ?? 1) === 0, $r['raw']);

$r = req('GET', '/admin/refund/detail?id=' . $refundB, null, $atoken);
check('驳回后状态=已驳回+备注', ($r['json']['data']['status'] ?? -1) === 2
    && ($r['json']['data']['status_text'] ?? '') === '已驳回'
    && ($r['json']['data']['audit_remark'] ?? '') === '不符合退款条件', $r['raw']);

$r = req('GET', '/api/order/detail?id=' . $orderB, null, $mtoken);
check('驳回后订单仍为已支付', ($r['json']['data']['status'] ?? -1) === 1, $r['raw']);

$r = req('GET', '/admin/refund/list?status=2', null, $atoken);
$found = false;
foreach ($r['json']['data']['list'] ?? [] as $x) {
    if ((int) $x['id'] === $refundB) $found = true;
}
check('按状态筛选已驳回', $found, $r['raw']);

// ---- 4. 流程C：已发货退款（不回补库存） ----
check('驳回后库存为 5', goodsStock($atoken, $goodsId, $KW) === 5);
$r = req('POST', '/api/order/create', ['goods_id' => $goodsId, 'num' => 1, 'address_id' => $addrId], $mtoken);
$orderC = (int) ($r['json']['data']['id'] ?? 0);
req('POST', '/api/order/pay', ['order_id' => $orderC, 'pay_type' => 'wechat'], $mtoken);
req('POST', '/admin/order/ship', ['id' => $orderC, 'ship_company' => '顺丰', 'ship_sn' => 'SFC' . time()], $atoken);

$r = req('POST', '/api/order/refund', ['order_id' => $orderC, 'reason' => '已发货也要退'], $mtoken);
check('已发货订单可申请退款', ($r['json']['code'] ?? 1) === 0, $r['raw']);
$refundC = (int) ($r['json']['data']['id'] ?? 0);

$r = req('POST', '/admin/refund/audit', ['id' => $refundC, 'pass' => 1, 'remark' => ''], $atoken);
check('已发货退款审核通过', ($r['json']['code'] ?? 1) === 0, $r['raw']);
check('已发货退款不回补库存（仍为4）', goodsStock($atoken, $goodsId, $KW) === 4);
$r = req('GET', '/api/order/detail?id=' . $orderC, null, $mtoken);
check('已发货退款后订单关闭', ($r['json']['data']['status'] ?? -1) === 4, $r['raw']);

// ---- 5. 边界 ----
$r = req('POST', '/api/order/create', ['goods_id' => $goodsId, 'num' => 1, 'address_id' => $addrId], $mtoken);
$orderD = (int) ($r['json']['data']['id'] ?? 0);
$r = req('POST', '/api/order/refund', ['order_id' => $orderD, 'reason' => '未支付想退'], $mtoken);
check('未支付订单申请退款被拒', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

$r = req('POST', '/api/order/refund', ['order_id' => $orderD, 'reason' => ''], $mtoken);
check('空退款原因被拒', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

$r = req('GET', '/admin/refund/stat', null, $atoken);
check('统计口径一致（done>=2 rejected>=1）', ($r['json']['data']['done'] ?? 0) >= 2
    && ($r['json']['data']['rejected'] ?? 0) >= 1, $r['raw']);

echo "\n========== 退款验收结果：{$pass} PASS / {$fail} FAIL ==========\n";
exit($fail > 0 ? 1 : 0);
