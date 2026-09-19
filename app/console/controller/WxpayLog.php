<?php

declare(strict_types=1);

namespace app\console\controller;

use app\BaseController;
use think\facade\Db;

/**
 * 超管控制台 - 微信支付记录（只读）
 *
 * 数据来源：pay_transaction 统一支付流水表
 * 字段说明：ordernum=商户订单号 transaction_num=微信交易号（支付流水号）
 *           paynum=微信支付回执单号 paytypeid=支付方式 type=业务类型
 *           status: 0待支付 1已支付(成功) 2已取消(失败)
 */
class WxpayLog extends BaseController
{
    /** 支付方式映射（paytypeid） */
    private const PAY_TYPE_MAP = [
        1  => '余额支付',
        2  => '微信支付',
        3  => '支付宝支付',
        4  => '货到付款',
        5  => '转账汇款',
        11 => '百度小程序支付',
        12 => '头条小程序支付',
    ];

    /**
     * 支付流水列表（筛选 + 分页）
     * GET /console/wxpaylog/list
     * 参数：page pagesize ordernum transaction_num status paytypeid start_date end_date
     */
    public function list()
    {
        $page     = max(1, (int) $this->request->param('page', 1));
        $pagesize = (int) $this->request->param('pagesize', 20);
        $pagesize = max(1, min(100, $pagesize));

        $query = Db::name('pay_transaction');

        // 商户订单号（模糊）
        $ordernum = trim((string) $this->request->param('ordernum', ''));
        if ($ordernum !== '') {
            $query->whereLike('ordernum', '%' . $ordernum . '%');
        }

        // 微信交易号（模糊）
        $transactionNum = trim((string) $this->request->param('transaction_num', ''));
        if ($transactionNum !== '') {
            $query->whereLike('transaction_num', '%' . $transactionNum . '%');
        }

        // 状态：0待支付 1成功 2失败
        $status = $this->request->param('status', '');
        if ($status !== '' && $status !== null && in_array((int) $status, [0, 1, 2], true)) {
            $query->where('status', (int) $status);
        }

        // 支付方式（paytypeid）
        $paytypeid = $this->request->param('paytypeid', '');
        if ($paytypeid !== '' && $paytypeid !== null) {
            $query->where('paytypeid', (int) $paytypeid);
        }

        // 时间范围（按下单时间 createtime，含端点日全天）
        $startDate = trim((string) $this->request->param('start_date', ''));
        if ($startDate !== '') {
            $ts = strtotime($startDate);
            if ($ts !== false) {
                $query->where('createtime', '>=', $ts);
            }
        }
        $endDate = trim((string) $this->request->param('end_date', ''));
        if ($endDate !== '') {
            $ts = strtotime($endDate . ' 23:59:59');
            if ($ts !== false) {
                $query->where('createtime', '<=', $ts);
            }
        }

        $total = (clone $query)->count();
        $rows  = $query
            ->field('id,aid,ordernum,transaction_num,paynum,title,money,score,type,status,paytypeid,paytype,platform,createtime,paytime,refund_money,refund_time')
            ->order('id', 'desc')
            ->page($page, $pagesize)
            ->select()
            ->toArray();

        // 补齐商户（租户）名称
        $aids = array_values(array_unique(array_filter(array_map(static function ($row) {
            return (int) $row['aid'];
        }, $rows))));

        $tenantNames = [];
        if (!empty($aids)) {
            $tenantNames = Db::name('admin')
                ->whereIn('id', $aids)
                ->column('company', 'id');
        }

        $list = array_map(static function ($row) use ($tenantNames) {
            $aid               = (int) $row['aid'];
            $tenantName        = (string) ($tenantNames[$aid] ?? '');
            $row['tenant']     = $tenantName !== '' ? $tenantName : ('租户#' . $aid);
            $row['money']      = number_format((float) $row['money'], 2, '.', '');
            $row['refund_money'] = number_format((float) $row['refund_money'], 2, '.', '');
            $row['status']     = (int) $row['status'];
            $row['paytypeid']  = (int) $row['paytypeid'];
            $row['paytype_text'] = self::PAY_TYPE_MAP[(int) $row['paytypeid']] ?? '其他';
            $row['createtime'] = (int) $row['createtime'];
            $row['paytime']    = (int) $row['paytime'];
            $row['refund_time'] = (int) $row['refund_time'];

            return $row;
        }, $rows);

        return $this->success([
            'list'  => $list,
            'total' => (int) $total,
        ]);
    }

    /**
     * 流水详情（完整字段，只读）
     * GET /console/wxpaylog/detail?id=xx
     */
    public function detail()
    {
        $id = (int) $this->request->param('id', 0);
        if ($id <= 0) {
            return $this->error('参数错误');
        }

        $row = Db::name('pay_transaction')->where('id', $id)->find();
        if (empty($row)) {
            return $this->error('流水记录不存在');
        }

        $tenantName = Db::name('admin')->where('id', (int) $row['aid'])->value('company');

        $row['tenant']       = (string) ($tenantName ?: ('租户#' . (int) $row['aid']));
        $row['money']        = number_format((float) $row['money'], 2, '.', '');
        $row['refund_money'] = number_format((float) $row['refund_money'], 2, '.', '');
        $row['status']       = (int) $row['status'];
        $row['paytypeid']    = (int) $row['paytypeid'];
        $row['paytype_text'] = self::PAY_TYPE_MAP[(int) $row['paytypeid']] ?? '其他';

        return $this->success($row);
    }
}
