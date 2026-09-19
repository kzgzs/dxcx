<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\DepositService;
use app\common\service\ScopeService;
use think\facade\Db;

/**
 * 租户后台 - 餐饮寄存管理（批次6-T6-3 任务204）
 *
 * 状态机：0审核中 → 1寄存中/3未通过；1 → 2全部取出（部分取出仅扣 num）
 * 对标 canzhao ApiAdminRestaurantDepositOrder（index/detail/check/takeout）
 */
class RestaurantDeposit extends BaseController
{
    public function __construct(\think\App $app, protected DepositService $depositService)
    {
        parent::__construct($app);
    }

    /**
     * 寄存订单列表（状态筛选 + 关键词：物品名/联系人/电话）
     * GET /admin/restaurant/deposit/list?st=&keyword=&page=&pagesize=
     */
    public function list()
    {
        $aid      = ScopeService::aidOf($this->request);
        $st       = trim((string) $this->request->get('st', 'all'));
        $keyword  = trim((string) $this->request->get('keyword', ''));
        $page     = (int) $this->request->get('page', 1);
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        $query = ScopeService::query('restaurant_deposit_order', $aid)->where('bid', 0);

        if ('' !== $keyword) {
            $query->where('name|linkman|tel', 'like', '%' . $keyword . '%');
        }

        if ('all' !== $st && in_array($st, ['0', '1', '2', '3'], true)) {
            $query->where('status', (int) $st);
        }

        $total = (clone $query)->count();

        $rows = $query->page(max($page, 1), $pageSize)->order('id', 'desc')->select()->toArray();

        // 会员信息补全
        $mids = array_values(array_unique(array_map(static fn (array $r) => (int) ($r['mid'] ?? 0), $rows)));
        $memberMap = [];

        if ($mids) {
            $members = Db::name('member')->whereIn('id', $mids)->field('id,nickname,headimg,tel')->select()->toArray();

            foreach ($members as $m) {
                $memberMap[(int) $m['id']] = $m;
            }
        }

        $list = array_map(function (array $row) use ($memberMap) {
            $status = (int) ($row['status'] ?? 0);
            $member = $memberMap[(int) ($row['mid'] ?? 0)] ?? null;

            return [
                'id'            => (int) $row['id'],
                'mid'           => (int) ($row['mid'] ?? 0),
                'nickname'      => (string) ($member['nickname'] ?? ''),
                'headimg'       => (string) ($member['headimg'] ?? ''),
                'member_tel'    => (string) ($member['tel'] ?? ''),
                'name'          => (string) ($row['name'] ?? ''),
                'num'           => (int) ($row['num'] ?? 0),
                'linkman'       => (string) ($row['linkman'] ?? ''),
                'tel'           => (string) ($row['tel'] ?? ''),
                'pic'           => (string) ($row['pic'] ?? ''),
                'message'       => (string) ($row['message'] ?? ''),
                'remark'        => (string) ($row['remark'] ?? ''),
                'position_id'   => (int) ($row['position_id'] ?? 0),
                'position_name' => (string) ($row['position_name'] ?? ''),
                'number_id'     => (int) ($row['number_id'] ?? 0),
                'number_name'   => (string) ($row['number_name'] ?? ''),
                'status'        => $status,
                'status_text'   => $this->depositService->statusLabel($status),
                'createtime'    => (int) ($row['createtime'] ?? 0),
                'takeout_time'  => (int) ($row['takeout_time'] ?? 0),
            ];
        }, $rows);

        return $this->success([
            'list'     => $list,
            'total'    => $total,
            'page'     => max($page, 1),
            'pagesize' => $pageSize,
        ]);
    }

    /**
     * 订单详情（含取出/存入流水）
     * GET /admin/restaurant/deposit/detail?id=
     */
    public function detail()
    {
        $aid = ScopeService::aidOf($this->request);
        $id  = (int) $this->request->get('id', 0);

        if ($id <= 0) {
            throw new BusinessException('缺少订单ID');
        }

        $order = ScopeService::query('restaurant_deposit_order', $aid)->where('id', $id)->find();

        if (empty($order)) {
            throw new BusinessException('寄存订单不存在', 404, 404);
        }

        $member = (int) $order['mid'] > 0
            ? Db::name('member')->where('id', (int) $order['mid'])->field('id,nickname,headimg,tel')->find()
            : null;

        $status = (int) ($order['status'] ?? 0);

        $logs = Db::name('restaurant_deposit_order_log')
            ->where('aid', $aid)->where('order_id', $id)
            ->order('id', 'desc')->select()->toArray();

        return $this->success([
            'order' => [
                'id'            => (int) $order['id'],
                'mid'           => (int) $order['mid'],
                'nickname'      => (string) ($member['nickname'] ?? ''),
                'headimg'       => (string) ($member['headimg'] ?? ''),
                'member_tel'    => (string) ($member['tel'] ?? ''),
                'name'          => (string) $order['name'],
                'num'           => (int) $order['num'],
                'linkman'       => (string) $order['linkman'],
                'tel'           => (string) $order['tel'],
                'pic'           => (string) $order['pic'],
                'message'       => (string) $order['message'],
                'remark'        => (string) $order['remark'],
                'position_id'   => (int) ($order['position_id'] ?? 0),
                'position_name' => (string) ($order['position_name'] ?? ''),
                'number_id'     => (int) ($order['number_id'] ?? 0),
                'number_name'   => (string) ($order['number_name'] ?? ''),
                'tableid'       => (int) ($order['tableid'] ?? 0),
                'status'        => $status,
                'status_text'   => $this->depositService->statusLabel($status),
                'platform'      => (string) $order['platform'],
                'waiter_id'     => (int) $order['waiter_id'],
                'createtime'    => (int) $order['createtime'],
                'takeout_time'  => (int) $order['takeout_time'],
            ],
            'log' => array_map(fn (array $l) => [
                'id'         => (int) $l['id'],
                'type'       => (int) $l['type'],
                'type_text'  => 0 === (int) $l['type'] ? '存入' : '取出',
                'num'        => (int) $l['num'],
                'remark'     => (string) $l['remark'],
                'platform'   => (string) $l['platform'],
                'createtime' => (int) $l['createtime'],
            ], $logs),
        ]);
    }

    /**
     * 审核：access 通过（0→1 + 存入log）；refuse 驳回（0→3）
     * POST /admin/restaurant/deposit/check {id, type: access|refuse}
     */
    public function check()
    {
        $aid  = ScopeService::aidOf($this->request);
        $id   = (int) $this->request->post('id', 0);
        $type = (string) $this->request->post('type', '');

        $this->depositService->check($aid, $id, $type, (int) $this->request->authUid);

        return $this->success([], 'access' === $type ? '审核通过' : '已驳回');
    }

    /**
     * 代取出（部分/全部，幂等防超取）
     * POST /admin/restaurant/deposit/takeout {id, numbers}
     */
    public function takeout()
    {
        $aid     = ScopeService::aidOf($this->request);
        $id      = (int) $this->request->post('id', 0);
        $numbers = (int) $this->request->post('numbers', 0);

        $result = $this->depositService->takeout($aid, $id, $numbers, 'admin', (int) $this->request->authUid);

        return $this->success($result, 0 === $result['left'] ? '已全部取出' : '取出成功');
    }

    /**
     * 设置/修改后台备注
     * POST /admin/restaurant/deposit/setremark {id, remark}
     */
    public function setremark()
    {
        $aid    = ScopeService::aidOf($this->request);
        $id     = (int) $this->request->post('id', 0);
        $remark = trim((string) $this->request->post('remark', ''));

        if ($id <= 0) {
            throw new BusinessException('缺少订单ID');
        }

        $affected = ScopeService::query('restaurant_deposit_order', $aid)->where('id', $id)->update([
            'remark' => $remark,
        ]);

        if ($affected < 1) {
            throw new BusinessException('寄存订单不存在', 404, 404);
        }

        return $this->success([], '设置完成');
    }

    /**
     * 删除寄存订单（连同流水）
     * POST /admin/restaurant/deposit/del {id}
     */
    public function del()
    {
        $aid = ScopeService::aidOf($this->request);
        $id  = (int) $this->request->post('id', 0);

        if ($id <= 0) {
            throw new BusinessException('缺少订单ID');
        }

        $exists = ScopeService::query('restaurant_deposit_order', $aid)->where('id', $id)->find();

        if (empty($exists)) {
            throw new BusinessException('寄存订单不存在', 404, 404);
        }

        Db::name('restaurant_deposit_order')->where('id', $id)->delete();
        Db::name('restaurant_deposit_order_log')->where('aid', $aid)->where('order_id', $id)->delete();

        return $this->success([], '删除成功');
    }
}
