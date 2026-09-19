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
        $rows  = $query->order('id', 'desc')
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

        if ($this->isMock()) {
            $html = '<div style="width:700px;border:2px dashed #999;padding:14px;font-family:微软雅黑,sans-serif;color:#333;">'
                . '<div style="text-align:center;font-weight:bold;font-size:18px;">物流助手运单（MOCK ' . ($printType === 1 ? '一联单' : '二联单') . '）</div>'
                . '<p>订单号：' . htmlspecialchars($orderId) . '</p>'
                . '<p>快递公司：' . htmlspecialchars($deliveryId) . '</p>'
                . '<p>运单号：' . htmlspecialchars($waybillId ?: '（未生成）') . '</p>'
                . '<p style="color:#999;">EWAYBILL.MOCK=1 占位打印数据，真实模式由微信物流助手 getOrder 返回。</p>'
                . '</div>';

            return [
                'order_status' => 0,
                'waybill_id'   => $waybillId,
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

        if ($this->isMock()) {
            $base  = time();
            $steps = [
                ['time' => date('Y-m-d H:i:s', $base - 86400 * 2), 'text' => '快件已揽收，揽收网点：' . $deliveryId . '（MOCK）'],
                ['time' => date('Y-m-d H:i:s', $base - 86400), 'text' => '快件已到达【转运中心】'],
                ['time' => date('Y-m-d H:i:s', $base - 3600), 'text' => '快件正在派送中，请保持电话畅通'],
            ];

            return [
                'waybill_id' => $waybillId,
                'state'      => 2,
                'state_text' => '运输中（MOCK）',
                'steps'      => $steps,
                'is_mock'    => true,
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
     * 物流账号列表（对标参考系统 myaccount：mock 返回固定演示列表）
     *
     * @return array{list: array, is_mock: bool}
     */
    public function deliveryAccounts(int $aid): array
    {
        if ($this->isMock()) {
            return [
                'list' => [
                    ['biz_id' => 'MOCK_SF_BIZ', 'delivery_id' => 'SF', 'delivery_name' => '顺丰速运', 'status_code' => 0],
                    ['biz_id' => 'MOCK_YTO_BIZ', 'delivery_id' => 'YTO', 'delivery_name' => '圆通速递', 'status_code' => 0],
                    ['biz_id' => 'MOCK_ZTO_BIZ', 'delivery_id' => 'ZTO', 'delivery_name' => '中通快递', 'status_code' => 0],
                ],
                'is_mock' => true,
            ];
        }

        // 真实模式接口位：微信物流助手 /获取已绑定的物流账号（本期留桩）
        throw new BusinessException('真实微信物流助手对接暂未开放，请保持 EWAYBILL_MOCK=1');
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
