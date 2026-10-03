<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\CollageService;
use app\common\service\ScopeService;
use app\common\exception\BusinessException;
use util\ExcelUtil;

/**
 * 租户后台 - 拼团管理（admin 应用，JWT role=admin，aid 走 ScopeService）
 *
 * 覆盖：拼团设置 / 拼团商品 / 团管理 / 拼团订单
 * （超时解散团由 schedule:run 挂接 CollageService::dissolveExpiredTeams）
 */
class Collage extends BaseController
{
    public function __construct(\think\App $app, protected CollageService $collageService)
    {
        parent::__construct($app);
    }

    /* ================= 拼团设置 ================= */

    /**
     * 读取拼团设置
     * GET /admin/collage/set
     */
    public function setGet()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->collageService->sysset($aid));
    }

    /**
     * 保存拼团设置（teamnum/teamhour/leadermoney/autoshdays/comment_check/timeset/team_refund）
     * POST /admin/collage/set
     */
    public function setSave()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->collageService->saveSysset($aid, $this->request->post());

        return $this->success([], '保存成功');
    }

    /* ================= 拼团商品 ================= */

    /**
     * 商品列表（keyword/status/checked 过滤）
     * GET /admin/collage/product/list?page&pagesize&keyword&status&checked
     */
    public function productList()
    {
        $aid = ScopeService::aidOf($this->request);

        $keyword  = trim((string) $this->request->get('keyword', ''));
        $status   = trim((string) $this->request->get('status', ''));
        $checked  = trim((string) $this->request->get('checked', ''));
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;
        $bid      = trim((string) $this->request->get('bid', ''));
        $cid      = (int) $this->request->get('cid', 0);
        $orderField = trim((string) $this->request->get('orderField', ''));
        $orderType  = trim((string) $this->request->get('orderType', ''));

        return $this->success($this->collageService->productList($aid, $keyword, $status, $checked, $page, $pageSize, $bid, $cid, $orderField, $orderType));
    }

    /**
     * 商品详情（含规格行）
     * GET /admin/collage/product/detail?id=
     */
    public function productDetail()
    {
        $aid = ScopeService::aidOf($this->request);

        $id = (int) $this->request->get('id', 0);

        return $this->success($this->collageService->productDetail($aid, $id));
    }

    /**
     * 新增/编辑拼团商品（id>0 编辑；含规格行 options）
     * POST /admin/collage/product/save
     */
    public function productSave()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->collageService->productSave($aid, $this->request->post()), '保存成功');
    }

    /**
     * 商品上架/下架
     * POST /admin/collage/product/status {id,status}
     */
    public function productStatus()
    {
        $aid = ScopeService::aidOf($this->request);

        $id     = (int) $this->request->post('id', 0);
        $status = (int) $this->request->post('status', -1);

        $this->collageService->productBatchStatus($aid, $this->resolveProductIds(), $status);

        return $this->success([], 1 === $status ? '已上架' : '已下架');
    }

    /**
     * 商品审核（ischecked 1通过 2驳回）
     * POST /admin/collage/product/check {id,ischecked,reason}
     */
    public function productCheck()
    {
        $aid = ScopeService::aidOf($this->request);

        $id        = (int) $this->request->post('id', 0);
        $ischecked = (int) $this->request->post('ischecked', 0);
        $reason    = trim((string) $this->request->post('reason', ''));

        $this->collageService->productCheck($aid, $id, $ischecked, $reason);

        return $this->success([], 1 === $ischecked ? '审核通过' : '已驳回');
    }

    /**
     * 删除商品
     * POST /admin/collage/product/delete {id}
     */
    public function productDelete()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->collageService->productBatchDelete($aid, $this->resolveProductIds());

        return $this->success([], '删除成功');
    }

    /**
     * 归一化商品 ids 入参：兼容批量 ids[] 与单个 id
     *
     * @return int[]
     */
    private function resolveProductIds(): array
    {
        $ids = $this->request->post('ids');

        if (is_array($ids)) {
            return array_values(array_filter(array_map('intval', $ids)));
        }

        $id = (int) $this->request->post('id', 0);

        return $id > 0 ? [$id] : [];
    }

    /* ================= 团管理 ================= */

    /**
     * 团列表（status 空=全部 1进行中 2成功 3失败）
     * GET /admin/collage/team/list?page&pagesize&status
     */
    public function teamList()
    {
        $aid = ScopeService::aidOf($this->request);

        $status     = trim((string) $this->request->get('status', ''));
        $ctimeStart = trim((string) $this->request->get('ctime_start', ''));
        $ctimeEnd   = trim((string) $this->request->get('ctime_end', ''));
        $page       = max(1, (int) $this->request->get('page', 1));
        $pageSize   = (int) $this->request->get('pagesize', 10);
        $pageSize   = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->collageService->teamList($aid, $status, $page, $pageSize, $ctimeStart, $ctimeEnd));
    }

    /**
     * 删除团（进行中的团禁止删除）
     * POST /admin/collage/team/delete {id}
     */
    public function teamDelete()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->collageService->teamDelete($aid, (int) $this->request->post('id', 0));

        return $this->success([], '删除成功');
    }

    /* ================= 拼团订单 ================= */

    /**
     * 订单列表（status 0-4 / r1-r3 退款态；buytype 1单买 2发团 3参团）
     * GET /admin/collage/order/list?page&pagesize&status&buytype&keyword&teamid
     */
    public function orderList()
    {
        $aid = ScopeService::aidOf($this->request);

        $status     = trim((string) $this->request->get('status', ''));
        $buytype    = trim((string) $this->request->get('buytype', ''));
        $keyword    = trim((string) $this->request->get('keyword', ''));
        $teamId     = (int) $this->request->get('teamid', 0);
        $ordernum   = trim((string) $this->request->get('ordernum', ''));
        $linkman    = trim((string) $this->request->get('linkman', ''));
        $tel        = trim((string) $this->request->get('tel', ''));
        $ctimeStart = trim((string) $this->request->get('ctime_start', ''));
        $ctimeEnd   = trim((string) $this->request->get('ctime_end', ''));
        $page       = max(1, (int) $this->request->get('page', 1));
        $pageSize   = (int) $this->request->get('pagesize', 10);
        $pageSize   = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->collageService->orderList(
            $aid,
            $status,
            $buytype,
            $keyword,
            $teamId,
            $page,
            $pageSize,
            $ordernum,
            $linkman,
            $tel,
            $ctimeStart,
            $ctimeEnd
        ));
    }

    /**
     * 订单改价（仅未支付）
     * POST /admin/collage/order/changePrice {id,price}
     */
    public function orderChangePrice()
    {
        $aid = ScopeService::aidOf($this->request);

        $id    = (int) $this->request->post('id', 0);
        $price = (float) $this->request->post('price', 0);

        $this->collageService->orderChangePrice($aid, $id, $price);

        return $this->success([], '改价成功');
    }

    /**
     * 关闭订单（仅未支付；回补库存+释放券）
     * POST /admin/collage/order/close {id}
     */
    public function orderClose()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->collageService->orderClose($aid, (int) $this->request->post('id', 0));

        return $this->success([], '订单已关闭');
    }

    /**
     * 退款审核（pass=1 同意→原路退款+退积分+退券同事务；pass=2 驳回）
     * POST /admin/collage/order/refundCheck {id,pass,remark}
     */
    public function orderRefundCheck()
    {
        $aid = ScopeService::aidOf($this->request);

        $id     = (int) $this->request->post('id', 0);
        $pass   = (int) $this->request->post('pass', 0);
        $remark = trim((string) $this->request->post('remark', ''));

        $this->collageService->orderRefundCheck($aid, $id, $pass, $remark);

        return $this->success([], 1 === $pass ? '已同意并退款' : '已驳回');
    }

    /**
     * 发货（已支付→已发货；已发货时更新物流）
     * POST /admin/collage/order/sendExpress {id,express_com,express_no}
     */
    public function orderSendExpress()
    {
        $aid = ScopeService::aidOf($this->request);

        $id         = (int) $this->request->post('id', 0);
        $expressCom = trim((string) $this->request->post('express_com', ''));
        $expressNo  = trim((string) $this->request->post('express_no', ''));

        $this->collageService->orderSendExpress($aid, $id, $expressCom, $expressNo);

        return $this->success([], '发货成功');
    }

    /**
     * 快递公司下拉（手动发货用）
     * GET /admin/collage/order/expressOptions
     */
    public function orderExpressOptions()
    {
        return $this->success($this->collageService->expressOptions());
    }

    /**
     * 直接退款（管理员对已支付/已发货订单发起退款，原路退回）
     * POST /admin/collage/order/refund {id, reason}
     */
    public function orderRefund()
    {
        $aid = ScopeService::aidOf($this->request);

        $id     = (int) $this->request->post('id', 0);
        $reason = (string) $this->request->post('reason', '');

        $this->collageService->orderRefund($aid, $id, $reason);

        return $this->success([], '已退款成功');
    }

    /**
     * 批量发货（Excel 导入：第一列订单号、第二列快递单号，统一快递公司）
     * POST /admin/collage/order/batchShip（multipart：file + express_com）
     */
    public function orderBatchShip()
    {
        $aid           = ScopeService::aidOf($this->request);
        $expressCom    = trim((string) $this->request->post('express_com', ''));

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

        if (!empty($excelRows)) {
            $firstCell = trim((string) ($excelRows[0][0] ?? ''));

            if (str_contains($firstCell, '订单号') || str_contains($firstCell, '单号')) {
                array_shift($excelRows);
            }
        }

        if (empty($excelRows)) {
            throw new BusinessException('表格中没有有效数据');
        }

        $result = $this->collageService->orderBatchShip($aid, $expressCom, $excelRows);

        return $this->success(
            $result,
            sprintf('共导入 %d 条数据，成功发货 %d 条，失败 %d 条', $result['total'], $result['success'], $result['fail'])
        );
    }

    /**
     * 订单导出（真实 xlsx 附件下载）
     * GET /admin/collage/order/export?status&buytype&ordernum&linkman&tel&ctime_start&ctime_end&teamid
     */
    public function orderExport()
    {
        $aid = ScopeService::aidOf($this->request);

        set_time_limit(0);
        ini_set('memory_limit', '1024M');

        $status     = trim((string) $this->request->get('status', ''));
        $buytype    = trim((string) $this->request->get('buytype', ''));
        $ordernum   = trim((string) $this->request->get('ordernum', ''));
        $linkman    = trim((string) $this->request->get('linkman', ''));
        $tel        = trim((string) $this->request->get('tel', ''));
        $ctimeStart = trim((string) $this->request->get('ctime_start', ''));
        $ctimeEnd   = trim((string) $this->request->get('ctime_end', ''));
        $teamId     = (int) $this->request->get('teamid', 0);

        [$titles, $rows] = $this->collageService->orderExportData(
            $aid,
            $status,
            $buytype,
            $ordernum,
            $linkman,
            $tel,
            $ctimeStart,
            $ctimeEnd,
            $teamId,
            20000
        );

        ExcelUtil::export($titles, $rows, '拼团订单导出_' . date('YmdHis'));
    }

    /**
     * 订单详情
     * GET /admin/collage/order/detail?id=
     */
    public function orderDetail()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->collageService->orderDetail($aid, (int) $this->request->get('id', 0)));
    }

    /**
     * 删除订单（兼容 id 单条 / ids[] 批量）
     * POST /admin/collage/order/delete {id | ids:[]}
     */
    public function orderDelete()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->collageService->orderDelete($aid, $this->resolveOrderIds());

        return $this->success([], '删除成功');
    }

    /**
     * 设置订单备注
     * POST /admin/collage/order/setRemark {id,content}
     */
    public function orderSetRemark()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->collageService->orderSetRemark(
            $aid,
            (int) $this->request->post('id', 0),
            (string) $this->request->post('content', ''),
        );

        return $this->success([], '备注已保存');
    }

    /**
     * 改为已支付（后台支付；仅未支付）
     * POST /admin/collage/order/ispay {id}
     */
    public function orderIspay()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->collageService->orderIspay($aid, (int) $this->request->post('id', 0));

        return $this->success([], '已改为已支付');
    }

    /* ================= 评价管理 ================= */

    /**
     * GET /admin/collage/comment/list?page&pagesize&content&ctime_start&ctime_end&status
     */
    public function commentList()
    {
        $aid = ScopeService::aidOf($this->request);

        $content    = trim((string) $this->request->get('content', ''));
        $ctimeStart = trim((string) $this->request->get('start_time', $this->request->get('ctime_start', '')));
        $ctimeEnd   = trim((string) $this->request->get('end_time', $this->request->get('ctime_end', '')));
        $status     = trim((string) $this->request->get('st', $this->request->get('status', '')));
        $page       = max(1, (int) $this->request->get('page', 1));
        $pageSize   = (int) $this->request->get('pagesize', 10);
        $pageSize   = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->collageService->commentList($aid, $content, $ctimeStart, $ctimeEnd, $status, $page, $pageSize));
    }

    /**
     * 评价审核/批量审核（status=1 通过 / 0 不通过）
     * POST /admin/collage/comment/setst {ids:[] | id, status}
     */
    public function commentSetst()
    {
        $aid = ScopeService::aidOf($this->request);

        $ids    = $this->resolveCommentIds();
        $status = (int) $this->request->post('status', -1);

        $this->collageService->commentSetst($aid, $ids, $status);

        return $this->success([], 1 === $status ? '已通过' : '已隐藏');
    }

    /**
     * 评价详情
     * GET /admin/collage/comment/detail?id=
     */
    public function commentDetail()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->collageService->commentDetail($aid, (int) $this->request->get('id', 0)));
    }

    /**
     * 评价回复
     * POST /admin/collage/comment/reply {id,content}
     */
    public function commentReply()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->collageService->commentReply(
            $aid,
            (int) $this->request->post('id', 0),
            (string) $this->request->post('content', ''),
        );

        return $this->success([], '回复成功');
    }

    /**
     * 删除评价（兼容 id 单条 / ids[] 批量）
     * POST /admin/collage/comment/delete {id | ids:[]}
     */
    public function commentDelete()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->collageService->commentDelete($aid, $this->resolveCommentIds());

        return $this->success([], '删除成功');
    }

    /**
     * 归一化订单 ids 入参
     *
     * @return int[]
     */
    private function resolveOrderIds(): array
    {
        $ids = $this->request->post('ids');

        if (is_array($ids)) {
            return array_values(array_filter(array_map('intval', $ids)));
        }

        $id = (int) $this->request->post('id', 0);

        return $id > 0 ? [$id] : [];
    }

    /**
     * 归一化评价 ids 入参
     *
     * @return int[]
     */
    private function resolveCommentIds(): array
    {
        $ids = $this->request->post('ids');

        if (is_array($ids)) {
            return array_values(array_filter(array_map('intval', $ids)));
        }

        $id = (int) $this->request->post('id', 0);

        return $id > 0 ? [$id] : [];
    }
}
