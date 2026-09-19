<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;

/**
 * 团购服务（P20-2 批次4-B 团购整套�?
 *
 * 关键差异：团购无成团机制——本质是独立商品体系的垂直小商城�?
 * 独立商品/分类/订单/评价/卡密/设置；支持到店核销（hexiao_code，hexiao_order type=tuangou�?
 * + 快递发货双通道�?
 *
 * 表口径（金额单位「元」）�?
 * - tuangou_sysset    团购设置：pics 轮播 / autoshdays 自动收货天数 / comment 评价开�?
 *                     / comment_check 评价审核 / showjd 显示进度
 * - tuangou_category  团购分类（pid 两级�?
 * - tuangou_product   团购商品：pricedata 团购价阶�?JSON [{num,money}]（人数递增、价格递减），
 *                     sell_price 为初始价（起价）；starttime/endtime 活动起止时间�?
 *                     perlimit 每人限购；freighttype 3=自动发货 4=在线卡密 其它=普通配送；
 *                     showtj 展示条件 / gettj 购买条件（口径预留）
 * - tuangou_order     团购订单：status 0未支�?1已支�?2已发�?3已收�?已核销 4关闭�?
 *                     refund_status 0�?1审核�?2已同�?3已驳回；
 *                     freight_type 0/10快�?1到店自提核销；delete 会员删除标记
 * - tuangou_comment   团购评价：status 0待审�?1显示 2驳回
 * - tuangou_codelist  团购卡密：status 0未售�?1已售出（在线卡密商品支付后自动发放）
 *
 * 阶梯价口径（对标参考系�?ApiTuangou）：
 * 已购总人�?= 该商�?status in (1,2,3) 订单 num 之和；依次匹�?pricedata�?
 * �?buynum >= num 的最高档为当前价；sell_price 为初始价（最高价），末档为最低价�?
 */
class TuangouService
{
    /** 订单状�?*/
    public const ORDER_UNPAID    = 0;
    public const ORDER_PAID      = 1;
    public const ORDER_SHIPPED   = 2;
    public const ORDER_COLLECTED = 3;
    public const ORDER_CLOSED    = 4;

    public const ORDER_STATUS_TEXT = [0 => '未支付', 1 => '已支付', 2 => '已发货', 3 => '已完成', 4 => '已关闭'];
    public const REFUND_TEXT       = [0 => '', 1 => '退款审核中', 2 => '已退款', 3 => '退款已驳回'];
    public const FREIGHT_TYPE_TEXT = [0 => '快递发货', 1 => '到店核销', 2 => '同城配送', 3 => '自动发货', 4 => '在线卡密', 10 => '快递发货'];

    public function __construct(
        protected PayService $payService
    ) {
    }

    /* ================= 团购设置 ================= */

    /**
     * 读取团购设置（无记录时落默认行）
     *
     * @return array<string, mixed>
     */
    public function sysset(int $aid): array
    {
        $info = ScopeService::query('tuangou_sysset', $aid)->find();

        if (empty($info)) {
            $id = ScopeService::query('tuangou_sysset', $aid)->insertGetId([
                'aid'           => $aid,
                'autoshdays'    => 7,
                'comment'       => 1,
                'comment_check' => 1,
                'showjd'        => 1,
            ]);

            $info = Db::name('tuangou_sysset')->where('id', $id)->find();
        }

        return [
            'pics'          => (string) ($info['pics'] ?? ''),
            'autoshdays'    => (int) ($info['autoshdays'] ?? 7),
            'comment'       => (int) ($info['comment'] ?? 1),
            'comment_check' => (int) ($info['comment_check'] ?? 1),
            'showjd'        => (int) ($info['showjd'] ?? 1),
        ];
    }

    /**
     * 保存团购设置（白名单字段�?
     *
     * @param array<string, mixed> $data
     */
    public function saveSysset(int $aid, array $data): void
    {
        $this->sysset($aid);

        $update = [];

        if (array_key_exists('pics', $data)) {
            $update['pics'] = trim((string) $data['pics']);
        }

        if (isset($data['autoshdays'])) {
            $update['autoshdays'] = max(1, (int) $data['autoshdays']);
        }

        foreach (['comment', 'comment_check', 'showjd'] as $key) {
            if (isset($data[$key])) {
                $update[$key] = (int) $data[$key] === 1 ? 1 : 0;
            }
        }

        if (!empty($update)) {
            ScopeService::query('tuangou_sysset', $aid)->update($update);
        }
    }

    /* ================= 团购分类 ================= */

    /**
     * 分类列表（扁平全量，前端自行组树；status �?全部�?
     *
     * @return array<int, array<string, mixed>>
     */
    public function categoryList(int $aid): array
    {
        $rows = ScopeService::query('tuangou_category', $aid)
            ->order('sort', 'desc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        return array_map(static fn (array $r): array => [
            'id'         => (int) ($r['id'] ?? 0),
            'pid'        => (int) ($r['pid'] ?? 0),
            'name'       => (string) ($r['name'] ?? ''),
            'pic'        => (string) ($r['pic'] ?? ''),
            'status'     => (int) ($r['status'] ?? 1),
            'sort'       => (int) ($r['sort'] ?? 1),
            'createtime' => (int) ($r['createtime'] ?? 0),
        ], $rows);
    }

    /**
     * 分类新增/编辑（id>0 编辑�?
     *
     * @param array<string, mixed> $data
     * @return array{id: int}
     */
    public function categorySave(int $aid, array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));

        if ('' === $name) {
            throw new BusinessException('请输入分类名称');
        }

        $pid = (int) ($data['pid'] ?? 0);

        $id = (int) ($data['id'] ?? 0);

        if ($pid > 0) {
            $parent = ScopeService::query('tuangou_category', $aid)->where('id', $pid)->find();

            if (empty($parent)) {
                throw new BusinessException('上级分类不存在');
            }

            if ((int) $parent['pid'] > 0) {
                throw new BusinessException('分类最多两级');
            }

            if ($pid === $id) {
                throw new BusinessException('上级分类不能是自己');
            }
        }

        $row = [
            'pid'    => $pid,
            'name'   => $name,
            'pic'    => trim((string) ($data['pic'] ?? '')),
            'status' => (int) ($data['status'] ?? 1) === 0 ? 0 : 1,
            'sort'   => (int) ($data['sort'] ?? 1),
        ];

        if ($id > 0) {
            $exists = ScopeService::query('tuangou_category', $aid)->where('id', $id)->find();

            if (empty($exists)) {
                throw new BusinessException('分类不存在', 404, 404);
            }

            ScopeService::query('tuangou_category', $aid)->where('id', $id)->update($row);
        } else {
            $row['aid']        = $aid;
            $row['createtime'] = time();

            $id = (int) ScopeService::query('tuangou_category', $aid)->insertGetId($row);
        }

        return ['id' => $id];
    }

    /**
     * 删除分类（存在子分类或挂载商品时拒绝�?
     */
    public function categoryDelete(int $aid, int $id): void
    {
        $exists = ScopeService::query('tuangou_category', $aid)->where('id', $id)->find();

        if (empty($exists)) {
            throw new BusinessException('分类不存在', 404, 404);
        }

        $children = ScopeService::query('tuangou_category', $aid)->where('pid', $id)->count();

        if ($children > 0) {
            throw new BusinessException('该分类下存在子分类，请先删除子分类');
        }

        $products = ScopeService::query('tuangou_product', $aid)->where('cid', $id)->count();

        if ($products > 0) {
            throw new BusinessException('该分类下存在商品，请先转移商品');
        }

        ScopeService::query('tuangou_category', $aid)->where('id', $id)->delete();
    }

    /* ================= 团购商品 ================= */

    /**
     * 商品列表（keyword/status/checked/cid 过滤；bid 商户维度�?
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function productList(int $aid, string $keyword, string $status, string $checked, int $cid, int $page, int $pageSize, string $bid = '', string $orderField = 'sort', string $orderType = 'desc'): array
    {
        $query = ScopeService::query('tuangou_product', $aid);

        ScopeService::applyBid($query, $bid, 'bid');

        if ('' !== trim($keyword)) {
            $query->where('name', 'like', '%' . trim($keyword) . '%');
        }

        if ('' !== $status) {
            $query->where('status', (int) $status);
        }

        if ('' !== $checked) {
            $query->where('ischecked', (int) $checked);
        }

        if ($cid > 0) {
            $query->where('cid', $cid);
        }

        $total = (clone $query)->count();

        // 排序白名单（对标参考系统 field/order）
        $allowSort = ['id' => 'id', 'sales' => 'sales', 'stock' => 'stock', 'sort' => 'sort', 'createtime' => 'createtime'];
        $sortField = $allowSort[$orderField] ?? 'sort';
        $sortOrder = 'asc' === strtolower($orderType) ? 'asc' : 'desc';

        $rows = $query->order($sortField, $sortOrder)
            ->order('id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        // 分类名批量取
        $cids = array_values(array_unique(array_map(static fn (array $r): int => (int) $r['cid'], $rows)));
        $cnames = [];
        if (!empty($cids)) {
            $cnames = Db::name('tuangou_category')->where('aid', $aid)->whereIn('id', $cids)->column('name', 'id');
        }

        // 所属商户名批量取（bid=0 平台自营）
        $bids = array_values(array_filter(array_map(static fn (array $r): int => (int) ($r['bid'] ?? 0), $rows)));
        $bnames = [];
        if (!empty($bids)) {
            $bnames = Db::name('business')->where('aid', $aid)->whereIn('id', $bids)->column('name', 'id');
        }

        $list = array_map(function (array $r) use ($cnames, $bnames): array {
            $item = $this->formatProduct($r);
            $cid  = (int) ($r['cid'] ?? 0);
            $bid  = (int) ($r['bid'] ?? 0);
            $item['cname'] = 0 === $cid ? '' : (string) ($cnames[$cid] ?? '');
            $item['bname'] = 0 === $bid ? '平台自营' : (string) ($bnames[$bid] ?? '');
            $item['bid']   = $bid;
            return $item;
        }, $rows);

        return [
            'list'     => $list,
            'total'    => $total,
            'page'     => $page,
            'pagesize' => $pageSize,
        ];
    }

    /**
     * 商品详情
     *
     * @return array<string, mixed>
     */
    public function productDetail(int $aid, int $id): array
    {
        return $this->formatProduct($this->findProduct($aid, $id));
    }

    /**
     * 新增/编辑团购商品（id>0 编辑；pricedata 阶梯价校验：人数递增、价格递减�?
     *
     * @param array<string, mixed> $data
     * @return array{id: int}
     */
    public function productSave(int $aid, array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));

        if ('' === $name) {
            throw new BusinessException('请输入商品名称');
        }

        $sellPrice = (float) ($data['sell_price'] ?? 0);

        if ($sellPrice <= 0) {
            throw new BusinessException('初始价（起价）须大于0');
        }

        $pricedata = $this->normalizePricedata($data['pricedata'] ?? []);

        $starttime = $this->parseTime($data['starttime'] ?? '');
        $endtime   = $this->parseTime($data['endtime'] ?? '');

        if ($starttime > 0 && $endtime > 0 && $endtime <= $starttime) {
            throw new BusinessException('结束时间须晚于开始时间');
        }

        $cid = (int) ($data['cid'] ?? 0);

        if ($cid > 0) {
            $category = ScopeService::query('tuangou_category', $aid)->where('id', $cid)->find();

            if (empty($category)) {
                throw new BusinessException('所选分类不存在');
            }
        }

        $freighttype = (int) ($data['freighttype'] ?? 1);

        if (!in_array($freighttype, [0, 1, 2, 3, 4], true)) {
            throw new BusinessException('非法的配送方式');
        }

        $update = [
            'name'          => $name,
            'cid'           => $cid,
            'pic'           => trim((string) ($data['pic'] ?? '')),
            'pics'          => trim((string) ($data['pics'] ?? '')),
            'sellpoint'     => trim((string) ($data['sellpoint'] ?? '')),
            'fuwupoint'     => trim((string) ($data['fuwupoint'] ?? '')),
            'procode'       => trim((string) ($data['procode'] ?? '')),
            'sell_price'    => $sellPrice,
            'market_price'  => max(0, (float) ($data['market_price'] ?? 0)),
            'cost_price'    => max(0, (float) ($data['cost_price'] ?? 0)),
            'stock'         => max(0, (int) ($data['stock'] ?? 0)),
            'weight'        => max(0, (int) ($data['weight'] ?? 0)),
            'perlimit'      => max(0, (int) ($data['perlimit'] ?? 0)),
            'sort'          => (int) ($data['sort'] ?? 0),
            'status'        => (int) ($data['status'] ?? 1) === 1 ? 1 : 0,
            'detail'        => (string) ($data['detail'] ?? ''),
            'pricedata'     => json_encode($pricedata, JSON_UNESCAPED_UNICODE),
            'starttime'     => $starttime,
            'endtime'       => $endtime,
            'showtj'        => trim((string) ($data['showtj'] ?? '')),
            'gettj'         => trim((string) ($data['gettj'] ?? '')),
            'freighttype'   => $freighttype,
            'freightdata'   => trim((string) ($data['freightdata'] ?? '')),
            'scoredkmaxset' => (int) ($data['scoredkmaxset'] ?? 0) === 1 ? 1 : 0,
            'scoredkmaxval' => max(0, (float) ($data['scoredkmaxval'] ?? 0)),
            'contact_require' => (int) ($data['contact_require'] ?? 0) === 1 ? 1 : 0,
        ];

        $id = (int) ($data['id'] ?? 0);

        if ($id > 0) {
            $this->findProduct($aid, $id);

            ScopeService::query('tuangou_product', $aid)->where('id', $id)->update($update);
        } else {
            $update['aid']        = $aid;
            $update['bid']        = 0;
            $update['ischecked']  = 1;
            $update['createtime'] = time();

            $id = (int) ScopeService::query('tuangou_product', $aid)->insertGetId($update);
        }

        return ['id' => $id];
    }

    /**
     * 复制商品（名称加「复�?」前缀，下架待售状态）
     *
     * @return array{id: int}
     */
    public function productCopy(int $aid, int $id): array
    {
        $product = $this->findProduct($aid, $id);

        unset($product['id'], $product['sales'], $product['comment_score'], $product['comment_num'], $product['comment_haopercent']);

        $product['name']       = '复制-' . (string) $product['name'];
        $product['status']     = 0;
        $product['ischecked']  = 1;
        $product['createtime'] = time();
        $product['sales']      = 0;

        $newId = (int) Db::name('tuangou_product')->insertGetId($product);

        return ['id' => $newId];
    }

    /**
     * 商品上架/下架（支持批量）
     *
     * @param array<int, int> $ids
     */
    public function productStatus(int $aid, array $ids, int $status): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $v) => $v > 0));

        if (empty($ids)) {
            throw new BusinessException('缺少商品ID');
        }

        if (0 !== $status && 1 !== $status) {
            throw new BusinessException('非法的状态');
        }

        ScopeService::query('tuangou_product', $aid)->whereIn('id', $ids)->update(['status' => $status]);
    }

    /**
     * 商品审核（ischecked 1通过 2驳回；驳回须填原因）
     */
    public function productCheck(int $aid, int $id, int $ischecked, string $reason): void
    {
        $this->findProduct($aid, $id);

        if (1 !== $ischecked && 2 !== $ischecked) {
            throw new BusinessException('非法的审核结果');
        }

        if (2 === $ischecked && '' === trim($reason)) {
            throw new BusinessException('请填写驳回原因');
        }

        ScopeService::query('tuangou_product', $aid)->where('id', $id)->update([
            'ischecked'    => $ischecked,
            'check_reason' => 2 === $ischecked ? trim($reason) : '',
        ]);
    }

    /**
     * 删除商品（存在未关闭订单时拒绝）
     */
    public function productDelete(int $aid, array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) {
            throw new BusinessException('请选择要删除的商品');
        }

        foreach ($ids as $id) {
            $this->findProduct($aid, $id);

            $activeOrders = Db::name('tuangou_order')
                ->where('aid', $aid)
                ->where('proid', $id)
                ->whereIn('status', [self::ORDER_UNPAID, self::ORDER_PAID, self::ORDER_SHIPPED, self::ORDER_COLLECTED])
                ->count();

            if ($activeOrders > 0) {
                throw new BusinessException('该商品存在有效订单，暂不能删除');
            }
        }

        ScopeService::query('tuangou_product', $aid)->whereIn('id', $ids)->delete();
    }

    /**
     * 退差价（对标参考系统 tuichajia）：按当前已达成阶梯价对已支付订单逐单退差价
     *
     * @return array{money: float, total: float}
     */
    public function refundDiff(int $aid, int $id): array
    {
        $product = $this->findProduct($aid, $id);
        $pricedata = $this->normalizePricedata($product['pricedata'] ?? '');
        if (empty($pricedata)) {
            throw new BusinessException('该商品未设置阶梯价');
        }

        $buynum = (int) Db::name('tuangou_order')
            ->where('aid', $aid)->where('proid', $id)
            ->whereIn('status', [self::ORDER_PAID, self::ORDER_SHIPPED, self::ORDER_COLLECTED])
            ->sum('num');

        $now = ['num' => 0, 'money' => (float) ($product['sell_price'] ?? 0)];
        foreach ($pricedata as $tier) {
            if ($buynum >= (int) ($tier['num'] ?? PHP_INT_MAX)) {
                $now = ['num' => (int) ($tier['num'] ?? 0), 'money' => (float) ($tier['money'] ?? 0)];
            }
        }
        $nowPrice = (float) $now['money'];

        $orders = Db::name('tuangou_order')
            ->where('aid', $aid)->where('proid', $id)
            ->whereIn('status', [self::ORDER_PAID, self::ORDER_SHIPPED, self::ORDER_COLLECTED])
            ->select()->toArray();

        $total = 0.0;
        foreach ($orders as $order) {
            $num = (int) ($order['num'] ?? 0);
            $productPrice = (float) ($order['product_price'] ?? 0);
            $should = round($nowPrice * $num, 2);
            if ($productPrice > $should) {
                $tuimoney = round($productPrice - $should, 2);
                $this->payService->refund($order, $tuimoney, 'RC-DIFF-' . (string) $order['ordernum']);
                $this->markPayorderRefund($aid, (string) $order['ordernum'], $tuimoney);
                Db::name('tuangou_order')->where('id', $order['id'])->update(['product_price' => $should]);
                $total += $tuimoney;
            }
        }

        return ['money' => $nowPrice, 'total' => round($total, 2)];
    }

    /* ================= 团购订单 ================= */

    /**
     * 订单列表（status ''=全部 0-4 / r1-r3 退款态；keyword 订单�?商品�?联系�?手机号）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function orderList(int $aid, string $status, string $keyword, int $page, int $pageSize, string $bid = ''): array
    {
        $query = ScopeService::query('tuangou_order', $aid);

        ScopeService::applyBid($query, $bid, 'bid');

        $this->applyOrderStatusFilter($query, $status);

        if ('' !== trim($keyword)) {
            $like = '%' . trim($keyword) . '%';
            $query->where(function ($q) use ($like) {
                $q->whereOr('ordernum', 'like', $like)
                    ->whereOr('proname', 'like', $like)
                    ->whereOr('linkman', 'like', $like)
                    ->whereOr('tel', 'like', $like);
            });
        }

        $total = (clone $query)->count();

        $rows = $query->order('id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        return [
            'list'     => array_map(fn (array $r) => $this->formatOrder($aid, $r), $rows),
            'total'    => $total,
            'page'     => $page,
            'pagesize' => $pageSize,
        ];
    }

    /**
     * 订单详情
     *
     * @return array<string, mixed>
     */
    public function orderDetail(int $aid, int $orderId): array
    {
        return $this->formatOrder($aid, $this->findOrder($aid, $orderId));
    }

    /**
     * 订单改价（仅未支付；同步更新支付单金额）
     */
    public function orderChangePrice(int $aid, int $orderId, float $newPrice): void
    {
        $order = $this->findOrder($aid, $orderId);

        if (self::ORDER_UNPAID !== (int) $order['status']) {
            throw new BusinessException('仅未支付订单可改价');
        }

        if ($newPrice <= 0) {
            throw new BusinessException('订单金额须大于0');
        }

        $newPrice = round($newPrice, 2);

        ScopeService::query('tuangou_order', $aid)->where('id', $orderId)->update([
            'totalprice' => $newPrice,
        ]);

        Db::name('payorder')
            ->where('aid', $aid)
            ->where('type', 'tuangou')
            ->where('ordernum', (string) $order['ordernum'])
            ->where('status', 0)
            ->update(['money' => $newPrice]);
    }

    /**
     * 关闭订单（仅未支付；回补库存销量）
     */
    public function orderClose(int $aid, int $orderId): void
    {
        Db::transaction(function () use ($aid, $orderId) {
            $order = Db::name('tuangou_order')
                ->where('aid', $aid)
                ->where('id', $orderId)
                ->lock(true)
                ->find();

            if (empty($order)) {
                throw new BusinessException('订单不存在', 404, 404);
            }

            if (self::ORDER_UNPAID !== (int) $order['status']) {
                throw new BusinessException('仅未支付订单可关闭');
            }

            $this->restoreStock($order);

            Db::name('tuangou_order')->where('id', $orderId)->update(['status' => self::ORDER_CLOSED]);
        });
    }

    /**
     * 订单备注
     */
    public function orderRemark(int $aid, int $orderId, string $remark): void
    {
        $this->findOrder($aid, $orderId);

        ScopeService::query('tuangou_order', $aid)->where('id', $orderId)->update([
            'remark' => trim($remark),
        ]);
    }

    /**
     * 退款审核（pass=1 同意→原路退款同事务；pass=2 驳回�?
     */
    public function orderRefundCheck(int $aid, int $orderId, int $pass, string $remark): void
    {
        if (1 !== $pass && 2 !== $pass) {
            throw new BusinessException('非法的审核结果');
        }

        $order = $this->findOrder($aid, $orderId);

        if (1 !== (int) $order['refund_status']) {
            throw new BusinessException('该订单无待审核的退款申请');
        }

        if (2 === $pass) {
            ScopeService::query('tuangou_order', $aid)->where('id', $orderId)->update([
                'refund_status'      => 3,
                'refund_checkremark' => trim($remark),
                'refund_time'        => time(),
            ]);

            return;
        }

        if (!in_array((int) $order['status'], [self::ORDER_PAID, self::ORDER_SHIPPED], true)) {
            throw new BusinessException('该订单状态不允许退款');
        }

        Db::transaction(function () use ($aid, $orderId, $remark) {
            $locked = Db::name('tuangou_order')
                ->where('aid', $aid)
                ->where('id', $orderId)
                ->lock(true)
                ->find();

            if (empty($locked) || 1 !== (int) $locked['refund_status']
                || !in_array((int) $locked['status'], [self::ORDER_PAID, self::ORDER_SHIPPED], true)) {
                throw new BusinessException('订单状态已变化，请刷新后重试');
            }

            $refundMoney = (float) $locked['refund_money'] > 0
                ? (float) $locked['refund_money']
                : (float) $locked['totalprice'];

            // 原路退款（mock 即时成功；真实模式微�?V3 原路退款）
            $this->payService->refund($locked, $refundMoney, 'RC' . (string) $locked['ordernum']);

            // payorder 退款登记（type=tuangou 原支付单�?
            $this->markPayorderRefund($aid, (string) $locked['ordernum'], $refundMoney);

            // 退款回补库存销�?
            $this->restoreStock($locked);

            // 在线卡密商品退款：释放已绑定未使用的卡密（置回未售出）
            Db::name('tuangou_codelist')
                ->where('aid', $aid)
                ->where('orderid', $orderId)
                ->update(['status' => 0, 'ordernum' => '', 'orderid' => 0, 'nickname' => '', 'headimg' => '', 'buytime' => 0]);

            Db::name('tuangou_order')->where('id', $orderId)->update([
                'status'             => self::ORDER_CLOSED,
                'refund_status'      => 2,
                'refund_money'       => $refundMoney,
                'refund_time'        => time(),
                'refund_checkremark' => trim($remark),
            ]);
        });
    }

    /**
     * 快递发货（status=1 �?2；对标参考系统：活动未结束时不允许发货；已发货时仅更新物流）
     */
    public function orderSendExpress(int $aid, int $orderId, string $expressCom, string $expressNo): void
    {
        $expressCom = trim($expressCom);
        $expressNo  = trim($expressNo);

        if ('' === $expressCom || '' === $expressNo) {
            throw new BusinessException('请填写快递公司与快递单号');
        }

        $order = $this->findOrder($aid, $orderId);

        if (in_array((int) $order['freight_type'], [1, 3, 4], true)) {
            throw new BusinessException('该订单为到店核销/虚拟发货订单，不支持快递发货');
        }

        $product = Db::name('tuangou_product')->where('aid', $aid)->where('id', (int) $order['proid'])->find();

        if (!empty($product) && (int) $product['endtime'] > time()) {
            throw new BusinessException('团购活动未结束，暂不能发货');
        }

        if (self::ORDER_PAID === (int) $order['status']) {
            ScopeService::query('tuangou_order', $aid)->where('id', $orderId)->update([
                'express_com' => $expressCom,
                'express_no'  => $expressNo,
                'send_time'   => time(),
                'status'      => self::ORDER_SHIPPED,
            ]);

            return;
        }

        if (self::ORDER_SHIPPED === (int) $order['status']) {
            ScopeService::query('tuangou_order', $aid)->where('id', $orderId)->update([
                'express_com' => $expressCom,
                'express_no'  => $expressNo,
            ]);

            return;
        }

        throw new BusinessException('当前订单状态不可发货');
    }

    /**
     * 到店核销并确认收货（status in 1,2 �?freight_type=1 �?3；写 hexiao_order 记录�?
     */
    public function orderHexiao(int $aid, int $orderId): void
    {
        Db::transaction(function () use ($aid, $orderId) {
            $order = Db::name('tuangou_order')
                ->where('aid', $aid)
                ->where('id', $orderId)
                ->lock(true)
                ->find();

            if (empty($order)) {
                throw new BusinessException('订单不存在', 404, 404);
            }

            if (!in_array((int) $order['status'], [self::ORDER_PAID, self::ORDER_SHIPPED], true)
                || 1 !== (int) $order['freight_type']) {
                throw new BusinessException('订单状态不符合核销收货要求');
            }

            Db::name('hexiao_order')->insert([
                'aid'        => $aid,
                'bid'        => (int) ($order['bid'] ?? 0),
                'uid'        => 0,
                'mid'        => (string) ($order['mid'] ?? ''),
                'orderid'    => $orderId,
                'ordernum'   => (string) $order['ordernum'],
                'title'      => (string) ($order['proname'] ?? ''),
                'createtime' => time(),
                'remark'     => '团购到店核销',
                'type'       => 'tuangou',
                'mdid'       => (int) ($order['mdid'] ?? 0),
            ]);

            Db::name('tuangou_order')->where('id', $orderId)->update([
                'status'       => self::ORDER_COLLECTED,
                'collect_time' => time(),
            ]);
        });
    }

    /**
     * 订单导出行（�?ExcelHelper::download�?
     *
     * @return array<int, array<int, mixed>>
     */
    public function orderExportRows(int $aid, string $status, string $keyword): array
    {
        $query = ScopeService::query('tuangou_order', $aid);

        $this->applyOrderStatusFilter($query, $status);

        if ('' !== trim($keyword)) {
            $like = '%' . trim($keyword) . '%';
            $query->where(function ($q) use ($like) {
                $q->whereOr('ordernum', 'like', $like)
                    ->whereOr('proname', 'like', $like)
                    ->whereOr('linkman', 'like', $like)
                    ->whereOr('tel', 'like', $like);
            });
        }

        $rows = $query->order('id', 'desc')->select()->toArray();

        return array_map(function (array $v) use ($aid): array {
            $status  = (int) ($v['status'] ?? 0);
            $refund  = (int) ($v['refund'] ?? 0) ?: (int) ($v['refund_status'] ?? 0);
            $buyer   = $this->buyerOf($aid, $v);
            $freight = (int) ($v['freight_type'] ?? 0);

            return [
                (string) ($v['ordernum'] ?? ''),
                (string) ($buyer['nickname'] ?? ''),
                (string) ($v['proname'] ?? ''),
                (int) ($v['num'] ?? 0),
                (float) ($v['totalprice'] ?? 0),
                self::FREIGHT_TYPE_TEXT[$freight] ?? '快递发货',
                trim(((string) ($v['area'] ?? '')) . ' ' . ((string) ($v['address'] ?? ''))),
                (string) ($v['linkman'] ?? ''),
                (string) ($v['tel'] ?? ''),
                (string) ($v['express_com'] ?? ''),
                (string) ($v['express_no'] ?? ''),
                (string) ($v['message'] ?? ''),
                (string) ($v['remark'] ?? ''),
                (int) ($v['createtime'] ?? 0) > 0 ? date('Y-m-d H:i:s', (int) $v['createtime']) : '',
                self::ORDER_STATUS_TEXT[$status] ?? '未知',
                self::REFUND_TEXT[$refund] ?? '',
            ];
        }, $rows);
    }

    /* ================= 团购评价 ================= */

    /**
     * 评价列表（status ''=全部 0待审�?1显示 2驳回�?
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function commentList(int $aid, string $status, int $proid, int $page, int $pageSize): array
    {
        $query = ScopeService::query('tuangou_comment', $aid);

        if ('' !== $status) {
            $query->where('status', (int) $status);
        }

        if ($proid > 0) {
            $query->where('proid', $proid);
        }

        $total = (clone $query)->count();

        $rows = $query->order('id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        return [
            'list'     => array_map(fn (array $r) => $this->formatComment($r), $rows),
            'total'    => $total,
            'page'     => $page,
            'pagesize' => $pageSize,
        ];
    }

    /**
     * 评价审核（status 1显示 2驳回；审核后聚合回写商品评价统计�?
     */
    public function commentSetStatus(int $aid, int $id, int $status): void
    {
        if (1 !== $status && 2 !== $status) {
            throw new BusinessException('非法的状态');
        }

        $comment = ScopeService::query('tuangou_comment', $aid)->where('id', $id)->find();

        if (empty($comment)) {
            throw new BusinessException('评价不存在', 404, 404);
        }

        ScopeService::query('tuangou_comment', $aid)->where('id', $id)->update(['status' => $status]);

        $this->refreshCommentStat($aid, (int) ($comment['proid'] ?? 0));
    }

    /**
     * 评价回复
     */
    public function commentReply(int $aid, int $id, string $content): void
    {
        $content = trim($content);

        if ('' === $content) {
            throw new BusinessException('回复内容不能为空');
        }

        $comment = ScopeService::query('tuangou_comment', $aid)->where('id', $id)->find();

        if (empty($comment)) {
            throw new BusinessException('评价不存在', 404, 404);
        }

        ScopeService::query('tuangou_comment', $aid)->where('id', $id)->update([
            'reply_content' => $content,
            'reply_time'    => time(),
        ]);
    }

    /**
     * 删除评价（聚合回写商品评价统计）
     */
    public function commentDelete(int $aid, int $id): void
    {
        $comment = ScopeService::query('tuangou_comment', $aid)->where('id', $id)->find();

        if (empty($comment)) {
            throw new BusinessException('评价不存在', 404, 404);
        }

        ScopeService::query('tuangou_comment', $aid)->where('id', $id)->delete();

        $this->refreshCommentStat($aid, (int) ($comment['proid'] ?? 0));
    }

    /**
     * 商品评价统计聚合回写（comment_num/comment_score/comment_haopercent；好�?4星及以上�?
     */
    public function refreshCommentStat(int $aid, int $proid): void
    {
        if ($proid <= 0) {
            return;
        }

        $rows = Db::name('tuangou_comment')
            ->where('aid', $aid)
            ->where('proid', $proid)
            ->where('status', 1)
            ->field('score')
            ->select()
            ->toArray();

        $num = count($rows);

        if (0 === $num) {
            $update = ['comment_num' => 0, 'comment_score' => '5.0', 'comment_haopercent' => 100];
        } else {
            $total = 0;
            $good  = 0;

            foreach ($rows as $r) {
                $score = (int) ($r['score'] ?? 5);
                $total += $score;

                if ($score >= 4) {
                    ++$good;
                }
            }

            $update = [
                'comment_num'        => $num,
                'comment_score'      => (string) round($total / $num, 1),
                'comment_haopercent' => (int) round($good * 100 / $num),
            ];
        }

        ScopeService::query('tuangou_product', $aid)->where('id', $proid)->update($update);
    }

    /* ================= 团购卡密（tuangou_codelist�?================= */

    /**
     * 卡密分页列表
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function codelistList(int $aid, int $page, int $pagesize, int $proid, string $content, string $ordernum, string $nickname, string $status): array
    {
        $query = ScopeService::query('tuangou_codelist', $aid);

        if ($proid > 0) {
            $query->where('proid', $proid);
        }

        if ('' !== trim($content)) {
            $query->where('content', trim($content));
        }

        if ('' !== trim($ordernum)) {
            $query->where('ordernum', trim($ordernum));
        }

        if ('' !== trim($nickname)) {
            $query->where('nickname', 'like', '%' . trim($nickname) . '%');
        }

        if ('' !== $status) {
            $query->where('status', (int) $status);
        }

        $total = (clone $query)->count();

        $rows = $query->order('id', 'desc')->page($page, $pagesize)->select()->toArray();

        $list = array_map(static fn (array $row): array => [
            'id'          => (int) $row['id'],
            'proid'       => (int) ($row['proid'] ?? 0),
            'content'     => (string) ($row['content'] ?? ''),
            'ordernum'    => (string) ($row['ordernum'] ?? ''),
            'orderid'     => (int) ($row['orderid'] ?? 0),
            'nickname'    => (string) ($row['nickname'] ?? ''),
            'status'      => (int) ($row['status'] ?? 0),
            'create_time' => (int) ($row['createtime'] ?? 0) > 0 ? date('Y-m-d H:i:s', (int) $row['createtime']) : '',
            'buy_time'    => (int) ($row['buytime'] ?? 0) > 0 ? date('Y-m-d H:i:s', (int) $row['buytime']) : '',
        ], $rows);

        return ['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pagesize];
    }

    /**
     * Excel 导入卡密（首列即卡密内容，同商品下重复内容跳过）
     *
     * @return array{inserted: int, duplicated: int}
     */
    public function codelistImport(int $aid, int $proid, string $filePath): array
    {
        $this->findProduct($aid, $proid);

        $rows = ExcelHelper::readRows($filePath);

        if (empty($rows)) {
            throw new BusinessException('Excel 内容为空');
        }

        $exists = array_flip(array_map('strval', Db::name('tuangou_codelist')
            ->where('aid', $aid)
            ->where('proid', $proid)
            ->column('content')));

        $inserted   = 0;
        $duplicated = 0;
        $now        = time();
        $batch      = [];

        foreach ($rows as $ri => $data) {
            $content = trim((string) ($data[0] ?? ''));

            if (0 === $ri && ('' === $content || str_contains($content, '卡密') || str_contains($content, '内容'))) {
                continue;
            }

            if ('' === $content) {
                continue;
            }

            if (isset($exists[$content])) {
                ++$duplicated;
                continue;
            }

            $exists[$content] = true;
            $batch[]          = [
                'aid'        => $aid,
                'bid'        => 0,
                'proid'      => $proid,
                'content'    => $content,
                'createtime' => $now,
                'status'     => 0,
            ];
            ++$inserted;

            if (count($batch) >= 500) {
                Db::name('tuangou_codelist')->insertAll($batch);
                $batch = [];
            }
        }

        if (!empty($batch)) {
            Db::name('tuangou_codelist')->insertAll($batch);
        }

        return ['inserted' => $inserted, 'duplicated' => $duplicated];
    }

    /**
     * 批量生成卡密（codetype 1纯数�?2大小写字�?3小写 4大写 5数字+小写 6数字+大写�?
     *
     * @return array{created: int}
     */
    public function codelistMake(int $aid, int $proid, int $makeCount, int $codeLength, int $codeType): array
    {
        $this->findProduct($aid, $proid);

        if ($makeCount < 1 || $makeCount > 5000) {
            throw new BusinessException('每次生成数量须在5000以内');
        }
        if ($codeLength < 1) {
            throw new BusinessException('卡密长度须大于等于1');
        }

        $lengthLimit = [1 => 20, 2 => 52, 3 => 26, 4 => 26, 5 => 35, 6 => 35];

        if (!isset($lengthLimit[$codeType])) {
            throw new BusinessException('卡密类型取值1~6');
        }
        if ($codeLength > $lengthLimit[$codeType]) {
            throw new BusinessException('该类型卡密长度须小于等于' . $lengthLimit[$codeType]);
        }

        $exists = array_flip(array_map('strval', Db::name('tuangou_codelist')
            ->where('aid', $aid)
            ->where('proid', $proid)
            ->column('content')));

        $created = 0;
        $now     = time();
        $batch   = [];

        for ($i = 0; $i < $makeCount; ++$i) {
            $code = self::randomCode($codeType, $codeLength);

            if (isset($exists[$code])) {
                continue;
            }

            $exists[$code] = true;
            $batch[]       = [
                'aid'        => $aid,
                'bid'        => 0,
                'proid'      => $proid,
                'content'    => $code,
                'createtime' => $now,
                'status'     => 0,
            ];
            ++$created;

            if (count($batch) >= 500) {
                Db::name('tuangou_codelist')->insertAll($batch);
                $batch = [];
            }
        }

        if (!empty($batch)) {
            Db::name('tuangou_codelist')->insertAll($batch);
        }

        return ['created' => $created];
    }

    /**
     * 卡密导出行（�?ExcelHelper::download�?
     *
     * @return array<int, array<int, mixed>>
     */
    public function codelistExportRows(int $aid, int $proid): array
    {
        $query = ScopeService::query('tuangou_codelist', $aid);

        if ($proid > 0) {
            $query->where('proid', $proid);
        }

        $rows = $query->order('id', 'desc')->select()->toArray();

        return array_map(static fn (array $v): array => [
            (int) $v['id'],
            (string) ($v['content'] ?? ''),
            (string) ($v['ordernum'] ?? ''),
            (string) ($v['nickname'] ?? ''),
            (int) ($v['status'] ?? 0) === 1 && (int) ($v['buytime'] ?? 0) > 0 ? date('Y-m-d H:i:s', (int) $v['buytime']) : '',
            (int) ($v['status'] ?? 0) === 1 ? '已售出' : '未售出',
        ], $rows);
    }

    /**
     * 卡密批量删除（仅未售出可删）
     *
     * @param array<int, int> $ids
     * @return array{deleted: int}
     */
    public function codelistDelete(int $aid, array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $v) => $v > 0));

        if (empty($ids)) {
            throw new BusinessException('缺少要删除的卡密ID');
        }

        $deleted = Db::name('tuangou_codelist')
            ->where('aid', $aid)
            ->whereIn('id', $ids)
            ->where('status', 0)
            ->delete();

        return ['deleted' => (int) $deleted];
    }

    /* ================= C 端核心链�?================= */

    /**
     * C 端分类列表（启用状态）
     *
     * @return array<int, array<string, mixed>>
     */
    public function apiCategoryList(int $aid): array
    {
        $rows = ScopeService::query('tuangou_category', $aid)
            ->where('status', 1)
            ->order('sort', 'desc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        return array_map(static fn (array $r): array => [
            'id'   => (int) $r['id'],
            'pid'  => (int) ($r['pid'] ?? 0),
            'name' => (string) ($r['name'] ?? ''),
            'pic'  => (string) ($r['pic'] ?? ''),
        ], $rows);
    }

    /**
     * C 端商品列表（上架+审核通过+活动期内；附阶梯价进度）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function apiProductList(int $aid, int $cid, string $keyword, int $page, int $pageSize): array
    {
        $now   = time();
        $query = ScopeService::query('tuangou_product', $aid)
            ->where('status', 1)
            ->where('ischecked', 1)
            ->where(function ($q) use ($now) {
                $q->where('starttime', 0)->whereOr('starttime', '<=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->where('endtime', 0)->whereOr('endtime', '>=', $now);
            });

        if ($cid > 0) {
            $query->where('cid', $cid);
        }

        if ('' !== trim($keyword)) {
            $query->where('name', 'like', '%' . trim($keyword) . '%');
        }

        $total = (clone $query)->count();

        $rows = $query->order('sort', 'desc')
            ->order('id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        return [
            'list'     => array_map(fn (array $r) => $this->formatProductForApi($r), $rows),
            'total'    => $total,
            'page'     => $page,
            'pagesize' => $pageSize,
        ];
    }

    /**
     * C 端商品详情（阶梯价按人数展示�?
     *
     * @return array<string, mixed>
     */
    public function apiProductDetail(int $aid, int $id): array
    {
        $product = ScopeService::query('tuangou_product', $aid)->where('id', $id)->find();

        if (empty($product) || 1 !== (int) $product['status'] || 1 !== (int) $product['ischecked']) {
            throw new BusinessException('商品不存在或已下架', 404, 404);
        }

        $now   = time();
        $start = (int) ($product['starttime'] ?? 0);
        $end   = (int) ($product['endtime'] ?? 0);

        if (($start > 0 && $now < $start) || ($end > 0 && $now > $end)) {
            throw new BusinessException('团购活动不在有效期内');
        }

        $detail = $this->formatProductForApi($product);

        $detail['detail']          = (string) ($product['detail'] ?? '');
        $detail['pics']            = (string) ($product['pics'] ?? '');
        $detail['sellpoint']       = (string) ($product['sellpoint'] ?? '');
        $detail['fuwupoint']       = (string) ($product['fuwupoint'] ?? '');
        $detail['perlimit']        = (int) ($product['perlimit'] ?? 0);
        $detail['freighttype']     = (int) ($product['freighttype'] ?? 1);
        $detail['contact_require'] = (int) ($product['contact_require'] ?? 0);

        // 最新评价（�?条）
        $detail['comments'] = array_map(fn (array $r) => $this->formatComment($r), Db::name('tuangou_comment')
            ->where('aid', $aid)
            ->where('proid', $id)
            ->where('status', 1)
            ->order('id', 'desc')
            ->limit(3)
            ->select()
            ->toArray());

        return $detail;
    }

    /**
     * C 端下单（阶梯价当前价计价；限�?活动期校验；条件扣减防超卖；生成核销码）
     *
     * @param array<string, mixed> $extra linkman/tel/area/address/message/freight_type/mdid/freight_price/freight_text/platform
     * @return array{orderid: int, ordernum: string, totalprice: float, status: int}
     */
    public function apiCreateOrder(int $aid, int $mid, int $proid, int $num, array $extra = []): array
    {
        $num = max(1, $num);

        return Db::transaction(function () use ($aid, $mid, $proid, $num, $extra) {
            $now     = time();
            $product = Db::name('tuangou_product')
                ->where('aid', $aid)->where('id', $proid)
                ->where('status', 1)->where('ischecked', 1)
                ->lock(true)->find();

            if (empty($product)) {
                throw new BusinessException('团购商品不存在或已下架');
            }

            // 活动期校�?
            if ((int) $product['starttime'] > 0 && $now < (int) $product['starttime']) {
                throw new BusinessException('团购活动尚未开始');
            }

            if ((int) $product['endtime'] > 0 && $now > (int) $product['endtime']) {
                throw new BusinessException('团购活动已结束');
            }

            // 限购校验（perlimit>0：有效单累计 + 本次 �?perlimit�?
            $perlimit = (int) ($product['perlimit'] ?? 0);

            if ($perlimit > 0) {
                $bought = (int) Db::name('tuangou_order')
                    ->where('aid', $aid)->where('mid', $mid)->where('proid', $proid)
                    ->whereIn('status', [self::ORDER_UNPAID, self::ORDER_PAID, self::ORDER_SHIPPED, self::ORDER_COLLECTED])
                    ->sum('num');

                if ($bought + $num > $perlimit) {
                    throw new BusinessException('超出限购数量（每人限 ' . $perlimit . ' 件）');
                }
            }

            // 库存扣减（条件更新防超卖�?
            $affected = Db::name('tuangou_product')
                ->where('aid', $aid)->where('id', $proid)->where('stock', '>=', $num)
                ->dec('stock', $num)->inc('sales', $num)->update();

            if ($affected <= 0) {
                throw new BusinessException('库存不足，已被抢光了');
            }

            // 阶梯价当前价计价
            $priceInfo  = $this->priceInfo($product);
            $unitPrice  = $priceInfo['nowprice'];
            $freightFee = round(max(0, (float) ($extra['freight_price'] ?? 0)), 2);
            $totalPrice = round($unitPrice * $num + $freightFee, 2);
            $ordernum   = date('ymdHis') . $aid . random_int(1000, 9999);

            $freightType = (int) ($extra['freight_type'] ?? 0);

            // 自动发货/在线卡密商品强制虚拟发货
            if (in_array((int) ($product['freighttype'] ?? 1), [3, 4], true)) {
                $freightType = (int) $product['freighttype'];
            }

            if (1 === (int) ($product['contact_require'] ?? 0)
                && in_array($freightType, [3, 4], true)
                && ('' === trim((string) ($extra['linkman'] ?? '')) || '' === trim((string) ($extra['tel'] ?? '')))) {
                throw new BusinessException('请填写联系人和联系电话');
            }

            $freightText = trim((string) ($extra['freight_text'] ?? ''));

            if ('' === $freightText) {
                $freightText = 1 === $freightType ? '到店核销' : (in_array($freightType, [3, 4], true) ? (3 === $freightType ? '自动发货' : '在线卡密') : '包邮');
            }

            $orderId = (int) Db::name('tuangou_order')->insertGetId([
                'aid'           => $aid,
                'bid'           => (int) ($product['bid'] ?? 0),
                'mid'           => $mid,
                'ordernum'      => $ordernum,
                'buytype'       => 1,
                'teamid'        => 0,
                'title'         => (string) $product['name'],
                'proid'         => $proid,
                'proname'       => (string) $product['name'],
                'propic'        => (string) ($product['pic'] ?? ''),
                'ggid'          => 0,
                'ggname'        => '',
                'num'           => $num,
                'cost_price'    => (float) ($product['cost_price'] ?? 0),
                'sell_price'    => $unitPrice,
                'totalprice'    => $totalPrice,
                'product_price' => round($unitPrice * $num, 2),
                'freight_price' => $freightFee,
                'createtime'    => $now,
                'status'        => self::ORDER_UNPAID,
                'linkman'       => trim((string) ($extra['linkman'] ?? '')),
                'tel'           => trim((string) ($extra['tel'] ?? '')),
                'area'          => trim((string) ($extra['area'] ?? '')),
                'address'       => trim((string) ($extra['address'] ?? '')),
                'message'       => trim((string) ($extra['message'] ?? '')),
                'freight_text'  => $freightText,
                'freight_type'  => $freightType,
                'mdid'          => max(0, (int) ($extra['mdid'] ?? 0)),
                'hexiao_code'   => strtoupper(bin2hex(random_bytes(8))),
                'platform'      => (string) ($extra['platform'] ?? 'wx'),
                'delete'        => 0,
            ]);

            // 支付单（type=tuangou�?
            Db::name('payorder')->insert([
                'aid'        => $aid,
                'bid'        => (int) ($product['bid'] ?? 0),
                'mid'        => $mid,
                'orderid'    => $orderId,
                'ordernum'   => $ordernum,
                'title'      => (string) $product['name'],
                'money'      => $totalPrice,
                'score'      => 0,
                'type'       => 'tuangou',
                'createtime' => $now,
                'status'     => 0,
                'platform'   => (string) ($extra['platform'] ?? 'wx'),
            ]);

            // mock 模式即时支付成功（真实模式由支付回调�?payTuangouOrder�?
            if (PayService::isMock()) {
                $this->payTuangouOrder($aid, $orderId, 'wechat', 'MOCK' . time() . random_int(1000, 9999));
            }

            return [
                'orderid'    => $orderId,
                'ordernum'   => $ordernum,
                'totalprice' => $totalPrice,
                'status'     => PayService::isMock() ? self::ORDER_PAID : self::ORDER_UNPAID,
            ];
        });
    }

    /**
     * 团购订单支付成功入账（幂等：�?status=0 �?1；在线卡密商品自动发卡并完成�?
     */
    public function payTuangouOrder(int $aid, int $orderId, string $payType, string $payNum): bool
    {
        $order = ScopeService::query('tuangou_order', $aid)->where('id', $orderId)->find();

        if (empty($order) || self::ORDER_UNPAID !== (int) $order['status']) {
            return false;
        }

        $affected = Db::name('tuangou_order')
            ->where('aid', $aid)->where('id', $orderId)->where('status', self::ORDER_UNPAID)
            ->update([
                'status'    => self::ORDER_PAID,
                'paytype'   => $payType,
                'paytypeid' => PayService::PAY_TYPE_IDS[$payType] ?? 0,
                'paynum'    => $payNum,
                'paytime'   => time(),
            ]);

        if ($affected <= 0) {
            return false;
        }

        Db::name('payorder')
            ->where('aid', $aid)->where('type', 'tuangou')->where('ordernum', (string) $order['ordernum'])
            ->where('status', 0)
            ->update([
                'status'    => 1,
                'paytype'   => $payType,
                'paytypeid' => PayService::PAY_TYPE_IDS[$payType] ?? 0,
                'paynum'    => $payNum,
                'paytime'   => time(),
            ]);

        // 在线卡密商品：自动从卡密池发放（按购买数量逐条绑定）并自动完成
        $product = Db::name('tuangou_product')->where('aid', $aid)->where('id', (int) $order['proid'])->find();

        if (!empty($product) && 4 === (int) ($product['freighttype'] ?? 0)) {
            $this->assignCodelist($aid, (int) $order['proid'], $orderId, (string) $order['ordernum'], (int) $order['mid'], (int) ($order['num'] ?? 1));

            Db::name('tuangou_order')->where('id', $orderId)->update([
                'status'       => self::ORDER_COLLECTED,
                'collect_time' => time(),
            ]);
        }

        return true;
    }

    /**
     * 我的团购订单（st ''=全部 0-4 / r 退款相关）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function apiMyOrders(int $aid, int $mid, string $st, int $page, int $pageSize): array
    {
        $query = ScopeService::query('tuangou_order', $aid)
            ->where('mid', $mid)
            ->where('delete', 0);

        if ('' !== $st) {
            if ('r' === $st) {
                $query->whereIn('refund_status', [1, 2, 3]);
            } else {
                $query->where('status', (int) $st);
            }
        }

        $total = (clone $query)->count();

        $rows = $query->order('id', 'desc')->page($page, $pageSize)->select()->toArray();

        return [
            'list'     => array_map(fn (array $r) => $this->formatOrder($aid, $r), $rows),
            'total'    => $total,
            'page'     => $page,
            'pagesize' => $pageSize,
        ];
    }

    /**
     * C 端订单详情（本人校验；含核销码与已发卡密�?
     *
     * @return array<string, mixed>
     */
    public function apiOrderDetail(int $aid, int $mid, int $orderId): array
    {
        $order = ScopeService::query('tuangou_order', $aid)
            ->where('id', $orderId)
            ->where('mid', $mid)
            ->find();

        if (empty($order)) {
            throw new BusinessException('订单不存在', 404, 404);
        }

        $detail = $this->formatOrder($aid, $order);

        // 到店核销单展示核销码（已支付及以后有效�?
        $detail['hexiao_code'] = in_array($detail['status'], [1, 2, 3], true) && 1 === (int) ($order['freight_type'] ?? 0)
            ? (string) ($order['hexiao_code'] ?? '')
            : '';

        // 在线卡密单展示已发放卡密
        $detail['codes'] = array_values(Db::name('tuangou_codelist')
            ->where('aid', $aid)
            ->where('orderid', $orderId)
            ->column('content'));

        return $detail;
    }

    /**
     * C 端申请退款（status in 1,2 且未退�?�?refund_status=1 待审核）
     */
    public function apiOrderRefund(int $aid, int $mid, int $orderId, string $reason): void
    {
        $reason = trim($reason);

        if ('' === $reason) {
            throw new BusinessException('请填写退款原因');
        }

        $order = ScopeService::query('tuangou_order', $aid)
            ->where('id', $orderId)
            ->where('mid', $mid)
            ->find();

        if (empty($order)) {
            throw new BusinessException('订单不存在', 404, 404);
        }

        if (!in_array((int) $order['status'], [self::ORDER_PAID, self::ORDER_SHIPPED], true)) {
            throw new BusinessException('当前订单状态不允许申请退款');
        }

        if (2 === (int) $order['refund_status']) {
            throw new BusinessException('该订单已退款');
        }

        if (1 === (int) $order['refund_status']) {
            throw new BusinessException('退款申请审核中，请勿重复提交');
        }

        ScopeService::query('tuangou_order', $aid)->where('id', $orderId)->update([
            'refund_status' => 1,
            'refund_reason' => $reason,
            'refund_money'  => (float) $order['totalprice'],
        ]);
    }

    /**
     * C 端删除订单（仅已完成/已关闭可删，�?delete=1�?
     */
    public function apiOrderDelete(int $aid, int $mid, int $orderId): void
    {
        $order = ScopeService::query('tuangou_order', $aid)
            ->where('id', $orderId)
            ->where('mid', $mid)
            ->find();

        if (empty($order)) {
            throw new BusinessException('订单不存在', 404, 404);
        }

        if (!in_array((int) $order['status'], [self::ORDER_COLLECTED, self::ORDER_CLOSED], true)) {
            throw new BusinessException('当前订单状态不允许删除');
        }

        ScopeService::query('tuangou_order', $aid)->where('id', $orderId)->update(['delete' => 1]);
    }

    /**
     * C 端确认收货（已发�?�?已收货）
     */
    public function apiOrderCollect(int $aid, int $mid, int $orderId): void
    {
        $order = ScopeService::query('tuangou_order', $aid)
            ->where('id', $orderId)
            ->where('mid', $mid)
            ->find();

        if (empty($order)) {
            throw new BusinessException('订单不存在', 404, 404);
        }

        if (self::ORDER_SHIPPED !== (int) $order['status']) {
            throw new BusinessException('当前订单状态不允许确认收货');
        }

        ScopeService::query('tuangou_order', $aid)->where('id', $orderId)->update([
            'status'       => self::ORDER_COLLECTED,
            'collect_time' => time(),
        ]);
    }

    /**
     * C 端提交评价（sysset.comment_check=1 时进待审�?status=0，否则直接显示并聚合回写�?
     *
     * @return array{id: int}
     */
    public function apiCommentCreate(int $aid, int $mid, int $orderId, int $score, string $content, string $contentPic): array
    {
        if ($score < 1 || $score > 5) {
            throw new BusinessException('评分须在 1-5 分');
        }

        $order = ScopeService::query('tuangou_order', $aid)
            ->where('id', $orderId)
            ->where('mid', $mid)
            ->find();

        if (empty($order)) {
            throw new BusinessException('订单不存在', 404, 404);
        }

        $sysset = $this->sysset($aid);

        if (0 === (int) ($sysset['comment'] ?? 1)) {
            throw new BusinessException('该商城暂未开启评价');
        }

        if (!in_array((int) $order['status'], [self::ORDER_PAID, self::ORDER_SHIPPED, self::ORDER_COLLECTED], true)) {
            throw new BusinessException('当前订单状态不允许评价');
        }

        if (1 === (int) ($order['iscomment'] ?? 0)) {
            throw new BusinessException('该订单已评价');
        }

        $member = Db::name('member')->where('aid', $aid)->where('id', $mid)->field('nickname, headimg')->find();

        $status = 1 === (int) ($sysset['comment_check'] ?? 1) ? 0 : 1;

        $commentId = (int) Db::name('tuangou_comment')->insertGetId([
            'aid'         => $aid,
            'bid'         => (int) ($order['bid'] ?? 0),
            'mid'         => $mid,
            'orderid'     => $orderId,
            'proid'       => (int) ($order['proid'] ?? 0),
            'proname'     => (string) ($order['proname'] ?? ''),
            'propic'      => (string) ($order['propic'] ?? ''),
            'ggid'        => (int) ($order['ggid'] ?? 0),
            'ggname'      => (string) ($order['ggname'] ?? ''),
            'ordernum'    => (string) ($order['ordernum'] ?? ''),
            'nickname'    => (string) ($member['nickname'] ?? ''),
            'headimg'     => (string) ($member['headimg'] ?? ''),
            'score'       => $score,
            'content'     => trim($content),
            'content_pic' => trim($contentPic),
            'createtime'  => time(),
            'status'      => $status,
        ]);

        Db::name('tuangou_order')->where('id', $orderId)->update(['iscomment' => 1]);

        if (1 === $status) {
            $this->refreshCommentStat($aid, (int) ($order['proid'] ?? 0));
        }

        return ['id' => $commentId];
    }

    /* ================= 内部工具 ================= */

    /**
     * 阶梯价整理（pricedata：人数依次递增、价格依次递减�?
     *
     * @param mixed $raw
     * @return array<int, array{num: int, money: float}>
     */
    protected function normalizePricedata($raw): array
    {
        if (is_string($raw) && '' !== trim($raw)) {
            $raw = json_decode($raw, true);
        }

        if (!is_array($raw)) {
            $raw = [];
        }

        $list = [];

        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }

            $num   = (int) ($row['num'] ?? 0);
            $money = (float) ($row['money'] ?? 0);

            if ($num <= 0 || $money <= 0) {
                continue;
            }

            $list[] = ['num' => $num, 'money' => round($money, 2)];
        }

        foreach ($list as $k => $row) {
            if ($k > 0) {
                if ($row['num'] <= $list[$k - 1]['num']) {
                    throw new BusinessException('团购价阶梯人数须依次递增');
                }

                if ($row['money'] >= $list[$k - 1]['money']) {
                    throw new BusinessException('团购价阶梯价格须依次递减');
                }
            }
        }

        return $list;
    }

    /**
     * 时间入参解析（时间戳�?Y-m-d H:i:s 字符串；�?0 不限�?
     *
     * @param mixed $value
     */
    protected function parseTime($value): int
    {
        if (is_numeric($value)) {
            return max(0, (int) $value);
        }

        $value = trim((string) $value);

        if ('' === $value) {
            return 0;
        }

        $ts = strtotime($value);

        return false === $ts ? 0 : (int) $ts;
    }

    /**
     * 阶梯价进度计算（对标参考系统：已购人数匹配最高达成档位）
     *
     * @param array<string, mixed> $product
     * @return array{buynum: int, nowprice: float, nownum: int, maxprice: float, minprice: float}
     */
    protected function priceInfo(array $product): array
    {
        $buynum = (int) Db::name('tuangou_order')
            ->where('aid', (int) ($product['aid'] ?? 0))
            ->where('proid', (int) ($product['id'] ?? 0))
            ->whereIn('status', [self::ORDER_PAID, self::ORDER_SHIPPED, self::ORDER_COLLECTED])
            ->sum('num');

        $pricedata = json_decode((string) ($product['pricedata'] ?? ''), true);

        if (!is_array($pricedata)) {
            $pricedata = [];
        }

        $maxPrice = (float) ($product['sell_price'] ?? 0);
        $now      = ['num' => 0, 'money' => $maxPrice];

        foreach ($pricedata as $tier) {
            if ($buynum >= (int) ($tier['num'] ?? PHP_INT_MAX)) {
                $now = ['num' => (int) ($tier['num'] ?? 0), 'money' => (float) ($tier['money'] ?? $maxPrice)];
            }
        }

        $minPrice = $maxPrice;

        if (!empty($pricedata)) {
            $last     = end($pricedata);
            $minPrice = (float) ($last['money'] ?? $maxPrice);
        }

        return [
            'buynum'   => $buynum,
            'nowprice' => $now['money'],
            'nownum'   => $now['num'],
            'maxprice' => $maxPrice,
            'minprice' => $minPrice,
        ];
    }

    /**
     * 在线卡密发放（按数量逐条从未售出池绑定到订单；池不足时抛异常回滚�?
     */
    protected function assignCodelist(int $aid, int $proid, int $orderId, string $ordernum, int $mid, int $num): void
    {
        $member = Db::name('member')->where('aid', $aid)->where('id', $mid)->field('nickname, headimg')->find();

        $codes = Db::name('tuangou_codelist')
            ->where('aid', $aid)
            ->where('proid', $proid)
            ->where('status', 0)
            ->lock(true)
            ->limit($num)
            ->select()
            ->toArray();

        if (count($codes) < $num) {
            throw new BusinessException('卡密库存不足，请联系商家');
        }

        foreach ($codes as $code) {
            Db::name('tuangou_codelist')->where('id', (int) $code['id'])->update([
                'status'   => 1,
                'ordernum' => $ordernum,
                'orderid'  => $orderId,
                'nickname' => (string) ($member['nickname'] ?? ''),
                'headimg'  => (string) ($member['headimg'] ?? ''),
                'buytime'  => time(),
            ]);
        }
    }

    /**
     * 退�?关单回补库存销�?
     *
     * @param array<string, mixed> $order
     */
    protected function restoreStock(array $order): void
    {
        $num = (int) ($order['num'] ?? 0);

        if ($num <= 0) {
            return;
        }

        $proid = (int) ($order['proid'] ?? 0);

        if ($proid > 0) {
            Db::name('tuangou_product')->where('id', $proid)->inc('stock', $num)->dec('sales', $num)->update();
        }
    }

    /**
     * payorder 退款登记（type=tuangou 原支付单�?
     */
    protected function markPayorderRefund(int $aid, string $ordernum, float $refundMoney): void
    {
        if ('' === $ordernum || $refundMoney <= 0) {
            return;
        }

        Db::name('payorder')
            ->where('aid', $aid)
            ->where('type', 'tuangou')
            ->where('ordernum', $ordernum)
            ->where('status', 1)
            ->order('id', 'desc')
            ->limit(1)
            ->update([
                'refund_money' => $refundMoney,
                'refund_time'  => time(),
            ]);
    }

    /**
     * 订单状态筛选（''=全部�?-4 状态；r1/r2/r3 �?refund_status�?
     *
     * @param mixed $query
     */
    protected function applyOrderStatusFilter($query, string $status): void
    {
        if ('' === $status) {
            return;
        }

        if (str_starts_with($status, 'r')) {
            $refund = (int) substr($status, 1);

            if (!in_array($refund, [1, 2, 3], true)) {
                throw new BusinessException('非法的退款状态');
            }

            $query->where('refund_status', $refund);

            return;
        }

        $query->where('status', (int) $status);
    }

    /**
     * @return array<string, mixed>
     */
    protected function findProduct(int $aid, int $id): array
    {
        if ($id <= 0) {
            throw new BusinessException('缺少商品ID');
        }

        $product = ScopeService::query('tuangou_product', $aid)->where('id', $id)->find();

        if (empty($product)) {
            throw new BusinessException('商品不存在', 404, 404);
        }

        return $product;
    }

    /**
     * @return array<string, mixed>
     */
    protected function findOrder(int $aid, int $orderId): array
    {
        if ($orderId <= 0) {
            throw new BusinessException('缺少订单ID');
        }

        $order = ScopeService::query('tuangou_order', $aid)->where('id', $orderId)->find();

        if (empty($order)) {
            throw new BusinessException('订单不存在', 404, 404);
        }

        return $order;
    }

    /**
     * 后台商品口径（含 C 端字�?+ 管理字段�?
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function formatProduct(array $row): array
    {
        $detail = $this->formatProductForApi($row);

        $detail['detail']            = (string) ($row['detail'] ?? '');
        $detail['pics']              = (string) ($row['pics'] ?? '');
        $detail['fuwupoint']         = (string) ($row['fuwupoint'] ?? '');
        $detail['procode']           = (string) ($row['procode'] ?? '');
        $detail['cost_price']        = (float) ($row['cost_price'] ?? 0);
        $detail['weight']            = (int) ($row['weight'] ?? 0);
        $detail['showtj']            = (string) ($row['showtj'] ?? '');
        $detail['gettj']             = (string) ($row['gettj'] ?? '');
        $detail['freighttype']       = (int) ($row['freighttype'] ?? 1);
        $detail['freightdata']       = (string) ($row['freightdata'] ?? '');
        $detail['scoredkmaxset']     = (int) ($row['scoredkmaxset'] ?? 0);
        $detail['scoredkmaxval']     = (float) ($row['scoredkmaxval'] ?? 0);
        $detail['contact_require']   = (int) ($row['contact_require'] ?? 0);
        $detail['ischecked']         = (int) ($row['ischecked'] ?? 1);
        $detail['check_reason']      = (string) ($row['check_reason'] ?? '');
        $detail['comment_score']     = (float) ($row['comment_score'] ?? 5);
        $detail['comment_num']       = (int) ($row['comment_num'] ?? 0);
        $detail['comment_haopercent'] = (int) ($row['comment_haopercent'] ?? 100);

        return $detail;
    }

    /**
     * C 端商品口径（含阶梯价进度�?
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function formatProductForApi(array $row): array
    {
        $priceInfo = $this->priceInfo($row);
        $pricedata = json_decode((string) ($row['pricedata'] ?? ''), true);

        if (!is_array($pricedata)) {
            $pricedata = [];
        }

        return [
            'id'           => (int) ($row['id'] ?? 0),
            'cid'          => (int) ($row['cid'] ?? 0),
            'name'         => (string) ($row['name'] ?? ''),
            'pic'          => (string) ($row['pic'] ?? ''),
            'sellpoint'    => (string) ($row['sellpoint'] ?? ''),
            'market_price' => (float) ($row['market_price'] ?? 0),
            'sell_price'   => $priceInfo['maxprice'],
            'nowprice'     => $priceInfo['nowprice'],
            'minprice'     => $priceInfo['minprice'],
            'buynum'       => $priceInfo['buynum'],
            'pricedata'    => array_map(static fn (array $t): array => [
                'num'   => (int) ($t['num'] ?? 0),
                'money' => (float) ($t['money'] ?? 0),
            ], $pricedata),
            'stock'        => (int) ($row['stock'] ?? 0),
            'sales'        => (int) ($row['sales'] ?? 0),
            'perlimit'     => (int) ($row['perlimit'] ?? 0),
            'starttime'    => (int) ($row['starttime'] ?? 0),
            'endtime'      => (int) ($row['endtime'] ?? 0),
            'sort'         => (int) ($row['sort'] ?? 0),
            'status'       => (int) ($row['status'] ?? 0),
            'createtime'   => (int) ($row['createtime'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function formatOrder(int $aid, array $row): array
    {
        $status  = (int) ($row['status'] ?? 0);
        $refund  = (int) ($row['refund_status'] ?? 0);
        $freight = (int) ($row['freight_type'] ?? 0);

        return [
            'id'                => (int) ($row['id'] ?? 0),
            'ordernum'          => (string) ($row['ordernum'] ?? ''),
            'proid'             => (int) ($row['proid'] ?? 0),
            'proname'           => (string) ($row['proname'] ?? ''),
            'propic'            => (string) ($row['propic'] ?? ''),
            'num'               => (int) ($row['num'] ?? 0),
            'sell_price'        => (float) ($row['sell_price'] ?? 0),
            'totalprice'        => (float) ($row['totalprice'] ?? 0),
            'product_price'     => (float) ($row['product_price'] ?? 0),
            'freight_price'     => (float) ($row['freight_price'] ?? 0),
            'freight_type'      => $freight,
            'freight_text'      => (string) ($row['freight_text'] ?? ''),
            'freight_type_text' => self::FREIGHT_TYPE_TEXT[$freight] ?? '快递发货',
            'refund_money'      => (float) ($row['refund_money'] ?? 0),
            'refund_reason'     => (string) ($row['refund_reason'] ?? ''),
            'status'            => $status,
            'status_text'       => self::ORDER_STATUS_TEXT[$status] ?? '未知',
            'refund_status'     => $refund,
            'refund_text'       => self::REFUND_TEXT[$refund] ?? '',
            'buyer'             => $this->buyerOf($aid, $row),
            'linkman'           => (string) ($row['linkman'] ?? ''),
            'tel'               => (string) ($row['tel'] ?? ''),
            'address'           => trim(((string) ($row['area'] ?? '')) . ' ' . ((string) ($row['address'] ?? ''))),
            'message'           => (string) ($row['message'] ?? ''),
            'remark'            => (string) ($row['remark'] ?? ''),
            'express_com'       => (string) ($row['express_com'] ?? ''),
            'express_no'        => (string) ($row['express_no'] ?? ''),
            'hexiao_code'       => (string) ($row['hexiao_code'] ?? ''),
            'iscomment'         => (int) ($row['iscomment'] ?? 0),
            'paytype'           => (string) ($row['paytype'] ?? ''),
            'paytime'           => (int) ($row['paytime'] ?? 0),
            'send_time'         => (int) ($row['send_time'] ?? 0),
            'collect_time'      => (int) ($row['collect_time'] ?? 0),
            'createtime'        => (int) ($row['createtime'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function formatComment(array $row): array
    {
        $status = (int) ($row['status'] ?? 1);

        return [
            'id'            => (int) ($row['id'] ?? 0),
            'mid'           => (int) ($row['mid'] ?? 0),
            'proid'         => (int) ($row['proid'] ?? 0),
            'proname'       => (string) ($row['proname'] ?? ''),
            'propic'        => (string) ($row['propic'] ?? ''),
            'ordernum'      => (string) ($row['ordernum'] ?? ''),
            'nickname'      => (string) ($row['nickname'] ?? ''),
            'headimg'       => (string) ($row['headimg'] ?? ''),
            'score'         => (int) ($row['score'] ?? 5),
            'content'       => (string) ($row['content'] ?? ''),
            'content_pic'   => (string) ($row['content_pic'] ?? ''),
            'reply_content' => (string) ($row['reply_content'] ?? ''),
            'reply_time'    => (int) ($row['reply_time'] ?? 0),
            'status'        => $status,
            'status_text'   => [0 => '待审核', 1 => '已显示', 2 => '已驳回'][$status] ?? '未知',
            'createtime'    => (int) ($row['createtime'] ?? 0),
        ];
    }

    /**
     * 下单人信�?
     *
     * @param array<string, mixed> $order
     * @return array{mid: int, nickname: string, headimg: string}
     */
    protected function buyerOf(int $aid, array $order): array
    {
        $member = Db::name('member')
            ->where('aid', $aid)
            ->where('id', (int) ($order['mid'] ?? 0))
            ->field('id, nickname, headimg, tel')
            ->find();

        return [
            'mid'      => (int) ($order['mid'] ?? 0),
            'nickname' => (string) ($member['nickname'] ?? ''),
            'headimg'  => (string) ($member['headimg'] ?? ''),
        ];
    }

    /**
     * 随机卡密串生成（对标参考系�?make_rand_code�?
     */
    protected static function randomCode(int $type, int $length): string
    {
        $pools = [
            1 => '0123456789',
            2 => 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ',
            3 => 'abcdefghijklmnopqrstuvwxyz',
            4 => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
            5 => '0123456789abcdefghijklmnopqrstuvwxyz',
            6 => '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ',
        ];

        $pool = $pools[$type] ?? $pools[5];
        $max  = strlen($pool) - 1;
        $code = '';

        for ($i = 0; $i < $length; ++$i) {
            $code .= $pool[random_int(0, $max)];
        }

        return $code;
    }
}
