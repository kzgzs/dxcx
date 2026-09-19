<?php
// P8 一期验收脚本（schedule:run 统一调度 + pay:reconcile 对账 + 行业超时）
// 用法：php tools/acceptance_p8.php（纯 CLI + DB，无需起 HTTP 服务）
// 覆盖：商城超时取消+库存回补 / Redis 并发锁 / 回调丢失对账捞回 / 预约超时未接单退款
//       / 酒店与网约车未支付超时取消 / 各项幂等 / schedule.log 落盘

declare(strict_types=1);

// 与服务端业务口径对齐（CLI 默认 UTC 会串时区）
date_default_timezone_set('Asia/Shanghai');

const ROOT = __DIR__ . '/..';

$pass = 0;
$fail = 0;

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

/** 运行 think 命令，返回 [exitCode, output] */
function think(string $args): array
{
    $think = realpath(ROOT . '/think');
    $cmd   = '"' . PHP_BINARY . '" "' . $think . '" ' . $args . ' 2>&1';
    exec($cmd, $out, $code);

    return [(int) $code, implode(PHP_EOL, $out)];
}

function redis(): \Redis
{
    $client = new \Redis();
    $client->connect('127.0.0.1', 6379, 3.0);

    return $client;
}

$TS  = time();
$pdo = db();

// ---- 0. 环境清理（脚本可独立重跑） ----
$pdo->exec("DELETE FROM shop_order WHERE title LIKE 'P8验收%'");
$pdo->exec("DELETE FROM shop_order_goods WHERE name LIKE 'P8验收%'");
$pdo->exec("UPDATE shop_product SET deleted = 1 WHERE name LIKE 'P8验收商品%'");
$pdo->exec("DELETE FROM hotel_order WHERE order_sn LIKE 'P8AC%'");
$pdo->exec("DELETE FROM carhailing_order WHERE order_sn LIKE 'P8AC%'");
$pdo->exec("DELETE po FROM pay_order po INNER JOIN yuyue_order yo ON yo.id = po.biz_order_id WHERE po.order_type = 'yuyue' AND yo.proname LIKE 'P8验收%'");
$pdo->exec("DELETE FROM yuyue_order WHERE proname LIKE 'P8验收%'");
$pdo->exec("DELETE FROM pay_order WHERE status = 0 AND out_trade_no LIKE 'PTP8AC%'");
$pdo->exec("DELETE FROM pay_order WHERE status = 0"); // 清除历史残留待支付单，保证对账计数确定
$pdo->exec("DELETE FROM member WHERE aid = 1 AND tel LIKE '13899999%'");
try {
    redis()->del('dxcx:schedule:lock');
} catch (\Throwable $e) {
    // Redis 不可用时后续锁用例自然 FAIL
}

// 种子会员
$mid = (int) $pdo->query("SELECT id FROM member WHERE aid = 1 AND tel = '13800138000' LIMIT 1")->fetchColumn();
if ($mid <= 0) {
    $pdo->exec("INSERT INTO member (aid, tel, password, nickname, createtime) VALUES (1, '13800138000', '" . md5('123456') . "', 'P8种子', {$TS})");
    $mid = (int) $pdo->lastInsertId();
}
check('种子会员就绪', $mid > 0);

// 测试商品（优先复用现有上架商品，否则自建）
$productId   = (int) $pdo->query("SELECT id FROM shop_product WHERE aid = 1 AND status = 1 AND deleted = 0 LIMIT 1")->fetchColumn();
$selfProduct = false;
if ($productId <= 0) {
    $pdo->exec("INSERT INTO shop_product (aid, name, sell_price, stock, status, deleted, createtime) VALUES (1, 'P8验收商品_{$TS}', 5.00, 100, 1, 0, {$TS})");
    $productId   = (int) $pdo->lastInsertId();
    $selfProduct = true;
}
$stockBefore = (int) $pdo->query("SELECT stock FROM shop_product WHERE id = {$productId}")->fetchColumn();
check('测试商品就绪（stock=' . $stockBefore . '）', $productId > 0);

// ---- A. 商城超时未支付取消 + 库存回补 ----
$pdo->exec("INSERT INTO shop_order (aid, mid, ordernum, title, totalprice, product_price, status, createtime, pay_expire_time, platform)
            VALUES (1, {$mid}, 'P8AC{$TS}', 'P8验收订单_{$TS}', 10.00, 10.00, 0, " . ($TS - 700) . ', ' . ($TS - 10) . ", 'api')");
$shopOrderId = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO shop_order_goods (aid, mid, orderid, ordernum, proid, name, num, sell_price, totalprice, real_totalprice, status, createtime)
            VALUES (1, {$mid}, {$shopOrderId}, 'P8AC{$TS}', {$productId}, 'P8验收商品', 2, 5.00, 10.00, 10.00, 0, {$TS})");

[$code, $out] = think('schedule:run');
check('schedule:run 可执行', 0 === $code, $out);
check('商城超时取消子项 OK', str_contains($out, '[OK] order.cancel_expired'), $out);

$status = (int) $pdo->query("SELECT status FROM shop_order WHERE id = {$shopOrderId}")->fetchColumn();
check('超时未支付订单已取消（status=4）', 4 === $status, 'status=' . $status);

$stockAfter = (int) $pdo->query("SELECT stock FROM shop_product WHERE id = {$productId}")->fetchColumn();
check('取消后库存回补 +2', $stockBefore + 2 === $stockAfter, "before={$stockBefore} after={$stockAfter}");

// 幂等：重复执行不重复回补
[$code, $out] = think('schedule:run');
$stockTwice = (int) $pdo->query("SELECT stock FROM shop_product WHERE id = {$productId}")->fetchColumn();
check('重复调度幂等（库存不再变化）', $stockAfter === $stockTwice, "after2={$stockTwice}");
check('重复调度取消计数为0', str_contains($out, '商城超时未支付取消 0 笔'), $out);

// ---- B. Redis 并发锁 ----
$lock = redis()->set('dxcx:schedule:lock', (string) $TS, ['nx', 'ex' => 55]);
check('手工占锁成功', true === $lock);

[$code, $out] = think('schedule:run');
check('锁被占用时本轮跳过', str_contains($out, '另一个调度实例正在运行'), $out);
redis()->del('dxcx:schedule:lock');

// ---- C. pay:reconcile 回调丢失捞回 ----
$pdo->exec("INSERT INTO yuyue_order (aid, mid, ordernum, title, proname, totalprice, product_price, pay_amount, status, paytime, createtime, num, fwtype)
            VALUES (1, {$mid}, 'YYP8AC{$TS}', 'P8验收预约', 'P8验收预约项目', 10.00, 10.00, 10.00, 0, 0, " . ($TS - 300) . ', 1, 1)');
$yuyueOrderId = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO pay_order (aid, order_type, biz_order_id, out_trade_no, pay_amount, pay_type, status, pay_time, createtime)
            VALUES (1, 'yuyue', {$yuyueOrderId}, 'PTP8AC{$TS}', 10.00, 'wechat', 0, 0, " . ($TS - 300) . ')');

[$code, $out] = think('pay:reconcile');
check('pay:reconcile 可执行', 0 === $code, $out);
check('对账捞回 1 笔', str_contains($out, '捞回 1 笔'), $out);

$payStatus = (int) $pdo->query("SELECT status FROM pay_order WHERE out_trade_no = 'PTP8AC{$TS}'")->fetchColumn();
$yyStatus  = (int) $pdo->query("SELECT status FROM yuyue_order WHERE id = {$yuyueOrderId}")->fetchColumn();
check('支付单入账（pay_order.status=1）', 1 === $payStatus, 'status=' . $payStatus);
check('行业单联动入账（yuyue_order.status=1）', 1 === $yyStatus, 'status=' . $yyStatus);

[$code, $out] = think('pay:reconcile');
check('对账幂等（重复执行捞回0笔）', str_contains($out, '捞回 0 笔'), $out);

// ---- D. 预约超时未接单自动退款 ----
$pdo->exec("UPDATE yuyue_order SET paytime = " . ($TS - 61 * 60) . " WHERE id = {$yuyueOrderId}");

[$code, $out] = think('schedule:run');
check('预约超时退款子项 OK', str_contains($out, '[OK] yuyue.accept_timeout_refund'), $out);

$row = $pdo->query("SELECT status, refund_status, refund_money, refund_reason FROM yuyue_order WHERE id = {$yuyueOrderId}")->fetch(PDO::FETCH_ASSOC);
check('预约单已退款（status=5）', 5 === (int) $row['status'], json_encode($row, JSON_UNESCAPED_UNICODE));
check('退款状态标记 refund_status=2', 2 === (int) $row['refund_status']);
check('退款金额=实付 10.00', abs((float) $row['refund_money'] - 10.0) < 0.001);
check('退款原因=超时未接单退款', '超时未接单退款' === (string) $row['refund_reason']);

[$code, $out] = think('schedule:run');
check('预约退款幂等（计数0）', str_contains($out, '预约超时未接单退款 0 笔'), $out);

// ---- E. 酒店 / 网约车未支付超时取消 ----
$pdo->exec("INSERT INTO hotel_order (aid, mid, order_sn, hotel_name, room_name, pay_amount, status, name, phone, createtime)
            VALUES (1, {$mid}, 'P8ACH{$TS}', 'P8验收酒店', 'P8验收房型', 50.00, 0, 'P8', '13800138000', " . ($TS - 700) . ')');
$hotelOrderId = (int) $pdo->lastInsertId();

$pdo->exec("INSERT INTO carhailing_order (aid, mid, order_sn, product_name, pay_amount, status, createtime)
            VALUES (1, {$mid}, 'P8ACC{$TS}', 'P8验收车辆', 60.00, 0, " . ($TS - 700) . ')');
$carOrderId = (int) $pdo->lastInsertId();

[$code, $out] = think('schedule:run');
$hotelStatus = (int) $pdo->query("SELECT status FROM hotel_order WHERE id = {$hotelOrderId}")->fetchColumn();
$carStatus   = (int) $pdo->query("SELECT status FROM carhailing_order WHERE id = {$carOrderId}")->fetchColumn();
check('酒店未支付超时取消（status=4）', 4 === $hotelStatus, 'status=' . $hotelStatus);
check('网约车未支付超时取消（status=4）', 4 === $carStatus, 'status=' . $carStatus);

[$code, $out] = think('schedule:run');
check('行业取消幂等（酒店计数0）', str_contains($out, '酒店未支付超时取消 0 笔'), $out);
check('行业取消幂等（网约车计数0）', str_contains($out, '网约车未支付超时取消 0 笔'), $out);

// ---- F. 调度日志落盘 ----
$logFile = realpath(ROOT . '/runtime') . '/schedule.log';
check('runtime/schedule.log 已落盘', is_file($logFile));
check('日志含 schedule:run 记录', is_file($logFile) && str_contains((string) file_get_contents($logFile), 'schedule:run'));

// ---- 9. 清理 ----
$pdo->exec("DELETE FROM shop_order WHERE id = {$shopOrderId}");
$pdo->exec("DELETE FROM shop_order_goods WHERE orderid = {$shopOrderId}");
$pdo->exec("DELETE po FROM pay_order po INNER JOIN yuyue_order yo ON yo.id = po.biz_order_id WHERE yo.id = {$yuyueOrderId}");
$pdo->exec("DELETE FROM yuyue_order WHERE id = {$yuyueOrderId}");
$pdo->exec("DELETE FROM hotel_order WHERE id = {$hotelOrderId}");
$pdo->exec("DELETE FROM carhailing_order WHERE id = {$carOrderId}");
if ($selfProduct) {
    $pdo->exec("DELETE FROM shop_product WHERE id = {$productId}");
}
$pdo = null;

echo "\n==== P8 验收结果：PASS {$pass} / FAIL {$fail} ====\n";
exit($fail > 0 ? 1 : 0);
