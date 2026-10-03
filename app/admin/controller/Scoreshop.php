<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\ScopeService;
use app\common\service\ScoreshopService;
use think\facade\Db;
use util\ExcelUtil;

/**
 * 租户后台 - 积分商城（admin 应用，JWT role=admin，aid 走 ScopeService）
 *
 * 覆盖：商城设置 / 分类 CRUD / 积分商品 CRUD（纯积分或积分+钱） / 兑换订单与关单
 * 超时自动关单挂 app\command\ScheduleRun（ScoreshopService::closeExpired）
 */
class Scoreshop extends BaseController
{
    public function __construct(\think\App $app, protected ScoreshopService $scoreshopService)
    {
        parent::__construct($app);
    }

    /**
     * 积分商城设置读取
     * GET /admin/scoreshop/set
     */
    public function set()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->scoreshopService->getSysset($aid));
    }

    /**
     * 积分商城设置保存（自动关单/自动收货/进入人群）
     * POST /admin/scoreshop/set {autoclose, autoshdays, gettj, gettjtip, ...}
     */
    public function saveSet()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->scoreshopService->saveSysset($aid, $this->request->post());

        return $this->success([], '保存成功');
    }

    /**
     * 分类列表
     * GET /admin/scoreshop/category/list?keyword
     */
    public function categoryList()
    {
        $aid = ScopeService::aidOf($this->request);

        $keyword = trim((string) $this->request->get('keyword', ''));

        return $this->success($this->scoreshopService->categoryList($aid, $keyword));
    }

    /**
     * 分类新增/编辑
     * POST /admin/scoreshop/category/save {id?, name, pic?, sort?, status?}
     */
    public function categorySave()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->scoreshopService->categorySave($aid, $this->request->post()), '保存成功');
    }

    /**
     * 分类删除（分类下有商品时拒绝）
     * POST /admin/scoreshop/category/delete {id}
     */
    public function categoryDelete()
    {
        $aid = ScopeService::aidOf($this->request);

        $id = (int) $this->request->post('id', 0);

        $this->scoreshopService->categoryDelete($aid, $id);

        return $this->success([], '删除成功');
    }

    /**
     * 积分商品列表（附规格行）
     * GET /admin/scoreshop/product/list?page&pagesize&keyword&status&cid
     */
    public function productList()
    {
        $aid = ScopeService::aidOf($this->request);

        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        $keyword = trim((string) $this->request->get('keyword', ''));
        $status  = trim((string) $this->request->get('status', ''));
        $cid     = (int) $this->request->get('cid', 0);

        return $this->success($this->scoreshopService->productList($aid, $page, $pageSize, $keyword, $status, $cid));
    }

    /**
     * 积分商品新增/编辑（含规格 upsert）
     * POST /admin/scoreshop/product/save {id?, cid, name, score_price, money_price, ...}
     */
    public function productSave()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->scoreshopService->productSave($aid, $this->request->post()), '保存成功');
    }

    /**
     * 积分商品删除（已有兑换订单时拒绝）
     * POST /admin/scoreshop/product/delete {id}
     */
    public function productDelete()
    {
        $aid = ScopeService::aidOf($this->request);

        $id = (int) $this->request->post('id', 0);

        $this->scoreshopService->productDelete($aid, $id);

        return $this->success([], '删除成功');
    }

    /**
     * 积分商品上下架
     * POST /admin/scoreshop/product/status {id,status}
     */
    public function productStatus()
    {
        $aid = ScopeService::aidOf($this->request);

        $id     = (int) $this->request->post('id', 0);
        $status = (int) $this->request->post('status', 0);

        $this->scoreshopService->productStatus($aid, $id, $status);

        return $this->success([], 1 === $status ? '已上架' : '已下架');
    }

    /**
     * 兑换订单列表（附订单商品行）
     * GET /admin/scoreshop/order/list?page&pagesize&status&ordernum&title&linkman&tel&start_date&end_date
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
            'title'      => trim((string) $this->request->get('title', '')),
            'linkman'    => trim((string) $this->request->get('linkman', '')),
            'tel'        => trim((string) $this->request->get('tel', '')),
            'start_date' => trim((string) $this->request->get('start_date', '')),
            'end_date'   => trim((string) $this->request->get('end_date', '')),
        ];

        return $this->success($this->scoreshopService->orderList($aid, $page, $pageSize, $filters));
    }

    /**
     * 手动关单（仅未支付；同事务回补库存/销量）
     * POST /admin/scoreshop/order/close {id}
     */
    public function orderClose()
    {
        $aid = ScopeService::aidOf($this->request);

        $id = (int) $this->request->post('id', 0);

        $this->scoreshopService->orderClose($aid, $id);

        return $this->success([], '订单已关闭');
    }

    /**
     * 商品批量删除
     * POST /admin/scoreshop/product/batchDelete {ids:[1,2,3]}
     */
    public function productBatchDelete()
    {
        $aid = ScopeService::aidOf($this->request);

        $ids = $this->request->post('ids', []);
        $ids = array_values(array_filter(array_map('intval', (array) $ids)));
        if (empty($ids)) {
            return $this->error('请选择要删除的商品');
        }

        foreach ($ids as $id) {
            try {
                $this->scoreshopService->productDelete($aid, $id);
            } catch (\Throwable) {
                // 已有订单的商品跳过，不中断批量
            }
        }

        return $this->success([], '批量删除完成');
    }

    /**
     * 商品批量上下架
     * POST /admin/scoreshop/product/batchStatus {ids:[1,2,3], status:1}
     */
    public function productBatchStatus()
    {
        $aid = ScopeService::aidOf($this->request);

        $ids = $this->request->post('ids', []);
        $ids = array_values(array_filter(array_map('intval', (array) $ids)));
        $status = (int) $this->request->post('status', 0);
        if (empty($ids)) {
            return $this->error('请选择要操作的商品');
        }

        foreach ($ids as $id) {
            $this->scoreshopService->productStatus($aid, $id, $status);
        }

        return $this->success([], 1 === $status ? '已批量上架' : '已批量下架');
    }

    /**
     * 兑换订单导出（PhpSpreadsheet xlsx 附件下载）
     * GET /admin/scoreshop/order/export?status&ordernum&linkman&tel&start_date&end_date
     */
    public function orderExport()
    {
        $aid = ScopeService::aidOf($this->request);

        set_time_limit(0);
        ini_set('memory_limit', '1024M');

        $status     = trim((string) $this->request->get('status', ''));
        $ordernum   = trim((string) $this->request->get('ordernum', ''));
        $linkman    = trim((string) $this->request->get('linkman', ''));
        $tel        = trim((string) $this->request->get('tel', ''));
        $startDate  = trim((string) $this->request->get('start_date', ''));
        $endDate    = trim((string) $this->request->get('end_date', ''));

        $query = Db::name('scoreshop_order')
            ->alias('o')
            ->leftJoin('member m', 'm.id = o.mid')
            ->field('o.ordernum, o.totalscore, o.totalmoney, o.linkman, o.tel, o.address, o.status, o.createtime, m.nickname')
            ->where('o.aid', $aid);

        if ('' !== $status) {
            $query->where('o.status', (int) $status);
        }
        if ('' !== $ordernum) {
            $query->whereLike('o.ordernum', '%' . $ordernum . '%');
        }
        if ('' !== $linkman) {
            $query->whereLike('o.linkman', '%' . $linkman . '%');
        }
        if ('' !== $tel) {
            $query->whereLike('o.tel', '%' . $tel . '%');
        }
        if ('' !== $startDate && false !== strtotime($startDate)) {
            $query->where('o.createtime', '>=', strtotime($startDate));
        }
        if ('' !== $endDate && false !== strtotime($endDate)) {
            $query->where('o.createtime', '<', strtotime($endDate) + 86400);
        }

        $rows = $query->order('o.id', 'desc')->limit(20000)->select()->toArray();

        $statusMap = [0 => '未支付', 1 => '已支付', 2 => '已发货', 3 => '已收货', 4 => '已关闭'];

        $titles = ['订单号', '买家昵称', '消耗积分', '消耗金额', '收货人', '手机号', '收货地址', '状态', '下单时间'];
        $excelRows = [];
        foreach ($rows as $r) {
            $excelRows[] = [
                $r['ordernum'],
                $r['nickname'] ?? '',
                $r['totalscore'],
                $r['totalmoney'],
                $r['linkman'],
                $r['tel'],
                $r['address'],
                $statusMap[(int) $r['status']] ?? '未知',
                $r['createtime'] ? date('Y-m-d H:i:s', (int) $r['createtime']) : '',
            ];
        }

        ExcelUtil::export($titles, $excelRows, '兑换订单_' . date('YmdHis'));
    }

    /**
     * 商品详情（编辑回填）
     * GET /admin/scoreshop/product/detail?id=
     */
    public function productDetail()
    {
        $aid = ScopeService::aidOf($this->request);
        $id = (int) $this->request->get('id', 0);

        return $this->success($this->scoreshopService->productDetail($aid, $id));
    }

    /**
     * 手动快递发货
     * POST /admin/scoreshop/order/sendExpress {id, express_com, express_no}
     */
    public function orderSendExpress()
    {
        $aid = ScopeService::aidOf($this->request);
        $id = (int) $this->request->post('id', 0);
        $expressCom = (string) $this->request->post('express_com', '');
        $expressNo = (string) $this->request->post('express_no', '');
        $this->scoreshopService->orderSendExpress($aid, $id, $expressCom, $expressNo);
        return $this->success([], '发货成功');
    }

    /**
     * 到店核销（专有弹窗）
     * POST /admin/scoreshop/order/hexiao {id}
     */
    public function orderHexiao()
    {
        $aid = ScopeService::aidOf($this->request);
        $id = (int) $this->request->post('id', 0);
        $this->scoreshopService->orderHexiao($aid, $id);
        return $this->success([], '核销成功');
    }

    /**
     * 退款（专有弹窗）
     * POST /admin/scoreshop/order/refund {id, reason}
     */
    public function orderRefund()
    {
        $aid = ScopeService::aidOf($this->request);
        $id = (int) $this->request->post('id', 0);
        $reason = (string) $this->request->post('reason', '');
        $this->scoreshopService->orderRefund($aid, $id, $reason);
        return $this->success([], '退款成功');
    }

    /**
     * 配送（专有弹窗，选配送员）
     * POST /admin/scoreshop/order/deliver {id, mdid}
     */
    public function orderDeliver()
    {
        $aid = ScopeService::aidOf($this->request);
        $id = (int) $this->request->post('id', 0);
        $mdid = (int) $this->request->post('mdid', 0);
        $this->scoreshopService->orderDeliver($aid, $id, $mdid);
        return $this->success([], '配送成功');
    }

    /**
     * 改为已支付（仅未支付）
     * POST /admin/scoreshop/order/ispay {id}
     */
    public function orderIspay()
    {
        $aid = ScopeService::aidOf($this->request);
        $id = (int) $this->request->post('id', 0);
        $this->scoreshopService->orderIspay($aid, $id);
        return $this->success([], '已改为已支付');
    }

    /**
     * 删除订单（单 id 或批量 ids[]）
     * POST /admin/scoreshop/order/del {id} 或 {ids:[]}
     */
    public function orderDel()
    {
        $aid = ScopeService::aidOf($this->request);
        $ids = $this->request->post('ids', null);
        if (null === $ids) {
            $ids = [(int) $this->request->post('id', 0)];
        }
        $this->scoreshopService->orderDelete($aid, (array) $ids);
        return $this->success([], '删除成功');
    }

    /**
     * 快递公司下拉
     * GET /admin/scoreshop/order/expressOptions
     */
    public function orderExpressOptions()
    {
        return $this->success($this->scoreshopService->expressOptions());
    }

    /**
     * 物流轨迹（快递鸟）
     * GET /admin/scoreshop/order/trace?id=
     */
    public function orderTrace()
    {
        $aid = ScopeService::aidOf($this->request);
        $id = (int) $this->request->get('id', 0);
        return $this->success($this->scoreshopService->orderTrace($aid, $id));
    }

    /**
     * 送货单打印数据（按订单自动生成商品信息）
     * GET /admin/scoreshop/order/shd?id=
     */
    public function orderShd()
    {
        $aid = ScopeService::aidOf($this->request);
        $id = (int) $this->request->get('id', 0);
        return $this->success($this->scoreshopService->orderShdData($aid, $id));
    }

    /**
     * 批量发货（Excel：订单号+快递单号）
     * POST /admin/scoreshop/order/batchSend {express_com, file}
     */
    public function orderBatchSend()
    {
        $aid = ScopeService::aidOf($this->request);
        $expressCom = (string) $this->request->post('express_com', 'EMS');

        $file = $this->request->file('file');
        if (empty($file)) {
            return $this->error('请上传EXCEL文件');
        }
        $tmp = $file->getRealPath();
        $ext = strtolower($file->getOriginalExtension());

        try {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader(ucfirst($ext === 'xlsx' ? 'Xlsx' : 'Xls'));
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($tmp);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray(null, true, false, false);
        } catch (\Throwable $e) {
            return $this->error('EXCEL解析失败：' . $e->getMessage());
        }

        $data = [];
        foreach ($rows as $row) {
            $ordernum = trim((string) ($row[0] ?? ''));
            $expressNo = trim((string) ($row[1] ?? ''));
            if ('' === $ordernum || '' === $expressNo) { continue; }
            $data[$ordernum] = $expressNo;
        }

        $res = $this->scoreshopService->orderBatchSend($aid, $expressCom, $data);
        return $this->success($res, "批量发货完成：成功 {$res['success']} 笔，失败 {$res['fail']} 笔");
    }

    /**
     * 可分销会员等级（商品分销设置展开行）
     * GET /admin/scoreshop/agentLevels
     */
    public function agentLevels()
    {
        $aid = ScopeService::aidOf($this->request);
        return $this->success($this->scoreshopService->agentLevels($aid));
    }

    /**
     * 配送员列表（同城配送弹窗）
     * GET /admin/scoreshop/deliveryStaff
     */
    public function deliveryStaff()
    {
        $aid = ScopeService::aidOf($this->request);
        return $this->success($this->scoreshopService->deliveryStaff($aid));
    }
}
