<?php
// P5 积分体系验收脚本（mock 模式）
// 用法：先 php think run -p 8080，再 php tools/acceptance_score.php
// 覆盖：配置读写与校验 → 会员列表 → 后台调整（余额不足拒绝/流水type=4）
//       → 下单积分抵扣（预扣/抵扣额上限）→ 支付赠送 → 取消返还（手动+超时命令）
//       → 开关/比例未配拦截 → 流水查询（admin/api）→ 未登录拦截

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

/** 我的积分余额 */
function balance(string $mtoken): int
{
    $r = req('GET', '/api/member/score', null, $mtoken);
    return (int) ($r['json']['data']['score'] ?? -1);
}

/** 指定会员流水里是否存在 type 且 score 匹配的最新一条 */
function hasLog(string $atoken, int $mid, int $type, int $score): bool
{
    $r = req('GET', '/admin/score/log?mid=' . $mid . '&pagesize=50', null, $atoken);
    foreach ($r['json']['data']['list'] ?? [] as $row) {
        if ((int) ($row['type'] ?? 0) === $type && (int) ($row['score'] ?? 0) === $score) {
            return true;
        }
    }
    return false;
}

$TS = time();

// ---- 0. 环境清理：避免历史促销干扰计价 ----
$pdo = db();
$pdo->exec('UPDATE promotion SET deleted = 1 WHERE aid = 1');
$pdo->exec("UPDATE member SET score = 0 WHERE aid = 1 AND tel = '13800138000'");
$pdo = null;

// ---- 1. 登录与备货 ----
$r = req('POST', '/api/member/login', ['phone' => '13800138000', 'password' => '123456']);
check('会员登录', ($r['json']['code'] ?? 1) === 0, $r['raw']);
$mtoken = (string) ($r['json']['data']['token'] ?? '');

$r = req('POST', '/admin/login', ['username' => 'tenant1', 'password' => '123456']);
check('tenant1 登录', ($r['json']['code'] ?? 1) === 0, $r['raw']);
$atoken = (string) ($r['json']['data']['token'] ?? '');

$r = req('POST', '/admin/goods/category/add', ['name' => '积分验收分类_' . $TS, 'pid' => 0], $atoken);
$catId = (int) ($r['json']['data']['id'] ?? 0);

$r = req('POST', '/admin/goods/add', [
    'name' => '积分验收商品_' . $TS, 'category_id' => $catId, 'price' => '100.00', 'stock' => 50,
    'image' => '/uploads/tenant_1/test.png', 'status' => 1,
], $atoken);
check('建商品（单价100 库存50）', ($r['json']['code'] ?? 1) === 0 && ($r['json']['data']['id'] ?? 0) > 0, $r['raw']);
$goodsId = (int) ($r['json']['data']['id'] ?? 0);

$r = req('POST', '/api/address/add', [
    'name' => '积分验收人', 'phone' => '13800138000',
    'province' => '广东省', 'city' => '深圳市', 'district' => '南山区',
    'detail' => '科技路1号', 'is_default' => 1,
], $mtoken);
$addrId = (int) ($r['json']['data']['id'] ?? 0);
check('新增地址', $addrId > 0, $r['raw']);

// ---- 2. 配置读写与校验 ----
$r = req('GET', '/admin/score/config', null, $atoken);
check('读取积分配置（契约键）', ($r['json']['code'] ?? 1) === 0
    && array_key_exists('score_switch', $r['json']['data'] ?? [])
    && array_key_exists('score_ratio', $r['json']['data'] ?? [])
    && array_key_exists('pay_score', $r['json']['data'] ?? []), $r['raw']);

$r = req('POST', '/admin/score/config', ['score_switch' => 2, 'score_ratio' => 100, 'pay_score' => 5], $atoken);
check('开关值非0/1被拒', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

$r = req('POST', '/admin/score/config', ['score_switch' => 1, 'score_ratio' => 0.5, 'pay_score' => 5], $atoken);
check('比例非整数被拒（0.5）', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

$r = req('POST', '/admin/score/config', ['score_switch' => 1, 'score_ratio' => 0, 'pay_score' => 5], $atoken);
check('比例小于1被拒', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

$r = req('POST', '/admin/score/config', ['score_switch' => 1, 'score_ratio' => 100, 'pay_score' => -1], $atoken);
check('支付赠送负数被拒', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

$r = req('POST', '/admin/score/config', ['score_switch' => 1, 'score_ratio' => 100, 'pay_score' => 1.5], $atoken);
check('支付赠送非整数被拒', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

$r = req('POST', '/admin/score/config', ['score_switch' => 1, 'score_ratio' => 100], $atoken);
check('缺少配置项被拒', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

$r = req('POST', '/admin/score/config', ['score_switch' => 1, 'score_ratio' => 100, 'pay_score' => 5], $atoken);
check('保存配置成功（100积分抵1元 每付1元赠5）', ($r['json']['code'] ?? 1) === 0, $r['raw']);

$r = req('GET', '/admin/score/config', null, $atoken);
check('回读配置 1/100/5', ($r['json']['data']['score_switch'] ?? -1) === 1
    && ($r['json']['data']['score_ratio'] ?? -1) === 100
    && ($r['json']['data']['pay_score'] ?? -1) === 5, $r['raw']);

// ---- 3. 会员积分列表 ----
$r = req('GET', '/admin/score/memberlist?keyword=138', null, $atoken);
$mid = 0;
$rowOk = false;
foreach ($r['json']['data']['list'] ?? [] as $row) {
    if ('13800138000' === ($row['phone'] ?? '')) {
        $mid   = (int) ($row['mid'] ?? 0);
        $rowOk = $mid > 0 && array_key_exists('score', $row);
    }
}
check('会员列表按手机号搜索（mid/phone/score）', $rowOk, $r['raw']);

// ---- 4. 后台调整 ----
$r = req('POST', '/admin/score/adjust', ['mid' => $mid, 'score' => 0, 'remark' => 'x'], $atoken);
check('调整为0被拒', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

$r = req('POST', '/admin/score/adjust', ['mid' => $mid, 'score' => 10, 'remark' => ''], $atoken);
check('缺备注被拒', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

$r = req('POST', '/admin/score/adjust', ['mid' => 999999, 'score' => 10, 'remark' => 'x'], $atoken);
check('会员不存在被拒', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

$r = req('POST', '/admin/score/adjust', ['mid' => $mid, 'score' => -10, 'remark' => '余额不足测试'], $atoken);
check('余额0扣负被拒', ($r['json']['code'] ?? 1) !== 0 && balance($mtoken) === 0, $r['raw']);

$r = req('POST', '/admin/score/adjust', ['mid' => $mid, 'score' => 20000, 'remark' => '验收充值'], $atoken);
check('加20000成功（余额20000）', ($r['json']['code'] ?? 1) === 0 && balance($mtoken) === 20000, $r['raw']);

$r = req('POST', '/admin/score/adjust', ['mid' => $mid, 'score' => -500, 'remark' => '验收扣减'], $atoken);
check('扣500成功（余额19500）', ($r['json']['code'] ?? 1) === 0 && balance($mtoken) === 19500, $r['raw']);

$r = req('POST', '/admin/score/adjust', ['mid' => $mid, 'score' => 500, 'remark' => '验收回补'], $atoken);
check('回补500（余额20000）', ($r['json']['code'] ?? 1) === 0 && balance($mtoken) === 20000, $r['raw']);

check('后台流水含调整记录（type=4）', hasLog($atoken, $mid, 4, 20000) && hasLog($atoken, $mid, 4, -500));

$r = req('GET', '/admin/score/log?mid=' . $mid . '&pagesize=5', null, $atoken);
$one = $r['json']['data']['list'][0] ?? null;
check('后台流水契约字段（phone/type/create_time 整数秒）', null !== $one
    && ($one['phone'] ?? '') === '13800138000'
    && array_key_exists('type', $one) && ($one['create_time'] ?? 0) > 0, $r['raw']);

$r = req('GET', '/api/score/log?pagesize=50', null, $mtoken);
$hasAdjust = false;
$apiTimeOk = true;
foreach ($r['json']['data']['list'] ?? [] as $row) {
    if (4 === (int) ($row['type'] ?? 0) && 20000 === (int) ($row['score'] ?? 0)) $hasAdjust = true;
    if (!is_int($row['create_time'] ?? null)) $apiTimeOk = false;
}
check('api 我的积分流水含调整记录（create_time 整数秒）', $hasAdjust && $apiTimeOk, $r['raw']);

// ---- 5. 下单积分抵扣（预扣 + 抵扣上限） ----
$r = req('POST', '/api/order/create', ['goods_id' => $goodsId, 'num' => 1, 'address_id' => $addrId, 'use_score' => 10000], $mtoken);
check('积分下单：10000积分抵100→封顶99.99 实付0.01', ($r['json']['code'] ?? 1) === 0
    && abs((float) ($r['json']['data']['pay_amount'] ?? 0) - 0.01) < 0.0001
    && abs((float) ($r['json']['data']['score_money'] ?? 0) - 99.99) < 0.0001
    && ($r['json']['data']['use_score'] ?? -1) === 10000, $r['raw']);
$orderA = (int) ($r['json']['data']['id'] ?? 0);
check('下单即预扣积分（余额10000）', balance($mtoken) === 10000);
check('预扣流水 type=2', hasLog($atoken, $mid, 2, -10000));

$r = req('POST', '/api/order/cancel', ['id' => $orderA], $mtoken);
check('取消积分订单A', ($r['json']['code'] ?? 1) === 0, $r['raw']);
check('取消返还积分（余额20000）', balance($mtoken) === 20000);
check('返还流水 type=3', hasLog($atoken, $mid, 3, 10000));

// ---- 6. 积分下单 + 支付赠送 ----
$r = req('POST', '/api/order/create', ['goods_id' => $goodsId, 'num' => 1, 'address_id' => $addrId, 'use_score' => 2000], $mtoken);
check('积分下单：2000积分抵20 实付80', ($r['json']['code'] ?? 1) === 0
    && abs((float) ($r['json']['data']['pay_amount'] ?? 0) - 80.0) < 0.001
    && abs((float) ($r['json']['data']['score_money'] ?? 0) - 20.0) < 0.001, $r['raw']);
$orderB = (int) ($r['json']['data']['id'] ?? 0);
check('预扣后余额18000', balance($mtoken) === 18000);

$r = req('POST', '/api/order/pay', ['order_id' => $orderB, 'pay_type' => 'wechat'], $mtoken);
check('积分订单支付成功', ($r['json']['code'] ?? 1) === 0 && ($r['json']['data']['status'] ?? 0) === 1, $r['raw']);
check('支付赠送 5×80=400（余额18400）', balance($mtoken) === 18400);
check('赠送流水 type=1', hasLog($atoken, $mid, 1, 400));

$r = req('GET', '/api/order/detail?id=' . $orderB, null, $mtoken);
check('订单详情带出 use_score/score_money', ($r['json']['data']['use_score'] ?? -1) === 2000
    && abs((float) ($r['json']['data']['score_money'] ?? 0) - 20.0) < 0.001
    && abs((float) ($r['json']['data']['pay_amount'] ?? 0) - 80.0) < 0.001, $r['raw']);

$r = req('GET', '/admin/order/detail?id=' . $orderB, null, $atoken);
check('后台订单详情带出 use_score/score_money', ($r['json']['data']['use_score'] ?? -1) === 2000
    && abs((float) ($r['json']['data']['score_money'] ?? 0) - 20.0) < 0.001, $r['raw']);

// ---- 7. 开关关闭 / 比例未配置 拦截 ----
$r = req('POST', '/admin/score/config', ['score_switch' => 0, 'score_ratio' => 100, 'pay_score' => 5], $atoken);
$r = req('POST', '/api/order/create', ['goods_id' => $goodsId, 'num' => 1, 'address_id' => $addrId, 'use_score' => 100], $mtoken);
check('开关关闭时积分抵扣被拒', ($r['json']['code'] ?? 1) !== 0, $r['raw']);
check('拒绝下单未扣积分（余额18400）', balance($mtoken) === 18400);

$r = req('POST', '/admin/score/config', ['score_switch' => 1, 'score_ratio' => 100, 'pay_score' => 5], $atoken);

$pdo = db();
$pdo->exec("DELETE FROM tenant_config WHERE aid = 1 AND `key` = 'score_ratio'");
$pdo = null;

$r = req('POST', '/api/order/create', ['goods_id' => $goodsId, 'num' => 1, 'address_id' => $addrId, 'use_score' => 100], $mtoken);
check('比例未配置时积分抵扣被拒', ($r['json']['code'] ?? 1) !== 0, $r['raw']);

$r = req('POST', '/admin/score/config', ['score_switch' => 1, 'score_ratio' => 100, 'pay_score' => 5], $atoken);

// ---- 8. 余额不足拦截 ----
$r = req('POST', '/api/order/create', ['goods_id' => $goodsId, 'num' => 1, 'address_id' => $addrId, 'use_score' => 999999], $mtoken);
check('积分余额不足下单被拒', ($r['json']['code'] ?? 1) !== 0, $r['raw']);
check('失败下单未扣积分（余额18400）', balance($mtoken) === 18400);

// ---- 9. 超时命令取消返还 ----
$r = req('POST', '/api/order/create', ['goods_id' => $goodsId, 'num' => 1, 'address_id' => $addrId, 'use_score' => 1000], $mtoken);
check('积分订单C创建（预扣1000 余额17400）', ($r['json']['code'] ?? 1) === 0 && balance($mtoken) === 17400, $r['raw']);
$orderC = (int) ($r['json']['data']['id'] ?? 0);

$pdo = db();
$pdo->exec("UPDATE shop_order SET pay_expire_time = " . (time() - 10) . " WHERE id = {$orderC}");
$pdo = null;
exec('"' . PHP_BIN . '" "' . dirname(__DIR__) . '\\think" order:cancel 2>&1', $out, $code);
check('order:cancel 命令执行成功', 0 === $code && str_contains(implode("\n", $out), '取消'), implode("\n", $out));

$r = req('GET', '/api/order/detail?id=' . $orderC, null, $mtoken);
check('超时积分单被自动取消（状态4）', ($r['json']['data']['status'] ?? -1) === 4, $r['raw']);
check('超时取消返还积分（余额18400）', balance($mtoken) === 18400);

// ---- 10. 未登录拦截 ----
$r = req('GET', '/api/score/log');
check('未登录访问积分流水 401', 401 === $r['status'], $r['raw']);

$r = req('GET', '/api/member/score');
check('未登录访问积分余额 401', 401 === $r['status'], $r['raw']);

echo "\n========== 积分验收结果：{$pass} PASS / {$fail} FAIL ==========\n";
exit($fail > 0 ? 1 : 0);
