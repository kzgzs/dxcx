<?php
/**
 * 迁移：幸运拼团商品补列（按 24 项字段清单）
 * - lucky_collage_product 加 goods_id(关联商城商品)、no_discount(会员等级折扣 -1跟随/0参与/1不参与)、freightid(选择模板时的模板id)
 * - lucky_collage_guige 所需 规格编号(procode)/单买价(market_price)/重量(weight)/图片(pic) 均已存在，无需补列
 *
 * 幂等：SHOW COLUMNS 检查不存在则 ALTER TABLE ADD，可重复执行。
 * 用法：php E:\Dxcx\app\migrate\add_lucky_collage_product_columns.php
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

$app = new \think\App();
$app->initialize();

use think\facade\Db;

$prefix = (string) env('database.prefix', '');

function add_column_lucky(string $prefix, string $tableRaw, string $column, string $ddl): void
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

add_column_lucky($prefix, 'lucky_collage_product', 'goods_id', "`goods_id` int(11) NOT NULL DEFAULT 0 COMMENT '关联商城商品ID'");
add_column_lucky($prefix, 'lucky_collage_product', 'no_discount', "`no_discount` tinyint(2) NOT NULL DEFAULT -1 COMMENT '会员等级折扣 -1跟随系统 0参与 1不参与'");
add_column_lucky($prefix, 'lucky_collage_product', 'freightid', "`freightid` int(11) NOT NULL DEFAULT 0 COMMENT '配送模板ID(freighttype=0选择模板时)'");

echo "migration finished\n";
