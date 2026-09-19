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
     * @return array{autoshdays: int, comment: int}
     */
    public function getSysset(int $aid): array
    {
        $info = ScopeService::query('cycle_sysset', $aid)->find();

        return [
            'autoshdays' => (int) ($info['autoshdays'] ?? 7),
            'comment'    => isset($info['comment']) ? (int) $info['comment'] : 1,
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

        if (empty($update)) {
            throw new BusinessException('没有可保存的设置项');
        }

        $exists = ScopeService::query('cycle_sysset', $aid)->find();

        if (empty($exists)) {
            Db::name('cycle_sysset')->insert(array_merge([
                'aid'        => $aid,
                'autoshdays' => 7,
                'comment'    => 1,
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
     * 新增/编辑商品（含规格动态行：周期/次数/每期数量/价格/库存）
     *
     * @param array<string, mixed> $data {id?, name, pic, cid, sort, status, detail, guiges:[{cycle_days,qsnum,each_num,sell_price,stock,name?}]}
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

        // 规格动态行（至少一行）
        $guiges = $data['guiges'] ?? $data['guige_list'] ?? [];

        if (!is_array($guiges) || empty($guiges)) {
            throw new BusinessException('请至少添加一条规格（周期/次数/每期数量/价格）');
        }

        $cleanGuiges = [];

        foreach ($guiges as $guige) {
            if (!is_array($guige)) {
                continue;
            }

            $cycleDays = (int) ($guige['cycle_days'] ?? 0);
            $qsnum     = (int) ($guige['qsnum'] ?? 0);
            $eachNum   = (int) ($guige['each_num'] ?? 0);
            $sellPrice = round((float) ($guige['sell_price'] ?? 0), 2);
            $stock     = (int) ($guige['stock'] ?? 0);

            if ($cycleDays < 1 || $cycleDays > 365) {
                throw new BusinessException('规格配送周期须在 1-365 天之间');
            }
            if ($qsnum < 1 || $qsnum > 365) {
                throw new BusinessException('规格期数（次数）须在 1-365 之间');
            }
            if ($eachNum < 1 || $eachNum > 99) {
                throw new BusinessException('规格每期数量须在 1-99 之间');
            }
            if ($sellPrice <= 0) {
                throw new BusinessException('规格价格须大于 0');
            }
            if ($stock < 0) {
                throw new BusinessException('规格库存不能为负数');
            }

            $cleanGuiges[] = [
                'name'       => trim((string) ($guige['name'] ?? '')),
                'cycle_days' => $cycleDays,
                'qsnum'      => $qsnum,
                'each_num'   => $eachNum,
                'sell_price' => $sellPrice,
                'stock'      => $stock,
            ];
        }

        if (empty($cleanGuiges)) {
            throw new BusinessException('请至少添加一条规格（周期/次数/每期数量/价格）');
        }

        // 商品主表：价格取规格最低，库存取规格合计
        $sellPrice = min(array_column($cleanGuiges, 'sell_price'));
        $stock     = array_sum(array_column($cleanGuiges, 'stock'));

        $row = [
            'name'       => $name,
            'pic'        => $pic,
            'pics'       => trim((string) ($data['pics'] ?? '')),
            'cid'        => $cid,
            'sell_price' => $sellPrice,
            'stock'      => $stock,
            'sort'       => (int) ($data['sort'] ?? 0),
            'status'     => (int) ($data['status'] ?? 1) === 1 ? 1 : 0,
            'ps_cycle'   => (int) ($data['ps_cycle'] ?? 2),
            'detail'     => (string) ($data['detail'] ?? ''),
        ];

        return Db::transaction(function () use ($aid, $id, $row, $cleanGuiges) {
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
                $guigeName = '' !== $guige['name']
                    ? $guige['name']
                    : '每' . $guige['cycle_days'] . '天×' . $guige['qsnum'] . '期×每期' . $guige['each_num'] . '件';

                Db::name('cycle_guige')->insert([
                    'aid'        => $aid,
                    'proid'      => $id,
                    'name'       => $guigeName,
                    'cycle_days' => $guige['cycle_days'],
                    'qsnum'      => $guige['qsnum'],
                    'each_num'   => $guige['each_num'],
                    'sell_price' => $guige['sell_price'],
                    'stock'      => $guige['stock'],
                    'ks'         => (string) $index,
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
            $row['stage_progress'] = $this->stageProgress($aid, (int) $row['id']);
        }
        unset($row);

        return ['list' => $rows, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize];
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
}
