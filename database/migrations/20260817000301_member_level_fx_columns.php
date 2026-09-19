<?php

declare(strict_types=1);

use think\migration\Migrator;

/**
 * member_level 补充分销/升级条件缺失列（P19-2 等级配置页深化）
 *
 * - gettj：升级前置等级（逗号分隔等级ID，-1不限制）
 * - commissionset：佣金设置模式（0按等级 1价格比例 2固定金额 -1不参与分销）
 * - fx_jinsuo：分销紧缩模式开关（上级无分销权限时佣金是否向上紧缩）
 * - up_perpaymoney：升级条件-单次消费金额
 */
class MemberLevelFxColumns extends Migrator
{
    public function change(): void
    {
        $this->execute("ALTER TABLE `member_level`
            ADD COLUMN `gettj` varchar(255) NOT NULL DEFAULT '-1' COMMENT '升级前置等级（逗号分隔等级ID，-1不限制）' AFTER `up_cat_ordermoney`,
            ADD COLUMN `commissionset` tinyint(1) NOT NULL DEFAULT '0' COMMENT '佣金设置 0按会员等级 1价格比例 2固定金额 -1不参与分销' AFTER `commissiontype`,
            ADD COLUMN `fx_jinsuo` tinyint(1) NOT NULL DEFAULT '0' COMMENT '分销紧缩模式 0关闭 1开启' AFTER `commission_max`,
            ADD COLUMN `up_perpaymoney` decimal(11,2) NOT NULL DEFAULT '0.00' COMMENT '升级条件-单次消费金额' AFTER `up_orderprice`");
    }
}
