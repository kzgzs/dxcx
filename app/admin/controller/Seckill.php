<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\ScopeService;
use app\common\service\ExcelHelper;
use app\common\service\SeckillService;

/**
 * 租户后台 - 整点秒杀（admin 应用，JWT role=admin，aid 走 ScopeService）
 *
 * 覆盖：秒杀设置 / 商品报名 / 场次明细 / 订单查询与手动关单
 * 超时自动关单挂 app\command\ScheduleRun（SeckillService::closeExpired）
 */
class Seckill extends BaseController
{
    public function __construct(\think\App $app, protected SeckillService $seckillService)
    {
        parent::__construct($app);
    }

    /**
     * 秒杀设置读取
     * GET /admin/seckill/set
     */
    public function set()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->seckillService->getSysset($aid));
    }

    /**
     * 秒杀设置保存（场次时间点/时长/自动关单分钟/自动收货天数）
     * POST /admin/seckill/set {timeset, duration, status, autoclose, autoshdays, ...}
     */
    public function saveSet()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->seckillService->saveSysset($aid, $this->request->post());

        return $this->success([], '保存成功');
    }

    /**
     * 秒杀商品列表（附规格行）
     * GET /admin/seckill/product/list?page&pagesize&keyword&status
     */
    public function productList()
    {
        $aid = ScopeService::aidOf($this->request);

        $page       = max(1, (int) $this->request->get('page', 1));
        $pageSize   = (int) $this->request->get('pagesize', 10);
        $pageSize   = $pageSize > 0 ? min($pageSize, 100) : 10;
        $keyword    = trim((string) $this->request->get('keyword', ''));
        $status     = trim((string) $this->request->get('status', ''));
        $bid        = trim((string) $this->request->get('bid', ''));
        $checked    = trim((string) $this->request->get('checked', ''));
        $seckillDate  = trim((string) $this->request->get('seckill_date', ''));
        $seckillTime  = trim((string) $this->request->get('seckill_time', ''));
        $orderField = trim((string) $this->request->get('orderField', ''));
        $orderType  = trim((string) $this->request->get('orderType', ''));

        return $this->success($this->seckillService->productList($aid, $page, $pageSize, $keyword, $status, $bid, $checked, $seckillDate, $seckillTime, $orderField, $orderType));
    }

    /**
     * 秒杀商品报名/编辑（选商城商品带入基础信息，重复报名拦截）
     * POST /admin/seckill/product/save {id?, goods_id?, name?, sell_price, buymax, perlimit, ...}
     */
    public function productSave()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->seckillService->productSave($aid, $this->request->post()), '保存成功');
    }

    /**
     * 商品详情（含规格行）
     * GET /admin/seckill/product/detail?id=
     */
    public function productDetail()
    {
        $aid = ScopeService::aidOf($this->request);

        $id = (int) $this->request->get('id', 0);

        return $this->success($this->seckillService->productDetail($aid, $id));
    }

    /**
     * 商品审核（ischecked 1通过 2驳回）
     * POST /admin/seckill/product/check {id, ischecked, reason}
     */
    public function productCheck()
    {
        $aid = ScopeService::aidOf($this->request);

        $id        = (int) $this->request->post('id', 0);
        $ischecked = (int) $this->request->post('ischecked', 0);
        $reason    = trim((string) $this->request->post('reason', ''));

        $this->seckillService->productCheck($aid, $id, $ischecked, $reason);

        return $this->success([], 1 === $ischecked ? '审核通过' : '已驳回');
    }

    /**
     * 复制秒杀商品
     * POST /admin/seckill/product/procopy {id}
     */
    public function procopy()
    {
        $aid = ScopeService::aidOf($this->request);

        $id = (int) $this->request->post('id', 0);

        return $this->success($this->seckillService->procopy($aid, $id), '复制成功');
    }

    /**
     * 秒杀商品删除（已有场次/订单时拒绝）
     * POST /admin/seckill/product/delete {id}
     */
    public function productDelete()
    {
        $aid = ScopeService::aidOf($this->request);

        $ids = (array) $this->request->post('ids', []);
        if (empty($ids)) {
            $ids = [(int) $this->request->post('id', 0)];
        }

        $this->seckillService->productDeleteByIds($aid, $ids);

        return $this->success([], '删除成功');
    }

    /**
     * 秒杀商品上下架
     * POST /admin/seckill/product/status {id,status}
     */
    public function productStatus()
    {
        $aid = ScopeService::aidOf($this->request);

        $ids = (array) $this->request->post('ids', []);
        if (empty($ids)) {
            $ids = [(int) $this->request->post('id', 0)];
        }
        $status = (int) $this->request->post('status', 0);

        $this->seckillService->productStatusByIds($aid, $ids, $status);

        return $this->success([], 1 === $status ? '已上架' : '已下架');
    }

    /**
     * 场次明细列表（按 商品+日期+场次 聚合）
     * GET /admin/seckill/prodata/list?page&pagesize&proid&date&time&keyword
     */
    public function prodataList()
    {
        $aid = ScopeService::aidOf($this->request);

        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        $filters = [
            'proid'   => (int) $this->request->get('proid', 0),
            'date'    => trim((string) $this->request->get('date', '')),
            'time'    => trim((string) $this->request->get('time', '')),
            'keyword' => trim((string) $this->request->get('keyword', '')),
        ];

        return $this->success($this->seckillService->prodataList($aid, $page, $pageSize, $filters));
    }

    /**
     * 生成场次（商品 × 日期 × 整点展开，重复场次拦截）
     * POST /admin/seckill/prodata/save {proid, dates[], times[], buymax, options:{ggid:{seckill_price, seckill_num}}}
     */
    public function prodataSave()
    {
        $aid = ScopeService::aidOf($this->request);

        $result = $this->seckillService->prodataSave($aid, $this->request->post());

        return $this->success($result, '已生成 ' . $result['count'] . ' 条场次记录');
    }

    /**
     * 删除场次（仅未开场且无销量的场次）
     * POST /admin/seckill/prodata/delete {ids[]}
     */
    public function prodataDelete()
    {
        $aid = ScopeService::aidOf($this->request);

        $ids = (array) $this->request->post('ids', []);

        $this->seckillService->prodataDelete($aid, $ids);

        return $this->success([], '删除成功');
    }

    /**
     * 秒杀订单列表（状态机 0未付/1已付/2已发货/3已收货/4关闭）
     * GET /admin/seckill/order/list?page&pagesize&status&ordernum&proname&linkman&tel&start_date&end_date
     */
    public function orderList()
    {
        $aid = ScopeService::aidOf($this->request);

        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        $filters = [
            'status'     => trim((string) $this->request->get('status', '')),
            'ordernum'   => trim((string) $this->request->get('ordernum', '')),
            'proname'    => trim((string) $this->request->get('proname', '')),
            'linkman'    => trim((string) $this->request->get('linkman', '')),
            'tel'        => trim((string) $this->request->get('tel', '')),
            'start_date' => trim((string) $this->request->get('start_date', '')),
            'end_date'   => trim((string) $this->request->get('end_date', '')),
        ];

        return $this->success($this->seckillService->orderList($aid, $page, $pageSize, $filters));
    }

    /**
     * 手动关单（仅未支付；同事务回补库存/销量并退券）
     * POST /admin/seckill/order/close {id}
     */
    public function orderClose()
    {
        $aid = ScopeService::aidOf($this->request);

        $id = (int) $this->request->post('id', 0);

        $this->seckillService->orderClose($aid, $id);

        return $this->success([], '订单已关闭');
    }
    /* ================= 订单扩展操作（对齐 canzhao SeckillOrder） ================= */

    public function orderDetail()
    {
        $aid = ScopeService::aidOf($this->request);
        return $this->success($this->seckillService->orderDetail($aid, (int) $this->request->get('id', 0)));
    }

    public function orderDelete()
    {
        $aid = ScopeService::aidOf($this->request);
        $ids = (array) $this->request->post('ids', []);
        if (empty($ids)) { $ids = [(int) $this->request->post('id', 0)]; }
        $this->seckillService->orderDelete($aid, $ids);
        return $this->success([], '删除成功');
    }

    public function orderIspay()
    {
        $aid = ScopeService::aidOf($this->request);
        $this->seckillService->orderIspay($aid, (int) $this->request->post('id', 0));
        return $this->success([], '操作成功');
    }

    public function orderHexiao()
    {
        $aid = ScopeService::aidOf($this->request);
        $this->seckillService->orderHexiao($aid, (int) $this->request->post('id', 0));
        return $this->success([], '核销成功');
    }

    /**
     * 手动发货 {id, express_com, express_no}
     */
    public function orderSendExpress()
    {
        $aid = ScopeService::aidOf($this->request);
        $this->seckillService->orderSendExpress(
            $aid,
            (int) $this->request->post('id', 0),
            (string) $this->request->post('express_com', ''),
            (string) $this->request->post('express_no', '')
        );
        return $this->success([], '发货成功');
    }

    /**
     * 快递公司下拉（手动发货）
     */
    public function orderExpressOptions()
    {
        return $this->success($this->seckillService->expressOptions());
    }

    /**
     * 查物流（快递鸟 1002 轨迹）
     * GET /admin/seckill/order/trace?id=
     */
    public function orderTrace()
    {
        $aid = ScopeService::aidOf($this->request);
        return $this->success($this->seckillService->orderTrace($aid, (int) $this->request->get('id', 0)));
    }

    /**
     * 送货单（整页打印数据）
     * GET /admin/seckill/order/deliveryNote?id=
     */
    public function orderDeliveryNote()
    {
        $aid = ScopeService::aidOf($this->request);
        return $this->success($this->seckillService->orderDeliveryNote($aid, (int) $this->request->get('id', 0)));
    }

    public function orderExport()
    {
        $aid = ScopeService::aidOf($this->request);
        $filters = [
            'status'     => trim((string) $this->request->get('status', '')),
            'ordernum'   => trim((string) $this->request->get('ordernum', '')),
            'proname'    => trim((string) $this->request->get('proname', '')),
            'linkman'    => trim((string) $this->request->get('linkman', '')),
            'tel'        => trim((string) $this->request->get('tel', '')),
        ];
        $rows = $this->seckillService->orderExportRows($aid, $filters);
        return ExcelHelper::download('秒杀订单_' . date('YmdHis'), [
            '订单号', '商品名称', '规格', '数量', '秒杀价', '实付款', '支付方式', '联系人', '电话', '收货地址', '备注', '下单时间', '状态',
        ], $rows);
    }

    /* ================= 秒杀评价（对齐 canzhao SeckillComment） ================= */

    public function commentList()
    {
        $aid = ScopeService::aidOf($this->request);
        $page = max(1, (int) $this->request->get('page', 1));
        $pagesize = (int) $this->request->get('pagesize', 10);
        $pagesize = $pagesize > 0 ? min($pagesize, 100) : 10;
        $filters = [
            'status'     => trim((string) $this->request->get('status', '')),
            'content'    => trim((string) $this->request->get('content', '')),
            'proid'      => (int) $this->request->get('proid', 0),
            'start_date' => trim((string) $this->request->get('start_date', '')),
            'end_date'   => trim((string) $this->request->get('end_date', '')),
        ];
        return $this->success($this->seckillService->commentList($aid, $page, $pagesize, $filters));
    }

    public function commentSetStatus()
    {
        $aid = ScopeService::aidOf($this->request);
        $ids = (array) $this->request->post('ids', []);
        if (empty($ids)) { $ids = [(int) $this->request->post('id', 0)]; }
        $this->seckillService->commentSetStatus($aid, $ids, (int) $this->request->post('status', 1));
        return $this->success([], '操作成功');
    }

    public function commentReply()
    {
        $aid = ScopeService::aidOf($this->request);
        $this->seckillService->commentReply($aid, (int) $this->request->post('id', 0), (string) $this->request->post('content', ''));
        return $this->success([], '回复成功');
    }

    public function commentDelete()
    {
        $aid = ScopeService::aidOf($this->request);
        $ids = (array) $this->request->post('ids', []);
        if (empty($ids)) { $ids = [(int) $this->request->post('id', 0)]; }
        $this->seckillService->commentDelete($aid, $ids);
        return $this->success([], '删除成功');
    }
}
