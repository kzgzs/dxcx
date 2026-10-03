<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use app\common\Kdniao;
use think\facade\Db;

/**
 * 整点秒杀服务（admin 后台 + 定时任务共用）
 *
 * 表：seckill_sysset（场次/关单设置）、seckill_product（秒杀商品）、
 *     seckill_guige（秒杀规格库存）、seckill_prodata（日期×整点场次明细）、
 *     seckill_order（订单：0未付 1已付 2已发货 3已收货 4关闭）
 *
 * 防超卖口径（区别于旧系统「先查后扣」）：
 * - 扣减一律条件更新 where stock >= num，affected>0 才算成功
 * - 关单/超时关单同事务回补：stock+num、sales 回退（不低于0）、退券
 *
 * 限购口径：累计购买量只统计 status in(0,1,2,3) 的有效单，关闭单不计
 */
class SeckillService
{
    /** 订单状态机 */
    public const STATUS_UNPAID  = 0; // 未支付
    public const STATUS_PAID    = 1; // 已支付
    public const STATUS_SHIPPED = 2; // 已发货
    public const STATUS_FINISHED = 3; // 已收货
    public const STATUS_CLOSED  = 4; // 已关闭

    /** 定时任务单轮扫描上限 */
    protected const SCAN_LIMIT = 500;

    public function __construct(protected PayService $payService)
    {
    }

    /* ================= 系统设置 ================= */

    /**
     * 秒杀设置（无记录给默认值）
     *
     * @return array<string, mixed>
     */
    public function getSysset(int $aid): array
    {
        $row = ScopeService::query('seckill_sysset', $aid)->find();

        $default = [
            'timeset'        => '0,8,12,20',
            'duration'       => 12,
            'status'         => 1,
            'autoshdays'     => 7,
            'autoclose'      => 60,
            'comment'        => 1,
            'comment_check'  => 1,
            'showjd'         => 1,
            'showcommission' => 0,
        ];

        $data = $row ? array_merge($default, array_intersect_key($row, $default)) : $default;

        $data['timeset']   = $this->parseTimeset((string) $data['timeset']);
        $data['duration']  = (int) $data['duration'];
        $data['status']    = (int) $data['status'];
        $data['autoshdays'] = (int) $data['autoshdays'];
        $data['autoclose'] = (int) $data['autoclose'];

        return $data;
    }

    /**
     * 保存秒杀设置（按 aid upsert）
     *
     * @param array $data {timeset:[0,8,..]|'0,8,..', duration, status, autoshdays, autoclose, comment, comment_check, showjd, showcommission}
     */
    public function saveSysset(int $aid, array $data): void
    {
        $timeset = $data['timeset'] ?? '';
        if (is_string($timeset)) {
            $timeset = explode(',', $timeset);
        }
        $timeset = $this->parseTimeset(implode(',', array_map('strval', (array) $timeset)));
        if (empty($timeset)) {
            throw new BusinessException('请至少选择一个秒杀场次时间点');
        }

        $autoclose = (int) ($data['autoclose'] ?? 60);
        if ($autoclose < 1) {
            throw new BusinessException('自动关单分钟数不能小于1');
        }

        $autoshdays = (int) ($data['autoshdays'] ?? 7);
        if ($autoshdays < 1) {
            throw new BusinessException('自动收货天数不能小于1');
        }

        $duration = (int) ($data['duration'] ?? 12);
        if ($duration < 1 || $duration > 24) {
            throw new BusinessException('场次时长需在1-24小时之间');
        }

        $info = [
            'timeset'        => implode(',', $timeset),
            'duration'       => $duration,
            'status'         => (int) ($data['status'] ?? 1) ? 1 : 0,
            'autoshdays'     => $autoshdays,
            'autoclose'      => $autoclose,
            'comment'        => (int) ($data['comment'] ?? 1) ? 1 : 0,
            'comment_check'  => (int) ($data['comment_check'] ?? 1) ? 1 : 0,
            'showjd'         => (int) ($data['showjd'] ?? 1) ? 1 : 0,
            'showcommission' => (int) ($data['showcommission'] ?? 0) ? 1 : 0,
        ];

        $exists = ScopeService::query('seckill_sysset', $aid)->find();

        if ($exists) {
            Db::name('seckill_sysset')->where('aid', $aid)->update($info);
        } else {
            Db::name('seckill_sysset')->insert(array_merge($info, ['aid' => $aid]));
        }
    }

    /* ================= 秒杀商品 ================= */

    /**
     * 秒杀商品分页列表（附规格行）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function productList(int $aid, int $page, int $pagesize, string $keyword, string $status, string $bid = '', string $checked = '', string $seckillDate = '', string $seckillTime = '', string $orderField = '', string $orderType = ''): array
    {
        $query = ScopeService::query('seckill_product', $aid);

        // 商户维度过滤（任务177 showtype-2：''/all 不过滤，0 自营，gt0 商户）
        ScopeService::applyBid($query, $bid, 'bid');

        $keyword = trim($keyword);
        if ('' !== $keyword) {
            $query->whereLike('name', '%' . $keyword . '%');
        }
        if ('' !== $status) {
            $query->where('status', (int) $status);
        }
        // 审核状态过滤（0待审 1通过 2驳回）
        if ('' !== $checked) {
            $query->where('ischecked', (int) $checked);
        }
        // 秒杀日期 / 场次小时过滤
        if ('' !== $seckillDate) {
            $query->where('seckill_date', trim($seckillDate));
        }
        if ('' !== $seckillTime) {
            $query->where('seckill_time', (int) $seckillTime);
        }

        $total = (clone $query)->count();

        // 排序白名单
        $orderMap = ['id' => 'id', 'sales' => 'sales', 'stock' => 'stock', 'seckill_time' => 'seckill_time', 'sort' => 'sort', 'createtime' => 'createtime'];
        $sortField = $orderMap[$orderField] ?? '';
        $sortDir   = 'asc' === strtolower($orderType) ? 'asc' : 'desc';
        if ('' !== $sortField) {
            $query->order($sortField, $sortDir);
        }
        $query->order('sort', 'desc')->order('id', 'desc');

        $rows = $query
            ->page($page, $pagesize)
            ->select()
            ->toArray();

        // 当前页商品的规格行（一次查出按 proid 分组）
        $guigeMap = [];
        if (!empty($rows)) {
            $proids = array_map('intval', array_column($rows, 'id'));
            $guiges = ScopeService::query('seckill_guige', $aid)
                ->whereIn('proid', $proids)
                ->order('id', 'asc')
                ->select()
                ->toArray();

            foreach ($guiges as $gg) {
                $guigeMap[(int) $gg['proid']][] = [
                    'id'         => (int) $gg['id'],
                    'name'       => (string) ($gg['name'] ?? ''),
                    'sell_price' => (float) ($gg['sell_price'] ?? 0),
                    'stock'      => (int) ($gg['stock'] ?? 0),
                    'sales'      => (int) ($gg['sales'] ?? 0),
                ];
            }
        }

        // 所属商户名映射（bid=0 平台自营）
        $bids = array_values(array_filter(array_map(fn ($r) => (int) ($r['bid'] ?? 0), $rows), fn ($b) => $b > 0));
        $businessMap = [];
        if (!empty($bids)) {
            $businessMap = Db::name('business')->where('aid', $aid)->whereIn('id', $bids)->column('name', 'id');
        }

        $list = array_map(function (array $row) use ($businessMap): array {
            $bidVal = (int) ($row['bid'] ?? 0);
            $stock  = (int) ($row['stock'] ?? 0);
            $sales  = (int) ($row['sales'] ?? 0);
            $totalNum = $stock + $sales;
            return [
                'id'           => (int) $row['id'],
                'name'         => (string) ($row['name'] ?? ''),
                'pic'          => (string) ($row['pic'] ?? ''),
                'pics'         => (string) ($row['pics'] ?? ''),
                'procode'      => (string) ($row['procode'] ?? ''),
                'market_price' => (float) ($row['market_price'] ?? 0),
                'sell_price'   => (float) ($row['sell_price'] ?? 0),
                'stock'        => $stock,
                'sales'        => $sales,
                'buymax'       => (int) ($row['buymax'] ?? 0),
                'perlimit'     => (int) ($row['perlimit'] ?? 0),
                'status'       => (int) ($row['status'] ?? 0),
                'sort'         => (int) ($row['sort'] ?? 0),
                'createtime'   => (int) ($row['createtime'] ?? 0),
                'seckill_date' => (string) ($row['seckill_date'] ?? ''),
                'seckill_time' => (int) ($row['seckill_time'] ?? 0),
                'starttime'    => (int) ($row['starttime'] ?? 0),
                'sellpoint'    => (string) ($row['sellpoint'] ?? ''),
                'ischecked'    => (int) ($row['ischecked'] ?? 1),
                'check_reason' => (string) ($row['check_reason'] ?? ''),
                'bname'        => $bidVal > 0 ? (string) ($businessMap[$bidVal] ?? ('商户' . $bidVal)) : '平台自营',
                'salepercent'  => $totalNum > 0 ? min(100, (int) round($sales / $totalNum * 100)) : 0,
                'salestotal'   => $totalNum,
                'guige'        => $guigeMap[(int) $row['id']] ?? [],
            ];
        }, $rows);

        return ['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pagesize];
    }

    /**
     * 秒杀商品报名/编辑
     *
     * 报名：可从商城商品（goods_id）带入名称/主图/编码等；同编码商品重复报名拦截
     * 规格：未传 guige 时按「默认规格」建一条 seckill_guige
     *
     * @param array $data {id?, goods_id?, name?, pic?, market_price?, sell_price, stock?, buymax, perlimit, sort?, status?, guige?:[{id?,name,sell_price,stock}]}
     * @return array{id: int}
     */
    public function productSave(int $aid, array $data): array
    {
        $id       = (int) ($data['id'] ?? 0);
        $goodsId  = (int) ($data['goods_id'] ?? 0);
        $sellPrice = $this->money($data['sell_price'] ?? 0);
        $marketPrice = $this->money($data['market_price'] ?? 0);
        $buymax   = max(0, (int) ($data['buymax'] ?? 0));
        $perlimit = max(0, (int) ($data['perlimit'] ?? 0));

        // 秒杀场次：日期 Y-m-d + 小时；starttime 缺省时由 日期+时间 推导
        $seckillDate = trim((string) ($data['seckill_date'] ?? ''));
        $seckillTime = (int) ($data['seckill_time'] ?? 0);
        if ($seckillTime < 0 || $seckillTime > 23) {
            throw new BusinessException('秒杀场次时间须在0-23点之间');
        }
        $starttime = (int) ($data['starttime'] ?? 0);
        if ($starttime <= 0 && '' !== $seckillDate) {
            $ts = strtotime($seckillDate . ' ' . $seckillTime . ':00:00');
            $starttime = false === $ts ? 0 : $ts;
        }
        $sellpoint = trim((string) ($data['sellpoint'] ?? ''));

        if ($sellPrice <= 0) {
            throw new BusinessException('秒杀价必须大于0');
        }
        if ($marketPrice > 0 && $marketPrice < $sellPrice) {
            throw new BusinessException('市场价不能低于秒杀价');
        }

        // 从商城商品带入基础信息（选商品报名）
        $goods = null;
        if ($goodsId > 0) {
            $goods = ScopeService::query('shop_product', $aid)
                ->where('id', $goodsId)
                ->where('deleted', 0)
                ->find();

            if (empty($goods)) {
                throw new BusinessException('所选商城商品不存在');
            }

            // 重复报名校验：同一商品编码已报过秒杀（排除自身编辑）
            $procode = trim((string) ($goods['procode'] ?? ''));
            if ('' !== $procode) {
                $dupQuery = ScopeService::query('seckill_product', $aid)->where('procode', $procode);
                if ($id > 0) {
                    $dupQuery->where('id', '<>', $id);
                }
                if ($dupQuery->find()) {
                    throw new BusinessException('该商品已报名秒杀活动，请勿重复报名');
                }
            }
        }

        $name = trim((string) ($data['name'] ?? ''));
        if ('' === $name) {
            $name = $goods ? (string) $goods['name'] : '';
        }
        if ('' === $name) {
            throw new BusinessException('请填写秒杀商品名称');
        }

        $pic = trim((string) ($data['pic'] ?? ''));
        if ('' === $pic && $goods) {
            $pic = (string) ($goods['pic'] ?? '');
        }
        if ($marketPrice <= 0 && $goods) {
            $marketPrice = $this->money($goods['sell_price'] ?? 0);
        }

        // 规格行：优先入参，其次未建过规格时补默认规格
        $guigeInput = isset($data['guige']) && is_array($data['guige']) ? $data['guige'] : [];

        $product = [
            'name'         => $name,
            'pic'          => $pic,
            'pics'         => $goods ? (string) ($goods['pics'] ?? '') : trim((string) ($data['pics'] ?? '')),
            'procode'      => $goods ? (string) ($goods['procode'] ?? '') : trim((string) ($data['procode'] ?? '')),
            'market_price' => $marketPrice,
            'sell_price'   => $sellPrice,
            'cost_price'   => $goods ? $this->money($goods['cost_price'] ?? 0) : $this->money($data['cost_price'] ?? 0),
            'detail'       => $goods && '' === trim((string) ($data['detail'] ?? '')) ? (string) ($goods['detail'] ?? '') : (string) ($data['detail'] ?? ''),
            'weight'       => $goods ? (int) ($goods['weight'] ?? 0) : (int) ($data['weight'] ?? 0),
            'buymax'       => $buymax,
            'perlimit'     => $perlimit,
            'sort'         => (int) ($data['sort'] ?? 0),
            'status'       => array_key_exists('status', $data) ? ((int) $data['status'] ? 1 : 0) : 1,
            'seckill_date' => $seckillDate,
            'seckill_time' => $seckillTime,
            'starttime'    => $starttime,
            'sellpoint'    => $sellpoint,
            'video'        => trim((string) ($data['video'] ?? '')),
            'gettj'        => in_array((string) ($data['gettj'] ?? 'all'), ['all', 'follow', 'vip', 'dealer'], true) ? (string) ($data['gettj'] ?? 'all') : 'all',
        ];

        if ($id > 0) {
            $this->findProduct($aid, $id);
            Db::name('seckill_product')->where('aid', $aid)->where('id', $id)->update($product);
        } else {
            $product['aid']        = $aid;
            $product['bid']        = 0;
            $product['sales']      = 0;
            $product['stock']      = max(0, (int) ($data['stock'] ?? 100));
            $product['createtime'] = time();
            $id                    = (int) Db::name('seckill_product')->insertGetId($product);
        }

        $this->saveGuige($aid, $id, $guigeInput, $sellPrice, $marketPrice, (int) ($product['stock'] ?? 0));

        // 商品库存与规格库存保持一致（取规格合计）
        $stockSum = (int) ScopeService::query('seckill_guige', $aid)->where('proid', $id)->sum('stock');
        Db::name('seckill_product')->where('aid', $aid)->where('id', $id)->update(['stock' => $stockSum]);

        return ['id' => $id];
    }

    /**
     * 删除秒杀商品（已有场次/订单时拒绝）
     */
    public function productDelete(int $aid, int $id): void
    {
        $this->findProduct($aid, $id);

        $prodataCount = ScopeService::query('seckill_prodata', $aid)->where('proid', $id)->count();
        if ($prodataCount > 0) {
            throw new BusinessException('请先删除该商品的秒杀场次');
        }

        $orderCount = ScopeService::query('seckill_order', $aid)->where('proid', $id)->count();
        if ($orderCount > 0) {
            throw new BusinessException('该商品已产生订单，不能删除');
        }

        Db::name('seckill_product')->where('aid', $aid)->where('id', $id)->delete();
        Db::name('seckill_guige')->where('aid', $aid)->where('proid', $id)->delete();
    }

    /**
     * 上架/下架
     */
    public function productStatus(int $aid, int $id, int $status): void
    {
        $this->findProduct($aid, $id);

        Db::name('seckill_product')->where('aid', $aid)->where('id', $id)
            ->update(['status' => $status ? 1 : 0]);
    }

    /**
     * 批量上下架（逐条走单条校验）
     *
     * @param array<int, mixed> $ids
     */
    public function productStatusByIds(int $aid, array $ids, int $status): void
    {
        foreach ($this->normIds($ids) as $id) {
            $this->productStatus($aid, $id, $status);
        }
    }

    /**
     * 批量删除（逐条走单条业务拦截）
     *
     * @param array<int, mixed> $ids
     */
    public function productDeleteByIds(int $aid, array $ids): void
    {
        foreach ($this->normIds($ids) as $id) {
            $this->productDelete($aid, $id);
        }
    }

    /**
     * 商品详情（含规格行、场次设置）
     *
     * @return array<string, mixed>
     */
    public function productDetail(int $aid, int $id): array
    {
        $product = $this->findProduct($aid, $id);

        $bidVal = (int) ($product['bid'] ?? 0);
        $product['bname'] = $bidVal > 0
            ? (string) (Db::name('business')->where('aid', $aid)->where('id', $bidVal)->value('name') ?: ('商户' . $bidVal))
            : '平台自营';

        $product['guige'] = ScopeService::query('seckill_guige', $aid)
            ->where('proid', $id)
            ->order('id', 'asc')
            ->select()
            ->toArray();

        return $product;
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

        Db::name('seckill_product')->where('aid', $aid)->where('id', $id)->update([
            'ischecked'    => $ischecked,
            'check_reason' => 2 === $ischecked ? trim($reason) : '',
        ]);
    }

    /**
     * 复制秒杀商品（连带规格，名称加「复制-」前缀，新副本下架）
     *
     * @return array{id: int}
     */
    public function procopy(int $aid, int $id): array
    {
        $product = $this->findProduct($aid, $id);

        $data = $product;
        $data['name'] = '复制-' . $data['name'];
        unset($data['id']);
        $data['status'] = 0;
        $newId = (int) Db::name('seckill_product')->insertGetId($data);

        $guiges = ScopeService::query('seckill_guige', $aid)->where('proid', $id)->select()->toArray();
        foreach ($guiges as $gg) {
            unset($gg['id']);
            $gg['proid'] = $newId;
            Db::name('seckill_guige')->insert($gg);
        }

        return ['id' => $newId];
    }

    /**
     * 归一化 id 列表
     *
     * @param array<int, mixed> $ids
     * @return array<int, int>
     */
    protected function normIds(array $ids): array
    {
        $out = [];
        foreach ($ids as $i) {
            $i = (int) $i;
            if ($i > 0) {
                $out[] = $i;
            }
        }
        if (empty($out)) {
            throw new BusinessException('请选择要操作的商品');
        }
        return $out;
    }

    /* ================= 场次明细（prodata） ================= */

    /**
     * 场次列表（按 商品+日期+场次 聚合：最低秒杀价/总库存/已售）
     *
     * @param array $filters {proid?, date?, time?, keyword?}
     * @return array{list: array, total: int, page: int, pagesize: int, timeset: array, duration: int}
     */
    public function prodataList(int $aid, int $page, int $pagesize, array $filters): array
    {
        $sysset = $this->getSysset($aid);

        $query = ScopeService::queryAs('seckill_prodata', $aid, 'p')
            ->join('seckill_product sp', 'sp.id = p.proid')
            ->field('p.proid, p.seckill_date, p.seckill_time, sp.name, sp.pic, sp.status prostatus, '
                . 'min(p.seckill_price) seckill_price, sum(p.seckill_num) seckill_num, '
                . 'sum(p.sales) sales, min(p.starttime) starttime, min(p.buymax) buymax, '
                . 'group_concat(p.id) ids')
            ->group('p.proid, p.seckill_date, p.seckill_time');

        $proid = (int) ($filters['proid'] ?? 0);
        if ($proid > 0) {
            $query->where('p.proid', $proid);
        }
        $date = trim((string) ($filters['date'] ?? ''));
        if ('' !== $date) {
            $query->where('p.seckill_date', $date);
        }
        $time = trim((string) ($filters['time'] ?? ''));
        if ('' !== $time) {
            $query->where('p.seckill_time', (int) $time);
        }
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ('' !== $keyword) {
            $query->whereLike('sp.name', '%' . $keyword . '%');
        }

        // 聚合结果总数：子查询包一层计数
        $sub   = (clone $query)->buildSql();
        $total = (int) Db::table('(' . $sub . ') t')->count();

        $rows = $query->order('p.seckill_date', 'desc')
            ->order('p.seckill_time', 'desc')
            ->page($page, $pagesize)
            ->select()
            ->toArray();

        $list = array_map(function (array $row) use ($sysset): array {
            $starttime = (int) $row['starttime'];
            $seckillNum = (int) $row['seckill_num'];

            return [
                'proid'         => (int) $row['proid'],
                'name'          => (string) $row['name'],
                'pic'           => (string) ($row['pic'] ?? ''),
                'prostatus'     => (int) ($row['prostatus'] ?? 0),
                'seckill_date'  => (string) $row['seckill_date'],
                'seckill_time'  => (int) $row['seckill_time'],
                'starttime'     => $starttime,
                'endtime'       => $starttime + (int) $sysset['duration'] * 3600,
                'seckill_price' => (float) $row['seckill_price'],
                'seckill_num'   => $seckillNum,
                'sales'         => (int) $row['sales'],
                'salepercent'   => $seckillNum > 0 ? min(100, (int) ((int) $row['sales'] / $seckillNum * 100)) : 0,
                'buymax'        => (int) $row['buymax'],
                'ids'           => array_map('intval', explode(',', (string) ($row['ids'] ?? ''))),
            ];
        }, $rows);

        return [
            'list'     => $list,
            'total'    => $total,
            'page'     => $page,
            'pagesize' => $pagesize,
            'timeset'  => $sysset['timeset'],
            'duration' => (int) $sysset['duration'],
        ];
    }

    /**
     * 生成场次：商品 × 日期 × 整点 展开为 prodata 行（同场次重复报名拦截）
     *
     * @param array $data {proid, dates:['Y-m-d',..], times:[8,..], buymax, options:{ggid:{seckill_price, seckill_num}}}
     * @return array{count: int}
     */
    public function prodataSave(int $aid, array $data): array
    {
        $proid = (int) ($data['proid'] ?? 0);
        if ($proid <= 0) {
            throw new BusinessException('请选择秒杀商品');
        }
        $this->findProduct($aid, $proid);

        $dates = (array) ($data['dates'] ?? []);
        $dates = array_values(array_filter(array_map(static fn ($d): string => trim((string) $d), $dates)));
        if (empty($dates)) {
            throw new BusinessException('请选择秒杀日期');
        }

        $times = (array) ($data['times'] ?? []);
        $times = array_values(array_unique(array_map('intval', $times)));
        if (empty($times)) {
            throw new BusinessException('请选择秒杀场次时间');
        }
        foreach ($times as $hour) {
            if ($hour < 0 || $hour > 23) {
                throw new BusinessException('场次时间需为0-23整点');
            }
        }

        $buymax = max(0, (int) ($data['buymax'] ?? 0));

        $guiges = ScopeService::query('seckill_guige', $aid)
            ->where('proid', $proid)
            ->order('id', 'asc')
            ->select()
            ->toArray();
        if (empty($guiges)) {
            throw new BusinessException('该商品未建立规格库存，请先编辑商品规格');
        }

        $options = isset($data['options']) && is_array($data['options']) ? $data['options'] : [];
        $today   = date('Y-m-d');

        $datalist = [];
        foreach ($dates as $date) {
            if (false === strtotime($date)) {
                throw new BusinessException('日期格式错误：' . $date);
            }
            if ($date < $today) {
                throw new BusinessException('不能为过去的日期设置秒杀场次');
            }

            foreach ($times as $hour) {
                // 重复报名校验：同商品同日同场次只允许设置一次
                $exists = ScopeService::query('seckill_prodata', $aid)
                    ->where('proid', $proid)
                    ->where('seckill_date', $date)
                    ->where('seckill_time', $hour)
                    ->find();
                if ($exists) {
                    throw new BusinessException(sprintf('该商品在%s %02d:00已设置秒杀活动，请勿重复设置', $date, $hour));
                }

                foreach ($guiges as $gg) {
                    $opt         = $options[(string) $gg['id']] ?? ($options[(int) $gg['id']] ?? []);
                    $seckillPrice = array_key_exists('seckill_price', $opt) ? $this->money($opt['seckill_price']) : (float) $gg['sell_price'];
                    $seckillNum   = array_key_exists('seckill_num', $opt) ? (int) $opt['seckill_num'] : (int) $gg['stock'];

                    if ($seckillNum < 1) {
                        throw new BusinessException(sprintf('规格「%s」的秒杀库存不能小于1', (string) $gg['name']));
                    }

                    $datalist[] = [
                        'aid'           => $aid,
                        'bid'           => 0,
                        'proid'         => $proid,
                        'ggid'          => (int) $gg['id'],
                        'seckill_date'  => $date,
                        'seckill_time'  => $hour,
                        'starttime'     => strtotime($date) + $hour * 3600,
                        'seckill_price' => $seckillPrice,
                        'sell_price'    => (float) $gg['sell_price'],
                        'seckill_num'   => $seckillNum,
                        'sales'         => 0,
                        'buymax'        => $buymax,
                        'createtime'    => time(),
                    ];
                }
            }
        }

        Db::name('seckill_prodata')->insertAll($datalist);

        return ['count' => count($datalist)];
    }

    /**
     * 删除场次行（仅允许删除未开场的场次）
     *
     * @param array $ids prodata 行ID集合
     */
    public function prodataDelete(int $aid, array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) {
            throw new BusinessException('请选择要删除的场次');
        }

        $rows = ScopeService::query('seckill_prodata', $aid)->whereIn('id', $ids)->select()->toArray();
        if (count($rows) !== count($ids)) {
            throw new BusinessException('场次不存在');
        }

        foreach ($rows as $row) {
            if ((int) $row['starttime'] <= time() || (int) $row['sales'] > 0) {
                throw new BusinessException(sprintf('%s %02d:00 场次已开场或已有销量，不能删除', (string) $row['seckill_date'], (int) $row['seckill_time']));
            }
        }

        Db::name('seckill_prodata')->where('aid', $aid)->whereIn('id', $ids)->delete();
    }

    /* ================= 订单 ================= */

    /**
     * 秒杀订单分页列表
     *
     * @param array $filters {status, ordernum, proname, linkman, tel, start_date, end_date}
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function orderList(int $aid, int $page, int $pagesize, array $filters): array
    {
        $query = ScopeService::queryAs('seckill_order', $aid, 'o')
            ->leftJoin('member m', 'm.id = o.mid')
            ->field('o.*, m.nickname, m.headimg');

        $status = trim((string) ($filters['status'] ?? ''));
        if ('' !== $status) {
            $query->where('o.status', (int) $status);
        }

        foreach (['ordernum', 'proname', 'linkman', 'tel'] as $field) {
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

        $list = array_map(static fn (array $row): array => [
            'id'          => (int) $row['id'],
            'ordernum'    => (string) ($row['ordernum'] ?? ''),
            'mid'         => (int) ($row['mid'] ?? 0),
            'nickname'    => (string) ($row['nickname'] ?? ''),
            'headimg'     => (string) ($row['headimg'] ?? ''),
            'title'       => (string) ($row['title'] ?? ''),
            'proname'     => (string) ($row['proname'] ?? ''),
            'propic'      => (string) ($row['propic'] ?? ''),
            'ggname'      => (string) ($row['ggname'] ?? ''),
            'num'         => (int) ($row['num'] ?? 1),
            'sell_price'  => (float) ($row['sell_price'] ?? 0),
            'totalprice'  => (float) ($row['totalprice'] ?? 0),
            'status'      => (int) ($row['status'] ?? 0),
            'linkman'     => (string) ($row['linkman'] ?? ''),
            'tel'         => (string) ($row['tel'] ?? ''),
            'address'     => trim(((string) ($row['area'] ?? '')) . ' ' . ((string) ($row['address'] ?? ''))),
            'paytype'     => (string) ($row['paytype'] ?? ''),
            'platform'    => (string) ($row['platform'] ?? ''),
            'freight_type' => (int) ($row['freight_type'] ?? 0),
            'freight_text' => (string) ($row['freight_text'] ?? ''),
            'product_price' => (float) ($row['product_price'] ?? $row['sell_price'] ?? 0),
            'express_com' => (string) ($row['express_com'] ?? ''),
            'express_no'  => (string) ($row['express_no'] ?? ''),
            'createtime'  => (int) ($row['createtime'] ?? 0),
            'paytime'     => (int) ($row['paytime'] ?? 0),
            'send_time'   => (int) ($row['send_time'] ?? 0),
            'collect_time' => (int) ($row['collect_time'] ?? 0),
            'remark'      => (string) ($row['remark'] ?? ''),
        ], $rows);

        return ['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pagesize];
    }

    /**
     * 后台手动关单（仅未支付单；同事务回补库存/销量并退券）
     */
    public function orderClose(int $aid, int $orderId): void
    {
        if ($orderId <= 0) {
            throw new BusinessException('缺少订单ID');
        }

        $order = ScopeService::query('seckill_order', $aid)->where('id', $orderId)->find();
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

    /* ================= 订单扩展操作（对齐 canzhao SeckillOrder） ================= */

    /**
     * 订单详情（含下单会员）
     *
     * @return array{order: array, member: array}
     */
    public function orderDetail(int $aid, int $orderId): array
    {
        $order = ScopeService::query('seckill_order', $aid)->where('id', $orderId)->find();
        if (empty($order)) {
            throw new BusinessException('订单不存在');
        }
        $member = Db::name('member')->field('id,nickname,headimg,realname,tel')
            ->where('id', (int) $order['mid'])->find() ?: [];
        $order['address'] = trim(((string) ($order['area'] ?? '')) . ' ' . ((string) ($order['address'] ?? '')));

        return ['order' => $order, 'member' => $member];
    }

    /**
     * 删除订单（单 id 或批量 ids[]；aid 租户隔离）
     *
     * @param array<int, int> $ids
     */
    public function orderDelete(int $aid, array $ids): void
    {
        if (empty($ids)) {
            throw new BusinessException('请选择要删除的订单');
        }
        ScopeService::query('seckill_order', $aid)->whereIn('id', $ids)->delete();
    }

    /**
     * 改为已支付（仅未支付）
     */
    public function orderIspay(int $aid, int $orderId): void
    {
        $order = ScopeService::query('seckill_order', $aid)->where('id', $orderId)->find();
        if (empty($order)) {
            throw new BusinessException('订单不存在');
        }
        if (self::STATUS_UNPAID !== (int) $order['status']) {
            throw new BusinessException('仅未支付订单可改为已支付');
        }
        ScopeService::query('seckill_order', $aid)->where('id', $orderId)->update([
            'status'  => self::STATUS_PAID,
            'paytime' => time(),
            'paytype' => '后台支付',
        ]);
    }

    /**
     * 核销并确认收货（status 1已支付 / 2已发货 → 3已收货）
     */
    public function orderHexiao(int $aid, int $orderId): void
    {
        $order = ScopeService::query('seckill_order', $aid)->where('id', $orderId)->find();
        if (empty($order)) {
            throw new BusinessException('订单不存在');
        }
        if (!in_array((int) $order['status'], [self::STATUS_PAID, self::STATUS_SHIPPED], true)) {
            throw new BusinessException('订单状态不符合核销收货要求');
        }
        ScopeService::query('seckill_order', $aid)->where('id', $orderId)->update([
            'status'       => self::STATUS_FINISHED,
            'collect_time' => time(),
        ]);
    }

    /**
     * 手动发货（已支付→已发货；已发货时更新物流单号）
     * POST /admin/seckill/order/sendExpress {id, express_com, express_no}
     */
    public function orderSendExpress(int $aid, int $orderId, string $expressCom, string $expressNo): void
    {
        $order = ScopeService::query('seckill_order', $aid)->where('id', $orderId)->find();
        if (empty($order)) {
            throw new BusinessException('订单不存在');
        }
        $status = (int) $order['status'];
        if (!in_array($status, [self::STATUS_PAID, self::STATUS_SHIPPED], true)) {
            throw new BusinessException('当前订单状态不允许发货');
        }
        $expressCom = trim($expressCom);
        $expressNo  = trim($expressNo);
        if ('' === $expressCom) {
            throw new BusinessException('请选择快递公司');
        }
        if ('' === $expressNo) {
            throw new BusinessException('请输入快递单号');
        }

        ScopeService::query('seckill_order', $aid)->where('id', $orderId)->update([
            'express_com' => $expressCom,
            'express_no'  => $expressNo,
            'send_time'   => time(),
            'status'      => self::STATUS_SHIPPED,
        ]);
    }

    /**
     * 快递公司下拉（手动发货用，取快递鸟字典 name/remark）
     */
    public function expressOptions(): array
    {
        $list = [];
        foreach (Kdniao::expressData() as $code => $info) {
            $list[] = ['code' => $code, 'name' => (string) ($info['name'] ?? $code)];
        }

        return $list;
    }

    /**
     * 物流轨迹查询（真实对接快递鸟 1002，EWAYBILL.MOCK=1 时返回演示轨迹）
     *
     * @return array{express_com:string, express_no:string, traces:array}
     */
    public function orderTrace(int $aid, int $orderId): array
    {
        $order = ScopeService::query('seckill_order', $aid)->where('id', $orderId)->find();
        if (empty($order)) {
            throw new BusinessException('订单不存在');
        }
        $expressNo = trim((string) ($order['express_no'] ?? ''));
        $expressCom = trim((string) ($order['express_com'] ?? ''));
        if ('' === $expressNo) {
            throw new BusinessException('该订单尚未发货，暂无物流轨迹');
        }

        // 快递公司名称 → 快递鸟 shipper code
        $shipperCode = '';
        foreach (Kdniao::expressData() as $code => $info) {
            if ('' !== $expressCom && ((string) ($info['name'] ?? '') === $expressCom)) {
                $shipperCode = (string) $code;
                break;
            }
        }
        // 兜底：若直接存的就是 code（电子面单回写 express_com 为名称）
        if ('' === $shipperCode) {
            $shipperCode = $expressCom;
        }

        $kdniao = new Kdniao($aid, 0);
        $res    = $kdniao->trace($shipperCode, $expressNo, (string) ($order['ordernum'] ?? ''));

        $traces = [];
        if (1 === (int) ($res['status'] ?? 0)) {
            foreach ((array) ($res['data']['Traces'] ?? []) as $t) {
                $traces[] = [
                    'time'    => (string) ($t['AcceptTime'] ?? ''),
                    'station' => (string) ($t['AcceptStation'] ?? ''),
                ];
            }
        } else {
            throw new BusinessException((string) ($res['msg'] ?? '物流查询失败'));
        }

        return [
            'express_com' => $expressCom,
            'express_no'  => $expressNo,
            'send_time'   => (int) ($order['send_time'] ?? 0),
            'traces'       => $traces,
        ];
    }

    /**
     * 送货单数据（按订单自动生成，前端整页打印）
     */
    public function orderDeliveryNote(int $aid, int $orderId): array
    {
        $order = ScopeService::query('seckill_order', $aid)->where('id', $orderId)->find();
        if (empty($order)) {
            throw new BusinessException('订单不存在');
        }

        return [
            'ordernum'    => (string) $order['ordernum'],
            'createtime'  => date('Y-m-d H:i', (int) $order['createtime']),
            'proname'     => (string) $order['proname'],
            'propic'      => (string) ($order['propic'] ?? ''),
            'ggname'      => (string) ($order['ggname'] ?: '默认规格'),
            'num'         => (int) $order['num'],
            'sell_price'  => (float) $order['sell_price'],
            'totalprice'  => (float) $order['totalprice'],
            'linkman'     => (string) $order['linkman'],
            'tel'         => (string) $order['tel'],
            'address'     => trim(((string) ($order['area'] ?? '')) . ' ' . ((string) ($order['address'] ?? ''))),
            'freight_text' => (string) ($order['freight_text'] ?? ''),
            'express_com' => (string) ($order['express_com'] ?? ''),
            'express_no'  => (string) ($order['express_no'] ?? ''),
        ];
    }

    /**
     * 订单导出行
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<int, mixed>>
     */
    public function orderExportRows(int $aid, array $filters): array
    {
        $query = ScopeService::queryAs('seckill_order', $aid, 'o');
        $status = trim((string) ($filters['status'] ?? ''));
        if ('' !== $status) {
            $query->where('o.status', (int) $status);
        }
        foreach (['ordernum', 'proname', 'linkman', 'tel'] as $field) {
            $val = trim((string) ($filters[$field] ?? ''));
            if ('' !== $val) {
                $query->whereLike('o.' . $field, '%' . $val . '%');
            }
        }
        $rows = $query->order('o.id', 'desc')->limit(5000)->select()->toArray();
        $statusText = [0 => '未支付', 1 => '已支付', 2 => '已发货', 3 => '已收货', 4 => '已关闭'];
        $data = [];
        foreach ($rows as $vo) {
            $data[] = [
                ' ' . (string) $vo['ordernum'],
                $vo['proname'] ?? '',
                $vo['ggname'] ?? '',
                $vo['num'] ?? 1,
                $vo['sell_price'] ?? '',
                $vo['totalprice'] ?? '',
                $vo['paytype'] ?? '',
                $vo['linkman'] ?? '',
                $vo['tel'] ?? '',
                trim(($vo['area'] ?? '') . ' ' . ($vo['address'] ?? '')),
                $vo['remark'] ?? '',
                date('Y-m-d H:i:s', (int) ($vo['createtime'] ?? 0)),
                $statusText[(int) ($vo['status'] ?? 0)] ?? '未知',
            ];
        }

        return $data;
    }

    /* ================= 秒杀评价（对齐 canzhao SeckillComment） ================= */

    /**
     * 评价列表（status 空=全部 0待审核 1显示 2驳回）
     *
     * @param array<string, mixed> $filters
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function commentList(int $aid, int $page, int $pagesize, array $filters): array
    {
        $query = ScopeService::queryAs('seckill_comment', $aid, 'c')
            ->leftJoin('member m', 'm.id = c.mid')
            ->leftJoin('seckill_product p', 'p.id = c.proid')
            ->field('c.*, m.nickname, m.headimg, p.name as proname, p.pic as propic');

        $status = trim((string) ($filters['status'] ?? ''));
        if ('' !== $status) {
            $query->where('c.status', (int) $status);
        }
        $content = trim((string) ($filters['content'] ?? ''));
        if ('' !== $content) {
            $query->whereLike('c.content', '%' . $content . '%');
        }
        $proid = (int) ($filters['proid'] ?? 0);
        if ($proid > 0) {
            $query->where('c.proid', $proid);
        }
        $start = trim((string) ($filters['start_date'] ?? ''));
        if ('' !== $start) {
            $query->where('c.createtime', '>=', strtotime($start));
        }
        $end = trim((string) ($filters['end_date'] ?? ''));
        if ('' !== $end) {
            $query->where('c.createtime', '<', strtotime($end) + 86400);
        }

        $total = (clone $query)->count();
        $rows = $query->order('c.id', 'desc')->page($page, $pagesize)->select()->toArray();

        $list = array_map(static function (array $r): array {
            $st = (int) ($r['status'] ?? 0);
            return [
                'id'            => (int) $r['id'],
                'mid'           => (int) $r['mid'],
                'proid'         => (int) $r['proid'],
                'proname'       => (string) ($r['proname'] ?? ''),
                'propic'        => (string) ($r['propic'] ?? ''),
                'nickname'      => (string) ($r['nickname'] ?? ''),
                'headimg'       => (string) ($r['headimg'] ?? ''),
                'score'         => (int) ($r['score'] ?? 5),
                'content'       => (string) ($r['content'] ?? ''),
                'content_pic'   => (string) ($r['content_pic'] ?? ''),
                'reply_content' => (string) ($r['reply_content'] ?? ''),
                'reply_time'    => (int) ($r['reply_time'] ?? 0),
                'status'        => $st,
                'status_text'   => [0 => '待审核', 1 => '已显示', 2 => '已驳回'][$st] ?? '未知',
                'createtime'    => (int) ($r['createtime'] ?? 0),
            ];
        }, $rows);

        return ['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pagesize];
    }

    /**
     * 评价审核（status 1显示 2驳回；支持批量 ids[]）
     *
     * @param array<int, int> $ids
     */
    public function commentSetStatus(int $aid, array $ids, int $status): void
    {
        if (1 !== $status && 2 !== $status && 0 !== $status) {
            throw new BusinessException('非法的状态');
        }
        if (empty($ids)) {
            throw new BusinessException('请选择评价');
        }
        ScopeService::query('seckill_comment', $aid)->whereIn('id', $ids)->update(['status' => $status]);
    }

    /**
     * 评价回复
     */
    public function commentReply(int $aid, int $id, string $content): void
    {
        $content = trim($content);
        if ('' === $content) {
            throw new BusinessException('请输入回复内容');
        }
        ScopeService::query('seckill_comment', $aid)->where('id', $id)->update([
            'reply_content' => $content,
            'reply_time'    => time(),
        ]);
    }

    /**
     * 删除评价（单 id 或批量 ids[]）
     *
     * @param array<int, int> $ids
     */
    public function commentDelete(int $aid, array $ids): void
    {
        if (empty($ids)) {
            throw new BusinessException('请选择评价');
        }
        ScopeService::query('seckill_comment', $aid)->whereIn('id', $ids)->delete();
    }
    /* ================= 库存扣减 / 关单核心 ================= */

    /**
     * 下单扣库存（防超卖核心，客户端下单流程调用）
     *
     * 条件更新 where stock >= num：并发下只有一个请求能扣成功，
     * affected=0 即库存不足，杜绝旧系统「先查后扣」的超卖窗口。
     * 规格扣减失败时先回补商品库存，保证成对。
     */
    public function deductStock(int $aid, int $proid, int $ggid, int $num): bool
    {
        if ($num < 1) {
            return false;
        }

        $affected = Db::name('seckill_product')
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
            $ggAffected = Db::name('seckill_guige')
                ->where('aid', $aid)
                ->where('id', $ggid)
                ->where('stock', '>=', $num)
                ->dec('stock', $num)
                ->inc('sales', $num)
                ->update();

            if ($ggAffected <= 0) {
                // 规格库存不足：回补商品侧，维持扣减成对
                Db::name('seckill_product')
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
     * 场次库存扣减（下单时按所在场次扣 seckill_prodata.seckill_num，同样条件更新防超卖）
     */
    public function deductProdata(int $aid, int $prodataId, int $num): bool
    {
        if ($num < 1) {
            return false;
        }

        $affected = Db::name('seckill_prodata')
            ->where('aid', $aid)
            ->where('id', $prodataId)
            ->where('seckill_num', '>=', Db::raw('sales + ' . $num))
            ->inc('sales', $num)
            ->update();

        return $affected > 0;
    }

    /**
     * 限购累计口径：只计有效单（未付/已付/已发货/已收货），关闭单不计
     */
    public function boughtNum(int $aid, int $mid, int $proid): int
    {
        return (int) Db::name('seckill_order')
            ->where('aid', $aid)
            ->where('mid', $mid)
            ->where('proid', $proid)
            ->whereIn('status', [self::STATUS_UNPAID, self::STATUS_PAID, self::STATUS_SHIPPED, self::STATUS_FINISHED])
            ->sum('num');
    }

    /**
     * 定时任务：扫描全部租户超时未支付秒杀单并关闭
     *
     * 超时口径：createtime + 租户 seckill_sysset.autoclose 分钟 <= 当前时间
     *
     * @return int 本次关闭笔数
     */
    public function closeExpired(): int
    {
        $now    = time();
        $orders = Db::name('seckill_order')
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
                $autoclose = Db::name('seckill_sysset')->where('aid', $aid)->value('autoclose');
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
     * C 端场次商品列表（复用 prodataList 场次口径，仅取上架商品）
     *
     * @return array{list: array, total: int, page: int, pagesize: int, timeset: array, duration: int}
     */
    public function apiProductList(int $aid, string $date, int $seckillTime, int $page, int $pagesize): array
    {
        $filters = [];

        if ('' !== $date) {
            $filters['date'] = $date;
        }

        if ($seckillTime > 0) {
            $filters['time'] = $seckillTime;
        }

        $data = $this->prodataList($aid, $page, $pagesize, $filters);

        $data['list'] = array_values(array_filter(
            $data['list'],
            static fn (array $row): bool => 1 === (int) ($row['prostatus'] ?? 0)
        ));

        return $data;
    }

    /**
     * C 端秒杀商品详情（商品 + 规格行 + 场次行）
     *
     * @return array<string, mixed>
     */
    public function apiProductDetail(int $aid, int $proid, string $date = '', int $seckillTime = 0): array
    {
        $product = $this->findProduct($aid, $proid);

        if (1 !== (int) ($product['status'] ?? 0) || 1 !== (int) ($product['ischecked'] ?? 1)) {
            throw new BusinessException('秒杀商品不存在或已下架');
        }

        $proQuery = ScopeService::query('seckill_prodata', $aid)->where('proid', $proid);

        if ('' !== $date) {
            $proQuery->where('seckill_date', $date);
        }

        if ($seckillTime > 0) {
            $proQuery->where('seckill_time', $seckillTime);
        }

        $sysset = $this->getSysset($aid);

        $prodata = array_map(static fn (array $p): array => [
            'id'            => (int) $p['id'],
            'seckill_date'  => (string) ($p['seckill_date'] ?? ''),
            'seckill_time'  => (int) ($p['seckill_time'] ?? 0),
            'starttime'     => (int) ($p['starttime'] ?? 0),
            'endtime'       => (int) ($p['starttime'] ?? 0) + (int) $sysset['duration'] * 3600,
            'seckill_price' => (float) ($p['seckill_price'] ?? 0),
            'seckill_num'   => (int) ($p['seckill_num'] ?? 0),
            'sales'         => (int) ($p['sales'] ?? 0),
            'buymax'        => (int) ($p['buymax'] ?? 0),
        ], $proQuery->order('starttime', 'asc')->select()->toArray());

        return [
            'id'           => (int) $product['id'],
            'name'         => (string) ($product['name'] ?? ''),
            'pic'          => (string) ($product['pic'] ?? ''),
            'pics'         => (string) ($product['pics'] ?? ''),
            'detail'       => (string) ($product['detail'] ?? ''),
            'market_price' => (float) ($product['market_price'] ?? 0),
            'sell_price'   => (float) ($product['sell_price'] ?? 0),
            'stock'        => (int) ($product['stock'] ?? 0),
            'sales'        => (int) ($product['sales'] ?? 0),
            'perlimit'     => (int) ($product['perlimit'] ?? 0),
            'givescore'    => (int) ($product['givescore'] ?? 0),
            'guige'        => ScopeService::query('seckill_guige', $aid)
                ->where('proid', $proid)->order('id', 'asc')->select()->toArray(),
            'prodata'      => $prodata,
        ];
    }

    /**
     * C 端秒杀下单（场次内 + 条件扣减防超卖 + 限购校验 + 写订单）
     *
     * @param array<string, mixed> $extra linkman/tel/area/address/message/platform/freight_price
     * @return array{orderid: int, ordernum: string, totalprice: float, status: int}
     */
    public function apiCreateOrder(int $aid, int $mid, int $proid, int $prodataId, int $ggid, int $num, array $extra = []): array
    {
        $num = max(1, $num);

        $sysset = $this->getSysset($aid);

        if (1 !== (int) ($sysset['status'] ?? 0)) {
            throw new BusinessException('秒杀活动尚未开启');
        }

        return Db::transaction(function () use ($aid, $mid, $proid, $prodataId, $ggid, $num, $extra, $sysset) {
            $product = Db::name('seckill_product')
                ->where('aid', $aid)->where('id', $proid)
                ->where('status', 1)->lock(true)->find();

            if (empty($product) || 1 !== (int) ($product['ischecked'] ?? 1)) {
                throw new BusinessException('秒杀商品不存在或已下架');
            }

            $prodata = Db::name('seckill_prodata')
                ->where('aid', $aid)->where('id', $prodataId)->where('proid', $proid)->find();

            if (empty($prodata)) {
                throw new BusinessException('秒杀场次不存在');
            }

            // 场次时间窗：starttime ≤ now ≤ starttime + duration 小时
            $now      = time();
            $startAt  = (int) ($prodata['starttime'] ?? 0);
            $endAt    = $startAt + (int) $sysset['duration'] * 3600;

            if ($now < $startAt) {
                throw new BusinessException('秒杀尚未开始，请稍后再来');
            }
            if ($now > $endAt) {
                throw new BusinessException('本场秒杀已结束');
            }

            // 限购：商品 perlimit + 场次 buymax（0 不限），只计有效单
            $bought   = $this->boughtNum($aid, $mid, $proid);
            $perlimit = (int) ($product['perlimit'] ?? 0);

            if ($perlimit > 0 && $bought + $num > $perlimit) {
                throw new BusinessException('超出限购数量（每人限购 ' . $perlimit . ' 件）');
            }

            $buymax = (int) ($prodata['buymax'] ?? 0);

            if ($buymax > 0 && $bought + $num > $buymax) {
                throw new BusinessException('超出本场限购数量（每人限购 ' . $buymax . ' 件）');
            }

            // 场次库存扣减（条件更新防超卖）
            if (!$this->deductProdata($aid, $prodataId, $num)) {
                throw new BusinessException('本场秒杀已被抢光了');
            }

            // 商品/规格库存扣减（失败回补场次销量，维持成对）
            if (!$this->deductStock($aid, $proid, $ggid, $num)) {
                Db::name('seckill_prodata')
                    ->where('aid', $aid)->where('id', $prodataId)->where('sales', '>=', $num)
                    ->dec('sales', $num)->update();

                throw new BusinessException('库存不足，已被抢光了');
            }

            $ggname = '';

            if ($ggid > 0) {
                $ggname = (string) Db::name('seckill_guige')
                    ->where('aid', $aid)->where('id', $ggid)->where('proid', $proid)
                    ->value('name');
            }

            $seckillPrice = (float) ($prodata['seckill_price'] ?? 0);
            $freightFee   = round(max(0, (float) ($extra['freight_price'] ?? 0)), 2);
            $totalPrice   = round($seckillPrice * $num + $freightFee, 2);
            $ordernum     = date('ymdHis') . $aid . random_int(1000, 9999);

            $orderId = (int) Db::name('seckill_order')->insertGetId([
                'aid'           => $aid,
                'bid'           => (int) ($product['bid'] ?? 0),
                'mid'           => $mid,
                'ordernum'      => $ordernum,
                'title'         => (string) $product['name'],
                'proid'         => $proid,
                'proname'       => (string) $product['name'],
                'propic'        => (string) ($product['pic'] ?? ''),
                'ggid'          => $ggid,
                'ggname'        => $ggname,
                'num'           => $num,
                'cost_price'    => (float) ($product['cost_price'] ?? 0),
                'sell_price'    => $seckillPrice,
                'totalprice'    => $totalPrice,
                'product_price' => round($seckillPrice * $num, 2),
                'freight_price' => $freightFee,
                'givescore'     => (int) ($product['givescore'] ?? 0),
                'createtime'    => $now,
                'status'        => self::STATUS_UNPAID,
                'linkman'       => trim((string) ($extra['linkman'] ?? '')),
                'tel'           => trim((string) ($extra['tel'] ?? '')),
                'area'          => trim((string) ($extra['area'] ?? '')),
                'address'       => trim((string) ($extra['address'] ?? '')),
                'message'       => trim((string) ($extra['message'] ?? '')),
                'freight_text'  => trim((string) ($extra['freight_text'] ?? '包邮')),
                'hexiao_code'   => strtoupper(bin2hex(random_bytes(8))),
                'platform'      => (string) ($extra['platform'] ?? 'wx'),
            ]);

            // 支付单（type=seckill）
            Db::name('payorder')->insert([
                'aid'        => $aid,
                'bid'        => (int) ($product['bid'] ?? 0),
                'mid'        => $mid,
                'orderid'    => $orderId,
                'ordernum'   => $ordernum,
                'title'      => (string) $product['name'],
                'money'      => $totalPrice,
                'score'      => 0,
                'type'       => 'seckill',
                'createtime' => $now,
                'status'     => 0,
                'platform'   => (string) ($extra['platform'] ?? 'wx'),
            ]);

            // mock 模式即时支付成功（真实模式由支付回调走 paySeckillOrder）
            if (PayService::isMock()) {
                $this->paySeckillOrder($aid, $orderId, 'wechat', 'MOCK' . time() . random_int(1000, 9999));
            }

            return [
                'orderid'    => $orderId,
                'ordernum'   => $ordernum,
                'totalprice' => $totalPrice,
                'status'     => PayService::isMock() ? self::STATUS_PAID : self::STATUS_UNPAID,
            ];
        });
    }

    /**
     * 秒杀订单支付成功入账（幂等：仅 status=0 → 1）
     */
    public function paySeckillOrder(int $aid, int $orderId, string $payType, string $payNum): bool
    {
        $order = ScopeService::query('seckill_order', $aid)->where('id', $orderId)->find();

        if (empty($order) || self::STATUS_UNPAID !== (int) $order['status']) {
            return false;
        }

        $affected = Db::name('seckill_order')
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

        Db::name('payorder')
            ->where('aid', $aid)->where('type', 'seckill')->where('ordernum', (string) $order['ordernum'])
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
     * 我的秒杀订单（C 端分页）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function apiMyOrders(int $aid, int $mid, string $status, int $page, int $pagesize): array
    {
        $query = ScopeService::query('seckill_order', $aid)->where('mid', $mid);

        if ('' !== $status) {
            $query->where('status', (int) $status);
        }

        $total = (clone $query)->count();

        $rows = $query->order('id', 'desc')->page($page, $pagesize)->select()->toArray();

        return ['list' => $rows, 'total' => $total, 'page' => $page, 'pagesize' => $pagesize];
    }

    /* ================= 内部工具 ================= */

    /**
     * 关单入账（幂等核心，事务内执行）：
     * 1. 行锁读取订单，非未支付直接返回
     * 2. status=0 条件更新为 4，并发下只有一个调用方能成功
     * 3. 回补商品/规格库存，销量回退（不低于0）
     * 4. 退还订单占用的优惠券
     */
    protected function closeInternal(int $orderId): bool
    {
        return (bool) Db::transaction(function () use ($orderId): bool {
            $order = Db::name('seckill_order')->where('id', $orderId)->lock(true)->find();

            if (empty($order) || self::STATUS_UNPAID !== (int) $order['status']) {
                return false;
            }

            $num = max(1, (int) $order['num']);

            // 条件更新：只有仍是未支付才关单（幂等防重复回补）
            $affected = Db::name('seckill_order')
                ->where('id', $orderId)
                ->where('status', self::STATUS_UNPAID)
                ->update(['status' => self::STATUS_CLOSED]);

            if ($affected <= 0) {
                return false;
            }

            // 库存回补 + 销量回退（sales 不低于 0）
            Db::name('seckill_product')
                ->where('aid', (int) $order['aid'])
                ->where('id', (int) $order['proid'])
                ->update([
                    'stock' => Db::raw('stock + ' . $num),
                    'sales' => Db::raw('IF(sales >= ' . $num . ', sales - ' . $num . ', 0)'),
                ]);

            if ((int) $order['ggid'] > 0) {
                Db::name('seckill_guige')
                    ->where('aid', (int) $order['aid'])
                    ->where('id', (int) $order['ggid'])
                    ->update([
                        'stock' => Db::raw('stock + ' . $num),
                        'sales' => Db::raw('IF(sales >= ' . $num . ', sales - ' . $num . ', 0)'),
                    ]);
            }

            // 优惠券抵扣返还（幂等）
            if ((int) ($order['coupon_rid'] ?? 0) > 0) {
                app(CouponService::class)->releaseByOrder((int) $order['coupon_rid'], $orderId);
            }

            return true;
        });
    }

    /**
     * 查找本租户秒杀商品（不存在抛错）
     *
     * @return array<string, mixed>
     */
    protected function findProduct(int $aid, int $id): array
    {
        if ($id <= 0) {
            throw new BusinessException('缺少秒杀商品ID');
        }

        $product = ScopeService::query('seckill_product', $aid)->where('id', $id)->find();
        if (empty($product)) {
            throw new BusinessException('秒杀商品不存在');
        }

        return $product;
    }

    /**
     * 规格行保存（upsert：带 id 更新，不带新增；从未建过规格时补默认规格）
     *
     * @param array $guigeInput [{id?, name, sell_price, stock}]
     */
    protected function saveGuige(int $aid, int $proid, array $guigeInput, float $sellPrice, float $marketPrice, int $defaultStock): void
    {
        $existingIds = array_map('intval', ScopeService::query('seckill_guige', $aid)
            ->where('proid', $proid)
            ->column('id'));

        if (empty($guigeInput) && empty($existingIds)) {
            Db::name('seckill_guige')->insert([
                'aid'          => $aid,
                'proid'        => $proid,
                'name'         => '默认',
                'market_price' => $marketPrice,
                'sell_price'   => $sellPrice,
                'stock'        => max(0, $defaultStock),
                'sales'        => 0,
            ]);

            return;
        }

        foreach ($guigeInput as $gg) {
            if (!is_array($gg)) {
                continue;
            }

            $ggId   = (int) ($gg['id'] ?? 0);
            $row    = [
                'name'         => trim((string) ($gg['name'] ?? '')) ?: '默认',
                'market_price' => $this->money($gg['market_price'] ?? $marketPrice),
                'sell_price'   => $this->money($gg['sell_price'] ?? $sellPrice),
                'cost_price'   => $this->money($gg['cost_price'] ?? 0),
                'weight'       => max(0, (int) ($gg['weight'] ?? 0)),
                'givescore'    => max(0, (int) ($gg['givescore'] ?? 0)),
                'pic'          => trim((string) ($gg['pic'] ?? '')),
                'stock'        => max(0, (int) ($gg['stock'] ?? 0)),
            ];

            if ($ggId > 0 && in_array($ggId, $existingIds, true)) {
                Db::name('seckill_guige')->where('aid', $aid)->where('id', $ggId)->where('proid', $proid)->update($row);
            } else {
                Db::name('seckill_guige')->insert(array_merge($row, [
                    'aid'   => $aid,
                    'proid' => $proid,
                    'sales' => 0,
                ]));
            }
        }
    }

    /**
     * 场次时间点解析（去重排序，限 0-23 整点）
     *
     * @return int[]
     */
    protected function parseTimeset(string $raw): array
    {
        $list = [];

        foreach (explode(',', $raw) as $one) {
            $hour = (int) trim($one);
            if ('' !== trim($one) && $hour >= 0 && $hour <= 23) {
                $list[$hour] = $hour;
            }
        }

        ksort($list);

        return array_values($list);
    }

    /**
     * 金额归一：保留两位（round(x*100)/100 口径）
     */
    protected function money(mixed $value): float
    {
        return round((float) $value * 100) / 100;
    }
}
