<?php
// +----------------------------------------------------------------------
// | 周期购示例数据生成器：分类/商品(上下架)/规格/订单(五状态)/分期配送(待发/已发/已核销)/评价(四态)
// | 幂等：名称统一前缀 DEMO-CYC-；purge 仅按前缀 + 本生成器产物级联删除，不碰手工与它模块数据。
// | 表：cycle_category / cycle_product / cycle_guige / cycle_order / cycle_order_stage / cycle_comment
// +----------------------------------------------------------------------
namespace app\common\service;

use think\facade\Db;

class DemoCycleGenerator
{
    private string $prefix = 'DEMO-CYC-';
    private int $aid = 1;
    private int $now;

    // 订单状态
    private const ORDER_UNPAID  = 0;
    private const ORDER_PAID    = 1;
    private const ORDER_SHIPPED = 2;
    private const ORDER_COLLECT = 3;
    private const ORDER_CLOSED  = 4;

    // 分期计划状态
    private const STAGE_CREATED = 0;
    private const STAGE_PAID    = 1;
    private const STAGE_SHIPPED = 2;
    private const STAGE_DONE    = 3;

    public function __construct()
    {
        $this->now = time();
    }

    public function setAid(int $aid): void
    {
        $this->aid = $aid;
    }

    /** 仅删除本生成器数据（按前缀 + 级联），不动手工数据 */
    public function purge(): void
    {
        $like = $this->prefix . '%';

        // 本生成器商品 id（按名称前缀）
        $productIds = Db::name('cycle_product')->where('aid', $this->aid)->where('name', 'like', $like)->column('id');
        // 本生成器订单 id（按订单号前缀）
        $orderIds = Db::name('cycle_order')->where('aid', $this->aid)->where('ordernum', 'like', $like)->column('id');

        if ($orderIds) {
            Db::name('cycle_order_stage')->where('aid', $this->aid)->whereIn('orderid', $orderIds)->delete();
            Db::name('cycle_order')->where('aid', $this->aid)->whereIn('id', $orderIds)->delete();
        }
        if ($productIds) {
            Db::name('cycle_guige')->where('aid', $this->aid)->whereIn('proid', $productIds)->delete();
            Db::name('cycle_comment')->where('aid', $this->aid)->whereIn('proid', $productIds)->delete();
            Db::name('cycle_product')->where('aid', $this->aid)->whereIn('id', $productIds)->delete();
        }
        Db::name('cycle_category')->where('aid', $this->aid)->where('name', 'like', $like)->delete();
    }

    /** 生成完整演示数据（幂等：先 purge） */
    public function generate(): void
    {
        $this->purge();

        $catIds = $this->makeCategories();
        $productIds = $this->makeProducts($catIds);
        $this->makeGuiges($productIds);
        $orderIds = $this->makeOrders($productIds);
        $this->makeStages($orderIds);
        $this->makeComments($productIds);
    }

    /* ---------------- 分类 ---------------- */
    private function makeCategories(): array
    {
        // [名称, 排序, 图标]
        $cats = [
            [$this->prefix . '鼎极新鲜直达', 50, '/static/demo/picture-1.jpg'],
            [$this->prefix . '鼎极鲜花绿植', 40, '/static/demo/picture-2.jpg'],
            [$this->prefix . '鼎极乳品饮品', 30, '/static/demo/picture-3.jpg'],
            [$this->prefix . '鼎极米面粮油', 20, '/static/demo/banner-1.jpg'],
        ];
        $ids = [];
        foreach ($cats as [$name, $sort, $pic]) {
            $ids[] = Db::name('cycle_category')->insertGetId([
                'aid'        => $this->aid,
                'pid'        => 0,
                'name'       => $name,
                'pic'        => $pic,
                'status'     => 1,
                'sort'       => $sort,
                'createtime' => $this->now - 86400 * 20,
            ]);
        }
        return $ids;
    }

    /* ---------------- 商品 ---------------- */
    private function makeProducts(array $catIds): array
    {
        // [名称, 分类idx, 状态, 主图, ps_cycle, min_num, min_qsnum, sell_price, market_price, stock, sales, 提前支付天, 提前顺延天]
        $goods = [
            [$this->prefix . '鼎极有机时蔬周配箱', 0, 1, '/static/demo/picture-1.jpg', 2, 4, 1, 39.90, 49.90, 120, 326, 1, 0],
            [$this->prefix . '鼎极田园散养土鸡蛋', 0, 0, '/static/demo/picture-3.jpg', 1, 6, 1, 29.90, 39.90, 80, 58, 1, 1],
            [$this->prefix . '鼎极当季混搭鲜花束', 1, 1, '/static/demo/picture-2.jpg', 2, 4, 1, 49.90, 69.90, 60, 142, 2, 0],
            [$this->prefix . '鼎极牧场鲜牛奶整箱', 2, 1, '/static/demo/picture-1.jpg', 1, 8, 1, 45.00, 59.00, 200, 510, 1, 0],
            [$this->prefix . '鼎极东北长粒香米', 3, 0, '/static/demo/banner-1.jpg', 3, 3, 1, 89.00, 109.00, 50, 23, 3, 2],
        ];
        $ids = [];
        foreach ($goods as $i => [$name, $cidx, $status, $pic, $psCycle, $minNum, $minQs, $sellPrice, $marketPrice, $stock, $sales, $payDays, $extendDays]) {
            $everyday = $psCycle === 1 ? json_encode([['value' => 1, 'label' => '每天配送'], ['value' => 2, 'label' => '工作日配送']], JSON_UNESCAPED_UNICODE) : '';
            $ids[] = Db::name('cycle_product')->insertGetId([
                'aid'                => $this->aid,
                'bid'                => 0,
                'cid'                => $catIds[$cidx],
                'name'               => $name,
                'procode'            => 'CYC' . str_pad((string)($i + 1), 3, '0', STR_PAD_LEFT),
                'pic'                => $pic,
                'pics'               => $pic . ',' . $pic,
                'sales'              => $sales,
                'detail'             => json_encode([['type' => 'richtext', 'content' => '<p>鼎极甄选，周期配送，新鲜直达。</p>']], JSON_UNESCAPED_UNICODE),
                'market_price'       => $marketPrice,
                'sell_price'         => $sellPrice,
                'cost_price'         => round($sellPrice * 0.6, 2),
                'weight'             => 1000,
                'sort'               => 100 - $i,
                'status'             => $status,
                'stock'              => $stock,
                'createtime'         => $this->now - 86400 * (30 - $i * 3),
                'commissionset'      => 0,
                'guigedata'          => json_encode([['group' => '规格', 'values' => ['默认规格']]], JSON_UNESCAPED_UNICODE),
                'comment_score'      => 5.0,
                'comment_num'        => 0,
                'comment_haopercent' => 100,
                'freighttype'        => 1,
                'ischecked'          => 1,
                'ps_cycle'           => $psCycle,
                'everyday_item'      => $everyday,
                'advance_pay_days'   => $payDays,
                'advance_pay_time'    => 9,
                'advance_extend_days' => $extendDays,
                'min_num'            => $minNum,
                'min_qsnum'          => $minQs,
            ]);
        }
        return $ids;
    }

    /* ---------------- 规格（每商品 1 行，保证列表价/库存与编辑回显） ---------------- */
    private function makeGuiges(array $productIds): void
    {
        // cycle_days: 每日1/每周7/每月30；qsnum 总期数；each_num 每期数量
        $plan = [
            ['每周1箱·共4期', 7, 4, 1],
            ['每10天1盒·共6期', 10, 6, 1],
            ['每周1束·共4期', 7, 4, 1],
            ['每天1份·共8期', 1, 8, 1],
            ['每月1袋·共3期', 30, 3, 1],
        ];
        foreach ($productIds as $i => $proid) {
            [$gname, $cycleDays, $qsnum, $eachNum] = $plan[$i] ?? ['默认规格', 7, 3, 1];
            $p = Db::name('cycle_product')->where('id', $proid)->find();
            Db::name('cycle_guige')->insert([
                'aid'         => $this->aid,
                'proid'       => $proid,
                'name'        => $gname,
                'cycle_days'  => $cycleDays,
                'qsnum'       => $qsnum,
                'each_num'    => $eachNum,
                'pic'         => (string)($p['pic'] ?? ''),
                'market_price'=> (float)($p['market_price'] ?? 0),
                'cost_price'  => (float)($p['cost_price'] ?? 0),
                'sell_price'  => (float)($p['sell_price'] ?? 0),
                'weight'      => (int)($p['weight'] ?? 0),
                'stock'       => (int)($p['stock'] ?? 0),
                'procode'     => (string)($p['procode'] ?? ''),
                'sales'       => (int)($p['sales'] ?? 0),
                'ks'          => (string)$i,
            ]);
        }
    }

    /* ---------------- 订单（覆盖五状态 + 周期分期） ---------------- */
    private function makeOrders(array $productIds): array
    {
        // [订单号后缀, 商品idx, 状态, buytype, freight_type(1到店核销/2物流), 收货, 电话, 区域, 地址, 下单偏移天, 是否支付]
        $orders = [
            ['ORD001', 0, self::ORDER_UNPAID, 1, 2, '张先生', '13800001001', '河南省南阳市卧龙区', '文化路街道鼎盛小区3号楼', 1, false],
            ['ORD002', 3, self::ORDER_PAID, 1, 2, '李女士', '13800001002', '河南省南阳市宛城区', '仲景街道阳光花园底商', 2, true],
            ['ORD003', 0, self::ORDER_SHIPPED, 2, 2, '王女士', '13800001003', '河南省南阳市镇平县', '雪枫街道玉鼎大道18号', 5, true],
            ['ORD004', 1, self::ORDER_COLLECT, 3, 1, '赵先生', '13800001004', '河南省南阳市邓州市', '花洲街道财富名家', 12, true],
            ['ORD005', 2, self::ORDER_PAID, 2, 1, '孙女士', '13800001005', '河南省南阳市南召县', '城关街道丹霞路9号', 3, true],
            ['ORD006', 4, self::ORDER_CLOSED, 1, 2, '周先生', '13800001006', '河南省南阳市西峡县', '白羽街道建设路22号', 15, false],
        ];

        $orderIds = [];
        foreach ($orders as [$suffix, $pidx, $status, $buytype, $freightType, $linkman, $tel, $area, $address, $daysAgo, $paid]) {
            $proid = $productIds[$pidx];
            $p = Db::name('cycle_product')->where('id', $proid)->find();
            $gg = Db::name('cycle_guige')->where('proid', $proid)->order('id', 'asc')->find();
            $sellPrice = (float)($gg['sell_price'] ?? $p['sell_price'] ?? 0);
            $num = (int)($gg['each_num'] ?? 1);
            $ordernum = $this->prefix . $suffix;
            $createtime = $this->now - 86400 * $daysAgo;
            $freightText = $freightType === 1 ? '到店自提' : '物流配送';

            $row = [
                'aid'           => $this->aid,
                'bid'           => 0,
                'mid'           => 0,
                'ordernum'      => $ordernum,
                'buytype'       => $buytype,
                'title'         => (string)$p['name'],
                'proid'         => $proid,
                'proname'       => (string)$p['name'],
                'propic'        => (string)$p['pic'],
                'ggid'          => (int)($gg['id'] ?? 0),
                'ggname'        => (string)($gg['name'] ?? '默认规格'),
                'num'           => $num,
                'cost_price'    => round($sellPrice * 0.6, 2),
                'sell_price'    => $sellPrice,
                'totalprice'    => $sellPrice,
                'product_price'  => $sellPrice,
                'freight_price' => 0,
                'createtime'    => $createtime,
                'status'        => $status,
                'linkman'       => $linkman,
                'tel'           => $tel,
                'area'          => $area,
                'address'       => $address,
                'freight_text'  => $freightText,
                'freight_type'  => $freightType,
                'platform'      => 'wx',
                'qsnum'         => (int)($gg['qsnum'] ?? 1),
                'ps_cycle'      => (int)($p['ps_cycle'] ?? 2),
                'start_date'    => date('Y-m-d', $createtime + 86400),
            ];
            if ($paid) {
                $row['paytype']  = 'wechat';
                $row['paynum']   = 'WX' . $createtime . str_pad((string)random_int(100, 999), 3, '0', STR_PAD_LEFT);
                $row['paytime']  = $createtime + 3600;
            }
            if ($status === self::ORDER_SHIPPED) {
                $row['express_com'] = '圆通快递';
                $row['express_no'] = 'YTO' . $createtime;
                $row['send_time']   = $createtime + 86400;
            }
            if ($status === self::ORDER_COLLECT) {
                $row['collect_time'] = $createtime + 86400 * 10;
            }
            $orderIds[$suffix] = Db::name('cycle_order')->insertGetId($row);
        }
        return $orderIds;
    }

    /* ---------------- 分期配送计划（待发货/已发货/已完成[核销]） ---------------- */
    private function makeStages(array $orderIds): void
    {
        // 按订单号后缀定义每期状态序列：status 1待发 2已发 3完成
        // 未支付单(ORD001)分期=0创建；已关闭单(ORD006)不生成可见分期
        $plan = [
            'ORD001' => [self::STAGE_CREATED, self::STAGE_CREATED, self::STAGE_CREATED],
            'ORD002' => [self::STAGE_PAID, self::STAGE_PAID, self::STAGE_PAID, self::STAGE_PAID],
            'ORD003' => [self::STAGE_DONE, self::STAGE_SHIPPED, self::STAGE_PAID, self::STAGE_PAID],
            'ORD004' => [self::STAGE_DONE, self::STAGE_DONE, self::STAGE_DONE],
            'ORD005' => [self::STAGE_SHIPPED, self::STAGE_PAID],
            'ORD006' => [],
        ];

        foreach ($plan as $suffix => $statusSeq) {
            $orderId = $orderIds[$suffix] ?? 0;
            if ($orderId <= 0) {
                continue;
            }
            $o = Db::name('cycle_order')->where('id', $orderId)->find();
            $cycleDays = 7;
            $baseTime = (int)$o['createtime'] + 86400;
            $perPrice = round((float)$o['totalprice'] / max(1, count($statusSeq) ?: 1), 2);

            foreach ($statusSeq as $i => $st) {
                $cycleNo = $i + 1;
                $stageTime = $baseTime + $i * $cycleDays * 86400;
                $row = [
                    'aid'             => $this->aid,
                    'bid'             => 0,
                    'mid'             => 0,
                    'ordernum'        => (string)$o['ordernum'],
                    'orderid'         => $orderId,
                    'cycle_date'      => date('Y-m-d', $stageTime),
                    'cycle_strtotime' => $stageTime,
                    'status'          => $st,
                    'cycle_number'    => $cycleNo,
                    'proname'         => (string)$o['proname'],
                    'propic'          => (string)$o['propic'],
                    'ggname'          => (string)$o['ggname'],
                    'num'             => (int)$o['num'],
                    'sell_price'      => $perPrice,
                ];
                if ($st === self::STAGE_SHIPPED) {
                    $row['express_com'] = '圆通快递';
                    $row['express_no']  = 'YTO' . $stageTime;
                    $row['send_time']   = $stageTime - 3600;
                }
                if ($st === self::STAGE_DONE) {
                    $row['collect_time'] = $stageTime + 86400;
                    if ((int)$o['freight_type'] === 1) {
                        $row['hexiao_code'] = 'HX' . $stageTime;
                    }
                }
                Db::name('cycle_order_stage')->insert($row);
            }
        }
    }

    /* ---------------- 评价（待回复/已回复/已审核/已驳回） ---------------- */
    private function makeComments(array $productIds): void
    {
        // [商品idx, 评分, 内容, 状态(0未审/1已审/2驳回), 回复内容, 多少天前, 昵称]
        $comments = [
            [0, 5, '蔬菜很新鲜，每周按时送到，包装也很用心，鼎极的品质值得信赖。', 0, '', 1, '暖阳妈咪'],
            [0, 5, '第二次订购了，菜量足、品种搭配合理，老人孩子都爱吃。', 1, '感谢您的认可，我们会继续保持品质！', 4, '山风拂面'],
            [2, 4, '花束搭配好看，就是有一支花瓣有点蔫，整体还不错。', 1, '', 6, '花间集'],
            [3, 1, '收到时有一盒漏液，联系客服还没处理，失望。', 2, '', 8, '追风少年'],
            [1, 5, '土鸡蛋蛋黄很黄，宝宝很爱吃，周期配送太省心了。', 1, '已为您补发一盒，请注意查收，给您带来不便敬请谅解。', 10, '琪琪妈'],
        ];

        foreach ($comments as $i => [$pidx, $score, $content, $status, $reply, $daysAgo, $nickname]) {
            $proid = $productIds[$pidx];
            $p = Db::name('cycle_product')->where('id', $proid)->find();
            $row = [
                'aid'           => $this->aid,
                'bid'           => 0,
                'mid'           => 0,
                'orderid'       => 0,
                'ogid'          => 0,
                'proid'         => $proid,
                'proname'       => (string)$p['name'],
                'propic'        => (string)$p['pic'],
                'ggid'          => 0,
                'ggname'        => '默认规格',
                'ordernum'      => $this->prefix . 'ORD00' . (($pidx % 6) + 1),
                'openid'        => '',
                'nickname'      => $nickname,
                'headimg'       => '',
                'score'         => $score,
                'content'       => $content,
                'content_pic'   => '',
                'reply_content' => $reply,
                'createtime'    => $this->now - 86400 * $daysAgo,
                'status'        => $status,
            ];
            if ('' !== $reply) {
                $row['reply_time'] = $row['createtime'] + 86400;
            }
            Db::name('cycle_comment')->insert($row);
        }

        // 重算商品评价聚合（与控制器口径一致）
        foreach ($productIds as $proid) {
            $approved = Db::name('cycle_comment')->where('aid', $this->aid)->where('proid', $proid)->where('status', 1);
            $num = (clone $approved)->count();
            $avg = (clone $approved)->avg('score');
            $goodNum = (clone $approved)->where('score', '>', 3)->count();
            Db::name('cycle_product')->where('id', $proid)->update([
                'comment_num'        => $num,
                'comment_score'      => $num > 0 ? round((float)$avg, 1) : 5.0,
                'comment_haopercent' => $num > 0 ? (int)round($goodNum / $num * 100) : 100,
            ]);
        }
    }
}
