<?php
// P13-2 客户端营销（整点秒杀）：补秒杀场次明细表
//
// seckill_prodata：秒杀商品按「日期 + 场次(整点)」展开的活动明细行
// - 一行 = 某商品某规格在某场次的一场秒杀（含秒杀价/本场库存/限购）
// - starttime = 场次日期零点时间戳 + seckill_time*3600，便于区间比较
// - 字段口径参照旧系统 seckill_prodata（proid/ggid/seckill_price/sell_price/seckill_num/buymax）

use think\migration\Migrator;

class SeckillProdataTable extends Migrator
{
    public function change()
    {
        $exists = $this->fetchRow(
            "SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'seckill_prodata' LIMIT 1"
        );

        if (!$exists) {
            $this->execute("CREATE TABLE `seckill_prodata` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `aid` int(11) DEFAULT NULL COMMENT '租户ID',
                `bid` int(11) DEFAULT '0' COMMENT '商户ID（0=平台自营）',
                `proid` int(11) DEFAULT NULL COMMENT '秒杀商品ID（seckill_product）',
                `ggid` int(11) DEFAULT '0' COMMENT '规格ID（seckill_guige，0=单规格）',
                `seckill_date` varchar(11) DEFAULT NULL COMMENT '场次日期 Y-m-d',
                `seckill_time` int(11) DEFAULT NULL COMMENT '场次开始小时（0-23 整点）',
                `starttime` int(11) DEFAULT NULL COMMENT '场次开始时间戳（Unix秒）',
                `seckill_price` decimal(11,2) DEFAULT '0.00' COMMENT '本场秒杀价（元）',
                `sell_price` decimal(11,2) DEFAULT '0.00' COMMENT '规格原售价（元）',
                `seckill_num` int(11) DEFAULT '0' COMMENT '本场秒杀库存',
                `sales` int(11) DEFAULT '0' COMMENT '本场已售数量',
                `buymax` int(11) DEFAULT '0' COMMENT '本场每人限购（0=不限）',
                `createtime` int(11) DEFAULT NULL COMMENT '创建时间（Unix秒）',
                PRIMARY KEY (`id`),
                KEY `aid` (`aid`) USING BTREE,
                KEY `proid` (`proid`) USING BTREE,
                KEY `seckill_date` (`seckill_date`) USING BTREE,
                KEY `starttime` (`starttime`) USING BTREE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='整点秒杀场次明细'");
        }
    }
}
