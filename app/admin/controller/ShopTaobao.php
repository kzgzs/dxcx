<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\PlogService;
use app\common\service\ScopeService;
use think\facade\Cache;
use think\facade\Db;

/**
 * 租户后台 - 商品采集（对照参考系统 ShopTaobao 淘宝助手）
 *
 * apikey 配置（cache 99apikey_{aid}_{bid}）+ 商品链接采集：
 * 99API（api03.6bqb.com）四来源：0 淘宝 / 1 天猫（同 taobao/detail）/ 2 京东（jd/detail）/ 3 1688（alibaba/detail）
 * 采集入库 shop_product + shop_guige（status=0 未上架），远程图片登记 pictolocal 异步本地化
 * 外部 API 依赖用户自备 apikey，失败一律友好提示
 */
class ShopTaobao extends BaseController
{
    /** 99API 基础地址 */
    private const API_HOST = 'https://api03.6bqb.com';

    /** 单次本地化处理图片数 */
    private const PIC_BATCH = 10;

    /**
     * 采集页初始数据（回显已保存的 apikey）
     * GET /admin/shopTaobao/config
     */
    public function config()
    {
        $this->aid();

        return $this->success([
            'apikey' => (string) Cache::get($this->cacheKey(), ''),
        ]);
    }

    /**
     * 保存 apikey
     * POST /admin/shopTaobao/saveKey body:{apikey}
     */
    public function saveKey()
    {
        $this->aid();

        $apikey = trim((string) $this->request->post('apikey', ''));
        Cache::set($this->cacheKey(), $apikey);

        return $this->success([], $apikey === '' ? '已清空apikey' : 'apikey保存成功');
    }

    /**
     * 商品链接采集
     * POST /admin/shopTaobao/collect body:{type:0淘宝|1天猫|2京东|3阿里, url}
     */
    public function collect()
    {
        $aid = $this->aid();

        $apikey = trim((string) $this->request->post('apikey', ''));
        if ($apikey === '') {
            $apikey = (string) Cache::get($this->cacheKey(), '');
        }
        if ($apikey === '') {
            return $this->error('请先填写并保存 99API 的 apikey（需自行到 api03.6bqb.com 申请）');
        }
        // 顺手回存，保持与参考系统一致（index 提交即缓存）
        Cache::set($this->cacheKey(), $apikey);

        $url  = trim((string) $this->request->post('url', ''));
        $type = (int) $this->request->post('type', 0);
        if ($url === '') {
            return $this->error('请填写商品链接或商品ID');
        }
        if (!in_array($type, [0, 1, 2, 3], true)) {
            return $this->error('采集来源不正确');
        }

        set_time_limit(120);

        try {
            $result = match ($type) {
                0, 1    => $this->collectTaobao($aid, $apikey, $url),
                2       => $this->collectJd($aid, $apikey, $url),
                default => $this->collectAlibaba($aid, $apikey, $url),
            };
        } catch (\app\common\exception\BusinessException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            return $this->error('采集失败：' . $e->getMessage());
        }

        PlogService::write($aid, $this->uid(), '商城商品采集' . $result['proid'], $this->bid());

        return $this->success([
            'proid'     => $result['proid'],
            'pic_count' => $result['pic_count'],
        ], '采集完成，商品已入库（未上架），远程图片可执行本地化');
    }

    /**
     * 远程图片本地化（分批异步：前端循环调用直至 done）
     * POST /admin/shopTaobao/picLocal body:{limit?}
     * 处理 pictolocal 中 pic='' 的待办：下载远程图到 uploads/collect/{aid}/ 并回写商品引用
     */
    public function picLocal()
    {
        $aid   = $this->aid();
        $limit = max(1, min(50, (int) $this->request->post('limit', self::PIC_BATCH)));

        set_time_limit(120);

        $rows = Db::name('pictolocal')
            ->where('aid', $aid)
            ->where('pic', '')
            ->order('id', 'asc')
            ->limit($limit)
            ->select()
            ->toArray();

        if (empty($rows)) {
            return $this->success(['done' => true, 'processed' => 0, 'failed' => 0]);
        }

        $processed = 0;
        $failed    = 0;
        foreach ($rows as $row) {
            $remote = (string) $row['url'];
            if (strpos($remote, '//') === 0) {
                $remote = 'https:' . $remote;
            }

            $local = $this->downloadImage($aid, $remote);
            if ($local === '') {
                // 下载失败：pic 置占位防止无限重试，计数失败
                Db::name('pictolocal')->where('id', $row['id'])->update(['pic' => 'failed']);
                $failed++;
                continue;
            }

            Db::name('pictolocal')->where('id', $row['id'])->update(['pic' => $local]);
            $this->replaceImageRef($aid, (string) $row['url'], $local);
            $processed++;
        }

        $hasMore = Db::name('pictolocal')->where('aid', $aid)->where('pic', '')->count() > 0;

        return $this->success([
            'done'      => !$hasMore,
            'processed' => $processed,
            'failed'    => $failed,
        ]);
    }

    // ---------- 四来源采集（对照参考系统 taobaourl/jdurl/alibabaurl） ----------

    /** 淘宝/天猫（type 0/1，同一 taobao/detail 接口） */
    private function collectTaobao(int $aid, string $apikey, string $url): array
    {
        $itemid = $this->extractId($url, 'id');

        $rs = $this->apiGet(self::API_HOST . '/taobao/detail?apikey=' . urlencode($apikey) . '&itemid=' . urlencode($itemid));
        if (empty($rs)) {
            throw new \app\common\exception\BusinessException('采集失败：接口无响应，请稍后重试');
        }
        if ((string) ($rs['retcode'] ?? '') !== '0000') {
            $msg = $rs['message'] ?? (is_string($rs['data'] ?? null) ? $rs['data'] : '');
            throw new \app\common\exception\BusinessException('采集失败：' . ($msg !== '' ? $msg : '接口返回异常（请检查apikey是否有效）'));
        }

        $rsdata = $rs['data']['item'] ?? [];
        if (empty($rsdata)) {
            throw new \app\common\exception\BusinessException('采集失败：未获取到商品数据');
        }

        // 主图
        $pics = [];
        foreach ((array) ($rsdata['images'] ?? []) as $pic) {
            $pics[] = $this->normUrl((string) $pic);
        }

        // 详情图 -> 富文本模块（同参考系统 detail 结构）
        $content = '';
        foreach ((array) ($rsdata['descImgs'] ?? []) as $pic) {
            $pic     = $this->normUrl((string) $pic);
            $content .= '<img src="' . $pic . '" width="100%"/>';
        }

        // 规格结构
        $guigedata = [];
        $ggnames   = [];
        foreach ((array) ($rsdata['props'] ?? []) as $k => $v) {
            $items = [];
            foreach ((array) ($v['values'] ?? []) as $k2 => $v2) {
                $items[] = ['k' => $k2, 'title' => $v2['name'] ?? ''];
                $ggnames[$v['pid'] . ':' . $v2['vid']] = [
                    'k' => $k2, 'title' => $v2['name'] ?? '',
                    'pid' => $v['pid'], 'vid' => $v2['vid'],
                    'path' => $v['pid'] . ':' . $v2['vid'],
                ];
            }
            $guigedata[] = ['k' => $k, 'title' => $v['name'] ?? '', 'items' => $items, 'pid' => $v['pid'] ?? ''];
        }

        // SKU
        $sku        = (array) ($rsdata['sku'] ?? []);
        $sellPrice  = 0.0;
        $gglist     = [];
        $countSku   = count($sku);
        foreach ($sku as $k => $v) {
            if ($countSku > 1 && $k === 0) {
                continue; // 同参考系统：多 SKU 时首条为占位
            }
            $ksArr   = [];
            $nameArr = [];
            foreach (explode(';', (string) ($v['propPath'] ?? '')) as $path) {
                if (!isset($ggnames[$path])) {
                    continue;
                }
                $ksArr[]   = $ggnames[$path]['k'];
                $nameArr[] = $ggnames[$path]['title'];
            }
            $gglist[] = [
                'ks'         => implode(',', $ksArr),
                'name'       => implode(',', $nameArr),
                'stock'      => (int) ($v['quantity'] ?? 0),
                'sell_price' => (float) ($v['price'] ?? 0),
                'pic'        => $this->normUrl((string) ($v['image'] ?? '')),
            ];
            $price = (float) ($v['price'] ?? 0);
            if ($sellPrice == 0.0 || ($price > 0 && $price < $sellPrice)) {
                $sellPrice = $price;
            }
        }

        $data = [
            'aid'         => $aid,
            'bid'         => $this->bid(),
            'name'        => (string) ($rsdata['title'] ?? '采集商品'),
            'sellpoint'   => (string) ($rsdata['subTitle'] ?? ''),
            'pic'         => $pics[0] ?? '',
            'pics'        => implode(',', $pics),
            'detail'      => $this->detailJson($content),
            'sell_price'  => $sellPrice,
            'guigedata'   => json_encode($guigedata, JSON_UNESCAPED_UNICODE),
            'status'      => 0,
            'createtime'  => time(),
        ];

        return $this->insertProduct($aid, $data, $gglist, $pics, (array) ($rsdata['descImgs'] ?? []));
    }

    /** 京东（type 2，jd/detail 接口） */
    private function collectJd(int $aid, string $apikey, string $url): array
    {
        $itemid = $this->extractId($url, 'jd');

        $rs = $this->apiGet(self::API_HOST . '/jd/detail?apikey=' . urlencode($apikey) . '&itemid=' . urlencode($itemid));
        $rsdata = $rs['data']['item'] ?? [];
        if (empty($rsdata)) {
            throw new \app\common\exception\BusinessException('采集失败：未获取到京东商品数据（请检查链接与apikey）');
        }

        $pics = [];
        foreach ((array) ($rsdata['images'] ?? []) as $pic) {
            $pics[] = $this->normUrl((string) $pic);
        }

        $content = '';
        foreach ((array) ($rsdata['descImgs'] ?? []) as $pic) {
            $pic     = $this->normUrl((string) $pic);
            $content .= '<img src="' . $pic . '" width="100%"/>';
        }

        $pros     = (array) ($rsdata['skuProps'] ?? []);
        $saleProp = (array) ($rsdata['saleProp'] ?? []);

        $gglist    = [];
        $guigedata = [];
        if (!empty($pros)) {
            $ggnames = [];
            foreach ($saleProp as $k => $v) {
                $items = [];
                foreach ((array) ($pros[$k] ?? []) as $k2 => $v2) {
                    $items[]        = ['k' => $k2, 'title' => (string) $v2];
                    $ggnames[(string) $v2] = $k2;
                }
                $guigedata[] = ['k' => $k, 'title' => (string) $v, 'items' => $items];
            }

            $sellPrice = 0.0;
            foreach ((array) ($rsdata['sku'] ?? []) as $v) {
                $nameArr = [];
                $ksArr   = [];
                foreach ($saleProp as $sk => $sv) {
                    $nameArr[] = (string) ($v[$sk] ?? '');
                    $ksArr[]   = $ggnames[(string) ($v[$sk] ?? '')] ?? '';
                }
                $gglist[] = [
                    'ks'           => implode(',', $ksArr),
                    'name'         => implode(',', $nameArr),
                    'stock'        => (int) ($v['stockState'] ?? 0),
                    'sell_price'   => (float) ($v['price'] ?? 0),
                    'market_price' => (float) ($v['originalPrice'] ?? 0),
                    'pic'          => $this->normUrl((string) ($v['imagePath'] ?? '')),
                    'barcode'      => (string) ($v['skuId'] ?? ''),
                ];
                $price = (float) ($v['price'] ?? 0);
                if ($sellPrice == 0.0 || ($price > 0 && $price < $sellPrice)) {
                    $sellPrice = $price;
                }
            }
        } else {
            // 无规格商品：默认规格（同参考系统）
            $sellPrice = (float) ($rsdata['price'] ?? 0);
            $guigedata = [[
                'k' => 0, 'title' => '规格',
                'items' => [['k' => 0, 'title' => '默认规格']],
            ]];
            $gglist[] = [
                'ks'           => '0',
                'name'         => '默认规格',
                'stock'        => 0,
                'sell_price'   => $sellPrice,
                'market_price' => (float) ($rsdata['originalPrice'] ?? 0),
                'pic'          => '',
            ];
        }

        $data = [
            'aid'        => $aid,
            'bid'        => $this->bid(),
            'name'       => (string) ($rsdata['name'] ?? '采集商品'),
            'pic'        => $pics[0] ?? '',
            'pics'       => implode(',', $pics),
            'detail'     => $this->detailJson($content),
            'sell_price' => $sellPrice,
            'guigedata'  => json_encode($guigedata, JSON_UNESCAPED_UNICODE),
            'status'     => 0,
            'createtime' => time(),
        ];

        return $this->insertProduct($aid, $data, $gglist, $pics, (array) ($rsdata['descImgs'] ?? []));
    }

    /** 1688（type 3，alibaba/detail 接口） */
    private function collectAlibaba(int $aid, string $apikey, string $url): array
    {
        $itemid = $this->extractId($url, '1688');

        $rs = $this->apiGet(self::API_HOST . '/alibaba/detail?apikey=' . urlencode($apikey) . '&itemid=' . urlencode($itemid));
        if (empty($rs)) {
            throw new \app\common\exception\BusinessException('采集失败：接口无响应，请稍后重试');
        }
        if ((string) ($rs['retcode'] ?? '') !== '0000') {
            $msg = $rs['message'] ?? (is_string($rs['data'] ?? null) ? $rs['data'] : '');
            throw new \app\common\exception\BusinessException('采集失败：' . ($msg !== '' ? $msg : '接口返回异常（请检查apikey是否有效）'));
        }

        $rsdata = $rs['data'] ?? [];
        if (empty($rsdata)) {
            throw new \app\common\exception\BusinessException('采集失败：未获取到1688商品数据');
        }

        $pics = [];
        foreach ((array) ($rsdata['images'] ?? []) as $pic) {
            $pics[] = $this->normUrl((string) $pic);
        }

        $content = '';
        foreach ((array) ($rsdata['descImgs'] ?? []) as $pic) {
            $pic     = $this->normUrl((string) $pic);
            $content .= '<img src="' . $pic . '" width="100%"/>';
        }

        // 规格结构 + 规格图映射
        $guigedata = [];
        $ggnames   = [];
        $ggimgs    = [];
        foreach ((array) ($rsdata['skuProps'] ?? []) as $k => $v) {
            $items = [];
            foreach ((array) ($v['value'] ?? []) as $v2) {
                $items[]              = ['k' => count($items), 'title' => (string) ($v2['name'] ?? '')];
                $ggnames[$k][]        = (string) ($v2['name'] ?? '');
                if (isset($v2['imageUrl'])) {
                    $ggimgs[(string) $v2['name']] = $this->normUrl((string) $v2['imageUrl']);
                }
            }
            $guigedata[] = ['k' => $k, 'title' => (string) ($v['prop'] ?? ''), 'items' => $items];
        }

        // 多维规格笛卡尔组合（同参考系统 getCombinationToString）
        $ksnames = [];
        foreach ($ggnames as $vals) {
            $ksnames = $this->combineNames($ksnames, $vals);
        }

        $skuMap    = (array) ($rsdata['skuMap'] ?? []);
        $sellPrice = 0.0;
        $gglist    = [];
        foreach ($ksnames as $ks => $nameStr) {
            $name    = str_replace('&gt;', ',', $nameStr);
            $skuInfo = $skuMap[$nameStr] ?? [];
            $price   = (float) ($skuInfo['price'] ?? 0);
            $dprice  = (float) ($skuInfo['discountPrice'] ?? 0);
            $ggPic   = '';
            foreach (explode(',', $name) as $vn) {
                if (isset($ggimgs[$vn])) {
                    $ggPic = $ggimgs[$vn];
                }
            }
            $gglist[] = [
                'ks'           => (string) $ks,
                'name'         => $name,
                'stock'        => (int) ($skuInfo['canBookCount'] ?? 0),
                'market_price' => $price,
                'sell_price'   => $dprice > 0 ? $dprice : $price,
                'pic'          => $ggPic,
            ];
            $sp = $dprice > 0 ? $dprice : $price;
            if ($sellPrice == 0.0 || ($sp > 0 && $sp < $sellPrice)) {
                $sellPrice = $sp;
            }
        }

        $data = [
            'aid'        => $aid,
            'bid'        => $this->bid(),
            'name'       => (string) ($rsdata['title'] ?? '采集商品'),
            'pic'        => $pics[0] ?? '',
            'pics'       => implode(',', $pics),
            'detail'     => $this->detailJson($content),
            'sell_price' => $sellPrice,
            'guigedata'  => json_encode($guigedata, JSON_UNESCAPED_UNICODE),
            'status'     => 0,
            'createtime' => time(),
        ];

        return $this->insertProduct($aid, $data, $gglist, $pics, (array) ($rsdata['descImgs'] ?? []));
    }

    // ---------- 入库与图片 ----------

    /**
     * 商品+规格入库，远程图片登记 pictolocal 待本地化
     * @return array{proid:int, pic_count:int}
     */
    private function insertProduct(int $aid, array $data, array $gglist, array $pics, array $descImgs): array
    {
        $proid = (int) Db::name('shop_product')->insertGetId($data);

        foreach ($gglist as $gg) {
            Db::name('shop_guige')->insert([
                'aid'          => $aid,
                'proid'        => $proid,
                'name'         => (string) ($gg['name'] ?? ''),
                'pic'          => (string) ($gg['pic'] ?? ''), // 存原图，本地化阶段回写
                'sell_price'   => (float) ($gg['sell_price'] ?? 0),
                'market_price' => (float) ($gg['market_price'] ?? 0),
                'stock'        => (int) ($gg['stock'] ?? 0),
                'ks'           => (string) ($gg['ks'] ?? ''),
                'barcode'      => (string) ($gg['barcode'] ?? ''),
            ]);
        }

        // 登记远程图片（主图/详情图/规格图），picLocal 分批下载
        $remotes = [];
        foreach ($pics as $p) {
            $remotes[] = $p;
        }
        foreach ($descImgs as $p) {
            $remotes[] = $this->normUrl((string) $p);
        }
        foreach ($gglist as $gg) {
            if (!empty($gg['pic'])) {
                $remotes[] = (string) $gg['pic'];
            }
        }

        $count = 0;
        foreach (array_unique(array_filter($remotes)) as $remote) {
            if (!preg_match('#^(https?:)?//#i', $remote)) {
                continue; // 仅登记远程图
            }
            $exists = Db::name('pictolocal')->where('aid', $aid)->where('url', $remote)->find();
            if (empty($exists)) {
                Db::name('pictolocal')->insert([
                    'aid'        => $aid,
                    'url'        => $remote,
                    'pic'        => '',
                    'createtime' => time(),
                ]);
                $count++;
            }
        }

        return ['proid' => $proid, 'pic_count' => $count];
    }

    /**
     * 下载远程图片到本地 uploads/collect/{aid}/，返回站点路径；失败返回 ''
     */
    private function downloadImage(int $aid, string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) DxcxCollect/1.0',
            CURLOPT_HTTPHEADER     => ['Referer: https://www.taobao.com/'],
        ]);
        $bin  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($bin === false || $code !== 200 || strlen($bin) < 100) {
            return '';
        }

        // 内容类型 -> 扩展名
        $ext = match (true) {
            str_contains($type, 'png')  => 'png',
            str_contains($type, 'gif')  => 'gif',
            str_contains($type, 'webp') => 'webp',
            default                     => 'jpg',
        };
        // 二进制嗅探兜底
        if (substr($bin, 0, 3) === "\xFF\xD8\xFF") {
            $ext = 'jpg';
        } elseif (substr($bin, 0, 8) === "\x89PNG\r\n\x1a\n") {
            $ext = 'png';
        } elseif (substr($bin, 0, 4) === 'GIF8') {
            $ext = 'gif';
        } elseif (substr($bin, 0, 4) === 'RIFF' && substr($bin, 8, 4) === 'WEBP') {
            $ext = 'webp';
        }

        $dir = app()->getRootPath() . 'public/uploads/collect/' . $aid;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $name = date('Ymd') . '_' . md5($url) . '.' . $ext;
        if (@file_put_contents($dir . '/' . $name, $bin) === false) {
            return '';
        }

        return '/uploads/collect/' . $aid . '/' . $name;
    }

    /** 回写商品/规格中的远程图引用为本地路径 */
    private function replaceImageRef(int $aid, string $remote, string $local): void
    {
        $variants = array_unique([$remote, 'https:' . $remote, 'http:' . $remote]);

        foreach ($variants as $from) {
            if ($from === '') {
                continue;
            }
            Db::name('shop_product')
                ->where('aid', $aid)
                ->where(function ($q) use ($from) {
                    $q->whereLike('pic', '%' . $from . '%')
                        ->whereOr('pics', 'like', '%' . $from . '%')
                        ->whereOr('detail', 'like', '%' . $from . '%');
                })
                ->update([
                    'pic'    => Db::raw("REPLACE(`pic`,'" . addslashes($from) . "','" . addslashes($local) . "')"),
                    'pics'   => Db::raw("REPLACE(`pics`,'" . addslashes($from) . "','" . addslashes($local) . "')"),
                    'detail' => Db::raw("REPLACE(`detail`,'" . addslashes($from) . "','" . addslashes($local) . "')"),
                ]);

            Db::name('shop_guige')
                ->where('aid', $aid)
                ->whereLike('pic', '%' . $from . '%')
                ->update([
                    'pic' => Db::raw("REPLACE(`pic`,'" . addslashes($from) . "','" . addslashes($local) . "')"),
                ]);
        }
    }

    // ---------- 工具 ----------

    /** 从链接/纯ID提取商品ID（对照参考系统三种解析） */
    private function extractId(string $url, string $source): string
    {
        $url = trim($url);
        if (ctype_digit($url)) {
            return $url;
        }

        if ($source === 'id') {
            // 淘宝/天猫：&id= 或 ?id=
            $url = str_replace('&id=', '?id=', $url);
            if (!str_contains($url, '?id=')) {
                throw new \app\common\exception\BusinessException('无法从链接解析淘宝/天猫商品ID，请检查链接或直接填写商品ID');
            }
            $arr = explode('?id=', $url);
            return explode('&', $arr[1])[0];
        }

        if ($source === 'jd') {
            if (!str_contains($url, 'item.jd.com/')) {
                throw new \app\common\exception\BusinessException('无法从链接解析京东商品ID，请检查链接或直接填写商品ID');
            }
            $arr = explode('item.jd.com/', $url);
            return explode('.html', $arr[1])[0];
        }

        // 1688
        if (!str_contains($url, 'detail.1688.com/offer/')) {
            throw new \app\common\exception\BusinessException('无法从链接解析1688商品ID，请检查链接或直接填写商品ID');
        }
        $arr = explode('detail.1688.com/offer/', $url);
        return explode('.html', $arr[1])[0];
    }

    /** 协议补全（// 开头补 https:） */
    private function normUrl(string $url): string
    {
        $url = trim($url);
        if (strpos($url, '//') === 0) {
            return 'https:' . $url;
        }
        return $url;
    }

    /** 详情富文本模块 JSON（同参考系统 detail 结构） */
    private function detailJson(string $content): string
    {
        return (string) json_encode([[
            'id'      => 'M0000000000000',
            'temp'    => 'richtext',
            'params'  => [
                'bgcolor'   => '#FFFFFF',
                'margin_x'  => 0,
                'margin_y'  => 0,
                'padding_x' => 0,
                'padding_y' => 0,
                'quanxian'  => ['all' => true],
                'platform'  => ['all' => true],
            ],
            'data'    => '',
            'other'   => '',
            'content' => $content,
        ]], JSON_UNESCAPED_UNICODE);
    }

    /** 多维规格组合（同参考系统 getCombinationToString，去掉 static 隐患） */
    private function combineNames(array $prev, array $vals): array
    {
        if (empty($prev)) {
            $out = [];
            foreach ($vals as $k => $v) {
                $out[(string) $k] = (string) $v;
            }
            return $out;
        }

        $out = [];
        foreach ($prev as $k => $v) {
            foreach ($vals as $k2 => $v2) {
                $out[$k . ',' . $k2] = $v . '&gt;' . $v2;
            }
        }
        return $out;
    }

    /** 99API GET 请求（15s 超时，返回解码数组；网络失败返回 []） */
    private function apiGet(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        $rs   = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($rs === false) {
            throw new \app\common\exception\BusinessException('采集接口请求失败（' . $err . '），请检查服务器网络能否访问 api03.6bqb.com');
        }

        $decoded = json_decode((string) $rs, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** apikey 缓存键（同参考系统 99apikey_{aid}_{bid}） */
    private function cacheKey(): string
    {
        return '99apikey_' . $this->aid() . '_' . $this->bid();
    }

    protected function aid(): int
    {
        return ScopeService::aidOf($this->request);
    }

    protected function uid(): int
    {
        return (int) ($this->request->authUid ?? 0);
    }

    protected function bid(): int
    {
        static $bid = null;
        if ($bid === null) {
            $bid = (int) Db::name('admin_user')->where('id', $this->uid())->value('bid');
        }
        return $bid;
    }
}
