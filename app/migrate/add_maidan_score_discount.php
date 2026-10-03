<?php
/**
 * 迁移：admin_set 表增加 maidan_score_discount 列（聚合收款码-会员等级折扣开关）
 *
 * 幂等：SHOW COLUMNS 检查不存在则 ALTER TABLE ADD，可重复执行。
 * 用法：php E:\Dxcx\app\migrate\add_maidan_score_discount.php
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

$app = new \think\App();
$app->initialize();

use think\facade\Db;

$table = (string) env('database.prefix', '') . 'admin_set';

$exists = Db::query("SHOW COLUMNS FROM `{$table}` LIKE 'maidan_score_discount'");

if (!empty($exists)) {
    echo "[skip] column maidan_score_discount already exists in {$table}\n";
    exit(0);
}

Db::execute("ALTER TABLE `{$table}` ADD COLUMN `maidan_score_discount` tinyint(1) NOT NULL DEFAULT 1 COMMENT '买单会员等级折扣 0关闭 1开启'");

echo "[done] column maidan_score_discount added to {$table}\n";
