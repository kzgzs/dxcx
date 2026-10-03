<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\ExcelHelper;
use app\common\service\ScopeService;
use app\common\service\TuangouService;

/**
 * 租户后台 - 团购管理（admin 应用，JWT role=admin，aid 走 ScopeService）
 *
 * 覆盖：团购设置 / 分类 / 商品（阶梯价/复制/审核/上下架） / 订单全操作（改价/关闭/发货/核销/退款审核/导出/备注）
 * / 评价（审核回复+好评率聚合） / 卡密（生成/导入/导出）
 */
class Tuangou extends BaseController
{
    public function __construct(\think\App $app, protected TuangouService $tuangouService)
    {
        parent::__construct($app);
    }

    /* ================= 团购设置 ================= */

    /**
     * 读取团购设置
     * GET /admin/tuangou/set
     */
    public function setGet()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->tuangouService->sysset($aid));
    }

    /**
     * 保存团购设置（pics/autoshdays/comment/comment_check/showjd）
     * POST /admin/tuangou/set
     */
    public function setSave()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->tuangouService->saveSysset($aid, $this->request->post());

        return $this->success([], '保存成功');
    }

    /* ================= 团购分类 ================= */

    /**
     * 分类列表（扁平全量）
     * GET /admin/tuangou/category/list
     */
    public function categoryList()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->tuangouService->categoryList($aid));
    }

    /**
     * 分类新增/编辑
     * POST /admin/tuangou/category/save
     */
    public function categorySave()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->tuangouService->categorySave($aid, $this->request->post()), '保存成功');
    }

    /**
     * 删除分类
     * POST /admin/tuangou/category/delete {id}
     */
    public function categoryDelete()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->tuangouService->categoryDelete($aid, (int) $this->request->post('id', 0));

        return $this->success([], '删除成功');
    }

    /* ================= 团购商品 ================= */

    /**
     * 商品列表（keyword/status/checked/cid/bid 过滤）
     * GET /admin/tuangou/product/list?page&pagesize&keyword&status&checked&cid&bid
     */
    public function productList()
    {
        $aid = ScopeService::aidOf($this->request);

        $keyword  = trim((string) $this->request->get('keyword', ''));
        $status   = trim((string) $this->request->get('status', ''));
        $checked  = trim((string) $this->request->get('checked', ''));
        $cid      = (int) $this->request->get('cid', 0);
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;
        $bid      = trim((string) $this->request->get('bid', ''));

        $orderField = trim((string) $this->request->get('orderField', 'sort'));
        $orderType  = trim((string) $this->request->get('orderType', 'desc'));

        return $this->success($this->tuangouService->productList($aid, $keyword, $status, $checked, $cid, $page, $pageSize, $bid, $orderField, $orderType));
    }

    /**
     * 商品详情
     * GET /admin/tuangou/product/detail?id=
     */
    public function productDetail()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->tuangouService->productDetail($aid, (int) $this->request->get('id', 0)));
    }

    /**
     * 新增/编辑团购商品（含 pricedata 阶梯价/起止时间/限购/配送方式）
     * POST /admin/tuangou/product/save
     */
    public function productSave()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->tuangouService->productSave($aid, $this->request->post()), '保存成功');
    }

    /**
     * 复制商品
     * POST /admin/tuangou/product/copy {id}
     */
    public function productCopy()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->tuangouService->productCopy($aid, (int) $this->request->post('id', 0)), '复制成功');
    }

    /**
     * 商品上架/下架（支持批量 ids[]）
     * POST /admin/tuangou/product/status {ids,status}
     */
    public function productStatus()
    {
        $aid = ScopeService::aidOf($this->request);

        $ids    = (array) $this->request->post('ids/a', []);
        $status = (int) $this->request->post('status', -1);

        if (empty($ids)) {
            $ids = [(int) $this->request->post('id', 0)];
        }

        $this->tuangouService->productStatus($aid, array_map('intval', $ids), $status);

        return $this->success([], 1 === $status ? '已上架' : '已下架');
    }

    /**
     * 商品审核（ischecked 1通过 2驳回）
     * POST /admin/tuangou/product/check {id,ischecked,reason}
     */
    public function productCheck()
    {
        $aid = ScopeService::aidOf($this->request);

        $id        = (int) $this->request->post('id', 0);
        $ischecked = (int) $this->request->post('ischecked', 0);
        $reason    = trim((string) $this->request->post('reason', ''));

        $this->tuangouService->productCheck($aid, $id, $ischecked, $reason);

        return $this->success([], 1 === $ischecked ? '审核通过' : '已驳回');
    }

    /**
     * 删除商品
     * POST /admin/tuangou/product/delete {id}
     */
    public function productDelete()
    {
        $aid = ScopeService::aidOf($this->request);

        $ids = (array) $this->request->post('ids/a', []);
        if (empty($ids)) {
            $ids = [(int) $this->request->post('id', 0)];
        }

        $this->tuangouService->productDelete($aid, array_map('intval', $ids));

        return $this->success([], '删除成功');
    }

    /**
     * 退差价（按当前已达成阶梯价对已支付订单退差价）
     * POST /admin/tuangou/product/refundDiff {id}
     */
    public function productRefundDiff()
    {
        $aid = ScopeService::aidOf($this->request);

        $id = (int) $this->request->post('id', 0);

        return $this->success($this->tuangouService->refundDiff($aid, $id), '退差价完成');
    }

    /* ================= 团购订单 ================= */

    /**
     * 订单列表（status 0-4 / r1-r3 退款态；keyword 订单号/商品名/联系人/手机号）
     * GET /admin/tuangou/order/list?page&pagesize&status&keyword&bid
     */
    public function orderList()
    {
        $aid = ScopeService::aidOf($this->request);

        $status   = trim((string) $this->request->get('status', ''));
        $keyword  = trim((string) $this->request->get('keyword', ''));
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;
        $bid      = trim((string) $this->request->get('bid', ''));

        $filters = [
            'ordernum'  => trim((string) $this->request->get('ordernum', '')),
            'linkman'   => trim((string) $this->request->get('linkman', '')),
            'tel'       => trim((string) $this->request->get('tel', '')),
            'starttime' => trim((string) $this->request->get('starttime', '')),
            'endtime'   => trim((string) $this->request->get('endtime', '')),
            'sortfield' => (string) $this->request->get('sortfield', 'id'),
            'sortorder' => (string) $this->request->get('sortorder', 'desc'),
        ];

        return $this->success($this->tuangouService->orderList($aid, $status, $keyword, $page, $pageSize, $bid, $filters));
    }

    /**
     * 订单详情
     * GET /admin/tuangou/order/detail?id=
     */
    public function orderDetail()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->tuangouService->orderDetail($aid, (int) $this->request->get('id', 0)));
    }

    /**
     * 订单改价（仅未支付）
     * POST /admin/tuangou/order/changePrice {id,price}
     */
    public function orderChangePrice()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->tuangouService->orderChangePrice($aid, (int) $this->request->post('id', 0), (float) $this->request->post('price', 0));

        return $this->success([], '改价成功');
    }

    /**
     * 关闭订单（仅未支付；回补库存）
     * POST /admin/tuangou/order/close {id}
     */
    public function orderClose()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->tuangouService->orderClose($aid, (int) $this->request->post('id', 0));

        return $this->success([], '订单已关闭');
    }

    /**
     * 订单备注
     * POST /admin/tuangou/order/remark {id,remark}
     */
    public function orderRemark()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->tuangouService->orderRemark($aid, (int) $this->request->post('id', 0), (string) $this->request->post('remark', ''));

        return $this->success([], '备注成功');
    }

    /**
     * 退款审核（pass=1 同意→原路退款；pass=2 驳回）
     * POST /admin/tuangou/order/refundCheck {id,pass,remark}
     */
    public function orderRefundCheck()
    {
        $aid = ScopeService::aidOf($this->request);

        $id     = (int) $this->request->post('id', 0);
        $pass   = (int) $this->request->post('pass', 0);
        $remark = trim((string) $this->request->post('remark', ''));

        $this->tuangouService->orderRefundCheck($aid, $id, $pass, $remark);

        return $this->success([], 1 === $pass ? '已同意并退款' : '已驳回');
    }

    /**
     * 快递发货（活动未结束不允许发货；已发货时更新物流）
     * POST /admin/tuangou/order/sendExpress {id,express_com,express_no}
     */
    public function orderSendExpress()
    {
        $aid = ScopeService::aidOf($this->request);

        $id         = (int) $this->request->post('id', 0);
        $expressCom = trim((string) $this->request->post('express_com', ''));
        $expressNo  = trim((string) $this->request->post('express_no', ''));

        $this->tuangouService->orderSendExpress($aid, $id, $expressCom, $expressNo);

        return $this->success([], '发货成功');
    }

    /**
     * 到店核销并确认收货（写 hexiao_order type=tuangou）
     * POST /admin/tuangou/order/hexiao {id}
     */
    public function orderHexiao()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->tuangouService->orderHexiao($aid, (int) $this->request->post('id', 0));

        return $this->success([], '核销成功');
    }

    /**
     * 订单导出（Excel 下载）
     * GET /admin/tuangou/order/export?status&keyword
     */
    public function orderExport()
    {
        $aid = ScopeService::aidOf($this->request);

        $status  = trim((string) $this->request->get('status', ''));
        $keyword = trim((string) $this->request->get('keyword', ''));

        $rows = $this->tuangouService->orderExportRows($aid, $status, $keyword);

        return ExcelHelper::download('团购订单_' . date('YmdHis'), [
            '订单号', '下单人', '商品名称', '数量', '实付款', '配送方式', '收货地址', '联系人', '联系电话',
            '快递公司', '快递单号', '客户留言', '备注', '下单时间', '状态', '退款状态',
        ], $rows);
    }


    /**
     * 删除订单（单 id 或批量 ids[]）
     * POST /admin/tuangou/order/del {id | ids[]}
     */
    public function orderDelete()
    {
        $aid = ScopeService::aidOf($this->request);
        $ids = (array) $this->request->post('ids', []);
        if (empty($ids)) { $ids = [(int) $this->request->post('id', 0)]; }
        $this->tuangouService->orderDelete($aid, $ids);
        return $this->success([], '删除成功');
    }

    /**
     * 改为已支付（仅未支付）
     * POST /admin/tuangou/order/ispay {id}
     */
    public function orderIspay()
    {
        $aid = ScopeService::aidOf($this->request);
        $this->tuangouService->orderIspay($aid, (int) $this->request->post('id', 0));
        return $this->success([], '操作成功');
    }
    /* ================= 团购评价 ================= */

    /**
     * 评价列表（status 空=全部 0待审核 1显示 2驳回）
     * GET /admin/tuangou/comment/list?page&pagesize&status&proid
     */
    public function commentList()
    {
        $aid = ScopeService::aidOf($this->request);

        $status   = trim((string) $this->request->get('status', ''));
        $proid    = (int) $this->request->get('proid', 0);
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        $filters = [
            'content'   => trim((string) $this->request->get('content', '')),
            'starttime' => trim((string) $this->request->get('starttime', '')),
            'endtime'   => trim((string) $this->request->get('endtime', '')),
            'sortorder' => (string) $this->request->get('sortorder', 'desc'),
        ];

        return $this->success($this->tuangouService->commentList($aid, $status, $proid, $page, $pageSize, $filters));
    }

    /**
     * 评价审核（status 1显示 2驳回）
     * POST /admin/tuangou/comment/setst {id,status}
     */
    public function commentSetStatus()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->tuangouService->commentSetStatus($aid, (int) $this->request->post('id', 0), (int) $this->request->post('status', 0));

        return $this->success([], '操作成功');
    }

    /**
     * 评价回复
     * POST /admin/tuangou/comment/reply {id,content}
     */
    public function commentReply()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->tuangouService->commentReply($aid, (int) $this->request->post('id', 0), (string) $this->request->post('content', ''));

        return $this->success([], '回复成功');
    }

    /**
     * 删除评价
     * POST /admin/tuangou/comment/delete {id}
     */
    public function commentDelete()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->tuangouService->commentDelete($aid, (int) $this->request->post('id', 0));

        return $this->success([], '删除成功');
    }

    /* ================= 团购卡密 ================= */

    /**
     * 卡密列表
     * GET /admin/tuangou/code/list?page&pagesize&proid&content&ordernum&nickname&status
     */
    public function codelistList()
    {
        $aid = ScopeService::aidOf($this->request);

        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->tuangouService->codelistList(
            $aid,
            $page,
            $pageSize,
            (int) $this->request->get('proid', 0),
            trim((string) $this->request->get('content', '')),
            trim((string) $this->request->get('ordernum', '')),
            trim((string) $this->request->get('nickname', '')),
            trim((string) $this->request->get('status', ''))
        ));
    }

    /**
     * Excel 导入卡密（multipart 上传 file；proid 必填）
     * POST /admin/tuangou/code/import
     */
    public function codelistImport()
    {
        $aid = ScopeService::aidOf($this->request);

        $proid = (int) $this->request->post('proid', 0);
        $file  = $this->request->file('file');

        if (empty($file)) {
            return $this->error('请上传 Excel 文件');
        }

        $result = $this->tuangouService->codelistImport($aid, $proid, $file->getPathname());

        return $this->success($result, '导入完成：新增 ' . $result['inserted'] . ' 条，跳过重复 ' . $result['duplicated'] . ' 条');
    }

    /**
     * 批量生成卡密
     * POST /admin/tuangou/code/make {proid,num,length,type}
     */
    public function codelistMake()
    {
        $aid = ScopeService::aidOf($this->request);

        $result = $this->tuangouService->codelistMake(
            $aid,
            (int) $this->request->post('proid', 0),
            (int) $this->request->post('num', 0),
            (int) $this->request->post('length', 8),
            (int) $this->request->post('type', 5)
        );

        return $this->success($result, '生成成功：新增 ' . $result['created'] . ' 条');
    }

    /**
     * 卡密导出（Excel 下载）
     * GET /admin/tuangou/code/export?proid=
     */
    public function codelistExport()
    {
        $aid = ScopeService::aidOf($this->request);

        $rows = $this->tuangouService->codelistExportRows($aid, (int) $this->request->get('proid', 0));

        return ExcelHelper::download('团购卡密_' . date('YmdHis'), ['ID', '卡密内容', '订单号', '购买人', '购买时间', '状态'], $rows);
    }

    /**
     * 卡密批量删除（仅未售出）
     * POST /admin/tuangou/code/delete {ids[]}
     */
    public function codelistDelete()
    {
        $aid = ScopeService::aidOf($this->request);

        $ids = (array) $this->request->post('ids/a', []);

        return $this->success($this->tuangouService->codelistDelete($aid, array_map('intval', $ids)), '删除成功');
    }

    /* ================= 扩展：分销等级/快递选项/物流/批量发货/送货单 ================= */

    /**
     * 可分销会员等级（商品分销设置展开行）
     * GET /admin/tuangou/agentLevels
     */
    public function agentLevels()
    {
        $aid = ScopeService::aidOf($this->request);
        return $this->success($this->tuangouService->agentLevels($aid));
    }

    /**
     * 快递公司下拉
     * GET /admin/tuangou/order/expressOptions
     */
    public function orderExpressOptions()
    {
        return $this->success($this->tuangouService->expressOptions());
    }

    /**
     * 查物流（快递鸟轨迹）
     * GET /admin/tuangou/order/trace?id=
     */
    public function orderTrace()
    {
        $aid = ScopeService::aidOf($this->request);
        return $this->success($this->tuangouService->orderTrace($aid, (int) $this->request->get('id', 0)));
    }

    /**
     * 批量发货（上传 Excel：第一列订单号，第二列快递单号）
     * POST /admin/tuangou/order/batchSend {express_com, file}
     */
    public function orderBatchSend()
    {
        $aid = ScopeService::aidOf($this->request);
        $expressCom = trim((string) $this->request->post('express_com', ''));
        if ('' === $expressCom) {
            return $this->error('请选择快递公司');
        }

        $file = $this->request->file('file');
        if (empty($file)) {
            return $this->error('请上传 Excel 文件');
        }

        $rows = [];
        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file->getPathname());
            $sheet = $spreadsheet->getActiveSheet();
            foreach ($sheet->getRowIterator() as $rowIdx => $row) {
                if ($rowIdx === 1) { continue; } // 跳过表头
                $ordernum = trim((string) $sheet->getCell([1, $rowIdx])->getValue());
                $expressNo = trim((string) $sheet->getCell([2, $rowIdx])->getValue());
                if ('' !== $ordernum) { $rows[$ordernum] = $expressNo; }
            }
        } catch (\Throwable $e) {
            return $this->error('Excel 解析失败：' . $e->getMessage());
        }

        if (empty($rows)) {
            return $this->error('未解析到有效订单行');
        }

        return $this->success($this->tuangouService->orderBatchSend($aid, $expressCom, $rows), '批量发货完成');
    }

    /**
     * 送货单（整页自动生成，浏览器打印）
     * GET /admin/tuangou/order/songhuo?ids=1,2,3
     */
    public function orderSonghuo()
    {
        $aid = ScopeService::aidOf($this->request);
        $ids = (string) $this->request->get('ids', '');
        $arr = array_filter(explode(',', $ids), static fn($v) => '' !== trim($v));
        $list = $this->tuangouService->orderSonghuo($aid, array_map('intval', $arr));

        $body = '';
        foreach ($list as $o) {
            $body .= '<tr><td>' . htmlspecialchars($o['ordernum']) . '</td>'
                . '<td>' . htmlspecialchars($o['proname']) . '</td>'
                . '<td>' . (int) $o['num'] . '</td>'
                . '<td>' . htmlspecialchars($o['linkman']) . ' ' . htmlspecialchars($o['tel']) . '</td>'
                . '<td>' . htmlspecialchars($o['address']) . '</td>'
                . '<td>￥' . number_format($o['totalprice'], 2) . '</td></tr>';
        }
        $html = '<html><head><meta charset="utf-8"><title>送货单</title>'
            . '<style>body{font-family:Microsoft YaHei;padding:20px}h2{text-align:center}table{width:100%;border-collapse:collapse}td,th{border:1px solid #333;padding:8px;font-size:13px}</style>'
            . '</head><body><h2>团购活动送货单</h2><p>打印时间：' . date('Y-m-d H:i:s') . '</p>'
            . '<table><tr><th>订单号</th><th>商品</th><th>数量</th><th>收货人</th><th>地址</th><th>金额</th></tr>'
            . $body . '</table>'
            . '<script>window.onload=function(){window.print();}</script></body></html>';
        return response($html);
    }
}
