<?php
// P5 营销第一期：优惠券全链路（幂等）
// 复用基线 coupon / coupon_record 表，字段映射：
//   full_money→minprice(满X) total→stock(总量) received→getnum(已领) per_limit→perlimit(每人限领)
//   start_time→starttime(varchar Y-m-d H:i:s) end_time→endtime
//   record：createtime/receive_time=领取时间 usetime=使用时间 couponid=券ID
// 补列：
//   coupon.status(1启用0停用) / coupon.deleted(软删) / coupon.used(已使用数)
//   coupon_record.order_id(核销时回写订单ID，创建订单时预锁定)

use think\migration\Migrator;

class CouponColumns extends Migrator
{
    public function change()
    {
        foreach ([
            'status'  => "ADD COLUMN `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态 1启用 0停用'",
            'deleted' => "ADD COLUMN `deleted` tinyint(1) NOT NULL DEFAULT '0' COMMENT '软删标记 0正常 1已删除'",
            'used'    => "ADD COLUMN `used` int NOT NULL DEFAULT '0' COMMENT '已使用数'",
        ] as $column => $ddl) {
            $row = $this->fetchRow(
                "SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'coupon' AND column_name = '{$column}' LIMIT 1"
            );
            if (!$row) {
                $this->execute("ALTER TABLE `coupon` {$ddl}");
            }
        }

        $row = $this->fetchRow(
            "SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'coupon_record' AND column_name = 'order_id' LIMIT 1"
        );
        if (!$row) {
            $this->execute("ALTER TABLE `coupon_record` ADD COLUMN `order_id` int NOT NULL DEFAULT '0' COMMENT '关联订单ID（下单预锁定/核销回写）'");
        }
    }
}
