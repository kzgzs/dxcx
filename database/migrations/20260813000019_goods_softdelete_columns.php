<?php
// P4 商品模块：基线表复用 shop_product / shop_category，补软删列
// 旧系统为物理删除；新系统统一软删（deleted=1），订单拦截依赖保留商品记录
// 金额说明：沿用旧表口径——sell_price/market_price 为 float，单位「元」（非分）

use think\migration\Migrator;

class GoodsSoftDeleteColumns extends Migrator
{
    public function change()
    {
        $this->execute("ALTER TABLE `shop_product` ADD COLUMN `deleted` tinyint(1) NOT NULL DEFAULT '0' COMMENT '软删标记 0正常 1已删除'");
        $this->execute("ALTER TABLE `shop_product` ADD COLUMN `deletetime` int DEFAULT NULL COMMENT '删除时间'");
        $this->execute("ALTER TABLE `shop_category` ADD COLUMN `deleted` tinyint(1) NOT NULL DEFAULT '0' COMMENT '软删标记 0正常 1已删除'");
    }
}
