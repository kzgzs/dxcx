<?php
require __DIR__ . '/../vendor/autoload.php';
$app = new \think\App();
$app->initialize();
$cols = \think\facade\Db::query("SHOW COLUMNS FROM `admin_set`");
$found = false;
foreach ($cols as $c) {
    if ($c['Field'] === 'wxkfstaff') { $found = true; break; }
}
if (!$found) {
    \think\facade\Db::execute("ALTER TABLE `admin_set` ADD COLUMN `wxkfstaff` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '小程序客服人员:1=系统管理员,2=小程序客服' AFTER `wxkfurl`");
    echo "Added wxkfstaff column\n";
} else {
    echo "wxkfstaff already exists\n";
}
