<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\ScopeService;
use app\common\service\WxProxyverifyService;

/**
 * 租户后台 - 微信代认证（任务216 批次7-8）
 *
 * 小程序列表（授权聚合）、认证订单全流程（创建→名称校验→资料上传→提交→状态流转→进度查询）、
 * 系统设置（三类客户代缴额度）。
 * mock/真实双轨经 WxProxyverifyService；参照 canzhao\app\controller\extend\WxProxyverify.php 路数重写。
 */
class WxProxyverify extends BaseController
{
    public function __construct(\think\App $app, protected WxProxyverifyService $proxyverify)
    {
        parent::__construct($app);
    }

    /* ==================== 小程序列表 ==================== */

    /**
     * 已授权小程序列表（聚合 open_authorizer + wx_proxyverify_authorizer）
     * GET /admin/wxproxyverify/index
     */
    public function index()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->proxyverify->authorizerList(
            $aid,
            max(1, (int) $this->request->get('page', 1)),
            max(1, min(100, (int) $this->request->get('pagesize', 20))),
            (string) $this->request->get('keyword', '')
        ));
    }

    /**
     * 修改小程序备注
     * POST /admin/wxproxyverify/authorizer/remark {id, remark}
     */
    public function authorizerRemark()
    {
        $aid = ScopeService::aidOf($this->request);
        $this->proxyverify->authorizerRemark(
            $aid,
            (int) $this->request->post('id', 0),
            (string) $this->request->post('remark', '')
        );

        return $this->success([], '操作成功');
    }

    /**
     * 删除小程序（快照 + 关联订单）
     * POST /admin/wxproxyverify/authorizer/del {ids: []}
     */
    public function authorizerDel()
    {
        $aid = ScopeService::aidOf($this->request);
        $ids = $this->request->post('ids/a');
        $this->proxyverify->authorizerDelete($aid, is_array($ids) ? $ids : []);

        return $this->success([], '删除成功');
    }

    /**
     * 授权回调（对标参考系统 callbackxcx）：auth_code 换取授权并更新列表状态
     * GET /admin/wxproxyverify/callbackxcx?auth_code=xxx
     */
    public function callbackxcx()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success(
            $this->proxyverify->callbackAuth($aid, (string) $this->request->param('auth_code', '')),
            '授权成功'
        );
    }

    /* ==================== 认证订单 ==================== */

    /**
     * 认证订单列表（状态筛选）
     * GET /admin/wxproxyverify/order
     */
    public function order()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->proxyverify->orderList(
            $aid,
            max(1, (int) $this->request->get('page', 1)),
            max(1, min(100, (int) $this->request->get('pagesize', 20))),
            [
                'customer_type' => (string) $this->request->get('customer_type', ''),
                'contact_name'  => (string) $this->request->get('contact_name', ''),
                'account_name'  => (string) $this->request->get('account_name', ''),
                'pay_type'      => (string) $this->request->get('pay_type', ''),
                'task_status'   => (string) $this->request->get('task_status', ''),
                'apply_status'  => (string) $this->request->get('apply_status', ''),
            ]
        ));
    }

    /**
     * 认证订单详情
     * GET /admin/wxproxyverify/order/detail?id=xx
     */
    public function orderDetail()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->proxyverify->orderDetail($aid, (int) $this->request->get('id', 0)));
    }

    /**
     * 创建/编辑认证订单并提交微信
     * POST /admin/wxproxyverify/order/save body: 订单资料（customer_type、company_xxx、contact_xxx、发票、名称、支付方式等）
     */
    public function orderSave()
    {
        $aid  = ScopeService::aidOf($this->request);
        $data = $this->request->post();

        return $this->success(
            $this->proxyverify->saveOrder($aid, is_array($data) ? $data : []),
            '提交成功'
        );
    }

    /**
     * 删除认证订单
     * POST /admin/wxproxyverify/order/del {ids: []}
     */
    public function orderDel()
    {
        $aid = ScopeService::aidOf($this->request);
        $ids = $this->request->post('ids/a');
        $this->proxyverify->orderDelete($aid, is_array($ids) ? $ids : []);

        return $this->success([], '删除成功');
    }

    /**
     * 修改订单备注
     * POST /admin/wxproxyverify/order/remark {id, remark}
     */
    public function orderRemark()
    {
        $aid = ScopeService::aidOf($this->request);
        $this->proxyverify->orderRemark(
            $aid,
            (int) $this->request->post('id', 0),
            (string) $this->request->post('remark', '')
        );

        return $this->success([], '操作成功');
    }

    /**
     * 查询认证进度（queryauth，回写 task_status/apply_status）
     * GET /admin/wxproxyverify/queryTaskStatus?id=xx
     */
    public function queryTaskStatus()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success(
            $this->proxyverify->queryTaskStatus($aid, (int) $this->request->param('id', 0)),
            '查询成功'
        );
    }

    /* ==================== 认证辅助接口 ==================== */

    /**
     * 查询个人认证身份选项列表
     * GET /admin/wxproxyverify/chooseidentity?authorizer_appid=xx
     */
    public function chooseidentity()
    {
        $aid   = ScopeService::aidOf($this->request);
        $appid = (string) $this->request->get('authorizer_appid', '');
        if ('' === $appid) {
            $appid = $this->proxyverify->resolveAppid($aid);
        }

        return $this->success(['list' => $this->proxyverify->chooseIdentity($aid, $appid)]);
    }

    /**
     * 检查小程序认证名称是否合规
     * POST /admin/wxproxyverify/checkNickName {authorizer_appid, nick_name}
     */
    public function checkNickName()
    {
        $aid   = ScopeService::aidOf($this->request);
        $appid = (string) $this->request->post('authorizer_appid', '');
        if ('' === $appid) {
            $appid = $this->proxyverify->resolveAppid($aid);
        }

        return $this->success(
            $this->proxyverify->checkNickName($aid, $appid, (string) $this->request->post('nick_name', ''))
        );
    }

    /**
     * 小程序认证上传补充材料（本地附件 → 微信 mediaid；mock 直出）
     * POST /admin/wxproxyverify/uploadauthmaterial {authorizer_appid, media}
     */
    public function uploadauthmaterial()
    {
        $aid   = ScopeService::aidOf($this->request);
        $appid = (string) $this->request->post('authorizer_appid', '');
        if ('' === $appid) {
            $appid = $this->proxyverify->resolveAppid($aid);
        }

        return $this->success(
            $this->proxyverify->uploadAuthMaterial($aid, $appid, (string) $this->request->post('media', '')),
            '上传成功'
        );
    }

    /* ==================== 系统设置（三类代缴额度） ==================== */

    /**
     * 读取系统设置
     * GET /admin/wxproxyverify/set
     */
    public function set()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->proxyverify->getSet($aid));
    }

    /**
     * 保存系统设置
     * POST /admin/wxproxyverify/set body: {status, service_appid, third_party_phone, all/used/all2/used2/all3/used3}
     */
    public function setSave()
    {
        $aid  = ScopeService::aidOf($this->request);
        $info = $this->request->post();
        $this->proxyverify->saveSet($aid, is_array($info) ? $info : []);

        return $this->success([], '保存成功');
    }
}
