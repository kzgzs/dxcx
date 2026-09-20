<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\PlogService;
use app\common\service\ScopeService;
use think\facade\Db;
use util\ExcelUtil;

/**
 * 租户后台 - 商户提现记录（批次8 任务221）
 *
 * 对标参考系统 BusinessMoney.php withdrawlog()/withdrawlogsetst()/withdrawlogdel()/withdrawlogexcel()：
 * status：0=审核中 1=已审核 2=已驳回 3=已打款
 * txmoney=申请提现扣减额、money=手续费后实际打款额，驳回返还 txmoney。
 * 已审核(status=1)未打款前仍可驳回（对齐点大）；自动打款通道（微信转账/通联/支付宝）
 * 随支付域批次实现，本批落地人工审核 + 人工标记打款链路。
 */
class BusinessWithdrawLog extends BaseController
{
    /** 列表排序字段白名单（防注入），默认 id desc */
    private const SORT_FIELDS = ['id', 'money', 'txmoney', 'createtime'];

    /** 状态文案（导出用） */
    private const STATUS_TEXT = [0 => '审核中', 1 => '已审核', 2 => '已驳回', 3 => '已打款'];

    /**
     * 提现记录列表
     * GET /admin/businessWithdrawLog/list?page=&pagesize=&bid=&name=&status=&start=&end=&field=&order=
     */
    public function list()
    {
        $aid      = ScopeService::aidOf($this->request);
        $page     = max(1, (int) $this->request->get('page', 1));
        $pagesize = min(100, max(1, (int) $this->request->get('pagesize', 10)));

        $query = $this->buildQuery($aid);

        $total = (clone $query)->count();
        $list  = $query->order(...$this->sortParam())->page($page, $pagesize)->select()->toArray();

        foreach ($list as &$row) {
            $row['id']          = (int) $row['id'];
            $row['bid']         = (int) $row['bid'];
            $row['money']       = round((float) $row['money'], 2);
            $row['txmoney']     = round((float) $row['txmoney'], 2);
            $row['status']      = (int) $row['status'];
            $row['createtime']  = (int) $row['createtime'];
            $row['paytime']     = (int) ($row['paytime'] ?? 0);
            $row['shop_weixin'] = (string) ($row['shop_weixin'] ?? '');
            $row['platform']    = (string) ($row['platform'] ?? '');
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
     * 提现记录导出 EXCEL（对照参考系统 withdrawlogexcel，沿用当前筛选条件）
     * GET /admin/businessWithdrawLog/excel?bid=&name=&status=&start=&end=
     */
    public function excel()
    {
        set_time_limit(0);
        ini_set('memory_limit', '1024M');
        $aid = ScopeService::aidOf($this->request);

        $rows = $this->buildQuery($aid)->order('business_withdrawlog.id', 'desc')->limit(10000)->select()->toArray();

        $titles = ['ID', '商户ID', '商户名称', '店主微信', '提现金额', '打款金额', '提现方式', '收款账号', '操作平台', '状态', '驳回原因', '申请时间', '打款时间'];
        $dataRows = [];
        foreach ($rows as $row) {
            $dataRows[] = [
                (int) $row['id'],
                (int) $row['bid'],
                (string) ($row['name'] ?? ''),
                (string) ($row['shop_weixin'] ?? $row['weixin'] ?? ''),
                round((float) $row['txmoney'], 2),
                round((float) $row['money'], 2),
                (string) ($row['paytype'] ?? ''),
                $this->accountText($row),
                (string) ($row['platform'] ?? ''),
                self::STATUS_TEXT[(int) ($row['status'] ?? 0)] ?? '未知',
                (string) ($row['reason'] ?? ''),
                !empty($row['createtime']) ? date('Y-m-d H:i:s', (int) $row['createtime']) : '',
                !empty($row['paytime']) ? date('Y-m-d H:i:s', (int) $row['paytime']) : '',
            ];
        }

        ExcelUtil::export($titles, $dataRows, '商户提现记录_' . date('YmdHis'));
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

        $curStatus = (int) $info['status'];

        if ($st === 1) {
            if ($curStatus !== 0) {
                return $this->error('仅审核中的记录可审核通过');
            }
            ScopeService::query('business_withdrawlog', $aid)->where('id', $id)->update(['status' => 1, 'reason' => '']);
            PlogService::write($aid, $uid, '商家提现审核通过' . $id);
            return $this->success([], '审核成功');
        }

        if ($st === 2) {
            // 对齐点大：审核中(0)、已审核未打款(1) 均可驳回；驳回返还申请扣减额 txmoney
            if (!in_array($curStatus, [0, 1], true)) {
                return $this->error('仅审核中或已审核（未打款）的记录可驳回');
            }
            ScopeService::query('business_withdrawlog', $aid)->where('id', $id)->update(['status' => 2, 'reason' => $reason]);
            // 驳回返还余额（addmoney 提现金额 txmoney 回商户余额并记明细）
            $this->refundBusinessMoney($aid, (int) $info['bid'], (float) $info['txmoney'], '余额提现返还');
            PlogService::write($aid, $uid, '商家提现驳回' . $id);
            return $this->success([], '已驳回');
        }

        // st=3：已审核才能标记打款
        if ($curStatus !== 1) {
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
     * 组装列表/导出共用查询（连 business 取名与店主微信，含全部过滤条件）
     */
    private function buildQuery(int $aid)
    {
        $bid    = trim((string) $this->request->get('bid', ''));
        $name   = trim((string) $this->request->get('name', ''));
        $status = $this->request->get('status', '');
        $start  = trim((string) $this->request->get('start', ''));
        $end    = trim((string) $this->request->get('end', ''));

        $query = ScopeService::queryAs('business_withdrawlog', $aid, 'business_withdrawlog')
            ->join('business', 'business.id=business_withdrawlog.bid', 'left')
            ->field('business.name,business.weixin as shop_weixin,business_withdrawlog.*');
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

        return $query;
    }

    /**
     * 排序参数（白名单字段 + asc/desc），返回 [字段, 方向]
     * @return array{0:string,1:string}
     */
    private function sortParam(): array
    {
        $field = (string) $this->request->get('field', 'id');
        $order = strtolower((string) $this->request->get('order', 'desc')) === 'asc' ? 'asc' : 'desc';
        if (!in_array($field, self::SORT_FIELDS, true)) {
            $field = 'id';
        }

        return ['business_withdrawlog.' . $field, $order];
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
