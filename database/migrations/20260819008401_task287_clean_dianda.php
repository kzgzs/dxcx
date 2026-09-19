<?php

// 任务287 点大链接/字样清零：历史种子脚本（runtime/t165_seed.php）曾向多张配置表写入
// v2.diandashop.com 图片链与参考字样，本迁移幂等清理：
// - JSON 结构内 iconPath/selectedIconPath 脏链 → 映射为内置线性图标 key（home/category/cart/user/service/heart/message），其余脏链置空
// - 普通文本列脏链整条置空；「点大」字样替换为「鼎极」
// - 仅更新命中行（LIKE 判脏），重跑无副作用

use think\facade\Db;
use think\migration\Migrator;

class Task287CleanDianda extends Migrator
{
    /** 内置图标 key 映射关键词（与 console-admin navIcons.ts 口径一致） */
    protected const KEY_MAP = [
        'kefu' => 'service', 'service' => 'service',
        'gwc' => 'cart', 'cart' => 'cart',
        'shoucang' => 'heart', 'fav' => 'heart', 'collect' => 'heart',
        'home' => 'home', 'index' => 'home',
        'category' => 'category', 'classify' => 'category', 'fenlei' => 'category',
        'my' => 'user', 'user' => 'user', 'member' => 'user',
        'message' => 'message', 'msg' => 'message',
    ];

    public function up()
    {
        $tables = [
            'admin_set', 'admin_set_poster', 'collage_sysset', 'designer_login', 'designer_menu',
            'designer_menu2', 'designer_share', 'designer_shopdetail', 'designer_template',
            'designerpage', 'member_level', 'mendian', 'plog', 'shop_category', 'shop_sysset', 'tenant_config',
        ];

        foreach ($tables as $table) {
            $this->cleanTable($table);
        }
    }

    public function down()
    {
        // 数据清理不可逆，无需回滚
    }

    protected function cleanTable(string $table): void
    {
        $exists = Db::query("SHOW TABLES LIKE '{$table}'");
        if (empty($exists)) {
            return;
        }

        $cols = Db::query("SHOW COLUMNS FROM `{$table}`");
        $textCols = [];
        $pk = 'id';
        foreach ($cols as $c) {
            if ((string) ($c['Key'] ?? '') === 'PRI') {
                $pk = (string) $c['Field'];
            }
            if (preg_match('/char|text|json/i', (string) $c['Type'])) {
                $textCols[] = (string) $c['Field'];
            }
        }
        if (empty($textCols)) {
            return;
        }

        $where = [];
        foreach ($textCols as $c) {
            $where[] = "`{$c}` LIKE '%dianda%' OR `{$c}` LIKE '%点大%'";
        }

        $rows = Db::query("SELECT * FROM `{$table}` WHERE " . implode(' OR ', $where));
        foreach ($rows as $row) {
            $update = [];
            foreach ($textCols as $c) {
                $old = (string) ($row[$c] ?? '');
                if (stripos($old, 'dianda') === false && strpos($old, '点大') === false) {
                    continue;
                }
                $new = $this->cleanValue($old);
                if ($new !== $old) {
                    $update[$c] = $new;
                }
            }
            if (!empty($update)) {
                Db::name($table)->where($pk, $row[$pk])->update($update);
            }
        }
    }

    /**
     * 清理单个列值：JSON 结构递归走 walk，普通文本整链置空 + 字样替换
     */
    protected function cleanValue(string $v): string
    {
        $t = trim($v);
        if ($t !== '' && ($t[0] === '{' || $t[0] === '[')) {
            $d = json_decode($v, true);
            if (is_array($d)) {
                return json_encode($this->walk($d), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        return $this->cleanLeaf($v);
    }

    /**
     * 递归清理 JSON 叶子：图标键脏链映射内置 key，其余脏链置空
     */
    protected function walk(array $node): array
    {
        foreach ($node as $k => $val) {
            if (is_array($val)) {
                $node[$k] = $this->walk($val);
                continue;
            }
            if (!is_string($val) || (stripos($val, 'dianda') === false && strpos($val, '点大') === false)) {
                continue;
            }
            if (in_array($k, ['iconPath', 'selectedIconPath'], true) && stripos($val, 'dianda') !== false) {
                $node[$k] = $this->builtinKey($val);
            } else {
                $node[$k] = $this->cleanLeaf($val);
            }
        }

        return $node;
    }

    /**
     * 普通文本：整条脏链置空，「点大」替换为「鼎极」
     */
    protected function cleanLeaf(string $v): string
    {
        $v = (string) preg_replace('#https?://[^\s"\'<>]*dianda[^\s"\'<>]*#i', '', $v);

        return str_replace('点大', '鼎极', $v);
    }

    /**
     * 按 basename 关键词映射内置图标 key，无命中置空
     */
    protected function builtinKey(string $url): string
    {
        $base = strtolower(basename((string) parse_url($url, PHP_URL_PATH)));
        foreach (self::KEY_MAP as $kw => $key) {
            if (strpos($base, $kw) !== false) {
                return $key;
            }
        }

        return '';
    }
}
