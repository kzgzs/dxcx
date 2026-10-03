<?php
/**
 * 迁移：多人拼团补列
 * - collage_product 加 goods_id(关联商城商品)、no_discount(会员等级折扣 -1跟随/0参与/1不参与)
 * - collage_sysset 加 leveldiscount(会员等级折扣开关 0关 1开)
 *
 * 幂等：SHOW COLUMNS 检查不存在则 ALTER TABLE ADD，可重复执行。
 * 用法：php E:\Dxcx\app\migrate\add_collage_missing_columns.php
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

$app = new \think\App();
$app->initialize();

use think\facade\Db;

$prefix = (string) env('database.prefix', '');

function add_column(string $prefix, string $tableRaw, string $column, string $ddl): void
{
    $table = $prefix . $tableRaw;
    $exists = Db::query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    if (!empty($exists)) {
        echo "[skip] {$table}.{$column} already exists\n";
        return;
    }
    Db::execute("ALTER TABLE `{$table}` ADD COLUMN {$ddl}");
    echo "[done] {$table}.{$column} added\n";
}

add_column($prefix, 'collage_product', 'goods_id', "`goods_id` int(11) NOT NULL DEFAULT 0 COMMENT '关联商城商品ID'");
add_column($prefix, 'collage_product', 'no_discount', "`no_discount` tinyint(1) NOT NULL DEFAULT -1 COMMENT '会员等级折扣 -1跟随 0参与 1不参与'");
add_column($prefix, 'collage_sysset', 'leveldiscount', "`leveldiscount` tinyint(1) NOT NULL DEFAULT 1 COMMENT '会员等级折扣开关 0关闭 1开启'");

echo "migration finished\n";
