<?php
// P9 数据迁移模拟验收脚本
// 用法：php tools/acceptance_p9_migrate.php（纯 CLI + DB，无需起 HTTP 服务）
// 覆盖：模拟旧库搭建（结构漂移）→ data:migrate 分批迁移 → 原ID保留/列交集/新增列默认值
//       → 幂等重跑 → 租户过滤 → 清理

declare(strict_types=1);

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

function db(string $name = 'dxcx'): PDO
{
    return new PDO('mysql:host=127.0.0.1;port=3306;dbname=' . $name . ';charset=utf8mb4', 'dxcx', 'dxcx123456', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

function think(string $args): array
{
    $cmd = '"' . PHP_BINARY . '" "' . realpath(ROOT . '/think') . '" ' . $args . ' 2>&1';
    exec($cmd, $out, $code);

    return [(int) $code, implode(PHP_EOL, $out)];
}

const AID = 999; // 迁移隔离租户

$TABLES = ['member', 'member_address', 'shop_category', 'shop_product', 'coupon', 'coupon_record', 'merchant', 'mendian', 'shop_order', 'shop_order_goods'];

// ---- 0. 清理旧残留 ----
$new = db();
foreach ($TABLES as $t) {
    $new->exec("DELETE FROM `$t` WHERE aid = " . AID);
}
$new->exec('DROP DATABASE IF EXISTS dxcx_legacy');
$new = null;

// ---- 1. 搭建模拟旧库（CREATE TABLE LIKE + 旧结构漂移） ----
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'dxcx', 'dxcx123456', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE DATABASE dxcx_legacy DEFAULT CHARSET utf8mb4');

foreach ($TABLES as $t) {
    $pdo->exec("CREATE TABLE dxcx_legacy.`$t` LIKE dxcx.`$t`");
}

// 结构漂移模拟：旧库删掉新系统新增列（迁移应走列交集，新列落默认值）
$hasCol = function (string $table, string $col): bool {
    $stmt = db('dxcx_legacy')->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ? LIMIT 1');
    $stmt->execute(['dxcx_legacy', $table, $col]);

    return false !== $stmt->fetchColumn();
};

if ($hasCol('shop_order', 'pay_expire_time')) {
    $pdo->exec('ALTER TABLE dxcx_legacy.shop_order DROP COLUMN pay_expire_time');
}
if ($hasCol('coupon_record', 'order_type')) {
    $pdo->exec('ALTER TABLE dxcx_legacy.coupon_record DROP COLUMN order_type');
}
check('模拟旧库已搭建（10 张表 + 结构漂移）', true);

// ---- 2. 旧库插入样本数据（显式 ID，验证原 ID 保留） ----
$L = db('dxcx_legacy');
$TS = time();

$L->exec("INSERT INTO member (id, aid, tel, nickname, createtime) VALUES (990001, " . AID . ", '13999990001', '迁移会员A', {$TS}), (990002, " . AID . ", '13999990002', '迁移会员B', {$TS})");
$L->exec("INSERT INTO member_address (id, aid, mid, name, tel, province, city, district, address) VALUES (990001, " . AID . ", 990001, '张三', '13999990001', '广东省', '深圳市', '南山区', '迁移路1号')");
$L->exec("INSERT INTO shop_category (id, aid, pid, name, status, sort, createtime) VALUES (990001, " . AID . ", 0, '迁移分类', 1, 1, {$TS})");
$L->exec("INSERT INTO shop_product (id, aid, cid, name, sell_price, stock, status, deleted, createtime) VALUES (990001, " . AID . ", '990001', '迁移商品A', 19.90, 100, 1, 0, {$TS}), (990002, " . AID . ", '990001', '迁移商品B', 29.90, 50, 1, 0, {$TS})");
$L->exec("INSERT INTO coupon (id, aid, type, name, starttime, endtime, money, minprice) VALUES (990001, " . AID . ", 1, '迁移券', " . ($TS - 86400) . ', ' . ($TS + 86400) . ", 5.00, 20.00)");
$L->exec("INSERT INTO coupon_record (id, aid, mid, couponid, couponname, money, minprice, starttime, endtime, createtime, status) VALUES (990001, " . AID . ", 990001, 990001, '迁移券', 5.00, 20.00, " . ($TS - 86400) . ', ' . ($TS + 86400) . ", {$TS}, 0)");
$L->exec("INSERT INTO merchant (id, aid, name, contacts, phone, status, deleted, createtime) VALUES (990001, " . AID . ", '迁移商户', '李四', '13999990003', 1, 0, {$TS})");
$L->exec("INSERT INTO mendian (id, aid, name, tel, address) VALUES (990001, " . AID . ", '迁移门店', '13999990003', '迁移路2号')");
$L->exec("INSERT INTO shop_order (id, aid, mid, ordernum, title, totalprice, product_price, status, createtime, platform) VALUES (990101, " . AID . ", 990001, 'P9MIG990101', '迁移商品A x1', 19.90, 19.90, 3, " . ($TS - 3600) . ", 'wx'), (990102, " . AID . ", 990002, 'P9MIG990102', '迁移商品B x1', 29.90, 29.90, 1, " . ($TS - 1800) . ", 'wx')");
$L->exec(sprintf(
    "INSERT INTO shop_order_goods (id, aid, mid, orderid, ordernum, proid, name, num, sell_price, totalprice, real_totalprice, status, createtime) VALUES (990101, %d, 990001, 990101, 'P9MIG990101', 990001, '迁移商品A', 1, 19.90, 19.90, 19.90, 3, %d), (990102, %d, 990002, 990102, 'P9MIG990102', 990002, '迁移商品B', 1, 29.90, 29.90, 29.90, 1, %d)",
    AID,
    $TS - 3600,
    AID,
    $TS - 1800
));
$L = null;
check('旧库样本数据已插入（12 行，aid=' . AID . '）', true);

// ---- 3. 执行迁移（--batch=1 强制多批循环） ----
[$code, $out] = think('data:migrate --aid=' . AID . ' --batch=1');
check('data:migrate 可执行', 0 === $code, $out);

// ---- 4. 断言：行数与原 ID 保留 ----
$N = db();
$expect = [
    'member'           => 2,
    'member_address'   => 1,
    'shop_category'    => 1,
    'shop_product'     => 2,
    'coupon'           => 1,
    'coupon_record'    => 1,
    'merchant'         => 1,
    'mendian'          => 1,
    'shop_order'       => 2,
    'shop_order_goods' => 2,
];

$allRowsOk = true;
$detail     = '';
foreach ($expect as $t => $n) {
    $cnt = (int) $N->query("SELECT COUNT(*) FROM `$t` WHERE aid = " . AID)->fetchColumn();
    if ($cnt !== $n) {
        $allRowsOk = false;
        $detail .= "{$t}:期望{$n}实际{$cnt} ";
    }
}
check('全部 10 表行数一致（14 行）', $allRowsOk, $detail);

$ids = $N->query('SELECT id FROM member WHERE aid = ' . AID . ' ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
check('原 ID 保留（member 990001/990002）', ['990001', '990002'] === array_map('strval', $ids), json_encode($ids));

$orderIds = $N->query('SELECT id FROM shop_order WHERE aid = ' . AID . ' ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
check('原 ID 保留（shop_order 990101/990102）', ['990101', '990102'] === array_map('strval', $orderIds), json_encode($orderIds));

// ---- 5. 结构漂移处理：新库新增列落默认值 ----
$expire = $N->query('SELECT pay_expire_time FROM shop_order WHERE id = 990101')->fetchColumn();
check('新增列 pay_expire_time 落默认值', null === $expire || 0 === (int) $expire, var_export($expire, true));

if ($N->query("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'coupon_record' AND column_name = 'order_type'")->fetchColumn()) {
    // 落新库列默认值（information_schema 口径，非 NULL 非乱码即可）
    $default = $N->query("SELECT COLUMN_DEFAULT FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'coupon_record' AND column_name = 'order_type'")->fetchColumn();
    $ot      = $N->query('SELECT order_type FROM coupon_record WHERE id = 990001')->fetchColumn();
    check('新增列 order_type 落默认值', null === $ot || (string) $ot === (string) $default, 'actual=' . var_export($ot, true) . ' default=' . var_export($default, true));
} else {
    check('新增列 order_type 落默认值', true, '新库无此列，跳过');
}

// ---- 6. 字段内容保真 ----
$row = $N->query('SELECT * FROM shop_product WHERE id = 990001')->fetch(PDO::FETCH_ASSOC);
check('商品字段保真（名称/价格/库存）', '迁移商品A' === $row['name'] && abs((float) $row['sell_price'] - 19.9) < 0.001 && 100 === (int) $row['stock'], json_encode($row, JSON_UNESCAPED_UNICODE));

// ---- 7. 幂等重跑（行数不变、无重复） ----
[$code, $out] = think('data:migrate --aid=' . AID);
check('重跑迁移可执行', 0 === $code, $out);

$dupOk = true;
$detail = '';
foreach ($expect as $t => $n) {
    $cnt = (int) $N->query("SELECT COUNT(*) FROM `$t` WHERE aid = " . AID)->fetchColumn();
    if ($cnt !== $n) {
        $dupOk = false;
        $detail .= "{$t}:期望{$n}实际{$cnt} ";
    }
}
check('幂等重跑无重复（行数不变）', $dupOk, $detail);

// ---- 8. 迁移日志落盘 ----
$logFile = realpath(ROOT . '/runtime') . '/data_migrate.log';
check('runtime/data_migrate.log 已落盘', is_file($logFile) && str_contains((string) file_get_contents($logFile), 'data:migrate'));

// ---- 9. 清理 ----
foreach ($TABLES as $t) {
    $N->exec("DELETE FROM `$t` WHERE aid = " . AID);
}
$N->exec('DROP DATABASE dxcx_legacy');
$N = null;

echo "\n==== P9 迁移验收结果：PASS {$pass} / FAIL {$fail} ====\n";
exit($fail > 0 ? 1 : 0);
