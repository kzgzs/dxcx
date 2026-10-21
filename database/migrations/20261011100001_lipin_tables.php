<?php
// 租户后台「扩展·礼品卡」四表幂等建表（lipin / lipin_codelist / lipin_category / lipin_set）
// 对齐 canzhao app/controller/Lipin.php + LipinCategory.php：
//   礼品卡活动 CRUD / 兑换码列表·生成·导入导出 / 批量改状态 / 礼品卡设置 / 分类两级平铺
// 幂等：information_schema.TABLES 判存在，已存在则跳过整表。

use think\migration\Migrator;

class LipinTables extends Migrator
{
    public function change()
    {
        $creates = [
            'lipin' => "CREATE TABLE `:prefix:lipin` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `aid` int unsigned NOT NULL DEFAULT 0 COMMENT '租户ID',
                `name` varchar(255) NOT NULL DEFAULT '' COMMENT '礼品卡名称',
                `cid` int unsigned NOT NULL DEFAULT 0 COMMENT '分类ID',
                `type` tinyint(1) NOT NULL DEFAULT 0 COMMENT '兑换类型 0余额1商品2积分3优惠券',
                `num_type` tinyint unsigned NOT NULL DEFAULT 0,
                `starttime` varchar(100) NOT NULL DEFAULT '' COMMENT '开始时间戳',
                `endtime` varchar(100) NOT NULL DEFAULT '' COMMENT '结束时间戳',
                `money` float(11,2) NOT NULL DEFAULT 0 COMMENT '余额金额',
                `prodata` text COMMENT '商品ID',
                `prodata4` text,
                `num_type4` tinyint unsigned NOT NULL DEFAULT 0,
                `createtime` int unsigned NOT NULL DEFAULT 0,
                `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态 0关闭1开启',
                `score` int NOT NULL DEFAULT 0 COMMENT '积分数',
                `coupon_ids` varchar(255) NOT NULL DEFAULT '' COMMENT '优惠券ID',
                PRIMARY KEY (`id`),
                KEY `aid` (`aid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='礼品卡活动'",

            'lipin_codelist' => "CREATE TABLE `:prefix:lipin_codelist` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `aid` int unsigned NOT NULL DEFAULT 0,
                `hid` int unsigned NOT NULL DEFAULT 0 COMMENT '礼品卡活动ID',
                `cardno` varchar(30) NOT NULL DEFAULT '' COMMENT '卡号',
                `code` varchar(100) NOT NULL DEFAULT '' COMMENT '兑换码',
                `qr` varchar(255) NOT NULL DEFAULT '' COMMENT '图形码',
                `status` tinyint(1) NOT NULL DEFAULT 0 COMMENT '状态 0未售1已使用2已售',
                `createtime` int unsigned NOT NULL DEFAULT 0,
                `saletime` int unsigned NOT NULL DEFAULT 0 COMMENT '售出时间',
                `usetime` int unsigned NOT NULL DEFAULT 0 COMMENT '兑换时间',
                `mid` int unsigned NOT NULL DEFAULT 0 COMMENT '使用人会员ID',
                `headimg` varchar(255) NOT NULL DEFAULT '',
                `nickname` varchar(255) NOT NULL DEFAULT '',
                `remark` varchar(255) NOT NULL DEFAULT '',
                `sale_mdid` int unsigned NOT NULL DEFAULT 0,
                `hexiao_mdid` int unsigned NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                UNIQUE KEY `hid_2` (`aid`,`cardno`),
                KEY `hid` (`hid`),
                KEY `aid` (`aid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='礼品卡兑换码'",

            'lipin_category' => "CREATE TABLE `:prefix:lipin_category` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `aid` int unsigned NOT NULL DEFAULT 0,
                `bid` int unsigned NOT NULL DEFAULT 0,
                `pid` int unsigned NOT NULL DEFAULT 0 COMMENT '父级ID',
                `name` varchar(255) NOT NULL DEFAULT '' COMMENT '分类名称',
                `pic` varchar(255) NOT NULL DEFAULT '',
                `status` int NOT NULL DEFAULT 1 COMMENT '状态 0隐藏1显示',
                `sort` int NOT NULL DEFAULT 0 COMMENT '序号',
                `createtime` int unsigned NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                KEY `aid` (`aid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='礼品卡分类'",

            'lipin_set' => "CREATE TABLE `:prefix:lipin_set` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `aid` int unsigned NOT NULL DEFAULT 0,
                `status` tinyint(1) NOT NULL DEFAULT 0 COMMENT '全局开关',
                `scanshow` tinyint(1) NOT NULL DEFAULT 0 COMMENT '扫一扫图标 0不显示1显示',
                `guize` longtext COMMENT '规则说明',
                PRIMARY KEY (`id`),
                KEY `aid` (`aid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='礼品卡设置'",
        ];

        foreach ($creates as $name => $sql) {
            $row = $this->fetchRow(
                "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$name}' LIMIT 1"
            );
            if ($row) {
                echo "[skip] table {$name} already exists\n";
                continue;
            }
            $this->execute($sql);
            echo "[done] table {$name} created\n";
        }
    }
}
