<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\EwaybillService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 电子面单记录（对标参考系统 Ewaybill：快递鸟电子面单）
 *
 * 面单列表/详情/创建面单（含前置数据）/取消/删除/打印模板
 * 快递鸟全链路依赖第三方 API，EWAYBILL.MOCK=1 时返回固定面单号 + 占位打印模板
 */
class Ewaybill extends BaseController
{
    public function __construct(\think\App $app, protected EwaybillService $ewaybillService)
    {
        parent::__construct($app);
    }

    /**
     * 面单列表
     * GET /admin/ewaybill/list?page&pagesize&ordernum&ordertype&name&status
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        $filters = [
            'ordernum'  => (string) $this->request->get('ordernum', ''),
            'ordertype' => (string) $this->request->get('ordertype', ''),
            'name'      => (string) $this->request->get('name', ''),
            'status'    => (string) $this->request->get('status', ''),
        ];

        return $this->success($this->ewaybillService->list($aid, $filters, $page, $pageSize));
    }

    /**
     * 面单详情
     * GET /admin/ewaybill/detail?id=
     */
    public function detail()
    {
        $aid = ScopeService::aidOf($this->request);
        $id  = (int) $this->request->get('id', 0);

        return $this->success($this->ewaybillService->detail($aid, $id));
    }

    /**
     * 创建面单前置数据（面单订单号 + 已绑定快递公司选项 + 温层 + 上次发件人）
     * GET /admin/ewaybill/prepare?ordertype=&orderid=
     */
    public function prepare()
    {
        $aid = ScopeService::aidOf($this->request);

        $ordertype = (string) $this->request->get('ordertype', '');
        $orderid   = (int) $this->request->get('orderid', 0);

        return $this->success($this->ewaybillService->prepare($aid, $ordertype, $orderid));
    }

    /**
     * 创建电子面单（发货）
     * POST /admin/ewaybill/add {delivery_info, ordercode, ordertype, orderid, 收发件人..., quantity, weight, volume, ...}
     */
    public function add()
    {
        $aid = ScopeService::aidOf($this->request);
        $uid = (int) (($this->request->authPayload ?? [])['uid'] ?? 0);

        $data = $this->request->post();
        $data = is_array($data) ? $data : [];

        return $this->success($this->ewaybillService->addOrder($aid, $uid, $data), '发货成功');
    }

    /**
     * 取消电子面单
     * POST /admin/ewaybill/cancel {id}
     */
    public function cancel()
    {
        $aid = ScopeService::aidOf($this->request);
        $uid = (int) (($this->request->authPayload ?? [])['uid'] ?? 0);

        $id = (int) $this->request->post('id', 0);

        $this->ewaybillService->cancel($aid, $uid, $id);

        return $this->success([], '取消成功');
    }

    /**
     * 删除电子面单
     * POST /admin/ewaybill/del {ids}
     */
    public function del()
    {
        $aid = ScopeService::aidOf($this->request);
        $uid = (int) (($this->request->authPayload ?? [])['uid'] ?? 0);

        $ids = $this->request->post('ids', []);
        $ids = is_array($ids) ? $ids : [];

        $this->ewaybillService->delete($aid, $uid, $ids);

        return $this->success([], '删除成功');
    }

    /**
     * 打印面单（返回打印模板 HTML 数组）
     * POST /admin/ewaybill/print {ids}
     */
    public function printOrder()
    {
        $aid = ScopeService::aidOf($this->request);

        $ids = $this->request->post('ids', []);
        $ids = is_array($ids) ? $ids : [];

        return $this->success($this->ewaybillService->printOrders($aid, $ids));
    }

    /**
     * 导出面单记录（与列表同口径筛选，xlsx 附件下载；隐藏动作 ewaybill-excel）
     * GET /admin/ewaybill/excel?ordernum&ordertype&name&status
     */
    public function excel()
    {
        $aid = ScopeService::aidOf($this->request);

        set_time_limit(0);
        ini_set('memory_limit', '1024M');

        $filters = [
            'ordernum'  => (string) $this->request->get('ordernum', ''),
            'ordertype' => (string) $this->request->get('ordertype', ''),
            'name'      => (string) $this->request->get('name', ''),
            'status'    => (string) $this->request->get('status', ''),
        ];

        [$titles, $rows] = $this->ewaybillService->exportData($aid, $filters);

        \util\ExcelUtil::export($titles, $rows, '电子面单记录_' . date('YmdHis'));
    }

    /**
     * 通知取件候选快递公司（isYuyue=1 的已出单面单去重）
     * GET /admin/ewaybill/noticeInit
     */
    public function noticeInit()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success(['list' => $this->ewaybillService->noticeExpressList($aid)]);
    }

    /**
     * 预约（通知）取件
     * POST /admin/ewaybill/yuyueNotice {codes: []}
     */
    public function yuyueNotice()
    {
        $aid = ScopeService::aidOf($this->request);
        $uid = (int) (($this->request->authPayload ?? [])['uid'] ?? 0);

        $codes = $this->request->post('codes', []);
        $codes = is_array($codes) ? $codes : [];

        $msg = $this->ewaybillService->yuyueNotice($aid, $uid, $codes);

        return $this->success([], $msg);
    }
}
