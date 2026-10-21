<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;

/**
 * 周期购服务（admin 后台管理 + C 端下单链路预留，简化版口径）
 *
 * 表口径（基线 cycle_* 表，金额单位「元」）：
 * - cycle_sysset      设置：autoshdays 自动收货天数 / comment 评价开关
 * - cycle_category    分类：name / pid / sort / status
 * - cycle_product     商品：sell_price 取规格最低价 / stock 规格库存合计 / ps_cycle 配送周期类型
 * - cycle_guige       规格动态行：cycle_days 配送周期(天) / qsnum 期数(总次数) / each_num 每期数量
 *                     / sell_price 价格 / stock 库存
 * - cycle_order       周期购订单：buytype 1包月 2包季 3包年
 *                     status 0未支付 1已支付 2已发货 3已收货 4已关闭
 *                     refund_status 0无 1审核中 2已退款 3已驳回
 * - cycle_order_stage 分期发货计划：每期一行，status 0创建 1待发货(已支付) 2已发货 3已完成
 *
 * 简化版范围：不做 stage 调度自动化（到期自动生成/自动发货均不实现），
 * 下单时按规格（周期×期数）一次性生成全部分期计划行。
 */
class CycleService
{
    /** 订单状态 */
    public const ORDER_UNPAID  = 0;
    public const ORDER_PAID    = 1;
    public const ORDER_SHIPPED = 2;
    public const ORDER_COLLECT = 3;
    public const ORDER_CLOSED  = 4;

    /** 分期计划状态 */
    public const STAGE_CREATED = 0;
    public const STAGE_PAID    = 1;
    public const STAGE_SHIPPED = 2;
    public const STAGE_DONE    = 3;

    /** 购买方式 */
    public const BUYTYPE_TEXT = [1 => '包月', 2 => '包季', 3 => '包年'];

    public function __construct(protected PayService $payService)
    {
    }

    /* ================= 系统设置 ================= */

    /**
     * 读取周期购设置（无记录给默认）
     *
     * 对齐参考系统系统设置截图：自动收货天数 / 商品评价开关 / 评价审核开关 / 会员等级折扣开关
     * 点大口径：comment_check=开启(1) 时用户评价需后台审核才展示；关闭(0) 后评价直接展示。
     *
     * @return array{autoshdays: int, comment: int, comment_check: int, vipdiscount: int}
     */
    public function getSysset(int $aid): array
    {
        $info = ScopeService::query('cycle_sysset', $aid)->find();

        return [
            'autoshdays'   => (int) ($info['autoshdays'] ?? 7),
            'comment'      => isset($info['comment']) ? (int) $info['comment'] : 1,
            'comment_check' => isset($info['comment_check']) ? (int) $info['comment_check'] : 1,
            'vipdiscount'  => isset($info['vipdiscount']) ? (int) $info['vipdiscount'] : 1,
        ];
    }

    /**
     * 保存周期购设置（upsert）
     *
     * @param array<string, mixed> $data
     */
    public function saveSysset(int $aid, array $data): void
    {
        $update = [];

        if (array_key_exists('autoshdays', $data)) {
            $days = (int) $data['autoshdays'];

            if ($days < 1 || $days > 90) {
                throw new BusinessException('自动收货天数须在 1-90 之间');
            }

            $update['autoshdays'] = $days;
        }
        if (array_key_exists('comment', $data)) {
            $update['comment'] = (int) $data['comment'] === 1 ? 1 : 0;
        }
        // 评价审核：开启(1) 需后台审核；关闭(0) 用户评价直接展示
        if (array_key_exists('comment_check', $data)) {
            $update['comment_check'] = (int) $data['comment_check'] === 1 ? 1 : 0;
        }
        // 会员等级折扣：是否开启
        if (array_key_exists('vipdiscount', $data)) {
            $update['vipdiscount'] = (int) $data['vipdiscount'] === 1 ? 1 : 0;
        }

        if (empty($update)) {
            throw new BusinessException('没有可保存的设置项');
        }

        $exists = ScopeService::query('cycle_sysset', $aid)->find();

        if (empty($exists)) {
            Db::name('cycle_sysset')->insert(array_merge([
                'aid'          => $aid,
                'autoshdays'   => 7,
                'comment'      => 1,
                'comment_check' => 1,
                'vipdiscount'  => 1,
            ], $update));

            return;
        }

        Db::name('cycle_sysset')->where('id', (int) $exists['id'])->update($update);
    }

    /* ================= 商品分类 ================= */

    /**
     * 分类列表（平铺，按 sort/id 倒序）
     *
     * @return array{list: array}
     */
    public function categoryList(int $aid): array
    {
        $rows = ScopeService::query('cycle_category', $aid)
            ->order('sort', 'desc')->order('id', 'desc')
            ->select()
            ->toArray();

        foreach ($rows as &$row) {
            $row['status_text']  = 1 === (int) $row['status'] ? '启用' : '停用';
            $row['product_num']  = (int) ScopeService::query('cycle_product', $aid)
                ->where('cid', $row['id'])->count();
        }
        unset($row);

        return ['list' => $rows];
    }

    /**
     * 分类新增/编辑
     *
     * @param array<string, mixed> $data
     * @return array{id: int}
     */
    public function categorySave(int $aid, array $data): array
    {
        $id   = (int) ($data['id'] ?? 0);
        $name = trim((string) ($data['name'] ?? ''));

        if ('' === $name) {
            throw new BusinessException('请填写分类名称');
        }

        $row = [
            'name'   => $name,
            'pic'    => trim((string) ($data['pic'] ?? '')),
            'pid'    => (int) ($data['pid'] ?? 0),
            'sort'   => (int) ($data['sort'] ?? 0),
            'status' => (int) ($data['status'] ?? 1) === 1 ? 1 : 0,
        ];

        if ($id > 0) {
            $exists = ScopeService::query('cycle_category', $aid)->where('id', $id)->find();

            if (empty($exists)) {
                throw new BusinessException('分类不存在', 404, 404);
            }

            Db::name('cycle_category')->where('id', (int) $exists['id'])->update($row);

            return ['id' => $id];
        }

        $row['aid']        = $aid;
        $row['createtime'] = time();

        return ['id' => (int) Db::name('cycle_category')->insertGetId($row)];
    }

    /**
     * 分类删除（已有商品时拒绝）
     */
    public function categoryDelete(int $aid, int $id): void
    {
        $exists = ScopeService::query('cycle_category', $aid)->where('id', $id)->find();

        if (empty($exists)) {
            throw new BusinessException('分类不存在', 404, 404);
        }

        $productNum = (int) ScopeService::query('cycle_product', $aid)->where('cid', $id)->count();

        if ($productNum > 0) {
            throw new BusinessException('该分类下已有商品，不能删除');
        }

        Db::name('cycle_category')->where('id', (int) $exists['id'])->delete();
    }

    /* ================= 商品管理 ================= */

    /**
     * 商品列表（附分类名/规格行）
     *
     * @param array<string, mixed> $filters keyword / status / cid
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function productList(int $aid, int $page, int $pageSize, array $filters): array
    {
        $query = ScopeService::query('cycle_product', $aid);

        // 商户维度过滤（任务177 showtype-2：''/all 不过滤，0 自营，gt0 商户）
        ScopeService::applyBid($query, (string) ($filters['bid'] ?? ''), 'bid');

        if (!empty($filters['keyword'])) {
            $query->whereLike('name', '%' . trim((string) $filters['keyword']) . '%');
        }
        if (isset($filters['status']) && '' !== (string) $filters['status']) {
            $query->where('status', (int) $filters['status']);
        }
        if (!empty($filters['cid'])) {
            $query->where('cid', (int) $filters['cid']);
        }

        if (isset($filters['checked']) && '' !== (string) $filters['checked']) {
            $query->where('ischecked', (int) $filters['checked']);
        }

        $total = (clone $query)->count();

        // 排序白名单
        $allowSort = ['id' => 'id', 'sales' => 'sales', 'stock' => 'stock', 'sort' => 'sort', 'createtime' => 'createtime'];
        $sortField = $allowSort[(string) ($filters['orderField'] ?? 'sort')] ?? 'sort';
        $sortOrder = 'asc' === strtolower((string) ($filters['orderType'] ?? 'desc')) ? 'asc' : 'desc';

        $rows = $query->order($sortField, $sortOrder)->order('id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        $cnames = ScopeService::query('cycle_category', $aid)->column('name', 'id');

        // 所属商户名批量取（bid=0 平台自营）
        $bids = array_values(array_filter(array_map(static fn (array $r): int => (int) ($r['bid'] ?? 0), $rows)));
        $bnames = [];
        if (!empty($bids)) {
            $bnames = Db::name('business')->where('aid', $aid)->whereIn('id', $bids)->column('name', 'id');
        }

        foreach ($rows as &$row) {
            $row['status_text'] = 1 === (int) $row['status'] ? '上架' : '下架';
            $row['cname']       = (string) ($cnames[(int) $row['cid']] ?? '');
            $row['bname']       = 0 === (int) ($row['bid'] ?? 0) ? '平台自营' : (string) ($bnames[(int) ($row['bid'] ?? 0)] ?? '');
            $row['guige_list']  = $this->guigeList($aid, (int) $row['id']);
        }
        unset($row);

        return ['list' => $rows, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize];
    }

    /**
     * 商品详情（编辑回显，含规格行）
     *
     * @return array<string, mixed>
     */
    public function productDetail(int $aid, int $id): array
    {
        $product = $this->findProduct($aid, $id);

        $product['guige_list'] = $this->guigeList($aid, (int) $product['id']);

        return $product;
    }

    /**
     * 新增/编辑商品（对齐 canzhao 片A：基本信息两部分 + 规格项目表）
     *
     * 商品级字段：name/pic/pics/cid/ps_cycle/everyday_item/advance_pay_days/advance_pay_time/
     *   advance_extend_days/min_num/min_qsnum/freighttype/freightdata/commissionset/sales/sort/status/detail/guigedata
     * 规格行 guiges：[{name, procode, market_price, sell_price, weight, stock, pic}]
     *   （订单分期所需 cycle_days/qsnum/each_num 由商品级 ps_cycle/min_num/min_qsnum 派生，保持跨片兼容）
     *
     * @param array<string, mixed> $data
     * @return array{id: int}
     */
    public function productSave(int $aid, array $data): array
    {
        $id = (int) ($data['id'] ?? 0);

        $name = trim((string) ($data['name'] ?? ''));
        if ('' === $name) {
            throw new BusinessException('请填写商品名称');
        }

        $pic = trim((string) ($data['pic'] ?? ''));
        if ('' === $pic) {
            throw new BusinessException('请上传商品主图');
        }

        $cid = (int) ($data['cid'] ?? 0);
        if ($cid > 0) {
            $category = ScopeService::query('cycle_category', $aid)->where('id', $cid)->find();

            if (empty($category)) {
                throw new BusinessException('所选商品分类不存在');
            }
        }

        $psCycle = (int) ($data['ps_cycle'] ?? 1);
        if (!in_array($psCycle, [1, 2, 3], true)) {
            throw new BusinessException('配送周期非法');
        }

        $minNum  = max(1, (int) ($data['min_num'] ?? 1));
        $minQsnum = max(1, (int) ($data['min_qsnum'] ?? 1));

        // 规格项目表（至少一行）
        $guiges = $data['guiges'] ?? $data['guige_list'] ?? [];

        if (!is_array($guiges) || empty($guiges)) {
            throw new BusinessException('请至少添加一条规格（销售价/库存）');
        }

        // 每日一期的配送日配置（每天1/工作日2/周末3/隔天4）
        $everydayMap = [1 => '每天配送', 2 => '工作日配送', 3 => '周末配送', 4 => '隔天配送'];
        $everydayItemRaw = $data['everyday_item'] ?? [];
        $everydayClean = [];
        if (is_array($everydayItemRaw)) {
            foreach ($everydayItemRaw as $val) {
                $v = (int) $val;
                if (isset($everydayMap[$v])) {
                    $everydayClean[] = ['value' => $v, 'label' => $everydayMap[$v]];
                }
            }
        }
        if (1 === $psCycle && empty($everydayClean)) {
            throw new BusinessException('每日一期请至少勾选一个配送日（每天/工作日/周末/隔天）');
        }

        // 规格行清洗
        $cleanGuiges = [];
        foreach ($guiges as $guige) {
            if (!is_array($guige)) {
                continue;
            }
            $sellPrice = round((float) ($guige['sell_price'] ?? 0), 2);
            if ($sellPrice <= 0) {
                throw new BusinessException('规格销售价须大于 0');
            }
            $stock = (int) ($guige['stock'] ?? 0);
            if ($stock < 0) {
                throw new BusinessException('规格库存不能为负数');
            }
            $cleanGuiges[] = [
                'name'         => trim((string) ($guige['name'] ?? '默认规格')),
                'procode'      => trim((string) ($guige['procode'] ?? '')),
                'market_price'  => round((float) ($guige['market_price'] ?? 0), 2),
                'sell_price'   => $sellPrice,
                'cost_price'   => round((float) ($guige['cost_price'] ?? 0), 2),
                'weight'       => (int) ($guige['weight'] ?? 0),
                'stock'        => $stock,
                'pic'          => trim((string) ($guige['pic'] ?? '')),
            ];
        }

        if (empty($cleanGuiges)) {
            throw new BusinessException('请至少添加一条规格（销售价/库存）');
        }

        // 商品主表：价格取规格最低，库存取规格合计
        $sellPrice = min(array_column($cleanGuiges, 'sell_price'));
        // 取最低价规格对应的市场价/重量
        $refIndex = array_search(min(array_column($cleanGuiges, 'sell_price')), array_column($cleanGuiges, 'sell_price'), true);
        $marketPrice = (float) ($cleanGuiges[$refIndex]['market_price'] ?? 0);
        $weight      = (int) ($cleanGuiges[$refIndex]['weight'] ?? 0);
        $stock       = array_sum(array_column($cleanGuiges, 'stock'));

        $freighttype = (int) ($data['freighttype'] ?? 1);
        $freighttype = in_array($freighttype, [1, 2], true) ? $freighttype : 1;
        $freightdata = 1 === $freighttype ? '' : trim((string) ($data['freightdata'] ?? ''));

        $commissionset = (int) ($data['commissionset'] ?? 0);
        $commissionset = in_array($commissionset, [0, 1, 2], true) ? $commissionset : 0;

        $row = [
            'name'               => $name,
            'pic'                => $pic,
            'pics'               => trim((string) ($data['pics'] ?? '')),
            'cid'                => $cid,
            'sell_price'         => $sellPrice,
            'market_price'       => $marketPrice,
            'weight'             => $weight,
            'stock'              => $stock,
            'sort'               => (int) ($data['sort'] ?? 0),
            'status'             => (int) ($data['status'] ?? 1) === 1 ? 1 : 0,
            'ps_cycle'           => $psCycle,
            'everyday_item'      => 1 === $psCycle ? json_encode($everydayClean, JSON_UNESCAPED_UNICODE) : '',
            'advance_pay_days'   => (int) ($data['advance_pay_days'] ?? 0),
            'advance_pay_time'   => (int) ($data['advance_pay_time'] ?? 0),
            'advance_extend_days' => (int) ($data['advance_extend_days'] ?? 0),
            'min_num'            => $minNum,
            'min_qsnum'          => $minQsnum,
            'freighttype'        => $freighttype,
            'freightdata'        => $freightdata,
            'commissionset'      => $commissionset,
            'guigedata'          => is_string($data['guigedata'] ?? null) ? $data['guigedata'] : json_encode($data['guigedata'] ?? [], JSON_UNESCAPED_UNICODE),
            'detail'             => (string) ($data['detail'] ?? ''),
        ];

        // 销量：仅在显式传入时回写（避免覆盖真实累计）
        if (isset($data['sales']) && '' !== (string) $data['sales']) {
            $row['sales'] = max(0, (int) $data['sales']);
        }

        // 派生值（供订单分期使用）：配送周期天数
        $cycleDays = 1 === $psCycle ? 1 : (2 === $psCycle ? 7 : 30);

        return Db::transaction(function () use ($aid, $id, $row, $cleanGuiges, $cycleDays, $minNum, $minQsnum) {
            if ($id > 0) {
                $product = $this->findProduct($aid, $id);

                Db::name('cycle_product')->where('id', (int) $product['id'])->update($row);
            } else {
                $row['aid']        = $aid;
                $row['bid']        = 0;
                $row['createtime'] = time();

                $id = (int) Db::name('cycle_product')->insertGetId($row);
            }

            // 规格行全量覆盖（先删后插，口径与参考系统 save 一致）
            Db::name('cycle_guige')->where('aid', $aid)->where('proid', $id)->delete();

            foreach ($cleanGuiges as $index => $guige) {
                Db::name('cycle_guige')->insert([
                    'aid'         => $aid,
                    'proid'       => $id,
                    'name'        => $guige['name'],
                    'procode'     => $guige['procode'],
                    'market_price' => $guige['market_price'],
                    'cost_price'  => $guige['cost_price'],
                    'sell_price'  => $guige['sell_price'],
                    'weight'      => $guige['weight'],
                    'stock'       => $guige['stock'],
                    'pic'         => $guige['pic'],
                    'ks'          => (string) $index,
                    // 派生：保持订单分期链路可用
                    'cycle_days'  => $cycleDays,
                    'qsnum'       => $minNum,
                    'each_num'    => $minQsnum,
                ]);
            }

            return ['id' => $id];
        });
    }

    /**
     * 商品删除（已有订单时拒绝）
     */
    public function productDelete(int $aid, array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) {
            throw new BusinessException('请选择要删除的商品');
        }

        foreach ($ids as $id) {
            $product = $this->findProduct($aid, $id);

            $orderNum = (int) ScopeService::query('cycle_order', $aid)->where('proid', $id)->count();
            if ($orderNum > 0) {
                throw new BusinessException('该商品已有周期购订单，不能删除');
            }

            Db::name('cycle_product')->where('id', (int) $product['id'])->delete();
            Db::name('cycle_guige')->where('aid', $aid)->where('proid', (int) $product['id'])->delete();
        }
    }

    /**
     * 商品上下架（支持批量 ids[]）
     */
    public function productStatus(int $aid, array $ids, int $status): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) {
            throw new BusinessException('请选择要操作的商品');
        }

        foreach ($ids as $id) {
            $this->findProduct($aid, $id);
        }

        Db::name('cycle_product')->where('aid', $aid)->whereIn('id', $ids)
            ->update(['status' => 1 === $status ? 1 : 0]);
    }

    /**
     * 商品审核（ischecked 1通过 2驳回）
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

        Db::name('cycle_product')->where('aid', $aid)->where('id', $id)->update([
            'ischecked'    => $ischecked,
            'check_reason' => 2 === $ischecked ? trim($reason) : '',
        ]);
    }

    /* ================= 周期购订单（状态机同商城） ================= */

    /**
     * 订单列表
     *
     * status 口径：''=全部；0-4 订单状态；r1/r2/r3 → refund_status 1/2/3
     * filters: status / ordernum / proname / linkman / tel / start_date / end_date
     *
     * @param array<string, mixed> $filters
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function orderList(int $aid, int $page, int $pageSize, array $filters): array
    {
        $query = ScopeService::queryAs('cycle_order', $aid, 'o')
            ->leftJoin('member m', 'm.id = o.mid')
            ->field('o.*, m.nickname, m.headimg')
            ->where(function ($q) {
                $q->whereNull('o.delete')->whereOr('o.delete', 0);
            });

        $status = (string) ($filters['status'] ?? '');

        if ('' !== $status) {
            if (str_starts_with($status, 'r')) {
                $refund = (int) substr($status, 1);

                if (!in_array($refund, [1, 2, 3], true)) {
                    throw new BusinessException('非法的退款状态');
                }

                $query->where('o.refund_status', $refund);
            } else {
                $query->where('o.status', (int) $status);
            }
        }

        if (!empty($filters['ordernum'])) {
            $query->whereLike('o.ordernum', '%' . trim((string) $filters['ordernum']) . '%');
        }
        if (!empty($filters['proname'])) {
            $query->whereLike('o.proname', '%' . trim((string) $filters['proname']) . '%');
        }
        if (!empty($filters['linkman'])) {
            $query->whereLike('o.linkman', '%' . trim((string) $filters['linkman']) . '%');
        }
        if (!empty($filters['tel'])) {
            $query->whereLike('o.tel', '%' . trim((string) $filters['tel']) . '%');
        }
        if (!empty($filters['start_date'])) {
            $query->where('o.createtime', '>=', strtotime((string) $filters['start_date']));
        }
        if (!empty($filters['end_date'])) {
            $query->where('o.createtime', '<', strtotime((string) $filters['end_date']) + 86400);
        }

        $total = (clone $query)->count();
        $rows  = $query->order('o.id', 'desc')->page($page, $pageSize)->select()->toArray();

        foreach ($rows as &$row) {
            $row['status_text']    = $this->orderStatusText((int) $row['status']);
            $row['refund_text']    = $this->refundStatusText((int) $row['refund_status']);
            $row['buytype_text']   = self::BUYTYPE_TEXT[(int) $row['buytype']] ?? '未知';
            $row['platform_text']  = $this->platformText((string) ($row['platform'] ?? ''));
            $row['stage_progress'] = $this->stageProgress($aid, (int) $row['id']);
            $row['stage_brief']    = $this->stageBrief($aid, (int) $row['id']);
        }
        unset($row);

        return ['list' => $rows, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize];
    }

    /**
     * 订单导出（按当前列表筛选返回全部匹配行，CSV 用，限 10000 行）
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function exportOrders(int $aid, array $filters): array
    {
        $query = ScopeService::queryAs('cycle_order', $aid, 'o')
            ->field('o.*')
            ->where(function ($q) {
                $q->whereNull('o.delete')->whereOr('o.delete', 0);
            });

        $status = (string) ($filters['status'] ?? '');
        if ('' !== $status) {
            if (str_starts_with($status, 'r')) {
                $refund = (int) substr($status, 1);
                if (in_array($refund, [1, 2, 3], true)) {
                    $query->where('o.refund_status', $refund);
                }
            } else {
                $query->where('o.status', (int) $status);
            }
        }
        if (!empty($filters['ordernum'])) {
            $query->whereLike('o.ordernum', '%' . trim((string) $filters['ordernum']) . '%');
        }
        if (!empty($filters['linkman'])) {
            $query->whereLike('o.linkman', '%' . trim((string) $filters['linkman']) . '%');
        }
        if (!empty($filters['tel'])) {
            $query->whereLike('o.tel', '%' . trim((string) $filters['tel']) . '%');
        }
        if (!empty($filters['start_date'])) {
            $query->where('o.createtime', '>=', strtotime((string) $filters['start_date']));
        }
        if (!empty($filters['end_date'])) {
            $query->where('o.createtime', '<', strtotime((string) $filters['end_date']) + 86400);
        }

        return $query->order('o.id', 'desc')->limit(10000)->select()->toArray();
    }

    /**
     * 订单详情（含分期发货计划 + 会员信息 + 可退金额）
     *
     * @return array<string, mixed>
     */
    public function orderDetail(int $aid, int $orderId): array
    {
        $order = $this->findOrder($aid, $orderId);

        $member = Db::name('member')
            ->field('id,nickname,headimg,realname,tel')
            ->where('id', (int) $order['mid'])->find();

        $order['status_text']  = $this->orderStatusText((int) $order['status']);
        $order['refund_text']  = $this->refundStatusText((int) $order['refund_status']);
        $order['buytype_text'] = self::BUYTYPE_TEXT[(int) $order['buytype']] ?? '未知';
        $order['member']       = empty($member) ? ['id' => $order['mid'], 'nickname' => ''] : $member;
        $order['stage_list']   = $this->stageList($aid, $orderId);
        $order['refund_money'] = $this->refundableMoney($order, $order['stage_list']);

        return $order;
    }

    /**
     * 按期发货（写当期 stage 状态 + 发货信息；已发货时仅更新物流）
     */
    public function orderShip(int $aid, int $stageId, string $expressCom, string $expressNo): void
    {
        $expressCom = trim($expressCom);
        $expressNo  = trim($expressNo);

        if ('' === $expressCom || '' === $expressNo) {
            throw new BusinessException('请填写快递公司与快递单号');
        }

        $stage = ScopeService::query('cycle_order_stage', $aid)->where('id', $stageId)->find();

        if (empty($stage)) {
            throw new BusinessException('分期计划不存在', 404, 404);
        }

        $order = $this->findOrder($aid, (int) $stage['orderid']);

        if (!in_array((int) $order['status'], [self::ORDER_PAID, self::ORDER_SHIPPED], true)) {
            throw new BusinessException('当前订单状态不可发货');
        }

        if (self::STAGE_PAID === (int) $stage['status']) {
            Db::name('cycle_order_stage')->where('id', $stageId)->update([
                'express_com' => $expressCom,
                'express_no'  => $expressNo,
                'send_time'   => time(),
                'status'      => self::STAGE_SHIPPED,
            ]);

            // 总订单同步为已发货（首期发货即推进）
            if (self::ORDER_PAID === (int) $order['status']) {
                Db::name('cycle_order')->where('id', (int) $order['id'])->update([
                    'status'      => self::ORDER_SHIPPED,
                    'express_com' => $expressCom,
                    'express_no'  => $expressNo,
                    'send_time'   => time(),
                ]);
            }

            $this->refreshOrderCollect($aid, (int) $order['id']);

            return;
        }

        if (self::STAGE_SHIPPED === (int) $stage['status']) {
            Db::name('cycle_order_stage')->where('id', $stageId)->update([
                'express_com' => $expressCom,
                'express_no'  => $expressNo,
            ]);

            return;
        }

        throw new BusinessException('当前分期状态不可发货');
    }

    /**
     * 分期确认收货（当期完成；全部完成时总订单收口为已收货）
     *
     * @return array{order_status: int}
     */
    public function orderStageCollect(int $aid, int $stageId): array
    {
        $stage = ScopeService::query('cycle_order_stage', $aid)->where('id', $stageId)->find();

        if (empty($stage)) {
            throw new BusinessException('分期计划不存在', 404, 404);
        }

        if (self::STAGE_SHIPPED !== (int) $stage['status']) {
            throw new BusinessException('仅已发货的分期可确认收货');
        }

        Db::name('cycle_order_stage')->where('id', $stageId)
            ->update(['status' => self::STAGE_DONE, 'collect_time' => time()]);

        $order = $this->findOrder($aid, (int) $stage['orderid']);

        $this->refreshOrderCollect($aid, (int) $order['id']);

        $order = $this->findOrder($aid, (int) $order['id']);

        return ['order_status' => (int) $order['status']];
    }

    /**
     * 退款审核（pass=1 同意→PayService 原路退款 + payorder 登记；pass=2 驳回）
     *
     * 退款金额口径：优先取订单 refund_money（C 端申请额），否则按未完成分期折算
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
            ScopeService::query('cycle_order', $aid)->where('id', $orderId)->update([
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
            $locked = Db::name('cycle_order')
                ->where('aid', $aid)
                ->where('id', $orderId)
                ->lock(true)
                ->find();

            if (empty($locked) || 1 !== (int) $locked['refund_status']
                || !in_array((int) $locked['status'], [self::ORDER_PAID, self::ORDER_SHIPPED], true)) {
                throw new BusinessException('订单状态已变化，请刷新后重试');
            }

            $stages = Db::name('cycle_order_stage')
                ->where('aid', $aid)->where('orderid', $orderId)
                ->order('cycle_number', 'asc')
                ->select()->toArray();

            $refundMoney = (float) $locked['refund_money'] > 0
                ? (float) $locked['refund_money']
                : $this->refundableMoney($locked, $stages);

            if ($refundMoney <= 0) {
                throw new BusinessException('无可退金额（分期已全部完成）');
            }
            if ($refundMoney > (float) $locked['totalprice']) {
                $refundMoney = (float) $locked['totalprice'];
            }

            // 原路退款（mock 即时成功；真实模式微信 V3 原路退款）
            $this->payService->refund($locked, $refundMoney, 'XQ' . (string) $locked['ordernum']);

            // payorder 退款登记（type=cycle 原支付单）
            $this->markPayorderRefund($aid, (string) $locked['ordernum'], $refundMoney);

            Db::name('cycle_order')->where('id', $orderId)->update([
                'status'             => self::ORDER_CLOSED,
                'refund_status'      => 2,
                'refund_money'       => $refundMoney,
                'refund_total_money' => round((float) $locked['refund_total_money'] + $refundMoney, 2),
                'refund_time'        => time(),
                'refund_checkremark' => trim($remark),
            ]);

            // 未完成分期一并关闭（保留已完成期履约数据）
            Db::name('cycle_order_stage')
                ->where('aid', $aid)->where('orderid', $orderId)
                ->whereIn('status', [self::STAGE_CREATED, self::STAGE_PAID])
                ->delete();
        });
    }

    /**
     * 订单备注
     */
    public function orderRemark(int $aid, int $orderId, string $remark): void
    {
        $this->findOrder($aid, $orderId);

        ScopeService::query('cycle_order', $aid)->where('id', $orderId)
            ->update(['remark' => trim($remark)]);
    }

    /* ================= 配送管理 / 周期计划（stage 分期配送视图） ================= */

    /**
     * 分期配送列表（配送管理三视图 + 订单管理「周期计划」平铺页共用）
     *
     * filters: status(''=全部 / 1待发 / 2已发 / 3完成) / orderid(周期计划页限定单订单)
     *          / ordernum / linkman / tel / start_date / end_date
     *
     * @param array<string, mixed> $filters
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function stageDeliveryList(int $aid, int $page, int $pageSize, array $filters): array
    {
        $query = ScopeService::queryAs('cycle_order_stage', $aid, 'os')
            ->leftJoin('cycle_order o', 'o.id = os.orderid')
            ->field('os.*, o.title, o.proname as order_proname, o.propic as order_propic, o.ggname as order_ggname, o.num as order_num, o.sell_price as order_sell_price, o.totalprice, o.freight_price, o.createtime as order_createtime, o.linkman, o.tel, o.area, o.address, o.freight_text, o.freight_type, o.message, o.platform, o.status as order_status')
            ->where(function ($q) {
                $q->whereNull('o.delete')->whereOr('o.delete', 0);
            })
            ->where('o.status', '>', 0)
            ->where('o.status', '<>', self::ORDER_CLOSED);

        $status = (string) ($filters['status'] ?? '');

        if ('' !== $status) {
            $query->where('os.status', (int) $status);
        } else {
            $query->where('os.status', '>', 0);
        }

        if (!empty($filters['orderid'])) {
            $query->where('os.orderid', (int) $filters['orderid']);
        }
        if (!empty($filters['ordernum'])) {
            $query->whereLike('os.ordernum', '%' . trim((string) $filters['ordernum']) . '%');
        }
        if (!empty($filters['linkman'])) {
            $query->whereLike('o.linkman', '%' . trim((string) $filters['linkman']) . '%');
        }
        if (!empty($filters['tel'])) {
            $query->whereLike('o.tel', '%' . trim((string) $filters['tel']) . '%');
        }
        if (!empty($filters['start_date'])) {
            $query->where('o.createtime', '>=', strtotime((string) $filters['start_date']));
        }
        if (!empty($filters['end_date'])) {
            $query->where('o.createtime', '<', strtotime((string) $filters['end_date']) + 86400);
        }

        $total = (clone $query)->count();
        $rows  = $query->order('os.status', 'asc')
            ->order('os.cycle_strtotime', 'asc')
            ->order('os.cycle_number', 'asc')
            ->page($page, $pageSize)
            ->select()->toArray();

        foreach ($rows as &$row) {
            $freightType = (int) ($row['freight_type'] ?? 0);
            $row['status_text'] = $this->stageDeliveryStatusText((int) $row['status'], $freightType);
            $row['freight_type'] = $freightType;
            $row['cycle_date_fmt'] = (string) ($row['cycle_date'] ?? '');
        }
        unset($row);

        return ['list' => $rows, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize];
    }

    /**
     * 核销（到店自提/到店核销 freight_type=1：当期 stage 1/2 → 3；全部完成时总订单收口）
     *
     * @return array{order_status: int}
     */
    public function orderHexiao(int $aid, int $stageId): array
    {
        $stage = ScopeService::query('cycle_order_stage', $aid)->where('id', $stageId)->find();

        if (empty($stage)) {
            throw new BusinessException('分期计划不存在', 404, 404);
        }

        $order = $this->findOrder($aid, (int) $stage['orderid']);

        if (!in_array((int) $stage['status'], [self::STAGE_PAID, self::STAGE_SHIPPED], true)) {
            throw new BusinessException('订单状态不符合完成要求');
        }

        if (1 !== (int) $order['freight_type']) {
            throw new BusinessException('仅到店自提订单可核销');
        }

        Db::name('cycle_order_stage')->where('id', $stageId)
            ->update(['status' => self::STAGE_DONE, 'collect_time' => time()]);

        $this->refreshOrderCollect($aid, (int) $order['id']);

        $order = $this->findOrder($aid, (int) $order['id']);

        return ['order_status' => (int) $order['status']];
    }

    /**
     * 送货单（按 stage 生成：店名/单号/客户/商品行/运费/大小写合计/打印日期）
     *
     * @return array<string, mixed>
     */
    public function deliveryNote(int $aid, int $stageId): array
    {
        $stage = ScopeService::query('cycle_order_stage', $aid)->where('id', $stageId)->find();

        if (empty($stage)) {
            throw new BusinessException('分期计划不存在', 404, 404);
        }

        $order = $this->findOrder($aid, (int) $stage['orderid']);

        // 店名：取租户 shop_sysset.name / admin.company，兜底「鼎极」（不使用文档类型名）
        $sysset   = Db::name('shop_sysset')->where('aid', $aid)->find();
        $shopName = trim((string) ($sysset['name'] ?? ''));
        if ('' === $shopName) {
            $shopName = trim((string) (Db::name('admin')->where('id', $aid)->value('company') ?? ''));
        }
        if ('' === $shopName) {
            $shopName = '鼎极';
        }

        // 商品行（订单级：品名+规格 × 数量，单价取订单 sell_price）
        $goodsName = (string) $order['proname']
            . ('' !== trim((string) $order['ggname']) ? ' ' . $order['ggname'] : '');
        $goodsNum    = (int) $order['num'];
        $goodsPrice  = (float) $order['sell_price'];
        $goodsAmount = round($goodsPrice * $goodsNum, 2);

        $freight   = round((float) ($order['freight_price'] ?? 0), 2);
        $totalMoney = round($goodsAmount + $freight, 2);

        $rows = [
            ['seq' => 1, 'name' => $goodsName, 'num' => $goodsNum, 'price' => $goodsPrice, 'money' => $goodsAmount, 'remark' => ''],
        ];
        if ($freight > 0) {
            $rows[] = ['seq' => 2, 'name' => '运费', 'num' => 1, 'price' => 0.00, 'money' => $freight, 'remark' => ''];
        }

        return [
            'shop_name'    => $shopName,
            'ordernum'     => (string) $order['ordernum'],
            'send_date'    => date('Y-m-d'),
            'linkman'      => (string) $order['linkman'],
            'tel'          => (string) $order['tel'],
            'address'      => trim((string) $order['area'] . ' ' . (string) $order['address']),
            'remark'       => (string) $order['remark'],
            'rows'         => $rows,
            'total_money'  => $totalMoney,
            'money_cn'     => $this->moneyToCn($totalMoney),
            'cycle_number' => (int) $stage['cycle_number'],
        ];
    }

    /**
     * 查物流 / 配送信息（MOCK：返回已录入的快递公司/单号 + 一条轨迹；无单号则空）
     *
     * @return array<string, mixed>
     */
    public function getExpress(int $aid, int $stageId): array
    {
        $stage = ScopeService::query('cycle_order_stage', $aid)->where('id', $stageId)->find();

        if (empty($stage)) {
            throw new BusinessException('分期计划不存在', 404, 404);
        }

        $expressCom = (string) ($stage['express_com'] ?? '');
        $expressNo  = (string) ($stage['express_no'] ?? '');
        $sendTime   = (int) ($stage['send_time'] ?? 0);

        if ('' === $expressNo) {
            return [
                'express_com' => $expressCom,
                'express_no'  => $expressNo,
                'send_time'   => $sendTime,
                'traces'      => [],
                'has_info'    => false,
            ];
        }

        return [
            'express_com' => $expressCom,
            'express_no'  => $expressNo,
            'send_time'   => $sendTime,
            'has_info'    => true,
            'traces'      => [[
                'time'    => $sendTime > 0 ? date('Y-m-d H:i', $sendTime) : '',
                'context' => $expressCom . ' 已揽收，正在配送途中',
            ]],
        ];
    }

    /**
     * 删除订单（软删 delete=1，stage 计划一并标记）
     */
    public function orderDelete(int $aid, int $orderId): void
    {
        $this->findOrder($aid, $orderId);

        ScopeService::query('cycle_order', $aid)->where('id', $orderId)->update(['delete' => 1]);
        Db::name('cycle_order_stage')->where('aid', $aid)->where('orderid', $orderId)->update(['status' => self::STAGE_CREATED]);
    }

    /**
     * 完成（整单收口：剩余未完成分期一律置为已完成，总订单 → 已收货）
     *
     * @return array{order_status: int}
     */
    public function orderFinish(int $aid, int $orderId): array
    {
        $order = $this->findOrder($aid, $orderId);

        if (!in_array((int) $order['status'], [self::ORDER_PAID, self::ORDER_SHIPPED], true)) {
            throw new BusinessException('当前订单状态不可完成');
        }

        Db::name('cycle_order_stage')->where('aid', $aid)->where('orderid', $orderId)
            ->whereIn('status', [self::STAGE_PAID, self::STAGE_SHIPPED])
            ->update(['status' => self::STAGE_DONE, 'collect_time' => time()]);

        Db::name('cycle_order')->where('aid', $aid)->where('id', $orderId)
            ->update(['status' => self::ORDER_COLLECT, 'collect_time' => time()]);

        return ['order_status' => self::ORDER_COLLECT];
    }

    /* ================= C 端下单链路（api 预留） ================= */

    /**
     * 周期购下单（写 order + 分期计划 + payorder；mock 即时支付成功）
     *
     * @param array<string, mixed> $extra linkman/tel/area/address/message/buytype/start_date/platform
     * @return array{orderid: int, ordernum: string, totalprice: float, status: int}
     */
    public function createOrder(int $aid, int $mid, int $proid, int $ggid, array $extra = []): array
    {
        return Db::transaction(function () use ($aid, $mid, $proid, $ggid, $extra) {
            $product = Db::name('cycle_product')
                ->where('aid', $aid)->where('status', 1)->where('id', $proid)->find();

            if (empty($product)) {
                throw new BusinessException('周期购商品不存在或已下架');
            }

            $guige = Db::name('cycle_guige')
                ->where('aid', $aid)->where('proid', $proid)->where('id', $ggid)->find();

            if (empty($guige)) {
                throw new BusinessException('所选规格不存在');
            }
            if ((int) $guige['stock'] <= 0) {
                throw new BusinessException('该规格已售罄');
            }

            $buytype = (int) ($extra['buytype'] ?? 1);

            if (!isset(self::BUYTYPE_TEXT[$buytype])) {
                throw new BusinessException('非法的购买方式');
            }

            $qsnum      = max(1, (int) $guige['qsnum']);
            $eachNum    = max(1, (int) $guige['each_num']);
            $cycleDays  = max(1, (int) $guige['cycle_days']);
            $totalPrice = round((float) $guige['sell_price'], 2);
            $ordernum   = date('ymdHis') . $aid . random_int(1000, 9999);

            $orderId = (int) Db::name('cycle_order')->insertGetId([
                'aid'           => $aid,
                'bid'           => 0,
                'mid'           => $mid,
                'ordernum'      => $ordernum,
                'buytype'       => $buytype,
                'title'         => (string) $product['name'],
                'proid'         => $proid,
                'proname'       => (string) $product['name'],
                'propic'        => (string) $product['pic'],
                'ggid'          => (int) $guige['id'],
                'ggname'        => (string) $guige['name'],
                'num'           => $eachNum,
                'sell_price'    => (float) $guige['sell_price'],
                'totalprice'    => $totalPrice,
                'product_price' => $totalPrice,
                'freight_price' => 0,
                'createtime'    => time(),
                'status'        => self::ORDER_UNPAID,
                'linkman'       => trim((string) ($extra['linkman'] ?? '')),
                'tel'           => trim((string) ($extra['tel'] ?? '')),
                'area'          => trim((string) ($extra['area'] ?? '')),
                'address'       => trim((string) ($extra['address'] ?? '')),
                'message'       => trim((string) ($extra['message'] ?? '')),
                'freight_text'  => '按期配送',
                'qsnum'         => $qsnum,
                'ps_cycle'      => (int) ($product['ps_cycle'] ?? 2),
                'platform'      => (string) ($extra['platform'] ?? 'wx'),
            ]);

            // 分期发货计划：支付前仅生成计划行（status=0 创建），支付成功统一转待发货
            $startDate = strtotime((string) ($extra['start_date'] ?? '')) ?: strtotime('+1 day');

            for ($i = 1; $i <= $qsnum; ++$i) {
                $stageTime = $startDate + ($i - 1) * $cycleDays * 86400;

                Db::name('cycle_order_stage')->insert([
                    'aid'             => $aid,
                    'bid'             => 0,
                    'mid'             => $mid,
                    'ordernum'        => $ordernum,
                    'orderid'         => $orderId,
                    'cycle_number'    => $i,
                    'cycle_date'      => date('Y-m-d', $stageTime),
                    'cycle_strtotime' => $stageTime,
                    'status'          => self::STAGE_CREATED,
                    'proname'         => (string) $product['name'],
                    'propic'          => (string) $product['pic'],
                    'ggname'          => (string) $guige['name'],
                    'num'             => $eachNum,
                    'sell_price'      => round($totalPrice / $qsnum, 2),
                ]);
            }

            // 支付单（type=cycle）
            Db::name('payorder')->insert([
                'aid'        => $aid,
                'bid'        => 0,
                'mid'        => $mid,
                'orderid'    => $orderId,
                'ordernum'   => $ordernum,
                'title'      => (string) $product['name'],
                'money'      => $totalPrice,
                'score'      => 0,
                'type'       => 'cycle',
                'createtime' => time(),
                'status'     => 0,
                'platform'   => (string) ($extra['platform'] ?? 'wx'),
            ]);

            // 扣规格库存
            Db::name('cycle_guige')->where('id', (int) $guige['id'])->where('stock', '>', 0)
                ->update([
                    'sales' => Db::raw('sales+1'),
                    'stock' => Db::raw('stock-1'),
                ]);

            // mock 模式即时支付成功（真实模式由支付回调走 payCycleOrder）
            if (PayService::isMock()) {
                $this->payCycleOrder($aid, $orderId, 'wechat', 'MOCK' . time() . random_int(1000, 9999));
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
     * 周期购订单支付成功入账（幂等：仅 status=0 → 1；分期计划同步转待发货）
     */
    public function payCycleOrder(int $aid, int $orderId, string $payType, string $payNum): bool
    {
        $order = ScopeService::query('cycle_order', $aid)->where('id', $orderId)->find();

        if (empty($order) || self::ORDER_UNPAID !== (int) $order['status']) {
            return false;
        }

        Db::name('cycle_order')->where('id', $orderId)->where('status', self::ORDER_UNPAID)->update([
            'status'    => self::ORDER_PAID,
            'paytype'   => $payType,
            'paytypeid' => PayService::PAY_TYPE_IDS[$payType] ?? 0,
            'paynum'    => $payNum,
            'paytime'   => time(),
        ]);

        // 分期计划 0创建 → 1待发货
        Db::name('cycle_order_stage')
            ->where('aid', $aid)->where('orderid', $orderId)->where('status', self::STAGE_CREATED)
            ->update(['status' => self::STAGE_PAID]);

        Db::name('payorder')
            ->where('aid', $aid)->where('type', 'cycle')->where('ordernum', (string) $order['ordernum'])
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

    /* ================= 内部工具 ================= */

    /**
     * 规格行列表
     *
     * @return array<int, array<string, mixed>>
     */
    protected function guigeList(int $aid, int $proid): array
    {
        return ScopeService::query('cycle_guige', $aid)
            ->where('proid', $proid)
            ->order('id', 'asc')
            ->select()
            ->toArray();
    }

    /**
     * 分期计划列表（按期数升序，附状态文案）
     *
     * @return array<int, array<string, mixed>>
     */
    protected function stageList(int $aid, int $orderId): array
    {
        $rows = ScopeService::query('cycle_order_stage', $aid)
            ->where('orderid', $orderId)
            ->order('cycle_number', 'asc')
            ->select()
            ->toArray();

        foreach ($rows as &$row) {
            $row['status_text'] = $this->stageStatusText((int) $row['status']);
        }
        unset($row);

        return $rows;
    }

    /**
     * 分期进度（已完成/已发货/总期数）
     *
     * @return array{total: int, done: int, shipped: int, text: string}
     */
    protected function stageProgress(int $aid, int $orderId): array
    {
        $rows = Db::name('cycle_order_stage')
            ->where('aid', $aid)->where('orderid', $orderId)
            ->field('status')->select()->toArray();

        $total   = count($rows);
        $done    = 0;
        $shipped = 0;

        foreach ($rows as $row) {
            if (self::STAGE_DONE === (int) $row['status']) {
                ++$done;
            } elseif (self::STAGE_SHIPPED === (int) $row['status']) {
                ++$shipped;
            }
        }

        return [
            'total'   => $total,
            'done'    => $done,
            'shipped' => $shipped,
            'text'    => $total > 0 ? $done . '/' . $total . ' 期完成' : '-',
        ];
    }

    /**
     * 配送周期状态摘要（订单列表「配送周期状态」列：前 3 期 + 状态文案）
     *
     * @return array<int, array{cycle_number: int, status: int, status_text: string}>
     */
    protected function stageBrief(int $aid, int $orderId): array
    {
        $order = Db::name('cycle_order')->where('aid', $aid)->where('id', $orderId)->find();
        $freightType = (int) ($order['freight_type'] ?? 0);

        $rows = Db::name('cycle_order_stage')
            ->where('aid', $aid)->where('orderid', $orderId)
            ->field('cycle_number, status')
            ->order('cycle_number', 'asc')
            ->limit(0, 3)
            ->select()->toArray();

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'cycle_number' => (int) $row['cycle_number'],
                'status'       => (int) $row['status'],
                'status_text'  => $this->stageDeliveryStatusText((int) $row['status'], $freightType),
            ];
        }

        return $out;
    }

    /**
     * 来源渠道文案（微信小程序 / 手机H5 / 微信公众号 等）
     */
    protected function platformText(string $platform): string
    {
        return match ($platform) {
            'wx', 'weixin', 'wxapp' => '微信小程序',
            'h5', 'wechat'          => '手机H5',
            'alipay'                => '支付宝小程序',
            default                 => '' !== $platform ? $platform : '微信小程序',
        };
    }

    /**
     * 可退金额（按未完成分期折算：每期均摊订单总价）
     *
     * @param array<string, mixed>       $order
     * @param array<int, array<string, mixed>> $stages
     */
    protected function refundableMoney(array $order, array $stages): float
    {
        $total = count($stages);

        if ($total <= 0) {
            return (float) $order['totalprice'];
        }

        $unfulfilled = 0;

        foreach ($stages as $stage) {
            if (in_array((int) $stage['status'], [self::STAGE_CREATED, self::STAGE_PAID, self::STAGE_SHIPPED], true)) {
                ++$unfulfilled;
            }
        }

        $money = round((float) $order['totalprice'] / $total * $unfulfilled, 2);

        return min($money, (float) $order['totalprice']);
    }

    /**
     * 总订单收口：全部分期完成 → 已收货（部分发货维持已发货态）
     */
    protected function refreshOrderCollect(int $aid, int $orderId): void
    {
        $unfinished = (int) Db::name('cycle_order_stage')
            ->where('aid', $aid)->where('orderid', $orderId)
            ->whereIn('status', [self::STAGE_CREATED, self::STAGE_PAID, self::STAGE_SHIPPED])
            ->count();

        if (0 !== $unfinished) {
            return;
        }

        Db::name('cycle_order')
            ->where('aid', $aid)->where('id', $orderId)
            ->whereIn('status', [self::ORDER_PAID, self::ORDER_SHIPPED])
            ->update(['status' => self::ORDER_COLLECT, 'collect_time' => time()]);
    }

    /**
     * payorder 退款登记（原路退款的账本记录：refund_money/refund_time）
     */
    protected function markPayorderRefund(int $aid, string $ordernum, float $refundMoney): void
    {
        if ('' === $ordernum || $refundMoney <= 0) {
            return;
        }

        Db::name('payorder')
            ->where('aid', $aid)
            ->where('type', 'cycle')
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
     * @return array<string, mixed>
     */
    protected function findProduct(int $aid, int $id): array
    {
        $product = ScopeService::query('cycle_product', $aid)->where('id', $id)->find();

        if (empty($product)) {
            throw new BusinessException('周期购商品不存在', 404, 404);
        }

        return $product;
    }

    /**
     * @return array<string, mixed>
     */
    protected function findOrder(int $aid, int $orderId): array
    {
        $order = ScopeService::query('cycle_order', $aid)->where('id', $orderId)->find();

        if (empty($order)) {
            throw new BusinessException('订单不存在', 404, 404);
        }

        return $order;
    }

    protected function orderStatusText(int $status): string
    {
        return match ($status) {
            self::ORDER_PAID    => '已支付',
            self::ORDER_SHIPPED => '已发货',
            self::ORDER_COLLECT => '已收货',
            self::ORDER_CLOSED  => '已关闭',
            default             => '未支付',
        };
    }

    protected function refundStatusText(int $refundStatus): string
    {
        return match ($refundStatus) {
            1      => '退款审核中',
            2      => '已退款',
            3      => '退款驳回',
            default => '',
        };
    }

    protected function stageStatusText(int $status): string
    {
        return match ($status) {
            self::STAGE_PAID    => '待发货',
            self::STAGE_SHIPPED => '已发货',
            self::STAGE_DONE    => '已完成',
            default             => '待支付',
        };
    }

    /**
     * 配送视图分期状态文案（到店核销 freight_type=1 用「待提货/已提货」，其余用「待发货/已发货」）
     */
    protected function stageDeliveryStatusText(int $status, int $freightType): string
    {
        if (self::STAGE_DONE === $status) {
            return '已完成';
        }
        if (self::STAGE_SHIPPED === $status) {
            return 1 === $freightType ? '已提货' : '已发货';
        }
        if (self::STAGE_PAID === $status) {
            return 1 === $freightType ? '待提货' : '待发货';
        }

        return '待支付';
    }

    /**
     * 金额转人民币大写（送货单「合计金额(大写)」）
     */
    protected function moneyToCn(float $money): string
    {
        $money  = round(abs($money), 2);
        $digits = ['零', '壹', '贰', '叁', '肆', '伍', '陆', '柒', '捌', '玖'];
        $iunits = ['', '拾', '佰', '仟'];
        $gunits = ['', '万', '亿', '万亿'];

        $intPart  = (int) floor($money);
        $decCents = (int) round(($money - $intPart) * 100);

        if (0 === $intPart) {
            $intStr = '零';
        } else {
            $groups = [];
            while ($intPart > 0) {
                $groups[] = $intPart % 10000;
                $intPart  = intdiv($intPart, 10000);
            }

            $parts = [];
            foreach ($groups as $gi => $group) {
                if (0 === $group) {
                    continue;
                }
                $gn  = (string) $group;
                $len = strlen($gn);
                $seg = '';
                $zero = false;
                for ($i = 0; $i < $len; ++$i) {
                    $d   = (int) $gn[$i];
                    $pos = $len - $i - 1;
                    if (0 === $d) {
                        $zero = true;
                        continue;
                    }
                    if ($zero) {
                        $seg .= '零';
                        $zero = false;
                    }
                    $seg .= $digits[$d] . $iunits[$pos];
                }
                $parts[] = $seg . $gunits[$gi];
            }
            $intStr = implode('', array_reverse($parts));
        }

        $result = $intStr . '元';
        $jiao   = intdiv($decCents, 10);
        $fen    = $decCents % 10;

        if (0 === $jiao && 0 === $fen) {
            $result .= '整';
        } else {
            if ($jiao > 0) {
                $result .= $digits[$jiao] . '角';
            } elseif ($fen > 0) {
                $result .= '零';
            }
            if ($fen > 0) {
                $result .= $digits[$fen] . '分';
            }
        }

        return $result;
    }
}
