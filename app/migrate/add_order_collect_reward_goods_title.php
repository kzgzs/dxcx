<?php
/**
 * 迁移：order_collect_reward_record 表增加 goods_title 列（确认收货奖励记录-商品信息快照）
 *
 * 幂等：SHOW COLUMNS 检查不存在则 ALTER TABLE ADD，可重复执行。
 * 用法：php E:\Dxcx\app\migrate\add_order_collect_reward_goods_title.php
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

$app = new \think\App();
$app->initialize();

use think\facade\Db;

$table = (string) env('database.prefix', '') . 'order_collect_reward_record';

$exists = Db::query("SHOW COLUMNS FROM `{$table}` LIKE 'goods_title'");

if (!empty($exists)) {
    echo "[skip] column goods_title already exists in {$table}\n";
    exit(0);
}

Db::execute("ALTER TABLE `{$table}` ADD COLUMN `goods_title` varchar(255) NOT NULL DEFAULT '' COMMENT '商品信息快照（展示用）' AFTER `ordertype`");

echo "[done] column goods_title added to {$table}\n";
