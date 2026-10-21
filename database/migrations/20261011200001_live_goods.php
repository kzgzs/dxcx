<?php
// 租户后台「扩展·小程序直播」商品库表 live_goods（对齐 canzhao app/controller/Live.php 商品库）
// 真实环境商品库在微信侧（wxaapi/broadcast/goods），mock/未配置小程序时落本地表保证功能可跑通。
// 价格单位：分；priceType 1一口价 2价格区间 3显示折扣价（price2 为区间上限/现价）；audit_status 1审核中 2审核通过 3审核驳回。
// 幂等：information_schema.TABLES 判存在，已存在则跳过。

use think\migration\Migrator;

class LiveGoods extends Migrator
{
    public function change()
    {
        $row = $this->fetchRow(
            "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'live_goods' LIMIT 1"
        );
        if ($row) {
            echo "[skip] table live_goods already exists\n";
            return;
        }

        $this->execute("CREATE TABLE `live_goods` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `aid` int unsigned NOT NULL DEFAULT 0 COMMENT '租户ID',
            `goodsId` int unsigned NOT NULL DEFAULT 0 COMMENT '微信商品ID（mock 等于本表 id）',
            `name` varchar(120) NOT NULL DEFAULT '' COMMENT '商品名称（3-17个汉字）',
            `coverImgUrl` varchar(255) NOT NULL DEFAULT '' COMMENT '商品图片 200*200 ≤80K',
            `url` varchar(500) NOT NULL DEFAULT '' COMMENT '商品链接（页面路径）',
            `priceType` tinyint(1) NOT NULL DEFAULT 1 COMMENT '价格形式 1一口价 2价格区间 3显示折扣价',
            `price` int unsigned NOT NULL DEFAULT 0 COMMENT '价格/区间下限/原价，单位分',
            `price2` int unsigned NOT NULL DEFAULT 0 COMMENT '区间上限/现价，单位分',
            `audit_status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '审核状态 1审核中 2审核通过 3审核驳回',
            `createtime` int unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `aid_status` (`aid`, `audit_status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='小程序直播商品库'");
        echo "[done] table live_goods created\n";
    }
}
