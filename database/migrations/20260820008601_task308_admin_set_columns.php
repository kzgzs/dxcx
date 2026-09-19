<?php

// 任务308 客户端系统设置-基础设置 Tab 全量对齐：admin_set 补六列（INFORMATION_SCHEMA 判重，幂等）
//
// 口径对照参考后台基础设置板块：
// - guanzhu_tip      关注提示 CSV（1首页 2商品详情页），仅公众号端生效
// - order_broadcast  订单播报 CSV（1首页 2商品详情页），勾选页面滚动展示最近购买记录
// - navbgmode        导航背景颜色 0跟随系统主色调 1自定义颜色
// - navbgcolor       导航自定义背景颜色（hex）
// - navtextcolor     导航文字颜色 0黑色 1白色（仅支持黑/白）
// - visitperm        系统前端访问条件 CSV（all所有人/member普通会员/dealer经销商）

use think\migration\Migrator;

class Task308AdminSetColumns extends Migrator
{
    public function change()
    {
        $this->addColumnIfMissing('admin_set', 'guanzhu_tip', "ADD COLUMN `guanzhu_tip` varchar(20) DEFAULT '' COMMENT '关注提示 CSV 1首页 2商品详情页'");
        $this->addColumnIfMissing('admin_set', 'order_broadcast', "ADD COLUMN `order_broadcast` varchar(20) DEFAULT '1,2' COMMENT '订单播报 CSV 1首页 2商品详情页'");
        $this->addColumnIfMissing('admin_set', 'navbgmode', "ADD COLUMN `navbgmode` tinyint(1) DEFAULT '0' COMMENT '导航背景颜色 0跟随系统主色调 1自定义颜色'");
        $this->addColumnIfMissing('admin_set', 'navbgcolor', "ADD COLUMN `navbgcolor` varchar(20) DEFAULT '' COMMENT '导航自定义背景颜色'");
        $this->addColumnIfMissing('admin_set', 'navtextcolor', "ADD COLUMN `navtextcolor` tinyint(1) DEFAULT '0' COMMENT '导航文字颜色 0黑色 1白色'");
        $this->addColumnIfMissing('admin_set', 'visitperm', "ADD COLUMN `visitperm` varchar(50) DEFAULT 'all' COMMENT '系统前端访问条件 CSV all所有人 member普通会员 dealer经销商'");
    }

    /**
     * 列不存在时补列（幂等）
     */
    protected function addColumnIfMissing(string $table, string $column, string $alter): void
    {
        $exists = $this->fetchRow(
            "SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = '{$table}' AND column_name = '{$column}' LIMIT 1"
        );
        if ($exists) {
            return;
        }

        $this->execute("ALTER TABLE `{$table}` {$alter}");
    }
}
