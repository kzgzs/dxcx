<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\CashierService;
use app\common\service\RestaurantCashdeskService;
use app\common\service\ScopeService;

/**
 * 餐饮收银台（批次6-T6-6 任务207，最小闭环）
 *
 * 开台→点餐/加菜（含加料）→改价/抹零→退菜→结账→反结账 + 报表统计。
 * canzhao RestaurantCashdesk 控制器缺失，依据基建表结构与菜单钩子功能清单自研。
 * 鉴权：role=admin（AuthMiddleware），aid 取自 token。
 */
class RestaurantCashdesk extends BaseController
{
    public function __construct(
        \think\App $app,
        protected RestaurantCashdeskService $cashdeskService,
        protected CashierService $cashierService,
    ) {
        parent::__construct($app);
    }

    /* ================= 桌台宫格 / 开台 ================= */

    /**
     * 桌台宫格（按区域分组 + 订单摘要）
     * GET /admin/restaurant/cashdesk/desk
     */
    public function desk()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->cashdeskService->desk($aid));
    }

    /**
     * 开台（生成堂食进行中订单，桌台置占用；幂等：已开台返回原订单）
     * POST /admin/restaurant/cashdesk/open {table_id, mid?}
     */
    public function open()
    {
        $aid     = ScopeService::aidOf($this->request);
        $tableId = (int) $this->request->post('table_id', 0);
        $mid     = (int) $this->request->post('mid', 0);

        $result = $this->cashdeskService->openTable($aid, $this->uid(), $tableId, $mid);

        return $this->success($result, $result['reopened'] ? '该桌台已有进行中订单' : '开台成功');
    }

    /* ================= 点餐 ================= */

    /**
     * 菜品分类
     * GET /admin/restaurant/cashdesk/categories
     */
    public function categories()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success(['list' => $this->cashdeskService->categories($aid)]);
    }

    /**
     * 菜品列表（含加料字典）
     * GET /admin/restaurant/cashdesk/products {keyword?, category_id?}
     */
    public function products()
    {
        $aid        = ScopeService::aidOf($this->request);
        $keyword    = trim((string) $this->request->get('keyword', ''));
        $categoryId = (int) $this->request->get('category_id', 0);

        return $this->success(['list' => $this->cashdeskService->products($aid, $keyword, $categoryId)]);
    }

    /**
     * 点餐/加菜（含加料）
     * POST /admin/restaurant/cashdesk/addgoods {order_id, items: [{product_id, num, jialiao_ids}]}
     */
    public function addgoods()
    {
        $aid     = ScopeService::aidOf($this->request);
        $orderId = (int) $this->request->post('order_id', 0);
        $items   = (array) $this->request->post('items', []);

        return $this->success($this->cashdeskService->addGoods($aid, $orderId, $items), '加菜成功');
    }

    /**
     * 改数量（num=0 删除该行）
     * POST /admin/restaurant/cashdesk/changenum {order_id, goods_id, num}
     */
    public function changenum()
    {
        $aid     = ScopeService::aidOf($this->request);
        $orderId = (int) $this->request->post('order_id', 0);
        $goodsId = (int) $this->request->post('goods_id', 0);
        $num     = (int) $this->request->post('num', 0);

        return $this->success($this->cashdeskService->changeNum($aid, $orderId, $goodsId, $num), '数量已更新');
    }

    /**
     * 单品改价（is_gj=1）
     * POST /admin/restaurant/cashdesk/changeprice {order_id, goods_id, price}
     */
    public function changeprice()
    {
        $aid     = ScopeService::aidOf($this->request);
        $orderId = (int) $this->request->post('order_id', 0);
        $goodsId = (int) $this->request->post('goods_id', 0);
        $price   = $this->request->post('price');

        if (!is_numeric($price)) {
            return $this->error('请输入有效的价格');
        }

        return $this->success($this->cashdeskService->changePrice($aid, $orderId, $goodsId, (float) $price), '改价成功');
    }

    /**
     * 整单抹零（length：0不抹 1抹分 2抹角 3抹元）
     * POST /admin/restaurant/cashdesk/removezero {order_id, length}
     */
    public function removezero()
    {
        $aid     = ScopeService::aidOf($this->request);
        $orderId = (int) $this->request->post('order_id', 0);
        $length  = (int) $this->request->post('length', 0);

        return $this->success($this->cashdeskService->setRemoveZero($aid, $orderId, $length), '抹零设置成功');
    }

    /**
     * 退菜（数量扣减 + 金额重算）
     * POST /admin/restaurant/cashdesk/refundgoods {order_id, goods_id, num}
     */
    public function refundgoods()
    {
        $aid     = ScopeService::aidOf($this->request);
        $orderId = (int) $this->request->post('order_id', 0);
        $goodsId = (int) $this->request->post('goods_id', 0);
        $num     = (int) $this->request->post('num', 1);

        return $this->success($this->cashdeskService->refundGoods($aid, $orderId, $goodsId, $num), '退菜成功');
    }

    /* ================= 订单 ================= */

    /**
     * 订单详情
     * GET /admin/restaurant/cashdesk/orderdetail {id}
     */
    public function orderdetail()
    {
        $aid = ScopeService::aidOf($this->request);
        $id  = (int) $this->request->get('id', 0);

        return $this->success($this->cashdeskService->orderDetail($aid, $id));
    }

    /**
     * 收银订单列表（时间/状态筛选）
     * GET /admin/restaurant/cashdesk/orderlist {status?, start_time?, end_time?, page?, pagesize?}
     */
    public function orderlist()
    {
        $aid       = ScopeService::aidOf($this->request);
        $status    = trim((string) $this->request->get('status', ''));
        $startTime = trim((string) $this->request->get('start_time', ''));
        $endTime   = trim((string) $this->request->get('end_time', ''));
        $page      = max(1, (int) $this->request->get('page', 1));
        $pageSize  = min(100, max(1, (int) $this->request->get('pagesize', 20)));

        return $this->success($this->cashdeskService->orderList($aid, $status, $startTime, $endTime, $page, $pageSize));
    }

    /* ================= 结账 / 反结账 ================= */

    /**
     * 结账（支付方式按 restaurant_cashdesk 开关；落账完成 + 桌台清台）
     * POST /admin/restaurant/cashdesk/pay {order_id, paytype}
     */
    public function pay()
    {
        $aid     = ScopeService::aidOf($this->request);
        $orderId = (int) $this->request->post('order_id', 0);
        $payType = (int) $this->request->post('paytype', 0);

        return $this->success($this->cashdeskService->pay($aid, $this->uid(), $orderId, $payType), '结账成功');
    }

    /**
     * 可用支付方式（按收银设置开关）
     * GET /admin/restaurant/cashdesk/paylist
     */
    public function paylist()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success(['list' => $this->cashierService->payList($aid)]);
    }

    /**
     * 反结账（已完成订单撤销回进行中；审核开关可配）
     * POST /admin/restaurant/cashdesk/reversepay {order_id}
     */
    public function reversepay()
    {
        $aid     = ScopeService::aidOf($this->request);
        $orderId = (int) $this->request->post('order_id', 0);

        $result = $this->cashdeskService->reversePay($aid, $orderId);

        return $this->success($result, $result['audited'] ? '反结账申请已提交，待审核' : '反结账成功');
    }

    /**
     * 反结账审核（reverse_audit=1 时）
     * POST /admin/restaurant/cashdesk/reverseaudit {order_id, pass}
     */
    public function reverseaudit()
    {
        $aid     = ScopeService::aidOf($this->request);
        $orderId = (int) $this->request->post('order_id', 0);
        $pass    = (int) $this->request->post('pass', 0) === 1;

        $this->cashdeskService->reverseAudit($aid, $orderId, $pass);

        return $this->success([], $pass ? '审核通过，订单已撤销回进行中' : '已驳回');
    }

    /* ================= 收银设置 / 统计 ================= */

    /**
     * 餐饮收银设置（支付开关/抹零/自定义支付/反结账审核开关）
     * GET /admin/restaurant/cashdesk/config
     */
    public function config()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->cashdeskService->config($aid));
    }

    /**
     * 保存餐饮收银设置
     * POST /admin/restaurant/cashdesk/saveconfig {wxpay?, cashpay?, moneypay?, remove_zero_length?, reverse_audit?}
     */
    public function saveconfig()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->cashdeskService->saveConfig($aid, $this->request->post());

        return $this->success([], '保存成功');
    }

    /**
     * 自定义支付字典 CRUD（复用一期 CashierService，字段名 title）
     * GET /admin/restaurant/cashdesk/customlist
     */
    public function customlist()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success(['list' => $this->cashierService->customPayList($aid, false)]);
    }

    /**
     * POST /admin/restaurant/cashdesk/customsave {id?, title, sort?, status?}
     */
    public function customsave()
    {
        $aid = ScopeService::aidOf($this->request);

        $result = $this->cashierService->customPaySave($aid, $this->request->post());

        return $this->success($result, '保存成功');
    }

    /**
     * POST /admin/restaurant/cashdesk/customdelete {id}
     */
    public function customdelete()
    {
        $aid = ScopeService::aidOf($this->request);
        $id  = (int) $this->request->post('id', 0);

        $this->cashierService->customPayDelete($aid, $id);

        return $this->success([], '删除成功');
    }

    /**
     * 收银统计（自定义时间范围：单数/营业额/按支付方式/按桌台）
     * GET /admin/restaurant/cashdesk/stat {start_time?, end_time?}
     */
    public function stat()
    {
        $aid       = ScopeService::aidOf($this->request);
        $startTime = trim((string) $this->request->get('start_time', ''));
        $endTime   = trim((string) $this->request->get('end_time', ''));

        return $this->success($this->cashdeskService->stat($aid, $startTime, $endTime));
    }

    /* ================= 内部 ================= */

    protected function uid(): int
    {
        return (int) ($this->request->authUid ?? 0);
    }
}
