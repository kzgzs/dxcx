<?php

declare(strict_types=1);

use think\migration\Migrator;

/**
 * 计次核销记录表 hexiao_shopproduct（任务177，对标点大 ddwx_hexiao_shopproduct）
 *
 * 字段口径自 canzhao/app/controller/Hexiao.php::shopproduct 与
 * canzhao/app/controller/ShopOrder.php 计次核销落库代码反推：
 * 商城计次商品每次核销插入一条，num=本次核销次数，bid 0=平台自营 >0=商户，
 * delivery_time/delivery_remark 为配送备注（点大 hexiao_shopproduct_search_hxtime 增强项）
 */
class HexiaoShopproductTable extends Migrator
{
    public function change(): void
    {
        $this->execute('CREATE TABLE IF NOT EXISTS `hexiao_shopproduct` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) DEFAULT NULL COMMENT \'账户ID\',
  `bid` int(11) DEFAULT \'0\' COMMENT \'商户ID 0代表平台的\',
  `uid` int(11) DEFAULT \'0\' COMMENT \'核销员ID\',
  `mid` int(11) DEFAULT NULL COMMENT \'会员ID\',
  `orderid` int(11) DEFAULT \'0\' COMMENT \'订单商品ID(shop_order_goods)\',
  `ogid` int(11) DEFAULT \'0\' COMMENT \'订单商品ID\',
  `ordernum` varchar(100) DEFAULT NULL COMMENT \'订单号\',
  `title` varchar(255) DEFAULT NULL COMMENT \'商品名称(含规格)\',
  `name` varchar(255) DEFAULT NULL COMMENT \'商品名称\',
  `pic` varchar(255) DEFAULT NULL COMMENT \'商品图\',
  `proid` int(11) DEFAULT \'0\' COMMENT \'商品ID\',
  `ggid` int(11) DEFAULT \'0\' COMMENT \'规格ID\',
  `ggname` varchar(255) DEFAULT NULL COMMENT \'规格名\',
  `num` int(11) DEFAULT \'0\' COMMENT \'本次核销数量\',
  `status` tinyint(1) DEFAULT \'1\' COMMENT \'状态 1已核销\',
  `remark` varchar(255) DEFAULT NULL COMMENT \'备注信息\',
  `delivery_time` int(11) DEFAULT \'0\' COMMENT \'配送时间\',
  `delivery_remark` varchar(255) DEFAULT \'\' COMMENT \'配送备注\',
  `hexiao_order_id` int(11) DEFAULT \'0\' COMMENT \'核销记录ID(hexiao_order)\',
  `createtime` int(11) DEFAULT NULL COMMENT \'核销时间\',
  PRIMARY KEY (`id`),
  KEY `aid` (`aid`) USING BTREE,
  KEY `bid` (`bid`) USING BTREE,
  KEY `mid` (`mid`) USING BTREE,
  KEY `proid` (`proid`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT=\'计次核销记录\'');
    }
}
