<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use app\common\Kdniao;
use think\facade\Db;

/**
 * 电子面单服务（对标参考系统 Ewaybill/EwaybillSet：快递鸟电子面单）
 *
 * 能力：
 * - 面单记录：列表/详情/创建面单/取消/删除/打印模板
 * - 面单设置：快递鸟账号（用户ID/API KEY）+ 打印内容开关
 * - 快递公司账号：绑定列表/绑定保存/解绑（字典来自 Kdniao::expressData）
 *
 * mock 语义（.env EWAYBILL.MOCK=1）：创建面单返回确定性 mock 面单号 + 占位打印模板，
 * 取消直接成功；真实模式走快递鸟真实接口（需先在面单设置配置账号）
 */
class EwaybillService
{
    /** 控制台为平台/自营维度，固定 bid=0 */
    private const BID = 0;

    /** 面单状态（对标参考系统：0待处理 1正常 2已取消） */
    public const STATUS_MAP = [0 => '待处理', 1 => '正常', 2 => '已取消'];

    /** 订单类型字典（对标参考系统 Order::getOrderTypeName 口径） */
    public const ORDER_TYPE_MAP = [
        'shop'    => '商城订单',
        'tuangou' => '团购订单',
        'seckill' => '秒杀订单',
        'collage' => '拼团订单',
    ];

    /** 打印内容字段字典（ewaybill_set.print_shop_content 候选） */
    public const PRINT_CONTENT_MAP = [
        'shop_name'  => '商品名称',
        'shop_alias' => '商品别名',
        'shop_code'  => '商品编码',
        'guige_name' => '规格名称',
        'guige_code' => '规格编码',
    ];

    public function isMock(): bool
    {
        return Kdniao::isMock();
    }

    /* ================= 面单记录 ================= */

    /**
     * 面单列表
     *
     * @param array $filters ordernum/ordertype/name/status
     * @return array{list: array, total: int, page: int, pagesize: int, is_mock: bool}
     */
    public function list(int $aid, array $filters, int $page, int $pageSize): array
    {
        $query = ScopeService::query('ewaybill_order', $aid)
            ->where('bid', self::BID)
            ->where('status', '<>', 0);

        if ('' !== trim((string) ($filters['ordernum'] ?? ''))) {
            $kw = (string) $filters['ordernum'];
            $query->where(function ($q) use ($kw) {
                $q->whereLike('ordernum', '%' . $kw . '%')
                    ->whereOr('order_code', 'like', '%' . $kw . '%')
                    ->whereOr('express_no', 'like', '%' . $kw . '%');
            });
        }
        if ('' !== trim((string) ($filters['ordertype'] ?? ''))) {
            $query->where('ordertype', (string) $filters['ordertype']);
        }
        if ('' !== trim((string) ($filters['name'] ?? ''))) {
            $name = (string) $filters['name'];
            $query->where(function ($q) use ($name) {
                $q->whereLike('receiver_name', '%' . $name . '%')
                    ->whereOr('receiver_mobile', 'like', '%' . $name . '%')
                    ->whereOr('receiver_tel', 'like', '%' . $name . '%');
            });
        }
        $status = (string) ($filters['status'] ?? '');
        if ('' !== $status) {
            $query->where('status', (int) $status);
        }

        $total = (clone $query)->count();
        $rows  = $query->order('id', 'desc')->page($page, $pageSize)->select()->toArray();

        foreach ($rows as &$row) {
            $row = $this->decorateRow($row);
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

    /** 面单详情 */
    public function detail(int $aid, int $id): array
    {
        $row = $this->findRow($aid, $id);

        $row = $this->decorateRow($row);
        // 详情口径：备注还原换行、温层文案
        $row['remark']          = str_replace('<br>', "\n", (string) ($row['remark'] ?? ''));
        $row['warm_layer_text'] = $row['warm_layer'] ? (string) Kdniao::warmLayer((string) $row['warm_layer']) : '';

        return $row;
    }

    /**
     * 创建面单前置数据（面单订单号 + 已绑定快递公司选项 + 温层 + 上次发件人）
     *
     * @return array{ordercode: string, express_options: array, warm_layer: array, last_order: array, is_mock: bool}
     */
    public function prepare(int $aid, string $ordertype = '', int $orderid = 0): array
    {
        $ordernum = '';
        if ('' !== $ordertype && $orderid > 0 && isset(self::ORDER_TYPE_MAP[$ordertype])) {
            $order = ScopeService::query($ordertype . '_order', $aid)->where('id', $orderid)->find();
            if (empty($order)) {
                throw new BusinessException('订单不存在');
            }
            $ordernum = (string) ($order['ordernum'] ?? '');
        }

        $lastOrder = ScopeService::query('ewaybill_order', $aid)
            ->where('bid', self::BID)
            ->order('id', 'desc')
            ->find();

        return [
            'ordercode'       => $this->createOrderCode($aid, $ordernum ?: ('MD' . date('ymdHis'))),
            'express_options' => $this->expressOptions($aid),
            'warm_layer'      => Kdniao::warmLayer(),
            'last_order'      => $lastOrder ?: [],
            'is_mock'         => $this->isMock(),
        ];
    }

    /**
     * 创建电子面单（对标参考系统 addorder：落单→快递鸟下单→回写面单号/模板→同步订单发货状态）
     */
    public function addOrder(int $aid, int $uid, array $info): array
    {
        $this->validateAddInfo($info);

        [$expressId, $shipperCode, $expType] = explode('-', (string) $info['delivery_info']) + [null, null, null];
        $expressId   = (int) $expressId;
        $expType     = (int) ($expType ?: 1);
        $expressInfo = ScopeService::query('ewaybill_express_account', $aid)
            ->where('id', $expressId)
            ->find();
        if (empty($expressInfo)) {
            throw new BusinessException('请先绑定快递公司账号');
        }

        $expressData = Kdniao::expressData((string) $shipperCode);
        if (empty($expressData)) {
            throw new BusinessException('快递公司不存在');
        }
        // 邮政/EMS 必填邮编
        if (in_array($expressData['code'] ?? '', ['YZPY', 'EMS'], true)) {
            if ('' === trim((string) ($info['receiver_post_code'] ?? ''))) {
                throw new BusinessException('请输入接收人邮编');
            }
            if ('' === trim((string) ($info['sender_post_code'] ?? ''))) {
                throw new BusinessException('请输入发送人邮编');
            }
        }
        // 跨越速运 必填预计揽件时间
        if ('KYSY' === ($expressData['code'] ?? '') && '' === trim((string) ($info['expect_start_time'] ?? ''))) {
            throw new BusinessException('请选择预计揽件时间');
        }
        // 温层（京东生鲜类）
        if (isset($expressData['warmLayer']) && in_array($expType, $expressData['warmLayer'], true)
            && '' === trim((string) ($info['warm_layer'] ?? ''))) {
            throw new BusinessException('请选择温层');
        }
        if (($info['expect_start_time'] ?? '') && strtotime((string) $info['expect_start_time']) < time()) {
            throw new BusinessException('预计揽件时间不能早于当前时间');
        }

        $ordertype = (string) ($info['ordertype'] ?? '');
        $orderid   = (int) ($info['orderid'] ?? 0);
        $order     = null;
        if ('' !== $ordertype && $orderid > 0 && isset(self::ORDER_TYPE_MAP[$ordertype])) {
            $order = ScopeService::query($ordertype . '_order', $aid)->where('id', $orderid)->find();
            if (empty($order)) {
                throw new BusinessException('订单不存在');
            }
            // 团购活动未结束不允许发货（对标参考系统）
            if ('tuangou' === $ordertype) {
                $product = Db::name('tuangou_product')->where('id', $order['proid'] ?? 0)->find();
                if ($product && (int) $product['endtime'] > time()) {
                    throw new BusinessException('团购活动未结束 暂不允许发货');
                }
            }
        }

        $orderdata = [
            'aid'            => $aid,
            'bid'            => self::BID,
            'mid'            => (int) ($order['mid'] ?? ($info['mid'] ?? 0)),
            'orderid'        => $orderid,
            'ordertype'      => $ordertype,
            'ordernum'       => (string) ($order['ordernum'] ?? ($info['ordernum'] ?? '')),
            'order_code'     => (string) ($info['ordercode'] ?? $this->createOrderCode($aid, 'MD' . date('ymdHis'))),
            'express_id'     => $expressId,
            'shipper_code'   => (string) $shipperCode,
            'customer_name'  => (string) ($expressInfo['customer_name'] ?? ''),
            'customer_pwd'   => (string) ($expressInfo['customer_pwd'] ?? ''),
            'send_site'      => (string) ($expressInfo['send_site'] ?? ''),
            'send_staff'     => (string) ($expressInfo['send_staff'] ?? ''),
            'month_code'     => (string) ($expressInfo['month_code'] ?? ''),
            'pay_type'       => (int) ($expressInfo['paytype'] ?? 1),
            'exp_type'       => $expType,
            'expect_start_time' => ($info['expect_start_time'] ?? '') ? strtotime((string) $info['expect_start_time']) : 0,
            'expect_end_time'   => ($info['expect_end_time'] ?? '') ? strtotime((string) $info['expect_end_time']) : 0,
            'is_notice'      => (int) ($info['is_notice'] ?? 0),
            'quantity'       => (int) $info['quantity'],
            'weight'         => (float) $info['weight'],
            'volume'         => (float) $info['volume'],
            'insure'         => (int) ($info['insure'] ?? 0),
            'insure_value'   => (float) ($info['insure_value'] ?? 0),
            'warm_layer'     => (string) ($info['warm_layer'] ?? ''),
            'remark'         => trim((string) ($info['remark'] ?? '')) !== '' ? (string) $info['remark'] : '无',
            'receiver_name'      => (string) $info['receiver_name'],
            'receiver_mobile'    => (string) ($info['receiver_mobile'] ?? ''),
            'receiver_province'  => (string) $info['receiver_province'],
            'receiver_city'      => (string) $info['receiver_city'],
            'receiver_area'      => (string) $info['receiver_area'],
            'receiver_address'   => (string) $info['receiver_address'],
            'receiver_tel'       => (string) ($info['receiver_tel'] ?? ''),
            'receiver_company'   => (string) ($info['receiver_company'] ?? ''),
            'receiver_post_code' => (string) ($info['receiver_post_code'] ?? ''),
            'sender_name'      => (string) $info['sender_name'],
            'sender_mobile'    => (string) ($info['sender_mobile'] ?? ''),
            'sender_province'  => (string) $info['sender_province'],
            'sender_city'      => (string) $info['sender_city'],
            'sender_area'      => (string) $info['sender_area'],
            'sender_address'   => (string) $info['sender_address'],
            'sender_tel'       => (string) ($info['sender_tel'] ?? ''),
            'sender_company'   => (string) ($info['sender_company'] ?? ''),
            'sender_post_code' => (string) ($info['sender_post_code'] ?? ''),
            'express_com'      => (string) ($expressInfo['express'] ?? ''),
            'createtime'       => time(),
        ];

        $ewaybillId = Db::name('ewaybill_order')->insertGetId($orderdata);

        $kdniao = new Kdniao($aid, self::BID);
        $res    = $kdniao->addorder($orderdata, $expressInfo);
        if ((int) ($res['status'] ?? 0) !== 1) {
            // 下单失败回滚本地记录（对标参考系统保留记录但状态 0；这里直接清理避免脏数据）
            Db::name('ewaybill_order')->where('id', $ewaybillId)->delete();
            throw new BusinessException((string) ($res['msg'] ?? '面单创建失败'));
        }

        $expressNo    = (string) ($res['data']['Order']['LogisticCode'] ?? '');
        $subExpressNo = !empty($res['data']['SubOrders']) ? implode(',', (array) $res['data']['SubOrders']) : '';
        Db::name('ewaybill_order')->where('id', $ewaybillId)->update([
            'express_no'     => $expressNo,
            'status'         => 1,
            'waybill'        => (string) ($res['data']['PrintTemplate'] ?? ''),
            'sub_express_no' => $subExpressNo,
            'subwaybill'     => (string) ($res['data']['SubPrintTemplate'] ?? ''),
        ]);

        // 同步订单发货状态（订单表存在时；失败不阻断面单主流程）
        if ($order) {
            try {
                Db::name($ordertype . '_order')->where('aid', $aid)->where('id', $orderid)->update([
                    'express_com' => $orderdata['express_com'],
                    'express_no'  => $expressNo,
                    'send_time'   => time(),
                    'status'      => 2,
                ]);
                if ('shop' === $ordertype) {
                    Db::name('shop_order_goods')->where('aid', $aid)->where('orderid', $orderid)->update(['status' => 2]);
                }
            } catch (\Throwable $e) {
                // 订单表结构差异时静默，面单本身已生成
            }
        }

        PlogService::write($aid, $uid, '生成电子面单' . $ewaybillId, self::BID);

        return [
            'id'         => $ewaybillId,
            'express_no' => $expressNo,
            'order_code' => $orderdata['order_code'],
            'is_mock'    => $this->isMock(),
        ];
    }

    /** 取消电子面单（对标参考系统 quxiaoOrder：仅支持取消且当日单） */
    public function cancel(int $aid, int $uid, int $id): void
    {
        $order = $this->findRow($aid, $id);

        $expressData = Kdniao::expressData((string) $order['shipper_code']);
        if (!is_array($expressData) || empty($expressData['cancel'] ?? null)) {
            throw new BusinessException('该快递公司不支持取消');
        }
        if ((int) $order['status'] === 2) {
            throw new BusinessException('面单已取消，请勿重复操作');
        }
        // 仅支持取消当日订单（对标参考系统口径）
        if (date('Y-m-d', (int) $order['createtime']) !== date('Y-m-d')) {
            throw new BusinessException('仅支持取消当日订单');
        }

        $kdniao = new Kdniao($aid, self::BID);
        $res    = $kdniao->cancelorder($order);
        if ((int) ($res['status'] ?? 0) !== 1) {
            throw new BusinessException((string) ($res['msg'] ?? '取消失败'));
        }

        Db::name('ewaybill_order')->where('id', $id)->update(['status' => 2]);

        // 还原订单发货状态（订单表存在时）
        $ordertype = (string) $order['ordertype'];
        if ('' !== $ordertype && (int) $order['orderid'] > 0 && isset(self::ORDER_TYPE_MAP[$ordertype])) {
            try {
                Db::name($ordertype . '_order')->where('aid', $aid)->where('id', $order['orderid'])->update([
                    'status'      => 1,
                    'express_com' => '',
                    'express_no'  => '',
                    'send_time'   => '',
                ]);
                if ('shop' === $ordertype) {
                    Db::name('shop_order_goods')->where('aid', $aid)->where('orderid', $order['orderid'])->update(['status' => 1]);
                }
            } catch (\Throwable $e) {
                // 订单表结构差异时静默
            }
        }
        PlogService::write($aid, $uid, '取消电子面单' . $order['order_code'], self::BID);
    }

    /** 删除面单记录 */
    public function delete(int $aid, int $uid, array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) {
            throw new BusinessException('请选择要删除的电子面单');
        }
        ScopeService::query('ewaybill_order', $aid)
            ->where('bid', self::BID)
            ->whereIn('id', $ids)
            ->delete();
        PlogService::write($aid, $uid, '删除电子面单' . implode(',', $ids), self::BID);
    }

    /** 打印面单（返回打印模板 HTML 数组，对标参考系统 printOrder） */
    public function printOrders(int $aid, array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) {
            throw new BusinessException('请选择订单');
        }
        $waybills = ScopeService::query('ewaybill_order', $aid)
            ->where('bid', self::BID)
            ->whereIn('id', $ids)
            ->where('waybill', '<>', '')
            ->column('waybill');
        if (empty($waybills)) {
            throw new BusinessException('订单不存在或无打印模板');
        }

        return ['list' => array_values($waybills)];
    }

    /* ================= 面单设置 ================= */

    /** 面单设置读取（不存在自动初始化，对标参考系统 EwaybillSet::index） */
    public function setOf(int $aid): array
    {
        $info = ScopeService::query('ewaybill_set', $aid)->where('bid', self::BID)->find();
        if (empty($info)) {
            $info = [
                'aid'                => $aid,
                'bid'                => self::BID,
                'account_id'         => '',
                'account_secret'     => '',
                'print_remarks'      => 1,
                'print_shop'         => 1,
                'print_shop_content' => implode(',', ['shop_alias', 'guige_name']),
                'createtime'         => time(),
                'status'             => 0,
            ];
            $info['id'] = Db::name('ewaybill_set')->insertGetId($info);
        }

        $info['print_shop_content'] = array_values(array_filter(explode(',', (string) $info['print_shop_content'])));
        $info['print_content_map']  = self::PRINT_CONTENT_MAP;
        $info['is_mock']            = $this->isMock();

        return $info;
    }

    /** 面单设置保存（对标参考系统 EwaybillSet::save：用户ID/API KEY 必填） */
    public function saveSet(int $aid, int $uid, array $info): void
    {
        $this->setOf($aid); // 确保行存在

        if ('' === trim((string) ($info['account_id'] ?? ''))) {
            throw new BusinessException('请输入用户ID');
        }
        if ('' === trim((string) ($info['account_secret'] ?? ''))) {
            throw new BusinessException('请输入API KEY');
        }

        $content = $info['print_shop_content'] ?? [];
        $content = is_array($content) ? $content : [];
        $content = array_values(array_intersect($content, array_keys(self::PRINT_CONTENT_MAP)));

        $update = [
            'account_id'         => (string) $info['account_id'],
            'account_secret'     => (string) $info['account_secret'],
            'print_remarks'      => (int) ($info['print_remarks'] ?? 0),
            'print_shop'         => (int) ($info['print_shop'] ?? 0),
            'print_shop_content' => implode(',', $content),
        ];
        ScopeService::query('ewaybill_set', $aid)->where('bid', self::BID)->update($update);
        PlogService::write($aid, $uid, '电子面单设置', self::BID);
    }

    /* ================= 快递公司账号 ================= */

    /** 快递公司字典（绑定弹窗候选，含动态字段与产品类型） */
    public function expressDict(): array
    {
        $list = [];
        foreach (Kdniao::expressData() as $code => $item) {
            $list[] = [
                'code'        => $code,
                'name'        => $item['name'] ?? $code,
                'field'       => $item['field'] ?? [],
                'customerMap' => $item['customerMap'] ?? [],
                'expType'     => $item['expType'] ?? [],
                'cancel'      => (int) ($item['cancel'] ?? 0),
                'isYuyue'     => (int) ($item['isYuyue'] ?? 0),
                'isBind'      => (int) ($item['isBind'] ?? 0),
            ];
        }

        return ['list' => $list, 'paytype' => Kdniao::paytype()];
    }

    /** 已绑定快递公司账号列表（对标参考系统 expressAccount） */
    public function accountList(int $aid, int $page, int $pageSize): array
    {
        $query = ScopeService::query('ewaybill_express_account', $aid)->where('bid', self::BID);
        $total = (clone $query)->count();
        $rows  = $query->order('id', 'desc')->page($page, $pageSize)->select()->toArray();

        foreach ($rows as &$row) {
            $row['paytype_text']    = (string) Kdniao::paytype((int) $row['paytype']);
            $row['createtime_text'] = $row['createtime'] ? date('Y-m-d H:i:s', (int) $row['createtime']) : '';
        }
        unset($row);

        return ['list' => $rows, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize];
    }

    /** 绑定/编辑快递公司账号（对标参考系统 bindExpressAccount：按字典字段动态校验） */
    public function accountSave(int $aid, int $uid, array $info): void
    {
        $code    = (string) ($info['express_code'] ?? '');
        $express = Kdniao::expressData($code);
        if ('' === $code) {
            throw new BusinessException('请选择快递公司');
        }
        if (empty($express)) {
            throw new BusinessException('快递公司不存在');
        }

        $fieldLabels = [
            'CustomerName' => ['customer_name', '客户编码'],
            'CustomerPwd'  => ['customer_pwd', '客户密码'],
            'MonthCode'    => ['month_code', '月结号'],
            'SendSite'     => ['send_site', '网点编码'],
            'SendStaff'    => ['send_staff', '网点名称'],
        ];
        foreach ($express['field'] ?? [] as $field) {
            if (isset($fieldLabels[$field]) && '' === trim((string) ($info[$fieldLabels[$field][0]] ?? ''))) {
                throw new BusinessException('请输入' . $fieldLabels[$field][1]);
            }
        }
        $paramLabels = ['Param3' => ['param3', 'AppKey'], 'Param4' => ['param4', 'AppSecret'], 'Param5' => ['param5', 'AccessToken']];
        foreach ($express['customerMap'] ?? [] as $param) {
            if (isset($paramLabels[$param]) && '' === trim((string) ($info[$paramLabels[$param][0]] ?? ''))) {
                throw new BusinessException('请输入' . $paramLabels[$param][1]);
            }
        }
        if (empty($info['paytype'])) {
            throw new BusinessException('请选择运费支付方式');
        }

        $save = [
            'express_code'  => $code,
            'express'       => (string) ($express['name'] ?? ''),
            'customer_name' => (string) ($info['customer_name'] ?? ''),
            'customer_pwd'  => (string) ($info['customer_pwd'] ?? ''),
            'month_code'    => (string) ($info['month_code'] ?? ''),
            'send_site'     => (string) ($info['send_site'] ?? ''),
            'send_staff'    => (string) ($info['send_staff'] ?? ''),
            'paytype'       => (int) $info['paytype'],
            'param1'        => (string) ($info['param1'] ?? ''),
            'param2'        => (string) ($info['param2'] ?? ''),
            'param3'        => (string) ($info['param3'] ?? ''),
            'param4'        => (string) ($info['param4'] ?? ''),
            'param5'        => (string) ($info['param5'] ?? ''),
        ];

        $id = (int) ($info['id'] ?? 0);
        if ($id > 0) {
            ScopeService::query('ewaybill_express_account', $aid)->where('id', $id)->update($save);
        } else {
            $check = ScopeService::query('ewaybill_express_account', $aid)
                ->where('bid', self::BID)
                ->where('express_code', $code)
                ->find();
            if ($check) {
                throw new BusinessException('该快递公司已绑定');
            }
            Db::name('ewaybill_express_account')->insert($save + [
                'aid'        => $aid,
                'bid'        => self::BID,
                'createtime' => time(),
            ]);
        }
        PlogService::write($aid, $uid, '电子面单绑定快递公司账号：' . $save['express'], self::BID);
    }

    /** 解绑快递公司账号 */
    public function accountDelete(int $aid, int $uid, array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) {
            throw new BusinessException('请选择要删除的快递公司账号');
        }
        ScopeService::query('ewaybill_express_account', $aid)
            ->where('bid', self::BID)
            ->whereIn('id', $ids)
            ->delete();
        PlogService::write($aid, $uid, '电子面单解绑快递公司账号', self::BID);
    }

    /**
     * 已绑定快递公司选项（创建面单下拉，对标参考系统 getExpressOptions：id-code-expType 三段式）
     */
    public function expressOptions(int $aid): array
    {
        $bindList = ScopeService::query('ewaybill_express_account', $aid)
            ->where('bid', self::BID)
            ->column('express_code', 'id');
        if (empty($bindList)) {
            return [];
        }

        $allExpress = Kdniao::expressData();
        $options    = [];
        foreach ($bindList as $accountId => $expressCode) {
            if (!isset($allExpress[$expressCode])) {
                continue;
            }
            $item      = $allExpress[$expressCode];
            $warmLayer = $item['warmLayer'] ?? [];
            if (!empty($item['expType'])) {
                foreach ($item['expType'] as $typeId => $typeName) {
                    $options[] = [
                        'code'   => $accountId . '-' . $expressCode . '-' . $typeId,
                        'name'   => $item['name'] . ' - ' . $typeName,
                        'iswarm' => ($warmLayer && in_array($typeId, $warmLayer)) ? 1 : 0,
                    ];
                }
            } else {
                $options[] = [
                    'code'   => $accountId . '-' . $expressCode . '-1',
                    'name'   => $item['name'] . ' - 标准快递',
                    'iswarm' => 0,
                ];
            }
        }

        return $options;
    }

    /* ================= 内部辅助 ================= */

    /** 查找面单（带租户/商户隔离） */
    protected function findRow(int $aid, int $id): array
    {
        $row = ScopeService::query('ewaybill_order', $aid)->where('bid', self::BID)->where('id', $id)->find();
        if (empty($row)) {
            throw new BusinessException('订单不存在');
        }

        return $row;
    }

    /** 列表行装饰：状态/类型文案、时间格式化、收件地址拼接 */
    protected function decorateRow(array $row): array
    {
        $row['status_name']     = self::STATUS_MAP[(int) $row['status']] ?? '未知';
        $row['ordertype_name']  = self::ORDER_TYPE_MAP[(string) $row['ordertype']] ?? ((string) $row['ordertype']);
        $row['paytype_text']    = (string) Kdniao::paytype((int) $row['pay_type']);
        $row['createtime_text'] = $row['createtime'] ? date('Y-m-d H:i:s', (int) $row['createtime']) : '';
        $row['expect_start_time_text'] = $row['expect_start_time'] ? date('Y-m-d H:i:s', (int) $row['expect_start_time']) : '';
        $row['expect_end_time_text']   = $row['expect_end_time'] ? date('Y-m-d H:i:s', (int) $row['expect_end_time']) : '';
        $row['receiver_full_address']  = implode('', array_filter([
            $row['receiver_province'] ?? '',
            $row['receiver_city'] ?? '',
            $row['receiver_area'] ?? '',
            $row['receiver_address'] ?? '',
        ]));
        $row['sender_full_address']    = implode('', array_filter([
            $row['sender_province'] ?? '',
            $row['sender_city'] ?? '',
            $row['sender_area'] ?? '',
            $row['sender_address'] ?? '',
        ]));

        return $row;
    }

    /** 创建面单表单校验（对标参考系统 validateData 口径） */
    protected function validateAddInfo(array $info): void
    {
        if ('' === trim((string) ($info['delivery_info'] ?? ''))) {
            throw new BusinessException('请选择快递公司');
        }
        if (empty($info['quantity']) || (int) $info['quantity'] < 1) {
            throw new BusinessException('包裹数量不能小于1');
        }
        if (!isset($info['weight']) || (float) $info['weight'] <= 0) {
            throw new BusinessException('包裹重量不能小于等于0kg');
        }
        if (!isset($info['volume']) || (float) $info['volume'] <= 0) {
            throw new BusinessException('包裹体积不能小于等于0m³');
        }
        if ((int) ($info['insure'] ?? 0) === 1 && empty($info['insure_value'])) {
            throw new BusinessException('请输入保价金额');
        }
        $requires = [
            'receiver_name'     => '请输入收件人姓名',
            'receiver_province' => '请输入收件人省份',
            'receiver_city'     => '请输入收件人城市',
            'receiver_area'     => '请输入收件人区县',
            'receiver_address'  => '请输入收件人地址',
            'sender_name'       => '请输入发件人姓名',
            'sender_province'   => '请输入发件人省份',
            'sender_city'       => '请输入发件人城市',
            'sender_area'       => '请输入发件人区县',
            'sender_address'    => '请输入发件人地址',
        ];
        foreach ($requires as $field => $msg) {
            if ('' === trim((string) ($info[$field] ?? ''))) {
                throw new BusinessException($msg);
            }
        }
        if ('' === trim((string) ($info['receiver_tel'] ?? '')) && '' === trim((string) ($info['receiver_mobile'] ?? ''))) {
            throw new BusinessException('请输入收件人座机号码或手机号');
        }
        if ('' === trim((string) ($info['sender_tel'] ?? '')) && '' === trim((string) ($info['sender_mobile'] ?? ''))) {
            throw new BusinessException('请输入发件人座机号码或手机号');
        }
    }

    /** 生成唯一面单订单编号（对标参考系统 createOrderCode） */
    public function createOrderCode(int $aid, string $ordernum): string
    {
        $orderCode = $ordernum . 'D' . mt_rand(100000, 999999);
        $check     = ScopeService::query('ewaybill_order', $aid)
            ->where('bid', self::BID)
            ->where('order_code', $orderCode)
            ->find();
        if ($check) {
            return $this->createOrderCode($aid, $ordernum);
        }

        return $orderCode;
    }
}
