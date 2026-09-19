<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\CouponService;
use app\common\service\ScoreService;
use app\common\service\ScopeService;
use think\facade\Db;

/**
 * 租户后台 - 餐饮外卖订单（P14-2 餐饮一期订单侧）
 *
 * 状态口径（对齐参考系统）：0未支付 1已支付 12接单 2发货 3完成(已收货) 4关闭
 * 一期范围：list/detail/接单/关单；发货 sendExpress 为二期桩
 */
class RestaurantTakeawayOrder extends BaseController
{
    /** 外卖订单状态文案 */
    public const STATUS_TEXT = [
        0  => '未支付',
        1  => '已支付',
        2  => '已发货',
        3  => '已完成',
        4  => '已关闭',
        12 => '已接单',
    ];

    public function __construct(
        \think\App $app,
        protected CouponService $couponService,
        protected ScoreService $scoreService
    ) {
        parent::__construct($app);
    }

    /**
     * 订单列表（status 支持 12 接单态；订单号/收货人/时间/会员筛选）
     * GET /admin/restaurant/takeawayOrder/list
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        $status    = trim((string) $this->request->get('status', ''));
        $keyword   = trim((string) $this->request->get('keyword', ''));
        $mid       = (int) $this->request->get('mid', 0);
        $startTime = trim((string) $this->request->get('start_time', ''));
        $endTime   = trim((string) $this->request->get('end_time', ''));
        $page      = max(1, (int) $this->request->get('page', 1));
        $pageSize  = (int) $this->request->get('pagesize', 10);
        $pageSize  = $pageSize > 0 ? min($pageSize, 100) : 10;

        $query = ScopeService::queryAs('restaurant_takeaway_order', $aid, 'o')
            ->leftJoin('member m', 'm.id = o.mid')
            ->field('o.*, m.nickname as member_nickname, m.tel as member_tel')
            ->where('o.delete', 0);

        if ('' !== $status) {
            $query->where('o.status', (int) $status);
        }
        if ('' !== $keyword) {
            $like = '%' . $keyword . '%';
            $query->where(function ($q) use ($like) {
                $q->where('o.ordernum', 'like', $like)
                    ->whereOr('o.linkman', 'like', $like)
                    ->whereOr('o.tel', 'like', $like);
            });
        }
        if ($mid > 0) {
            $query->where('o.mid', $mid);
        }
        if ('' !== $startTime) {
            $query->where('o.createtime', '>=', strtotime($startTime));
        }
        if ('' !== $endTime) {
            $query->where('o.createtime', '<', strtotime($endTime) + 86400);
        }

        $total = (clone $query)->count();

        $rows = $query->order('o.id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        return $this->success([
            'list'     => array_map(fn (array $row) => $this->formatRow($row), $rows),
            'total'    => $total,
            'page'     => $page,
            'pagesize' => $pageSize,
        ]);
    }

    /**
     * 订单详情（订单 + 商品明细 + 会员）
     * GET /admin/restaurant/takeawayOrder/detail?id=
     */
    public function detail()
    {
        $aid = ScopeService::aidOf($this->request);
        $id  = (int) $this->request->get('id', 0);

        $order = $this->findOrder($aid, $id);

        $goods = Db::name('restaurant_takeaway_order_goods')
            ->where('orderid', $id)
            ->order('id', 'asc')
            ->select()
            ->toArray();

        $member = Db::name('member')
            ->where('aid', $aid)
            ->where('id', (int) ($order['mid'] ?? 0))
            ->field('id, nickname, headimg, tel')
            ->find();

        return $this->success([
            'order' => $this->formatRow($order),
            'goods' => array_map(static fn (array $g) => [
                'id'         => (int) ($g['id'] ?? 0),
                'product_id' => (int) ($g['proid'] ?? 0),
                'name'       => (string) ($g['name'] ?? ''),
                'pic'        => (string) ($g['pic'] ?? ''),
                'ggname'     => (string) ($g['ggname'] ?? ''),
                'num'        => (int) ($g['num'] ?? 0),
                'price'      => (float) ($g['sell_price'] ?? 0),
                'totalprice' => (float) ($g['totalprice'] ?? 0),
            ], $goods),
            'member' => empty($member) ? null : [
                'mid'      => (int) ($member['id'] ?? 0),
                'nickname' => (string) ($member['nickname'] ?? ''),
                'headimg'  => (string) ($member['headimg'] ?? ''),
                'tel'      => (string) ($member['tel'] ?? ''),
            ],
        ]);
    }

    /**
     * 接单（已支付 → 接单态 12）
     * POST /admin/restaurant/takeawayOrder/jiedan {id}
     */
    public function jiedan()
    {
        $aid = ScopeService::aidOf($this->request);
        $id  = (int) $this->request->post('id', 0);

        $order = $this->findOrder($aid, $id);

        if (12 === (int) $order['status']) {
            return $this->success([], '该订单已是接单状态');
        }

        if (1 !== (int) $order['status']) {
            throw new BusinessException('仅已支付订单可接单');
        }

        $affected = Db::name('restaurant_takeaway_order')
            ->where('id', $id)
            ->where('status', 1)
            ->update(['status' => 12]);

        if (1 !== $affected) {
            throw new BusinessException('订单状态已变化，请刷新后重试');
        }

        return $this->success([], '已接单');
    }

    /**
     * 关单（仅未支付；事务：订单置4 + 释放券 + 返还抵扣积分）
     * POST /admin/restaurant/takeawayOrder/close {id}
     */
    public function close()
    {
        $aid = ScopeService::aidOf($this->request);
        $id  = (int) $this->request->post('id', 0);

        Db::transaction(function () use ($aid, $id) {
            $order = Db::name('restaurant_takeaway_order')
                ->where('aid', $aid)
                ->where('id', $id)
                ->lock(true)
                ->find();

            if (empty($order)) {
                throw new BusinessException('订单不存在', 404, 404);
            }

            if (0 !== (int) $order['status']) {
                throw new BusinessException('仅未支付订单可关闭');
            }

            $affected = Db::name('restaurant_takeaway_order')
                ->where('id', $id)
                ->where('status', 0)
                ->update(['status' => 4]);

            if (1 !== $affected) {
                return; // 并发已变更：幂等跳过
            }

            // 释放券预锁定
            $this->couponService->releaseByOrder((int) ($order['coupon_rid'] ?? 0), $id);

            // 返还抵扣积分
            if ((int) ($order['scoredkscore'] ?? 0) > 0) {
                $this->scoreService->refundForCancel(
                    $aid,
                    (int) ($order['mid'] ?? 0),
                    (int) $order['scoredkscore'],
                    '关闭订单返还 外卖订单' . (string) ($order['ordernum'] ?? '')
                );
            }
        });

        return $this->success([], '订单已关闭');
    }

    /**
     * 发货（二期桩：一期外卖发货链路未接入）
     * POST /admin/restaurant/takeawayOrder/sendExpress {id, express_com, express_no}
     */
    public function sendExpress()
    {
        $aid = ScopeService::aidOf($this->request);
        $id  = (int) $this->request->post('id', 0);

        $this->findOrder($aid, $id);

        throw new BusinessException('外卖发货链路二期待接入');
    }

    /* ================= 内部工具 ================= */

    /**
     * 查找本租户外卖订单（不存在抛 404）
     *
     * @return array<string, mixed>
     */
    protected function findOrder(int $aid, int $id): array
    {
        if ($id <= 0) {
            throw new BusinessException('缺少订单ID');
        }

        $order = ScopeService::query('restaurant_takeaway_order', $aid)
            ->where('id', $id)
            ->where('delete', 0)
            ->find();

        if (empty($order)) {
            throw new BusinessException('订单不存在', 404, 404);
        }

        return $order;
    }

    /**
     * 订单行格式化（含状态文案）
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function formatRow(array $row): array
    {
        $status       = (int) ($row['status'] ?? 0);
        $refundStatus = (int) ($row['refund_status'] ?? 0);

        $refundText = match ($refundStatus) {
            1       => '退款审核中',
            2       => '已退款',
            3       => '退款已驳回',
            default => '',
        };

        return [
            'id'              => (int) ($row['id'] ?? 0),
            'ordernum'        => (string) ($row['ordernum'] ?? ''),
            'mid'             => (int) ($row['mid'] ?? 0),
            'member_nickname' => (string) ($row['member_nickname'] ?? ''),
            'member_tel'      => (string) ($row['member_tel'] ?? ''),
            'linkman'         => (string) ($row['linkman'] ?? ''),
            'tel'             => (string) ($row['tel'] ?? ''),
            'address'         => trim(((string) ($row['area'] ?? '')) . ((string) ($row['area2'] ?? '')) . ((string) ($row['address'] ?? ''))),
            'message'         => (string) ($row['message'] ?? ''),
            'remark'          => (string) ($row['remark'] ?? ''),
            'totalprice'      => (float) ($row['totalprice'] ?? 0),
            'product_price'   => (float) ($row['product_price'] ?? 0),
            'pack_fee'        => (float) ($row['pack_fee'] ?? 0),
            'freight_price'   => (float) ($row['freight_price'] ?? 0),
            'coupon_money'    => (float) ($row['coupon_money'] ?? 0),
            'use_score'       => (int) ($row['scoredkscore'] ?? 0),
            'status'          => $status,
            'status_text'     => self::STATUS_TEXT[$status] ?? '未知',
            'refund_status'   => $refundStatus,
            'refund_text'     => $refundText,
            'refund_reason'   => (string) ($row['refund_reason'] ?? ''),
            'refund_money'    => (float) ($row['refund_money'] ?? 0),
            'express_com'     => (string) ($row['express_com'] ?? ''),
            'express_no'      => (string) ($row['express_no'] ?? ''),
            'paytype'         => (string) ($row['paytype'] ?? ''),
            'pay_time'        => (int) ($row['paytime'] ?? 0),
            'createtime'      => (int) ($row['createtime'] ?? 0),
        ];
    }
}
