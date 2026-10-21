<?php
// 2026-10-04 点大/diandashop 残留彻底清洗（任务287 的补充，覆盖全库所有表所有文本列）：
// - 任意文本列里的 v2.diandashop.com / v2d.diandashop.com 图片链 → 映射到本地占位资源 /static/demo/* 或 /static/img/*
// - JSON 内 iconPath/selectedIconPath 脏链 → 内置线性图标 key（与 task287 一致）
// - 未登记的 diandashop 链接 → 整条置空
// - 「点大」字样 →「鼎极」
// 幂等：仅更新命中行（LIKE 判脏），重跑无副作用。

use think\facade\Db;
use think\migration\Migrator;

class CleanDiandaResidual extends Migrator
{
    /** 图标 key 映射关键词（与 console-admin navIcons.ts / task287 口径一致） */
    protected const KEY_MAP = [
        'kefu' => 'service', 'service' => 'service',
        'gwc' => 'cart', 'cart' => 'cart',
        'shoucang' => 'heart', 'fav' => 'heart', 'collect' => 'heart',
        'home' => 'home', 'index' => 'home',
        'category' => 'category', 'classify' => 'category', 'fenlei' => 'category',
        'my' => 'user', 'user' => 'user', 'member' => 'user',
        'message' => 'message', 'msg' => 'message',
    ];

    /** 按 basename 映射到本地资源（无前导斜杠已含） */
    protected const FILE_MAP = [
        'logo.jpg' => '/static/demo/logo.jpg',
        'icon_1.png' => '/static/demo/icon_1.png',
        'cart_64.png' => '/static/demo/cart_64.png',
        'message_64.png' => '/static/demo/message_64.png',
        'posterbg.jpg' => '/static/img/posterbg.jpg',
        'picture-1.jpg' => '/static/demo/picture-1.jpg',
        'picture-2.jpg' => '/static/demo/picture-2.jpg',
        'picture-3.jpg' => '/static/demo/picture-3.jpg',
        'restaurant_queue_bg.jpg' => '/static/demo/restaurant_queue_bg.jpg',
        'touxiang.png' => '/static/img/touxiang.png',
        'banner-1.jpg' => '/static/demo/banner-1.jpg',
        'banner-2.jpg' => '/static/demo/banner-2.jpg',
        'cart.svg' => '/static/demo/cart.svg',
        'hotdot3.png' => '/static/demo/hotdot3.png',
        'ico2-address.png' => '/static/demo/ico2-address.png',
        'ico2-cart.png' => '/static/demo/ico2-cart.png',
        'ico2-favrite.png' => '/static/demo/ico2-favrite.png',
        'ico2-lingquan.png' => '/static/demo/ico2-lingquan.png',
        'ico2-mingxi.png' => '/static/demo/ico2-mingxi.png',
        'ico2-quan.png' => '/static/demo/ico2-quan.png',
        'ico2-tixian.png' => '/static/demo/ico2-tixian.png',
        'ico2-zuji.png' => '/static/demo/ico2-zuji.png',
        'ico-commission.png' => '/static/demo/ico-commission.png',
        'ico-downorder.png' => '/static/demo/ico-downorder.png',
        'ico-myteam.png' => '/static/demo/ico-myteam.png',
        'ico-poster.png' => '/static/demo/ico-poster.png',
        'kanjia_banner.png' => '/static/demo/kanjia_banner.png',
        'level_1.png' => '/static/demo/level_1.png',
        'level_2.png' => '/static/demo/level_2.png',
        'notice2.png' => '/static/demo/notice2.png',
        'pintuan_banner1.png' => '/static/demo/pintuan_banner1.png',
        'userinfobg.png' => '/static/demo/userinfobg.png',
        // tabbar 图标
        'home.png' => '/static/demo/tabbar_home.png',
        'home2.png' => '/static/demo/tabbar_home2.png',
        'category.png' => '/static/demo/tabbar_category.png',
        'category2.png' => '/static/demo/tabbar_category2.png',
        'cart.png' => '/static/demo/tabbar_cart.png',
        'cart2.png' => '/static/demo/tabbar_cart2.png',
        'my.png' => '/static/demo/tabbar_my.png',
        'my2.png' => '/static/demo/tabbar_my2.png',
        'kefu.png' => '/static/demo/tabbar_kefu.png',
        'gwc.png' => '/static/demo/tabbar_gwc.png',
        'shoucang.png' => '/static/demo/tabbar_shoucang.png',
        'shoucangselected.png' => '/static/demo/tabbar_shoucangselected.png',
    ];

    public function up()
    {
        $rows = Db::query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()");
        foreach ($rows as $r) {
            $this->cleanTable((string) $r['TABLE_NAME']);
        }
    }

    public function down()
    {
        // 数据清理不可逆，无需回滚
    }

    protected function cleanTable(string $table): void
    {
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

    protected function cleanLeaf(string $v): string
    {
        // 替换 diandashop 图片链：按 basename 映射本地，未登记的整条 URL 置空
        $v = (string) preg_replace_callback(
            '#https?:\\\\?//[a-z0-9.]*diandashop\.com[^\s"\'<>\\\\]*#i',
            function ($m) {
                $url = $m[0];
                $base = strtolower(basename((string) parse_url($url, PHP_URL_PATH)));
                return self::FILE_MAP[$base] ?? '';
            },
            $v
        );

        return str_replace('点大', '鼎极', $v);
    }

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
