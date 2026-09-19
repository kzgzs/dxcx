<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\CashierService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 通用收银台·收银操作页（批次6-T6-5 一期，核心）
 *
 * 商品选择加购/数量/改价/备注/删除、直接收款、挂单取单、会员绑定、
 * 结算预览（会员折扣+抹零）、支付（微信付款码mock/现金/余额/自定义）
 */
class Cashier extends BaseController
{
    /* ---------- 商品与会员 ---------- */

    /**
     * 商品分类
     * GET /admin/cashier/categories
     */
    public function categories()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success(['list' => app(CashierService::class)->categoryList($aid)]);
    }

    /**
     * 商品列表（带规格，支持名称/编码/条码搜索与分类过滤）
     * GET /admin/cashier/products
     */
    public function products()
    {
        $aid = ScopeService::aidOf($this->request);

        $filters = [
            'cid'     => (int) $this->request->get('cateid', 0),
            'keyword' => (string) $this->request->get('keyword', ''),
        ];
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 50);
        if ($pageSize < 1 || $pageSize > 200) {
            $pageSize = 50;
        }

        return $this->success(app(CashierService::class)->productList($aid, $filters, $page, $pageSize));
    }

    /**
     * 会员搜索（手机号/昵称/卡号，一期无扫码枪硬件，前端模拟扫码即走此接口）
     * GET /admin/cashier/member
     */
    public function memberSearch()
    {
        $aid     = ScopeService::aidOf($this->request);
        $keyword = trim((string) $this->request->get('keyword', ''));

        return $this->success(['list' => app(CashierService::class)->searchMember($aid, $keyword)]);
    }

    /* ---------- 购物车 ---------- */

    /**
     * 当前待结算购物车视图
     * GET /admin/cashier/cart
     */
    public function cart()
    {
        $aid        = ScopeService::aidOf($this->request);
        $removeZero = (int) $this->request->get('remove_zero', 0) === 1 ? 1 : 0;
        $mid        = (int) $this->request->get('mid', 0);

        return $this->success(app(CashierService::class)->waitOrderView($aid, $removeZero, $mid));
    }

    /**
     * 加购（商品/规格/条码/直接收款 proid=-99）
     * POST /admin/cashier/addgoods
     */
    public function addGoods()
    {
        $aid = ScopeService::aidOf($this->request);

        $data = app(CashierService::class)->addToCashier($aid, [
            'proid'   => (int) $this->request->post('proid', 0),
            'ggid'    => (int) $this->request->post('ggid', 0),
            'num'     => (int) $this->request->post('num', 1),
            'barcode' => (string) $this->request->post('barcode', ''),
            'price'   => $this->request->post('price', null),
            'protype' => (int) $this->request->post('protype', 0),
        ]);

        return $this->success($data, '已加入购物车');
    }

    /**
     * 修改数量（num<1 即删除该行）
     * POST /admin/cashier/changenum
     */
    public function changeNum()
    {
        $aid     = ScopeService::aidOf($this->request);
        $goodsId = (int) $this->request->post('goodsid', 0);
        $num     = (int) $this->request->post('num', 1);

        app(CashierService::class)->changeNum($aid, $goodsId, $num);

        return $this->success([], '操作成功');
    }

    /**
     * 改价（is_gj=1，不参与会员折扣）
     * POST /admin/cashier/changeprice
     */
    public function changePrice()
    {
        $aid     = ScopeService::aidOf($this->request);
        $goodsId = (int) $this->request->post('goodsid', 0);
        $price   = (float) $this->request->post('price', 0);

        app(CashierService::class)->changePrice($aid, $goodsId, $price);

        return $this->success([], '改价成功');
    }

    /**
     * 订单备注
     * POST /admin/cashier/changeremark
     */
    public function changeRemark()
    {
        $aid     = ScopeService::aidOf($this->request);
        $orderId = (int) $this->request->post('orderid', 0);
        $remark  = (string) $this->request->post('remark', '');

        app(CashierService::class)->changeRemark($aid, $orderId, $remark);

        return $this->success([], '操作成功');
    }

    /**
     * 删除购物车商品行
     * POST /admin/cashier/delgoods
     */
    public function delGoods()
    {
        $aid     = ScopeService::aidOf($this->request);
        $goodsId = (int) $this->request->post('goodsid', 0);

        app(CashierService::class)->delGoods($aid, $goodsId);

        return $this->success([], '已删除');
    }

    /**
     * 清空购物车
     * POST /admin/cashier/clearcart
     */
    public function clearCart()
    {
        $aid = ScopeService::aidOf($this->request);

        app(CashierService::class)->clearCart($aid);

        return $this->success([], '已清空');
    }

    /* ---------- 挂单/取单 ---------- */

    /**
     * 挂单
     * POST /admin/cashier/hangup
     */
    public function hangup()
    {
        $aid = ScopeService::aidOf($this->request);
        $mid = (int) $this->request->post('mid', 0);

        app(CashierService::class)->hangup($aid, $mid);

        return $this->success([], '挂单成功');
    }

    /**
     * 取单（挂单恢复为待结算）
     * POST /admin/cashier/cancelhangup
     */
    public function cancelHangup()
    {
        $aid     = ScopeService::aidOf($this->request);
        $orderId = (int) $this->request->post('orderid', 0);

        app(CashierService::class)->cancelHangup($aid, $orderId);

        return $this->success([], '取单成功');
    }

    /**
     * 挂单列表
     * GET /admin/cashier/hanguplist
     */
    public function hangupList()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success(['list' => app(CashierService::class)->hangupList($aid)]);
    }

    /* ---------- 结算 ---------- */

    /**
     * 结算预览（会员折扣+抹零，含可用支付方式）
     * POST /admin/cashier/paypreview
     */
    public function payPreview()
    {
        $aid = ScopeService::aidOf($this->request);

        $data = app(CashierService::class)->payPreview($aid, [
            'mid'         => (int) $this->request->post('mid', 0),
            'remove_zero' => (int) $this->request->post('remove_zero', 0),
        ]);

        return $this->success($data);
    }

    /**
     * 支付成单（paytype：1微信付款码mock / 3现金 / 4余额 / 100+自定义）
     * POST /admin/cashier/pay
     */
    public function pay()
    {
        $aid = ScopeService::aidOf($this->request);
        $uid = (int) ($this->request->authUid ?? 0);

        $data = app(CashierService::class)->pay($aid, $uid, [
            'mid'         => (int) $this->request->post('mid', 0),
            'remove_zero' => (int) $this->request->post('remove_zero', 0),
            'paytype'     => (int) $this->request->post('paytype', 0),
            'auth_code'   => (string) $this->request->post('auth_code', ''),
        ]);

        return $this->success($data, '收款成功');
    }
}
