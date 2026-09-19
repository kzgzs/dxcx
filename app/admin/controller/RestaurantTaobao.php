<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\PlogService;
use app\common\service\ScopeService;
use think\facade\Db;

/**
 * 租户后台 - 餐饮菜品采集（对照参考系统 RestaurantTaobao）
 *
 * 支持淘宝/天猫(0/1)、京东(2)、拼多多(3)、1688(4) 链接采集入库
 * 第三方抓取接口（6bqb API）按任务要求以 mock 数据替代，
 * 入库结构与参考系统一致：restaurant_product + restaurant_product_guige，status=0 未上架
 */
class RestaurantTaobao extends BaseController
{
    /** 平台映射 */
    private const PLATFORMS = [0 => '淘宝', 1 => '天猫', 2 => '京东', 3 => '拼多多', 4 => '1688'];

    /**
     * 采集配置（apikey 回显）
     * GET /admin/restaurant/taobao/config
     */
    public function config()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success([
            'apikey' => (string) cache('99apikey_' . $aid . '_0'),
        ]);
    }

    /**
     * 保存 apikey
     * POST /admin/restaurant/taobao/saveKey
     */
    public function saveKey()
    {
        $aid    = ScopeService::aidOf($this->request);
        $apikey = trim((string) $this->request->post('apikey', ''));
        if ('' === $apikey) {
            throw new BusinessException('请填写apikey');
        }

        cache('99apikey_' . $aid . '_0', $apikey);

        return $this->success([], '保存成功');
    }

    /**
     * 采集商品链接（第三方抓取 mock 实现）
     * POST /admin/restaurant/taobao/collect  { type, taobaourl, apikey? }
     */
    public function collect()
    {
        $aid = ScopeService::aidOf($this->request);
        $uid = (int) ($this->request->authUid ?? 0);

        $type = (int) $this->request->post('type', 0);
        if (!isset(self::PLATFORMS[$type])) {
            throw new BusinessException('不支持的采集平台');
        }

        $apikey = trim((string) $this->request->post('apikey', ''));
        if ('' !== $apikey) {
            cache('99apikey_' . $aid . '_0', $apikey);
        }

        $url = trim((string) $this->request->post('taobaourl', ''));
        if ('' === $url) {
            throw new BusinessException('请填写商品链接或商品ID');
        }

        // ---------- mock 抓取（真实环境调用第三方 API） ----------
        $itemId = $this->parseItemId($url, $type);
        $mock   = $this->mockItem($itemId, $type);

        // ---------- 入库 ----------
        $data = [
            'aid'         => $aid,
            'bid'         => 0,
            'name'        => $mock['title'],
            'sellpoint'   => $mock['subTitle'],
            'pic'         => $mock['pics'][0],
            'pics'        => implode(',', $mock['pics']),
            'detail'      => json_encode([[
                'id'      => 'M0000000000000',
                'temp'    => 'richtext',
                'params'  => ['bgcolor' => '#FFFFFF', 'margin_x' => 0, 'margin_y' => 0, 'padding_x' => 0, 'padding_y' => 0, 'quanxian' => ['all' => true], 'platform' => ['all' => true]],
                'data'    => '',
                'other'   => '',
                'content' => '<p>' . $mock['subTitle'] . '（采集自' . self::PLATFORMS[$type] . '，mock数据）</p>',
            ]], JSON_UNESCAPED_UNICODE),
            'market_price' => $mock['marketPrice'],
            'sell_price'   => $mock['sellPrice'],
            'guigedata'    => json_encode($mock['guigedata'], JSON_UNESCAPED_UNICODE),
            'status'       => 0,
            'is_delete'    => 0,
            'create_time'  => time(),
            'update_time'  => time(),
        ];
        $proid = (int) Db::name('restaurant_product')->insertGetId($data);

        foreach ($mock['skus'] as $sku) {
            Db::name('restaurant_product_guige')->insert([
                'aid'          => $aid,
                'product_id'   => $proid,
                'name'         => $sku['name'],
                'pic'          => '',
                'market_price' => $sku['market_price'],
                'sell_price'   => $sku['sell_price'],
                'stock'        => $sku['stock'],
                'ks'           => $sku['ks'],
            ]);
        }

        PlogService::write($aid, $uid, '餐饮菜品采集' . $proid, 0, (string) $this->request->ip());

        return $this->success(['proid' => $proid], '采集完成');
    }

    /** 从链接/ID中解析商品ID */
    private function parseItemId(string $url, int $type): string
    {
        if (ctype_digit($url)) {
            return $url;
        }
        if (in_array($type, [0, 1], true)) {
            $url = str_replace('&id=', '?id=', $url);
            if (str_contains($url, '?id=')) {
                $part = explode('?id=', $url)[1];
                return explode('&', $part)[0];
            }
        } elseif (2 === $type && str_contains($url, 'item.jd.com/')) {
            $part = explode('item.jd.com/', $url)[1];
            return explode('.html', $part)[0];
        } elseif (4 === $type && str_contains($url, 'detail.1688.com/offer/')) {
            $part = explode('detail.1688.com/offer/', $url)[1];
            return explode('.html', $part)[0];
        }
        // 无法解析时按链接 hash 生成稳定ID
        return (string) (crc32($url) % 1000000000);
    }

    /**
     * 生成 mock 商品数据（结构对齐第三方 API 返回）
     * @return array{title:string,subTitle:string,pics:string[],marketPrice:float,sellPrice:float,guigedata:array,skus:array}
     */
    private function mockItem(string $itemId, int $type): array
    {
        $seed     = crc32($itemId . '-' . $type);
        $platform = self::PLATFORMS[$type];

        $names = ['招牌秘制红烧肉套餐', '川香水煮鱼双人餐', '藤椒鸡丝拌面', '黑椒牛柳石锅饭', '鲜虾云吞面套餐', '农家小炒肉盖饭'];
        $name  = $names[$seed % count($names)] . '（' . $platform . '采集）';

        $pics = [];
        for ($i = 1; $i <= 3; $i++) {
            $pics[] = '/upload/mock/dish_' . ($seed % 6 + 1) . '_' . $i . '.jpg';
        }

        $specs = [['title' => '份量', 'items' => ['小份', '大份']], ['title' => '辣度', 'items' => ['不辣', '微辣', '中辣']]];
        $guigedata = [];
        foreach ($specs as $k => $spec) {
            $items = [];
            foreach ($spec['items'] as $k2 => $title) {
                $items[] = ['k' => $k2, 'title' => $title];
            }
            $guigedata[] = ['k' => $k, 'title' => $spec['title'], 'items' => $items];
        }

        $skus = [];
        $sellPrice = 0.0;
        $marketBase = 30.0 + ($seed % 40);
        foreach ($specs[0]['items'] as $k1 => $n1) {
            foreach ($specs[1]['items'] as $k2 => $n2) {
                $price = round($marketBase + $k1 * 8 + $k2 * 2, 2);
                $skus[] = [
                    'name'         => $n1 . ',' . $n2,
                    'ks'           => $k1 . ',' . $k2,
                    'stock'        => 50 + ($seed + $k1 + $k2) % 100,
                    'sell_price'   => $price,
                    'market_price' => round($price * 1.2, 2),
                ];
                if (0.0 === $sellPrice || $price < $sellPrice) {
                    $sellPrice = $price;
                }
            }
        }

        return [
            'title'       => $name,
            'subTitle'    => '采集演示菜品，新鲜现做，欢迎品尝',
            'pics'        => $pics,
            'marketPrice' => round($sellPrice * 1.2, 2),
            'sellPrice'   => $sellPrice,
            'guigedata'   => $guigedata,
            'skus'        => $skus,
        ];
    }
}
