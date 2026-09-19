<?php

declare(strict_types=1);

use think\migration\Migrator;

/**
 * 购物车 cart 增加 SKU 规格列 ggid（shop_guige.id，0=无规格）
 *
 * 唯一键由 (aid,member_id,product_id) 扩为 (aid,member_id,product_id,ggid)，
 * 同商品不同规格分行累加
 */
class CartGgidColumn extends Migrator
{
    public function change(): void
    {
        $this->execute("ALTER TABLE `cart`
            ADD COLUMN `ggid` int NOT NULL DEFAULT '0' COMMENT 'SKU规格ID（shop_guige.id，0无规格）' AFTER `product_id`,
            DROP INDEX `uk_member_product`,
            ADD UNIQUE KEY `uk_member_product_gg` (`aid`,`member_id`,`product_id`,`ggid`)");
    }
}
