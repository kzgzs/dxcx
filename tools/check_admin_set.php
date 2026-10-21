<?php
require __DIR__ . '/../vendor/autoload.php';
$app = new \think\App();
$app->initialize();
$cols = \think\facade\Db::query("SHOW COLUMNS FROM `admin_set`");
foreach ($cols as $c) {
    $name = $c['Field'];
    if (strpos($name, 'fx') !== false || strpos($name, 'show') !== false || strpos($name, 'distribution') !== false || strpos($name, 'commission') !== false) {
        echo "  $name ({$c['Type']})\n";
    }
}
echo "---all columns---\n";
foreach ($cols as $c) echo $c['Field'] . "\n";
