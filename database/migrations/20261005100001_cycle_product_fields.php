<?php
// 周期购 片A（商品管理 + 商品分类）：字段对齐 canzhao，幂等补列
// 基线 cycle_product / cycle_guige / cycle_category 已含大部分列；本迁移仅在缺列时补齐，
// 已存在则跳过（不重复 ALTER），保证在任意环境可重复执行。
// 覆盖：配送周期 everyday_item / 提前支付 advance_pay_* / 最小期数 min_num / 每期起订 min_qsnum
//       / 会员等级折扣 commissionset / 配送模板 freighttype+freightdata / 规格定义 guigedata
//       / 规格行 cycle_guige 的 编号/市场价/重量/图片 procode/market_price/weight/pic

use think\migration\Migrator;

class CycleProductFields extends Migrator
{
    public function change()
    {
        $ensure = [
            'cycle_product' => [
                'pics'               => "ADD COLUMN `pics` varchar(5000) NOT NULL DEFAULT '' COMMENT '商品图集（逗号分隔）'",
                'sales'              => "ADD COLUMN `sales` int unsigned NOT NULL DEFAULT '0' COMMENT '累计销量'",
                'market_price'       => "ADD COLUMN `market_price` float(11,2) NOT NULL DEFAULT '0.00' COMMENT '市场价（取规格最低）'",
                'cost_price'         => "ADD COLUMN `cost_price` decimal(11,2) NOT NULL DEFAULT '0.00' COMMENT '成本价'",
                'weight'             => "ADD COLUMN `weight` int NOT NULL DEFAULT '0' COMMENT '重量（克）'",
                'detail'             => "ADD COLUMN `detail` longtext COMMENT '商品详情（装修模块 JSON）'",
                'commissionset'      => "ADD COLUMN `commissionset` tinyint(1) NOT NULL DEFAULT '0' COMMENT '会员等级折扣 0跟随系统 1参与 2不参与'",
                'guigedata'          => "ADD COLUMN `guigedata` text COMMENT '规格分组/规格名称定义 JSON'",
                'freighttype'        => "ADD COLUMN `freighttype` tinyint(1) NOT NULL DEFAULT '1' COMMENT '配送模板 1全部模板 2选择模板'",
                'freightdata'        => "ADD COLUMN `freightdata` varchar(255) NOT NULL DEFAULT '' COMMENT '选定配送模板ID（逗号分隔）'",
                'ps_cycle'           => "ADD COLUMN `ps_cycle` tinyint(1) NOT NULL DEFAULT '1' COMMENT '配送周期 1每日 2每周 3每月'",
                'everyday_item'      => "ADD COLUMN `everyday_item` text COMMENT '每日一期配置 JSON（每天/工作日/周末/隔天）'",
                'advance_pay_days'   => "ADD COLUMN `advance_pay_days` int NOT NULL DEFAULT '0' COMMENT '提前支付天数'",
                'advance_pay_time'   => "ADD COLUMN `advance_pay_time` int NOT NULL DEFAULT '0' COMMENT '提前支付截止时（0-23）'",
                'advance_extend_days' => "ADD COLUMN `advance_extend_days` int NOT NULL DEFAULT '0' COMMENT '提前顺延天数'",
                'min_num'            => "ADD COLUMN `min_num` int NOT NULL DEFAULT '1' COMMENT '最小配送期数'",
                'min_qsnum'          => "ADD COLUMN `min_qsnum` int NOT NULL DEFAULT '1' COMMENT '每期起订数'",
            ],
            'cycle_guige' => [
                'procode'     => "ADD COLUMN `procode` varchar(255) NOT NULL DEFAULT '' COMMENT '规格编号'",
                'market_price' => "ADD COLUMN `market_price` decimal(11,2) NOT NULL DEFAULT '0.00' COMMENT '市场价'",
                'cost_price'  => "ADD COLUMN `cost_price` decimal(11,2) NOT NULL DEFAULT '0.00' COMMENT '成本价'",
                'weight'      => "ADD COLUMN `weight` int NOT NULL DEFAULT '0' COMMENT '重量（克）'",
                'pic'         => "ADD COLUMN `pic` varchar(255) NOT NULL DEFAULT '' COMMENT '规格图片'",
                'ks'          => "ADD COLUMN `ks` varchar(255) NOT NULL DEFAULT '' COMMENT '规格键（行索引）'",
                'cycle_days'  => "ADD COLUMN `cycle_days` int NOT NULL DEFAULT '1' COMMENT '配送周期天数（由商品ps_cycle派生）'",
                'qsnum'       => "ADD COLUMN `qsnum` int NOT NULL DEFAULT '1' COMMENT '期数（由商品min_num派生）'",
                'each_num'    => "ADD COLUMN `each_num` int NOT NULL DEFAULT '1' COMMENT '每期数量（由商品min_qsnum派生）'",
            ],
            'cycle_category' => [
                'pic'    => "ADD COLUMN `pic` varchar(255) NOT NULL DEFAULT '' COMMENT '分类图标'",
                'pid'    => "ADD COLUMN `pid` int NOT NULL DEFAULT '0' COMMENT '上级分类ID'",
                'sort'   => "ADD COLUMN `sort` int NOT NULL DEFAULT '1' COMMENT '序号（越大越靠前）'",
                'status' => "ADD COLUMN `status` int NOT NULL DEFAULT '1' COMMENT '状态 1显示 0隐藏'",
            ],
        ];

        foreach ($ensure as $table => $columns) {
            foreach ($columns as $column => $ddl) {
                $row = $this->fetchRow(
                    "SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = '{$table}' AND column_name = '{$column}' LIMIT 1"
                );
                if (!$row) {
                    $this->execute("ALTER TABLE `{$table}` {$ddl}");
                }
            }
        }
    }
}
