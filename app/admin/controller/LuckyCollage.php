<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\LuckyCollageService;
use app\common\service\ScopeService;
use util\ExcelUtil;

/**
 * 租户后台 - 幸运拼团管理（admin 应用，JWT role=admin，aid 走 ScopeService）
 *
 * 覆盖：系统设置 / 商品分类 / 商品 / 团管理 / 订单 / 评价 / 卡密 / 机器人(kaituan)
 * （超时失败退款由 schedule:run 挂接 LuckyCollageService::dissolveExpiredTeams）
 */
class LuckyCollage extends BaseController
{
    public function __construct(\think\App $app, protected LuckyCollageService $luckyCollageService)
    {
        parent::__construct($app);
    }

    /* ================= 系统设置 ================= */

    /**
     * GET /admin/luckyCollage/set
     */
    public function setGet()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->luckyCollageService->sysset($aid));
    }

    /**
     * POST /admin/luckyCollage/set
     */
    public function setSave()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->luckyCollageService->saveSysset($aid, $this->request->post());

        return $this->success([], '保存成功');
    }

    /* ================= 商品分类 ================= */

    /**
     * GET /admin/luckyCollage/category/list
     */
    public function categoryList()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success(['list' => $this->luckyCollageService->categoryList($aid)]);
    }

    /**
     * POST /admin/luckyCollage/category/save
     */
    public function categorySave()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->luckyCollageService->categorySave($aid, $this->request->post()), '保存成功');
    }

    /**
     * POST /admin/luckyCollage/category/delete {id}
     */
    public function categoryDelete()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->luckyCollageService->categoryDelete($aid, (int) $this->request->post('id', 0));

        return $this->success([], '删除成功');
    }

    /* ================= 商品管理 ================= */

    /**
     * GET /admin/luckyCollage/product/list?page&pagesize&keyword&status&checked
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

        return $this->success($this->luckyCollageService->productList($aid, $keyword, $status, $checked, $page, $pageSize, $bid, $cid, $orderField, $orderType));
    }

    /**
     * GET /admin/luckyCollage/product/detail?id=
     */
    public function productDetail()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->luckyCollageService->productDetail($aid, (int) $this->request->get('id', 0)));
    }

    /**
     * POST /admin/luckyCollage/product/save（含开奖设置字段与规格行 options）
     */
    public function productSave()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->luckyCollageService->productSave($aid, $this->request->post()), '保存成功');
    }

    /**
     * POST /admin/luckyCollage/product/status {id,status}
     */
    public function productStatus()
    {
        $aid = ScopeService::aidOf($this->request);

        $status = (int) $this->request->post('status', -1);

        $this->luckyCollageService->productBatchStatus($aid, $this->resolveProductIds(), $status);

        return $this->success([], 1 === $status ? '已上架' : '已下架');
    }

    /**
     * POST /admin/luckyCollage/product/check {id,ischecked,reason}
     */
    public function productCheck()
    {
        $aid = ScopeService::aidOf($this->request);

        $id        = (int) $this->request->post('id', 0);
        $ischecked = (int) $this->request->post('ischecked', 0);
        $reason    = trim((string) $this->request->post('reason', ''));

        $this->luckyCollageService->productCheck($aid, $id, $ischecked, $reason);

        return $this->success([], 1 === $ischecked ? '已通过' : '已驳回');
    }

    /**
     * POST /admin/luckyCollage/product/delete {id}
     */
    public function productDelete()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->luckyCollageService->productBatchDelete($aid, $this->resolveProductIds());

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
     * GET /admin/luckyCollage/team/list?page&pagesize&status&ctime_start&ctime_end
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

        return $this->success($this->luckyCollageService->teamList($aid, $status, $page, $pageSize, $ctimeStart, $ctimeEnd));
    }

    /**
     * POST /admin/luckyCollage/team/delete {id}
     */
    public function teamDelete()
    {
        $aid = ScopeService::aidOf($this->request);

        $ids = $this->request->post('ids', []);
        if (!is_array($ids)) {
            $ids = [(int) $this->request->post('id', 0)];
        }

        $this->luckyCollageService->teamDelete($aid, $ids);

        return $this->success([], '删除成功');
    }

    /* ================= 订单管理 ================= */

    /**
     * GET /admin/luckyCollage/order/list?page&pagesize&status&buytype&teamid&ordernum&linkman&tel&ctime_start&ctime_end
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

        return $this->success($this->luckyCollageService->orderList(
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
     * POST /admin/luckyCollage/order/close {id}
     */
    public function orderClose()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->luckyCollageService->orderClose($aid, (int) $this->request->post('id', 0));

        return $this->success([], '已关闭');
    }

    /**
     * POST /admin/luckyCollage/order/sendExpress {id,express_com,express_no}
     */
    public function orderSendExpress()
    {
        $aid = ScopeService::aidOf($this->request);

        $id         = (int) $this->request->post('id', 0);
        $expressCom = trim((string) $this->request->post('express_com', ''));
        $expressNo  = trim((string) $this->request->post('express_no', ''));

        $this->luckyCollageService->orderSendExpress($aid, $id, $expressCom, $expressNo);

        return $this->success([], '发货成功');
    }

    /**
     * 退款审核（用户申请 refund_status=1）
     * POST /admin/luckyCollage/order/refundCheck {id,pass,remark}
     */
    public function orderRefundCheck()
    {
        $aid = ScopeService::aidOf($this->request);

        $id     = (int) $this->request->post('id', 0);
        $pass   = (int) $this->request->post('pass', 0);
        $remark = trim((string) $this->request->post('remark', ''));

        $this->luckyCollageService->orderRefundCheck($aid, $id, $pass, $remark);

        return $this->success([], 1 === $pass ? '已同意并退款' : '已驳回');
    }

    /**
     * 直接退款（管理员发起）
     * POST /admin/luckyCollage/order/refund {id,reason}
     */
    public function orderRefund()
    {
        $aid = ScopeService::aidOf($this->request);

        $id     = (int) $this->request->post('id', 0);
        $reason = (string) $this->request->post('reason', '');

        $this->luckyCollageService->orderRefund($aid, $id, $reason);

        return $this->success([], '已退款成功');
    }

    /**
     * 快递公司下拉（手动发货用）
     * GET /admin/luckyCollage/order/expressOptions
     */
    public function orderExpressOptions()
    {
        return $this->success($this->luckyCollageService->expressOptions());
    }

    /**
     * 到店自提核销
     * POST /admin/luckyCollage/order/hexiao {id}
     */
    public function orderHexiao()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->luckyCollageService->orderHexiao($aid, (int) $this->request->post('id', 0));

        return $this->success([], '核销成功');
    }

    /**
     * 确认收货
     * POST /admin/luckyCollage/order/collect {id}
     */
    public function orderCollect()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->luckyCollageService->orderCollect($aid, (int) $this->request->post('id', 0));

        return $this->success([], '已确认收货');
    }

    /**
     * 查物流（快递鸟轨迹）
     * GET /admin/luckyCollage/order/getExpress?id=
     */
    public function orderGetExpress()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->luckyCollageService->orderGetExpress($aid, (int) $this->request->get('id', 0)));
    }

    /**
     * 送货单打印数据
     * GET /admin/luckyCollage/order/shd?id=
     */
    public function orderShd()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->luckyCollageService->orderShdData($aid, (int) $this->request->get('id', 0)));
    }

    /**
     * 批量发货（Excel 导入：第一列订单号、第二列快递单号，统一快递公司）
     * POST /admin/luckyCollage/order/batchShip（multipart：file + express_com）
     */
    public function orderBatchShip()
    {
        $aid        = ScopeService::aidOf($this->request);
        $expressCom = trim((string) $this->request->post('express_com', ''));

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

        $result = $this->luckyCollageService->orderBatchShip($aid, $expressCom, $excelRows);

        return $this->success(
            $result,
            sprintf('共导入 %d 条数据，成功发货 %d 条，失败 %d 条', $result['total'], $result['success'], $result['fail'])
        );
    }

    /**
     * 订单导出（真实 xlsx 附件下载）
     * GET /admin/luckyCollage/order/export?status&buytype&ordernum&linkman&tel&ctime_start&ctime_end&teamid
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

        [$titles, $rows] = $this->luckyCollageService->orderExportData(
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

        ExcelUtil::export($titles, $rows, '幸运拼团订单导出_' . date('YmdHis'));
    }

    /* ================= 评价管理 ================= */

    /**
     * GET /admin/luckyCollage/comment/list?page&pagesize
     */
    public function commentList()
    {
        $aid = ScopeService::aidOf($this->request);

        $content    = trim((string) $this->request->get('content', ''));
        $ctimeStart = trim((string) $this->request->get('ctime_start', ''));
        $ctimeEnd   = trim((string) $this->request->get('ctime_end', ''));
        $status     = trim((string) $this->request->get('status', ''));
        $page       = max(1, (int) $this->request->get('page', 1));
        $pageSize   = (int) $this->request->get('pagesize', 10);
        $pageSize   = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->luckyCollageService->commentList($aid, $content, $ctimeStart, $ctimeEnd, $status, $page, $pageSize));
    }

    /**
     * POST /admin/luckyCollage/comment/reply {id,content}
     */
    public function commentReply()
    {
        $aid = ScopeService::aidOf($this->request);

        $id      = (int) $this->request->post('id', 0);
        $content = trim((string) $this->request->post('content', ''));

        $this->luckyCollageService->commentReply($aid, $id, $content);

        return $this->success([], '回复成功');
    }

    /**
     * POST /admin/luckyCollage/comment/delete {id}
     */
    public function commentDelete()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->luckyCollageService->commentDelete($aid, (int) $this->request->post('id', 0));

        return $this->success([], '删除成功');
    }

    /* ================= 卡密管理 ================= */

    /**
     * GET /admin/luckyCollage/code/list?page&pagesize&proid&status
     */
    public function codeList()
    {
        $aid = ScopeService::aidOf($this->request);

        $proid    = (int) $this->request->get('proid', 0);
        $status   = trim((string) $this->request->get('status', ''));
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->luckyCollageService->codelistList($aid, $proid, $status, $page, $pageSize));
    }

    /**
     * POST /admin/luckyCollage/code/import {proid, contents: string[] 或换行分隔文本}
     */
    public function codeImport()
    {
        $aid = ScopeService::aidOf($this->request);

        $proid    = (int) $this->request->post('proid', 0);
        $contents = $this->request->post('contents', []);

        if (!is_array($contents)) {
            $contents = preg_split('/\r\n|\r|\n/', (string) $contents) ?: [];
        }

        return $this->success($this->luckyCollageService->codelistImport($aid, $proid, $contents), '导入完成');
    }

    /**
     * POST /admin/luckyCollage/code/delete {id}
     */
    public function codeDelete()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->luckyCollageService->codelistDelete($aid, (int) $this->request->post('id', 0));

        return $this->success([], '删除成功');
    }

    /* ================= 机器人管理 ================= */

    /**
     * GET /admin/luckyCollage/jiqi/list?page&pagesize
     */
    public function jiqiList()
    {
        $aid = ScopeService::aidOf($this->request);

        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->luckyCollageService->jiqiList($aid, $page, $pageSize));
    }

    /**
     * POST /admin/luckyCollage/jiqi/save {id?,nickname,headimg,status,sort}
     */
    public function jiqiSave()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->luckyCollageService->jiqiSave($aid, $this->request->post()), '保存成功');
    }

    /**
     * POST /admin/luckyCollage/jiqi/delete {id}
     */
    public function jiqiDelete()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->luckyCollageService->jiqiDelete($aid, (int) $this->request->post('id', 0));

        return $this->success([], '删除成功');
    }

    /**
     * 机器人开团/参团（凑人数，满员触发开奖）
     * POST /admin/luckyCollage/jiqi/kaituan {proid,buytype(2发团/3参团),teamid?}
     */
    public function kaituan()
    {
        $aid = ScopeService::aidOf($this->request);

        $proid   = (int) $this->request->post('proid', 0);
        $buytype = (int) $this->request->post('buytype', 2);
        $teamId  = (int) $this->request->post('teamid', 0);

        $result = $this->luckyCollageService->kaituan($aid, $proid, $buytype, $teamId);

        return $this->success($result, !empty($result['kaijiang']) ? '机器人已入团并触发开奖' : '机器人已入团');
    }

    /* ================= 订单补充操作 ================= */

    /**
     * 订单详情
     * GET /admin/luckyCollage/order/detail?id=
     */
    public function orderDetail()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->luckyCollageService->orderDetail($aid, (int) $this->request->get('id', 0)));
    }

    /**
     * 删除订单（兼容 id 单条 / ids[] 批量）
     * POST /admin/luckyCollage/order/delete {id | ids:[]}
     */
    public function orderDelete()
    {
        $aid = ScopeService::aidOf($this->request);

        $ids = $this->request->post('ids');
        if (is_array($ids)) {
            $ids = array_values(array_filter(array_map('intval', $ids)));
        } else {
            $id = (int) $this->request->post('id', 0);
            $ids = $id > 0 ? [$id] : [];
        }

        $this->luckyCollageService->orderDelete($aid, $ids);

        return $this->success([], '删除成功');
    }

    /**
     * 订单改价（仅未支付）
     * POST /admin/luckyCollage/order/changePrice {id,price}
     */
    public function orderChangePrice()
    {
        $aid = ScopeService::aidOf($this->request);

        $id    = (int) $this->request->post('id', 0);
        $price = (float) $this->request->post('price', 0);

        $this->luckyCollageService->orderChangePrice($aid, $id, $price);

        return $this->success([], '改价成功');
    }

    /**
     * 设置订单备注
     * POST /admin/luckyCollage/order/setRemark {id,content}
     */
    public function orderSetRemark()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->luckyCollageService->orderSetRemark(
            $aid,
            (int) $this->request->post('id', 0),
            (string) $this->request->post('content', ''),
        );

        return $this->success([], '备注已保存');
    }

    /**
     * 改为已支付（后台支付；仅未支付）
     * POST /admin/luckyCollage/order/ispay {id}
     */
    public function orderIspay()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->luckyCollageService->orderIspay($aid, (int) $this->request->post('id', 0));

        return $this->success([], '已改为已支付');
    }

    /* ================= 评价补充操作 ================= */

    /**
     * 评价审核/批量审核（status=1 通过 / 0 不通过）
     * POST /admin/luckyCollage/comment/setst {ids:[] | id, status}
     */
    public function commentSetst()
    {
        $aid = ScopeService::aidOf($this->request);

        $ids = $this->request->post('ids');
        if (is_array($ids)) {
            $ids = array_values(array_filter(array_map('intval', $ids)));
        } else {
            $id = (int) $this->request->post('id', 0);
            $ids = $id > 0 ? [$id] : [];
        }

        $status = (int) $this->request->post('status', -1);

        $this->luckyCollageService->commentSetst($aid, $ids, $status);

        return $this->success([], 1 === $status ? '已通过' : '已隐藏');
    }

    /**
     * 评价详情
     * GET /admin/luckyCollage/comment/detail?id=
     */
    public function commentDetail()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->luckyCollageService->commentDetail($aid, (int) $this->request->get('id', 0)));
    }
}
