<?php
// P4 订单与支付模块：基线表复用 shop_order / shop_order_goods / member_address
// 金额口径：沿用旧表 float(11,2)，单位「元」（非分）
// 状态口径：shop_order.status 0未支付 1已支付 2已发货 3已收货 4关闭（取消）
// 补列（幂等）：
// 1. shop_order.pay_expire_time —— 支付超时时间戳（下单时 createtime+600，供 order:cancel 扫描）
// 2. member_address.deleted    —— 软删标记（旧表物理删除，新系统统一软删）

use think\migration\Migrator;

class OrderPayColumns extends Migrator
{
    public function change()
    {
        $hasExpire = $this->fetchRow(
            "SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'shop_order' AND column_name = 'pay_expire_time' LIMIT 1"
        );
        if (!$hasExpire) {
            $this->execute("ALTER TABLE `shop_order` ADD COLUMN `pay_expire_time` int DEFAULT NULL COMMENT '支付超时时间戳'");
        }

        $hasDeleted = $this->fetchRow(
            "SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'member_address' AND column_name = 'deleted' LIMIT 1"
        );
        if (!$hasDeleted) {
            $this->execute("ALTER TABLE `member_address` ADD COLUMN `deleted` tinyint(1) NOT NULL DEFAULT '0' COMMENT '软删标记 0正常 1已删除'");
        }
    }
}
