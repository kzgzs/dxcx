<?php
// 批次5-B 任务195：论坛帖子挂载商品字段（C端 getprolist 挂商品依赖，对照点大 luntan.goods_info）

use think\migration\Migrator;

class LuntanGoodsInfoColumn extends Migrator
{
    public function change()
    {
        $this->execute("ALTER TABLE `luntan` ADD COLUMN `goods_info` text COMMENT '挂载商品 JSON {type:[ids]}'");
    }
}
