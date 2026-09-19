<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;

/**
 * 通用收银台服务（批次6-T6-5 一期，bid=0 平台自营）
 *
 * 参考 canzhao/app/controller/Cashier.php + CashierOrder.php + CashierLogin.php（只读），
 * 一期裁剪：优惠券/积分抵扣/余额抵扣/挂账/随行付/汇付/麒麟 全部不做；
 * 优惠口径简化为「会员等级折扣 + 抹零」。
 *
 * 表口径：
 * - restaurant_cashdesk             收银台配置（支付开关/抹零位数/交班打印机，bid=0 单行）
 * - restaurant_cashdesk_custom_pay  自定义支付方式字典（字段名 title，非 name）
 * - cashier_order                   收银订单（status 0结算等待中 1已完成 2挂单 10已退款；
 *                                   refund_status 0无 1审核中 2已同意 3已驳回）
 * - cashier_order_goods             订单商品（protype 1商品 2直接收款proid=-99；is_gj 改价标记）
 * - payorder                        统一支付单（type=cashier）
 * - member / member_moneylog        余额支付直接操作余额并写流水（paytype=cashier）
 *
 * 支付方式 paytypeid 口径（一期）：
 * - 1  微信扫码/付款码（付款码走 mock 通道）
 * - 3  现金
 * - 4  余额
 * - 100+字典id  自定义支付（restaurant_cashdesk_custom_pay）
 */
class CashierService
{
    public const PAY_WECHAT = 1;
    public const PAY_CASH   = 3;
    public const PAY_MONEY  = 4;

    /** 自定义支付 paytypeid = 100 + 字典id */
    public const PAY_CUSTOM_BASE = 100;

    public const ORDER_WAIT    = 0;
    public const ORDER_PAID    = 1;
    public const ORDER_HANGUP  = 2;
    public const ORDER_REFUND  = 10;

    public const BID = 0;

    /* ================= 收银设置 ================= */

    /**
     * 取收银台配置行（不存在则建默认行）
     */
    public function deskRow(int $aid): array
    {
        $row = ScopeService::query('restaurant_cashdesk', $aid)
            ->where('bid', self::BID)->order('id', 'asc')->find();

        if (empty($row)) {
            $id = Db::name('restaurant_cashdesk')->insertGetId([
                'aid'                => $aid,
                'bid'                => self::BID,
                'name'               => '收银台',
                'createtime'         => time(),
                'updatetime'         => time(),
                'remove_zero_length' => 0,
                'wxpay'              => 0,
                'cashpay'            => 1,
                'moneypay'           => 1,
            ]);
            $row = Db::name('restaurant_cashdesk')->where('id', $id)->find();
        }

        return $row;
    }

    /**
     * 收银设置详情（配置 + 自定义支付字典 + 可用支付方式列表）
     */
    public function deskConfig(int $aid): array
    {
        $row = $this->deskRow($aid);

        return [
            'id'                   => (int) $row['id'],
            'name'                 => (string) ($row['name'] ?? '收银台'),
            'wxpay'                => (int) ($row['wxpay'] ?? 0) ? 1 : 0,
            'cashpay'              => (int) ($row['cashpay'] ?? 0) ? 1 : 0,
            'moneypay'             => (int) ($row['moneypay'] ?? 0) ? 1 : 0,
            'remove_zero_length'   => (int) ($row['remove_zero_length'] ?? 0),
            'paypwd_use_status'    => (int) ($row['paypwd_use_status'] ?? 0) ? 1 : 0,
            'jiaoban_print_ids'    => (string) ($row['jiaoban_print_ids'] ?? ''),
            'custom_pays'          => $this->customPayList($aid, false),
        ];
    }

    /**
     * 保存收银设置
     */
    public function saveDeskConfig(int $aid, array $data): void
    {
        $row = $this->deskRow($aid);

        $length = (int) ($data['remove_zero_length'] ?? 0);
        if ($length < 0 || $length > 3) {
            throw new BusinessException('抹零位数取值 0-3');
        }

        Db::name('restaurant_cashdesk')->where('id', (int) $row['id'])->update([
            'name'               => trim((string) ($data['name'] ?? $row['name'])),
            'wxpay'              => (int) ($data['wxpay'] ?? 0) === 1 ? 1 : 0,
            'cashpay'            => (int) ($data['cashpay'] ?? 0) === 1 ? 1 : 0,
            'moneypay'           => (int) ($data['moneypay'] ?? 0) === 1 ? 1 : 0,
            'remove_zero_length' => $length,
            'paypwd_use_status'  => (int) ($data['paypwd_use_status'] ?? 0) === 1 ? 1 : 0,
            'jiaoban_print_ids'  => trim((string) ($data['jiaoban_print_ids'] ?? '')),
            'updatetime'         => time(),
        ]);
    }

    /**
     * 自定义支付方式字典列表
     */
    public function customPayList(int $aid, bool $onlyEnable = true): array
    {
        $query = ScopeService::query('restaurant_cashdesk_custom_pay', $aid)
            ->where('bid', self::BID);
        if ($onlyEnable) {
            $query->where('status', 1);
        }

        $rows = $query->order('sort', 'desc')->order('id', 'asc')->select()->toArray();

        return array_map(fn (array $v): array => [
            'id'         => (int) $v['id'],
            'title'      => (string) ($v['title'] ?? ''),
            'sort'       => (int) ($v['sort'] ?? 0),
            'status'     => (int) ($v['status'] ?? 1),
            'paytypeid'  => self::PAY_CUSTOM_BASE + (int) $v['id'],
            'createtime' => (int) ($v['createtime'] ?? 0),
        ], $rows);
    }

    /**
     * 新增/编辑自定义支付方式
     *
     * @return array{id: int}
     */
    public function customPaySave(int $aid, array $data): array
    {
        $id    = (int) ($data['id'] ?? 0);
        $title = trim((string) ($data['title'] ?? ''));
        if ('' === $title) {
            throw new BusinessException('请填写支付方式名称');
        }
        if (mb_strlen($title) > 30) {
            throw new BusinessException('支付方式名称过长');
        }

        $dup = ScopeService::query('restaurant_cashdesk_custom_pay', $aid)
            ->where('bid', self::BID)->where('title', $title);
        if ($id > 0) {
            $dup->where('id', '<>', $id);
        }
        if ($dup->find()) {
            throw new BusinessException('同名支付方式已存在');
        }

        $fields = [
            'title'  => $title,
            'sort'   => (int) ($data['sort'] ?? 0),
            'status' => (int) ($data['status'] ?? 1) === 1 ? 1 : 0,
        ];

        if ($id > 0) {
            $exists = ScopeService::query('restaurant_cashdesk_custom_pay', $aid)
                ->where('bid', self::BID)->where('id', $id)->find();
            if (empty($exists)) {
                throw new BusinessException('支付方式不存在', 404, 404);
            }
            Db::name('restaurant_cashdesk_custom_pay')->where('id', $id)->update($fields);
        } else {
            $fields['aid']        = $aid;
            $fields['bid']        = self::BID;
            $fields['createtime'] = time();
            $id                   = (int) Db::name('restaurant_cashdesk_custom_pay')->insertGetId($fields);
        }

        return ['id' => $id];
    }

    /**
     * 删除自定义支付方式
     */
    public function customPayDelete(int $aid, int $id): void
    {
        $affected = ScopeService::query('restaurant_cashdesk_custom_pay', $aid)
            ->where('bid', self::BID)->where('id', $id)->delete();

        if ($affected < 1) {
            throw new BusinessException('支付方式不存在', 404, 404);
        }
    }

    /**
     * 支付方式名称映射（统计/交接班拆分明细用）
     *
     * @return array<int, string>
     */
    public function payTypeMap(int $aid): array
    {
        $map = [
            self::PAY_WECHAT => '微信支付',
            self::PAY_CASH   => '现金',
            self::PAY_MONEY  => '余额',
        ];
        foreach ($this->customPayList($aid, false) as $v) {
            $map[$v['paytypeid']] = $v['title'];
        }

        return $map;
    }

    /**
     * 收银台可用支付方式列表（结算弹窗用，对标 canzhao getPayList）
     */
    public function payList(int $aid): array
    {
        $desk = $this->deskRow($aid);
        $list = [];

        if ((int) ($desk['wxpay'] ?? 0) === 1) {
            $list[] = [
                'value'   => (string) self::PAY_WECHAT,
                'lable'   => '微信扫码/付款码',
                'tip'     => '请扫描微信付款码收款（一期为模拟通道，扫码后直接确认收款）',
                'need_authcode' => 1,
            ];
        }
        if ((int) ($desk['cashpay'] ?? 0) === 1) {
            $list[] = ['value' => (string) self::PAY_CASH, 'lable' => '现金', 'tip' => '', 'need_authcode' => 0];
        }
        if ((int) ($desk['moneypay'] ?? 0) === 1) {
            $list[] = ['value' => (string) self::PAY_MONEY, 'lable' => '余额', 'tip' => '余额支付需先绑定会员', 'need_authcode' => 0];
        }
        foreach ($this->customPayList($aid) as $v) {
            $list[] = [
                'value'   => (string) $v['paytypeid'],
                'lable'   => $v['title'],
                'tip'     => '',
                'need_authcode' => 0,
            ];
        }

        return $list;
    }

    /* ================= 商品与会员 ================= */

    /**
     * 商品分类（收银台左侧筛选）
     */
    public function categoryList(int $aid): array
    {
        $rows = ScopeService::query('shop_category', $aid)
            ->where('status', 1)
            ->field('id,pid,name,sort')
            ->order('sort', 'desc')->order('id', 'asc')
            ->select()->toArray();

        return array_map(fn (array $v): array => [
            'id'   => (int) $v['id'],
            'pid'  => (int) ($v['pid'] ?? 0),
            'name' => (string) ($v['name'] ?? ''),
        ], $rows);
    }

    /**
     * 商品列表（已上架+已审核，支持名称/编码/条码搜索与分类过滤，带规格）
     */
    public function productList(int $aid, array $f, int $page, int $pageSize): array
    {
        $keyword = trim((string) ($f['keyword'] ?? ''));
        $cid     = (int) ($f['cid'] ?? 0);

        $query = ScopeService::queryAs('shop_product', $aid, 'p')
            ->where('p.bid', self::BID)
            ->where('p.status', 1)
            ->where('p.ischecked', 1);

        if (array_key_exists('deleted', Db::name('shop_product')->getTableFields())) {
            $query->where('p.deleted', 0);
        }

        if ('' !== $keyword) {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('p.name', '%' . $keyword . '%')
                    ->whereOr('p.procode', 'like', '%' . $keyword . '%')
                    ->whereOr('p.barcode', 'like', '%' . $keyword . '%');
            });
        }
        if ($cid > 0) {
            // cid 为逗号分隔多分类串，取自身+子分类
            $cids   = [$cid];
            $child1 = ScopeService::query('shop_category', $aid)->where('pid', $cid)->column('id');
            if ($child1) {
                $cids   = array_merge($cids, $child1);
                $child2 = ScopeService::query('shop_category', $aid)->where('pid', 'in', $child1)->column('id');
                $cids   = array_merge($cids, $child2);
            }
            $raw = [];
            foreach (array_unique($cids) as $c) {
                $raw[] = 'find_in_set(' . (int) $c . ',p.cid)';
            }
            $query->whereRaw(implode(' or ', $raw));
        }

        $total = (clone $query)->count();
        $rows  = $query->field('p.id,p.name,p.pic,p.sell_price,p.market_price,p.cost_price,p.stock,p.cid,p.perlimitdan,p.limit_start')
            ->order('p.sort', 'desc')->order('p.id', 'desc')
            ->page($page, $pageSize)->select()->toArray();

        $list = [];
        foreach ($rows as $v) {
            $guiges = Db::name('shop_guige')->where('aid', $aid)->where('proid', (int) $v['id'])
                ->field('id,name,pic,sell_price,market_price,cost_price,stock,barcode,ks')
                ->order('id', 'asc')->select()->toArray();

            $ggList = [];
            $totalStock = 0;
            foreach ($guiges as $g) {
                $ggList[] = [
                    'id'         => (int) $g['id'],
                    'name'       => (string) ($g['name'] ?? ''),
                    'pic'        => (string) ($g['pic'] ?? ''),
                    'sell_price' => (float) ($g['sell_price'] ?: $v['sell_price']),
                    'cost_price' => (float) ($g['cost_price'] ?: $v['cost_price']),
                    'stock'      => (int) ($g['stock'] ?? 0),
                    'barcode'    => (string) ($g['barcode'] ?? ''),
                ];
                $totalStock += (int) ($g['stock'] ?? 0);
            }
            if (empty($ggList)) {
                $totalStock = (int) ($v['stock'] ?? 0);
            }

            $list[] = [
                'id'           => (int) $v['id'],
                'name'         => (string) ($v['name'] ?? ''),
                'pic'          => (string) ($v['pic'] ?? ''),
                'sell_price'   => (float) ($v['sell_price'] ?? 0),
                'cost_price'   => (float) ($v['cost_price'] ?? 0),
                'stock'        => $totalStock,
                'perlimitdan'  => (int) ($v['perlimitdan'] ?? 0),
                'limit_start'  => (int) ($v['limit_start'] ?? 0),
                'guige_num'    => max(1, count($ggList)),
                'guigelist'    => $ggList,
            ];
        }

        return ['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize];
    }

    /**
     * 会员搜索（ID/手机号/昵称，对标 canzhao searchMember 一期简化）
     */
    public function searchMember(int $aid, string $keyword): array
    {
        $keyword = trim($keyword);
        if ('' === $keyword) {
            throw new BusinessException('请输入会员ID/手机号/昵称');
        }

        $query = ScopeService::query('member', $aid);
        if (ctype_digit($keyword)) {
            $query->where(function ($q) use ($keyword) {
                $q->where('id', (int) $keyword)
                    ->whereOr('tel', 'like', '%' . $keyword . '%');
            });
        } else {
            $query->whereLike('nickname', '%' . $keyword . '%');
        }

        $rows = $query->field('id,nickname,realname,tel,headimg,money,score,levelid')
            ->order('id', 'desc')->limit(20)->select()->toArray();

        return array_map(fn (array $v): array => $this->formatMember($aid, $v), $rows);
    }

    /**
     * 会员信息格式化（含等级名与折扣）
     */
    public function formatMember(int $aid, array $member): array
    {
        $level = null;
        if ((int) ($member['levelid'] ?? 0) > 0) {
            $level = Db::name('member_level')->where('aid', $aid)
                ->where('id', (int) $member['levelid'])->find();
        }

        $discount = 10.0;
        if (null !== $level && (float) ($level['discount'] ?? 0) > 0 && (float) ($level['discount'] ?? 0) < 10) {
            $discount = (float) $level['discount'];
        }

        return [
            'id'         => (int) ($member['id'] ?? 0),
            'nickname'   => (string) ($member['nickname'] ?? ''),
            'realname'   => (string) ($member['realname'] ?? ''),
            'tel'        => (string) ($member['tel'] ?? ''),
            'headimg'    => (string) ($member['headimg'] ?? ''),
            'money'      => round((float) ($member['money'] ?? 0), 2),
            'score'      => (int) ($member['score'] ?? 0),
            'levelid'    => (int) ($member['levelid'] ?? 0),
            'levelname'  => null !== $level ? (string) ($level['name'] ?? '') : '',
            'discount'   => $discount,
        ];
    }

    /* ================= 购物车（待结算订单） ================= */

    /**
     * 取当前待结算订单（status=0，每收银台同时仅一单，对标 canzhao getWaitOrder）
     */
    public function getWaitOrder(int $aid): ?array
    {
        $desk = $this->deskRow($aid);

        $order = ScopeService::query('cashier_order', $aid)
            ->where('cashier_id', (int) $desk['id'])
            ->where('status', self::ORDER_WAIT)
            ->order('id', 'desc')->find();

        return $order ?: null;
    }

    /**
     * 生成订单号（对标 canzhao：C + ymdHis + 6位随机）
     */
    protected function genOrdernum(): string
    {
        return 'C' . date('ymdHis') . random_int(100000, 999999);
    }

    /**
     * 加入收银台（商品加购 / 直接收款，对标 canzhao addToCashier）
     *
     * @param array<string, mixed> $data {proid, ggid, num, barcode, price(直接收款金额)}
     */
    public function addToCashier(int $aid, array $data): array
    {
        $desk    = $this->deskRow($aid);
        $proid   = (int) ($data['proid'] ?? 0);
        $ggid    = (int) ($data['ggid'] ?? 0);
        $num     = (int) ($data['num'] ?? 1);
        $barcode = trim((string) ($data['barcode'] ?? ''));
        $price   = $data['price'] ?? null;

        if ($num < 1) {
            $num = 1;
        }

        // 条码定位商品/规格
        if ('' !== $barcode) {
            $found = $this->findByBarcode($aid, $barcode);
            if (empty($found)) {
                throw new BusinessException('未查询到相关商品');
            }
            [$proid, $ggid] = $found;
            $num = 1;
        }

        $order = $this->getWaitOrder($aid);
        if (empty($order)) {
            $orderId = (int) Db::name('cashier_order')->insertGetId([
                'aid'        => $aid,
                'bid'        => self::BID,
                'ordernum'   => $this->genOrdernum(),
                'cashier_id' => (int) $desk['id'],
                'createtime' => time(),
                'status'     => self::ORDER_WAIT,
            ]);
            $order = Db::name('cashier_order')->where('id', $orderId)->find();
        }
        $orderId  = (int) $order['id'];
        $ordernum = (string) $order['ordernum'];

        // 直接收款（无商品，proid=-99 protype=2）
        if (-99 === $proid || 2 === (int) ($data['protype'] ?? 0)) {
            $price = round((float) $price, 2);
            if ($price <= 0) {
                throw new BusinessException('请输入收款金额');
            }
            $count = Db::name('cashier_order_goods')->where('orderid', $orderId)->where('protype', 2)->count();
            Db::name('cashier_order_goods')->insert([
                'aid'        => $aid,
                'bid'        => self::BID,
                'orderid'    => $orderId,
                'ordernum'   => $ordernum,
                'proid'      => '-99',
                'proid2'     => '0',
                'proname'    => '直接收款',
                'propic'     => '',
                'protype'    => 2,
                'sell_price' => $price,
                'cost_price' => 0,
                'num'        => 1,
                'totalprice' => $price,
                'pre_price'  => $price,
                'createtime' => time(),
                'barcode'    => $ordernum . '_' . ($count + 1),
            ]);

            return ['orderid' => $orderId];
        }

        if ($proid <= 0) {
            throw new BusinessException('请选择商品');
        }

        $product = ScopeService::query('shop_product', $aid)
            ->where('bid', self::BID)->where('id', $proid)
            ->where('status', '<>', 0)->where('ischecked', 1)->find();
        if (empty($product)) {
            throw new BusinessException('商品不存在或已下架');
        }

        // 规格定位
        $guiges = Db::name('shop_guige')->where('aid', $aid)->where('proid', $proid)
            ->order('id', 'asc')->select()->toArray();
        $guige = null;
        if (count($guiges) > 1) {
            if ($ggid <= 0) {
                throw new BusinessException('请选择购买的商品规格');
            }
            foreach ($guiges as $g) {
                if ((int) $g['id'] === $ggid) {
                    $guige = $g;
                    break;
                }
            }
            if (null === $guige) {
                throw new BusinessException('商品规格不存在');
            }
        } elseif (count($guiges) === 1) {
            $guige = $guiges[0];
            $ggid  = (int) $guige['id'];
        }

        if (null !== $guige && (int) ($guige['stock'] ?? 0) < $num) {
            throw new BusinessException((string) ($product['name'] ?? '') . '库存不足');
        }

        $sellPrice = (null !== $guige && (float) ($guige['sell_price'] ?? 0) > 0)
            ? (float) $guige['sell_price']
            : (float) ($product['sell_price'] ?? 0);
        $costPrice = (null !== $guige && (float) ($guige['cost_price'] ?? 0) > 0)
            ? (float) $guige['cost_price']
            : (float) ($product['cost_price'] ?? 0);

        // 每单限购
        $perlimitdan = (int) ($product['perlimitdan'] ?? 0);

        // 同商品同规格合并数量
        $exists = Db::name('cashier_order_goods')->where('orderid', $orderId)
            ->where('proid', (string) $proid)->where('ggid', $ggid)->find();

        $newNum = empty($exists) ? $num : (int) $exists['num'] + $num;
        if ($perlimitdan > 0 && $newNum > $perlimitdan) {
            throw new BusinessException((string) ($product['name'] ?? '') . '每单限购' . $perlimitdan . '份');
        }

        $goodsData = [
            'aid'        => $aid,
            'bid'        => self::BID,
            'orderid'    => $orderId,
            'ordernum'   => $ordernum,
            'proid'      => (string) $proid,
            'proid2'     => (string) $proid,
            'proname'    => (string) ($product['name'] ?? ''),
            'propic'     => (null !== $guige && '' !== (string) ($guige['pic'] ?? '')) ? (string) $guige['pic'] : (string) ($product['pic'] ?? ''),
            'protype'    => 1,
            'ggid'       => $ggid,
            'ggname'     => null !== $guige ? (string) ($guige['name'] ?? '') : '',
            'barcode'    => null !== $guige ? (string) ($guige['barcode'] ?? '') : (string) ($product['barcode'] ?? ''),
            'sell_price' => $sellPrice,
            'cost_price' => $costPrice,
            'num'        => $newNum,
            'totalprice' => round($sellPrice * $newNum, 2),
            'pre_price'  => $sellPrice,
        ];

        if (empty($exists)) {
            $goodsData['createtime'] = time();
            Db::name('cashier_order_goods')->insert($goodsData);
        } else {
            Db::name('cashier_order_goods')->where('id', (int) $exists['id'])->update($goodsData);
        }

        return ['orderid' => $orderId];
    }

    /**
     * 条码定位 [proid, ggid]
     *
     * @return array{0: int, 1: int}|null
     */
    protected function findByBarcode(int $aid, string $barcode): ?array
    {
        // 规格条码优先
        $gg = Db::name('shop_guige')->alias('g')
            ->join('shop_product p', 'p.id = g.proid')
            ->where('g.aid', $aid)->where('p.bid', self::BID)
            ->where('g.barcode', $barcode)
            ->field('g.proid,g.id')->find();
        if (!empty($gg)) {
            return [(int) $gg['proid'], (int) $gg['id']];
        }

        // 商品条码/编码
        $pro = ScopeService::query('shop_product', $aid)->where('bid', self::BID)
            ->where(function ($q) use ($barcode) {
                $q->where('barcode', $barcode)->whereOr('procode', $barcode);
            })->find();
        if (empty($pro)) {
            return null;
        }
        $ggid = (int) (Db::name('shop_guige')->where('aid', $aid)
            ->where('proid', (int) $pro['id'])->order('id', 'asc')->value('id') ?? 0);

        return [(int) $pro['id'], $ggid];
    }

    /**
     * 待结算订单视图（商品行 + 合计 + 抹零预览，对标 canzhao getWaitPayOrder）
     */
    public function waitOrderView(int $aid, int $removeZero, int $mid = 0): array
    {
        $desk  = $this->deskRow($aid);
        $order = $this->getWaitOrder($aid);

        if (empty($order)) {
            return [
                'has_order'          => false,
                'orderid'            => 0,
                'ordernum'           => '',
                'prolist'            => [],
                'totalnum'           => 0,
                'totalprice'         => 0.0,
                'discount_money'     => 0.0,
                'remove_zero'        => $removeZero,
                'remove_zero_length' => (int) ($desk['remove_zero_length'] ?? 0),
                'mid'                => 0,
                'remark'             => '',
            ];
        }

        $orderId = (int) $order['id'];

        // 抹零开关变化时落库
        if ((int) $order['remove_zero'] !== $removeZero) {
            Db::name('cashier_order')->where('id', $orderId)->update([
                'remove_zero'        => $removeZero,
                'remove_zero_length' => (int) ($desk['remove_zero_length'] ?? 0),
            ]);
            $order['remove_zero'] = $removeZero;
        }

        $goods = Db::name('cashier_order_goods')->where('orderid', $orderId)
            ->order('createtime', 'asc')->order('id', 'asc')->select()->toArray();

        $totalPrice = 0.0;
        $totalNum   = 0;
        $list       = [];
        foreach ($goods as $g) {
            $stock = 0;
            if (1 === (int) $g['protype'] && (int) $g['ggid'] > 0) {
                $stock = (int) (Db::name('shop_guige')->where('aid', $aid)
                    ->where('id', (int) $g['ggid'])->value('stock') ?? 0);
            }

            $sellPrice = (float) $g['sell_price'];
            $gtotal    = round($sellPrice * (int) $g['num'], 2);
            $totalPrice += $gtotal;
            $totalNum   += (int) $g['num'];

            $list[] = [
                'id'         => (int) $g['id'],
                'proid'      => (string) ($g['proid'] ?? ''),
                'proname'    => (string) ($g['proname'] ?? ''),
                'propic'     => (string) ($g['propic'] ?? ''),
                'protype'    => (int) ($g['protype'] ?? 1),
                'ggid'       => (int) ($g['ggid'] ?? 0),
                'ggname'     => (string) ($g['ggname'] ?? ''),
                'sell_price' => $sellPrice,
                'num'        => (int) $g['num'],
                'totalprice' => $gtotal,
                'is_gj'      => (int) ($g['is_gj'] ?? 0),
                'stock'      => $stock,
            ];
        }
        $totalPrice = round($totalPrice, 2);

        $discountMoney = 0.0;
        if (1 === $removeZero) {
            $zeroinfo      = $this->removeZero($totalPrice, (int) ($desk['remove_zero_length'] ?? 1));
            $totalPrice    = $zeroinfo['totalprice'];
            $discountMoney = $zeroinfo['moling_money'];
        }

        return [
            'has_order'          => true,
            'orderid'            => $orderId,
            'ordernum'           => (string) ($order['ordernum'] ?? ''),
            'prolist'            => $list,
            'totalnum'           => $totalNum,
            'totalprice'         => $totalPrice,
            'discount_money'     => $discountMoney,
            'remove_zero'        => $removeZero,
            'remove_zero_length' => (int) ($desk['remove_zero_length'] ?? 0),
            'mid'                => (int) ($order['mid'] ?? $mid),
            'remark'             => (string) ($order['remark'] ?? ''),
        ];
    }

    /**
     * 修改商品数量（num<1 即删除该行）
     */
    public function changeNum(int $aid, int $goodsId, int $num): void
    {
        $order = $this->getWaitOrder($aid);
        if (empty($order)) {
            throw new BusinessException('没有待结算订单不支持该操作');
        }

        $og = Db::name('cashier_order_goods')->where('orderid', (int) $order['id'])
            ->where('id', $goodsId)->find();
        if (empty($og)) {
            throw new BusinessException('数据有误');
        }

        if (1 === (int) $og['protype']) {
            $perlimitdan = (int) (ScopeService::query('shop_product', $aid)
                ->where('id', (int) $og['proid'])->value('perlimitdan') ?? 0);
            if ($perlimitdan > 0 && $num > $perlimitdan) {
                throw new BusinessException((string) $og['proname'] . '每单限购' . $perlimitdan . '份');
            }
        }

        if ($num < 1) {
            Db::name('cashier_order_goods')->where('id', $goodsId)->delete();
        } else {
            Db::name('cashier_order_goods')->where('id', $goodsId)->update([
                'num'        => $num,
                'totalprice' => round((float) $og['sell_price'] * $num, 2),
            ]);
        }
    }

    /**
     * 改价（is_gj=1，改价行不再参与会员折扣）
     */
    public function changePrice(int $aid, int $goodsId, float $price): void
    {
        $order = $this->getWaitOrder($aid);
        if (empty($order)) {
            throw new BusinessException('没有待结算订单不支持该操作');
        }
        if ($price < 0) {
            throw new BusinessException('价格不能为负数');
        }

        $affected = Db::name('cashier_order_goods')
            ->where('orderid', (int) $order['id'])->where('id', $goodsId)
            ->update([
                'sell_price' => $price,
                'is_gj'      => 1,
                'totalprice' => round($price * (int) (Db::name('cashier_order_goods')->where('id', $goodsId)->value('num') ?? 0), 2),
            ]);
        if ($affected < 1) {
            throw new BusinessException('数据有误');
        }
    }

    /**
     * 修改订单备注
     */
    public function changeRemark(int $aid, int $orderId, string $remark): void
    {
        $affected = ScopeService::query('cashier_order', $aid)
            ->where('bid', self::BID)->where('id', $orderId)
            ->update(['remark' => mb_substr($remark, 0, 200)]);
        if ($affected < 1) {
            throw new BusinessException('订单不存在', 404, 404);
        }
    }

    /**
     * 删除购物车商品行
     */
    public function delGoods(int $aid, int $goodsId): void
    {
        $order = $this->getWaitOrder($aid);
        if (empty($order)) {
            throw new BusinessException('没有待结算订单不支持该操作');
        }

        Db::name('cashier_order_goods')
            ->where('orderid', (int) $order['id'])->where('id', $goodsId)->delete();
    }

    /**
     * 清空购物车（删除空壳订单）
     */
    public function clearCart(int $aid): void
    {
        $order = $this->getWaitOrder($aid);
        if (empty($order)) {
            return;
        }
        Db::name('cashier_order_goods')->where('orderid', (int) $order['id'])->delete();
        Db::name('cashier_order')->where('id', (int) $order['id'])->delete();
    }

    /* ================= 挂单/取单 ================= */

    /**
     * 挂单（status 0→2）
     */
    public function hangup(int $aid, int $mid = 0): void
    {
        $order = $this->getWaitOrder($aid);
        if (empty($order)) {
            throw new BusinessException('暂无可挂订单');
        }
        if (0 === (int) Db::name('cashier_order_goods')->where('orderid', (int) $order['id'])->count()) {
            throw new BusinessException('购物车为空，无需挂单');
        }

        $up = ['status' => self::ORDER_HANGUP, 'hangup_time' => time()];
        if ($mid > 0) {
            $up['mid'] = $mid;
            Db::name('cashier_order_goods')->where('orderid', (int) $order['id'])->update(['mid' => $mid]);
        }
        Db::name('cashier_order')->where('id', (int) $order['id'])->update($up);
    }

    /**
     * 取单（status 2→0；当前有待结算且非空则拒绝）
     */
    public function cancelHangup(int $aid, int $orderId): void
    {
        $order = ScopeService::query('cashier_order', $aid)
            ->where('bid', self::BID)->where('id', $orderId)->find();
        if (empty($order)) {
            throw new BusinessException('无效订单');
        }

        $wait = $this->getWaitOrder($aid);
        if (!empty($wait)) {
            $count = (int) Db::name('cashier_order_goods')->where('orderid', (int) $wait['id'])->count();
            if ($count < 1) {
                Db::name('cashier_order')->where('id', (int) $wait['id'])->delete();
            } else {
                throw new BusinessException('请先结算当前订单后再取单');
            }
        }

        if (self::ORDER_HANGUP !== (int) $order['status']) {
            throw new BusinessException('订单状态有误');
        }

        Db::name('cashier_order')->where('id', $orderId)->update(['status' => self::ORDER_WAIT]);
    }

    /**
     * 挂单列表
     */
    public function hangupList(int $aid): array
    {
        $rows = ScopeService::query('cashier_order', $aid)
            ->where('bid', self::BID)->where('status', self::ORDER_HANGUP)
            ->order('hangup_time', 'desc')->select()->toArray();

        $list = [];
        foreach ($rows as $o) {
            $goods = Db::name('cashier_order_goods')->where('orderid', (int) $o['id'])->select()->toArray();
            $total = 0.0;
            $num   = 0;
            foreach ($goods as $g) {
                $total += round((float) $g['sell_price'] * (int) $g['num'], 2);
                $num   += (int) $g['num'];
            }
            $list[] = [
                'id'          => (int) $o['id'],
                'ordernum'    => (string) ($o['ordernum'] ?? ''),
                'totalprice'  => round($total, 2),
                'totalnum'    => $num,
                'mid'         => (int) ($o['mid'] ?? 0),
                'hangup_time' => (int) ($o['hangup_time'] ?? 0),
                'remark'      => (string) ($o['remark'] ?? ''),
            ];
        }

        return $list;
    }

    /* ================= 计价（会员折扣 + 抹零） ================= */

    /**
     * 抹零（对标 canzhao removeZero：length 1抹分 2抹角 3抹元；<100 不支持抹元）
     *
     * @return array{totalprice: float, moling_money: float}
     */
    public function removeZero(float $total, int $zeroLength): array
    {
        $totalStr   = sprintf('%.2f', $total);
        $discount   = 0.0;

        if ($zeroLength <= 0) {
            return ['totalprice' => round($total, 2), 'moling_money' => 0.0];
        }

        $len = $zeroLength;
        // 小于100不支持整数位抹零
        if ((float) $totalStr < 100) {
            $len = min(2, $len);
        }
        if (strlen($totalStr) - 1 <= $len) {
            $len = 2;
        }

        if ($len > 0 && $len <= 2) {
            $tail     = substr($totalStr, 0 - $len);
            $discount = round((float) $tail / 100, 2);
        } elseif ($len > 2) {
            $discount = (float) substr($totalStr, 0 - ($len + 1));
        }

        $discount = round($discount, 2);
        $after    = round($total - $discount, 2);

        return ['totalprice' => max(0.0, $after), 'moling_money' => $discount];
    }

    /**
     * 结算计价（一期：会员等级折扣 + 抹零，优惠券/积分裁剪）
     *
     * @return array{
     *   pre_totalprice: float, totalprice: float, leveldk_money: float,
     *   moling_money: float, discount: float
     * }
     */
    public function calcOrderPrice(int $aid, array $order, int $mid, int $removeZero): array
    {
        $goods = Db::name('cashier_order_goods')->where('orderid', (int) $order['id'])
            ->select()->toArray();
        if (empty($goods)) {
            throw new BusinessException('无待结算商品');
        }

        $preTotal = 0.0;
        foreach ($goods as $og) {
            $preTotal += round((float) $og['sell_price'] * (int) $og['num'], 2);
            Db::name('cashier_order_goods')->where('id', (int) $og['id'])
                ->update(['totalprice' => round((float) $og['sell_price'] * (int) $og['num'], 2)]);
        }
        $preTotal = round($preTotal, 2);

        // 会员等级折扣：改价行（is_gj=1）不参与，对标 canzhao needzkproduct_price 口径
        $leveldkMoney = 0.0;
        $discount     = 10.0;
        if ($mid > 0) {
            $member = Db::name('member')->where('aid', $aid)->where('id', $mid)->find();
            if (!empty($member)) {
                $level = Db::name('member_level')->where('aid', $aid)
                    ->where('id', (int) ($member['levelid'] ?? 0))->find();
                if (null !== $level && (float) ($level['discount'] ?? 0) > 0 && (float) ($level['discount'] ?? 0) < 10) {
                    $discount = (float) $level['discount'];
                    $needzk   = 0.0;
                    foreach ($goods as $og) {
                        if (0 === (int) ($og['is_gj'] ?? 0)) {
                            $needzk += round((float) $og['sell_price'] * (int) $og['num'], 2);
                        }
                    }
                    $leveldkMoney = round(round($needzk, 2) * (10 - $discount) * 0.1, 2);
                }
            }
        }

        $total = round($preTotal - $leveldkMoney, 2);

        // 抹零
        $molingMoney = 0.0;
        if (1 === $removeZero) {
            $desk        = $this->deskRow($aid);
            $zero        = $this->removeZero($total, (int) ($desk['remove_zero_length'] ?? 1));
            $molingMoney = $zero['moling_money'];
            $total       = $zero['totalprice'];
        }

        return [
            'pre_totalprice' => $preTotal,
            'totalprice'     => max(0.0, $total),
            'leveldk_money'  => $leveldkMoney,
            'moling_money'   => $molingMoney,
            'discount'       => $discount,
        ];
    }

    /**
     * 结算预览（对标 canzhao payPreview 一期裁剪版）
     */
    public function payPreview(int $aid, array $params): array
    {
        $order = $this->getWaitOrder($aid);
        if (empty($order)) {
            throw new BusinessException('无待结算订单');
        }

        $mid        = (int) ($params['mid'] ?? 0);
        $removeZero = (int) ($params['remove_zero'] ?? ($order['remove_zero'] ?? 0));

        // 库存预检
        $goods = Db::name('cashier_order_goods')->where('orderid', (int) $order['id'])->select()->toArray();
        if (empty($goods)) {
            throw new BusinessException('无待结算商品');
        }
        foreach ($goods as $g) {
            if (1 === (int) $g['protype'] && (int) $g['ggid'] > 0) {
                $stock = (int) (Db::name('shop_guige')->where('aid', $aid)
                    ->where('id', (int) $g['ggid'])->value('stock') ?? 0);
                if ($stock < (int) $g['num']) {
                    throw new BusinessException((string) $g['proname'] . '(' . (string) $g['ggname'] . ')库存不足');
                }
            }
        }

        $price = $this->calcOrderPrice($aid, $order, $mid, $removeZero);

        $memberinfo = '';
        if ($mid > 0) {
            $member = Db::name('member')->where('aid', $aid)->where('id', $mid)->find();
            if (!empty($member)) {
                $memberinfo = $this->formatMember($aid, $member);
            }
        }

        return [
            'orderid'        => (int) $order['id'],
            'ordernum'       => (string) ($order['ordernum'] ?? ''),
            'totalprice'     => $price['pre_totalprice'],
            'final_totalprice' => $price['totalprice'],
            'leveldk_money'  => $price['leveldk_money'],
            'moling_money'   => $price['moling_money'],
            'coupon_money'   => 0.0,
            'scoredk_money'  => 0.0,
            'remove_zero'    => $removeZero,
            'memberinfo'     => $memberinfo,
            'paylist'        => $this->payList($aid),
        ];
    }

    /* ================= 支付 ================= */

    /**
     * 收银台结算支付（一期：1微信付款码mock / 3现金 / 4余额 / 100+自定义）
     *
     * @return array{orderid: int, ordernum: string, totalprice: float, paytype: string}
     */
    public function pay(int $aid, int $uid, array $params): array
    {
        $order = $this->getWaitOrder($aid);
        if (empty($order)) {
            throw new BusinessException('无待结算订单');
        }

        $mid        = (int) ($params['mid'] ?? ($order['mid'] ?? 0));
        $removeZero = (int) ($params['remove_zero'] ?? ($order['remove_zero'] ?? 0));
        $paytype    = (int) ($params['paytype'] ?? 0);
        $authCode   = trim((string) ($params['auth_code'] ?? ''));

        $desk = $this->deskRow($aid);

        // 支付方式合法性（按收银设置开关）
        $customId = 0;
        if (self::PAY_WECHAT === $paytype) {
            if ((int) ($desk['wxpay'] ?? 0) !== 1) {
                throw new BusinessException('微信收款未开启');
            }
            if ('' === $authCode) {
                throw new BusinessException('请扫描付款码');
            }
        } elseif (self::PAY_CASH === $paytype) {
            if ((int) ($desk['cashpay'] ?? 0) !== 1) {
                throw new BusinessException('现金收款未开启');
            }
        } elseif (self::PAY_MONEY === $paytype) {
            if ((int) ($desk['moneypay'] ?? 0) !== 1) {
                throw new BusinessException('余额收款未开启');
            }
        } elseif ($paytype > self::PAY_CUSTOM_BASE) {
            $customId = $paytype - self::PAY_CUSTOM_BASE;
            $custom   = ScopeService::query('restaurant_cashdesk_custom_pay', $aid)
                ->where('bid', self::BID)->where('id', $customId)->where('status', 1)->find();
            if (empty($custom)) {
                throw new BusinessException('自定义支付方式不存在或已停用');
            }
        } else {
            throw new BusinessException('非法的支付方式');
        }

        $orderId = (int) $order['id'];

        return Db::transaction(function () use ($aid, $uid, $order, $orderId, $mid, $removeZero, $paytype, $authCode, $customId) {
            $price = $this->calcOrderPrice($aid, $order, $mid, $removeZero);
            $total = $price['totalprice'];

            $goods = Db::name('cashier_order_goods')->where('orderid', $orderId)->select()->toArray();

            // 库存校验 + 扣减（protype=1 商品，参照商城下单条件扣减防超卖）
            foreach ($goods as $g) {
                if (1 !== (int) $g['protype']) {
                    continue;
                }
                $num = (int) $g['num'];

                if ((int) $g['ggid'] > 0) {
                    $affected = Db::name('shop_guige')
                        ->where('id', (int) $g['ggid'])->where('aid', $aid)
                        ->where('stock', '>=', $num)
                        ->dec('stock', $num)->inc('sales', $num)->update();
                    if (1 !== $affected) {
                        throw new BusinessException((string) $g['proname'] . '(' . (string) $g['ggname'] . ')库存不足');
                    }
                }

                $proAffected = Db::name('shop_product')
                    ->where('id', (int) $g['proid'])->where('aid', $aid)
                    ->where('stock', '>=', $num)
                    ->dec('stock', $num)->inc('sales', $num)->update();
                if (1 !== $proAffected) {
                    throw new BusinessException((string) $g['proname'] . '库存不足');
                }
            }

            // 支付方式名
            $payLabels = [
                self::PAY_WECHAT => '微信支付',
                self::PAY_CASH   => '现金支付',
                self::PAY_MONEY  => '余额支付',
            ];
            $payLabel = $payLabels[$paytype] ?? '';
            if ($paytype > self::PAY_CUSTOM_BASE) {
                $payLabel = (string) (ScopeService::query('restaurant_cashdesk_custom_pay', $aid)
                    ->where('id', $customId)->value('title') ?? '自定义支付');
            }

            // 余额支付：行锁扣余额 + 流水
            if (self::PAY_MONEY === $paytype) {
                if ($mid <= 0) {
                    throw new BusinessException('余额支付请先绑定会员');
                }
                $member = Db::name('member')->where('aid', $aid)->where('id', $mid)->lock(true)->find();
                if (empty($member)) {
                    throw new BusinessException('会员信息有误');
                }
                if ($total > 0 && round((float) ($member['money'] ?? 0), 2) < $total) {
                    throw new BusinessException('会员余额不足，请改用其他支付方式');
                }
                if ($total > 0) {
                    $after = round((float) ($member['money'] ?? 0) - $total, 2);
                    Db::name('member')->where('id', $mid)->where('aid', $aid)->update(['money' => $after]);
                    Db::name('member_moneylog')->insert([
                        'aid'        => $aid,
                        'mid'        => $mid,
                        'money'      => -$total,
                        'after'      => $after,
                        'createtime' => time(),
                        'remark'     => '收银台买单，订单号：' . (string) ($order['ordernum'] ?? ''),
                        'paytype'    => 'cashier',
                    ]);
                }
            }

            // 微信付款码 mock 通道：生成模拟交易号
            $paynum = '';
            if (self::PAY_WECHAT === $paytype) {
                $paynum = 'MOCK' . time() . random_int(1000, 9999);
            }

            // 更新订单为已完成
            $now = time();
            Db::name('cashier_order')->where('id', $orderId)->update([
                'status'         => self::ORDER_PAID,
                'mid'            => $mid,
                'paytime'        => $now,
                'endtime'        => $now,
                'paytypeid'      => $paytype,
                'paytype'        => $payLabel,
                'paynum'         => $paynum,
                'uid'            => $uid,
                'platform'       => 'cashdesk',
                'pre_totalprice' => $price['pre_totalprice'],
                'totalprice'     => $total,
                'leveldk_money'  => $price['leveldk_money'],
                'moling_money'   => $price['moling_money'],
                'remove_zero'    => $removeZero,
            ]);

            // 商品行实收（real_totalprice：按占比分摊折扣后的金额）
            if ($price['pre_totalprice'] > 0) {
                foreach ($goods as $g) {
                    $real = round((float) $g['totalprice'] * $total / $price['pre_totalprice'], 2);
                    Db::name('cashier_order_goods')->where('id', (int) $g['id'])
                        ->update(['real_totalprice' => $real, 'mid' => $mid]);
                }
            }

            // 统一支付单（type=cashier）
            $payorderId = (int) Db::name('payorder')->insertGetId([
                'aid'        => $aid,
                'bid'        => self::BID,
                'mid'        => $mid,
                'orderid'    => $orderId,
                'ordernum'   => (string) ($order['ordernum'] ?? ''),
                'title'      => '收银台收款',
                'money'      => $total,
                'score'      => 0,
                'type'       => 'cashier',
                'createtime' => $now,
                'status'     => 1,
                'paynum'     => $paynum,
                'paytypeid'  => $paytype,
                'paytype'    => $payLabel . '-收银台',
                'paytime'    => $now,
                'platform'   => 'cashdesk',
            ]);
            Db::name('cashier_order')->where('id', $orderId)->update(['payorderid' => $payorderId]);

            return [
                'orderid'    => $orderId,
                'ordernum'   => (string) ($order['ordernum'] ?? ''),
                'totalprice' => $total,
                'paytype'    => $payLabel,
                'paytypeid'  => $paytype,
                'paynum'     => $paynum,
                'mid'        => $mid,
            ];
        });
    }

    /* ================= 收银订单 ================= */

    /**
     * 订单列表（筛选：status/ordernum/mid/paytypeid/时间范围）
     */
    public function orderList(int $aid, array $f, int $page, int $pageSize): array
    {
        $query = ScopeService::query('cashier_order', $aid)->where('bid', self::BID);

        if ('' !== (string) ($f['status'] ?? '') && null !== ($f['status'] ?? null)) {
            $query->where('status', (int) $f['status']);
        }
        if ('' !== trim((string) ($f['ordernum'] ?? ''))) {
            $query->whereLike('ordernum', '%' . trim((string) $f['ordernum']) . '%');
        }
        if ((int) ($f['mid'] ?? 0) > 0) {
            $query->where('mid', (int) $f['mid']);
        }
        if ((int) ($f['paytypeid'] ?? 0) > 0) {
            $query->where('paytypeid', (int) $f['paytypeid']);
        }
        if ('' !== (string) ($f['refund_status'] ?? '') && null !== ($f['refund_status'] ?? null)) {
            $query->where('refund_status', (int) $f['refund_status']);
        }
        $range = $this->timeRange((string) ($f['starttime'] ?? ''), (string) ($f['endtime'] ?? ''));
        if (null !== $range) {
            $query->whereBetween('createtime', $range);
        }

        $total = (clone $query)->count();
        $rows  = $query->order('id', 'desc')->page($page, $pageSize)->select()->toArray();

        $list = [];
        foreach ($rows as $o) {
            $list[] = $this->formatOrder($aid, $o, false);
        }

        return ['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize];
    }

    /**
     * 订单详情（含商品行）
     */
    public function orderDetail(int $aid, int $orderId): array
    {
        $order = ScopeService::query('cashier_order', $aid)
            ->where('bid', self::BID)->where('id', $orderId)->find();
        if (empty($order)) {
            throw new BusinessException('订单不存在', 404, 404);
        }

        return $this->formatOrder($aid, $order, true);
    }

    /**
     * 订单格式化
     */
    protected function formatOrder(int $aid, array $o, bool $withGoods): array
    {
        $statusMap = [
            self::ORDER_WAIT   => '结算等待中',
            self::ORDER_PAID   => '已完成',
            self::ORDER_HANGUP => '挂单',
            self::ORDER_REFUND => '已退款',
        ];
        $refundMap = [0 => '', 1 => '退款审核中', 2 => '已同意退款', 3 => '已驳回退款'];

        $nickname = '';
        if ((int) ($o['mid'] ?? 0) > 0) {
            $nickname = (string) (Db::name('member')->where('id', (int) $o['mid'])->value('nickname') ?? '');
        }
        $adminUser = '超级管理员';
        if ((int) ($o['uid'] ?? 0) > 0) {
            $adminUser = (string) (Db::name('admin_user')->where('id', (int) $o['uid'])->value('un') ?? '超级管理员');
        }

        $data = [
            'id'              => (int) $o['id'],
            'ordernum'        => (string) ($o['ordernum'] ?? ''),
            'status'          => (int) ($o['status'] ?? 0),
            'status_desc'     => $statusMap[(int) ($o['status'] ?? 0)] ?? '未知',
            'mid'             => (int) ($o['mid'] ?? 0),
            'nickname'        => $nickname,
            'admin_user'      => $adminUser,
            'uid'             => (int) ($o['uid'] ?? 0),
            'paytypeid'       => (int) ($o['paytypeid'] ?? 0),
            'paytype'         => (string) ($o['paytype'] ?? ''),
            'paynum'          => (string) ($o['paynum'] ?? ''),
            'pre_totalprice'  => round((float) ($o['pre_totalprice'] ?? 0), 2),
            'totalprice'      => round((float) ($o['totalprice'] ?? 0), 2),
            'leveldk_money'   => round((float) ($o['leveldk_money'] ?? 0), 2),
            'moling_money'    => round((float) ($o['moling_money'] ?? 0), 2),
            'remove_zero'     => (int) ($o['remove_zero'] ?? 0),
            'remark'          => (string) ($o['remark'] ?? ''),
            'createtime'      => (int) ($o['createtime'] ?? 0),
            'paytime'         => (int) ($o['paytime'] ?? 0),
            'refund_status'   => (int) ($o['refund_status'] ?? 0),
            'refund_status_desc' => $refundMap[(int) ($o['refund_status'] ?? 0)] ?? '',
            'refund_money'    => round((float) ($o['refund_money'] ?? 0), 2),
            'refund_reason'   => (string) ($o['refund_reason'] ?? ''),
            'refund_time'     => (int) ($o['refund_time'] ?? 0),
            'refund_checkremark' => (string) ($o['refund_checkremark'] ?? ''),
        ];

        if ($withGoods) {
            $goods = Db::name('cashier_order_goods')->where('orderid', (int) $o['id'])
                ->order('id', 'asc')->select()->toArray();
            $data['prolist'] = array_map(fn (array $g): array => [
                'id'              => (int) $g['id'],
                'proid'           => (string) ($g['proid'] ?? ''),
                'proname'         => (string) ($g['proname'] ?? ''),
                'propic'          => (string) ($g['propic'] ?? ''),
                'protype'         => (int) ($g['protype'] ?? 1),
                'ggname'          => (string) ($g['ggname'] ?? ''),
                'sell_price'      => round((float) ($g['sell_price'] ?? 0), 2),
                'num'             => (int) ($g['num'] ?? 0),
                'totalprice'      => round((float) ($g['totalprice'] ?? 0), 2),
                'real_totalprice' => round((float) ($g['real_totalprice'] ?? 0), 2),
                'is_gj'           => (int) ($g['is_gj'] ?? 0),
            ], $goods);
        }

        return $data;
    }

    /**
     * 删除订单（含商品行）
     *
     * @param array<int> $ids
     */
    public function deleteOrders(int $aid, array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids), fn (int $v): bool => $v > 0));
        if (empty($ids)) {
            throw new BusinessException('请选择要删除的订单');
        }

        Db::name('cashier_order_goods')->where('aid', $aid)->where('orderid', 'in', $ids)->delete();
        ScopeService::query('cashier_order', $aid)->where('bid', self::BID)->where('id', 'in', $ids)->delete();
    }

    /**
     * 申请退款（已完成且未退款/已驳回 可申请 → refund_status=1）
     */
    public function refundApply(int $aid, int $orderId, float $refundMoney, string $reason): void
    {
        $order = ScopeService::query('cashier_order', $aid)
            ->where('bid', self::BID)->where('id', $orderId)->find();
        if (empty($order)) {
            throw new BusinessException('订单不存在', 404, 404);
        }
        if (self::ORDER_PAID !== (int) $order['status']) {
            throw new BusinessException('仅已完成订单可申请退款');
        }
        if (!in_array((int) ($order['refund_status'] ?? 0), [0, 3], true)) {
            throw new BusinessException('该订单已有退款流程在处理中');
        }

        $refundMoney = round($refundMoney, 2);
        $maxRefund   = round((float) $order['totalprice'] - (float) $order['refund_money'], 2);
        if ($refundMoney <= 0 || $refundMoney > $maxRefund) {
            throw new BusinessException('退款金额有误（最多可退' . $maxRefund . '元）');
        }

        Db::name('cashier_order')->where('id', $orderId)->update([
            'refund_status' => 1,
            'refund_money'  => $refundMoney,
            'refund_reason' => mb_substr($reason, 0, 200),
        ]);
    }

    /**
     * 退款审核（同意：refund_status=2 + status=10，退余额/回库存；驳回：refund_status=3）
     */
    public function refundAudit(int $aid, int $orderId, int $agree, string $checkRemark): void
    {
        $order = ScopeService::query('cashier_order', $aid)
            ->where('bid', self::BID)->where('id', $orderId)->find();
        if (empty($order)) {
            throw new BusinessException('订单不存在', 404, 404);
        }
        if (1 !== (int) ($order['refund_status'] ?? 0)) {
            throw new BusinessException('该订单无待审核的退款申请');
        }

        if (1 !== $agree) {
            Db::name('cashier_order')->where('id', $orderId)->update([
                'refund_status'      => 3,
                'refund_checkremark' => mb_substr($checkRemark, 0, 200),
            ]);
            return;
        }

        Db::transaction(function () use ($aid, $order, $orderId, $checkRemark) {
            $refundMoney = round((float) $order['refund_money'], 2);
            $mid         = (int) ($order['mid'] ?? 0);

            // 余额支付：退回会员余额 + 流水
            if (self::PAY_MONEY === (int) $order['paytypeid'] && $mid > 0 && $refundMoney > 0) {
                $member = Db::name('member')->where('aid', $aid)->where('id', $mid)->lock(true)->find();
                if (!empty($member)) {
                    $after = round((float) ($member['money'] ?? 0) + $refundMoney, 2);
                    Db::name('member')->where('id', $mid)->where('aid', $aid)->update(['money' => $after]);
                    Db::name('member_moneylog')->insert([
                        'aid'        => $aid,
                        'mid'        => $mid,
                        'money'      => $refundMoney,
                        'after'      => $after,
                        'createtime' => time(),
                        'remark'     => '收银台订单退款，订单号：' . (string) ($order['ordernum'] ?? ''),
                        'paytype'    => 'cashier',
                    ]);
                }
            }

            // 商品库存回退（protype=1）
            $goods = Db::name('cashier_order_goods')->where('aid', $aid)->where('orderid', $orderId)->select()->toArray();
            foreach ($goods as $g) {
                if (1 !== (int) $g['protype']) {
                    continue;
                }
                $num = (int) $g['num'];
                if ((int) $g['ggid'] > 0) {
                    Db::name('shop_guige')->where('aid', $aid)->where('id', (int) $g['ggid'])
                        ->inc('stock', $num)->dec('sales', $num)->update();
                }
                Db::name('shop_product')->where('aid', $aid)->where('id', (int) $g['proid'])
                    ->inc('stock', $num)->dec('sales', $num)->update();
            }

            // 支付单退款标记
            if ((int) ($order['payorderid'] ?? 0) > 0) {
                Db::name('payorder')->where('id', (int) $order['payorderid'])
                    ->update(['refund_money' => $refundMoney, 'refund_time' => time()]);
            }

            Db::name('cashier_order')->where('id', $orderId)->update([
                'refund_status'      => 2,
                'status'             => self::ORDER_REFUND,
                'refund_time'        => time(),
                'refund_checkremark' => mb_substr($checkRemark, 0, 200),
            ]);
        });
    }

    /* ================= 统计与交接班 ================= */

    /**
     * 订单统计（时间范围：单数/营业额/按支付方式拆分/按商品汇总）
     * 口径对标 canzhao：统计已支付订单（status=1）
     */
    public function stat(int $aid, string $startTime, string $endTime): array
    {
        $range = $this->timeRange($startTime, $endTime);
        if (null === $range) {
            // 默认今日
            $range = [strtotime(date('Y-m-d 00:00:00')), time()];
        }

        $base = fn () => ScopeService::query('cashier_order', $aid)
            ->where('bid', self::BID)
            ->where('status', self::ORDER_PAID)
            ->whereBetween('paytime', $range);

        $orderCount  = $base()->count();
        $totalMoney  = round((float) $base()->sum('totalprice'), 2);
        $memberCount = $base()->where('mid', '>', 0)->count();

        // 退款（区间内 refund_time 且 status=10）
        $refundQuery = ScopeService::query('cashier_order', $aid)
            ->where('bid', self::BID)
            ->where('status', self::ORDER_REFUND)
            ->whereBetween('refund_time', $range);
        $refundMoney = round((float) $refundQuery->sum('refund_money'), 2);
        $refundCount = ScopeService::query('cashier_order', $aid)
            ->where('bid', self::BID)
            ->where('status', self::ORDER_REFUND)
            ->whereBetween('refund_time', $range)->count();

        // 按支付方式拆分
        $payRows = $base()->field('paytypeid,ANY_VALUE(paytype) as paytype,count(*) as cnt,sum(totalprice) as money')
            ->group('paytypeid')->select()->toArray();
        $payMap  = $this->payTypeMap($aid);
        $payStat = [];
        foreach ($payRows as $r) {
            $payStat[] = [
                'paytypeid' => (int) $r['paytypeid'],
                'paytype'   => $payMap[(int) $r['paytypeid']] ?? ((string) $r['paytype'] ?: '未知'),
                'count'     => (int) $r['cnt'],
                'money'     => round((float) $r['money'], 2),
            ];
        }

        // 按商品汇总（join 商品行，仅已支付订单）
        $goodsRows = ScopeService::queryAs('cashier_order_goods', $aid, 'og')
            ->join('cashier_order o', 'o.id = og.orderid')
            ->where('o.bid', self::BID)
            ->where('o.status', self::ORDER_PAID)
            ->whereBetween('o.paytime', $range)
            ->field('og.proname,sum(og.num) as num,sum(og.real_totalprice) as money')
            ->group('og.proid2,og.proname')
            ->order('num', 'desc')
            ->limit(100)
            ->select()->toArray();
        $goodsStat = array_map(fn (array $g): array => [
            'proname' => (string) ($g['proname'] ?? ''),
            'num'     => (int) $g['num'],
            'money'   => round((float) $g['money'], 2),
        ], $goodsRows);

        return [
            'starttime'    => date('Y-m-d H:i:s', $range[0]),
            'endtime'      => date('Y-m-d H:i:s', $range[1]),
            'order_count'  => $orderCount,
            'total_money'  => $totalMoney,
            'member_count' => $memberCount,
            'refund_count' => $refundCount,
            'refund_money' => $refundMoney,
            'pay_stat'     => $payStat,
            'goods_stat'   => $goodsStat,
        ];
    }

    /**
     * 交接班统计（统计视图，无独立表；登录时刻~当前，对标 canzhao CashierLogin::tongji）
     *
     * @param int $loginTime 本班次开始时间（当前 token 签发时间 iat）
     */
    public function jiaoban(int $aid, int $uid, int $loginTime): array
    {
        $now = time();
        if ($loginTime <= 0 || $loginTime > $now) {
            $loginTime = strtotime(date('Y-m-d 00:00:00'));
        }

        $base = fn () => ScopeService::query('cashier_order', $aid)
            ->where('bid', self::BID)
            ->where('uid', $uid)
            ->where('paytime', '>=', $loginTime)
            ->where('paytime', '<=', $now);

        $orderCount = $base()->count();
        $totalMoney = round((float) $base()->sum('totalprice'), 2);

        // 按支付方式拆分（含自定义）
        $payRows = $base()->where('status', self::ORDER_PAID)
            ->field('paytypeid,ANY_VALUE(paytype) as paytype,count(*) as cnt,sum(totalprice) as money')
            ->group('paytypeid')->select()->toArray();
        $payMap  = $this->payTypeMap($aid);
        $payStat = [];
        foreach ($payRows as $r) {
            $payStat[] = [
                'paytypeid' => (int) $r['paytypeid'],
                'paytype'   => $payMap[(int) $r['paytypeid']] ?? ((string) $r['paytype'] ?: '未知'),
                'count'     => (int) $r['cnt'],
                'money'     => round((float) $r['money'], 2),
            ];
        }

        // 退款合计（本班内）
        $refundMoney = round((float) (ScopeService::query('cashier_order', $aid)
            ->where('bid', self::BID)
            ->where('uid', $uid)
            ->where('status', self::ORDER_REFUND)
            ->where('refund_time', '>=', $loginTime)
            ->where('refund_time', '<=', $now)
            ->sum('refund_money') ?? 0), 2);

        // 会员单数/金额
        $memberCount = $base()->where('mid', '>', 0)->count();
        $memberMoney = round((float) $base()->where('mid', '>', 0)->sum('totalprice'), 2);

        $operator = '超级管理员';
        if ($uid > 0) {
            $operator = (string) (Db::name('admin_user')->where('id', $uid)->value('un') ?? '超级管理员');
        }

        return [
            'logintime'     => date('Y-m-d H:i:s', $loginTime),
            'jiaobantime'   => date('Y-m-d H:i:s', $now),
            'cashdesk_user' => $operator,
            'ordercount'    => $orderCount,
            'today_total_money' => $totalMoney,
            'pay_stat'      => $payStat,
            'refund_money'  => $refundMoney,
            'member_count'  => $memberCount,
            'member_money'  => $memberMoney,
        ];
    }

    /**
     * 时间范围解析（空则 null，支持 Y-m-d / Y-m-d H:i:s）
     *
     * @return array{0: int, 1: int}|null
     */
    protected function timeRange(string $start, string $end): ?array
    {
        $start = trim($start);
        $end   = trim($end);
        if ('' === $start && '' === $end) {
            return null;
        }

        $startTs = '' !== $start ? strtotime($start) : strtotime(date('Y-m-d 00:00:00'));
        $endTs   = '' !== $end ? strtotime($end) : time();
        if (false === $startTs || false === $endTs) {
            throw new BusinessException('时间格式不正确');
        }
        // 仅日期时补到当天结束
        if ('' !== $end && strlen($end) <= 10) {
            $endTs += 86399;
        }

        return [$startTs, $endTs];
    }
}
