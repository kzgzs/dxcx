<?php
// GENERATED-BY sql2migration （一次性基线生成，勿手工逐行编辑）
// 基线迁移[14/14] 索引补强：高频联合索引（aid,status,createtime）/（aid,mid,createtime）
// 仅针对订单、会员流水、支付日志等明显受益的大表，共 10 条（控制在 10 条内）

use think\migration\Migrator;

class IndexEnhance extends Migrator
{
    public function change()
    {
        $this->execute('-- shop_order: 核心商城订单：按状态筛选+时间排序
ALTER TABLE `shop_order` ADD INDEX `idx_aid_status_ct` (`aid`, `status`, `createtime`)');
        $this->execute('-- shop_order: 核心商城订单：查指定会员订单
ALTER TABLE `shop_order` ADD INDEX `idx_aid_mid_ct` (`aid`, `mid`, `createtime`)');
        $this->execute('-- payorder: 统一支付订单：按状态筛选+时间排序
ALTER TABLE `payorder` ADD INDEX `idx_aid_status_ct` (`aid`, `status`, `createtime`)');
        $this->execute('-- shop_refund_order: 售后退款单：按状态筛选+时间排序
ALTER TABLE `shop_refund_order` ADD INDEX `idx_aid_status_ct` (`aid`, `status`, `createtime`)');
        $this->execute('-- member_moneylog: 会员余额流水：查指定会员流水
ALTER TABLE `member_moneylog` ADD INDEX `idx_aid_mid_ct` (`aid`, `mid`, `createtime`)');
        $this->execute('-- member_scorelog: 会员积分流水：查指定会员流水
ALTER TABLE `member_scorelog` ADD INDEX `idx_aid_mid_ct` (`aid`, `mid`, `createtime`)');
        $this->execute('-- member_commissionlog: 会员佣金流水：查指定会员流水
ALTER TABLE `member_commissionlog` ADD INDEX `idx_aid_mid_ct` (`aid`, `mid`, `createtime`)');
        $this->execute('-- wxpay_log: 微信支付日志：查指定会员支付记录
ALTER TABLE `wxpay_log` ADD INDEX `idx_aid_mid_ct` (`aid`, `mid`, `createtime`)');
        $this->execute('-- yuyue_order: 预约订单：按状态筛选+时间排序
ALTER TABLE `yuyue_order` ADD INDEX `idx_aid_status_ct` (`aid`, `status`, `createtime`)');
        $this->execute('-- restaurant_takeaway_order: 餐饮外卖订单：按状态筛选+时间排序
ALTER TABLE `restaurant_takeaway_order` ADD INDEX `idx_aid_status_ct` (`aid`, `status`, `createtime`)');
    }
}
