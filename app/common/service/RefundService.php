<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;

/**
 * 退款服务（api 会员申请 + admin 审核）
 *
 * 表口径（复用基线 shop_refund_order，金额单位「元」）：
 * - refund_status 内部语义：2=申请中 1=已退款 0=已驳回
 * - 对外契约 status：0=申请中 1=已退款 2=已驳回（format 时映射）
 * - refund_money=退款金额 product_price=订单实付快照 message/refund_reason=原因
 *   refund_checkremark=审核备注 createtime=申请时间 refund_time=审核/退款时间
 *
 * 审核通过流程（事务）：PayService 退款（mock 即时成功）→ 仅订单 status=1 未发货时回补库存
 * → 订单置 4 关闭（shop_order.refund_status=1）→ 退款单 refund_status=1；重复审核幂等拦截
 */
class RefundService
{
    /** 内部 refund_status → 契约 status */
    public const STATUS_MAP = [2 => 0, 1 => 1, 0 => 2];

    /** 契约 status → 内部 refund_status */
    public const STATUS_REVERSE = [0 => 2, 1 => 1, 2 => 0];

    public const STATUS_TEXT = [0 => '申请中', 1 => '已退款', 2 => '已驳回'];

    public function __construct(protected PayService $payService, protected FenxiaoService $fenxiaoService)
    {
    }

    /* ================= api 会员侧 ================= */

    /**
     * 申请退款（仅订单 status=1/2 且无进行中退款单）
     *
     * @return array{id: int, refund_sn: string, refund_amount: float}
     */
    public function apply(int $aid, int $mid, int $orderId, string $reason): array
    {
        $reason = trim($reason);

        if ('' === $reason) {
            throw new BusinessException('请填写退款原因');
        }

        $order = ScopeService::query('shop_order', $aid)
            ->where('id', $orderId)
            ->where('mid', $mid)
            ->find();

        if (empty($order)) {
            throw new BusinessException('订单不存在', 404, 404);
        }

        if (!in_array((int) $order['status'], [OrderService::STATUS_PAID, OrderService::STATUS_SHIPPED], true)) {
            throw new BusinessException('仅已支付或已发货订单可申请退款');
        }

        // 进行中退款单拦截
        $pending = ScopeService::query('shop_refund_order', $aid)
            ->where('orderid', $orderId)
            ->where('deleted', 0)
            ->where('refund_status', 2)
            ->find();

        if (!empty($pending)) {
            throw new BusinessException('已有进行中的退款单，请勿重复申请');
        }

        // 已退款成功拦截
        $done = ScopeService::query('shop_refund_order', $aid)
            ->where('orderid', $orderId)
            ->where('deleted', 0)
            ->where('refund_status', 1)
            ->find();

        if (!empty($done)) {
            throw new BusinessException('该订单已完成退款');
        }

        $now         = time();
        $refundMoney = (float) $order['totalprice']; // 本期全额退

        $refundId = (int) ScopeService::query('shop_refund_order', $aid)->insertGetId([
            'aid'           => $aid,
            'bid'           => 0,
            'mid'           => $mid,
            'refund_ordernum' => '',
            'orderid'       => $orderId,
            'ordernum'      => (string) ($order['ordernum'] ?? ''),
            'title'         => (string) ($order['title'] ?? ''),
            'product_price' => $refundMoney,
            'createtime'    => $now,
            'status'        => (int) $order['status'],
            'message'       => $reason,
            'refund_type'   => 'refund',
            'refund_reason' => $reason,
            'refund_money'  => $refundMoney,
            'refund_status' => 2,
            'platform'      => 'api',
        ]);

        // 退款单号：R + 日期时间 + 租户(4) + 序列(6)
        $refundSn = 'R' . date('YmdHis', $now)
            . str_pad((string) ($aid % 10000), 4, '0', STR_PAD_LEFT)
            . str_pad((string) ($refundId % 1000000), 6, '0', STR_PAD_LEFT);

        Db::name('shop_refund_order')->where('id', $refundId)->update(['refund_ordernum' => $refundSn]);

        return ['id' => $refundId, 'refund_sn' => $refundSn, 'refund_amount' => $refundMoney];
    }

    /**
     * 我的退款单列表
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function apiList(int $aid, int $mid, int $page, int $pageSize): array
    {
        $query = ScopeService::query('shop_refund_order', $aid)
            ->where('mid', $mid)
            ->where('deleted', 0);

        $total = (clone $query)->count();

        $rows = $query->order('id', 'desc')->page($page, $pageSize)->select()->toArray();

        return [
            'list'     => array_map(fn (array $r) => $this->format($r), $rows),
            'total'    => $total,
            'page'     => $page,
            'pagesize' => $pageSize,
        ];
    }

    /* ================= admin 租户后台侧 ================= */

    /**
     * 退款单列表（status 空=全部；keyword 匹配订单号/收件人）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function adminList(int $aid, string $status, string $keyword, int $page, int $pageSize, array $filter = []): array
    {
        $query = ScopeService::queryAs('shop_refund_order', $aid, 'r')
            ->leftJoin('shop_order o', 'o.id = r.orderid')
            ->leftJoin('member m', 'm.id = r.mid')
            ->field('r.*, m.tel as member_phone, m.nickname as member_nickname, m.headimg as member_headimg, o.linkman as order_linkman, o.tel as order_tel')
            ->where('r.deleted', 0);

        if ('' !== $status) {
            $internal = self::STATUS_REVERSE[(int) $status] ?? null;

            if (null === $internal) {
                throw new BusinessException('非法的退款状态');
            }

            $query->where('r.refund_status', $internal);
        }

        // 兼容旧关键词入口（订单号 / 收件人）
        if ('' !== $keyword) {
            $like = '%' . $keyword . '%';
            $query->where(function ($q) use ($like) {
                $q->whereOr('r.ordernum', 'like', $like)
                    ->whereOr('o.linkman', 'like', $like);
            });
        }

        // 退款单号
        $refundSn = trim((string) ($filter['refund_sn'] ?? ''));
        if ('' !== $refundSn) {
            $query->where('r.refund_ordernum', 'like', '%' . $refundSn . '%');
        }

        // 退货快递单号
        $expressNo = trim((string) ($filter['express_no'] ?? ''));
        if ('' !== $expressNo) {
            $query->where('r.express_no', 'like', '%' . $expressNo . '%');
        }

        // 订单号
        $orderNum = trim((string) ($filter['ordernum'] ?? ''));
        if ('' !== $orderNum) {
            $query->where('r.ordernum', 'like', '%' . $orderNum . '%');
        }

        // 手机号（会员手机 / 订单收件电话 / 收件人）
        $tel = trim((string) ($filter['tel'] ?? ''));
        if ('' !== $tel) {
            $like = '%' . $tel . '%';
            $query->where(function ($q) use ($like) {
                $q->whereOr('m.tel', 'like', $like)
                    ->whereOr('o.tel', 'like', $like)
                    ->whereOr('o.linkman', 'like', $like);
            });
        }

        // 申请时间区间
        $start = trim((string) ($filter['start'] ?? ''));
        $end   = trim((string) ($filter['end'] ?? ''));
        if ('' !== $start) {
            $query->where('r.createtime', '>=', strtotime($start . ' 00:00:00'));
        }
        if ('' !== $end) {
            $query->where('r.createtime', '<=', strtotime($end . ' 23:59:59'));
        }

        $total = (clone $query)->count();

        // 排序（白名单，默认 ID 倒序）
        $sortMap  = ['id' => 'id', 'refund_ordernum' => 'refund_ordernum', 'ordernum' => 'ordernum'];
        $sortFld  = $sortMap[(string) ($filter['sort_field'] ?? 'id')] ?? 'id';
        $sortOrd  = 'asc' === strtolower((string) ($filter['sort_order'] ?? 'desc')) ? 'asc' : 'desc';

        $rows = $query->order('r.' . $sortFld, $sortOrd)->page($page, $pageSize)->select()->toArray();

        // 批量取商品快照，避免 N+1
        $orderIds = array_values(array_unique(array_map(static fn (array $r): int => (int) $r['orderid'], $rows)));
        $goodsMap = [];
        if ($orderIds) {
            $gRows = Db::name('shop_order_goods')->whereIn('orderid', $orderIds)->order('id', 'asc')->select()->toArray();
            foreach ($gRows as $g) {
                $goodsMap[(int) $g['orderid']][] = [
                    'goods_id' => (int) ($g['proid'] ?? 0),
                    'name'     => (string) ($g['name'] ?? ''),
                    'pic'      => (string) ($g['pic'] ?? ''),
                    'sku'      => (string) ($g['ggname'] ?? ''),
                    'num'      => (int) ($g['num'] ?? 0),
                    'price'    => (float) ($g['sell_price'] ?? 0),
                ];
            }
        }

        $list = [];
        foreach ($rows as $r) {
            $item          = $this->format($r, true);
            $item['goods'] = $goodsMap[(int) $r['orderid']] ?? [];
            $list[]        = $item;
        }

        return [
            'list'     => $list,
            'total'    => $total,
            'page'     => $page,
            'pagesize' => $pageSize,
        ];
    }

    /**
     * 退款单详情（含订单商品快照与地址快照）
     *
     * @return array<string, mixed>
     */
    public function adminDetail(int $aid, int $refundId): array
    {
        $refund = ScopeService::query('shop_refund_order', $aid)
            ->where('id', $refundId)
            ->where('deleted', 0)
            ->find();

        if (empty($refund)) {
            throw new BusinessException('退款单不存在', 404, 404);
        }

        $detail = $this->format($refund, true);

        // 订单商品快照
        $goodsRows = Db::name('shop_order_goods')
            ->where('orderid', (int) $refund['orderid'])
            ->order('id', 'asc')
            ->select()
            ->toArray();

        $detail['goods'] = array_map(static fn (array $row) => [
            'goods_id' => (int) ($row['proid'] ?? 0),
            'name'     => (string) ($row['name'] ?? ''),
            'pic'      => (string) ($row['pic'] ?? ''),
            'num'      => (int) ($row['num'] ?? 0),
            'price'    => (float) ($row['sell_price'] ?? 0),
        ], $goodsRows);

        // 订单地址快照
        $order = Db::name('shop_order')->where('id', (int) $refund['orderid'])->find();

        $detail['order'] = empty($order) ? null : [
            'order_sn'    => (string) ($order['ordernum'] ?? ''),
            'status'      => (int) ($order['status'] ?? 0),
            'status_text' => OrderService::STATUS_TEXT[(int) ($order['status'] ?? 0)] ?? '未知',
            'pay_amount'  => (float) ($order['totalprice'] ?? 0),
            'product_amount' => (float) ($order['product_price'] ?? 0),
            'pay_type'    => (string) ($order['paytype'] ?? ''),
            'create_time' => $this->formatTime($order['createtime'] ?? null),
            'pay_time'    => $this->formatTime($order['paytime'] ?? null),
            'address'     => [
                'name'  => (string) ($order['linkman'] ?? ''),
                'phone' => (string) ($order['tel'] ?? ''),
                'detail' => trim(((string) ($order['area'] ?? '')) . ' ' . ((string) ($order['address'] ?? ''))),
            ],
        ];

        // 会员信息
        $member = Db::name('member')->where('id', (int) $refund['mid'])->find();
        $detail['member'] = empty($member) ? null : [
            'id'       => (int) ($member['id'] ?? 0),
            'nickname' => (string) ($member['nickname'] ?? ''),
            'headimg'  => (string) ($member['headimg'] ?? ''),
            'tel'      => (string) ($member['tel'] ?? ''),
        ];

        // 商家寄回地址（退货退款场景，买家寄回用）
        $returnAddr = trim(((string) ($refund['return_province'] ?? '')) . ' '
            . ((string) ($refund['return_city'] ?? '')) . ' '
            . ((string) ($refund['return_area'] ?? '')) . ' '
            . ((string) ($refund['return_address'] ?? '')));
        $detail['return'] = [
            'name'    => (string) ($refund['return_name'] ?? ''),
            'tel'     => (string) ($refund['return_tel'] ?? ''),
            'address' => $returnAddr,
        ];

        // 退货物流轨迹文本（如有）
        $detail['express_content'] = (string) ($refund['express_content'] ?? '');

        $detail['audit_remark'] = (string) ($refund['refund_checkremark'] ?? '');

        return $detail;
    }

    /**
     * 后台删除退款单（软删 deleted=1）
     */
    public function adminDelete(int $aid, int $refundId): void
    {
        $exist = ScopeService::query('shop_refund_order', $aid)
            ->where('id', $refundId)
            ->where('deleted', 0)
            ->find();

        if (empty($exist)) {
            throw new BusinessException('退款单不存在', 404, 404);
        }

        ScopeService::query('shop_refund_order', $aid)
            ->where('id', $refundId)
            ->update(['deleted' => 1]);
    }

    /**
     * 审核退款单（pass=1 通过退款 / pass=2 驳回）
     *
     * 通过流程（事务）：PayService 退款（mock 即时成功）→ 订单 status=1 时回补库存
     * → 订单置 4 关闭 → 退款单 refund_status=1；已审核的单据重复操作直接拦截（幂等）
     */
    public function audit(int $aid, int $refundId, int $pass, string $remark): void
    {
        if (1 !== $pass && 2 !== $pass) {
            throw new BusinessException('非法的审核结果');
        }

        $refund = ScopeService::query('shop_refund_order', $aid)
            ->where('id', $refundId)
            ->where('deleted', 0)
            ->find();

        if (empty($refund)) {
            throw new BusinessException('退款单不存在', 404, 404);
        }

        if (2 !== (int) $refund['refund_status']) {
            throw new BusinessException('该退款单已处理，请勿重复审核');
        }

        if (2 === $pass) {
            ScopeService::query('shop_refund_order', $aid)
                ->where('id', $refundId)
                ->where('refund_status', 2)
                ->update([
                    'refund_status'       => 0,
                    'refund_checkremark'  => $remark,
                    'refund_time'         => time(),
                ]);

            return;
        }

        $order = Db::name('shop_order')->where('id', (int) $refund['orderid'])->find();

        if (empty($order)) {
            throw new BusinessException('原订单不存在', 404, 404);
        }

        Db::transaction(function () use ($aid, $refundId, $refund, $order, $remark) {
            // 行锁防并发重复审核
            $locked = Db::name('shop_refund_order')->where('id', $refundId)->lock(true)->find();

            if (2 !== (int) ($locked['refund_status'] ?? -1)) {
                throw new BusinessException('该退款单已处理，请勿重复审核');
            }

            // 调支付渠道退款（mock 即时成功；真实模式未接入时抛异常回滚）
            $this->payService->refund(
                $order,
                (float) $refund['refund_money'],
                (string) $refund['refund_ordernum']
            );

            $now = time();

            // 仅订单 status=1（已支付未发货）时回补库存；已发货走退货流程本期不处理
            if (OrderService::STATUS_PAID === (int) $order['status']) {
                $goodsRows = Db::name('shop_order_goods')->where('orderid', (int) $order['id'])->select()->toArray();

                foreach ($goodsRows as $row) {
                    $proid = (int) ($row['proid'] ?? 0);
                    $num   = (int) ($row['num'] ?? 0);

                    if ($proid > 0 && $num > 0) {
                        Db::name('shop_product')
                            ->where('id', $proid)
                            ->where('aid', $aid)
                            ->inc('stock', $num)
                            ->update();
                    }
                }
            }

            // 订单关闭 + 退款标记
            Db::name('shop_order')->where('id', (int) $order['id'])->update([
                'status'        => OrderService::STATUS_CANCELLED,
                'refund_status' => 1,
                'refund_money'  => (float) $refund['refund_money'],
                'refund_reason' => (string) $refund['refund_reason'],
                'refund_time'   => $now,
            ]);

            // 退款单置已退款
            Db::name('shop_refund_order')->where('id', $refundId)->update([
                'refund_status'      => 1,
                'refund_time'        => $now,
                'refund_checkremark' => $remark,
                'paytype'            => (string) ($order['paytype'] ?? ''),
                'paynum'             => (string) ($order['paynum'] ?? ''),
            ]);

            // 分销回滚（任务179）：订单相关 commission_record 置 2 作废，已结算的扣回佣金
            $this->fenxiaoService->refundFenxiao($aid, (int) $order['id'], 'shop');
        });
    }

    /**
     * 退款单统计（tab 计数）
     *
     * @return array{pending: int, done: int, rejected: int}
     */
    public function stat(int $aid): array
    {
        $rows = ScopeService::query('shop_refund_order', $aid)
            ->where('deleted', 0)
            ->field('refund_status, count(*) as cnt')
            ->group('refund_status')
            ->select()
            ->toArray();

        $stat = ['pending' => 0, 'done' => 0, 'rejected' => 0];
        $map  = [2 => 'pending', 1 => 'done', 0 => 'rejected'];

        foreach ($rows as $row) {
            $key = $map[(int) $row['refund_status']] ?? null;

            if (null !== $key) {
                $stat[$key] += (int) $row['cnt'];
            }
        }

        return $stat;
    }

    /* ================= 内部工具 ================= */

    /**
     * 退款单格式化（内部 refund_status → 契约 status 映射）
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function format(array $row, bool $forAdmin = false): array
    {
        $internal = (int) ($row['refund_status'] ?? 2);
        $status   = self::STATUS_MAP[$internal] ?? 0;

        $base = [
            'id'            => (int) $row['id'],
            'refund_sn'     => (string) ($row['refund_ordernum'] ?? ''),
            'ordernum'      => (string) ($row['ordernum'] ?? ''),
            'order_sn'      => (string) ($row['ordernum'] ?? ''),
            'pay_amount'    => (float) ($row['product_price'] ?? 0),
            'refund_amount' => (float) ($row['refund_money'] ?? 0),
            'reason'        => (string) ($row['refund_reason'] ?? ($row['message'] ?? '')),
            'status'        => $status,
            'status_text'   => self::STATUS_TEXT[$status] ?? '未知',
            'apply_time'    => $this->formatTime($row['createtime'] ?? null),
            'audit_time'    => $this->formatTime($row['refund_time'] ?? null),
        ];

        if ($forAdmin) {
            $type = (string) ($row['refund_type'] ?? 'refund');
            $base['orderid']         = (int) ($row['orderid'] ?? 0);
            $base['mid']             = (int) ($row['mid'] ?? 0);
            $base['member_phone']    = (string) ($row['member_phone'] ?? '');
            $base['member_nickname'] = (string) ($row['member_nickname'] ?? '');
            $base['member_headimg']  = (string) ($row['member_headimg'] ?? '');
            $base['audit_remark']    = (string) ($row['refund_checkremark'] ?? '');
            $base['refund_type']     = $type;
            $base['refund_type_label'] = $this->refundTypeLabel($type);
            $base['express_company'] = (string) ($row['express_com'] ?? '');
            $base['express_no']      = (string) ($row['express_no'] ?? '');
            $base['refund_pics']     = $this->parsePics($row['refund_pics'] ?? '');
        }

        return $base;
    }

    /**
     * 退款类型中文
     */
    protected function refundTypeLabel(string $type): string
    {
        return match ($type) {
            'return'   => '退货退款',
            'exchange' => '换货',
            default    => '退款',
        };
    }

    /**
     * 解析凭证图片（JSON 数组 或 逗号/换行分隔）为字符串数组
     *
     * @return string[]
     */
    protected function parsePics(mixed $raw): array
    {
        if (is_array($raw)) {
            return array_values(array_filter(array_map('strval', $raw)));
        }

        $s = trim((string) $raw);
        if ('' === $s) {
            return [];
        }

        $decoded = json_decode($s, true);
        if (is_array($decoded)) {
            return array_values(array_filter(array_map('strval', $decoded)));
        }

        $arr = preg_split('/[,，\r\n]+/u', $s) ?: [];

        return array_values(array_filter(array_map('trim', $arr)));
    }

    protected function formatTime(mixed $timestamp): string
    {
        $timestamp = (int) $timestamp;

        return $timestamp > 0 ? date('Y-m-d H:i:s', $timestamp) : '';
    }
}
