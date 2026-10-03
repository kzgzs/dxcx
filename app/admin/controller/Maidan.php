<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\MaidanService;
use app\common\service\ScopeService;
use util\ExcelUtil;

/**
 * 租户后台 - 买单收款（对标参考系统 Maidan）
 *
 * 四块能力：
 * - 买单扣费（手机号找会员→等级折扣→优惠券→积分抵扣→扣余额，余额支付）
 * - 买单记录（列表筛选 + 退款原路退回 + 导出）
 * - 聚合收款码设置（maidan_login/maidan_getlocation/maidan_payaftertourl + H5 买单页链接）
 */
class Maidan extends BaseController
{
    public function __construct(\think\App $app, protected MaidanService $maidanService)
    {
        parent::__construct($app);
    }

    /* ================= 买单扣费 ================= */

    /**
     * 手机号找会员（含等级/余额/积分，扣费页联动）
     * GET /admin/maidan/memberByTel?tel=
     */
    public function memberByTel()
    {
        $aid = ScopeService::aidOf($this->request);
        $tel = trim((string) $this->request->get('tel', ''));

        return $this->success($this->maidanService->memberByTel($aid, $tel));
    }

    /**
     * 会员可用优惠券（按消费金额过滤门槛）
     * GET /admin/maidan/coupons?mid=&money=
     */
    public function coupons()
    {
        $aid   = ScopeService::aidOf($this->request);
        $mid   = (int) $this->request->get('mid', 0);
        $money = (float) $this->request->get('money', 0);

        return $this->success(['list' => $this->maidanService->usableCoupons($aid, $mid, $money)]);
    }

    /**
     * 买单试算（折扣/券/积分/实付预览）
     * POST /admin/maidan/preview {tel, money, couponrid, usescore}
     */
    public function preview()
    {
        $aid = ScopeService::aidOf($this->request);

        $tel       = trim((string) $this->request->post('tel', ''));
        $money     = (float) $this->request->post('money', 0);
        $couponRid = (int) $this->request->post('couponrid', 0);
        $useScore  = (int) $this->request->post('usescore', 0) === 1;

        return $this->success($this->maidanService->preview($aid, $tel, $money, $couponRid, $useScore));
    }

    /**
     * 买单扣费（余额支付，事务内完成券/积分/余额扣减并落单）
     * POST /admin/maidan/add {tel, money, couponrid, usescore, mdid}
     */
    public function add()
    {
        $aid = ScopeService::aidOf($this->request);
        $uid = (int) (($this->request->authPayload ?? [])['uid'] ?? 0);

        $data = $this->request->post();
        $data = is_array($data) ? $data : [];

        $result = $this->maidanService->pay($aid, $uid, $data);

        return $this->success($result, '收款成功，实付：' . $result['paymoney']);
    }

    /* ================= 买单记录 ================= */

    /**
     * 买单记录列表
     * GET /admin/maidan/list?page&pagesize&ordernum&mid&mdid&status&nickname&starttime&endtime
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        $filters = [
            'ordernum'  => (string) $this->request->get('ordernum', ''),
            'mid'       => (int) $this->request->get('mid', 0),
            'mdid'      => (int) $this->request->get('mdid', 0),
            'status'    => (string) $this->request->get('status', ''),
            'nickname'  => (string) $this->request->get('nickname', ''),
            'starttime' => (string) $this->request->get('starttime', ''),
            'endtime'   => (string) $this->request->get('endtime', ''),
        ];

        return $this->success($this->maidanService->list($aid, $filters, $page, $pageSize));
    }

    /**
     * 买单退款（余额原路退回；全额退时返还积分并释放优惠券）
     * POST /admin/maidan/refund {id, money, reason}
     */
    public function refund()
    {
        $aid = ScopeService::aidOf($this->request);
        $uid = (int) (($this->request->authPayload ?? [])['uid'] ?? 0);

        $id     = (int) $this->request->post('id', 0);
        $money  = (float) $this->request->post('money', 0);
        $reason = trim((string) $this->request->post('reason', ''));

        if ($id <= 0) {
            throw new BusinessException('缺少订单ID');
        }

        $this->maidanService->refund($aid, $uid, $id, $money, $reason);

        return $this->success([], '退款成功');
    }

    /**
     * 买单记录导出（与列表同口径筛选，xlsx 附件下载）
     * GET /admin/maidan/export?ordernum&mid&mdid&status&nickname&starttime&endtime
     */
    public function export()
    {
        $aid = ScopeService::aidOf($this->request);

        set_time_limit(0);
        ini_set('memory_limit', '1024M');

        $filters = [
            'ordernum'  => (string) $this->request->get('ordernum', ''),
            'mid'       => (int) $this->request->get('mid', 0),
            'mdid'      => (int) $this->request->get('mdid', 0),
            'status'    => (string) $this->request->get('status', ''),
            'nickname'  => (string) $this->request->get('nickname', ''),
            'starttime' => (string) $this->request->get('starttime', ''),
            'endtime'   => (string) $this->request->get('endtime', ''),
        ];

        [$titles, $rows] = $this->maidanService->exportData($aid, $filters);

        ExcelUtil::export($titles, $rows, '买单记录_' . date('YmdHis'));
    }

    /**
     * 买单记录批量删除
     * POST /admin/maidan/logdel {ids: [id,...]}
     */
    public function logdel()
    {
        $aid = ScopeService::aidOf($this->request);

        $ids = $this->request->post('ids/a', []);
        $ids = array_map('intval', $ids);
        $ids = array_filter($ids, fn($v) => $v > 0);

        if (empty($ids)) {
            throw new BusinessException('请选择要删除的记录');
        }

        $this->maidanService->deleteByIds($aid, $ids);

        return $this->success([], '删除成功');
    }

    /* ================= 聚合收款码设置 ================= */

    /**
     * 收款码设置读取（三字段 + H5 买单页链接）
     * GET /admin/maidan/set
     */
    public function set()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->maidanService->setOf($aid));
    }

    /**
     * 收款码设置保存
     * POST /admin/maidan/saveSet {maidan_login, maidan_getlocation, maidan_payaftertourl, maidan_score_discount}
     */
    public function saveSet()
    {
        $aid = ScopeService::aidOf($this->request);

        $data = $this->request->post();
        $data = is_array($data) ? $data : [];

        $this->maidanService->saveSet($aid, $data);

        return $this->success([], '设置成功');
    }

    /**
     * 上传域名校验文件（微信txt/支付宝html）
     * POST /admin/maidan/uploadJstxt  (multipart: file)
     */
    public function uploadJstxt()
    {
        $file = $this->request->file('file');

        if (null === $file) {
            throw new BusinessException('请选择文件');
        }

        $fileArr = [
            'name'     => $file->getOriginalName(),
            'type'     => $file->getMime(),
            'tmp_name' => $file->getPathname(),
        ];

        $this->maidanService->uploadJstxt($fileArr);

        return $this->success([], '上传成功');
    }
}
