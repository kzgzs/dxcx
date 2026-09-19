<?php
/**
 * sql2migration.php —— 一次性转换工具（独立脚本，不依赖框架引导）
 *
 * 用法：
 *   php tools/sql2migration.php            # 生成迁移文件到 database/migrations
 *   php tools/sql2migration.php --dry-run  # 只输出解析/分组统计，不写文件
 *
 * 功能：
 *   1. 解析 canzhao/sql.sql（旧系统 mysqldump，346 张表 + 34 条 INSERT 初始化数据）
 *   2. 清洗规则：
 *      - 去掉 ddwx_ 表前缀（新系统 prefix 为空）
 *      - 确保 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
 *      - 去除 ZEROFILL（MySQL 8.0.17+ 已废弃，同时把 '000...' 默认值规范为 '0'）
 *      - AUTO_INCREMENT=xxx 初始值清零为 1（保留自增属性）
 *      - 去除 dump 特有的条件注释行（如 !40101/!40014 等 MySQL 版本号包裹的 SET 语句）
 *   3. 按业务模块分组输出 think-migration（Phinx）迁移文件：
 *      全部使用 $this->execute('原始 DDL') 保持结构零失真，不用 builder 逐列转写
 *   4. INSERT 初始化数据清洗后输出为独立数据迁移文件（在全部建表迁移之后执行）
 *   5. 末尾追加索引补强迁移（高频联合索引，仅对列确实存在的大表生效）
 *
 * 重复执行安全：会先清理带 GENERATED-BY sql2migration 标记的旧输出文件。
 */

error_reporting(E_ALL);
ini_set('memory_limit', '1024M');

$dryRun = in_array('--dry-run', $argv);
$root   = dirname(__DIR__);
$source = $root . '/canzhao/sql.sql';
$migDir = $root . '/database/migrations';

if (!is_file($source)) {
    fwrite(STDERR, "[ERROR] 源文件不存在: $source\n");
    exit(1);
}
$sql = file_get_contents($source);
if ($sql === false) {
    fwrite(STDERR, "[ERROR] 读取失败: $source\n");
    exit(1);
}
// 统一换行（源文件为 CRLF）
$sql = str_replace("\r\n", "\n", $sql);

/* --------------------------------------------------------------------------
 * 1. 提取 CREATE TABLE 与 INSERT INTO
 * ------------------------------------------------------------------------ */

// CREATE TABLE `ddwx_xxx` ( ... ) ENGINE=xxx;
preg_match_all('/^CREATE TABLE `ddwx_(\w+)` \((.*?)^\) ENGINE=[^\n]*/ms', $sql, $ms, PREG_SET_ORDER);
$creates = [];
$columns = []; // 表名 => 列名数组（供索引补强校验）
$existingKeys = []; // 表名 => 已有索引列组合（小写串）
foreach ($ms as $m) {
    $creates[$m[1]] = $m[0];
    preg_match_all('/^  `(\w+)`/m', $m[2], $cm);
    $columns[$m[1]] = $cm[1];
    preg_match_all('/(?:UNIQUE )?KEY `\w+` \(([^)]+)\)/', $m[2], $km);
    $existingKeys[$m[1]] = array_map(function ($cols) {
        return strtolower(preg_replace('/[`\s]+/', '', $cols));
    }, $km[1]);
}

// INSERT INTO `ddwx_xxx` VALUES (...);  （dump 中均为单行）
preg_match_all('/^INSERT INTO `ddwx_(\w+)`[^\n]*/m', $sql, $im);
$inserts = [];
foreach ($im[0] as $i => $stmt) {
    $inserts[] = ['table' => $im[1][$i], 'stmt' => $stmt];
}

printf("[解析] CREATE TABLE: %d 条, INSERT INTO: %d 条\n", count($creates), count($inserts));
if (count($creates) === 0) {
    fwrite(STDERR, "[ERROR] 未解析到任何 CREATE TABLE，终止\n");
    exit(1);
}

/* --------------------------------------------------------------------------
 * 2. 清洗函数
 * ------------------------------------------------------------------------ */

/** 去除 mysqldump 条件注释行（独立成行的 !40101 ... ; 等版本号包裹语句） */
function stripDumpCommentLines(string $text): string
{
    $out = [];
    foreach (explode("\n", $text) as $line) {
        if (preg_match('/^\s*\/\*!\d+.*\*\/;\s*$/', $line)) {
            continue;
        }
        $out[] = $line;
    }
    return implode("\n", $out);
}

/** 清洗 CREATE TABLE 语句 */
function cleanCreate(string $table, string $stmt): string
{
    $stmt = stripDumpCommentLines($stmt);
    // 去 ddwx_ 前缀
    $stmt = str_replace("CREATE TABLE `ddwx_{$table}`", "CREATE TABLE `{$table}`", $stmt);

    // 去 ZEROFILL（MySQL 8.0.17+ 废弃），并把纯 0 数字默认值规范为 '0'
    $lines = explode("\n", $stmt);
    foreach ($lines as &$line) {
        if (stripos($line, 'zerofill') !== false) {
            $line = preg_replace('/\s+zerofill/i', '', $line);
            $line = preg_replace("/DEFAULT '(0+)'/", "DEFAULT '0'", $line);
        }
    }
    unset($line);
    $stmt = implode("\n", $lines);

    // AUTO_INCREMENT=xxx 清零（保留自增属性，初始值从 1 开始）
    $stmt = preg_replace('/AUTO_INCREMENT=\d+/', 'AUTO_INCREMENT=1', $stmt);

    // 字符集统一 utf8mb4 + utf8mb4_general_ci（不误伤已有 utf8mb4）
    $stmt = preg_replace('/CHARSET=utf8(mb3)?\b/', 'CHARSET=utf8mb4', $stmt);
    if (preg_match('/COLLATE=\w+/', $stmt)) {
        $stmt = preg_replace('/COLLATE=\w+/', 'COLLATE=utf8mb4_general_ci', $stmt);
    } else {
        $stmt = preg_replace('/CHARSET=utf8mb4/', 'CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci', $stmt, 1);
    }

    return rtrim(rtrim($stmt), ';');
}

/** 清洗 INSERT 语句 */
function cleanInsert(string $table, string $stmt): string
{
    $stmt = str_replace("INSERT INTO `ddwx_{$table}`", "INSERT INTO `{$table}`", $stmt);
    return rtrim(rtrim($stmt), ';');
}

/* --------------------------------------------------------------------------
 * 3. 业务模块分组规则（按顺序首个命中生效）
 * ------------------------------------------------------------------------ */

$groupRules = [
    'cardcode_hexiao_tables' => '/^(hexiao|.*_codelist)/',           // 卡密核销
    'restaurant_tables'      => '/^restaurant/',                     // 餐饮
    'yuyue_tables'           => '/^yuyue/',                          // 预约
    'delivery_express_tables'=> '/^(peisong|express|ewaybill|freight)/', // 配送物流
    'designer_tables'        => '/^(designer|designerpage|renovation)/', // 设计装修
    'content_tables'         => '/^(article|kecheng|shortvideo|luntan|wxvideo|video|live|guanggao)/', // 内容
    'marketing_tables'       => '/^(coupon|collage|kanjia|seckill|tuangou|choujiang|lipin|cuxiao|lucky|sign|signset|toupiao|fifa|cashback|cycle|scoreshop|manjian|payaftergive|dscj|sendredpack)/', // 营销
    'paylog_transfer_tables' => '/^(payorder|pay|wxpay|wxrefund|wx_transfer|.*_pay_log|alipay|baidupay|toutiaopay|sxpay|cashier|recharge)/', // 支付日志/转账
    'shop_order_tables'      => '/^(shop|order|ordernum|miandan|maidan)/', // 商品订单
    'business_mendian_tables'=> '/^(business|mendian)/',             // 商户门店
    'core_tables'            => '/^(admin|member|membercard|sysset|access|session|smslog|jobs|register|open|shortcut|kefu|help|invoice|largearea|headimg)/', // 核心
];
$groupFallback = 'misc_tables';                                      // 其余兜底

$groupTitles = [
    'core_tables'             => '核心（admin/member/sysset/config 等）',
    'shop_order_tables'       => '商品订单（shop_/order/买单免单）',
    'business_mendian_tables' => '商户门店（business/mendian）',
    'marketing_tables'        => '营销（coupon/collage/kanjia/seckill/tuangou/choujiang/lipin/cuxiao 等）',
    'restaurant_tables'       => '餐饮（restaurant_）',
    'yuyue_tables'            => '预约（yuyue_）',
    'cardcode_hexiao_tables'  => '卡密核销（*_codelist/hexiao）',
    'content_tables'          => '内容（article/kecheng/shortvideo/forum 等）',
    'delivery_express_tables' => '配送物流（peisong/express/ewaybill/freight）',
    'designer_tables'         => '设计装修（designer/renovation）',
    'paylog_transfer_tables'  => '支付日志（*pay_log/wx_transfer/payorder/cashier/recharge）',
    'misc_tables'             => '其余兜底（mp/wx/杂项）',
];

$groups = [];
foreach ($creates as $table => $stmt) {
    $target = $groupFallback;
    foreach ($groupRules as $g => $rule) {
        if (preg_match($rule, $table)) {
            $target = $g;
            break;
        }
    }
    $groups[$target][] = $table;
}

echo "[分组] 结果:\n";
foreach ($groupRules as $g => $rule) {
    printf("  %-26s %3d 张  %s\n", $g, count($groups[$g] ?? []), $groupTitles[$g]);
}
printf("  %-26s %3d 张  %s\n", $groupFallback, count($groups[$groupFallback] ?? []), $groupTitles[$groupFallback]);
$total = array_sum(array_map('count', $groups));
printf("  合计 %d 张表\n", $total);
if ($total !== count($creates)) {
    fwrite(STDERR, "[ERROR] 分组合计与表总数不一致\n");
    exit(1);
}

/* --------------------------------------------------------------------------
 * 4. 索引补强计划（仅对列确实存在、且已有索引未覆盖的大表生成 ALTER）
 * ------------------------------------------------------------------------ */

$indexPlan = [
    // [表名, 索引名, 列, 注释]
    ['shop_order',              'idx_aid_status_ct', ['aid', 'status', 'createtime'], '核心商城订单：按状态筛选+时间排序'],
    ['shop_order',              'idx_aid_mid_ct',    ['aid', 'mid', 'createtime'],    '核心商城订单：查指定会员订单'],
    ['payorder',                'idx_aid_status_ct', ['aid', 'status', 'createtime'], '统一支付订单：按状态筛选+时间排序'],
    ['shop_refund_order',       'idx_aid_status_ct', ['aid', 'status', 'createtime'], '售后退款单：按状态筛选+时间排序'],
    ['member_moneylog',         'idx_aid_mid_ct',    ['aid', 'mid', 'createtime'],    '会员余额流水：查指定会员流水'],
    ['member_scorelog',         'idx_aid_mid_ct',    ['aid', 'mid', 'createtime'],    '会员积分流水：查指定会员流水'],
    ['member_commissionlog',    'idx_aid_mid_ct',    ['aid', 'mid', 'createtime'],    '会员佣金流水：查指定会员流水'],
    ['wxpay_log',               'idx_aid_mid_ct',    ['aid', 'mid', 'createtime'],    '微信支付日志：查指定会员支付记录'],
    ['yuyue_order',             'idx_aid_status_ct', ['aid', 'status', 'createtime'], '预约订单：按状态筛选+时间排序'],
    ['restaurant_takeaway_order','idx_aid_status_ct',['aid', 'status', 'createtime'], '餐饮外卖订单：按状态筛选+时间排序'],
];

$indexStmts = [];
foreach ($indexPlan as [$t, $name, $cols, $why]) {
    if (!isset($columns[$t])) {
        echo "[索引] 跳过 {$t}：表不存在\n";
        continue;
    }
    $missing = array_diff($cols, $columns[$t]);
    if ($missing) {
        echo "[索引] 跳过 {$t}.{$name}：缺列 " . implode(',', $missing) . "\n";
        continue;
    }
    $sig = strtolower(implode(',', $cols));
    if (in_array($sig, $existingKeys[$t], true)) {
        echo "[索引] 跳过 {$t}.{$name}：已有相同索引\n";
        continue;
    }
    $existingKeys[$t][] = $sig;
    $colStr = implode('`, `', $cols);
    $indexStmts[] = "-- {$t}: {$why}\nALTER TABLE `{$t}` ADD INDEX `{$name}` (`{$colStr}`)";
}
printf("[索引] 最终生成 %d 条联合索引（上限 10）\n", count($indexStmts));

/* --------------------------------------------------------------------------
 * 5. 生成迁移文件
 * ------------------------------------------------------------------------ */

/** 生成 Phinx 迁移 PHP 文件内容 */
function renderMigration(string $class, string $header, array $execStmts): string
{
    $body = '';
    foreach ($execStmts as $stmt) {
        $body .= "        \$this->execute(" . var_export($stmt, true) . ");\n";
    }
    return <<<PHP
<?php
// GENERATED-BY sql2migration （一次性基线生成，勿手工逐行编辑）
{$header}
use think\\migration\\Migrator;

class {$class} extends Migrator
{
    public function change()
    {
{$body}    }
}

PHP;
}

/** 文件名 => 类名（与 Phinx Util::mapFileNameToClassName 规则一致） */
function className(string $slug): string
{
    return str_replace('_', '', ucwords($slug, '_'));
}

if ($dryRun) {
    echo "[dry-run] 不写入文件\n";
    exit(0);
}

if (!is_dir($migDir)) {
    mkdir($migDir, 0777, true);
}
// 清理本工具之前生成的文件（幂等）
foreach (glob($migDir . '/*.php') ?: [] as $f) {
    $head = file_get_contents($f, false, null, 0, 200);
    if ($head !== false && strpos($head, 'GENERATED-BY sql2migration') !== false) {
        unlink($f);
        echo "[清理] 删除旧输出 " . basename($f) . "\n";
    }
}

// 分组迁移文件顺序（业务依赖：先建表模块，再数据，最后索引）
$orderedGroups = array_keys($groupRules);
$orderedGroups[] = $groupFallback;

$seq      = 0;
$written  = [];
foreach ($orderedGroups as $g) {
    if (empty($groups[$g])) {
        continue;
    }
    $seq++;
    $version = sprintf('20260812%06d', $seq);
    sort($groups[$g]);
    $stmts = [];
    foreach ($groups[$g] as $table) {
        $stmts[] = cleanCreate($table, $creates[$table]);
    }
    $header = "// 基线迁移[{$seq}/14] {$groupTitles[$g]}\n"
        . "// 共 " . count($stmts) . " 张表：" . implode(', ', $groups[$g]) . "\n"
        . "// 清洗：去 ddwx_ 前缀 / utf8mb4+utf8mb4_general_ci / 去 ZEROFILL / AUTO_INCREMENT 归 1 / 去 dump 条件注释\n";
    $file = "{$migDir}/{$version}_{$g}.php";
    file_put_contents($file, renderMigration(className($g), $header, $stmts));
    $written[] = basename($file);
}

// 数据迁移（全部 INSERT，放在建表迁移之后）
$seq++;
$dataStmts = [];
foreach ($inserts as $ins) {
    $dataStmts[] = cleanInsert($ins['table'], $ins['stmt']);
}
$header = "// 基线迁移[{$seq}/14] 初始化数据（旧系统 dump 中的 INSERT，共 " . count($dataStmts) . " 条）\n"
    . "// 涉及表：" . implode(', ', array_unique(array_column($inserts, 'table'))) . "\n"
    . "// 清洗：仅去 ddwx_ 前缀，数据保持原样\n";
$file = "{$migDir}/" . sprintf('20260812%06d', $seq) . "_init_data.php";
file_put_contents($file, renderMigration('InitData', $header, $dataStmts));
$written[] = basename($file);

// 索引补强迁移（最后执行）
$seq++;
$header = "// 基线迁移[{$seq}/14] 索引补强：高频联合索引（aid,status,createtime）/（aid,mid,createtime）\n"
    . "// 仅针对订单、会员流水、支付日志等明显受益的大表，共 " . count($indexStmts) . " 条（控制在 10 条内）\n";
$indexBody = '';
foreach ($indexStmts as $s) {
    $indexBody .= "        \$this->execute(" . var_export($s, true) . ");\n";
}
$content = <<<PHP
<?php
// GENERATED-BY sql2migration （一次性基线生成，勿手工逐行编辑）
{$header}
use think\\migration\\Migrator;

class IndexEnhance extends Migrator
{
    public function change()
    {
{$indexBody}    }
}

PHP;
$file = "{$migDir}/" . sprintf('20260812%06d', $seq) . "_index_enhance.php";
file_put_contents($file, $content);
$written[] = basename($file);

echo "\n[输出] database/migrations/ 共 " . count($written) . " 个迁移文件:\n";
foreach ($written as $f) {
    echo "  - database/migrations/{$f}\n";
}
echo "\n完成。后续步骤：\n";
echo "  1) php think migrate:run\n";
echo "  2) php think seed:run   （seeds 需手工编写：超管账号/平台配置）\n";
