<?php

// 幸运拼团补丁迁移（P20-3 任务188）：
// 1. lucky_collage_product 增加 zjmax（单人中奖上限，0不限）
// 2. lucky_collage_sysset 增加 robot_get（机器人是否可中奖 0否 1是）
//    与 tklx（未中奖退款路线 1原路退回 2退余额 3不退款）
// 基线表缺这三列，开奖结算依赖。

use think\migration\Migrator;

class LuckyCollagePatch extends Migrator
{
    public function up()
    {
        $this->execute("ALTER TABLE `lucky_collage_product`
  ADD COLUMN `zjmax` int(11) NOT NULL DEFAULT '0' COMMENT '单人中奖上限 0不限' AFTER `zjids`");

        $this->execute("ALTER TABLE `lucky_collage_sysset`
  ADD COLUMN `robot_get` tinyint(1) NOT NULL DEFAULT '0' COMMENT '机器人是否可中奖 0否 1是' AFTER `duration`,
  ADD COLUMN `tklx` int(2) NOT NULL DEFAULT '1' COMMENT '未中奖退款路线 1原路退回 2退余额 3不退款' AFTER `robot_get`");
    }

    public function down()
    {
        $this->execute('ALTER TABLE `lucky_collage_product` DROP COLUMN `zjmax`');
        $this->execute('ALTER TABLE `lucky_collage_sysset` DROP COLUMN `robot_get`, DROP COLUMN `tklx`');
    }
}
