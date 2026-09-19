<?php

declare(strict_types=1);

namespace app\api\controller;

use app\common\exception\BusinessException;
use app\common\service\DepositService;
use think\facade\Db;

/**
 * C端 - 餐饮寄存（批次6-T6-3 任务204，鉴权 role=member）
 *
 * 对标 canzhao ApiRestaurantDeposit（orderlist/orderdetail/orderlog/add/takeout）
 * 决策：无取件码机制，取件凭登录身份（mid 归属校验）或 B端人工操作
 */
class RestaurantDeposit extends Base
{
    public function __construct(\think\App $app, protected DepositService $depositService)
    {
        parent::__construct($app);
    }

    /**
     * 全部存酒（按商家分组，仅展示寄存中 status=1）
     * GET /api/restaurantDeposit/orderlist?page=&pagesize=
     */
    public function orderlist()
    {
        [$aid, $mid] = $this->memberContext();

        $page     = (int) $this->request->param('page', 1);
        $pageSize = (int) $this->request->param('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 50) : 10;

        // 按 bid 分组，取各组最新存酒记录倒序（MySQL8 ONLY_FULL_GROUP_BY 兼容：MAX(id) 排序）
        $groups = Db::name('restaurant_deposit_order')
            ->field('bid, MAX(id) AS maxid')
            ->where('aid', $aid)->where('mid', $mid)->where('status', DepositService::STATUS_DEPOSITING)
            ->group('bid')
            ->order('maxid', 'desc')
            ->page(max($page, 1), $pageSize)
            ->select()->toArray();

        $datalist = [];

        foreach ($groups as $g) {
            $bid   = (int) $g['bid'];
            $binfo = 0 === $bid
                ? Db::name('admin_set')->where('aid', $aid)->field('id,name,logo')->find()
                : Db::name('business')->where('aid', $aid)->where('id', $bid)->field('id,name,logo')->find();

            $prolist = Db::name('restaurant_deposit_order')
                ->where('aid', $aid)->where('bid', $bid)->where('mid', $mid)
                ->where('status', DepositService::STATUS_DEPOSITING)
                ->order('id', 'desc')->select()->toArray();

            $datalist[] = [
                'bid'     => $bid,
                'binfo'   => $binfo ?: ['id' => 0, 'name' => '', 'logo' => ''],
                'prolist' => array_map(fn (array $o) => $this->formatOrder($o), $prolist),
            ];
        }

        return $this->success(['datalist' => $datalist]);
    }

    /**
     * 某商家的存酒记录（含寄存设置）
     * GET /api/restaurantDeposit/orderdetail?bid=&page=&pagesize=
     */
    public function orderdetail()
    {
        [$aid, $mid] = $this->memberContext();

        $bid      = (int) $this->request->param('bid', 0);
        $page     = (int) $this->request->param('page', 1);
        $pageSize = (int) $this->request->param('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 50) : 10;

        $query = Db::name('restaurant_deposit_order')
            ->where('aid', $aid)->where('bid', $bid)->where('mid', $mid);

        $total = (clone $query)->count();

        $rows = $query->page(max($page, 1), $pageSize)->order('id', 'desc')->select()->toArray();

        return $this->success([
            'datalist' => array_map(fn (array $o) => $this->formatOrder($o), $rows),
            'sysset'   => $this->depositService->syssetRow($aid, $bid),
            'total'    => $total,
        ]);
    }

    /**
     * 单笔存酒记录 + 流水（mid 归属校验）
     * GET /api/restaurantDeposit/orderlog?id=
     */
    public function orderlog()
    {
        [$aid, $mid] = $this->memberContext();

        $id = (int) $this->request->param('id', 0);

        if ($id <= 0) {
            throw new BusinessException('参数错误');
        }

        $order = Db::name('restaurant_deposit_order')
            ->where('id', $id)->where('aid', $aid)->where('mid', $mid)->find();

        if (empty($order)) {
            throw new BusinessException('记录不存在', 404, 404);
        }

        $order = $this->formatOrder($order);

        $bid   = (int) ($order['bid'] ?? 0);
        $binfo = $bid <= 0
            ? Db::name('admin_set')->where('aid', $aid)->field('id,name,logo')->find()
            : Db::name('business')->where('aid', $aid)->where('id', $bid)->field('id,name,logo')->find();

        $order['binfo'] = $binfo ?: ['id' => 0, 'name' => '', 'logo' => ''];

        $logs = Db::name('restaurant_deposit_order_log')
            ->where('aid', $aid)->where('order_id', $id)->where('mid', $mid)
            ->order('id', 'desc')->select()->toArray();

        $order['log'] = array_map(static fn (array $l) => [
            'id'         => (int) $l['id'],
            'type'       => (int) $l['type'],
            'type_text'  => 0 === (int) $l['type'] ? '存入' : '取出',
            'num'        => (int) $l['num'],
            'remark'     => (string) $l['remark'],
            'createtime' => (int) $l['createtime'],
        ], $logs);

        return $this->success(['data' => $order]);
    }

    /**
     * 添加存酒申请（校验 sysset.status；status=0 待审核）
     * POST /api/restaurantDeposit/add {bid?,name,num,linkman,tel,message?,pic?}
     */
    public function add()
    {
        [$aid, $mid] = $this->memberContext();

        $bid = (int) $this->request->post('bid', 0);

        $sysset = $this->depositService->syssetRow($aid, $bid);

        if (1 !== (int) $sysset['status']) {
            throw new BusinessException('当前不能寄存');
        }

        $orderId = $this->depositService->createOrder($aid, $mid, [
            'bid'     => $bid,
            'name'    => $this->request->post('name', ''),
            'num'     => $this->request->post('num', 0),
            'linkman' => $this->request->post('linkman', ''),
            'tel'     => $this->request->post('tel', ''),
            'message' => $this->request->post('message', ''),
            'pic'     => $this->request->post('pic', ''),
        ], (string) $this->request->header('platform', 'wx') ?: 'wx');

        return $this->success(['id' => $orderId], '提交成功,请等待审核');
    }

    /**
     * 取酒：指定订单按数量部分取/全部取（幂等防超取）
     * POST /api/restaurantDeposit/takeout {orderid, numbers, bid?}
     */
    public function takeout()
    {
        [$aid, $mid] = $this->memberContext();

        $orderId = (int) $this->request->post('orderid', 0);
        $numbers = (int) $this->request->post('numbers', 0);

        if ($orderId <= 0) {
            throw new BusinessException('请选择要取出的存酒记录');
        }

        $result = $this->depositService->takeout(
            $aid,
            $orderId,
            $numbers,
            (string) $this->request->header('platform', 'wx') ?: 'wx',
            0,
            $mid
        );

        return $this->success($result, 0 === $result['left'] ? '已全部取出' : '操作成功');
    }

    /**
     * 订单行格式化
     */
    protected function formatOrder(array $o): array
    {
        $status = (int) ($o['status'] ?? 0);

        return [
            'id'            => (int) $o['id'],
            'bid'           => (int) ($o['bid'] ?? 0),
            'mid'           => (int) ($o['mid'] ?? 0),
            'name'          => (string) ($o['name'] ?? ''),
            'num'           => (int) ($o['num'] ?? 0),
            'linkman'       => (string) ($o['linkman'] ?? ''),
            'tel'           => (string) ($o['tel'] ?? ''),
            'pic'           => (string) ($o['pic'] ?? ''),
            'message'       => (string) ($o['message'] ?? ''),
            'position_name' => (string) ($o['position_name'] ?? ''),
            'number_name'   => (string) ($o['number_name'] ?? ''),
            'status'        => $status,
            'status_text'   => $this->depositService->statusLabel($status),
            'createtime'    => (int) ($o['createtime'] ?? 0),
            'takeout_time'  => (int) ($o['takeout_time'] ?? 0),
        ];
    }
}
