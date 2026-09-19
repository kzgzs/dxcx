<?php

// P15-6 周期购简化版
// cycle_guige 补列：规格动态行口径（周期天数/期数/每期数量），基线表仅有名称/价格/库存
// 对齐简化版范围：商品规格 = 周期(cycle_days) × 次数(qsnum) × 每期数量(each_num) × 价格(sell_price)

use think\migration\Migrator;

class CycleGuigeColumns extends Migrator
{
    public function up()
    {
        $this->execute("ALTER TABLE `cycle_guige`
  ADD COLUMN `cycle_days` int(11) NOT NULL DEFAULT '1' COMMENT '配送周期（天）' AFTER `name`,
  ADD COLUMN `qsnum` int(11) NOT NULL DEFAULT '1' COMMENT '期数（总次数）' AFTER `cycle_days`,
  ADD COLUMN `each_num` int(11) NOT NULL DEFAULT '1' COMMENT '每期配送数量' AFTER `qsnum`");
    }

    public function down()
    {
        $this->execute('ALTER TABLE `cycle_guige` DROP COLUMN `cycle_days`, DROP COLUMN `qsnum`, DROP COLUMN `each_num`');
    }
}
