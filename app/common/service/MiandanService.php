<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use app\common\Kdniao;
use think\facade\Db;

/**
 * 物流助手服务（对标参考系统 Miandan：微信物流助手运单管理）
 *
 * 能力：运单列表/查询运单（打印数据）/查询轨迹/取消运单/删除/物流助手设置/物流账号列表
 *
 * mock 语义（.env EWAYBILL.MOCK=1，与电子面单共用开关）：
 * - 查询运单：返回占位打印 HTML；查询轨迹：返回固定 mock 轨迹链
 * - 取消运单：本地直接置为已取消（status=4），不调微信接口
 * 真实模式（EWAYBILL.MOCK=0）：微信物流助手接口位（TODO 真实对接，本期留桩）
 */
class MiandanService
{
    /** 控制台为平台/自营维度，固定 bid=0 */
    private const BID = 0;

    /** 状态字典（对标参考系统：0待处理 1正常 4已取消） */
    public const STATUS_MAP = [0 => '待处理', 1 => '正常', 4 => '已取消'];

    /**
     * 快递公司下拉字典（对标微信物流助手 delivery/getall；真实模式由微信接口返回，
     * mock/离线模式用此固定字典，覆盖截图默认「安能物流 ANN」与列表「圆通速递 YTO」）
     */
    public const DELIVERY_OPTIONS = [
        ['delivery_id' => 'SF',   'delivery_name' => '顺丰速运'],
        ['delivery_id' => 'YTO',  'delivery_name' => '圆通速递'],
        ['delivery_id' => 'ZTO',  'delivery_name' => '中通快递'],
        ['delivery_id' => 'STO',  'delivery_name' => '申通快递'],
        ['delivery_id' => 'YD',   'delivery_name' => '韵达速递'],
        ['delivery_id' => 'JD',   'delivery_name' => '京东快递'],
        ['delivery_id' => 'DB',   'delivery_name' => '德邦快递'],
        ['delivery_id' => 'JTSD', 'delivery_name' => '极兔速递'],
        ['delivery_id' => 'EMS',  'delivery_name' => '中国邮政EMS'],
        ['delivery_id' => 'ZJS',  'delivery_name' => '宅急送'],
        ['delivery_id' => 'HTKY',  'delivery_name' => '百世快递'],
        ['delivery_id' => 'ANN',  'delivery_name' => '安能物流'],
        ['delivery_id' => 'FAST', 'delivery_name' => '快捷快递'],
        ['delivery_id' => 'ZTKY', 'delivery_name' => '中铁快运'],
        ['delivery_id' => 'BEST', 'delivery_name' => '百世快运'],
    ];

    /** 绑定状态字典（对标参考系统 myaccount status_code 渲染口径） */
    public const ACCOUNT_STATUS_MAP = [0 => '绑定成功', 1 => '审核中', 2 => '绑定失败', 3 => '已解绑'];

    public function isMock(): bool
    {
        return Kdniao::isMock();
    }

    /**
     * 运单列表
     *
     * @param array $filters order_id/waybill_id/name/tel/status/starttime/endtime
     * @return array{list: array, total: int, page: int, pagesize: int, is_mock: bool}
     */
    public function list(int $aid, array $filters, int $page, int $pageSize): array
    {
        // miandan_order 表无 bid 列，租户隔离仅按 aid（任务234 Bug1 修复）
        $query = ScopeService::query('miandan_order', $aid)
            ->where('status', '<>', 0);

        if ('' !== trim((string) ($filters['order_id'] ?? ''))) {
            $query->where('order_id', (string) $filters['order_id']);
        }
        if ('' !== trim((string) ($filters['waybill_id'] ?? ''))) {
            $query->where('waybill_id', (string) $filters['waybill_id']);
        }
        if ('' !== trim((string) ($filters['name'] ?? ''))) {
            $name = (string) $filters['name'];
            $query->where(function ($q) use ($name) {
                $q->whereLike('receiver_name', '%' . $name . '%')
                    ->whereOr('receiver_mobile', 'like', '%' . $name . '%')
                    ->whereOr('receiver_tel', 'like', '%' . $name . '%');
            });
        }
        if ('' !== trim((string) ($filters['tel'] ?? ''))) {
            $tel = (string) $filters['tel'];
            $query->where(function ($q) use ($tel) {
                $q->whereLike('receiver_mobile', '%' . $tel . '%')
                    ->whereOr('receiver_tel', 'like', '%' . $tel . '%');
            });
        }
        $status = (string) ($filters['status'] ?? '');
        if ('' !== $status) {
            $query->where('status', (int) $status);
        }
        if ('' !== trim((string) ($filters['starttime'] ?? ''))) {
            $query->where('createtime', '>=', strtotime((string) $filters['starttime']));
        }
        if ('' !== trim((string) ($filters['endtime'] ?? ''))) {
            $query->where('createtime', '<', strtotime((string) $filters['endtime']) + 86400);
        }

        $total = (clone $query)->count();

        // 排序（白名单：ID / 创建时间；默认 id desc，对标参考系统 sort）
        $sortField = (string) ($filters['sort_field'] ?? '');
        $sortOrder = strtolower((string) ($filters['sort_order'] ?? 'desc'));
        $sortField = in_array($sortField, ['id', 'createtime'], true) ? $sortField : 'id';
        $sortOrder = ('asc' === $sortOrder) ? 'asc' : 'desc';

        $rows  = $query->order($sortField, $sortOrder)
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        foreach ($rows as &$row) {
            $row['status_name']    = self::STATUS_MAP[(int) $row['status']] ?? '未知';
            $row['createtime_text'] = $row['createtime'] ? date('Y-m-d H:i:s', (int) $row['createtime']) : '';
        }
        unset($row);

        return [
            'list'     => $rows,
            'total'    => $total,
            'page'     => $page,
            'pagesize' => $pageSize,
            'is_mock'  => $this->isMock(),
        ];
    }

    /**
     * 查询运单（取打印数据，对标参考系统 getorder）
     *
     * @return array{order_status: int, waybill_id: string, print_html: string, print_type: int, is_mock: bool}
     */
    public function getOrder(int $aid, string $orderId, string $deliveryId, string $waybillId): array
    {
        if ('' === $orderId || '' === $deliveryId) {
            throw new BusinessException('缺少运单参数');
        }

        $set = $this->setOf($aid);
        // 顺丰不支持一联单，自动切换为二联单（对标参考系统口径）
        $printType = ('SF' === $deliveryId) ? 0 : (int) ($set['print_type'] ?? 0);

        // 取运单真实落库数据（mock 也用真实订单信息渲染面单）
        $order = ScopeService::query('miandan_order', $aid)
            ->where('order_id', $orderId)
            ->where('delivery_id', $deliveryId)
            ->find();

        $receiverName = $order['receiver_name'] ?? '';
        $receiverMobile = $order['receiver_mobile'] ?: $order['receiver_tel'];
        $receiverArea = trim(($order['receiver_province'] ?? '') . ($order['receiver_city'] ?? '') . ($order['receiver_area'] ?? ''));
        $receiverAddress = $order['receiver_address'] ?? '';
        $deliveryName = $order['delivery_name'] ?? $deliveryId;
        $waybillIdReal = $order['waybill_id'] ?? $waybillId;
        $shopGoodsName = $order['shop_goods_name'] ?? '';

        // 手机号中间四位脱敏（对齐截图 156****6666）
        $maskMobile = '';
        if ($receiverMobile) {
            $maskMobile = strlen($receiverMobile) == 11
                ? substr($receiverMobile, 0, 3) . '****' . substr($receiverMobile, -4)
                : $receiverMobile;
        }

        if ($this->isMock()) {
            $barcode = $waybillIdReal ?: 'NO-WAYBILL';
            // 面单 HTML：按截图「运单详情」样式渲染（公司 logo/电话/母单号/条码/收件人），数据来自真实落库订单
            $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>'
                . 'body{font-family:"微软雅黑",Arial,sans-serif;color:#222;margin:0;padding:16px;width:680px;}'
                . '.hd{display:flex;justify-content:space-between;align-items:center;border-bottom:2px solid #333;padding-bottom:8px;}'
                . '.logo{font-size:30px;font-weight:800;letter-spacing:2px;}'
                . '.tel{font-size:26px;font-weight:800;text-align:right;}'
                . '.sub{font-size:13px;text-align:right;color:#555;}'
                . '.meta{display:flex;justify-content:space-between;font-size:14px;margin:10px 0;color:#333;}'
                . '.bc{display:flex;justify-content:space-between;align-items:flex-end;}'
                . '.bar{font-family:"Libre Barcode 39",monospace;font-size:64px;letter-spacing:4px;}'
                . '.big{font-size:96px;font-weight:800;color:#333;}'
                . '.wb{font-size:18px;text-align:center;margin:6px 0;}'
                . '.box{border:2px solid #333;padding:14px;margin-top:8px;}'
                . '.box .title{font-size:22px;font-weight:800;}'
                . '.recv{display:flex;align-items:center;gap:14px;margin:14px 0;}'
                . '.badge{width:46px;height:46px;border:2px solid #333;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:16px;}'
                . '.who{font-size:20px;font-weight:700;}'
                . '.addr{font-size:18px;margin-top:10px;color:#222;}'
                . '</style></head><body>'
                . '<div class="hd"><div class="logo">' . htmlspecialchars($deliveryName) . '</div>'
                . '<div><div class="tel">95338</div><div class="sub">www.sf-express.com</div></div></div>'
                . '<div class="meta"><span>CCSP</span><span>打印时间' . date('Y/m/d H:i:s') . '</span></div>'
                . '<div class="bc"><div class="bar">' . htmlspecialchars($barcode) . '</div><div class="big">1</div></div>'
                . '<div class="wb">1/1 母单号 ' . htmlspecialchars($waybillIdReal) . '</div>'
                . '<div class="box"><div class="title">010W-010</div>'
                . '<div class="recv"><div class="badge">收</div><div class="who">' . htmlspecialchars($receiverName) . ' ' . htmlspecialchars($maskMobile) . '</div></div>'
                . '<div class="addr">' . htmlspecialchars($receiverArea . ' ' . $receiverAddress) . '</div>'
                . ($shopGoodsName ? '<div class="addr" style="color:#666;">商品：' . htmlspecialchars($shopGoodsName) . '</div>' : '')
                . '</div>'
                . '</body></html>';

            return [
                'order_status' => ((int) ($order['status'] ?? 1) === 4) ? 1 : 0,
                'waybill_id'   => $waybillIdReal,
                'print_html'   => $html,
                'print_type'   => $printType,
                'is_mock'      => true,
            ];
        }

        // 真实模式接口位：微信物流助手 /获取运单数据（需要 access_token 托管，本期留桩）
        throw new BusinessException('真实微信物流助手对接暂未开放，请保持 EWAYBILL_MOCK=1');
    }

    /**
     * 查询运单轨迹（对标参考系统 getpath）
     *
     * @return array{waybill_id: string, state: int, state_text: string, steps: array, is_mock: bool}
     */
    public function getPath(int $aid, string $orderId, string $deliveryId, string $waybillId): array
    {
        if ('' === $waybillId) {
            throw new BusinessException('该运单尚未生成运单号');
        }

        $order = ScopeService::query('miandan_order', $aid)
            ->where('order_id', $orderId)
            ->where('delivery_id', $deliveryId)
            ->find();
        $deliveryName = $order['delivery_name'] ?? $deliveryId;

        if ($this->isMock()) {
            // 已取消运单（status=4）无实时轨迹，按截图「暂未查到物流信息」兜底
            if ($order && (int) $order['status'] === 4) {
                return [
                    'delivery_id'  => $deliveryId,
                    'delivery_name' => $deliveryName,
                    'waybill_id'   => $waybillId,
                    'state'        => 0,
                    'state_text'   => '已取消',
                    'steps'        => [],
                    'is_mock'      => true,
                ];
            }
            $base  = time();
            $steps = [
                ['time' => date('Y-m-d H:i:s', $base - 3600), 'text' => '【' . $deliveryName . '】快件正在派送中，请保持电话畅通'],
                ['time' => date('Y-m-d H:i:s', $base - 86400), 'text' => '【' . $deliveryName . '】快件已到达【转运中心】'],
                ['time' => date('Y-m-d H:i:s', $base - 86400 * 2), 'text' => '【' . $deliveryName . '】快件已揽收，揽收成功'],
            ];

            return [
                'delivery_id'   => $deliveryId,
                'delivery_name' => $deliveryName,
                'waybill_id'    => $waybillId,
                'state'         => 2,
                'state_text'    => '运输中',
                'steps'         => $steps,
                'is_mock'       => true,
            ];
        }

        // 真实模式接口位：微信物流助手 /查询运单轨迹（需要 access_token 托管，本期留桩）
        throw new BusinessException('真实微信物流助手对接暂未开放，请保持 EWAYBILL_MOCK=1');
    }

    /** 取消运单（对标参考系统 quxiao：mock 直接置为已取消） */
    public function cancel(int $aid, int $uid, string $orderId, string $deliveryId, string $waybillId): void
    {
        $order = ScopeService::query('miandan_order', $aid)
            ->where('order_id', $orderId)
            ->where('delivery_id', $deliveryId)
            ->find();
        if (empty($order)) {
            throw new BusinessException('运单不存在');
        }
        if ((int) $order['status'] === 4) {
            throw new BusinessException('运单已取消，请勿重复操作');
        }

        if (!$this->isMock()) {
            // 真实模式接口位：微信物流助手 /取消运单（本期留桩）
            throw new BusinessException('真实微信物流助手对接暂未开放，请保持 EWAYBILL_MOCK=1');
        }

        Db::name('miandan_order')->where('id', $order['id'])->update(['status' => 4]);
        PlogService::write($aid, $uid, '取消运单' . $orderId, self::BID);
    }

    /** 删除运单记录 */
    public function delete(int $aid, int $uid, array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) {
            throw new BusinessException('请选择要删除的运单');
        }
        ScopeService::query('miandan_order', $aid)
            ->whereIn('id', $ids)
            ->delete();
        PlogService::write($aid, $uid, '删除运单' . implode(',', $ids), self::BID);
    }

    /**
     * 快递公司下拉选项（绑定物流账号表单用，对标微信 delivery/getall）
     *
     * @return array{list: array, is_mock: bool}
     */
    public function deliveryOptions(): array
    {
        return ['list' => self::DELIVERY_OPTIONS, 'is_mock' => $this->isMock()];
    }

    /**
     * 物流账号列表（落库 miandan_account，对标参考系统 myaccount）
     *
     * @return array{list: array, total: int, is_mock: bool}
     */
    public function accountList(int $aid): array
    {
        $rows = ScopeService::query('miandan_account', $aid)
            ->order('id', 'desc')
            ->select()
            ->toArray();
        foreach ($rows as &$row) {
            $row['status_name'] = self::ACCOUNT_STATUS_MAP[(int) $row['status_code']] ?? '未知';
            $row['create_time_text'] = $row['create_time'] ? date('Y-m-d H:i', (int) $row['create_time']) : '';
        }
        unset($row);

        return ['list' => $rows, 'total' => count($rows), 'is_mock' => $this->isMock()];
    }

    /**
     * 绑定物流账号（落库；真实模式调微信 account/bind，mock 直接写本地表）
     *
     * @param array{delivery_id?:string, biz_id?:string, password?:string, remark_content?:string} $data
     */
    public function accountBind(int $aid, int $uid, array $data): void
    {
        $deliveryId = trim((string) ($data['delivery_id'] ?? ''));
        $bizId      = trim((string) ($data['biz_id'] ?? ''));
        if ('' === $deliveryId) {
            throw new BusinessException('请选择快递公司');
        }
        if ('' === $bizId) {
            throw new BusinessException('请填写客户编码');
        }
        $deliveryName = '';
        foreach (self::DELIVERY_OPTIONS as $opt) {
            if ($opt['delivery_id'] === $deliveryId) {
                $deliveryName = $opt['delivery_name'];
                break;
            }
        }

        $row = ScopeService::query('miandan_account', $aid)
            ->where('delivery_id', $deliveryId)
            ->where('biz_id', $bizId)
            ->find();

        $payload = [
            'delivery_id'     => $deliveryId,
            'delivery_name'   => $deliveryName,
            'biz_id'          => $bizId,
            'password'        => (string) ($data['password'] ?? ''),
            'remark_content'  => (string) ($data['remark_content'] ?? ''),
            'status_code'     => 0, // 绑定成功
            'quota_num'       => 83,
            'updatetime'      => time(),
        ];

        if ($row) {
            Db::name('miandan_account')->where('id', $row['id'])->update($payload);
        } else {
            $payload['aid'] = $aid;
            $payload['create_time'] = time();
            Db::name('miandan_account')->insert($payload);
        }
        PlogService::write($aid, $uid, '绑定物流账号' . $deliveryName . '(' . $bizId . ')', self::BID);
    }

    /** 解绑物流账号（落库置 status_code=3 已解绑） */
    public function accountUnbind(int $aid, int $uid, string $bizId, string $deliveryId): void
    {
        $row = ScopeService::query('miandan_account', $aid)
            ->where('delivery_id', $deliveryId)
            ->where('biz_id', $bizId)
            ->find();
        if (empty($row)) {
            throw new BusinessException('账号不存在');
        }
        Db::name('miandan_account')->where('id', $row['id'])->update(['status_code' => 3, 'updatetime' => time()]);
        PlogService::write($aid, $uid, '解绑物流账号' . $bizId, self::BID);
    }

    /**
     * 打印员列表（落库 miandan_printer，对标参考系统 printerlist）
     *
     * @return array{list: array, total: int, is_mock: bool}
     */
    public function printerList(int $aid): array
    {
        $rows = ScopeService::query('miandan_printer', $aid)
            ->order('id', 'desc')
            ->select()
            ->toArray();

        return ['list' => $rows, 'total' => count($rows), 'is_mock' => $this->isMock()];
    }

    /**
     * 添加打印员（会员ID或openid；数字按会员ID反查 openid，否则直接用 openid）
     */
    public function printerAdd(int $aid, int $uid, string $openid, string $nickname = ''): void
    {
        $openid = trim($openid);
        if ('' === $openid) {
            throw new BusinessException('请填写会员ID或openid');
        }
        // 数字视为会员ID，反查微信 openid（对标参考系统 addprinter）
        if (ctype_digit($openid)) {
            $wxopenid = (string) Db::name('member')->where('aid', $aid)->where('id', (int) $openid)->value('wxopenid');
            if ('' !== $wxopenid) {
                $openid = $wxopenid;
            }
        }
        if ('' === $openid) {
            throw new BusinessException('请填写会员ID或openid');
        }

        $exists = ScopeService::query('miandan_printer', $aid)->where('openid', $openid)->find();
        if ($exists) {
            throw new BusinessException('该打印员已存在，请勿重复添加');
        }
        Db::name('miandan_printer')->insert([
            'aid' => $aid, 'openid' => $openid,
            'nickname' => $nickname ?: ('打印员 ' . substr($openid, -6)),
            'headimg' => '',
            'createtime' => time(),
        ]);
        PlogService::write($aid, $uid, '添加物流助手打印员' . $openid, self::BID);
    }

    /** 解绑打印员 */
    public function printerDel(int $aid, int $uid, string $openid): void
    {
        $openid = trim($openid);
        if ('' === $openid) {
            throw new BusinessException('缺少打印员标识');
        }
        ScopeService::query('miandan_printer', $aid)->where('openid', $openid)->delete();
        PlogService::write($aid, $uid, '删除物流助手打印员' . $openid, self::BID);
    }

    /** 物流助手设置读取（不存在自动初始化，对标参考系统 set） */
    public function setOf(int $aid): array
    {
        $info = ScopeService::query('miandan_set', $aid)->where('bid', self::BID)->find();
        if (empty($info)) {
            $info = [
                'aid'          => $aid,
                'bid'          => self::BID,
                'status'       => 1,
                'query_status' => 1,
                'print_type'   => 0,
            ];
            $info['id'] = Db::name('miandan_set')->insertGetId($info);
        }

        return $info + ['is_mock' => $this->isMock()];
    }

    /** 物流助手设置保存 */
    public function saveSet(int $aid, int $uid, array $data): void
    {
        $this->setOf($aid); // 确保行存在

        $update = [
            'status'       => isset($data['status']) ? (int) $data['status'] : 1,
            'query_status' => isset($data['query_status']) ? (int) $data['query_status'] : 1,
            'print_type'   => isset($data['print_type']) ? (int) $data['print_type'] : 0,
        ];
        ScopeService::query('miandan_set', $aid)->where('bid', self::BID)->update($update);
        PlogService::write($aid, $uid, '设置物流助手', self::BID);
    }
}
