<?php
/**
 * 迁移：yuyue_product 表增加 level_discount 列（预约服务商品-会员等级折扣）
 *
 * 对齐截图「会员等级折扣：跟随系统 / 参与 / 不参与」。
 * 0=跟随系统（默认） 1=参与 2=不参与。
 * 幂等：SHOW COLUMNS 检查不存在则 ALTER TABLE ADD，可重复执行。
 * 用法：php E:\Dxcx\app\migrate\add_yuyue_product_level_discount.php
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

$app = new \think\App();
$app->initialize();

use think\facade\Db;

$table = (string) env('database.prefix', '') . 'yuyue_product';

$exists = Db::query("SHOW COLUMNS FROM `{$table}` LIKE 'level_discount'");

if (!empty($exists)) {
    echo "[skip] column level_discount already exists in {$table}\n";
    exit(0);
}

Db::execute("ALTER TABLE `{$table}` ADD COLUMN `level_discount` tinyint(1) NOT NULL DEFAULT 0 COMMENT '会员等级折扣 0跟随系统 1参与 2不参与'");

echo "[done] column level_discount added to {$table}\n";
