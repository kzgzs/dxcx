<?php
// P7 客户端（uni-app）接口补齐：购物车表
//
// cart：会员购物车行，唯一键 aid+member_id+product_id（同商品一行，num 累加）

use think\migration\Migrator;

class CartTable extends Migrator
{
    public function change()
    {
        $exists = $this->fetchRow(
            "SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'cart' LIMIT 1"
        );

        if (!$exists) {
            $this->execute("CREATE TABLE `cart` (
                `id` int NOT NULL AUTO_INCREMENT,
                `aid` int NOT NULL DEFAULT '0' COMMENT '租户ID',
                `member_id` int NOT NULL DEFAULT '0' COMMENT '会员ID',
                `product_id` int NOT NULL DEFAULT '0' COMMENT '商品ID（shop_product）',
                `num` int NOT NULL DEFAULT '1' COMMENT '数量',
                `selected` tinyint(1) NOT NULL DEFAULT '1' COMMENT '是否选中 1是 0否',
                `create_time` int unsigned NOT NULL DEFAULT '0' COMMENT '创建时间（Unix秒）',
                `update_time` int unsigned NOT NULL DEFAULT '0' COMMENT '更新时间（Unix秒）',
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_member_product` (`aid`,`member_id`,`product_id`),
                KEY `idx_member` (`aid`,`member_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='购物车'");
        }
    }
}
