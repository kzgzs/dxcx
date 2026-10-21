<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\CycleService;
use app\common\service\ScopeService;
use think\Response;

/**
 * 租户后台 - 周期购【片B：订单管理 + 配送管理】
 *
 * 对齐 canzhao CycleOrder：分期配送三视图（待发货/已发货/全部订单）、
 * 订单行操作「周期计划」平铺页、发货、送货单（可打印）、查物流/配送信息、
 * 到店核销、删除订单、整单完成。
 *
 * 已在 Cycle.php 中实现且不重复：orderList / orderDetail / orderShip /
 * orderStageCollect / orderRefundCheck / orderRemark。本控制器只补缺。
 */
class CycleOrder extends BaseController
{
    public function __construct(\think\App $app, protected CycleService $cycleService)
    {
        parent::__construct($app);
    }

    /**
     * 分期配送列表（配送管理三视图 + 订单管理「周期计划」平铺页共用）
     * GET /admin/cycle/order/stageList?page&pagesize&status&orderid&ordernum&linkman&tel&start_date&end_date
     */
    public function stageList()
    {
        $aid = ScopeService::aidOf($this->request);

        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        $filters = [
            'status'     => (string) $this->request->get('status', ''),
            'orderid'    => (int) $this->request->get('orderid', 0),
            'ordernum'   => trim((string) $this->request->get('ordernum', '')),
            'linkman'    => trim((string) $this->request->get('linkman', '')),
            'tel'        => trim((string) $this->request->get('tel', '')),
            'start_date' => trim((string) $this->request->get('start_date', '')),
            'end_date'   => trim((string) $this->request->get('end_date', '')),
        ];

        return $this->success($this->cycleService->stageDeliveryList($aid, $page, $pageSize, $filters));
    }

    /**
     * 核销（到店自提/到店核销：当期 → 已完成）
     * POST /admin/cycle/order/hexiao {stageid}
     */
    public function hexiao()
    {
        $aid     = ScopeService::aidOf($this->request);
        $stageId = (int) $this->request->post('stageid', 0);

        return $this->success($this->cycleService->orderHexiao($aid, $stageId), '核销成功');
    }

    /**
     * 送货单（按 stage 生成，前端平铺打印）
     * GET /admin/cycle/order/shd?stageid=
     */
    public function shd()
    {
        $aid     = ScopeService::aidOf($this->request);
        $stageId = (int) $this->request->get('stageid', 0);

        return $this->success($this->cycleService->deliveryNote($aid, $stageId));
    }

    /**
     * 查物流 / 配送信息
     * GET /admin/cycle/order/express?stageid=
     */
    public function express()
    {
        $aid     = ScopeService::aidOf($this->request);
        $stageId = (int) $this->request->get('stageid', 0);

        return $this->success($this->cycleService->getExpress($aid, $stageId));
    }

    /**
     * 删除订单（软删）
     * POST /admin/cycle/order/delete {id}
     */
    public function delete()
    {
        $aid = ScopeService::aidOf($this->request);
        $id  = (int) $this->request->post('id', 0);

        $this->cycleService->orderDelete($aid, $id);

        return $this->success([], '删除成功');
    }

    /**
     * 完成（整单收口）
     * POST /admin/cycle/order/finish {id}
     */
    public function finish()
    {
        $aid = ScopeService::aidOf($this->request);
        $id  = (int) $this->request->post('id', 0);

        return $this->success($this->cycleService->orderFinish($aid, $id), '订单已完成');
    }

    /**
     * 导出订单 CSV（按当前列表筛选，UTF-8 BOM，Excel 直开不乱码）
     * GET /admin/cycle/order/export?ordernum&linkman&tel&status&start_date&end_date
     */
    public function export()
    {
        $aid = ScopeService::aidOf($this->request);

        $filters = [
            'status'     => (string) $this->request->get('status', ''),
            'ordernum'   => trim((string) $this->request->get('ordernum', '')),
            'linkman'    => trim((string) $this->request->get('linkman', '')),
            'tel'        => trim((string) $this->request->get('tel', '')),
            'start_date' => trim((string) $this->request->get('start_date', '')),
            'end_date'   => trim((string) $this->request->get('end_date', '')),
        ];

        $rows = $this->cycleService->exportOrders($aid, $filters);

        $statusMap = [0 => '未支付', 1 => '已支付', 2 => '已发货', 3 => '已收货', 4 => '已关闭'];

        $fp = fopen('php://temp', 'r+');
        fwrite($fp, "\xEF\xBB\xBF"); // BOM：Excel 中文不乱码
        fputcsv($fp, ['订单号', '商品', '规格', '数量', '商品总价', '实付款', '客户', '电话', '收货地址', '配送方式', '支付方式', '状态', '下单时间']);

        foreach ($rows as $row) {
            fputcsv($fp, [
                (string) $row['ordernum'],
                (string) $row['proname'],
                (string) $row['ggname'],
                (int) $row['num'],
                number_format((float) $row['product_price'], 2),
                number_format((float) $row['totalprice'], 2),
                (string) $row['linkman'],
                (string) $row['tel'],
                trim((string) $row['area'] . ' ' . (string) $row['address']),
                (string) ($row['freight_text'] ?? ''),
                (string) ($row['paytype'] ?? ''),
                $statusMap[(int) $row['status']] ?? '未知',
                !empty($row['createtime']) ? date('Y-m-d H:i:s', (int) $row['createtime']) : '',
            ]);
        }

        rewind($fp);
        $csv = stream_get_contents($fp);
        fclose($fp);

        return Response::create($csv, 'html', 200)
            ->header([
                'Content-Type'        => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="cycle_order_' . date('Ymd_His') . '.csv"',
            ]);
    }
}
