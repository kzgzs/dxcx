<?php
// 种子：超级管理员账号
// 说明：旧系统 canzhao 中真正的后台登录表是 admin_user（列名为 un/pwd），
//       admin 表是平台租户表（无 username/password 列）。
//       因此本种子同时保证：admin 存在 id=1 的平台租户行，
//       admin_user 存在 un=admin 的超管行（isadmin=2，密码用 password_hash）。
use think\migration\Seeder;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $now = time();
        $pdo = $this->getAdapter()->getConnection();

        // 1) 平台租户行（admin_user.aid 引用 admin.id）
        $row = $this->fetchRow("SELECT `id` FROM `admin` WHERE `id` = 1");
        if (!$row) {
            $this->execute(
                "INSERT INTO `admin` (`id`, `createtime`, `platform`, `status`, `endtime`) "
                . "VALUES (1, {$now}, 'mp,wx,alipay,baidu,toutiao,qq,h5,app', 1, 2145888000)"
            );
            echo "[seed] admin: 已创建平台租户 id=1\n";
        } else {
            echo "[seed] admin: 平台租户 id=1 已存在，跳过\n";
        }

        // 2) 超管账号 admin_user
        $row = $this->fetchRow("SELECT `id`, `pwd` FROM `admin_user` WHERE `un` = 'admin' LIMIT 1");
        $hash  = password_hash('admin888', PASSWORD_DEFAULT);
        $rstr  = bin2hex(random_bytes(8)); // 16 位随机串（random_str）
        if (!$row) {
            $stmt = $pdo->prepare(
                "INSERT INTO `admin_user` (`aid`, `bid`, `un`, `pwd`, `ip`, `logintime`, `status`, `createtime`, `auth_type`, `isadmin`, `random_str`) "
                . "VALUES (1, 0, 'admin', ?, '127.0.0.1', NULL, 1, {$now}, 1, 2, ?)"
            );
            $stmt->execute([$hash, $rstr]);
            echo "[seed] admin_user: 已创建超管 admin（isadmin=2, password_hash）\n";
        } else {
            // 已存在则刷新为新的 password_hash 哈希，保证 password_verify 可用
            $stmt = $pdo->prepare("UPDATE `admin_user` SET `pwd` = ?, `isadmin` = 2, `status` = 1 WHERE `id` = ?");
            $stmt->execute([$hash, $row['id']]);
            echo "[seed] admin_user: admin 已存在，已刷新密码哈希\n";
        }
    }
}
