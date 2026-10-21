<?php
require __DIR__ . '/../vendor/autoload.php';
$app = new \think\App();
$app->initialize();
$cols = \think\facade\Db::query("SHOW COLUMNS FROM `admin_set`");
$keywords = ['commission2money', 'money_transfer', 'wx_transfer', 'maidan_login', 'close_platform', 'force_login'];
foreach ($cols as $c) {
    foreach ($keywords as $k) {
        if (stripos($c['Field'], $k) !== false) {
            echo "{$c['Field']} ({$c['Type']}) Default={$c['Default']}\n";
        }
    }
}
echo "---\n";
// Also check for 强制登录 related fields
foreach ($cols as $c) {
    if (stripos($c['Field'], 'login_mast') !== false || stripos($c['Field'], 'force') !== false) {
        echo "{$c['Field']} ({$c['Type']}) Default={$c['Default']}\n";
    }
}
