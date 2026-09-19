<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\BookingService;
use app\common\service\PlogService;
use app\common\service\ScopeService;
use think\facade\Db;

/**
 * 租户后台 - 餐饮预定订单（批次6 T6-1 预定订座模块）
 *
 * 状态口径（对齐参考系统）：
 * - status：0未支付 1已支付 4已关闭
 * - check_status：0待审核 1审核通过 -1已驳回（驳回自动原路退款）
 * - refund_status：0无 2已退款
 *
 * 筛选：''全部 0待支付 1待审核（已支付未审核） 3已完成（已支付审核通过） 4已关闭 refund退款
 */
class RestaurantBookingOrder extends BaseController
{
    public function __construct(\think\App $app, protected BookingService $bookingService)
    {
        parent::__construct($app);
    }

    /**
     * 预定订单列表（筛选 st/订单号/联系人/手机号/桌号/时间范围；统计今日单数与定金成交额）
     * GET /admin/restaurant/booking/list
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        $st        = trim((string) $this->request->get('st', ''));
        $ordernum  = trim((string) $this->request->get('ordernum', ''));
        $linkman   = trim((string) $this->request->get('linkman', ''));
        $tel       = trim((string) $this->request->get('tel', ''));
        $tableName = trim((string) $this->request->get('table_name', ''));
        $startTime = trim((string) $this->request->get('start_time', ''));
        $endTime   = trim((string) $this->request->get('end_time', ''));
        $page      = max(1, (int) $this->request->get('page', 1));
        $pageSize  = (int) $this->request->get('pagesize', 10);
        $pageSize  = $pageSize > 0 ? min($pageSize, 100) : 10;

        $query = ScopeService::queryAs('restaurant_booking_order', $aid, 'o')
            ->leftJoin('restaurant_table t', 't.id = o.tableid')
            ->leftJoin('member m', 'm.id = o.mid')
            ->field('o.*, t.name as table_name, m.nickname as member_nickname, m.headimg as member_headimg')
            ->where('o.bid', 0);

        switch ($st) {
            case '0': // 待支付
                $query->where('o.status', BookingService::STATUS_UNPAID);
                break;
            case '1': // 待审核
                $query->where('o.status', BookingService::STATUS_PAID)
                    ->where('o.check_status', BookingService::CHECK_PENDING);
                break;
            case '3': // 已完成（已支付且审核通过）
                $query->where('o.status', BookingService::STATUS_PAID)
                    ->where('o.check_status', BookingService::CHECK_PASS);
                break;
            case '4': // 已关闭
                $query->where('o.status', BookingService::STATUS_CLOSED);
                break;
            case 'refund': // 退款
                $query->where('o.refund_status', '>', 0);
                break;
            default:
                break;
        }

        if ('' !== $ordernum) {
            $query->where('o.ordernum', 'like', '%' . $ordernum . '%');
        }
        if ('' !== $linkman) {
            $query->where('o.linkman', 'like', '%' . $linkman . '%');
        }
        if ('' !== $tel) {
            $query->where('o.tel', 'like', '%' . $tel . '%');
        }
        if ('' !== $tableName) {
            $query->where('t.name', 'like', '%' . $tableName . '%');
        }
        if ('' !== $startTime) {
            $query->where('o.createtime', '>=', strtotime($startTime));
        }
        if ('' !== $endTime) {
            $query->where('o.createtime', '<', strtotime($endTime) + 86400);
        }

        $total = (clone $query)->count();

        $rows = $query->order('o.id', 'desc')->page($page, $pageSize)->select()->toArray();

        // 今日统计：单数=今日创建；成交额=今日支付的定金
        $dayStart = strtotime(date('Y-m-d'));
        $dayEnd   = $dayStart + 86400;

        $baseStat = ScopeService::query('restaurant_booking_order', $aid)->where('bid', 0);

        $todayCount = (clone $baseStat)
            ->where('createtime', '>=', $dayStart)
            ->where('createtime', '<', $dayEnd)
            ->count();

        $todayMoney = (float) (clone $baseStat)
            ->where('status', BookingService::STATUS_PAID)
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
     * 预定订单详情（订单 + 桌台 + 会员）
     * GET /admin/restaurant/booking/detail?id=
     */
    public function detail()
    {
        $aid = ScopeService::aidOf($this->request);
        $id  = (int) $this->request->get('id', 0);

        $order = $this->findOrder($aid, $id);

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
            'table' => empty($table) ? null : [
                'id'   => (int) ($table['id'] ?? 0),
                'name' => (string) ($table['name'] ?? ''),
                'seat' => (int) ($table['seat'] ?? 0),
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
     * 审核（type=access 通过：短信通知会员；type=refuse 驳回：已支付自动原路退款并关闭）
     * POST /admin/restaurant/booking/check {id, type}
     */
    public function check()
    {
        $aid  = ScopeService::aidOf($this->request);
        $id   = (int) $this->request->post('id', 0);
        $type = trim((string) $this->request->post('type', ''));

        $this->findOrder($aid, $id);

        $this->bookingService->checkOrder($aid, $id, $type);

        $uid = (int) ($this->request->authUid ?? 0);
        PlogService::write($aid, $uid, '餐饮预定订单审核（' . ('access' === $type ? '通过' : '驳回') . '）' . $id);

        return $this->success([], 'access' === $type ? '已通过审核' : '已驳回并处理退款');
    }

    /**
     * 设置备注
     * POST /admin/restaurant/booking/setremark {id, remark}
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

        $this->findOrder($aid, $id);

        Db::name('restaurant_booking_order')->where('id', $id)->update(['remark' => $remark]);

        return $this->success([], '备注已保存');
    }

    /**
     * 关闭订单（仅未支付单；条件更新幂等）
     * POST /admin/restaurant/booking/close {id}
     */
    public function close()
    {
        $aid = ScopeService::aidOf($this->request);
        $id  = (int) $this->request->post('id', 0);

        $order = $this->findOrder($aid, $id);

        if (BookingService::STATUS_UNPAID !== (int) $order['status']) {
            throw new BusinessException('仅未支付订单可关闭');
        }

        Db::name('restaurant_booking_order')
            ->where('id', $id)
            ->where('status', BookingService::STATUS_UNPAID)
            ->update(['status' => BookingService::STATUS_CLOSED]);

        $uid = (int) ($this->request->authUid ?? 0);
        PlogService::write($aid, $uid, '餐饮预定订单关闭' . $id);

        return $this->success([], '订单已关闭');
    }

    /* ================= 内部工具 ================= */

    /**
     * 查找本租户预定订单（不存在抛 404）
     *
     * @return array<string, mixed>
     */
    protected function findOrder(int $aid, int $id): array
    {
        if ($id <= 0) {
            throw new BusinessException('缺少订单ID');
        }

        $order = ScopeService::query('restaurant_booking_order', $aid)
            ->where('id', $id)
            ->where('bid', 0)
            ->find();

        if (empty($order)) {
            throw new BusinessException('订单不存在', 404, 404);
        }

        return $order;
    }

    /**
     * 订单行格式化（含状态/审核/退款文案）
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function formatRow(array $row): array
    {
        $status      = (int) ($row['status'] ?? 0);
        $checkStatus = (int) ($row['check_status'] ?? 0);

        return [
            'id'               => (int) ($row['id'] ?? 0),
            'ordernum'         => (string) ($row['ordernum'] ?? ''),
            'mid'              => (int) ($row['mid'] ?? 0),
            'member_nickname'  => (string) ($row['member_nickname'] ?? ''),
            'member_headimg'   => (string) ($row['member_headimg'] ?? ''),
            'table_id'         => (int) ($row['tableid'] ?? 0),
            'table_name'       => (string) ($row['table_name'] ?? ''),
            'booking_time'     => (string) ($row['booking_time'] ?? ''),
            'seat'             => (int) ($row['seat'] ?? 0),
            'tel'              => (string) ($row['tel'] ?? ''),
            'linkman'          => (string) ($row['linkman'] ?? ''),
            'message'          => (string) ($row['message'] ?? ''),
            'remark'           => (string) ($row['remark'] ?? ''),
            'booking_fee'      => (float) ($row['booking_fee'] ?? 0),
            'totalprice'       => (float) ($row['totalprice'] ?? 0),
            'status'           => $status,
            'status_text'      => match ($status) {
                0       => '待支付',
                1       => '已支付',
                4       => '已关闭',
                default => '未知',
            },
            'check_status'     => $checkStatus,
            'check_text'       => match ($checkStatus) {
                1       => '审核通过',
                -1      => '已驳回',
                default => '待审核',
            },
            'refund_status'    => (int) ($row['refund_status'] ?? 0),
            'refund_text'      => 2 === (int) ($row['refund_status'] ?? 0) ? '已退款' : '',
            'refund_money'     => (float) ($row['refund_money'] ?? 0),
            'refund_reason'    => (string) ($row['refund_reason'] ?? ''),
            'paytype'          => (string) ($row['paytype'] ?? ''),
            'pay_time'         => (int) ($row['paytime'] ?? 0),
            'createtime'       => (int) ($row['createtime'] ?? 0),
        ];
    }
}
