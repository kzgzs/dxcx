<?php
// P6 验收修复：carhailing_category / hotel_room 补 create_time 列（Unix 秒，幂等）
//
// 前端列表需展示「创建时间」，两表建表时未含该列，此处补列并回填存量行（迁移执行时刻）。

use think\migration\Migrator;

class CreateTimePatch extends Migrator
{
    public function change()
    {
        $this->addColumnIfMissing('carhailing_category', 'create_time', "ADD COLUMN `create_time` int unsigned NOT NULL DEFAULT '0' COMMENT '创建时间（Unix秒）'");
        $this->addColumnIfMissing('hotel_room', 'create_time', "ADD COLUMN `create_time` int unsigned NOT NULL DEFAULT '0' COMMENT '创建时间（Unix秒）'");

        // 存量行回填（0 → 迁移执行时刻），保证前端不显示 "-"
        $this->execute("UPDATE `carhailing_category` SET `create_time` = UNIX_TIMESTAMP() WHERE `create_time` = 0");
        $this->execute("UPDATE `hotel_room` SET `create_time` = UNIX_TIMESTAMP() WHERE `create_time` = 0");
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
