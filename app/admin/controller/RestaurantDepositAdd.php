<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\DepositService;
use app\common\service\ScopeService;
use think\facade\Db;

/**
 * 租户后台 - 餐饮添加寄存（批次6-T6-3 任务204）
 *
 * B端录入：直接 status=1 寄存中 + 存入流水（对标 canzhao RestaurantDeposit/edit）
 * 位置/编号字典下拉由 positionOptions 提供（自研位置管理）
 */
class RestaurantDepositAdd extends BaseController
{
    public function __construct(\think\App $app, protected DepositService $depositService)
    {
        parent::__construct($app);
    }

    /**
     * 添加表单数据：位置/编号两级字典 + 寄存设置
     * GET /admin/restaurant/deposit/form
     */
    public function form()
    {
        $aid = ScopeService::aidOf($this->request);

        $positions = Db::name('restaurant_deposit_position')
            ->where('aid', $aid)->where('bid', 0)->where('status', 1)
            ->order('sort', 'desc')->order('id', 'asc')->select()->toArray();

        $positionIds = array_map(static fn (array $p) => (int) $p['id'], $positions);
        $numberMap   = [];

        if ($positionIds) {
            $numbers = Db::name('restaurant_deposit_position_number')
                ->where('aid', $aid)->whereIn('position_id', $positionIds)->where('status', 1)
                ->order('sort', 'desc')->order('id', 'asc')->select()->toArray();

            foreach ($numbers as $n) {
                $numberMap[(int) $n['position_id']][] = [
                    'id'   => (int) $n['id'],
                    'name' => (string) $n['name'],
                ];
            }
        }

        $list = array_map(static fn (array $p) => [
            'id'      => (int) $p['id'],
            'name'    => (string) $p['name'],
            'numbers' => $numberMap[(int) $p['id']] ?? [],
        ], $positions);

        $sysset = $this->depositService->syssetRow($aid);

        return $this->success([
            'positions' => $list,
            'sysset'    => $sysset,
        ]);
    }

    /**
     * B端录入寄存（status=1 + 存入流水）
     * POST /admin/restaurant/deposit/add {name,num,linkman,tel,pic?,message?,mid?,position_id?,number_id?,tableid?}
     */
    public function save()
    {
        $aid        = ScopeService::aidOf($this->request);
        $positionId = (int) $this->request->post('position_id', 0);
        $numberId   = (int) $this->request->post('number_id', 0);

        $data = [
            'bid'         => 0,
            'mid'         => (int) $this->request->post('mid', 0),
            'name'        => $this->request->post('name', ''),
            'num'         => (int) $this->request->post('num', 0),
            'linkman'     => $this->request->post('linkman', ''),
            'tel'         => $this->request->post('tel', ''),
            'pic'         => $this->request->post('pic', ''),
            'message'     => $this->request->post('message', ''),
            'tableid'     => (int) $this->request->post('tableid', 0),
            'position_id' => $positionId,
            'number_id'   => $numberId,
        ];

        // 位置/编号名称反查固化（订单冗余存名称，字典删除不影响历史单）
        $resolved = $this->depositService->resolvePosition($aid, $positionId, $numberId);
        $data += $resolved;

        $orderId = $this->depositService->adminAdd($aid, $data, (int) $this->request->authUid);

        return $this->success(['id' => $orderId], '添加成功');
    }
}
