<?php

// 任务281 商品详情设置重做与商品描述文字化：
// - shop_product 补商品描述富文本列 description（单品描述，非空时 C 端优先渲染）
// - designer_shopdetail 补公共底部描述三列：description 富文本 / description_img 补充图 / share_login 分享海报登录口径
//
// INFORMATION_SCHEMA 判重，幂等。

use think\migration\Migrator;

class Task281DescriptionColumns extends Migrator
{
    public function change()
    {
        $this->addColumnIfMissing('shop_product', 'description', "ADD COLUMN `description` longtext COMMENT '商品描述富文本（单品，优先于公共底部描述）'");

        $this->addColumnIfMissing('designer_shopdetail', 'description', "ADD COLUMN `description` longtext COMMENT '公共底部商品描述富文本'");
        $this->addColumnIfMissing('designer_shopdetail', 'description_img', "ADD COLUMN `description_img` varchar(500) NOT NULL DEFAULT '' COMMENT '公共底部描述补充图'");
        $this->addColumnIfMissing('designer_shopdetail', 'share_login', "ADD COLUMN `share_login` tinyint(1) NOT NULL DEFAULT '1' COMMENT '分享海报 1需要登录 0无需登录'");
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
