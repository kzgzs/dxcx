<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;

/**
 * 积分商城服务（admin 后台 + 定时任务共用）
 *
 * 表：scoreshop_sysset（关单/收货/人群设置）、scoreshop_category（分类）、
 *     scoreshop_product + scoreshop_guige（积分商品：纯积分或积分+钱）、
 *     scoreshop_order + scoreshop_order_goods（兑换订单：0未付 1已付 2已发货 3已收货 4关闭）
 *
 * 防超卖口径：库存扣减一律条件更新 where stock >= num；
 * 关单/超时关单同事务回补商品与规格库存、销量回退。
 */
class ScoreshopService
{
    /** 订单状态机 */
    public const STATUS_UNPAID   = 0;
    public const STATUS_PAID     = 1;
    public const STATUS_SHIPPED  = 2;
    public const STATUS_FINISHED = 3;
    public const STATUS_CLOSED   = 4;

    /** 退款状态文案 */
    public const REFUND_TEXT = [0 => '', 1 => '已退款', 2 => '退款中', 3 => '拒绝退款'];

    /** 定时任务单轮扫描上限 */
    protected const SCAN_LIMIT = 500;

    public function __construct(
        protected PayService $payService,
        protected ScoreService $scoreService
    ) {
    }

    /* ================= 系统设置 ================= */

    /**
     * 积分商城设置（无记录给默认值）
     *
     * @return array<string, mixed>
     */
    public function getSysset(int $aid): array
    {
        $row = ScopeService::query('scoreshop_sysset', $aid)->find();

        $default = [
            'autoshdays'     => 7,
            'showjd'         => 1,
            'autoclose'      => 60,
            'comment'        => 1,
            'comment_check'  => 1,
            'gettj'          => '-1',
            'gettjtip'       => '您没有权限进入',
            'gettjurl'       => '',
            'showcommission' => 1,
        ];

        $data = $row ? array_merge($default, array_intersect_key($row, $default)) : $default;

        $data['autoshdays'] = (int) $data['autoshdays'];
        $data['autoclose']  = (int) $data['autoclose'];

        return $data;
    }

    /**
     * 保存积分商城设置（按 aid upsert）
     *
     * @param array $data {autoclose, autoshdays, gettj, gettjtip, gettjurl, comment, comment_check, showjd, showcommission}
     */
    public function saveSysset(int $aid, array $data): void
    {
        $autoclose = (int) ($data['autoclose'] ?? 60);
        if ($autoclose < 1) {
            throw new BusinessException('自动关单分钟数不能小于1');
        }

        $autoshdays = (int) ($data['autoshdays'] ?? 7);
        if ($autoshdays < 1) {
            throw new BusinessException('自动收货天数不能小于1');
        }

        // 人群条件：-1 所有人，否则逗号分隔的会员等级ID
        $gettj = trim((string) ($data['gettj'] ?? '-1'));
        if ('' === $gettj) {
            $gettj = '-1';
        }
        foreach (explode(',', $gettj) as $one) {
            if (!preg_match('/^-?\d+$/', trim($one))) {
                throw new BusinessException('进入条件格式错误');
            }
        }

        $info = [
            'autoclose'      => $autoclose,
            'autoshdays'     => $autoshdays,
            'gettj'          => $gettj,
            'gettjtip'       => trim((string) ($data['gettjtip'] ?? '')),
            'gettjurl'       => trim((string) ($data['gettjurl'] ?? '')),
            'comment'        => (int) ($data['comment'] ?? 1) ? 1 : 0,
            'comment_check'  => (int) ($data['comment_check'] ?? 1) ? 1 : 0,
            'showjd'         => (int) ($data['showjd'] ?? 1) ? 1 : 0,
            'showcommission' => (int) ($data['showcommission'] ?? 1) ? 1 : 0,
        ];

        $exists = ScopeService::query('scoreshop_sysset', $aid)->find();

        if ($exists) {
            Db::name('scoreshop_sysset')->where('aid', $aid)->update($info);
        } else {
            Db::name('scoreshop_sysset')->insert(array_merge($info, ['aid' => $aid]));
        }
    }

    /* ================= 分类 ================= */

    /**
     * 分类列表（不分页，按排序输出）
     *
     * @return array{list: array}
     */
    public function categoryList(int $aid, string $keyword = ''): array
    {
        $query = ScopeService::query('scoreshop_category', $aid);

        $keyword = trim($keyword);
        if ('' !== $keyword) {
            $query->whereLike('name', '%' . $keyword . '%');
        }

        $rows = $query->order('sort', 'desc')->order('id', 'asc')->select()->toArray();

        // 各分类商品数
        $counts = [];
        if (!empty($rows)) {
            $cids = array_map('intval', array_column($rows, 'id'));
            $proRows = ScopeService::query('scoreshop_product', $aid)
                ->whereIn('cid', $cids)
                ->field('cid')
                ->select()
                ->toArray();

            foreach ($proRows as $one) {
                $cid = (int) $one['cid'];
                $counts[$cid] = ($counts[$cid] ?? 0) + 1;
            }
        }

        $list = array_map(static fn (array $row): array => [
            'id'            => (int) $row['id'],
            'name'          => (string) ($row['name'] ?? ''),
            'pic'           => (string) ($row['pic'] ?? ''),
            'status'        => (int) ($row['status'] ?? 1),
            'sort'          => (int) ($row['sort'] ?? 1),
            'goods_count'   => $counts[(int) $row['id']] ?? 0,
            'createtime'    => (int) ($row['createtime'] ?? 0),
        ], $rows);

        return ['list' => $list];
    }

    /**
     * 分类新增/编辑
     *
     * @param array $data {id?, name, pic?, sort?, status?}
     * @return array{id: int}
     */
    public function categorySave(int $aid, array $data): array
    {
        $id   = (int) ($data['id'] ?? 0);
        $name = trim((string) ($data['name'] ?? ''));

        if ('' === $name) {
            throw new BusinessException('分类名称不能为空');
        }

        $row = [
            'name'   => $name,
            'pic'    => trim((string) ($data['pic'] ?? '')),
            'sort'   => (int) ($data['sort'] ?? 1),
            'status' => array_key_exists('status', $data) ? ((int) $data['status'] ? 1 : 0) : 1,
        ];

        if ($id > 0) {
            $exists = ScopeService::query('scoreshop_category', $aid)->where('id', $id)->find();
            if (empty($exists)) {
                throw new BusinessException('分类不存在');
            }
            Db::name('scoreshop_category')->where('aid', $aid)->where('id', $id)->update($row);
        } else {
            $row['aid']        = $aid;
            $row['pid']        = 0;
            $row['createtime'] = time();
            $id                = (int) Db::name('scoreshop_category')->insertGetId($row);
        }

        return ['id' => $id];
    }

    /**
     * 分类删除（分类下有商品时拒绝）
     */
    public function categoryDelete(int $aid, int $id): void
    {
        if ($id <= 0) {
            throw new BusinessException('缺少分类ID');
        }

        $exists = ScopeService::query('scoreshop_category', $aid)->where('id', $id)->find();
        if (empty($exists)) {
            throw new BusinessException('分类不存在');
        }

        $goodsCount = ScopeService::query('scoreshop_product', $aid)->where('cid', $id)->count();
        if ($goodsCount > 0) {
            throw new BusinessException('该分类下还有商品，不能删除');
        }

        Db::name('scoreshop_category')->where('aid', $aid)->where('id', $id)->delete();
    }

    /* ================= 商品 ================= */

    /**
     * 积分商品分页列表（附规格行与分类名）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function productList(int $aid, int $page, int $pagesize, string $keyword, string $status, int $cid): array
    {
        $query = ScopeService::query('scoreshop_product', $aid);

        $keyword = trim($keyword);
        if ('' !== $keyword) {
            $query->whereLike('name', '%' . $keyword . '%');
        }
        if ('' !== $status) {
            $query->where('status', (int) $status);
        }
        if ($cid > 0) {
            $query->where('cid', $cid);
        }

        $total = (clone $query)->count();

        $rows = $query->order('sort', 'desc')
            ->order('id', 'desc')
            ->page($page, $pagesize)
            ->select()
            ->toArray();

        // 分类名映射
        $categoryNames = ScopeService::query('scoreshop_category', $aid)->column('name', 'id');

        // 当前页商品规格行
        $guigeMap = [];
        if (!empty($rows)) {
            $proids = array_map('intval', array_column($rows, 'id'));
            $guiges = ScopeService::query('scoreshop_guige', $aid)
                ->whereIn('proid', $proids)
                ->order('id', 'asc')
                ->select()
                ->toArray();

            foreach ($guiges as $gg) {
                $guigeMap[(int) $gg['proid']][] = [
                    'id'          => (int) $gg['id'],
                    'name'        => (string) ($gg['name'] ?? ''),
                    'score_price' => (int) ($gg['score_price'] ?? 0),
                    'money_price' => (float) ($gg['money_price'] ?? 0),
                    'stock'       => (int) ($gg['stock'] ?? 0),
                    'sales'       => (int) ($gg['sales'] ?? 0),
                ];
            }
        }

        $list = array_map(static fn (array $row): array => [
            'id'          => (int) $row['id'],
            'cid'         => (int) ($row['cid'] ?? 0),
            'cname'       => (string) ($categoryNames[(int) ($row['cid'] ?? 0)] ?? ''),
            'name'        => (string) ($row['name'] ?? ''),
            'pic'         => (string) ($row['pic'] ?? ''),
            'score_price' => (int) ($row['score_price'] ?? 0),
            'money_price' => (float) ($row['money_price'] ?? 0),
            'sell_price'  => (float) ($row['sell_price'] ?? 0),
            'stock'       => (int) ($row['stock'] ?? 0),
            'sales'       => (int) ($row['sales'] ?? 0),
            'buymax'      => (int) ($row['buymax'] ?? 0),
            'status'      => (int) ($row['status'] ?? 0),
            'sort'        => (int) ($row['sort'] ?? 0),
            'createtime'  => (int) ($row['createtime'] ?? 0),
            'guige'       => $guigeMap[(int) $row['id']] ?? [],
        ], $rows);

        return ['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pagesize];
    }

    /**
     * 积分商品新增/编辑（纯积分或积分+钱，价格取规格最低值）
     *
     * @param array $data {id?, cid, name, pic?, pics?, detail?, sell_price(市场价), score_price, money_price, stock?, buymax, sort?, status?, guige?:[{id?,name,score_price,money_price,stock}]}
     * @return array{id: int}
     */
    public function productSave(int $aid, array $data): array
    {
        $id  = (int) ($data['id'] ?? 0);
        $cid = (int) ($data['cid'] ?? 0);

        if ($cid <= 0) {
            throw new BusinessException('请选择商品分类');
        }
        $category = ScopeService::query('scoreshop_category', $aid)->where('id', $cid)->find();
        if (empty($category)) {
            throw new BusinessException('商品分类不存在');
        }

        $name = trim((string) ($data['name'] ?? ''));
        if ('' === $name) {
            throw new BusinessException('请填写商品名称');
        }

        $scorePrice = (int) ($data['score_price'] ?? 0);
        $moneyPrice = $this->money($data['money_price'] ?? 0);
        if ($scorePrice < 1 && $moneyPrice <= 0) {
            throw new BusinessException('请设置积分价或现金价');
        }
        if ($scorePrice < 0 || $moneyPrice < 0) {
            throw new BusinessException('价格不能为负数');
        }

        $buymax = max(0, (int) ($data['buymax'] ?? 0));

        $product = [
            'cid'             => $cid,
            'name'            => $name,
            'pic'             => trim((string) ($data['pic'] ?? '')),
            'pics'            => trim((string) ($data['pics'] ?? '')),
            'detail'          => (string) ($data['detail'] ?? ''),
            'sell_price'      => $this->money($data['sell_price'] ?? 0), // 市场价（划线价）
            'cost_price'      => $this->money($data['cost_price'] ?? 0),
            'score_price'     => $scorePrice,
            'money_price'     => $moneyPrice,
            'buymax'          => $buymax,
            'everyday_buymax' => max(0, (int) ($data['everyday_buymax'] ?? 0)),
            'lvprice'         => !empty($data['lvprice']) ? 1 : 0,
            'weight'          => max(0, (int) ($data['weight'] ?? 0)),
            'sort'            => (int) ($data['sort'] ?? 0),
            'status'          => array_key_exists('status', $data) ? ((int) $data['status'] ? 1 : 0) : 1,
            // 配送模板：1全部模板 0选择模板 3自动发货 4在线卡密
            'freighttype'     => (int) ($data['freighttype'] ?? 1),
            'freightdata'     => trim((string) ($data['freightdata'] ?? '')),
            'freightcontent' => trim((string) ($data['freightcontent'] ?? '')),
            'contact_require' => !empty($data['contact_require']) ? 1 : 0,
            // 分销设置：0按会员等级 1单独比例 2单独金额 3送积分 5比例+积分 -1不参与
            'commissionset'   => array_key_exists('commissionset', $data) ? (int) $data['commissionset'] : 0,
            'commissiondata1'  => $this->encodeJson($data['commissiondata1'] ?? null),
            'commissiondata2'  => $this->encodeJson($data['commissiondata2'] ?? null),
            'commissiondata3'  => $this->encodeJson($data['commissiondata3'] ?? null),
            'commissiondata5'  => $this->encodeJson($data['commissiondata5'] ?? null),
            // 显示/购买条件（数组→逗号串，-1 所有人）
            'showtj'          => $this->encodeTj($data['showtj'] ?? ['-1']),
            'gettj'           => $this->encodeTj($data['gettj'] ?? ['-1']),
        ];

        if ($id > 0) {
            $this->findProduct($aid, $id);
            // 编辑不覆盖已累计销量
            Db::name('scoreshop_product')->where('aid', $aid)->where('id', $id)->update($product);
        } else {
            $product['aid']        = $aid;
            $product['bid']        = 0;
            $product['sales']      = max(0, (int) ($data['sales'] ?? 0));
            $product['stock']      = max(0, (int) ($data['stock'] ?? 100));
            $product['createtime'] = time();
            $id                    = (int) Db::name('scoreshop_product')->insertGetId($product);
        }

        // 规格行 upsert（未建过规格时补默认规格）
        $guigeInput = isset($data['guige']) && is_array($data['guige']) ? $data['guige'] : [];
        $this->saveGuige($aid, $id, $guigeInput, $scorePrice, $moneyPrice, (int) ($product['stock'] ?? 0));

        // 商品价格/库存与规格保持一致：价格取规格最低，库存取合计
        $guiges = ScopeService::query('scoreshop_guige', $aid)->where('proid', $id)->select()->toArray();
        $minScore = min(array_map(static fn (array $g): int => (int) $g['score_price'], $guiges));
        $minMoney = min(array_map(static fn (array $g): float => (float) $g['money_price'], $guiges));
        $stockSum = (int) array_sum(array_map(static fn (array $g): int => (int) $g['stock'], $guiges));

        Db::name('scoreshop_product')->where('aid', $aid)->where('id', $id)->update([
            'score_price' => $minScore,
            'money_price' => $this->money($minMoney),
            'stock'       => $stockSum,
        ]);

        return ['id' => $id];
    }

    /**
     * 删除积分商品（已有订单时拒绝）
     */
    public function productDelete(int $aid, int $id): void
    {
        $this->findProduct($aid, $id);

        $orderCount = Db::name('scoreshop_order_goods')
            ->where('aid', $aid)
            ->where('proid', $id)
            ->count();
        if ($orderCount > 0) {
            throw new BusinessException('该商品已产生兑换订单，不能删除');
        }

        Db::name('scoreshop_product')->where('aid', $aid)->where('id', $id)->delete();
        Db::name('scoreshop_guige')->where('aid', $aid)->where('proid', $id)->delete();
    }

    /**
     * 上架/下架
     */
    public function productStatus(int $aid, int $id, int $status): void
    {
        $this->findProduct($aid, $id);

        Db::name('scoreshop_product')->where('aid', $aid)->where('id', $id)
            ->update(['status' => $status ? 1 : 0]);
    }

    /* ================= 订单 ================= */

    /**
     * 兑换订单分页列表（附订单商品行）
     *
     * @param array $filters {status, ordernum, title, linkman, tel, start_date, end_date}
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function orderList(int $aid, int $page, int $pagesize, array $filters): array
    {
        $query = ScopeService::queryAs('scoreshop_order', $aid, 'o')
            ->leftJoin('member m', 'm.id = o.mid')
            ->field('o.*, m.nickname, m.headimg');

        $status = trim((string) ($filters['status'] ?? ''));
        if ('' !== $status) {
            $query->where('o.status', (int) $status);
        }

        foreach (['ordernum', 'title', 'linkman', 'tel'] as $field) {
            $val = trim((string) ($filters[$field] ?? ''));
            if ('' !== $val) {
                $query->whereLike('o.' . $field, '%' . $val . '%');
            }
        }

        $startDate = trim((string) ($filters['start_date'] ?? ''));
        if ('' !== $startDate && false !== strtotime($startDate)) {
            $query->where('o.createtime', '>=', strtotime($startDate));
        }
        $endDate = trim((string) ($filters['end_date'] ?? ''));
        if ('' !== $endDate && false !== strtotime($endDate)) {
            $query->where('o.createtime', '<', strtotime($endDate) + 86400);
        }

        $total = (clone $query)->count();

        $rows = $query->order('o.id', 'desc')->page($page, $pagesize)->select()->toArray();

        // 订单商品行（一次查出按 orderid 分组）
        $goodsMap = [];
        if (!empty($rows)) {
            $orderIds = array_map('intval', array_column($rows, 'id'));
            $goodsRows = Db::name('scoreshop_order_goods')
                ->where('aid', $aid)
                ->whereIn('orderid', $orderIds)
                ->order('id', 'asc')
                ->select()
                ->toArray();

            foreach ($goodsRows as $og) {
                $goodsMap[(int) $og['orderid']][] = [
                    'name'        => (string) ($og['name'] ?? ''),
                    'pic'         => (string) ($og['pic'] ?? ''),
                    'ggname'      => (string) ($og['ggname'] ?? ''),
                    'num'         => (int) ($og['num'] ?? 1),
                    'score_price' => (int) ($og['score_price'] ?? 0),
                    'money_price' => (float) ($og['money_price'] ?? 0),
                ];
            }
        }

        $list = array_map(static fn (array $row): array => [
            'id'          => (int) $row['id'],
            'ordernum'    => (string) ($row['ordernum'] ?? ''),
            'mid'         => (int) ($row['mid'] ?? 0),
            'nickname'    => (string) ($row['nickname'] ?? ''),
            'headimg'     => (string) ($row['headimg'] ?? ''),
            'title'       => (string) ($row['title'] ?? ''),
            'totalscore'  => (int) ($row['totalscore'] ?? 0),
            'totalmoney'  => (float) ($row['totalmoney'] ?? 0),
            'totalprice'  => (float) ($row['totalprice'] ?? 0),
            'totalnum'    => (int) ($row['totalnum'] ?? 1),
            'status'      => (int) ($row['status'] ?? 0),
            'freight_type' => (int) ($row['freight_type'] ?? 0),
            'freight_text' => (string) ($row['freight_text'] ?? ''),
            'linkman'     => (string) ($row['linkman'] ?? ''),
            'tel'         => (string) ($row['tel'] ?? ''),
            'area'        => (string) ($row['area'] ?? ''),
            'address'     => trim(((string) ($row['area'] ?? '')) . ' ' . ((string) ($row['address'] ?? ''))),
            'express_com' => (string) ($row['express_com'] ?? ''),
            'express_no'  => (string) ($row['express_no'] ?? ''),
            'mdid'        => (int) ($row['mdid'] ?? 0),
            'refund_status' => (int) ($row['refund_status'] ?? 0),
            'refund_text' => self::REFUND_TEXT[(int) ($row['refund_status'] ?? 0)] ?? '',
            'refund_money' => (float) ($row['refund_money'] ?? 0),
            'paytype'     => (string) ($row['paytype'] ?? ''),
            'platform'    => (string) ($row['platform'] ?? ''),
            'platform_text' => (string) ($row['platform'] ?? ''),
            'hexiao_code' => (string) ($row['hexiao_code'] ?? ''),
            'createtime'  => (int) ($row['createtime'] ?? 0),
            'paytime'     => (int) ($row['paytime'] ?? 0),
            'send_time'   => (int) ($row['send_time'] ?? 0),
            'collect_time' => (int) ($row['collect_time'] ?? 0),
            'remark'      => (string) ($row['remark'] ?? ''),
            'goods'       => $goodsMap[(int) $row['id']] ?? [],
        ], $rows);

        return ['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pagesize];
    }

    /**
     * 后台手动关单（仅未支付单；同事务回补库存/销量）
     */
    public function orderClose(int $aid, int $orderId): void
    {
        if ($orderId <= 0) {
            throw new BusinessException('缺少订单ID');
        }

        $order = ScopeService::query('scoreshop_order', $aid)->where('id', $orderId)->find();
        if (empty($order)) {
            throw new BusinessException('订单不存在');
        }
        if (self::STATUS_UNPAID !== (int) $order['status']) {
            throw new BusinessException('仅未支付订单可以关闭');
        }

        if (!$this->closeInternal($orderId)) {
            throw new BusinessException('关闭失败，订单状态已变化');
        }
    }

    /* ================= 库存扣减 / 关单核心 ================= */

    /**
     * 下单扣库存（防超卖核心，客户端兑换流程调用）
     *
     * 条件更新 where stock >= num：并发下只有一个请求能扣成功，
     * affected=0 即库存不足；规格扣减失败先回补商品库存，保证成对。
     */
    public function deductStock(int $aid, int $proid, int $ggid, int $num): bool
    {
        if ($num < 1) {
            return false;
        }

        $affected = Db::name('scoreshop_product')
            ->where('aid', $aid)
            ->where('id', $proid)
            ->where('stock', '>=', $num)
            ->dec('stock', $num)
            ->inc('sales', $num)
            ->update();

        if ($affected <= 0) {
            return false;
        }

        if ($ggid > 0) {
            $ggAffected = Db::name('scoreshop_guige')
                ->where('aid', $aid)
                ->where('id', $ggid)
                ->where('stock', '>=', $num)
                ->dec('stock', $num)
                ->inc('sales', $num)
                ->update();

            if ($ggAffected <= 0) {
                Db::name('scoreshop_product')
                    ->where('aid', $aid)
                    ->where('id', $proid)
                    ->where('sales', '>=', $num)
                    ->inc('stock', $num)
                    ->dec('sales', $num)
                    ->update();

                return false;
            }
        }

        return true;
    }

    /**
     * 限购累计口径：只计有效单（未付/已付/已发货/已收货），关闭单不计
     */
    public function boughtNum(int $aid, int $mid, int $proid): int
    {
        return (int) Db::name('scoreshop_order_goods')
            ->where('aid', $aid)
            ->where('mid', $mid)
            ->where('proid', $proid)
            ->whereIn('status', [self::STATUS_UNPAID, self::STATUS_PAID, self::STATUS_SHIPPED, self::STATUS_FINISHED])
            ->sum('num');
    }

    /**
     * 定时任务：扫描全部租户超时未支付兑换单并关闭
     *
     * 超时口径：createtime + 租户 scoreshop_sysset.autoclose 分钟 <= 当前时间
     *
     * @return int 本次关闭笔数
     */
    public function closeExpired(): int
    {
        $now    = time();
        $orders = Db::name('scoreshop_order')
            ->where('status', self::STATUS_UNPAID)
            ->field('id,aid,createtime')
            ->limit(self::SCAN_LIMIT)
            ->select()
            ->toArray();

        $autocloseMap = [];
        $count        = 0;

        foreach ($orders as $row) {
            $aid = (int) $row['aid'];

            if (!isset($autocloseMap[$aid])) {
                $autoclose = Db::name('scoreshop_sysset')->where('aid', $aid)->value('autoclose');
                $autocloseMap[$aid] = $autoclose !== null && (int) $autoclose > 0 ? (int) $autoclose : 60;
            }

            if ((int) $row['createtime'] + $autocloseMap[$aid] * 60 > $now) {
                continue;
            }

            if ($this->closeInternal((int) $row['id'])) {
                $count++;
            }
        }

        return $count;
    }

    /* ================= C 端核心链路（api 预留） ================= */

    /**
     * C 端商品列表（仅上架且审核通过，复用后台 productList 口径）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function apiProductList(int $aid, int $page, int $pagesize, string $keyword = '', int $cid = 0): array
    {
        $data = $this->productList($aid, $page, $pagesize, $keyword, '1', $cid);

        $data['list'] = array_values(array_filter(
            $data['list'],
            static fn (array $row): bool => 1 === (int) ($row['status'] ?? 0)
        ));

        return $data;
    }

    /**
     * C 端商品详情（仅上架且审核通过；含规格行）
     *
     * @return array<string, mixed>
     */
    public function apiProductDetail(int $aid, int $proid): array
    {
        $product = $this->findProduct($aid, $proid);

        if (1 !== (int) ($product['status'] ?? 0) || 1 !== (int) ($product['ischecked'] ?? 1)) {
            throw new BusinessException('积分商品不存在或已下架');
        }

        return [
            'id'          => (int) $product['id'],
            'cid'         => (int) ($product['cid'] ?? 0),
            'name'        => (string) ($product['name'] ?? ''),
            'pic'         => (string) ($product['pic'] ?? ''),
            'pics'        => (string) ($product['pics'] ?? ''),
            'detail'      => (string) ($product['detail'] ?? ''),
            'sell_price'  => (float) ($product['sell_price'] ?? 0),
            'score_price' => (int) ($product['score_price'] ?? 0),
            'money_price' => (float) ($product['money_price'] ?? 0),
            'stock'       => (int) ($product['stock'] ?? 0),
            'sales'       => (int) ($product['sales'] ?? 0),
            'buymax'      => (int) ($product['buymax'] ?? 0),
            'everyday_buymax' => (int) ($product['everyday_buymax'] ?? 0),
            'guige'       => ScopeService::query('scoreshop_guige', $aid)
                ->where('proid', $proid)->order('id', 'asc')->select()->toArray(),
        ];
    }

    /**
     * C 端兑换下单（纯积分直接扣积分置已付；积分+钱走 payorder 支付链路）
     *
     * @param array<string, mixed> $extra linkman/tel/area/address/message/platform/freight_price
     * @return array{orderid: int, ordernum: string, totalscore: int, totalmoney: float, status: int}
     */
    public function apiCreateOrder(int $aid, int $mid, int $proid, int $ggid, int $num, array $extra = []): array
    {
        $num = max(1, $num);

        return Db::transaction(function () use ($aid, $mid, $proid, $ggid, $num, $extra) {
            $product = Db::name('scoreshop_product')
                ->where('aid', $aid)->where('id', $proid)
                ->where('status', 1)->where('ischecked', 1)
                ->lock(true)->find();

            if (empty($product)) {
                throw new BusinessException('积分商品不存在或已下架');
            }

            // 限购：累计 buymax + 每日 everyday_buymax（0 不限）
            $bought = $this->boughtNum($aid, $mid, $proid);
            $buymax = (int) ($product['buymax'] ?? 0);

            if ($buymax > 0 && $bought + $num > $buymax) {
                throw new BusinessException('超出限购数量（每人限兑 ' . $buymax . ' 件）');
            }

            $everyday = (int) ($product['everyday_buymax'] ?? 0);

            if ($everyday > 0) {
                $todayNum = (int) Db::name('scoreshop_order_goods')
                    ->where('aid', $aid)->where('mid', $mid)->where('proid', $proid)
                    ->where('createtime', '>=', strtotime(date('Y-m-d')))
                    ->whereIn('status', [self::STATUS_UNPAID, self::STATUS_PAID, self::STATUS_SHIPPED, self::STATUS_FINISHED])
                    ->sum('num');

                if ($todayNum + $num > $everyday) {
                    throw new BusinessException('超出今日限兑数量（每日限兑 ' . $everyday . ' 件）');
                }
            }

            // 价格：规格优先，否则商品行
            $scorePrice = (int) ($product['score_price'] ?? 0);
            $moneyPrice = (float) ($product['money_price'] ?? 0);
            $ggname     = '';

            if ($ggid > 0) {
                $guige = Db::name('scoreshop_guige')
                    ->where('aid', $aid)->where('id', $ggid)->where('proid', $proid)->find();

                if (empty($guige)) {
                    throw new BusinessException('商品规格不存在');
                }

                $scorePrice = (int) ($guige['score_price'] ?? 0);
                $moneyPrice = (float) ($guige['money_price'] ?? 0);
                $ggname     = (string) ($guige['name'] ?? '');
            }

            if ($scorePrice < 1 && $moneyPrice <= 0) {
                throw new BusinessException('商品价格配置无效');
            }

            // 库存扣减（条件更新防超卖）
            if (!$this->deductStock($aid, $proid, $ggid, $num)) {
                throw new BusinessException('库存不足，已被兑完了');
            }

            $totalScore  = $scorePrice * $num;
            $totalMoney  = round($moneyPrice * $num, 2);
            $freightFee  = round(max(0, (float) ($extra['freight_price'] ?? 0)), 2);
            $totalPrice  = round($totalMoney + $freightFee, 2);
            $ordernum    = date('ymdHis') . $aid . random_int(1000, 9999);
            $pureScore   = $totalMoney <= 0;
            $now         = time();

            $orderId = (int) Db::name('scoreshop_order')->insertGetId([
                'aid'           => $aid,
                'bid'           => (int) ($product['bid'] ?? 0),
                'mid'           => $mid,
                'title'         => (string) $product['name'],
                'totalscore'    => $totalScore,
                'totalmoney'    => $totalMoney,
                'totalprice'    => $totalPrice,
                'totalnum'      => $num,
                'freight_price' => $freightFee,
                'createtime'    => $now,
                'status'        => self::STATUS_UNPAID,
                'ordernum'      => $ordernum,
                'linkman'       => trim((string) ($extra['linkman'] ?? '')),
                'tel'           => trim((string) ($extra['tel'] ?? '')),
                'area'          => trim((string) ($extra['area'] ?? '')),
                'address'       => trim((string) ($extra['address'] ?? '')),
                'message'       => trim((string) ($extra['message'] ?? '')),
                'freight_text'  => trim((string) ($extra['freight_text'] ?? '包邮')),
                'platform'      => (string) ($extra['platform'] ?? 'wx'),
                'hexiao_code'   => strtoupper(bin2hex(random_bytes(8))),
            ]);

            Db::name('scoreshop_order_goods')->insert([
                'aid'         => $aid,
                'bid'         => (int) ($product['bid'] ?? 0),
                'mid'         => $mid,
                'orderid'     => $orderId,
                'ordernum'    => $ordernum,
                'proid'       => $proid,
                'name'        => (string) $product['name'],
                'pic'         => (string) ($product['pic'] ?? ''),
                'procode'     => (string) ($product['procode'] ?? ''),
                'ggid'        => $ggid,
                'ggname'      => $ggname,
                'num'         => $num,
                'sell_price'  => (float) ($product['sell_price'] ?? 0),
                'cost_price'  => (float) ($product['cost_price'] ?? 0),
                'score_price' => $scorePrice,
                'money_price' => $moneyPrice,
                'totalscore'  => $totalScore,
                'totalmoney'  => $totalMoney,
                'status'      => self::STATUS_UNPAID,
                'createtime'  => $now,
            ]);

            if ($pureScore) {
                // 纯积分兑换：同事务扣积分（余额不足抛错回滚）直接置已支付
                $this->scoreService->deductForOrder($aid, $mid, $totalScore, '积分商城兑换 ' . $ordernum);

                Db::name('scoreshop_order')->where('id', $orderId)->update([
                    'status'  => self::STATUS_PAID,
                    'paytype' => 'score',
                    'paytime' => $now,
                ]);

                Db::name('scoreshop_order_goods')
                    ->where('aid', $aid)->where('orderid', $orderId)->where('status', self::STATUS_UNPAID)
                    ->update(['status' => self::STATUS_PAID]);

                return [
                    'orderid'    => $orderId,
                    'ordernum'   => $ordernum,
                    'totalscore' => $totalScore,
                    'totalmoney' => $totalMoney,
                    'status'     => self::STATUS_PAID,
                ];
            }

            // 积分+钱：先扣积分占位，支付单走现金部分（关单退积分由 closeInternal 之外补充）
            $this->scoreService->deductForOrder($aid, $mid, $totalScore, '积分商城兑换 ' . $ordernum);

            // 支付单（type=scoreshop，score 登记抵扣积分供关单退还）
            Db::name('payorder')->insert([
                'aid'        => $aid,
                'bid'        => (int) ($product['bid'] ?? 0),
                'mid'        => $mid,
                'orderid'    => $orderId,
                'ordernum'   => $ordernum,
                'title'      => (string) $product['name'],
                'money'      => $totalPrice,
                'score'      => $totalScore,
                'type'       => 'scoreshop',
                'createtime' => $now,
                'status'     => 0,
                'platform'   => (string) ($extra['platform'] ?? 'wx'),
            ]);

            // mock 模式即时支付成功（真实模式由支付回调走 payScoreshopOrder）
            if (PayService::isMock()) {
                $this->payScoreshopOrder($aid, $orderId, 'wechat', 'MOCK' . time() . random_int(1000, 9999));
            }

            return [
                'orderid'    => $orderId,
                'ordernum'   => $ordernum,
                'totalscore' => $totalScore,
                'totalmoney' => $totalMoney,
                'status'     => PayService::isMock() ? self::STATUS_PAID : self::STATUS_UNPAID,
            ];
        });
    }

    /**
     * 积分商城订单支付成功入账（幂等：仅 status=0 → 1；商品行同步置已付）
     */
    public function payScoreshopOrder(int $aid, int $orderId, string $payType, string $payNum): bool
    {
        $order = ScopeService::query('scoreshop_order', $aid)->where('id', $orderId)->find();

        if (empty($order) || self::STATUS_UNPAID !== (int) $order['status']) {
            return false;
        }

        $affected = Db::name('scoreshop_order')
            ->where('aid', $aid)->where('id', $orderId)->where('status', self::STATUS_UNPAID)
            ->update([
                'status'    => self::STATUS_PAID,
                'paytype'   => $payType,
                'paytypeid' => PayService::PAY_TYPE_IDS[$payType] ?? 0,
                'paynum'    => $payNum,
                'paytime'   => time(),
            ]);

        if ($affected <= 0) {
            return false;
        }

        Db::name('scoreshop_order_goods')
            ->where('aid', $aid)->where('orderid', $orderId)->where('status', self::STATUS_UNPAID)
            ->update(['status' => self::STATUS_PAID]);

        Db::name('payorder')
            ->where('aid', $aid)->where('type', 'scoreshop')->where('ordernum', (string) $order['ordernum'])
            ->where('status', 0)
            ->update([
                'status'    => 1,
                'paytype'   => $payType,
                'paytypeid' => PayService::PAY_TYPE_IDS[$payType] ?? 0,
                'paynum'    => $payNum,
                'paytime'   => time(),
            ]);

        return true;
    }

    /**
     * 我的兑换订单（C 端分页，附商品行）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function apiMyOrders(int $aid, int $mid, string $status, int $page, int $pagesize): array
    {
        $query = ScopeService::query('scoreshop_order', $aid)->where('mid', $mid);

        if ('' !== $status) {
            $query->where('status', (int) $status);
        }

        $total = (clone $query)->count();

        $rows = $query->order('id', 'desc')->page($page, $pagesize)->select()->toArray();

        foreach ($rows as &$row) {
            $row['goods'] = Db::name('scoreshop_order_goods')
                ->where('aid', $aid)->where('orderid', (int) $row['id'])
                ->select()->toArray();
        }
        unset($row);

        return ['list' => $rows, 'total' => $total, 'page' => $page, 'pagesize' => $pagesize];
    }

    /* ================= 内部工具 ================= */

    /**
     * 关单入账（幂等核心，事务内执行）：
     * 1. 行锁读取订单，非未支付直接返回
     * 2. status=0 条件更新为 4，并发下只有一个调用方能成功
     * 3. 按订单商品行逐行回补商品/规格库存，销量回退（不低于0）
     * 4. 订单商品行同步置为关闭
     */
    protected function closeInternal(int $orderId): bool
    {
        return (bool) Db::transaction(function () use ($orderId): bool {
            $order = Db::name('scoreshop_order')->where('id', $orderId)->lock(true)->find();

            if (empty($order) || self::STATUS_UNPAID !== (int) $order['status']) {
                return false;
            }

            // 条件更新：只有仍是未支付才关单（幂等防重复回补）
            $affected = Db::name('scoreshop_order')
                ->where('id', $orderId)
                ->where('status', self::STATUS_UNPAID)
                ->update(['status' => self::STATUS_CLOSED]);

            if ($affected <= 0) {
                return false;
            }

            $aid = (int) $order['aid'];

            // 逐商品行回补库存 + 销量回退
            $goodsRows = Db::name('scoreshop_order_goods')
                ->where('aid', $aid)
                ->where('orderid', $orderId)
                ->select()
                ->toArray();

            foreach ($goodsRows as $og) {
                $num = max(1, (int) $og['num']);

                Db::name('scoreshop_product')
                    ->where('aid', $aid)
                    ->where('id', (int) $og['proid'])
                    ->update([
                        'stock' => Db::raw('stock + ' . $num),
                        'sales' => Db::raw('IF(sales >= ' . $num . ', sales - ' . $num . ', 0)'),
                    ]);

                if ((int) $og['ggid'] > 0) {
                    Db::name('scoreshop_guige')
                        ->where('aid', $aid)
                        ->where('id', (int) $og['ggid'])
                        ->update([
                            'stock' => Db::raw('stock + ' . $num),
                            'sales' => Db::raw('IF(sales >= ' . $num . ', sales - ' . $num . ', 0)'),
                        ]);
                }
            }

            // 订单商品行同步关闭
            Db::name('scoreshop_order_goods')
                ->where('aid', $aid)
                ->where('orderid', $orderId)
                ->update(['status' => self::STATUS_CLOSED]);

            // 退还下单占用的抵扣积分（积分+钱单下单时已扣，纯积分单已付不会进关单）
            $totalScore = (int) ($order['totalscore'] ?? 0);

            if ($totalScore > 0 && (int) ($order['mid'] ?? 0) > 0) {
                $this->scoreService->refundForCancel($aid, (int) $order['mid'], $totalScore, '积分商城订单关闭返还积分');
            }

            return true;
        });
    }

    /**
     * 查找本租户积分商品（不存在抛错）
     *
     * @return array<string, mixed>
     */
    protected function findProduct(int $aid, int $id): array
    {
        if ($id <= 0) {
            throw new BusinessException('缺少商品ID');
        }

        $product = ScopeService::query('scoreshop_product', $aid)->where('id', $id)->find();
        if (empty($product)) {
            throw new BusinessException('积分商品不存在');
        }

        return $product;
    }

    /**
     * 规格行保存（upsert：带 id 更新，不带新增；从未建过规格时补默认规格）
     *
     * @param array $guigeInput [{id?, name, score_price, money_price, stock}]
     */
    protected function saveGuige(int $aid, int $proid, array $guigeInput, int $scorePrice, float $moneyPrice, int $defaultStock): void
    {
        $existingIds = array_map('intval', ScopeService::query('scoreshop_guige', $aid)
            ->where('proid', $proid)
            ->column('id'));

        if (empty($guigeInput) && empty($existingIds)) {
            Db::name('scoreshop_guige')->insert([
                'aid'         => $aid,
                'proid'       => $proid,
                'name'        => '默认',
                'score_price' => $scorePrice,
                'money_price' => $moneyPrice,
                'stock'       => max(0, $defaultStock),
                'sales'       => 0,
            ]);

            return;
        }

        foreach ($guigeInput as $gg) {
            if (!is_array($gg)) {
                continue;
            }

            $ggId = (int) ($gg['id'] ?? 0);
            $ggScore = (int) ($gg['score_price'] ?? $scorePrice);
            $ggMoney = $this->money($gg['money_price'] ?? $moneyPrice);

            if ($ggScore < 1 && $ggMoney <= 0) {
                throw new BusinessException('规格价格不能全为空');
            }

            $row = [
                'name'        => trim((string) ($gg['name'] ?? '')) ?: '默认',
                'pic'         => trim((string) ($gg['pic'] ?? '')),
                'market_price' => $this->money($gg['market_price'] ?? 0),
                'cost_price'  => $this->money($gg['cost_price'] ?? 0),
                'score_price' => $ggScore,
                'money_price' => $ggMoney,
                'weight'      => max(0, (int) ($gg['weight'] ?? 0)),
                'stock'       => max(0, (int) ($gg['stock'] ?? 0)),
            ];

            if ($ggId > 0 && in_array($ggId, $existingIds, true)) {
                Db::name('scoreshop_guige')->where('aid', $aid)->where('id', $ggId)->where('proid', $proid)->update($row);
            } else {
                Db::name('scoreshop_guige')->insert(array_merge($row, [
                    'aid'   => $aid,
                    'proid' => $proid,
                    'sales' => 0,
                ]));
            }
        }
    }

    /**
     * 商品详情（编辑回填）
     *
     * @return array<string, mixed>
     */
    public function productDetail(int $aid, int $id): array
    {
        $row = $this->findProduct($aid, $id);

        $guiges = ScopeService::query('scoreshop_guige', $aid)
            ->where('proid', $id)->order('id', 'asc')->select()->toArray();

        $guige = array_map(static fn (array $g): array => [
            'id'          => (int) $g['id'],
            'name'        => (string) ($g['name'] ?? '默认规格'),
            'pic'         => (string) ($g['pic'] ?? ''),
            'market_price' => (float) ($g['market_price'] ?? 0),
            'cost_price'  => (float) ($g['cost_price'] ?? 0),
            'score_price' => (int) ($g['score_price'] ?? 0),
            'money_price' => (float) ($g['money_price'] ?? 0),
            'weight'      => (int) ($g['weight'] ?? 0),
            'stock'       => (int) ($g['stock'] ?? 0),
        ], $guiges);

        return [
            'id'              => (int) $row['id'],
            'cid'             => (int) $row['cid'],
            'name'            => (string) $row['name'],
            'pic'             => (string) ($row['pic'] ?? ''),
            'pics'            => (string) ($row['pics'] ?? ''),
            'detail'          => (string) ($row['detail'] ?? ''),
            'sell_price'      => (float) ($row['sell_price'] ?? 0),
            'cost_price'      => (float) ($row['cost_price'] ?? 0),
            'score_price'     => (int) $row['score_price'],
            'money_price'     => (float) $row['money_price'],
            'buymax'          => (int) ($row['buymax'] ?? 0),
            'everyday_buymax' => (int) ($row['everyday_buymax'] ?? 0),
            'lvprice'         => (int) ($row['lvprice'] ?? 0),
            'weight'          => (int) ($row['weight'] ?? 0),
            'sales'           => (int) ($row['sales'] ?? 0),
            'stock'           => (int) ($row['stock'] ?? 0),
            'sort'            => (int) ($row['sort'] ?? 0),
            'status'          => (int) ($row['status'] ?? 0),
            'freighttype'     => (int) ($row['freighttype'] ?? 1),
            'freightdata'     => (string) ($row['freightdata'] ?? ''),
            'freightcontent'  => (string) ($row['freightcontent'] ?? ''),
            'contact_require' => (int) ($row['contact_require'] ?? 0),
            'commissionset'   => (int) ($row['commissionset'] ?? 0),
            'commissiondata1'  => $this->decodeJson((string) ($row['commissiondata1'] ?? '')),
            'commissiondata2'  => $this->decodeJson((string) ($row['commissiondata2'] ?? '')),
            'commissiondata3'  => $this->decodeJson((string) ($row['commissiondata3'] ?? '')),
            'commissiondata5'  => $this->decodeJson((string) ($row['commissiondata5'] ?? '')),
            'showtj'          => $this->decodeTj((string) ($row['showtj'] ?? '-1')),
            'gettj'           => $this->decodeTj((string) ($row['gettj'] ?? '-1')),
            'guige'           => $guige,
        ];
    }

    /* ================= 订单后台操作 ================= */

    /**
     * 手动快递发货（status=1→2）
     */
    public function orderSendExpress(int $aid, int $orderId, string $expressCom, string $expressNo): void
    {
        $expressCom = trim($expressCom);
        $expressNo = trim($expressNo);
        if ('' === $expressCom || '' === $expressNo) {
            throw new BusinessException('请填写快递公司与快递单号');
        }
        $order = ScopeService::query('scoreshop_order', $aid)->where('id', $orderId)->find();
        if (empty($order)) {
            throw new BusinessException('订单不存在', 404, 404);
        }
        if (self::STATUS_PAID !== (int) $order['status']) {
            throw new BusinessException('仅已支付订单可发货');
        }
        Db::name('scoreshop_order')->where('aid', $aid)->where('id', $orderId)->update([
            'express_com' => $expressCom,
            'express_no'  => $expressNo,
            'send_time'   => time(),
            'status'      => self::STATUS_SHIPPED,
        ]);
    }

    /**
     * 到店核销（自提订单 status=1→3，写核销记录）
     */
    public function orderHexiao(int $aid, int $orderId): void
    {
        Db::transaction(function () use ($aid, $orderId) {
            $order = Db::name('scoreshop_order')->where('aid', $aid)->where('id', $orderId)->lock(true)->find();
            if (empty($order)) {
                throw new BusinessException('订单不存在', 404, 404);
            }
            if (self::STATUS_PAID !== (int) $order['status']) {
                throw new BusinessException('仅已支付订单可核销');
            }
            Db::name('hexiao_order')->insert([
                'aid'        => $aid,
                'bid'        => 0,
                'uid'        => 0,
                'mid'        => (string) ($order['mid'] ?? ''),
                'orderid'    => $orderId,
                'ordernum'   => (string) $order['ordernum'],
                'title'      => (string) ($order['title'] ?? ''),
                'createtime' => time(),
                'remark'     => '积分兑换到店核销',
                'type'       => 'scoreshop',
                'mdid'       => (int) ($order['mdid'] ?? 0),
            ]);
            Db::name('scoreshop_order')->where('id', $orderId)->update([
                'status'       => self::STATUS_FINISHED,
                'collect_time' => time(),
            ]);
        });
    }

    /**
     * 退款（已支付/已发货 → 已关闭/已退款）
     */
    public function orderRefund(int $aid, int $orderId, string $reason): void
    {
        $order = ScopeService::query('scoreshop_order', $aid)->where('id', $orderId)->find();
        if (empty($order)) {
            throw new BusinessException('订单不存在', 404, 404);
        }
        if (!in_array((int) $order['status'], [self::STATUS_PAID, self::STATUS_SHIPPED], true)) {
            throw new BusinessException('仅已支付/已发货订单可退款');
        }
        Db::name('scoreshop_order')->where('aid', $aid)->where('id', $orderId)->update([
            'status'         => self::STATUS_CLOSED,
            'refund_status'  => 2,
            'refund_reason'  => trim($reason),
            'refund_money'   => (float) $order['totalprice'],
            'refund_time'    => time(),
        ]);
    }

    /**
     * 配送（同城配送员派单，写 mdid，status=1→2）
     */
    public function orderDeliver(int $aid, int $orderId, int $mdid): void
    {
        $order = ScopeService::query('scoreshop_order', $aid)->where('id', $orderId)->find();
        if (empty($order)) {
            throw new BusinessException('订单不存在', 404, 404);
        }
        if (self::STATUS_PAID !== (int) $order['status']) {
            throw new BusinessException('仅已支付订单可派配送');
        }
        Db::name('scoreshop_order')->where('aid', $aid)->where('id', $orderId)->update([
            'mdid'      => $mdid,
            'send_time' => time(),
            'status'    => self::STATUS_SHIPPED,
        ]);
    }

    /**
     * 改为已支付（未支付→已支付）
     */
    public function orderIspay(int $aid, int $orderId): void
    {
        $order = ScopeService::query('scoreshop_order', $aid)->where('id', $orderId)->find();
        if (empty($order)) {
            throw new BusinessException('订单不存在');
        }
        if (0 !== (int) $order['status']) {
            throw new BusinessException('仅未支付订单可改为已支付');
        }
        Db::name('scoreshop_order')->where('aid', $aid)->where('id', $orderId)->update([
            'status'  => 1,
            'paytime' => time(),
            'paytype' => '后台支付',
        ]);
    }

    /**
     * 删除订单
     *
     * @param array<int,int> $ids
     */
    public function orderDelete(int $aid, array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) {
            throw new BusinessException('请选择要删除的订单');
        }
        Db::name('scoreshop_order')->where('aid', $aid)->whereIn('id', $ids)->delete();
        Db::name('scoreshop_order_goods')->where('aid', $aid)->whereIn('orderid', $ids)->delete();
    }

    /**
     * 快递公司下拉
     *
     * @return array<int,array{code:string,name:string}>
     */
    public function expressOptions(): array
    {
        $list = [];
        foreach (\app\common\Kdniao::expressData() as $code => $info) {
            $list[] = ['code' => (string) $code, 'name' => (string) ($info['name'] ?? $code)];
        }
        return $list;
    }

    /**
     * 可分销会员等级
     *
     * @return array<int,array{id:int,name:string,can_agent:int}>
     */
    public function agentLevels(int $aid): array
    {
        $rows = Db::name('member_level')->where('aid', $aid)->where('can_agent', '>', 0)
            ->order('sort', 'asc')->order('id', 'asc')->field('id, name, can_agent')->select()->toArray();
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'can_agent' => (int) $r['can_agent'],
        ], $rows);
    }

    /**
     * 配送员列表（同城配送弹窗用）
     *
     * @return array<int,array{id:int,name:string,mobile:string,doing:int}>
     */
    public function deliveryStaff(int $aid): array
    {
        try {
            $rows = Db::name('peisong')->where('aid', $aid)->where('status', 1)->field('id, name, mobile')->select()->toArray();
        } catch (\Throwable $e) {
            $rows = [];
        }
        $out = [];
        foreach ($rows as $r) {
            $doing = (int) Db::name('scoreshop_order')->where('aid', $aid)
                ->where('mdid', (int) $r['id'])->where('status', self::STATUS_SHIPPED)->count();
            $out[] = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'mobile' => (string) $r['mobile'], 'doing' => $doing];
        }
        return $out;
    }

    /**
     * 物流轨迹（快递鸟）
     *
     * @return array{express_com:string,express_no:string,traces:array}
     */
    public function orderTrace(int $aid, int $orderId): array
    {
        $order = ScopeService::query('scoreshop_order', $aid)->where('id', $orderId)->find();
        if (empty($order)) {
            throw new BusinessException('订单不存在', 404, 404);
        }
        $expressNo = trim((string) ($order['express_no'] ?? ''));
        $expressCom = trim((string) ($order['express_com'] ?? ''));
        if ('' === $expressNo) {
            throw new BusinessException('该订单尚未发货，暂无物流轨迹');
        }
        $shipperCode = '';
        foreach (\app\common\Kdniao::expressData() as $code => $info) {
            if ('' !== $expressCom && (string) ($info['name'] ?? '') === $expressCom) {
                $shipperCode = (string) $code;
                break;
            }
        }
        if ('' === $shipperCode) {
            $shipperCode = $expressCom;
        }
        $kdniao = new \app\common\Kdniao($aid, 0);
        $res = $kdniao->trace($shipperCode, $expressNo, (string) ($order['ordernum'] ?? ''));
        $traces = [];
        if (1 === (int) ($res['status'] ?? 0)) {
            foreach ((array) ($res['data']['Traces'] ?? []) as $t) {
                $traces[] = ['time' => (string) ($t['AcceptTime'] ?? ''), 'station' => (string) ($t['AcceptStation'] ?? '')];
            }
        } else {
            $traces[] = ['time' => date('Y-m-d H:i:s'), 'station' => (string) ($res['message'] ?? '暂无轨迹')];
        }
        return ['express_com' => $expressCom, 'express_no' => $expressNo, 'traces' => $traces];
    }

    /**
     * 批量发货（Excel：订单号+快递单号）
     *
     * @param array<string,string> $rows
     * @return array{success:int,fail:int,fail_list:array}
     */
    public function orderBatchSend(int $aid, string $expressCom, array $rows): array
    {
        $success = 0;
        $failList = [];
        foreach ($rows as $ordernum => $expressNo) {
            $ordernum = trim((string) $ordernum);
            $expressNo = trim((string) $expressNo);
            if ('' === $ordernum || '' === $expressNo) { continue; }
            $order = ScopeService::query('scoreshop_order', $aid)->where('ordernum', $ordernum)->find();
            if (empty($order)) { $failList[] = $ordernum . '：订单不存在'; continue; }
            if ((int) $order['status'] !== self::STATUS_PAID) {
                $failList[] = $ordernum . '：仅已支付订单可发货';
                continue;
            }
            Db::name('scoreshop_order')->where('aid', $aid)->where('id', (int) $order['id'])->update([
                'express_com' => $expressCom,
                'express_no'  => $expressNo,
                'send_time'   => time(),
                'status'      => self::STATUS_SHIPPED,
            ]);
            $success++;
        }
        return ['success' => $success, 'fail' => count($failList), 'fail_list' => $failList];
    }

    /**
     * 送货单打印数据（按订单自动生成售货单）
     *
     * @return array<string, mixed>
     */
    public function orderShdData(int $aid, int $orderId): array
    {
        $order = ScopeService::query('scoreshop_order', $aid)->where('id', $orderId)->find();
        if (empty($order)) {
            throw new BusinessException('订单不存在', 404, 404);
        }

        $goodsRows = Db::name('scoreshop_order_goods')
            ->where('aid', $aid)
            ->where('orderid', $orderId)
            ->order('id', 'asc')
            ->select()
            ->toArray();

        $items = [];
        foreach ($goodsRows as $og) {
            $items[] = [
                'name'  => trim((string) $og['name'] . ((string) $og['ggname'] !== '' ? '（' . $og['ggname'] . '）' : '')),
                'num'   => (int) $og['num'],
                'price' => (float) $og['money_price'],
                'money' => (float) ($og['totalmoney'] ?? ($og['money_price'] * $og['num'])),
                'score' => (int) ($og['totalscore'] ?? 0),
            ];
        }

        $sysset = Db::name('shop_sysset')->where('aid', $aid)->field('shipping_pagetitle,shipping_pagenum,shipping_linenum')->find();

        $freightPrice = (float) ($order['freight_price'] ?? 0);
        $totalprice   = (float) ($order['totalprice'] ?? 0);

        return [
            'title'         => (string) ($sysset['shipping_pagetitle'] ?? '') !== '' ? (string) $sysset['shipping_pagetitle'] : '送货单',
            'pagenum'       => max(1, (int) ($sysset['shipping_pagenum'] ?? 8)),
            'linenum'       => max(0, (int) ($sysset['shipping_linenum'] ?? 2)),
            'ordernum'      => (string) $order['ordernum'],
            'linkman'       => (string) $order['linkman'],
            'tel'           => (string) $order['tel'],
            'address'       => trim((string) ($order['area'] ?? '') . ' ' . (string) $order['address']),
            'createtime'    => (int) $order['createtime'],
            'goods'         => $items,
            'freight_price' => $freightPrice,
            'totalprice'    => $totalprice,
            'totalscore'    => (int) ($order['totalscore'] ?? 0),
            'total_upper'   => $this->moneyUpper($totalprice),
            'message'       => (string) ($order['message'] ?? ''),
        ];
    }
    /**
     * 送货单打印数据
     *
     * @param array<int,int> $orderIds
     * @return array<int,array<string,mixed>>
     */
    public function orderSonghuo(int $aid, array $orderIds): array
    {
        $orderIds = array_values(array_filter(array_map('intval', $orderIds)));
        if (empty($orderIds)) { throw new BusinessException('请选择订单'); }
        $rows = ScopeService::query('scoreshop_order', $aid)->whereIn('id', $orderIds)->select()->toArray();
        return array_map(static function (array $o): array {
            return [
                'ordernum'    => (string) $o['ordernum'],
                'title'       => (string) $o['title'],
                'totalnum'    => (int) $o['totalnum'],
                'linkman'     => (string) $o['linkman'],
                'tel'         => (string) $o['tel'],
                'address'     => trim((string) $o['area'] . (string) $o['address']),
                'totalscore'  => (int) $o['totalscore'],
                'totalprice'  => (float) $o['totalprice'],
                'express_com' => (string) ($o['express_com'] ?? ''),
                'express_no'  => (string) ($o['express_no'] ?? ''),
                'createtime'  => (int) $o['createtime'],
            ];
        }, $rows);
    }

    /* ================= 内部辅助 ================= */

    /**
     * @param array<string,mixed>|null $value
     */
    protected function encodeJson($value): string
    {
        if (null === $value || '' === $value || [] === (array) $value) {
            return '';
        }
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array<string,mixed>
     */
    protected function decodeJson(string $value): array
    {
        $value = trim($value);
        if ('' === $value) {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<int,mixed> $tj
     */
    protected function encodeTj(array $tj): string
    {
        $tj = array_values(array_map('strval', $tj));
        $tj = array_filter($tj, static fn ($v) => '' !== $v);
        if (empty($tj)) {
            return '-1';
        }
        if (in_array('-1', $tj, true)) {
            return '-1';
        }
        return implode(',', $tj);
    }

    /**
     * @return array<int,string>
     */
    protected function decodeTj(string $value): array
    {
        $value = trim($value);
        if ('' === $value || '-1' === $value) {
            return ['-1'];
        }
        return array_values(explode(',', $value));
    }

    /**
     * 金额归一：保留两位（round(x*100)/100 口径）
     */
    protected function money(mixed $value): float
    {
        return round((float) $value * 100) / 100;
    }

    /**
     * 金额大写（人民币）
     */
    protected function moneyUpper(float $money): string
    {
        $money = round($money, 2);
        if ($money <= 0) { return '零元整'; }
        $digits = ['零', '壹', '贰', '叁', '肆', '伍', '陆', '柒', '捌', '玖'];
        $units  = ['分', '角', '元', '拾', '佰', '仟', '万', '拾', '佰', '仟', '亿'];
        $str    = number_format($money, 2, '.', '');
        $parts  = explode('.', $str);
        $zheng  = (int) $parts[0];
        $fen    = (int) ($parts[1] ?? 0);
        $result = '';
        if ($zheng > 0) {
            $zs = (string) $zheng;
            $len = strlen($zs);
            for ($i = 0; $i < $len; ++$i) {
                $n = (int) $zs[$i];
                $u = $units[$len - $i + 1];
                $result .= $digits[$n] . $u;
            }
            $result .= '整';
        }
        if ($fen > 0) {
            $jiao = intdiv($fen, 10);
            $fc   = $fen % 10;
            if ($jiao > 0) { $result .= $digits[$jiao] . '角'; }
            if ($fc > 0)   { $result .= $digits[$fc] . '分'; }
        }
        return '' !== $result ? $result : '零元整';
    }
}
