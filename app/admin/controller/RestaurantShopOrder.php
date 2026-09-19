<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\CouponService;
use app\common\service\PayService;
use app\common\service\RestaurantService;
use app\common\service\ScoreService;
use app\common\service\ScopeService;
use think\facade\Db;

/**
 * 租户后台 - 餐饮堂食订单（P14-2 餐饮一期订单侧）
 *
 * 状态口径（对齐参考系统，一期仅处理 eattype=0 先付后吃）：
 * - status：0未支付 1已支付 3已完成 4关闭
 * - refund_status：0无 1审核中 2已退款 3已驳回
 *
 * 支付联动：pay_order 统一支付单（order_type=restaurant）；
 * 退款走 PayService::refundBiz（mock 兼容），退款流水落 wxrefund_log。
 */
class RestaurantShopOrder extends BaseController
{
    /** 堂食订单状态文案 */
    public const STATUS_TEXT = [0 => '未支付', 1 => '已支付', 3 => '已完成', 4 => '已关闭'];

    public function __construct(
        \think\App $app,
        protected RestaurantService $restaurantService,
        protected CouponService $couponService,
        protected ScoreService $scoreService
    ) {
        parent::__construct($app);
    }

    /**
     * 订单列表（筛选 status/桌号/订单号/时间/会员；统计今日单数与成交额）
     * GET /admin/restaurant/shopOrder/list
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        $status    = trim((string) $this->request->get('status', ''));
        $refundStatus = trim((string) $this->request->get('refund_status', ''));
        $ordernum  = trim((string) $this->request->get('ordernum', ''));
        $tableName = trim((string) $this->request->get('table_name', ''));
        $mid       = (int) $this->request->get('mid', 0);
        $startTime = trim((string) $this->request->get('start_time', ''));
        $endTime   = trim((string) $this->request->get('end_time', ''));
        $page      = max(1, (int) $this->request->get('page', 1));
        $pageSize  = (int) $this->request->get('pagesize', 10);
        $pageSize  = $pageSize > 0 ? min($pageSize, 100) : 10;

        $query = ScopeService::queryAs('restaurant_shop_order', $aid, 'o')
            ->leftJoin('restaurant_table t', 't.id = o.tableid')
            ->leftJoin('member m', 'm.id = o.mid')
            ->field('o.*, t.name as table_name, m.nickname as member_nickname, m.tel as member_tel')
            ->where('o.delete', 0);

        if ('' !== $status) {
            $query->where('o.status', (int) $status);
        }
        if ('' !== $refundStatus) {
            $query->where('o.refund_status', (int) $refundStatus);
        }
        if ('' !== $ordernum) {
            $query->where('o.ordernum', 'like', '%' . $ordernum . '%');
        }
        if ('' !== $tableName) {
            $query->where('t.name', 'like', '%' . $tableName . '%');
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

        // 今日统计：单数=今日创建单；成交额=今日支付金额
        $dayStart = strtotime(date('Y-m-d'));
        $dayEnd   = $dayStart + 86400;

        $baseStat = ScopeService::query('restaurant_shop_order', $aid)->where('delete', 0);

        $todayCount = (clone $baseStat)
            ->where('createtime', '>=', $dayStart)
            ->where('createtime', '<', $dayEnd)
            ->count();

        $todayMoney = (float) (clone $baseStat)
            ->whereIn('status', [1, 3])
            ->where('paytime', '>=', $dayStart)
            ->where('paytime', '<', $dayEnd)
            ->sum('totalprice');

        return $this->success([
            'list'     => array_map(fn (array $row) => $this->formatRow($row), $rows),
            'total'    => $total,
            'page'     => $page,
            'pagesize' => $pageSize,
            'stats'    => [
                'today_count' => $todayCount,
                'today_money' => round($todayMoney, 2),
            ],
        ]);
    }

    /**
     * 订单详情（订单 + 商品明细 + 桌信息 + 会员）
     * GET /admin/restaurant/shopOrder/detail?id=
     */
    public function detail()
    {
        $aid = ScopeService::aidOf($this->request);
        $id  = (int) $this->request->get('id', 0);

        $order = $this->findOrder($aid, $id);

        $goods = Db::name('restaurant_shop_order_goods')
            ->where('orderid', $id)
            ->order('id', 'asc')
            ->select()
            ->toArray();

        $table = ScopeService::query('restaurant_table', $aid)
            ->where('id', (int) ($order['tableid'] ?? 0))
            ->find();

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
                'num'        => (int) ($g['num'] ?? 0),
                'price'      => (float) ($g['sell_price'] ?? 0),
                'totalprice' => (float) ($g['totalprice'] ?? 0),
            ], $goods),
            'table' => empty($table) ? null : [
                'id'        => (int) ($table['id'] ?? 0),
                'name'      => (string) ($table['name'] ?? ''),
                'persons'   => (int) ($table['seat'] ?? 0),
                'area_name' => (string) ScopeService::query('restaurant_area', $aid)
                    ->where('id', (int) ($table['cid'] ?? 0))->value('name'),
            ],
            'member' => empty($member) ? null : [
                'mid'      => (int) ($member['id'] ?? 0),
                'nickname' => (string) ($member['nickname'] ?? ''),
                'headimg'  => (string) ($member['headimg'] ?? ''),
                'tel'      => (string) ($member['tel'] ?? ''),
            ],
        ]);
    }

    /**
     * 设置备注（常用备注选取或手填，写入订单 remark）
     * POST /admin/restaurant/shopOrder/setremark {id, remark}
     */
    public function setremark()
    {
        $aid    = ScopeService::aidOf($this->request);
        $id     = (int) $this->request->post('id', 0);
        $remark = trim((string) $this->request->post('remark', ''));

        if ('' === $remark) {
            throw new BusinessException('请填写备注内容');
        }

        if (mb_strlen($remark) > 200) {
            throw new BusinessException('备注内容过长（限200字）');
        }

        $order = $this->findOrder($aid, $id);

        Db::name('restaurant_shop_order')
            ->where('id', (int) $order['id'])
            ->update(['remark' => $remark]);

        return $this->success([], '备注已保存');
    }

    /**
     * 改价（仅未支付可改）
     *
     * 路数照参考系统：改价即重生成订单号并同步支付单，防止旧支付单号被复用：
     * - 订单与商品行 ordernum 全部换新
     * - pay_order 未支付支付单同步新金额（真实支付以此为准）
     * - 旧 payorder 遗留单防御性同步（如存在）
     *
     * POST /admin/restaurant/shopOrder/changeprice {id, newprice}
     */
    public function changeprice()
    {
        $aid      = ScopeService::aidOf($this->request);
        $id       = (int) $this->request->post('id', 0);
        $newPrice = $this->request->post('newprice');

        if (!is_numeric($newPrice) || (float) $newPrice <= 0) {
            throw new BusinessException('请填写有效的新金额（大于0）');
        }

        $newPrice = round((float) $newPrice, 2);

        Db::transaction(function () use ($aid, $id, $newPrice) {
            $order = Db::name('restaurant_shop_order')
                ->where('aid', $aid)
                ->where('id', $id)
                ->lock(true)
                ->find();

            if (empty($order)) {
                throw new BusinessException('订单不存在', 404, 404);
            }

            if (0 !== (int) $order['status']) {
                throw new BusinessException('仅未支付订单可改价');
            }

            $oldOrderNum = (string) ($order['ordernum'] ?? '');

            // 重生成订单号（防支付单号复用）
            $newOrderNum = 'RS' . date('ymdHis') . random_int(100000, 999999);

            Db::name('restaurant_shop_order')->where('id', $id)->update([
                'totalprice' => $newPrice,
                'pay_amount' => $newPrice,
                'ordernum'   => $newOrderNum,
            ]);

            Db::name('restaurant_shop_order_goods')->where('orderid', $id)->update([
                'ordernum' => $newOrderNum,
            ]);

            // 同步统一支付单（未支付行按新金额；下次拉起支付即生效）
            Db::name('pay_order')
                ->where('order_type', 'restaurant')
                ->where('biz_order_id', $id)
                ->where('status', 0)
                ->update(['pay_amount' => $newPrice]);

            // 旧 payorder 遗留单防御性同步（ordernum/money 换新）
            if ('' !== $oldOrderNum) {
                Db::name('payorder')
                    ->where('aid', $aid)
                    ->where('ordernum', $oldOrderNum)
                    ->where('status', 0)
                    ->update(['ordernum' => $newOrderNum, 'money' => $newPrice]);
            }
        });

        return $this->success([], '改价成功，订单号已更新');
    }

    /**
     * 关单（仅未支付；事务：订单置4 + 释放券 + 返还抵扣积分）
     * POST /admin/restaurant/shopOrder/close {id}
     */
    public function close()
    {
        $aid = ScopeService::aidOf($this->request);
        $id  = (int) $this->request->post('id', 0);

        Db::transaction(function () use ($aid, $id) {
            $order = Db::name('restaurant_shop_order')
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

            $affected = Db::name('restaurant_shop_order')
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
                    '关闭订单返还 堂食订单' . (string) ($order['ordernum'] ?? '')
                );
            }
        });

        return $this->success([], '订单已关闭');
    }

    /**
     * 代收款（线下收款置已支付：核销券 + 赠送积分，走既有支付入账管道）
     * POST /admin/restaurant/shopOrder/ispay {id}
     */
    public function ispay()
    {
        $aid = ScopeService::aidOf($this->request);
        $id  = (int) $this->request->post('id', 0);

        $order = $this->findOrder($aid, $id);

        if (0 !== (int) $order['status']) {
            if (in_array((int) $order['status'], [1, 3], true)) {
                return $this->success([], '该订单已是支付完成状态');
            }
            throw new BusinessException('仅未支付订单可代收款');
        }

        // 复用支付入账管道（幂等；内部核销券 + 赠送积分）
        $handled = $this->restaurantService->onPaid($id, '线下收款', 'ADMINPAY' . time() . random_int(100, 999));

        if ($handled) {
            // 同步统一支付单（代收款视为渠道已支付）
            Db::name('pay_order')
                ->where('order_type', 'restaurant')
                ->where('biz_order_id', $id)
                ->where('status', 0)
                ->update(['status' => 1, 'pay_time' => time(), 'pay_type' => 'offline']);
        }

        return $this->success(['handled' => $handled], $handled ? '代收款成功' : '该订单已处理过');
    }

    /**
     * 退款审核（st=1 同意：PayService 原路退款 + 退款流水 + 退积分；st=2 驳回）
     * POST /admin/restaurant/shopOrder/refundCheck {id, st, remark?}
     */
    public function refundCheck()
    {
        $aid    = ScopeService::aidOf($this->request);
        $id     = (int) $this->request->post('id', 0);
        $st     = (int) $this->request->post('st', 0);
        $remark = trim((string) $this->request->post('remark', ''));

        if (1 !== $st && 2 !== $st) {
            throw new BusinessException('非法的审核结果');
        }

        $order = $this->findOrder($aid, $id);

        if (1 !== (int) ($order['refund_status'] ?? 0)) {
            throw new BusinessException('该订单无待审核的退款申请');
        }

        // 驳回：仅改退款态
        if (2 === $st) {
            Db::name('restaurant_shop_order')->where('id', $id)->update([
                'refund_status'      => 3,
                'refund_checkremark' => $remark,
                'refund_time'        => time(),
            ]);

            return $this->success([], '已驳回退款申请');
        }

        if (!in_array((int) $order['status'], [1, 3], true)) {
            throw new BusinessException('该订单状态不允许退款（仅已支付/已完成可退）');
        }

        Db::transaction(function () use ($aid, $id, $remark) {
            $locked = Db::name('restaurant_shop_order')
                ->where('aid', $aid)
                ->where('id', $id)
                ->lock(true)
                ->find();

            if (empty($locked) || 1 !== (int) ($locked['refund_status'] ?? 0)
                || !in_array((int) $locked['status'], [1, 3], true)) {
                throw new BusinessException('订单状态已变化，请刷新后重试');
            }

            $refundMoney = (float) $locked['refund_money'] > 0
                ? (float) $locked['refund_money']
                : (float) $locked['totalprice'];

            if ($refundMoney <= 0) {
                throw new BusinessException('退款金额无效');
            }

            $refundSn = 'RR' . date('YmdHis') . str_pad((string) ($id % 1000000), 6, '0', STR_PAD_LEFT);

            // 原路退款（mock 即时成功；真实模式微信 V3 原路退款）
            $result = PayService::refundBiz('restaurant', $id, $refundMoney, $refundSn);

            if (empty($result['success'])) {
                throw new BusinessException('退款渠道返回失败');
            }

            // 退款流水（mock 也留痕，财务可查）
            Db::name('wxrefund_log')->insert([
                'aid'           => $aid,
                'mch_id'        => '',
                'ordernum'      => (string) ($locked['ordernum'] ?? ''),
                'out_refund_no' => $refundSn,
                'totalprice'    => (float) $locked['totalprice'],
                'refundmoney'   => $refundMoney,
                'createtime'    => (string) time(),
                'status'        => 1,
                'remark'        => '堂食订单退款' . ('' !== $remark ? '：' . $remark : ''),
                'errmsg'        => '',
            ]);

            // 订单置关闭 + 退款态
            Db::name('restaurant_shop_order')->where('id', $id)->update([
                'status'             => 4,
                'refund_status'      => 2,
                'refund_money'       => $refundMoney,
                'refund_time'        => time(),
                'refund_checkremark' => $remark,
            ]);

            $mid = (int) ($locked['mid'] ?? 0);

            if ($mid > 0) {
                // 抵扣积分返还
                if ((int) ($locked['scoredkscore'] ?? 0) > 0) {
                    $this->scoreService->refundForCancel(
                        $aid,
                        $mid,
                        (int) $locked['scoredkscore'],
                        '订单退款返还 堂食订单' . (string) ($locked['ordernum'] ?? '')
                    );
                }

                // 扣回支付赠送积分（余额不足按余额上限扣）
                $gift = (int) ($locked['givescore'] ?? 0);

                if ($gift > 0) {
                    $balance = (int) Db::name('member')->where('aid', $aid)->where('id', $mid)->value('score');
                    $deduct  = min($gift, max($balance, 0));

                    if ($deduct > 0) {
                        $this->scoreService->deductForOrder(
                            $aid,
                            $mid,
                            $deduct,
                            '退款扣回赠送积分 堂食订单' . (string) ($locked['ordernum'] ?? '')
                        );
                    }
                }
            }
        });

        return $this->success([], '已同意并完成退款');
    }

    /**
     * 补打小票（一期桩：打印链路待接入，预留与 Restaurant::print 同构入口）
     * POST /admin/restaurant/shopOrder/print {id}
     */
    public function print()
    {
        $aid = ScopeService::aidOf($this->request);
        $id  = (int) $this->request->post('id', 0);

        $this->findOrder($aid, $id);

        return $this->success(['printed' => false], '打印链路待接入');
    }

    /* ================= 内部工具 ================= */

    /**
     * 查找本租户堂食订单（不存在抛 404）
     *
     * @return array<string, mixed>
     */
    protected function findOrder(int $aid, int $id): array
    {
        if ($id <= 0) {
            throw new BusinessException('缺少订单ID');
        }

        $order = ScopeService::query('restaurant_shop_order', $aid)
            ->where('id', $id)
            ->where('delete', 0)
            ->find();

        if (empty($order)) {
            throw new BusinessException('订单不存在', 404, 404);
        }

        return $order;
    }

    /**
     * 订单行格式化（含状态/退款文案）
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
            'id'                 => (int) ($row['id'] ?? 0),
            'ordernum'           => (string) ($row['ordernum'] ?? ''),
            'mid'                => (int) ($row['mid'] ?? 0),
            'member_nickname'    => (string) ($row['member_nickname'] ?? ''),
            'member_tel'         => (string) ($row['member_tel'] ?? ''),
            'table_id'           => (int) ($row['tableid'] ?? 0),
            'table_name'         => (string) ($row['table_name'] ?? ''),
            'renshu'             => (int) ($row['renshu'] ?? 0),
            'totalprice'         => (float) ($row['totalprice'] ?? 0),
            'product_price'      => (float) ($row['product_price'] ?? 0),
            'coupon_money'       => (float) ($row['coupon_money'] ?? 0),
            'score_money'        => (float) ($row['score_money'] ?? ($row['scoredk_money'] ?? 0)),
            'pay_amount'         => (float) ($row['pay_amount'] ?? $row['totalprice']),
            'use_score'          => (int) ($row['scoredkscore'] ?? 0),
            'eattype'            => (int) ($row['eattype'] ?? 0),
            'remark'             => (string) ($row['remark'] ?? ''),
            'status'             => $status,
            'status_text'        => self::STATUS_TEXT[$status] ?? '未知',
            'refund_status'      => $refundStatus,
            'refund_text'        => $refundText,
            'refund_reason'      => (string) ($row['refund_reason'] ?? ''),
            'refund_money'       => (float) ($row['refund_money'] ?? 0),
            'refund_checkremark' => (string) ($row['refund_checkremark'] ?? ''),
            'paytype'            => (string) ($row['paytype'] ?? ''),
            'pay_time'           => (int) ($row['paytime'] ?? 0),
            'createtime'         => (int) ($row['createtime'] ?? 0),
        ];
    }
}
