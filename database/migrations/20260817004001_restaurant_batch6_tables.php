<?php

// 批次6 T6-0 基建：餐饮深水区 8 张新表（收银台/取餐叫号/寄存字典/加料/计时日志）
//
// 结构逐字段对齐 canzhao（点大参考源码，只读）：
// - restaurant_cashdesk            ← upgrade_c1.php:5045（+upgrade_c2 后续 ALTER：guazhangpay/default_paypwd/paypwd_use_status；
//                                    jiaoban_print_ids 参照 canzhao/sql.sql ddwx_cashier 同名字段）
// - restaurant_cashdesk_custom_pay ← upgrade_c2.php:2019
// - restaurant_take_food           ← upgrade_c2.php:649（status 扩展 -1过期 语义，注释注明）
// - restaurant_take_food_sysset    ← upgrade_c2.php:671 + 679(banner_interval)
// - restaurant_deposit_position    ← upgrade_c2.php:11029
// - restaurant_deposit_position_number ← upgrade_c2.php:11041
// - restaurant_product_jialiao     ← upgrade_c1.php:9941
// - restaurant_timing_log          ← upgrade_c1.php:11222
//
// 说明：admin_setapp_restaurant_cashdesk（多商户支付密钥表）按任务要求缓建，本期不做。
// 新系统统一 utf8mb4，均带 aid 租户列与常规索引。

use think\migration\Migrator;

class RestaurantBatch6Tables extends Migrator
{
    public function up()
    {
        // 1. 收银台配置（支付开关/抹零位数/交班打印机）
        $this->execute("CREATE TABLE IF NOT EXISTS `restaurant_cashdesk` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) DEFAULT NULL COMMENT '账户ID',
  `bid` int(11) DEFAULT '0' COMMENT '商家ID 0表示平台',
  `name` varchar(255) DEFAULT NULL COMMENT '收银台名称',
  `url` varchar(255) DEFAULT NULL COMMENT '收银台地址',
  `createtime` int(11) DEFAULT NULL COMMENT '创建时间',
  `updatetime` int(11) DEFAULT NULL COMMENT '更新时间',
  `remove_zero_length` tinyint(4) DEFAULT '0' COMMENT '抹零位数',
  `option_name` varchar(32) DEFAULT '' COMMENT '选项名称',
  `color1` varchar(60) DEFAULT NULL COMMENT '主题色',
  `wxpay` tinyint(1) DEFAULT '0' COMMENT '微信收款',
  `cashpay` tinyint(1) DEFAULT '1' COMMENT '现金收款',
  `moneypay` tinyint(1) DEFAULT '1' COMMENT '余额收款',
  `alipay` tinyint(1) DEFAULT '0' COMMENT '支付宝收款',
  `sxpay` tinyint(1) DEFAULT '0' COMMENT '随行付收款',
  `guazhangpay` tinyint(1) DEFAULT '1' COMMENT '挂账收款',
  `default_paypwd` varchar(50) DEFAULT NULL COMMENT '默认支付密码',
  `paypwd_use_status` tinyint(1) DEFAULT '0' COMMENT '余额支付输入支付密码',
  `jiaoban_print_ids` varchar(255) DEFAULT NULL COMMENT '交班打印机',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `aid` (`aid`) USING BTREE,
  KEY `bid` (`bid`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='餐饮收银台配置'");

        // 2. 自定义支付方式字典（canzhao 口径字段名为 title）
        $this->execute("CREATE TABLE IF NOT EXISTS `restaurant_cashdesk_custom_pay` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) DEFAULT '0' COMMENT '账户ID',
  `bid` int(11) DEFAULT '0' COMMENT '商家ID 0表示平台',
  `title` varchar(255) DEFAULT NULL COMMENT '支付方式名称',
  `sort` int(11) DEFAULT '0' COMMENT '排序',
  `status` tinyint(1) DEFAULT '1' COMMENT '状态 0停用 1启用',
  `createtime` int(11) DEFAULT '0' COMMENT '创建时间',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `aid` (`aid`) USING BTREE,
  KEY `bid` (`bid`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='收银台自定义支付方式'");

        // 3. 取餐号（叫号队列）
        $this->execute("CREATE TABLE IF NOT EXISTS `restaurant_take_food` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) DEFAULT NULL COMMENT '账户ID',
  `bid` int(11) DEFAULT '0' COMMENT '商家ID 0表示平台',
  `mid` int(11) DEFAULT NULL COMMENT '会员ID',
  `orderid` int(11) DEFAULT '0' COMMENT '订单ID',
  `pickup_number` varchar(60) NOT NULL COMMENT '取餐号',
  `create_time` int(11) DEFAULT NULL COMMENT '创建时间',
  `call_time` int(11) DEFAULT NULL COMMENT '叫号时间',
  `status` int(11) DEFAULT '0' COMMENT '0制作中;1待取餐;-1过期',
  `call_text` varchar(255) DEFAULT NULL COMMENT '叫号语音文字',
  `platform` varchar(60) DEFAULT 'wx' COMMENT '来源平台',
  `call_voice_url` varchar(255) DEFAULT NULL COMMENT '叫号语音地址',
  `need_play` tinyint(1) DEFAULT '0' COMMENT '需要播放音频',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `aid` (`aid`) USING BTREE,
  KEY `mid` (`mid`) USING BTREE,
  KEY `queue_no` (`pickup_number`) USING BTREE,
  KEY `status` (`status`) USING BTREE,
  KEY `create_time` (`create_time`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='取餐叫号列表'");

        // 4. 取餐设置（轮播/大屏图/间隔）
        $this->execute("CREATE TABLE IF NOT EXISTS `restaurant_take_food_sysset` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) DEFAULT NULL COMMENT '账户ID',
  `bid` int(11) DEFAULT '0' COMMENT '商家ID 0表示平台',
  `banner` text COMMENT '轮播图（多图）',
  `screen_pic` varchar(255) DEFAULT NULL COMMENT '大屏背景图',
  `banner_interval` int(11) DEFAULT '3' COMMENT '轮播间隔',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `aid` (`aid`) USING BTREE,
  KEY `bid` (`bid`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='取餐设置'");

        // 5. 寄存位置字典
        $this->execute("CREATE TABLE IF NOT EXISTS `restaurant_deposit_position` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) DEFAULT NULL COMMENT '账户ID',
  `bid` int(11) DEFAULT '0' COMMENT '商家ID 0表示平台',
  `name` varchar(255) DEFAULT NULL COMMENT '位置名称',
  `status` tinyint(1) DEFAULT '0' COMMENT '状态',
  `sort` int(11) DEFAULT '0' COMMENT '排序',
  `createtime` int(11) DEFAULT '0' COMMENT '创建时间',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `aid` (`aid`) USING BTREE,
  KEY `bid` (`bid`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='寄存位置字典'");

        // 6. 寄存编号字典
        $this->execute("CREATE TABLE IF NOT EXISTS `restaurant_deposit_position_number` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) DEFAULT NULL COMMENT '账户ID',
  `bid` int(11) DEFAULT '0' COMMENT '商家ID 0表示平台',
  `position_id` int(11) DEFAULT '0' COMMENT '位置ID',
  `name` varchar(255) DEFAULT NULL COMMENT '编号名称',
  `sort` int(11) DEFAULT '0' COMMENT '排序',
  `status` tinyint(1) DEFAULT '1' COMMENT '状态',
  `createtime` int(11) DEFAULT '0' COMMENT '创建时间',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `aid` (`aid`) USING BTREE,
  KEY `bid` (`bid`) USING BTREE,
  KEY `position_id` (`position_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='寄存编号字典'");

        // 7. 菜品加料字典
        $this->execute("CREATE TABLE IF NOT EXISTS `restaurant_product_jialiao` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) DEFAULT NULL COMMENT '账户ID',
  `bid` int(11) DEFAULT '0' COMMENT '商家ID 0表示平台',
  `proid` int(11) DEFAULT '0' COMMENT '菜品ID',
  `title` varchar(255) DEFAULT NULL COMMENT '加料名称',
  `price` decimal(10,2) DEFAULT NULL COMMENT '加料价格',
  `limit_num` int(11) DEFAULT NULL COMMENT '数量限制',
  `createtime` int(11) DEFAULT '0' COMMENT '创建时间',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `aid` (`aid`) USING BTREE,
  KEY `bid` (`bid`) USING BTREE,
  KEY `proid` (`proid`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='菜品加料字典'");

        // 8. 计时计费日志
        $this->execute("CREATE TABLE IF NOT EXISTS `restaurant_timing_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) DEFAULT NULL COMMENT '账户ID',
  `bid` int(11) DEFAULT NULL COMMENT '商家ID',
  `tableid` int(11) DEFAULT NULL COMMENT '桌台ID',
  `orderid` int(11) DEFAULT NULL COMMENT '订单ID',
  `start_time` varchar(100) DEFAULT NULL COMMENT '开始时间',
  `end_time` varchar(100) DEFAULT NULL COMMENT '结束时间',
  `starttime` int(11) DEFAULT NULL COMMENT '开始时间戳',
  `endtime` int(11) DEFAULT NULL COMMENT '结束时间戳',
  `num` int(11) DEFAULT '0' COMMENT '数量',
  `createtime` int(11) DEFAULT '0' COMMENT '创建时间',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `aid` (`aid`) USING BTREE,
  KEY `bid` (`bid`) USING BTREE,
  KEY `orderid` (`orderid`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='计时计费日志'");
    }

    public function down()
    {
        $this->execute('DROP TABLE IF EXISTS `restaurant_timing_log`');
        $this->execute('DROP TABLE IF EXISTS `restaurant_product_jialiao`');
        $this->execute('DROP TABLE IF EXISTS `restaurant_deposit_position_number`');
        $this->execute('DROP TABLE IF EXISTS `restaurant_deposit_position`');
        $this->execute('DROP TABLE IF EXISTS `restaurant_take_food_sysset`');
        $this->execute('DROP TABLE IF EXISTS `restaurant_take_food`');
        $this->execute('DROP TABLE IF EXISTS `restaurant_cashdesk_custom_pay`');
        $this->execute('DROP TABLE IF EXISTS `restaurant_cashdesk`');
    }
}
