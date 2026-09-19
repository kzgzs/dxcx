<?php
// P4 租户后台/客户端会话地基：测试种子数据（幂等）
// 1. 租户1 补充 username（历史 dump 无该列值）
// 2. 租户1 管理员账号 tenant1 / 123456（bcrypt，isadmin=1）
// 3. 测试会员 13800138000 / 123456（bcrypt，aid=1）

use think\migration\Migrator;

class SeedAdminMember extends Migrator
{
    public function change()
    {
        // 租户1 账号名
        $this->execute("UPDATE `admin` SET `username` = 'tenant1' WHERE `id` = 1 AND (`username` IS NULL OR `username` = '')");

        // 租户1 管理员账号（isadmin=1 租户管理员，默认密码 123456）
        $exists = $this->fetchRow("SELECT `id` FROM `admin_user` WHERE `un` = 'tenant1' LIMIT 1");
        if (!$exists) {
            $pwd  = password_hash('123456', PASSWORD_DEFAULT);
            $rand = bin2hex(random_bytes(8));
            $now  = time();
            $this->execute(sprintf(
                "INSERT INTO `admin_user` (`aid`,`bid`,`un`,`pwd`,`status`,`createtime`,`isadmin`,`random_str`) VALUES (1,0,'tenant1','%s',1,%d,1,'%s')",
                $pwd,
                $now,
                $rand
            ));
        }

        // 测试会员（tel=13800138000，密码 123456 bcrypt，aid=1）
        $exists = $this->fetchRow("SELECT `id` FROM `member` WHERE `tel` = '13800138000' LIMIT 1");
        if (!$exists) {
            $pwd = password_hash('123456', PASSWORD_DEFAULT);
            $now = time();
            $this->execute(sprintf(
                "INSERT INTO `member` (`aid`,`bid`,`nickname`,`realname`,`tel`,`pwd`,`score`,`money`,`createtime`) VALUES (1,0,'测试会员','测试会员','13800138000','%s',0,0.00,%d)",
                $pwd,
                $now
            ));
        }
    }
}
