<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\PlogService;
use app\common\service\ScopeService;
use think\facade\Db;

/**
 * 租户后台 - 商户提现记录（批次8 任务221）
 *
 * 对标参考系统 BusinessMoney.php withdrawlog()/withdrawlogsetst()/withdrawlogdel()：
 * status：0=审核中 1=已审核 2=已驳回 3=已打款
 * 自动打款通道（微信转账/通联/支付宝）随支付域批次实现，本批落地人工审核链路
 */
class BusinessWithdrawLog extends BaseController
{
    /**
     * 提现记录列表
     * GET /admin/businessWithdrawLog/list?page=&pagesize=&bid=&name=&status=&start=&end=
     */
    public function list()
    {
        $aid      = ScopeService::aidOf($this->request);
        $page     = max(1, (int) $this->request->get('page', 1));
        $pagesize = min(100, max(1, (int) $this->request->get('pagesize', 10)));
        $bid      = trim((string) $this->request->get('bid', ''));
        $name     = trim((string) $this->request->get('name', ''));
        $status   = $this->request->get('status', '');
        $start    = trim((string) $this->request->get('start', ''));
        $end      = trim((string) $this->request->get('end', ''));

        $query = ScopeService::queryAs('business_withdrawlog', $aid, 'business_withdrawlog')
            ->join('business', 'business.id=business_withdrawlog.bid', 'left')
            ->field('business.name,business_withdrawlog.*');
        ScopeService::applyBid($query, $bid, 'business_withdrawlog.bid');
        if ('' !== $name) {
            $query->whereLike('business.name', '%' . $name . '%');
        }
        if ('' !== $status && $status !== null) {
            $query->where('business_withdrawlog.status', (int) $status);
        }
        if ('' !== $start) {
            $query->where('business_withdrawlog.createtime', '>=', strtotime($start));
        }
        if ('' !== $end) {
            $query->where('business_withdrawlog.createtime', '<', strtotime($end) + 86400);
        }

        $total = (clone $query)->count();
        $list  = $query->order('business_withdrawlog.id', 'desc')->page($page, $pagesize)->select()->toArray();

        foreach ($list as &$row) {
            $row['id']         = (int) $row['id'];
            $row['bid']        = (int) $row['bid'];
            $row['money']      = round((float) $row['money'], 2);
            $row['txmoney']    = round((float) $row['txmoney'], 2);
            $row['status']     = (int) $row['status'];
            $row['createtime'] = (int) $row['createtime'];
            $row['paytime']    = (int) ($row['paytime'] ?? 0);
            // 收款账号按提现方式拼展示串（参考系统导出口径）
            $row['account'] = $this->accountText($row);
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
     * 提现记录改状态（审核通过 st=1 / 驳回 st=2 / 标记已打款 st=3）
     * POST /admin/businessWithdrawLog/setst  {id, st, reason?}
     */
    public function setst()
    {
        $aid    = ScopeService::aidOf($this->request);
        $uid    = (int) ($this->request->authUid ?? 0);
        $id     = (int) $this->request->post('id', 0);
        $st     = (int) $this->request->post('st', 0);
        $reason = trim((string) $this->request->post('reason', ''));

        if ($id <= 0 || !in_array($st, [1, 2, 3], true)) {
            return $this->error('参数错误');
        }

        $info = ScopeService::query('business_withdrawlog', $aid)->where('id', $id)->find();
        if (empty($info)) {
            return $this->error('记录不存在');
        }

        if ($st === 1) {
            if ((int) $info['status'] !== 0) {
                return $this->error('仅审核中的记录可审核通过');
            }
            ScopeService::query('business_withdrawlog', $aid)->where('id', $id)->update(['status' => 1, 'reason' => '']);
            PlogService::write($aid, $uid, '商家提现审核通过' . $id);
            return $this->success([], '审核成功');
        }

        if ($st === 2) {
            if ((int) $info['status'] !== 0) {
                return $this->error('仅审核中的记录可驳回');
            }
            ScopeService::query('business_withdrawlog', $aid)->where('id', $id)->update(['status' => 2, 'reason' => $reason]);
            // 驳回返还余额（addmoney 提现金额 txmoney 回商户余额并记明细）
            $this->refundBusinessMoney($aid, (int) $info['bid'], (float) $info['txmoney'], '余额提现返还');
            PlogService::write($aid, $uid, '商家提现驳回' . $id);
            return $this->success([], '已驳回');
        }

        // st=3：已审核才能标记打款
        if ((int) $info['status'] !== 1) {
            return $this->error('已审核状态才能标记打款');
        }
        ScopeService::query('business_withdrawlog', $aid)->where('id', $id)->update([
            'status'  => 3,
            'paytime' => time(),
            'reason'  => $reason,
        ]);
        PlogService::write($aid, $uid, '商家提现标记打款' . $id);

        return $this->success([], '操作成功');
    }

    /**
     * 提现记录删除（批量）
     * POST /admin/businessWithdrawLog/delete  {ids:[]}
     */
    public function delete()
    {
        $aid = ScopeService::aidOf($this->request);
        $uid = (int) ($this->request->authUid ?? 0);
        $ids = array_map('intval', (array) $this->request->post('ids', []));

        if (empty($ids)) {
            return $this->error('无可操作的记录');
        }

        $list = ScopeService::query('business_withdrawlog', $aid)->whereIn('id', $ids)->select()->toArray();
        if (empty($list)) {
            return $this->error('无可操作的记录');
        }

        ScopeService::query('business_withdrawlog', $aid)->whereIn('id', $ids)->delete();
        PlogService::write($aid, $uid, '删除商户提现记录' . implode(',', $ids));

        return $this->success([], '删除成功');
    }

    /**
     * 商户余额变更（对标参考系统 \app\common\Business::addmoney 口径：
     * business.money 增减 + business_moneylog 落明细，支持负数扣减）
     */
    private function refundBusinessMoney(int $aid, int $bid, float $money, string $remark): void
    {
        if ($bid <= 0 || abs($money) < 0.005) {
            return;
        }

        Db::startTrans();
        try {
            $business = Db::name('business')->where('aid', $aid)->where('id', $bid)->lock(true)->find();
            if (empty($business)) {
                Db::rollback();
                return;
            }
            $after = round((float) $business['money'] + $money, 2);
            Db::name('business')->where('aid', $aid)->where('id', $bid)->update(['money' => $after]);
            Db::name('business_moneylog')->insert([
                'aid'        => $aid,
                'bid'        => $bid,
                'money'      => round($money, 2),
                'after'      => $after,
                'type'       => '提现返还',
                'ordernum'   => '',
                'remark'     => $remark,
                'createtime' => time(),
            ]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
        }
    }

    /** 收款账号展示串（按提现方式，参考系统导出口径） */
    private function accountText(array $row): string
    {
        $paytype = (string) ($row['paytype'] ?? '');
        if ($paytype === '支付宝') {
            return (string) ($row['aliaccount'] ?? '');
        }
        if ($paytype === '银行卡') {
            return trim(implode(' - ', array_filter([
                (string) ($row['bankname'] ?? ''),
                (string) ($row['bankcarduser'] ?? ''),
                (string) ($row['bankcardnum'] ?? ''),
            ])), ' -');
        }
        if ($paytype === '微信' || $paytype === '微信钱包') {
            return (string) ($row['weixin'] ?? '');
        }

        return '';
    }
}
