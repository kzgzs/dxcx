<?php

// 页面设计器补丁迁移（P14-3）：
// 1. designerpage 增加 type 列（0 常规设计页 / 1 模板库），基线迁移[5/14]未含该列
// 2. designer_template 种子两套示例页面模板（幂等，仅在空表时写入）

use think\migration\Migrator;

class DesignerpageTypeTemplateSeed extends Migrator
{
    public function up()
    {
        // type 列（先探测，避免重复执行报错）
        $row = $this->fetchRow("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'designerpage' AND COLUMN_NAME = 'type' LIMIT 1");
        if (!$row) {
            $this->execute("ALTER TABLE `designerpage` ADD COLUMN `type` tinyint(1) NOT NULL DEFAULT '0' COMMENT '类型 0常规设计页 1模板库' AFTER `ishome`");
        }

        // 模板种子（designer_template 为空时写入）
        $count = $this->fetchRow("SELECT COUNT(*) AS c FROM `designer_template` LIMIT 1");
        if (!$count || (int) ($count['c'] ?? 0) > 0) {
            return;
        }

        $now = time();
        $json = static function ($data) {
            return str_replace(["'", '\\'], ["''", '\\\\'], json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        };

        // 模板一：基础首页（搜索+轮播+宫格导航+公告+标题+商品组）
        $tpl1Pageinfo = [[
            'id' => 'M0000000000000', 'temp' => 'topbar',
            'params' => ['title' => '基础首页模板', 'bgcolor' => '#F5F5F5', 'needlogin' => '0', 'quanxian' => ['all' => true]],
        ]];
        $tpl1Content = [
            ['id' => 'MTPL10000000001', 'temp' => 'search', 'params' => ['placeholder' => '输入关键字搜索您感兴趣的商品', 'color' => '#666666', 'bgcolor' => '#FFFFFF', 'borderradius' => 5, 'margin_x' => '0', 'margin_y' => '0', 'padding_x' => '6', 'padding_y' => '6'], 'data' => []],
            ['id' => 'MTPL10000000002', 'temp' => 'banner', 'params' => ['shape' => '', 'align' => 'center', 'bgcolor' => '#FFFFFF', 'height' => '200', 'borderradius' => '0', 'indicatordots' => '1', 'interval' => 5, 'margin_x' => '0', 'margin_y' => '0', 'padding_x' => '0', 'padding_y' => '0'], 'data' => [['imgurl' => '', 'hrefurl' => ''], ['imgurl' => '', 'hrefurl' => '']]],
            ['id' => 'MTPL10000000003', 'temp' => 'menu', 'params' => ['num' => '5', 'pernum' => '5', 'fontsize' => '14', 'iconsize' => '40', 'showicon' => '1', 'showtitle' => '1', 'bgcolor' => '#FFFFFF', 'margin_x' => '0', 'margin_y' => '0', 'padding_x' => '10', 'padding_y' => '5'], 'data' => [
                ['imgurl' => '', 'title' => '菜单一', 'hrefurl' => ''],
                ['imgurl' => '', 'title' => '菜单二', 'hrefurl' => ''],
                ['imgurl' => '', 'title' => '菜单三', 'hrefurl' => ''],
                ['imgurl' => '', 'title' => '菜单四', 'hrefurl' => ''],
                ['imgurl' => '', 'title' => '菜单五', 'hrefurl' => ''],
            ]],
            ['id' => 'MTPL10000000004', 'temp' => 'notice', 'params' => ['showicon' => 1, 'color' => '#666666', 'bgcolor' => '#FFFFFF', 'scroll' => 3, 'fontsize' => '14', 'margin_x' => '0', 'margin_y' => '0', 'padding_x' => '5', 'padding_y' => '7'], 'data' => [['title' => '这里是第一条公告的标题', 'hrefurl' => '']]],
            ['id' => 'MTPL10000000005', 'temp' => 'title', 'params' => ['title' => '猜你喜欢', 'fontsize' => '14', 'color' => '#333333', 'bgcolor' => '#F6F6F6', 'margin_x' => '0', 'margin_y' => '0', 'padding_x' => '10', 'padding_y' => '5'], 'data' => []],
            ['id' => 'MTPL10000000006', 'temp' => 'product', 'params' => ['style' => '2', 'showname' => '1', 'showprice' => '1', 'showcart' => '1', 'showsales' => '1', 'proshownum' => 6, 'productfrom' => '1', 'sortby' => 'sort', 'bgcolor' => '#FFFFFF', 'margin_x' => '0', 'margin_y' => '0', 'padding_x' => '8', 'padding_y' => '8'], 'data' => []],
        ];

        // 模板二：营销页（轮播+优惠券+标题+多图+商品组）
        $tpl2Pageinfo = [[
            'id' => 'M0000000000000', 'temp' => 'topbar',
            'params' => ['title' => '营销活动模板', 'bgcolor' => '#F5F5F5', 'needlogin' => '0', 'quanxian' => ['all' => true]],
        ]];
        $tpl2Content = [
            ['id' => 'MTPL20000000001', 'temp' => 'banner', 'params' => ['shape' => '', 'align' => 'center', 'bgcolor' => '#FFFFFF', 'height' => '200', 'borderradius' => '0', 'indicatordots' => '1', 'interval' => 5, 'margin_x' => '0', 'margin_y' => '0', 'padding_x' => '0', 'padding_y' => '0'], 'data' => [['imgurl' => '', 'hrefurl' => '']]],
            ['id' => 'MTPL20000000002', 'temp' => 'coupon', 'params' => ['style' => '1', 'couponfrom' => '1', 'sortby' => 'sort', 'shownum' => 6, 'bgcolor' => '#FFFFFF', 'margin_x' => '0', 'margin_y' => '0', 'padding_x' => '0', 'padding_y' => '4'], 'data' => []],
            ['id' => 'MTPL20000000003', 'temp' => 'title', 'params' => ['title' => '热销专区', 'fontsize' => '14', 'color' => '#333333', 'bgcolor' => '#F6F6F6', 'margin_x' => '0', 'margin_y' => '0', 'padding_x' => '10', 'padding_y' => '5'], 'data' => []],
            ['id' => 'MTPL20000000004', 'temp' => 'pictures', 'params' => ['style' => '2', 'imageRadius' => '5', 'bgcolor' => '#FFFFFF', 'margin_x' => '0', 'margin_y' => '0', 'padding_x' => '0', 'padding_y' => '0'], 'data' => [
                ['imgurl' => '', 'hrefurl' => ''],
                ['imgurl' => '', 'hrefurl' => ''],
                ['imgurl' => '', 'hrefurl' => ''],
                ['imgurl' => '', 'hrefurl' => ''],
            ]],
            ['id' => 'MTPL20000000005', 'temp' => 'product', 'params' => ['style' => '2', 'showname' => '1', 'showprice' => '1', 'showcart' => '1', 'showsales' => '1', 'proshownum' => 6, 'productfrom' => '1', 'sortby' => 'sort', 'bgcolor' => '#FFFFFF', 'margin_x' => '0', 'margin_y' => '0', 'padding_x' => '8', 'padding_y' => '8'], 'data' => []],
        ];

        $this->execute(sprintf(
            "INSERT INTO `designer_template` (`name`,`type`,`pageinfo`,`content`,`createtime`,`updatetime`,`sort`,`remark`) VALUES ('基础首页模板',1,'%s','%s',%d,%d,1,'搜索/轮播/宫格导航/公告/商品组的通用首页结构')",
            $json($tpl1Pageinfo), $json($tpl1Content), $now, $now
        ));
        $this->execute(sprintf(
            "INSERT INTO `designer_template` (`name`,`type`,`pageinfo`,`content`,`createtime`,`updatetime`,`sort`,`remark`) VALUES ('营销活动模板',0,'%s','%s',%d,%d,2,'轮播/优惠券/多图/商品组的促销活动页结构')",
            $json($tpl2Pageinfo), $json($tpl2Content), $now, $now
        ));
    }

    public function down()
    {
        $this->execute("DELETE FROM `designer_template` WHERE `name` IN ('基础首页模板','营销活动模板')");
        $this->execute("ALTER TABLE `designerpage` DROP COLUMN `type`");
    }
}
