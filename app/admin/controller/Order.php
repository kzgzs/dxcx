<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\OrderService;
use app\common\service\ScopeService;
use util\ExcelUtil;

/**
 * 租户后台 - 订单管理（admin 应用，JWT role=admin，aid 走 ScopeService）
 */
class Order extends BaseController
{
    public function __construct(\think\App $app, protected OrderService $orderService)
    {
        parent::__construct($app);
    }

    /**
     * 订单列表（status 空=全部；keyword 模糊匹配订单号/收件人/手机号）
     * GET /admin/order/list?page&pagesize&status&keyword
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        $status   = trim((string) $this->request->get('status', ''));
        $keyword  = trim((string) $this->request->get('keyword', ''));
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;
        $bid      = trim((string) $this->request->get('bid', ''));

        $filter = [
            'keyword_type' => (int) $this->request->get('keyword_type', 1),
            'time_type'    => (int) $this->request->get('time_type', 1),
            'start'        => trim((string) $this->request->get('start', '')),
            'end'          => trim((string) $this->request->get('end', '')),
            'freight_id'   => (int) $this->request->get('freight_id', 0),
            'price_min'    => trim((string) $this->request->get('price_min', '')),
            'price_max'    => trim((string) $this->request->get('price_max', '')),
            'sort_field'   => trim((string) $this->request->get('sort_field', 'id')),
            'sort_order'   => trim((string) $this->request->get('sort_order', 'desc')),
        ];

        return $this->success($this->orderService->adminList($aid, $status, $keyword, $page, $pageSize, $bid, $filter));
    }

    /**
     * 配送方式选项（订单筛选下拉）
     * GET /admin/order/freightOptions
     */
    public function freightOptions()
    {
        $aid = ScopeService::aidOf($this->request);

        $list = ScopeService::query('freight', $aid)
            ->field('id, name')
            ->order('sort asc, id asc')
            ->select()
            ->toArray();

        return $this->success($list);
    }

    /**
     * 后台改价（仅未支付订单）
     * POST /admin/order/changePrice {id, price}
     */
    /**
     * 商户下拉选项（商户订单视图筛选，对照参考系统 ShopOrder/index showtype=2 的 business_list）
     * GET /admin/order/businessOptions
     */
    public function businessOptions()
    {
        $aid = ScopeService::aidOf($this->request);

        $list = ScopeService::query('business', $aid)
            ->field('id, name')
            ->order('id asc')
            ->select()
            ->toArray();

        return $this->success($list);
    }

    public function changePrice()
    {
        $aid   = ScopeService::aidOf($this->request);
        $id    = (int) $this->request->post('id', 0);
        $price = (float) $this->request->post('price', 0);

        if ($id <= 0) {
            throw new BusinessException('缺少订单ID');
        }

        $this->orderService->adminChangePrice($aid, $id, $price);

        return $this->success([], '改价成功');
    }

    /**
     * 后台关闭订单（仅未支付，回补库存/券/积分）
     * POST /admin/order/close {id}
     */
    public function close()
    {
        $aid = ScopeService::aidOf($this->request);
        $id  = (int) $this->request->post('id', 0);

        if ($id <= 0) {
            throw new BusinessException('缺少订单ID');
        }

        $this->orderService->adminClose($aid, $id);

        return $this->success([], '订单已关闭');
    }

    /**
     * 订单详情（全量：商品快照、地址快照、支付/物流信息）
     * GET /admin/order/detail?id=
     */
    public function detail()
    {
        $aid = ScopeService::aidOf($this->request);
        $id  = (int) $this->request->get('id', 0);

        if ($id <= 0) {
            throw new BusinessException('缺少订单ID');
        }

        return $this->success($this->orderService->adminDetail($aid, $id));
    }

    /**
     * 发货（仅状态1 已支付可发货）
     * POST /admin/order/ship {id,ship_company,ship_sn}
     */
    public function ship()
    {
        $aid = ScopeService::aidOf($this->request);

        $id          = (int) $this->request->post('id', 0);
        $shipCompany = trim((string) $this->request->post('ship_company', ''));
        $shipSn      = trim((string) $this->request->post('ship_sn', ''));

        if ($id <= 0) {
            throw new BusinessException('缺少订单ID');
        }

        $this->orderService->ship($aid, $id, $shipCompany, $shipSn);

        return $this->success([], '发货成功');
    }

    /**
     * 订单状态统计（订单页 tab 计数）
     * GET /admin/order/stat
     */
    public function stat()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->orderService->stat($aid));
    }

    /**
     * 批量发货（Excel 导入：第一列订单号、第二列运单号，统一快递公司）
     * POST /admin/order/batchShip（multipart：file + express_com）
     */
    public function batchShip()
    {
        $aid           = ScopeService::aidOf($this->request);
        $expressCompany = trim((string) $this->request->post('express_com', ''));

        $file = $this->request->file('file');

        if (empty($file)) {
            throw new BusinessException('请上传发货表格文件');
        }

        $extension = strtolower($file->getOriginalExtension());

        if (!in_array($extension, ['xlsx', 'xls', 'csv'], true)) {
            throw new BusinessException('仅支持 xlsx/xls/csv 格式的表格文件');
        }

        if ($file->getSize() > 10 * 1024 * 1024) {
            throw new BusinessException('表格文件不能超过10MB');
        }

        // 落临时目录后解析
        $dir      = app()->getRuntimePath() . 'import';
        $saveName = date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . $extension;

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $file->move($dir, $saveName);
        $filePath = $dir . DIRECTORY_SEPARATOR . $saveName;

        try {
            $excelRows = ExcelUtil::import($filePath, 1);
        } catch (\Throwable $e) {
            throw new BusinessException('表格解析失败：' . $e->getMessage());
        } finally {
            if (is_file($filePath)) {
                @unlink($filePath);
            }
        }

        // 首行若为表头（订单号/运单号字样）自动跳过
        if (!empty($excelRows)) {
            $firstCell = trim((string) ($excelRows[0][0] ?? ''));

            if (str_contains($firstCell, '订单号') || str_contains($firstCell, '单号')) {
                array_shift($excelRows);
            }
        }

        if (empty($excelRows)) {
            throw new BusinessException('表格中没有有效数据');
        }

        $result = $this->orderService->batchShip($aid, $expressCompany, $excelRows);

        return $this->success(
            $result,
            sprintf('共导入 %d 条，成功发货 %d 条，失败 %d 条', $result['total'], $result['success'], $result['fail'])
        );
    }

    /**
     * 选中项批量发货（同一快递+运单号发多单）
     * POST /admin/order/batchShipSelected {ids, ship_company, ship_sn}
     */
    public function batchShipSelected()
    {
        $aid           = ScopeService::aidOf($this->request);
        $ids           = (array) $this->request->post('ids', []);
        $shipCompany   = trim((string) $this->request->post('ship_company', ''));
        $shipSn        = trim((string) $this->request->post('ship_sn', ''));

        if (empty($ids)) {
            throw new BusinessException('请先选择订单');
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        $result = $this->orderService->batchShipByIds($aid, $ids, $shipCompany, $shipSn);

        return $this->success(
            $result,
            sprintf('共 %d 单，成功发货 %d 单，失败 %d 单', $result['total'], $result['success'], $result['fail'])
        );
    }

    /**
     * 批量删除（先回滚库存/券/积分，再级联删除 order/goods/refund/invoice）
     * POST /admin/order/batchDelete {ids}
     */
    public function batchDelete()
    {
        $aid = ScopeService::aidOf($this->request);
        $ids = (array) $this->request->post('ids', []);

        if (empty($ids)) {
            throw new BusinessException('请先选择订单');
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        $result = $this->orderService->batchDelete($aid, $ids);

        return $this->success(
            $result,
            sprintf('共 %d 单，删除成功 %d 单，失败 %d 单', $result['total'], $result['success'], $result['fail'])
        );
    }

    /**
     * 设置商家备注
     * POST /admin/order/remark {id, remark}
     */
    public function setRemark()
    {
        $aid    = ScopeService::aidOf($this->request);
        $id     = (int) $this->request->post('id', 0);
        $remark = trim((string) $this->request->post('remark', ''));

        if ($id <= 0) {
            throw new BusinessException('缺少订单ID');
        }

        $this->orderService->setRemark($aid, $id, $remark);

        return $this->success([], '备注保存成功');
    }

    /**
     * 订单导出（全筛选条件或选中 ids；PhpSpreadsheet xlsx 附件下载）
     * GET /admin/order/export?status&keyword&ids=1,2,3
     */
    public function export()
    {
        $aid = ScopeService::aidOf($this->request);

        set_time_limit(0);
        ini_set('memory_limit', '1024M');

        $status  = trim((string) $this->request->get('status', ''));
        $keyword = trim((string) $this->request->get('keyword', ''));
        $ids     = trim((string) $this->request->get('ids', ''));
        $bid     = trim((string) $this->request->get('bid', ''));

        $idArr = [];

        if ('' !== $ids) {
            $idArr = array_values(array_filter(array_map('intval', explode(',', $ids))));
        }

        [$titles, $rows] = $this->orderService->exportData($aid, $status, $keyword, $idArr, 20000, $bid);

        ExcelUtil::export($titles, $rows, '订单导出_' . date('YmdHis'));
    }
}
