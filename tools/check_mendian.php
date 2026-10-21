<?php
require __DIR__ . '/../vendor/autoload.php';
$app = new \think\App();
$app->initialize();
$cols = \think\facade\Db::query("SHOW COLUMNS FROM `mendian`");
foreach ($cols as $c) echo "{$c['Field']} ({$c['Type']})\n";
