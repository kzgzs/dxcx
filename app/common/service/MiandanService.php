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

        // 寄件人信息（真实落库；缺省回退到商户发货地址）
        $senderCompany = $order['sender_company'] ?: ($order['sender_name'] ?: '鼎极商城');
        $senderMobile  = $order['sender_mobile'] ?: $order['sender_tel'] ?: '17811111111';
        $senderArea    = trim(($order['sender_province'] ?? '') . ($order['sender_city'] ?? '') . ($order['sender_area'] ?? ''));
        $senderAddress = $order['sender_address'] ?: '安徽省合肥市瑶海区西乡大道宝运达物流中心A2-102档';

        // 商品明细（cargo_detail_list JSON：[{name,count}]）
        $goodsList = [];
        if (!empty($order['cargo_detail_list'])) {
            $decoded = json_decode((string) $order['cargo_detail_list'], true);
            if (is_array($decoded)) {
                foreach ($decoded as $g) {
                    if (!empty($g['name'])) {
                        $goodsList[] = ['name' => $g['name'], 'count' => (int) ($g['count'] ?? 1)];
                    }
                }
            }
        }
        if (empty($goodsList) && $shopGoodsName) {
            $goodsList = [['name' => $shopGoodsName, 'count' => (int) ($order['shop_goods_count'] ?? 1)]];
        }
        $goodsText = implode('；', array_map(fn($g) => $g['name'] . ' x' . $g['count'], $goodsList));

        // 重量 / 件数 / 付款方式
        $weight = (float) ($order['cargo_weight'] ?? 0);
        if ($weight <= 0) { $weight = 1.00; }
        $packageCount = max(1, (int) ($order['cargo_count'] ?? 1));
        // 现付=寄付；含「到付」字样则为到付
        $payText = (false !== mb_strpos((string) $deliveryName, '到付')) ? '到付' : '寄付';

        // 路由码（面单模板路由标识，对标截图 010W-010）
        $routeCode = '010W-010';

        // 手机号中间四位脱敏（对齐截图 156****6666）
        $maskMobile = '';
        if ($receiverMobile) {
            $maskMobile = strlen($receiverMobile) == 11
                ? substr($receiverMobile, 0, 3) . '****' . substr($receiverMobile, -4)
                : $receiverMobile;
        }

        if ($this->isMock()) {
            $barcode = $waybillIdReal ?: 'NO-WAYBILL';

            // 伪条码：按运单号字符生成竖条（打印态视觉，非真实扫码）
            $bars = '';
            foreach (str_split($barcode) as $ch) {
                $w = (ord($ch) % 3) + 1;
                $bars .= '<span style="display:inline-block;width:' . $w . 'px;height:64px;background:#000;"></span>';
            }
            // 伪二维码：按运单号 hash 生成 12x12 点阵
            $qr = '<div style="width:120px;height:120px;display:grid;grid-template-columns:repeat(12,1fr);grid-template-rows:repeat(12,1fr);border:1px solid #999;padding:4px;box-sizing:border-box;">';
            $h = crc32($barcode);
            for ($i = 0; $i < 144; $i++) {
                $on = (($h >> ($i % 24)) & 1) === 1;
                $qr .= '<span style="background:' . ($on ? '#000' : '#fff') . ';"></span>';
            }
            $qr .= '</div>';

            $printTime = date('Y/m/d H:i:s');
            $recvLine = trim($receiverName . '  ' . $maskMobile);
            $recvAddr = trim($receiverArea . ' ' . $receiverAddress);
            $senderLine = trim($senderCompany . '  ' . $senderMobile);
            $senderAddr = trim($senderArea . ' ' . $senderAddress);
            $insured = trim((string) ($order['insured_value'] ?? ''));

            // SF 雷达小标（寄方表底行右列）
            $radar = '<div style="width:46px;height:46px;border-radius:50%;border:3px solid #666;position:relative;margin:0 auto;">'
                . '<div style="position:absolute;inset:6px;border-radius:50%;border:2px solid #666;"></div>'
                . '<div style="position:absolute;left:50%;top:50%;width:6px;height:6px;background:#1e88e5;border-radius:50%;transform:translate(-50%,-50%);"></div></div>';

            // 面单 HTML：收方一张表 + 寄方一张表（border-collapse，边框连续），数据来自真实落库订单
            $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>'
                . 'body{font-family:"微软雅黑",Arial,sans-serif;color:#111;margin:0;padding:14px;width:680px;box-sizing:border-box;}'
                . '.hd{display:flex;justify-content:space-between;align-items:center;border-bottom:2px solid #333;padding-bottom:6px;}'
                . '.logo-block{display:flex;align-items:center;gap:10px;}'
                . '.sf-circle{width:54px;height:54px;border:3px solid #000;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:20px;}'
                . '.l1{font-size:26px;font-weight:800;letter-spacing:1px;line-height:1;}'
                . '.l2{font-size:18px;font-weight:700;}'
                . '.hd-right{text-align:right;}'
                . '.tel{font-size:30px;font-weight:800;}'
                . '.sub{font-size:13px;color:#333;}'
                . '.meta{display:flex;justify-content:space-between;font-size:14px;margin:8px 0;color:#333;}'
                . '.bc{display:flex;justify-content:space-between;align-items:flex-end;}'
                . '.bars{display:flex;align-items:flex-end;gap:1px;height:64px;}'
                . '.bign{font-size:88px;font-weight:800;line-height:0.8;}'
                . '.wb{font-size:16px;text-align:center;margin:6px 0;}'
                . 'table.way{width:100%;border-collapse:collapse;margin-bottom:8px;}'
                . 'table.way td{border:2px solid #333;vertical-align:middle;padding:8px;}'
                . '.route{font-size:44px;font-weight:800;text-align:center;letter-spacing:2px;padding:10px;}'
                . '.badge{width:64px;text-align:center;}'
                . '.circ{width:46px;height:46px;border:2px solid #333;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-weight:800;font-size:16px;}'
                . '.who{font-size:20px;font-weight:700;}'
                . '.addr{font-size:17px;margin-top:6px;color:#222;}'
                . '.pay{text-align:center;font-size:18px;padding:6px;}'
                . '.wu{text-align:center;font-size:52px;font-weight:800;color:#444;padding:6px;}'
                . '.qrc{text-align:center;}'
                . '.insp{writing-mode:vertical-rl;font-size:24px;font-weight:800;letter-spacing:6px;text-align:center;}'
                . '.cl{padding:10px;font-size:15px;vertical-align:top;}'
                . '</style></head><body>'

                // —— 头部：logo/电话/打印时间/条码/母单号 ——
                . '<div class="hd"><div class="logo-block"><div class="sf-circle">SF</div><div><div class="l1">EXPRESS</div><div class="l2">顺丰速运</div></div></div>'
                . '<div class="hd-right"><div class="tel">95338</div><div class="sub">www.sf-express.com</div></div></div>'
                . '<div class="meta"><span>CCSP</span><span>打印时间 ' . $printTime . '</span></div>'
                . '<div class="bc"><div class="bars">' . $bars . '</div><div class="bign">' . $packageCount . '</div></div>'
                . '<div class="wb">1/' . $packageCount . ' 母单号 ' . htmlspecialchars($waybillIdReal) . '</div>'

                // —— 收方表（一张连续表格）：路由码 / 收+姓名手机 / 地址 / 末行三列(寄付·WU | 二维码 | 已验视) ——
                . '<table class="way">'
                . '<tr><td colspan="3" class="route">' . htmlspecialchars($routeCode) . '</td></tr>'
                . '<tr><td class="badge"><span class="circ">收</span></td>'
                . '<td colspan="2"><div class="who">' . htmlspecialchars($recvLine) . '</div></td></tr>'
                . '<tr><td colspan="3" class="addr">' . htmlspecialchars($recvAddr) . '</td></tr>'
                . '<tr>'
                . '<td style="width:150px;"><div class="pay">' . $payText . '</div><div class="wu">WU</div></td>'
                . '<td class="qrc">' . $qr . '</td>'
                . '<td style="width:64px;"><div class="insp">已验视</div></td>'
                . '</tr>'
                . '</table>'

                // —— 寄方表（单独一张表）：寄+名称电话地址 / 托寄物·增值服务 / 重量·付款 / SF logo ——
                . '<table class="way">'
                . '<tr><td class="badge"><span class="circ">寄</span></td>'
                . '<td><div class="who">' . htmlspecialchars($senderLine) . '</div>'
                . '<div class="addr">' . htmlspecialchars($senderAddr) . '</div></td></tr>'
                . '<tr><td class="cl">托寄物：' . htmlspecialchars($goodsText) . '</td>'
                . '<td class="cl">增值服务：' . ('' !== $insured ? htmlspecialchars($insured) : '') . '</td></tr>'
                . '<tr><td class="cl"></td>'
                . '<td class="cl">计费重量：' . number_format($weight, 2) . ' kg<br>实际重量：' . number_format($weight, 2) . ' kg<br>付款方式：' . $payText . '</td></tr>'
                . '<tr><td class="cl"></td><td class="cl" style="text-align:center;">' . $radar . '</td></tr>'
                . '</table>'
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
        throw new BusinessException('真实微信物流助手对接暂未开放，请在 .env 中保持模拟模式开启');
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
        throw new BusinessException('真实微信物流助手对接暂未开放，请在 .env 中保持模拟模式开启');
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
            throw new BusinessException('真实微信物流助手对接暂未开放，请在 .env 中保持模拟模式开启');
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
