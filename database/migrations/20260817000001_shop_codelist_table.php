<?php

declare(strict_types=1);

use think\migration\Migrator;

/**
 * 卡密表 shop_codelist（对标点大 ddwx_shop_codelist：卡密商品的卡密池）
 *
 * 字段口径与旧系统一致：content=卡密内容，status 0未售出 1已售出，
 * 售出后回填 ordernum/orderid/nickname/buytime
 */
class ShopCodelistTable extends Migrator
{
    public function change(): void
    {
        $this->execute('CREATE TABLE IF NOT EXISTS `shop_codelist` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) DEFAULT NULL COMMENT \'账户ID\',
  `bid` int(11) DEFAULT \'0\' COMMENT \'商户ID 0代表平台的\',
  `proid` int(11) DEFAULT NULL COMMENT \'商品ID\',
  `content` text COMMENT \'卡密内容\',
  `ordernum` varchar(100) DEFAULT NULL COMMENT \'订单号\',
  `orderid` int(11) DEFAULT NULL COMMENT \'订单ID\',
  `headimg` varchar(255) DEFAULT NULL COMMENT \'用户头像\',
  `nickname` varchar(255) DEFAULT NULL COMMENT \'用户昵称\',
  `createtime` int(11) DEFAULT NULL COMMENT \'创建时间\',
  `buytime` int(11) DEFAULT NULL COMMENT \'购买时间\',
  `status` tinyint(1) DEFAULT \'0\' COMMENT \'状态0未售出1已售出\',
  PRIMARY KEY (`id`),
  KEY `aid` (`aid`) USING BTREE,
  KEY `bid` (`bid`) USING BTREE,
  KEY `proid` (`proid`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT=\'卡密商品的卡密\'');
    }
}
