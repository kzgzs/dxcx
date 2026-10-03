<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\MiandanService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 物流助手（对标参考系统 Miandan：微信物流助手运单管理）
 *
 * 运单列表/查询运单（打印数据）/查询轨迹/取消/删除/物流账号列表/物流助手设置
 * 微信物流助手全链路依赖第三方 API，EWAYBILL.MOCK=1 时全部走 mock
 */
class Miandan extends BaseController
{
    public function __construct(\think\App $app, protected MiandanService $miandanService)
    {
        parent::__construct($app);
    }

    /**
     * 运单列表
     * GET /admin/miandan/list?page&pagesize&order_id&waybill_id&name&tel&status&starttime&endtime
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        $filters = [
            'order_id'   => (string) $this->request->get('order_id', ''),
            'waybill_id' => (string) $this->request->get('waybill_id', ''),
            'name'       => (string) $this->request->get('name', ''),
            'tel'        => (string) $this->request->get('tel', ''),
            'status'     => (string) $this->request->get('status', ''),
            'starttime'  => (string) $this->request->get('starttime', ''),
            'endtime'    => (string) $this->request->get('endtime', ''),
            'sort_field' => (string) $this->request->get('sort_field', ''),
            'sort_order' => (string) $this->request->get('sort_order', ''),
        ];

        return $this->success($this->miandanService->list($aid, $filters, $page, $pageSize));
    }

    /**
     * 查询运单（打印数据）
     * POST /admin/miandan/getOrder {order_id, delivery_id, waybill_id}
     */
    public function getOrder()
    {
        $aid = ScopeService::aidOf($this->request);

        $orderId   = trim((string) $this->request->post('order_id', ''));
        $deliveryId = trim((string) $this->request->post('delivery_id', ''));
        $waybillId = trim((string) $this->request->post('waybill_id', ''));

        return $this->success($this->miandanService->getOrder($aid, $orderId, $deliveryId, $waybillId));
    }

    /**
     * 查询运单轨迹
     * POST /admin/miandan/getPath {order_id, delivery_id, waybill_id}
     */
    public function getPath()
    {
        $aid = ScopeService::aidOf($this->request);

        $orderId   = trim((string) $this->request->post('order_id', ''));
        $deliveryId = trim((string) $this->request->post('delivery_id', ''));
        $waybillId = trim((string) $this->request->post('waybill_id', ''));

        return $this->success($this->miandanService->getPath($aid, $orderId, $deliveryId, $waybillId));
    }

    /**
     * 取消运单
     * POST /admin/miandan/cancel {order_id, delivery_id, waybill_id}
     */
    public function cancel()
    {
        $aid = ScopeService::aidOf($this->request);
        $uid = (int) (($this->request->authPayload ?? [])['uid'] ?? 0);

        $orderId   = trim((string) $this->request->post('order_id', ''));
        $deliveryId = trim((string) $this->request->post('delivery_id', ''));
        $waybillId = trim((string) $this->request->post('waybill_id', ''));

        $this->miandanService->cancel($aid, $uid, $orderId, $deliveryId, $waybillId);

        return $this->success([], '取消成功');
    }

    /**
     * 删除运单记录
     * POST /admin/miandan/del {ids}
     */
    public function del()
    {
        $aid = ScopeService::aidOf($this->request);
        $uid = (int) (($this->request->authPayload ?? [])['uid'] ?? 0);

        $ids = $this->request->post('ids', []);
        $ids = is_array($ids) ? $ids : [];

        $this->miandanService->delete($aid, $uid, $ids);

        return $this->success([], '删除成功');
    }

    /**
     * 物流公司下拉选项（绑定物流账号表单用）
     * GET /admin/miandan/deliveryOptions
     */
    public function deliveryOptions()
    {
        return $this->success($this->miandanService->deliveryOptions());
    }

    /**
     * 物流账号列表（落库回读）
     * GET /admin/miandan/accountList
     */
    public function accountList()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->miandanService->accountList($aid));
    }

    /**
     * 绑定物流账号（真实落库）
     * POST /admin/miandan/accountBind {delivery_id, biz_id, password, remark_content}
     */
    public function accountBind()
    {
        $aid = ScopeService::aidOf($this->request);
        $uid = (int) (($this->request->authPayload ?? [])['uid'] ?? 0);

        $info = $this->request->post();
        $info = is_array($info) ? $info : [];

        $this->miandanService->accountBind($aid, $uid, $info);

        return $this->success([], '绑定成功');
    }

    /**
     * 解绑物流账号（落库置已解绑）
     * POST /admin/miandan/accountUnbind {biz_id, delivery_id}
     */
    public function accountUnbind()
    {
        $aid = ScopeService::aidOf($this->request);
        $uid = (int) (($this->request->authPayload ?? [])['uid'] ?? 0);

        $bizId      = trim((string) $this->request->post('biz_id', ''));
        $deliveryId = trim((string) $this->request->post('delivery_id', ''));

        $this->miandanService->accountUnbind($aid, $uid, $bizId, $deliveryId);

        return $this->success([], '解绑成功');
    }

    /**
     * 打印员列表（落库回读）
     * GET /admin/miandan/printerList
     */
    public function printerList()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->miandanService->printerList($aid));
    }

    /**
     * 添加打印员（会员ID或openid，真实落库）
     * POST /admin/miandan/printerAdd {openid, nickname}
     */
    public function printerAdd()
    {
        $aid = ScopeService::aidOf($this->request);
        $uid = (int) (($this->request->authPayload ?? [])['uid'] ?? 0);

        $openid   = trim((string) $this->request->post('openid', ''));
        $nickname = trim((string) $this->request->post('nickname', ''));

        $this->miandanService->printerAdd($aid, $uid, $openid, $nickname);

        return $this->success([], '添加成功');
    }

    /**
     * 解绑打印员
     * POST /admin/miandan/printerDel {openid}
     */
    public function printerDel()
    {
        $aid = ScopeService::aidOf($this->request);
        $uid = (int) (($this->request->authPayload ?? [])['uid'] ?? 0);

        $openid = trim((string) $this->request->post('openid', ''));

        $this->miandanService->printerDel($aid, $uid, $openid);

        return $this->success([], '解绑成功');
    }

    /**
     * 物流助手设置读取
     * GET /admin/miandan/set
     */
    public function set()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->miandanService->setOf($aid));
    }

    /**
     * 物流助手设置保存
     * POST /admin/miandan/set {status, query_status, print_type}
     */
    public function setSave()
    {
        $aid = ScopeService::aidOf($this->request);
        $uid = (int) (($this->request->authPayload ?? [])['uid'] ?? 0);

        $data = $this->request->post();
        $data = is_array($data) ? $data : [];

        $this->miandanService->saveSet($aid, $uid, $data);

        return $this->success([], '操作成功');
    }
}
