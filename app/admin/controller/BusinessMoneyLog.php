<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\PlogService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 商户余额明细（批次8 任务221）
 *
 * 对标参考系统 BusinessMoney.php moneylog()/moneylogdel()：
 * business_moneylog 连 business 取商户名，支持商户/商户名/时间/备注过滤
 */
class BusinessMoneyLog extends BaseController
{
    /**
     * 余额明细列表
     * GET /admin/businessMoneyLog/list?page=&pagesize=&bid=&name=&remark=&start=&end=
     */
    public function list()
    {
        $aid      = ScopeService::aidOf($this->request);
        $page     = max(1, (int) $this->request->get('page', 1));
        $pagesize = min(100, max(1, (int) $this->request->get('pagesize', 10)));
        $bid      = trim((string) $this->request->get('bid', ''));
        $name     = trim((string) $this->request->get('name', ''));
        $remark   = trim((string) $this->request->get('remark', ''));
        $start    = trim((string) $this->request->get('start', ''));
        $end      = trim((string) $this->request->get('end', ''));

        $query = ScopeService::queryAs('business_moneylog', $aid, 'business_moneylog')
            ->join('business', 'business.id=business_moneylog.bid', 'left')
            ->field('business.name,business_moneylog.*');
        ScopeService::applyBid($query, $bid, 'business_moneylog.bid');
        if ('' !== $name) {
            $query->whereLike('business.name', '%' . $name . '%');
        }
        if ('' !== $remark) {
            $query->whereLike('business_moneylog.remark', '%' . $remark . '%');
        }
        if ('' !== $start) {
            $query->where('business_moneylog.createtime', '>=', strtotime($start));
        }
        if ('' !== $end) {
            $query->where('business_moneylog.createtime', '<', strtotime($end) + 86400);
        }

        $total = (clone $query)->count();
        $list  = $query->order('business_moneylog.id', 'desc')->page($page, $pagesize)->select()->toArray();

        foreach ($list as &$row) {
            $row['id']         = (int) $row['id'];
            $row['bid']        = (int) $row['bid'];
            $row['money']      = round((float) $row['money'], 2);
            $row['after']      = round((float) $row['after'], 2);
            $row['createtime'] = (int) $row['createtime'];
        }
        unset($row);

        // 商户下拉（过滤用）
        $bnameArr = ScopeService::query('business', $aid)->column('name', 'id');
        $businessOptions = [];
        foreach ($bnameArr as $bId => $bName) {
            $businessOptions[] = ['id' => (int) $bId, 'name' => (string) $bName];
        }

        return $this->success(['list' => $list, 'total' => $total, 'business_options' => $businessOptions]);
    }

    /**
     * 余额明细删除（批量）
     * POST /admin/businessMoneyLog/delete  {ids:[]}
     */
    public function delete()
    {
        $aid = ScopeService::aidOf($this->request);
        $uid = (int) ($this->request->authUid ?? 0);
        $ids = array_map('intval', (array) $this->request->post('ids', []));

        if (empty($ids)) {
            return $this->error('无可操作的记录');
        }

        $list = ScopeService::query('business_moneylog', $aid)->whereIn('id', $ids)->select()->toArray();
        if (empty($list)) {
            return $this->error('无可操作的记录');
        }

        ScopeService::query('business_moneylog', $aid)->whereIn('id', $ids)->delete();
        PlogService::write($aid, $uid, '删除商户余额明细' . implode(',', $ids));

        return $this->success([], '删除成功');
    }
}
