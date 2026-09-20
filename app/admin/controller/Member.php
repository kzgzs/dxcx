<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\MemberAdminService;
use app\common\service\ScopeService;
use util\ExcelUtil;

/**
 * 租户后台 - 会员管理（admin 应用，JWT role=admin，aid 走 ScopeService）
 */
class Member extends BaseController
{
    public function __construct(\think\App $app, protected MemberAdminService $memberService)
    {
        parent::__construct($app);
    }

    /**
     * 会员列表（对齐点大）
     * GET /admin/member/list?page&pagesize&id&keyword&pid&levelid&hascard&start_time&end_time&field&order
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->memberService->list($aid, $this->request->get()));
    }

    /**
     * 会员详情（基本信息+各计数）
     * GET /admin/member/detail?id=
     */
    public function detail()
    {
        $aid = ScopeService::aidOf($this->request);
        $mid = (int) $this->request->get('id', 0);

        return $this->success($this->memberService->detail($aid, $mid));
    }

    /**
     * 编辑会员（昵称/手机/等级/等级到期/备注；等级变更写变动记录）
     * POST /admin/member/edit {id, nickname, tel, levelid, levelendtime, remark}
     */
    public function edit()
    {
        $aid = ScopeService::aidOf($this->request);
        $mid = (int) $this->request->post('id', 0);

        $this->memberService->edit($aid, $mid, $this->request->post());

        return $this->success([], '保存成功');
    }

    /**
     * 余额充值/扣费（money 正=充值 负=扣款；事务校验扣款后余额不为负）
     * POST /admin/member/recharge {id, money, remark, paytype}
     */
    public function recharge()
    {
        $aid = ScopeService::aidOf($this->request);

        $mid     = (int) $this->request->post('id', 0);
        $money   = (float) $this->request->post('money', 0);
        $remark  = trim((string) $this->request->post('remark', ''));
        $paytype = trim((string) $this->request->post('paytype', 'admin'));

        $result = $this->memberService->recharge($aid, $mid, $money, $remark, (int) ($this->request->authUid ?? 0), $paytype);

        return $this->success($result, $money > 0 ? '充值成功' : '扣费成功');
    }

    /**
     * 积分调整（score 正负整数；事务写流水）
     * POST /admin/member/addscore {id, score, remark}
     */
    public function addscore()
    {
        $aid = ScopeService::aidOf($this->request);

        $mid    = (int) $this->request->post('id', 0);
        $score  = (int) $this->request->post('score', 0);
        $remark = trim((string) $this->request->post('remark', ''));

        $result = $this->memberService->addScore($aid, $mid, $score, $remark, (int) ($this->request->authUid ?? 0));

        return $this->success($result, '调整成功');
    }

    /**
     * 加/扣佣金（commission 正=增加 负=扣除；事务写佣金流水）
     * POST /admin/member/addcommission {id, commission, remark}
     */
    public function addcommission()
    {
        $aid = ScopeService::aidOf($this->request);

        $mid        = (int) $this->request->post('id', 0);
        $commission = (float) $this->request->post('commission', 0);
        $remark     = trim((string) $this->request->post('remark', ''));

        $result = $this->memberService->addCommission($aid, $mid, $commission, $remark, (int) ($this->request->authUid ?? 0));

        return $this->success($result, $commission > 0 ? '加佣金成功' : '扣佣金成功');
    }

    /**
     * 批量删除会员（物理删除会员主记录，业务凭证保留）
     * POST /admin/member/del {ids:[...]}
     */
    public function del()
    {
        $aid = ScopeService::aidOf($this->request);

        $ids = $this->request->post('ids', []);
        if (!is_array($ids)) {
            $ids = $ids === null ? [] : [$ids];
        }

        $count = $this->memberService->deleteMembers($aid, $ids);

        return $this->success(['count' => $count], '删除成功');
    }

    /**
     * 会员列表导出
     * GET /admin/member/excel（筛选同 list）
     */
    public function excel()
    {
        $aid = ScopeService::aidOf($this->request);

        [$titles, $rows] = $this->memberService->memberExport($aid, $this->request->get());

        ExcelUtil::export($titles, $rows, '会员列表_' . date('YmdHis'));
    }

    /**
     * 冻结/解冻（切换 isfreeze）
     * POST /admin/member/freeze {id}
     */
    public function freeze()
    {
        $aid = ScopeService::aidOf($this->request);
        $mid = (int) $this->request->post('id', 0);

        return $this->success($this->memberService->freeze($aid, $mid), '操作成功');
    }

    /**
     * 锁定/解锁（切换 islock）
     * POST /admin/member/lock {id}
     */
    public function lock()
    {
        $aid = ScopeService::aidOf($this->request);
        $mid = (int) $this->request->post('id', 0);

        return $this->success($this->memberService->lock($aid, $mid), '操作成功');
    }

    /**
     * 余额明细（member_moneylog）
     * GET /admin/member/moneylog?mid=&page=&pagesize=
     */
    public function moneylog()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->memberService->moneyLog(
            $aid,
            (int) $this->request->get('mid', 0),
            max(1, (int) $this->request->get('page', 1)),
            $this->pageSizeOf()
        ));
    }

    /**
     * 积分明细（member_scorelog）
     * GET /admin/member/scorelog?mid=&page=&pagesize=
     */
    public function scorelog()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->memberService->scoreLog(
            $aid,
            (int) $this->request->get('mid', 0),
            max(1, (int) $this->request->get('page', 1)),
            $this->pageSizeOf()
        ));
    }

    /**
     * 会员订单
     * GET /admin/member/orders?mid=&page=&pagesize=
     */
    public function orders()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->memberService->orders(
            $aid,
            (int) $this->request->get('mid', 0),
            max(1, (int) $this->request->get('page', 1)),
            $this->pageSizeOf()
        ));
    }

    /**
     * 会员-商城订单（平铺页，对齐点大 ShopOrder/index/mid）
     * GET /admin/member/shopOrders?mid=&page&pagesize&status&ordernum&freight_type&min_price&max_price&start_time&end_time
     */
    public function shopOrders()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->memberService->shopOrders(
            $aid,
            (int) $this->request->get('mid', 0),
            $this->request->get()
        ));
    }

    /**
     * 会员-商城订单导出
     */
    public function shopOrdersExcel()
    {
        $aid = ScopeService::aidOf($this->request);
        $f   = $this->request->get();
        $f['pagesize'] = 50000;
        $f['page']     = 1;
        $data = $this->memberService->shopOrders($aid, (int) ($f['mid'] ?? 0), $f);

        $titles = ['ID', '订单号', '商品', '商品总价', '实付款', '支付方式', '收货人', '收货地址', '配送方式', '状态', '下单时间'];
        $statusMap = [0 => '未支付', 1 => '待发货', 2 => '已发货', 3 => '已完成', 4 => '已关闭'];
        $rows = [];
        foreach ($data['list'] as $v) {
            $goods = implode('、', array_map(static fn ($g) => $g['name'] . ($g['ggname'] ? '(' . $g['ggname'] . ')' : ''), $v['goods']));
            $rows[] = [
                $v['id'], $v['ordernum'] ?: $v['sysOrderNo'], $goods,
                $v['product_price'], $v['totalprice'], $v['paytype'],
                $v['linkman'], $v['address'], $v['freight_text'],
                $statusMap[$v['status']] ?? '-',
                $v['createtime'] ? date('Y-m-d H:i:s', $v['createtime']) : '',
            ];
        }

        ExcelUtil::export($titles, $rows, '会员商城订单_' . date('YmdHis'));
    }

    /**
     * 会员-优惠券领取记录（平铺页，对齐点大 Coupon/record/mid）
     * GET /admin/member/coupons?mid=&page&pagesize&status&remark&start_time&end_time
     */
    public function coupons()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->memberService->memberCoupons(
            $aid,
            (int) $this->request->get('mid', 0),
            $this->request->get()
        ));
    }

    /**
     * 会员-优惠券改状态（0未使用 / 1已使用）
     * POST /admin/member/couponSetSt {mid, ids:[], status}
     */
    public function couponSetSt()
    {
        $aid = ScopeService::aidOf($this->request);

        $ids = $this->request->post('ids', []);
        if (!is_array($ids)) {
            $ids = $ids === null ? [] : [$ids];
        }
        $this->memberService->couponSetStatus(
            $aid,
            (int) $this->request->post('mid', 0),
            $ids,
            (int) $this->request->post('status', 0)
        );

        return $this->success([], '操作成功');
    }

    /**
     * 会员-优惠券删除
     * POST /admin/member/couponDel {mid, ids:[]}
     */
    public function couponDel()
    {
        $aid = ScopeService::aidOf($this->request);

        $ids = $this->request->post('ids', []);
        if (!is_array($ids)) {
            $ids = $ids === null ? [] : [$ids];
        }
        $count = $this->memberService->couponDelete($aid, (int) $this->request->post('mid', 0), $ids);

        return $this->success(['count' => $count], '删除成功');
    }

    /**
     * 会员-优惠券导出
     */
    public function couponsExcel()
    {
        $aid = ScopeService::aidOf($this->request);
        $f   = $this->request->get();
        $f['pagesize'] = 50000;
        $f['page']     = 1;
        $data = $this->memberService->memberCoupons($aid, (int) ($f['mid'] ?? 0), $f);

        $titles = ['ID', '所属商户', '优惠券名称', '面值', '领取时间', '到期时间', '总次数', '已使用', '使用时间', '状态', '备注'];
        $rows = [];
        foreach ($data['list'] as $v) {
            $rows[] = [
                $v['id'], $v['business_name'] ?: '平台自营', $v['couponname'], $v['money'],
                $v['createtime'] ? date('Y-m-d H:i:s', $v['createtime']) : '',
                $v['endtime'] ? date('Y-m-d H:i:s', $v['endtime']) : '',
                $v['limit_count'], $v['used_count'],
                $v['usetime'] ? date('Y-m-d H:i:s', $v['usetime']) : '',
                $v['status'] ? '已使用' : '未使用',
                $v['remark'],
            ];
        }

        ExcelUtil::export($titles, $rows, '会员优惠券_' . date('YmdHis'));
    }

    /**
     * 会员-分销订单（佣金订单记录，该会员作为分销员 r.mid）
     * GET /admin/member/fenxiaoOrders?mid(=分销员)&page&pagesize&buy_mid(下单人)&status&type&orderid&ordernum&start_time&end_time
     */
    public function fenxiaoOrders()
    {
        $aid = ScopeService::aidOf($this->request);

        $f       = $this->request->get();
        $distMid = (int) ($f['mid'] ?? 0);   // 分销员（佣金获得者，当前会员）
        unset($f['mid']);                     // 避免被服务层误当成“下单人”筛选
        // 下单人筛选字段 buy_mid 映射到服务层的 mid（r.frommid）
        if (isset($f['buy_mid']) && $f['buy_mid'] !== '') {
            $f['mid'] = (int) $f['buy_mid'];
        }

        return $this->success($this->memberService->fenxiaoOrders($aid, $distMid, $f));
    }

    /**
     * 会员-分销订单导出
     */
    public function fenxiaoExcel()
    {
        $aid     = ScopeService::aidOf($this->request);
        $f       = $this->request->get();
        $distMid = (int) ($f['mid'] ?? 0);   // 分销员（须在剥离前取）
        unset($f['mid']);
        $f['pagesize'] = 50000;
        $f['page']     = 1;
        if (isset($f['buy_mid']) && $f['buy_mid'] !== '') {
            $f['mid'] = (int) $f['buy_mid'];
        }
        $data = $this->memberService->fenxiaoOrders($aid, $distMid, $f);

        $titles = ['ID', '订单ID', '商家', '订单类型', '会员ID', '商品', '订单号', '下单时间', '分销层级', '佣金', '积分', '状态', '备注'];
        $rows = [];
        foreach ($data['list'] as $v) {
            $rows[] = [
                $v['id'], $v['orderid'], $v['business_name'] ?: '平台自营', $v['type_text'],
                $v['mid'],
                $v['goods_name'] . ($v['goods_gg'] ? '(' . $v['goods_gg'] . ')' : ''),
                $v['ordernum'],
                $v['order_createtime'] ? date('Y-m-d H:i:s', $v['order_createtime']) : '',
                $v['level'] ?: '-', $v['commission'], $v['score'], $v['status_text'], $v['remark'],
            ];
        }

        ExcelUtil::export($titles, $rows, '会员分销订单_' . date('YmdHis'));
    }

    /**
     * 收货地址
     * GET /admin/member/address?mid=&page=&pagesize=
     */
    public function address()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->memberService->address(
            $aid,
            (int) $this->request->get('mid', 0),
            max(1, (int) $this->request->get('page', 1)),
            $this->pageSizeOf()
        ));
    }

    /**
     * 等级列表
     * GET /admin/member/level/list
     */
    public function levelList()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->memberService->levelList($aid));
    }

    /**
     * 等级详情（编辑回读全字段：升级条件/分销/分红/赠送，P19-2）
     * GET /admin/member/level/detail?id=
     */
    public function levelDetail()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->memberService->levelDetail($aid, (int) $this->request->get('id', 0)));
    }

    /**
     * 等级新增/编辑
     * POST /admin/member/level/save
     */
    public function levelSave()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->memberService->levelSave($aid, $this->request->post());

        return $this->success([], '保存成功');
    }

    /**
     * 区域代理归属记录列表（member_level_record 按等级）
     * GET /admin/member/level/record/list?levelid=
     */
    public function levelRecordList()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->memberService->levelRecordList($aid, (int) $this->request->get('levelid', 0)));
    }

    /**
     * 区域代理归属保存（省市区 + 开关/比例）
     * POST /admin/member/level/record/save {id, areafenhong, areafenhongbl, areafenhong_province, areafenhong_city, areafenhong_area}
     */
    public function levelRecordSave()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->memberService->levelRecordSave($aid, $this->request->post());

        return $this->success([], '保存成功');
    }

    /**
     * 等级删除（有会员占用时拒绝）
     * POST /admin/member/level/delete {id}
     */
    public function levelDelete()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->memberService->levelDelete($aid, (int) $this->request->post('id', 0));

        return $this->success([], '删除成功');
    }

    /**
     * 分页大小（默认 10，上限 100）
     */
    protected function pageSizeOf(): int
    {
        $pageSize = (int) $this->request->get('pagesize', 10);

        return $pageSize > 0 ? min($pageSize, 100) : 10;
    }
}
