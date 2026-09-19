<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\RefundService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 退款管理（admin 应用，JWT role=admin，aid 走 ScopeService）
 */
class Refund extends BaseController
{
    public function __construct(\think\App $app, protected RefundService $refundService)
    {
        parent::__construct($app);
    }

    /**
     * 退款单列表（status 空=全部；keyword 匹配订单号/收件人）
     * GET /admin/refund/list?page&pagesize&status&keyword
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        $status   = trim((string) $this->request->get('status', ''));
        $keyword  = trim((string) $this->request->get('keyword', ''));
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;
        $filter = [
            'refund_sn'  => trim((string) $this->request->get('refund_sn', '')),
            'express_no' => trim((string) $this->request->get('express_no', '')),
            'ordernum'   => trim((string) $this->request->get('ordernum', '')),
            'tel'        => trim((string) $this->request->get('tel', '')),
            'start'      => trim((string) $this->request->get('start', '')),
            'end'        => trim((string) $this->request->get('end', '')),
            'sort_field' => trim((string) $this->request->get('sort_field', 'id')),
            'sort_order' => trim((string) $this->request->get('sort_order', 'desc')),
        ];

        return $this->success($this->refundService->adminList($aid, $status, $keyword, $page, $pageSize, $filter));
    }

    /**
     * 退款单详情（含订单商品快照与地址快照）
     * GET /admin/refund/detail?id=
     */
    public function detail()
    {
        $aid = ScopeService::aidOf($this->request);
        $id  = (int) $this->request->get('id', 0);

        if ($id <= 0) {
            throw new BusinessException('缺少退款单ID');
        }

        return $this->success($this->refundService->adminDetail($aid, $id));
    }

    /**
     * 审核退款单
     * POST /admin/refund/audit {id, pass(1通过/2驳回), remark}
     * 通过：PayService 退款（mock 即时成功）→ 未发货回补库存 → 订单置4关闭
     */
    public function audit()
    {
        $aid = ScopeService::aidOf($this->request);

        $id     = (int) $this->request->post('id', 0);
        $pass   = (int) $this->request->post('pass', 0);
        $remark = trim((string) $this->request->post('remark', ''));

        if ($id <= 0) {
            throw new BusinessException('缺少退款单ID');
        }

        $this->refundService->audit($aid, $id, $pass, $remark);

        return $this->success([], 1 === $pass ? '退款成功' : '已驳回');
    }

    /**
     * 退款单统计
     * GET /admin/refund/stat → {pending, done, rejected}
     */
    public function stat()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->refundService->stat($aid));
    }

    /**
     * 删除退款单（软删）
     * POST /admin/refund/del {id}
     */
    public function del()
    {
        $aid = ScopeService::aidOf($this->request);
        $id  = (int) $this->request->post('id', 0);

        if ($id <= 0) {
            throw new BusinessException('缺少退款单ID');
        }

        $this->refundService->adminDelete($aid, $id);

        return $this->success([], '删除成功');
    }
}
