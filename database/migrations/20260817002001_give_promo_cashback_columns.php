<?php
// 批次4-A 赠送促销四项（任务186）补列迁移（幂等）
//
// 六张业务表（register_giveset/recharge_giveset/cuxiao/cashback/cashback_member/cashback_member_log）
// 已在基线迁移中存在，此处仅补下单算价/充值赠送所需列：
// - shop_order.cuxiao_ids / cuxiao_money   商品促销（满减）落单
// - shop_order_goods.gtype                 行类型 0正常 1满赠赠品行 2换购行
// - cuxiao.status                          启停开关（基线表无此列）
// - recharge_order.give_money / give_score 充值赠送发放快照（幂等留痕）

use think\migration\Migrator;

class GivePromoCashbackColumns extends Migrator
{
    public function change()
    {
        // shop_order 补列（商品促销算价落单）
        foreach ([
            'cuxiao_ids'   => "ADD COLUMN `cuxiao_ids` varchar(255) NOT NULL DEFAULT '' COMMENT '应用的商品促销活动ID（逗号分隔）'",
            'cuxiao_money' => "ADD COLUMN `cuxiao_money` decimal(11,2) NOT NULL DEFAULT '0.00' COMMENT '商品促销优惠金额（元）'",
        ] as $column => $ddl) {
            $row = $this->fetchRow(
                "SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'shop_order' AND column_name = '{$column}' LIMIT 1"
            );
            if (!$row) {
                $this->execute("ALTER TABLE `shop_order` {$ddl}");
            }
        }

        // shop_order_goods 补列（赠品行/换购行标记）
        $row = $this->fetchRow(
            "SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'shop_order_goods' AND column_name = 'gtype' LIMIT 1"
        );
        if (!$row) {
            $this->execute("ALTER TABLE `shop_order_goods` ADD COLUMN `gtype` tinyint(1) NOT NULL DEFAULT '0' COMMENT '行类型 0正常 1满赠赠品行 2换购行'");
        }

        // cuxiao 补列（启停开关）
        $row = $this->fetchRow(
            "SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'cuxiao' AND column_name = 'status' LIMIT 1"
        );
        if (!$row) {
            $this->execute("ALTER TABLE `cuxiao` ADD COLUMN `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态 1启用 0停用'");
        }

        // recharge_order 补列（充值赠送发放快照）
        foreach ([
            'give_money' => "ADD COLUMN `give_money` decimal(11,2) NOT NULL DEFAULT '0.00' COMMENT '充值赠送余额（元）'",
            'give_score' => "ADD COLUMN `give_score` int(11) NOT NULL DEFAULT '0' COMMENT '充值赠送积分'",
        ] as $column => $ddl) {
            $row = $this->fetchRow(
                "SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'recharge_order' AND column_name = '{$column}' LIMIT 1"
            );
            if (!$row) {
                $this->execute("ALTER TABLE `recharge_order` {$ddl}");
            }
        }
    }
}
