<?php
// 种子：平台级初始配置（sysset 表，参照 canzhao sql.sql 中 sysset 的初始 INSERT，挑必要项）
//   - webinfo  站点基础信息（站点名/版权/备案号）
//   - remote   附件存储方式（本地存储）
//   - component 前端组件配置（空数组）
// 幂等：行已存在则把 value 刷新为干净默认值（旧 dump 中的 webinfo 含乱码遗留数据）。
use think\migration\Seeder;

class PlatformSeeder extends Seeder
{
    public function run(): void
    {
        $configs = [
            'webinfo' => json_encode([
                'webname'   => '新平台',
                'copyright' => 'Copyright © ' . date('Y') . ' All Rights Reserved',
                'beian'     => '',
            ], JSON_UNESCAPED_UNICODE),
            'remote' => json_encode([
                'type'         => '1',
                'thumb'        => '1',
                'thumb_width'  => '800',
                'thumb_height' => '10000',
            ], JSON_UNESCAPED_UNICODE),
            'component' => '[]',
        ];

        $pdo = $this->getAdapter()->getConnection();
        foreach ($configs as $name => $value) {
            $row = $this->fetchRow("SELECT `id` FROM `sysset` WHERE `name` = " . $pdo->quote($name));
            if ($row) {
                $stmt = $pdo->prepare("UPDATE `sysset` SET `value` = ? WHERE `id` = ?");
                $stmt->execute([$value, $row['id']]);
                echo "[seed] sysset.{$name}: 已存在，刷新默认值\n";
            } else {
                $stmt = $pdo->prepare("INSERT INTO `sysset` (`name`, `value`) VALUES (?, ?)");
                $stmt->execute([$name, $value]);
                echo "[seed] sysset.{$name}: 已创建\n";
            }
        }
    }
}
