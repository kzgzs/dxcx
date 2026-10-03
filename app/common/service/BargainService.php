<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;

/**
 * 砍价活动服务（admin 后台查询/订单处理 + C 端核心链路封装）
 *
 * 表口径（基线 kanjia_* 表，金额单位「元」）：
 * - kanjia_sysset   活动设置：pic 横幅 / autoshdays 自动收货天数 / showjd 进度显示 / mastpay 必须支付
 * - kanjia_product  砍价商品：sell_price 原价 / min_price 底价 / helpnum 砍价人数
 *                   / limitHour 砍价时限（小时）/ stock 库存 / directbuy 直接购买开关
 *                   / helpgive_type 帮砍奖励 0积分 1余额 + helpgive_percent 奖励比例 + helpgive_ff 0实发
 *                   / kjdata 分段砍价金额 JSON（[{startnum,endnum,startmoney,endmoney}]，可空走内置递减）
 * - kanjia_join     发起记录：now_price 当前价 / helpnum 已帮砍人数 / status 0进行中 1已完成 2已失败
 *                   / createtime+limitHour*3600 为截止时间（懒过期：读取/帮砍时落 2）
 * - kanjia_help     帮砍记录：cut_price 砍掉金额 / after_price 砍后价
 * - kanjia_order    砍价订单：status 0未支付 1已支付 2已发货 3已收货 4已关闭
 *                   refund_status 0无 1审核中 2已退款 3已驳回（与商城订单同构）
 * - kanjia_codelist 在线卡密库：status 0未售出 1已售出
 *
 * 砍价核心链路（C 端 api 预留，直接调本服务即可）：
 * - startJoin()  发起砍价（写 join，now_price=原价）
 * - helpCut()    帮砍一刀（分段/递减随机金额，砍到下限置完成并扣库存，发帮砍奖励）
 * - createOrder() 达底价（或开直接购买）下单（写 order + payorder，mock 即时支付成功）
 *
 * 订单处理：发货 orderShip / 退款审核 orderRefundCheck（PayService 原路退款 + payorder 登记）
 */
class BargainService
{
    /** 订单状态 */
    public const ORDER_UNPAID   = 0;
    public const ORDER_PAID     = 1;
    public const ORDER_SHIPPED  = 2;
    public const ORDER_COLLECT  = 3;
    public const ORDER_CLOSED   = 4;

    /** 发起记录状态 */
    public const JOIN_ONGOING = 0;
    public const JOIN_DONE    = 1;
    public const JOIN_FAILED  = 2;

    public function __construct(protected PayService $payService)
    {
    }

    /* ================= 活动设置 ================= */

    /**
     * 读取砍价设置（无记录给默认）
     *
     * @return array{pic: string, autoshdays: int, showjd: int, mastpay: int}
     */
    public function getSysset(int $aid): array
    {
        $info = ScopeService::query('kanjia_sysset', $aid)->find();

        return [
            'pic'        => (string) ($info['pic'] ?? ''),
            'autoshdays' => (int) ($info['autoshdays'] ?? 7),
            'showjd'     => isset($info['showjd']) ? (int) $info['showjd'] : 1,
            'mastpay'    => (int) ($info['mastpay'] ?? 0),
        ];
    }

    /**
     * 保存砍价设置（upsert）
     *
     * @param array<string, mixed> $data
     */
    public function saveSysset(int $aid, array $data): void
    {
        $update = [];

        if (array_key_exists('pic', $data)) {
            $update['pic'] = trim((string) $data['pic']);
        }
        if (array_key_exists('autoshdays', $data)) {
            $days = (int) $data['autoshdays'];

            if ($days < 1 || $days > 90) {
                throw new BusinessException('自动收货天数须在 1-90 之间');
            }

            $update['autoshdays'] = $days;
        }
        if (array_key_exists('showjd', $data)) {
            $update['showjd'] = (int) $data['showjd'] === 1 ? 1 : 0;
        }
        if (array_key_exists('mastpay', $data)) {
            $update['mastpay'] = (int) $data['mastpay'] === 1 ? 1 : 0;
        }

        if (empty($update)) {
            throw new BusinessException('没有可保存的设置项');
        }

        $exists = ScopeService::query('kanjia_sysset', $aid)->find();

        if (empty($exists)) {
            Db::name('kanjia_sysset')->insert(array_merge([
                'aid'        => $aid,
                'pic'        => '',
                'autoshdays' => 7,
                'showjd'     => 1,
                'mastpay'    => 0,
            ], $update));

            return;
        }

        Db::name('kanjia_sysset')->where('id', (int) $exists['id'])->update($update);
    }

    /* ================= 商品管理 ================= */

    /**
     * 砍价商品列表
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function productList(int $aid, int $page, int $pageSize, string $keyword, string $status, string $bid = '', string $checked = '', string $orderField = '', string $orderType = ''): array
    {
        $query = ScopeService::query('kanjia_product', $aid);

        // 商户维度过滤（任务177 showtype-2：''/all 不过滤，0 自营，gt0 商户）
        ScopeService::applyBid($query, $bid, 'bid');

        if ('' !== $keyword) {
            $query->where('name', 'like', '%' . $keyword . '%');
        }
        if ('' !== $status) {
            $query->where('status', (int) $status);
        }
        // 审核状态过滤（0待审 1通过 2驳回）
        if ('' !== $checked) {
            $query->where('ischecked', (int) $checked);
        }

        $total = (clone $query)->count();

        // 排序白名单
        $orderMap = ['id' => 'id', 'sales' => 'sales', 'saleing' => 'saleing', 'sort' => 'sort'];
        $sortField = $orderMap[$orderField] ?? '';
        $sortDir   = 'asc' === strtolower($orderType) ? 'asc' : 'desc';
        if ('' !== $sortField) {
            $query->order($sortField, $sortDir)->order('id', 'desc');
        } else {
            $query->order('sort', 'desc')->order('id', 'desc');
        }

        $rows = $query
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        // 所属商户名映射（bid=0 平台自营）
        $bids = array_values(array_filter(array_map(fn ($r) => (int) $r['bid'], $rows), fn ($b) => $b > 0));
        $businessMap = [];
        if (!empty($bids)) {
            $businessMap = Db::name('business')->where('aid', $aid)->whereIn('id', $bids)->column('name', 'id');
        }

        foreach ($rows as &$row) {
            $bidVal = (int) ($row['bid'] ?? 0);
            $row['bname']            = $bidVal > 0 ? (string) ($businessMap[$bidVal] ?? ('商户' . $bidVal)) : '平台自营';
            $row['status_text']      = 1 === (int) $row['status'] ? '上架' : '下架';
            $row['directbuy_text']   = 1 === (int) $row['directbuy'] ? '可直接购买' : '须砍到底价';
            $row['helpgive_text']    = $this->helpgiveText($row);
            $row['ongoing_join_num'] = (int) Db::name('kanjia_join')
                ->where('aid', $aid)->where('proid', $row['id'])->where('status', self::JOIN_ONGOING)->count();
            $row['ischecked']        = (int) ($row['ischecked'] ?? 1);
            $row['check_reason']     = (string) ($row['check_reason'] ?? '');
            $row['starttime']        = (int) ($row['starttime'] ?? 0);
            $row['endtime']          = (int) ($row['endtime'] ?? 0);
            $row['saleing']          = (int) ($row['saleing'] ?? 0);
            $row['sales']            = (int) ($row['sales'] ?? 0);
            $row['sellpoint']        = (string) ($row['sellpoint'] ?? '');
        }
        unset($row);

        return ['list' => $rows, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize];
    }

    /**
     * 新增/编辑砍价商品
     *
     * @param array<string, mixed> $data
     * @return array{id: int}
     */
    public function productSave(int $aid, array $data): array
    {
        $id = (int) ($data['id'] ?? 0);

        $name = trim((string) ($data['name'] ?? ''));
        if ('' === $name) {
            throw new BusinessException('请填写商品名称');
        }

        $pic = trim((string) ($data['pic'] ?? ''));
        if ('' === $pic) {
            throw new BusinessException('请上传商品主图');
        }

        $sellPrice = round((float) ($data['sell_price'] ?? 0), 2);
        $minPrice  = round((float) ($data['min_price'] ?? 0), 2);

        if ($sellPrice <= 0) {
            throw new BusinessException('请填写商品原价');
        }
        if ($minPrice < 0) {
            throw new BusinessException('底价不能为负数');
        }
        if ($minPrice > $sellPrice) {
            throw new BusinessException('底价不能高于原价');
        }

        $helpnum = (int) ($data['helpnum'] ?? 5);
        if ($helpnum < 1 || $helpnum > 100) {
            throw new BusinessException('砍价人数须在 1-100 之间');
        }

        $limitHour = (int) ($data['limitHour'] ?? 12);
        if ($limitHour < 1 || $limitHour > 720) {
            throw new BusinessException('砍价时限须在 1-720 小时之间');
        }

        $stock = (int) ($data['stock'] ?? 0);
        if ($stock < 0) {
            throw new BusinessException('库存不能为负数');
        }

        // 活动时间（Y-m-d H:i 字符串转时间戳）
        $starttime = $this->toTs($data['starttime'] ?? 0);
        $endtime   = $this->toTs($data['endtime'] ?? 0);
        if ($endtime > 0 && $endtime <= $starttime) {
            throw new BusinessException('活动结束时间须晚于开始时间');
        }


        $helpgiveType    = (int) ($data['helpgive_type'] ?? 0);
        $helpgivePercent = round((float) ($data['helpgive_percent'] ?? 0), 2);
        $helpgiveFf      = (int) ($data['helpgive_ff'] ?? 0);

        if (!in_array($helpgiveType, [0, 1], true)) {
            throw new BusinessException('帮砍奖励类型不合法');
        }
        if ($helpgivePercent < 0 || $helpgivePercent > 100) {
            throw new BusinessException('帮砍奖励比例须在 0-100 之间');
        }

        // 分段砍价金额（可选 JSON：[{startnum,endnum,startmoney,endmoney}]）
        $kjdata = '';
        if (!empty($data['kjdata'])) {
            $kjRows = is_array($data['kjdata']) ? $data['kjdata'] : json_decode((string) $data['kjdata'], true);

            if (!is_array($kjRows)) {
                throw new BusinessException('分段砍价金额格式不合法');
            }

            $clean = [];

            foreach ($kjRows as $kjRow) {
                if (!is_array($kjRow)) {
                    continue;
                }

                $clean[] = [
                    'startnum'   => (int) ($kjRow['startnum'] ?? 0),
                    'endnum'     => (string) ($kjRow['endnum'] ?? ''),
                    'startmoney' => round((float) ($kjRow['startmoney'] ?? 0), 2),
                    'endmoney'   => round((float) ($kjRow['endmoney'] ?? 0), 2),
                ];
            }

            $kjdata = json_encode($clean, JSON_UNESCAPED_UNICODE);
        }

        $row = [
            'name'             => $name,
            'pic'              => $pic,
            'pics'             => trim((string) ($data['pics'] ?? '')),
            'sell_price'       => $sellPrice,
            'min_price'        => $minPrice,
            'helpnum'          => $helpnum,
            'limitHour'        => $limitHour,
            'stock'            => $stock,
            'directbuy'        => (int) ($data['directbuy'] ?? 0) === 1 ? 1 : 0,
            'freighttype'      => (int) ($data['freighttype'] ?? 1),
            'freightdata'      => trim((string) ($data['freightdata'] ?? '')),
            'freightcontent'   => trim((string) ($data['freightcontent'] ?? '')),
            'helpgive_type'    => $helpgiveType,
            'helpgive_percent' => $helpgivePercent,
            'helpgive_ff'      => 1 === $helpgiveFf ? 1 : 0,
            'weight'           => (int) ($data['weight'] ?? 0),
            'sales'            => (int) ($data['sales'] ?? 0),
            'kjdata'           => $kjdata,
            'sort'             => (int) ($data['sort'] ?? 0),
            'status'           => (int) ($data['status'] ?? 1) === 1 ? 1 : 0,
            'detail'           => (string) ($data['detail'] ?? ''),
            'starttime'        => $starttime,
            'endtime'          => $endtime,

        ];

        if ($id > 0) {
            $product = $this->findProduct($aid, $id);

            Db::name('kanjia_product')->where('id', (int) $product['id'])->update($row);

            return ['id' => $id];
        }

        $row['aid']        = $aid;
        $row['bid']        = 0;
        $row['createtime'] = time();

        return ['id' => (int) Db::name('kanjia_product')->insertGetId($row)];
    }

    /**
     * 删除砍价商品（已有发起记录/订单时拒绝）
     */
    public function productDelete(int $aid, int $id): void
    {
        $product = $this->findProduct($aid, $id);

        $joinNum = (int) ScopeService::query('kanjia_join', $aid)->where('proid', $product['id'])->count();
        if ($joinNum > 0) {
            throw new BusinessException('该商品已有砍价记录，不能删除');
        }

        $orderNum = (int) ScopeService::query('kanjia_order', $aid)->where('proid', $product['id'])->count();
        if ($orderNum > 0) {
            throw new BusinessException('该商品已有砍价订单，不能删除');
        }

        Db::name('kanjia_product')->where('id', (int) $product['id'])->delete();
    }

    /**
     * 商品上下架
     */
    public function productStatus(int $aid, int $id, int $status): void
    {
        $product = $this->findProduct($aid, $id);

        Db::name('kanjia_product')->where('id', (int) $product['id'])
            ->update(['status' => 1 === $status ? 1 : 0]);
    }

    /**
     * 批量上下架（逐条走单条校验）
     *
     * @param array<int, int|string> $ids
     */
    public function productStatusByIds(int $aid, array $ids, int $status): void
    {
        foreach ($this->normIds($ids) as $id) {
            $this->productStatus($aid, $id, $status);
        }
    }

    /**
     * 批量删除（逐条走单条业务拦截）
     *
     * @param array<int, int|string> $ids
     */
    public function productDeleteByIds(int $aid, array $ids): void
    {
        foreach ($this->normIds($ids) as $id) {
            $this->productDelete($aid, $id);
        }
    }

    /**
     * 商品详情（含分段砍价区间 kjdata 解析、所属商户名）
     *
     * @return array<string, mixed>
     */
    public function productDetail(int $aid, int $id): array
    {
        $product = $this->findProduct($aid, $id);

        $bidVal = (int) ($product['bid'] ?? 0);
        $product['bname'] = $bidVal > 0
            ? (string) (Db::name('business')->where('aid', $aid)->where('id', $bidVal)->value('name') ?: ('商户' . $bidVal))
            : '平台自营';

        $kj = json_decode((string) ($product['kjdata'] ?? ''), true);
        $product['kjdata_list'] = is_array($kj) ? $kj : [];
        $product['starttime']   = (int) ($product['starttime'] ?? 0);
        $product['endtime']     = (int) ($product['endtime'] ?? 0);
        $product['ischecked']   = (int) ($product['ischecked'] ?? 1);

        return $product;
    }

    /**
     * 商品审核（ischecked 1通过 2驳回；驳回须填原因）
     */
    public function productCheck(int $aid, int $id, int $ischecked, string $reason): void
    {
        $product = $this->findProduct($aid, $id);

        if (1 !== $ischecked && 2 !== $ischecked) {
            throw new BusinessException('非法的审核结果');
        }
        if (2 === $ischecked && '' === trim($reason)) {
            throw new BusinessException('请填写驳回原因');
        }

        Db::name('kanjia_product')->where('aid', $aid)->where('id', (int) $product['id'])->update([
            'ischecked'    => $ischecked,
            'check_reason' => 2 === $ischecked ? trim($reason) : '',
        ]);
    }

    /**
     * 时间戳归一：已是 int 直接用，'Y-m-d H:i' 等字符串转 strtotime
     */
    protected function toTs(int|string $v): int
    {
        if (is_int($v) || ctype_digit((string) $v)) {
            return (int) $v;
        }
        $ts = strtotime((string) $v);
        return false === $ts ? 0 : $ts;
    }

    /**
     * 归一化 id 列表
     *
     * @param array<int, mixed> $ids
     * @return array<int, int>
     */
    protected function normIds(array $ids): array
    {
        $out = [];
        foreach ($ids as $i) {
            $i = (int) $i;
            if ($i > 0) {
                $out[] = $i;
            }
        }
        if (empty($out)) {
            throw new BusinessException('请选择要操作的商品');
        }
        return $out;
    }

    /* ================= 发起记录 ================= */

    /**
     * 发起记录列表（懒过期：截止时间已过的进行中记录落失败）
     *
     * filters: keyword(会员昵称/商品名) / proid / status
     *
     * @param array<string, mixed> $filters
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function joinList(int $aid, int $page, int $pageSize, array $filters): array
    {
        $query = ScopeService::queryAs('kanjia_join', $aid, 'j')
            ->leftJoin('member m', 'm.id = j.mid')
            ->leftJoin('kanjia_product p', 'p.id = j.proid')
            ->field('j.*, m.nickname, m.headimg, p.name proname, p.pic propic, p.sell_price, p.min_price, p.helpnum target_helpnum, p.limitHour');

        if (!empty($filters['proid'])) {
            $query->where('j.proid', (int) $filters['proid']);
        }
        if (!empty($filters['mid'])) {
            $query->where('j.mid', (int) $filters['mid']);
        }
        if (isset($filters['status']) && '' !== (string) $filters['status']) {
            $query->where('j.status', (int) $filters['status']);
        }
        if (!empty($filters['keyword'])) {
            $keyword = trim((string) $filters['keyword']);
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('m.nickname', '%' . $keyword . '%')
                    ->whereOr('p.name', 'like', '%' . $keyword . '%');
            });
        }

        $total = (clone $query)->count();
        $rows  = $query->order('j.id', 'desc')->page($page, $pageSize)->select()->toArray();

        $now = time();

        foreach ($rows as &$row) {
            $row['status']      = $this->joinStatusAfterExpire($aid, $row, $now);
            $row['status_text'] = $this->joinStatusText((int) $row['status']);
            $row['deadtime']    = (int) $row['createtime'] + (int) $row['limitHour'] * 3600;
            $row['leftsecond']  = max(0, $row['deadtime'] - $now);
            $row['cut_percent'] = $this->cutPercent($row);
        }
        unset($row);

        return ['list' => $rows, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize];
    }

    /**
     * 帮砍明细（某次发起的帮砍流水）
     */
    public function helpList(int $aid, int $joinId): array
    {
        ScopeService::query('kanjia_join', $aid)->where('id', $joinId)->findOrEmpty();

        return ScopeService::queryAs('kanjia_help', $aid, 'h')
            ->leftJoin('member m', 'm.id = h.mid')
            ->field('h.*, m.nickname, m.headimg')
            ->where('h.joinid', $joinId)
            ->order('h.id', 'desc')
            ->select()
            ->toArray();
    }

    /**
     * 删除发起记录（仅进行中可删，避免动已完成履约数据）
     */
    public function joinDelete(int $aid, int $id): void
    {
        $join = ScopeService::query('kanjia_join', $aid)->where('id', $id)->find();

        if (empty($join)) {
            throw new BusinessException('发起记录不存在', 404, 404);
        }

        if (self::JOIN_DONE === (int) $join['status']) {
            throw new BusinessException('已完成的砍价记录不能删除');
        }

        Db::name('kanjia_join')->where('id', (int) $join['id'])->delete();
        Db::name('kanjia_help')->where('aid', $aid)->where('joinid', (int) $join['id'])->delete();
    }

    /* ================= 砍价订单（状态机同商城） ================= */

    /**
     * 订单列表
     *
     * status 口径：''=全部；0-4 订单状态；r1/r2/r3 → refund_status 1/2/3
     * filters: status / ordernum / proname / linkman / tel / start_date / end_date
     *
     * @param array<string, mixed> $filters
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function orderList(int $aid, int $page, int $pageSize, array $filters): array
    {
        $query = ScopeService::queryAs('kanjia_order', $aid, 'o')
            ->leftJoin('member m', 'm.id = o.mid')
            ->field('o.*, m.nickname, m.headimg')
            ->where(function ($q) {
                $q->whereNull('o.delete')->whereOr('o.delete', 0);
            });

        $status = (string) ($filters['status'] ?? '');

        if ('' !== $status) {
            if (str_starts_with($status, 'r')) {
                $refund = (int) substr($status, 1);

                if (!in_array($refund, [1, 2, 3], true)) {
                    throw new BusinessException('非法的退款状态');
                }

                $query->where('o.refund_status', $refund);
            } else {
                $query->where('o.status', (int) $status);
            }
        }

        if (!empty($filters['ordernum'])) {
            $query->whereLike('o.ordernum', '%' . trim((string) $filters['ordernum']) . '%');
        }
        if (!empty($filters['proname'])) {
            $query->whereLike('o.proname', '%' . trim((string) $filters['proname']) . '%');
        }
        if (!empty($filters['linkman'])) {
            $query->whereLike('o.linkman', '%' . trim((string) $filters['linkman']) . '%');
        }
        if (!empty($filters['tel'])) {
            $query->whereLike('o.tel', '%' . trim((string) $filters['tel']) . '%');
        }
        if (!empty($filters['start_date'])) {
            $query->where('o.createtime', '>=', strtotime((string) $filters['start_date']));
        }
        if (!empty($filters['end_date'])) {
            $query->where('o.createtime', '<', strtotime((string) $filters['end_date']) + 86400);
        }

        $total = (clone $query)->count();
        $rows  = $query->order('o.id', 'desc')->page($page, $pageSize)->select()->toArray();

        foreach ($rows as &$row) {
            $row['status_text'] = $this->orderStatusText((int) $row['status']);
            $row['refund_text'] = $this->refundStatusText((int) $row['refund_status']);
        }
        unset($row);

        return ['list' => $rows, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize];
    }

    /**
     * 发货（status=1 → 2；已发货时仅更新物流信息）
     */
    public function orderShip(int $aid, int $orderId, string $expressCom, string $expressNo): void
    {
        $expressCom = trim($expressCom);
        $expressNo  = trim($expressNo);

        if ('' === $expressCom || '' === $expressNo) {
            throw new BusinessException('请填写快递公司与快递单号');
        }

        $order = $this->findOrder($aid, $orderId);

        if (self::ORDER_PAID === (int) $order['status']) {
            ScopeService::query('kanjia_order', $aid)->where('id', $orderId)->update([
                'express_com' => $expressCom,
                'express_no'  => $expressNo,
                'send_time'   => time(),
                'status'      => self::ORDER_SHIPPED,
            ]);

            return;
        }

        if (self::ORDER_SHIPPED === (int) $order['status']) {
            ScopeService::query('kanjia_order', $aid)->where('id', $orderId)->update([
                'express_com' => $expressCom,
                'express_no'  => $expressNo,
            ]);

            return;
        }

        throw new BusinessException('当前订单状态不可发货');
    }

    /**
     * 退款审核（pass=1 同意→PayService 原路退款 + payorder 登记 + 回补库存；pass=2 驳回）
     */
    public function orderRefundCheck(int $aid, int $orderId, int $pass, string $remark): void
    {
        if (1 !== $pass && 2 !== $pass) {
            throw new BusinessException('非法的审核结果');
        }

        $order = $this->findOrder($aid, $orderId);

        if (1 !== (int) $order['refund_status']) {
            throw new BusinessException('该订单无待审核的退款申请');
        }

        if (2 === $pass) {
            ScopeService::query('kanjia_order', $aid)->where('id', $orderId)->update([
                'refund_status'      => 3,
                'refund_checkremark' => trim($remark),
                'refund_time'        => time(),
            ]);

            return;
        }

        if (!in_array((int) $order['status'], [self::ORDER_PAID, self::ORDER_SHIPPED], true)) {
            throw new BusinessException('该订单状态不允许退款');
        }

        Db::transaction(function () use ($aid, $orderId, $remark) {
            $locked = Db::name('kanjia_order')
                ->where('aid', $aid)
                ->where('id', $orderId)
                ->lock(true)
                ->find();

            if (empty($locked) || 1 !== (int) $locked['refund_status']
                || !in_array((int) $locked['status'], [self::ORDER_PAID, self::ORDER_SHIPPED], true)) {
                throw new BusinessException('订单状态已变化，请刷新后重试');
            }

            $refundMoney = (float) $locked['refund_money'] > 0
                ? (float) $locked['refund_money']
                : (float) $locked['totalprice'];

            // 原路退款（mock 即时成功；真实模式微信 V3 原路退款）
            $this->payService->refund($locked, $refundMoney, 'KJ' . (string) $locked['ordernum']);

            // payorder 退款登记（type=kanjia 原支付单）
            $this->markPayorderRefund($aid, (string) $locked['ordernum'], $refundMoney);

            // 已支付未发货退款回补库存
            if (self::ORDER_PAID === (int) $locked['status']) {
                $this->restoreStock($locked);
            }

            Db::name('kanjia_order')->where('id', $orderId)->update([
                'status'             => self::ORDER_CLOSED,
                'refund_status'      => 2,
                'refund_money'       => $refundMoney,
                'refund_time'        => time(),
                'refund_checkremark' => trim($remark),
            ]);
        });
    }

    /**
     * 关闭订单（仅未支付；回补库存）
     */
    public function orderClose(int $aid, int $orderId): void
    {
        $order = $this->findOrder($aid, $orderId);

        if (self::ORDER_UNPAID !== (int) $order['status']) {
            throw new BusinessException('仅未支付订单可关闭');
        }

        Db::transaction(function () use ($aid, $orderId, $order) {
            Db::name('kanjia_order')
                ->where('aid', $aid)->where('id', $orderId)->where('status', self::ORDER_UNPAID)
                ->update(['status' => self::ORDER_CLOSED]);

            $this->restoreStock($order);
        });
    }

    /**
     * 订单备注
     */
    public function orderRemark(int $aid, int $orderId, string $remark): void
    {
        $this->findOrder($aid, $orderId);

        ScopeService::query('kanjia_order', $aid)->where('id', $orderId)
            ->update(['remark' => trim($remark)]);
    }

    /**
     * 订单详情（含下单会员）
     *
     * @return array{order: array, member: array}
     */
    public function orderDetail(int $aid, int $orderId): array
    {
        $order = $this->findOrder($aid, $orderId);
        $member = Db::name('member')->field('id,nickname,headimg,realname,tel')
            ->where('id', (int) $order['mid'])->find() ?: [];
        $order['status_text'] = $this->orderStatusText((int) $order['status']);
        $order['refund_text'] = $this->refundStatusText((int) $order['refund_status']);

        return ['order' => $order, 'member' => $member];
    }

    /**
     * 删除订单（单 id 或批量 ids[]；aid 租户隔离）
     *
     * @param array<int, int> $ids
     */
    public function orderDelete(int $aid, array $ids): void
    {
        if (empty($ids)) {
            throw new BusinessException('请选择要删除的订单');
        }
        ScopeService::query('kanjia_order', $aid)->whereIn('id', $ids)->delete();
    }

    /**
     * 改为已支付（仅未支付；mock 后台支付）
     */
    public function orderIspay(int $aid, int $orderId): void
    {
        $order = $this->findOrder($aid, $orderId);
        if (self::ORDER_UNPAID !== (int) $order['status']) {
            throw new BusinessException('仅未支付订单可改为已支付');
        }
        ScopeService::query('kanjia_order', $aid)->where('id', $orderId)->update([
            'status'  => self::ORDER_PAID,
            'paytime' => time(),
            'paytype' => '后台支付',
        ]);
    }

    /**
     * 核销并确认收货（status 1已支付 / 2已发货 → 3已收货）
     */
    public function orderHexiao(int $aid, int $orderId): void
    {
        $order = $this->findOrder($aid, $orderId);
        if (!in_array((int) $order['status'], [self::ORDER_PAID, self::ORDER_SHIPPED], true)) {
            throw new BusinessException('订单状态不符合核销收货要求');
        }
        ScopeService::query('kanjia_order', $aid)->where('id', $orderId)->update([
            'status'       => self::ORDER_COLLECT,
            'collect_time' => time(),
        ]);
    }

    /**
     * 订单导出行（列对齐 canzhao KanjiaOrder/excel）
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<int, mixed>>
     */
    public function orderExportRows(int $aid, array $filters): array
    {
        $query = ScopeService::queryAs('kanjia_order', $aid, 'o')
            ->where(function ($q) {
                $q->whereNull('o.delete')->whereOr('o.delete', 0);
            });

        $status = (string) ($filters['status'] ?? '');
        if ('' !== $status) {
            if (str_starts_with($status, 'r')) {
                $query->where('o.refund_status', (int) substr($status, 1));
            } else {
                $query->where('o.status', (int) $status);
            }
        }
        foreach (['ordernum' => 'o.ordernum', 'proname' => 'o.proname', 'linkman' => 'o.linkman', 'tel' => 'o.tel'] as $k => $col) {
            if (!empty($filters[$k])) {
                $query->whereLike($col, '%' . trim((string) $filters[$k]) . '%');
            }
        }
        if (!empty($filters['start_date'])) {
            $query->where('o.createtime', '>=', strtotime((string) $filters['start_date']));
        }
        if (!empty($filters['end_date'])) {
            $query->where('o.createtime', '<', strtotime((string) $filters['end_date']) + 86400);
        }

        $rows = $query->order('o.id', 'desc')->limit(5000)->select()->toArray();
        $data = [];
        foreach ($rows as $vo) {
            $data[] = [
                ' ' . (string) $vo['ordernum'],
                $vo['proname'] ?? '',
                $vo['sell_price'] ?? '',
                $vo['product_price'] ?? '',
                $vo['totalprice'] ?? '',
                $vo['paytype'] ?? '',
                $vo['linkman'] ?? '',
                $vo['tel'] ?? '',
                trim(($vo['area'] ?? '') . ' ' . ($vo['address'] ?? '')),
                $vo['freight_text'] ?? '',
                ($vo['express_com'] ?? '') . (!empty($vo['express_no']) ? '(' . $vo['express_no'] . ')' : ''),
                $vo['remark'] ?? '',
                date('Y-m-d H:i:s', (int) $vo['createtime']),
                $this->orderStatusText((int) $vo['status']),
                $this->refundStatusText((int) $vo['refund_status']),
            ];
        }

        return $data;
    }
    /* ================= 核销码 ================= */

    /**
     * 卡密列表（在线卡密库）
     *
     * filters: proid / status / keyword(卡密内容/订单号/昵称)
     *
     * @param array<string, mixed> $filters
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function codelistList(int $aid, int $page, int $pageSize, array $filters): array
    {
        $query = ScopeService::queryAs('kanjia_codelist', $aid, 'c')
            ->leftJoin('kanjia_product p', 'p.id = c.proid')
            ->field('c.*, p.name proname');

        if (!empty($filters['proid'])) {
            $query->where('c.proid', (int) $filters['proid']);
        }
        if (isset($filters['status']) && '' !== (string) $filters['status']) {
            $query->where('c.status', (int) $filters['status']);
        }
        if (!empty($filters['keyword'])) {
            $keyword = trim((string) $filters['keyword']);
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('c.content', '%' . $keyword . '%')
                    ->whereOr('c.ordernum', 'like', '%' . $keyword . '%')
                    ->whereOr('c.nickname', 'like', '%' . $keyword . '%');
            });
        }

        $total = (clone $query)->count();
        $rows  = $query->order('c.id', 'desc')->page($page, $pageSize)->select()->toArray();

        foreach ($rows as &$row) {
            $row['status_text'] = 1 === (int) $row['status'] ? '已售出' : '未售出';
        }
        unset($row);

        return ['list' => $rows, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize];
    }

    /**
     * 核销（到店自提订单：核销码/订单号 → 确认收货）
     *
     * @return array{ordernum: string, proname: string}
     */
    public function hexiao(int $aid, string $code): array
    {
        $code = trim($code);

        if ('' === $code) {
            throw new BusinessException('请输入核销码或订单号');
        }

        $order = ScopeService::query('kanjia_order', $aid)
            ->where(function ($q) use ($code) {
                $q->where('hexiao_code', $code)->whereOr('ordernum', $code);
            })
            ->find();

        if (empty($order)) {
            throw new BusinessException('未找到对应的砍价订单');
        }

        if (!in_array((int) $order['status'], [self::ORDER_PAID, self::ORDER_SHIPPED], true)) {
            throw new BusinessException('订单状态不符合核销要求');
        }

        Db::name('kanjia_order')->where('id', (int) $order['id'])
            ->update(['status' => self::ORDER_COLLECT, 'collect_time' => time()]);

        return ['ordernum' => (string) $order['ordernum'], 'proname' => (string) $order['proname']];
    }

    /* ================= C 端核心链路（api 预留） ================= */

    /**
     * 发起砍价（写 join，now_price=原价；同一会员同一商品仅一条进行中/已完成记录）
     *
     * @return array{joinid: int, now_price: float}
     */
    public function startJoin(int $aid, int $mid, int $proid): array
    {
        $product = ScopeService::query('kanjia_product', $aid)->where('id', $proid)->find();

        if (empty($product) || 1 !== (int) $product['status']) {
            throw new BusinessException('砍价商品不存在或已下架');
        }
        if ((int) $product['stock'] <= 0) {
            throw new BusinessException('该砍价商品已被抢光了');
        }

        $exists = ScopeService::query('kanjia_join', $aid)
            ->where('mid', $mid)->where('proid', $proid)
            ->where('status', '<>', self::JOIN_FAILED)
            ->find();

        if (!empty($exists)) {
            return ['joinid' => (int) $exists['id'], 'now_price' => (float) $exists['now_price']];
        }

        Db::transaction(function () use ($aid, $mid, $product) {
            Db::name('kanjia_join')->insert([
                'aid'        => $aid,
                'bid'        => (int) $product['bid'],
                'mid'        => $mid,
                'proid'      => (int) $product['id'],
                'now_price'  => (float) $product['sell_price'],
                'helpnum'    => 0,
                'createtime' => time(),
                'status'     => self::JOIN_ONGOING,
                'endtime'    => 0,
                'isbuy'      => 0,
            ]);

            Db::name('kanjia_product')->where('id', (int) $product['id'])->inc('saleing')->update();
        });

        $join = ScopeService::query('kanjia_join', $aid)
            ->where('mid', $mid)->where('proid', $proid)
            ->order('id', 'desc')->find();

        return ['joinid' => (int) $join['id'], 'now_price' => (float) $join['now_price']];
    }

    /**
     * 帮砍一刀（同一会员同一发起仅可帮砍一次；金额按分段/递减随机，砍到下限置完成）
     *
     * @return array{cut_price: float, now_price: float, status: int, givescore: int, givemoney: float}
     */
    public function helpCut(int $aid, int $mid, int $joinId): array
    {
        return Db::transaction(function () use ($aid, $mid, $joinId) {
            $join = Db::name('kanjia_join')
                ->where('aid', $aid)->where('id', $joinId)->lock(true)->find();

            if (empty($join)) {
                throw new BusinessException('砍价记录不存在', 404, 404);
            }

            $product = Db::name('kanjia_product')->where('aid', $aid)->where('id', $join['proid'])->find();

            if (empty($product)) {
                throw new BusinessException('砍价商品不存在');
            }

            $now = time();

            if (self::JOIN_ONGOING === (int) $join['status']
                && $now > (int) $join['createtime'] + (int) $product['limitHour'] * 3600) {
                Db::name('kanjia_join')->where('id', $joinId)->where('status', self::JOIN_ONGOING)
                    ->update(['status' => self::JOIN_FAILED]);

                throw new BusinessException('砍价已超时结束');
            }

            if (self::JOIN_DONE === (int) $join['status']) {
                throw new BusinessException('该砍价已完成');
            }
            if (self::JOIN_FAILED === (int) $join['status']) {
                throw new BusinessException('砍价已结束');
            }
            if (1 !== (int) $product['status']) {
                throw new BusinessException('商品已下架');
            }

            $helped = Db::name('kanjia_help')
                ->where('aid', $aid)->where('joinid', $joinId)->where('mid', $mid)->find();

            if (!empty($helped)) {
                throw new BusinessException('您已经砍过了');
            }

            $nowPrice = (float) $join['now_price'];
            $minPrice = (float) $product['min_price'];
            $helpnum  = (int) $join['helpnum'];

            // 上一刀金额（内置递减算法用于逐刀收敛）
            $lastHelp = Db::name('kanjia_help')
                ->where('aid', $aid)->where('joinid', $joinId)
                ->order('id', 'desc')->find();
            $prevCut = empty($lastHelp) ? 0.0 : (float) $lastHelp['cut_price'];

            $cut = $this->computeCut($product, $helpnum, $nowPrice - $minPrice, $prevCut);

            if ($cut <= 0) {
                throw new BusinessException('该砍价已砍到底价');
            }

            $newPrice = round($nowPrice - $cut, 2);
            $finished = $newPrice <= $minPrice;

            if ($finished) {
                $newPrice = $minPrice;

                Db::name('kanjia_join')->where('id', $joinId)->update([
                    'now_price' => $newPrice,
                    'helpnum'   => $helpnum + 1,
                    'status'    => self::JOIN_DONE,
                    'endtime'   => $now,
                ]);

                // 砍到底价即锁库存
                Db::name('kanjia_product')
                    ->where('id', (int) $product['id'])->where('stock', '>', 0)
                    ->update([
                        'sales'   => Db::raw('sales+1'),
                        'saleing' => Db::raw('saleing-1'),
                        'stock'   => Db::raw('stock-1'),
                    ]);
            } else {
                Db::name('kanjia_join')->where('id', $joinId)->update([
                    'now_price' => $newPrice,
                    'helpnum'   => $helpnum + 1,
                ]);
            }

            Db::name('kanjia_help')->insert([
                'aid'         => $aid,
                'bid'         => (int) $product['bid'],
                'mid'         => $mid,
                'helpmid'     => (int) $join['mid'],
                'proid'       => (int) $product['id'],
                'joinid'      => $joinId,
                'cut_price'   => $cut,
                'after_price' => $newPrice,
                'createtime'  => $now,
            ]);

            Db::name('kanjia_product')->where('id', (int) $product['id'])->inc('helpcount')->update();

            // 帮砍奖励（type 0积分 1余额；ff=0 实发）
            $giveScore = 0;
            $giveMoney = 0.0;

            if ($cut > 0 && (float) $product['helpgive_percent'] > 0 && 0 === (int) $product['helpgive_ff']) {
                if (0 === (int) $product['helpgive_type']) {
                    $giveScore = (int) floor($cut * (float) $product['helpgive_percent'] * 0.01);

                    if ($giveScore > 0) {
                        $this->giveScore($aid, $mid, $giveScore, '帮好友砍价奖励');
                    }
                } else {
                    $giveMoney = round($cut * (float) $product['helpgive_percent'] * 0.01, 2);

                    if ($giveMoney > 0) {
                        $this->giveMoney($aid, $mid, $giveMoney, '帮好友砍价奖励');
                    }
                }
            }

            return [
                'cut_price' => $cut,
                'now_price' => $newPrice,
                'status'    => $finished ? self::JOIN_DONE : self::JOIN_ONGOING,
                'givescore' => $giveScore,
                'givemoney' => $giveMoney,
            ];
        });
    }

    /**
     * 达底价下单（directbuy=1 允许未到底价直接购买；写 order + payorder，mock 即时支付成功）
     *
     * @param array<string, mixed> $extra linkman/tel/area/address/message/freight_price...
     * @return array{orderid: int, ordernum: string, totalprice: float, status: int}
     */
    public function createOrder(int $aid, int $mid, int $joinId, array $extra = []): array
    {
        return Db::transaction(function () use ($aid, $mid, $joinId, $extra) {
            $join = Db::name('kanjia_join')
                ->where('aid', $aid)->where('id', $joinId)->lock(true)->find();

            if (empty($join)) {
                throw new BusinessException('砍价记录不存在', 404, 404);
            }
            if ((int) $join['mid'] !== $mid) {
                throw new BusinessException('只能购买自己发起的砍价');
            }
            if (self::JOIN_FAILED === (int) $join['status']) {
                throw new BusinessException('砍价已失败');
            }
            if (1 === (int) $join['isbuy']) {
                throw new BusinessException('该砍价已下单过了');
            }

            $product = Db::name('kanjia_product')
                ->where('aid', $aid)->where('status', 1)->where('id', $join['proid'])->find();

            if (empty($product)) {
                throw new BusinessException('商品不存在或已下架');
            }
            if (0 === (int) $product['directbuy'] && (float) $join['now_price'] > (float) $product['min_price']) {
                throw new BusinessException('砍到底价后才能购买');
            }
            if (self::JOIN_ONGOING === (int) $join['status'] && (int) $product['stock'] <= 0) {
                throw new BusinessException('商品库存不足');
            }

            $nowPrice    = round((float) $join['now_price'], 2);
            $freightFee  = round((float) ($extra['freight_price'] ?? 0), 2);
            $totalPrice  = round(max(0, $nowPrice + $freightFee), 2);
            $ordernum    = date('ymdHis') . $aid . random_int(1000, 9999);

            $orderId = (int) Db::name('kanjia_order')->insertGetId([
                'aid'           => $aid,
                'bid'           => (int) $product['bid'],
                'mid'           => $mid,
                'ordernum'      => $ordernum,
                'title'         => (string) $product['name'],
                'proid'         => (int) $product['id'],
                'proname'       => (string) $product['name'],
                'propic'        => (string) $product['pic'],
                'num'           => 1,
                'totalprice'    => $totalPrice,
                'product_price' => $nowPrice,
                'freight_price' => $freightFee,
                'sell_price'    => (float) $product['sell_price'],
                'cost_price'    => (float) ($product['cost_price'] ?? 0),
                'createtime'    => time(),
                'status'        => self::ORDER_UNPAID,
                'linkman'       => trim((string) ($extra['linkman'] ?? '')),
                'tel'           => trim((string) ($extra['tel'] ?? '')),
                'area'          => trim((string) ($extra['area'] ?? '')),
                'address'       => trim((string) ($extra['address'] ?? '')),
                'message'       => trim((string) ($extra['message'] ?? '')),
                'freight_text'  => trim((string) ($extra['freight_text'] ?? '包邮')),
                'hexiao_code'   => strtoupper(bin2hex(random_bytes(8))),
                'joinid'        => $joinId,
                'platform'      => (string) ($extra['platform'] ?? 'wx'),
            ]);

            // 支付单（type=kanjia）
            Db::name('payorder')->insert([
                'aid'        => $aid,
                'bid'        => (int) $product['bid'],
                'mid'        => $mid,
                'orderid'    => $orderId,
                'ordernum'   => $ordernum,
                'title'      => (string) $product['name'],
                'money'      => $totalPrice,
                'score'      => 0,
                'type'       => 'kanjia',
                'createtime' => time(),
                'status'     => 0,
                'platform'   => (string) ($extra['platform'] ?? 'wx'),
            ]);

            // 进行中直接购买：落完成 + 扣库存（砍到底价的发起记录库存已在帮砍时扣过）
            if (self::JOIN_ONGOING === (int) $join['status']) {
                Db::name('kanjia_join')->where('id', $joinId)
                    ->update(['status' => self::JOIN_DONE, 'endtime' => time(), 'isbuy' => 1]);

                Db::name('kanjia_product')->where('id', (int) $product['id'])->where('stock', '>', 0)
                    ->update([
                        'sales'   => Db::raw('sales+1'),
                        'saleing' => Db::raw('saleing-1'),
                        'stock'   => Db::raw('stock-1'),
                    ]);
            } else {
                Db::name('kanjia_join')->where('id', $joinId)->update(['isbuy' => 1]);
            }

            // mock 模式即时支付成功（真实模式由支付回调走 payKanjiOrder）
            if (PayService::isMock()) {
                $this->payKanjiOrder($aid, $orderId, 'wechat', 'MOCK' . time() . random_int(1000, 9999));
            }

            return [
                'orderid'    => $orderId,
                'ordernum'   => $ordernum,
                'totalprice' => $totalPrice,
                'status'     => PayService::isMock() ? self::ORDER_PAID : self::ORDER_UNPAID,
            ];
        });
    }

    /**
     * 砍价订单支付成功入账（幂等：仅 status=0 → 1）
     */
    public function payKanjiOrder(int $aid, int $orderId, string $payType, string $payNum): bool
    {
        $order = ScopeService::query('kanjia_order', $aid)->where('id', $orderId)->find();

        if (empty($order) || self::ORDER_UNPAID !== (int) $order['status']) {
            return false;
        }

        Db::name('kanjia_order')->where('id', $orderId)->where('status', self::ORDER_UNPAID)->update([
            'status'     => self::ORDER_PAID,
            'paytype'    => $payType,
            'paytypeid'  => PayService::PAY_TYPE_IDS[$payType] ?? 0,
            'paynum'     => $payNum,
            'paytime'    => time(),
        ]);

        Db::name('payorder')
            ->where('aid', $aid)->where('type', 'kanjia')->where('ordernum', (string) $order['ordernum'])
            ->where('status', 0)
            ->update([
                'status'    => 1,
                'paytype'   => $payType,
                'paytypeid' => PayService::PAY_TYPE_IDS[$payType] ?? 0,
                'paynum'    => $payNum,
                'paytime'   => time(),
            ]);

        return true;
    }

    /* ================= C 端读接口（api 预留） ================= */

    /**
     * C 端砍价商品列表（仅上架，复用后台 productList 口径）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function apiProductList(int $aid, int $page, int $pageSize, string $keyword = ''): array
    {
        return $this->productList($aid, $page, $pageSize, $keyword, '1');
    }

    /**
     * C 端砍价商品详情（仅上架；附带本人当前发起记录）
     *
     * @return array<string, mixed>
     */
    public function apiProductDetail(int $aid, int $mid, int $proid): array
    {
        $product = $this->findProduct($aid, $proid);

        if (1 !== (int) ($product['status'] ?? 0)) {
            throw new BusinessException('砍价商品不存在或已下架', 404, 404);
        }

        $product['helpgive_text'] = $this->helpgiveText($product);

        $join = ScopeService::query('kanjia_join', $aid)
            ->where('mid', $mid)->where('proid', $proid)
            ->where('status', '<>', self::JOIN_FAILED)
            ->order('id', 'desc')->find();

        if (!empty($join)) {
            $join['deadtime']   = (int) $join['createtime'] + (int) $product['limitHour'] * 3600;
            $join['leftsecond'] = max(0, (int) $join['deadtime'] - time());
        }

        $product['myjoin'] = $join ?: null;

        return $product;
    }

    /**
     * 我的砍价订单（C 端分页）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function apiMyOrders(int $aid, int $mid, string $status, int $page, int $pageSize): array
    {
        $query = ScopeService::query('kanjia_order', $aid)->where('mid', $mid);

        if ('' !== $status) {
            $query->where('status', (int) $status);
        }

        $total = (clone $query)->count();
        $rows  = $query->order('id', 'desc')->page($page, $pageSize)->select()->toArray();

        foreach ($rows as &$row) {
            $row['status_text'] = $this->orderStatusText((int) $row['status']);
        }
        unset($row);

        return ['list' => $rows, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize];
    }

    /* ================= 内部工具 ================= */

    /**
     * 计算本次帮砍金额（分段 kjdata 优先；否则内置递减随机，保证约 helpnum 刀砍到底）
     */
    protected function computeCut(array $product, int $doneNum, float $remaining, float $prevCut = 0.0): float
    {
        $remaining = round($remaining, 2);

        if ($remaining <= 0) {
            return 0.0;
        }

        // 分段砍价金额（商家配置）
        $kjdata = json_decode((string) ($product['kjdata'] ?? ''), true);

        if (is_array($kjdata) && !empty($kjdata)) {
            foreach ($kjdata as $kj) {
                $startnum = (int) ($kj['startnum'] ?? 0);
                $endnum   = (string) ($kj['endnum'] ?? '');
                $hit      = $startnum <= $doneNum + 1
                    && ('最后一' === $endnum || (int) $endnum >= $doneNum + 1 || '' === $endnum);

                if ($hit) {
                    $lo = (int) round((float) ($kj['startmoney'] ?? 0) * 100);
                    $hi = (int) round((float) ($kj['endmoney'] ?? ($kj['startmoney'] ?? 0)) * 100);

                    if ($hi < $lo) {
                        $hi = $lo;
                    }

                    $cut = random_int(max(0, $lo), max(0, $hi)) / 100;

                    return round(min($cut, $remaining), 2);
                }
            }
        }

        // 内置递减：首刀大额（剩余 35%-55%），其后逐刀收敛（不超过上一刀 85%），最后一刀收口
        $target       = max(1, (int) $product['helpnum']);
        $remainingNum = max(1, $target - $doneNum);

        if (1 === $remainingNum) {
            return $remaining; // 最后一刀直接砍到底
        }

        if ($doneNum <= 0) {
            // 首刀：剩余金额的 35%-55%
            $lo = (int) round($remaining * 0.35 * 100);
            $hi = (int) round($remaining * 0.55 * 100);
        } else {
            // 后续刀：不超过上一刀的 85%，同时预留后续刀数不至断尾
            $capByPrev  = $prevCut > 0 ? $prevCut * 0.85 : $remaining;
            $avg        = $remaining / $remainingNum;
            $maxCut     = min($capByPrev, $avg * 1.5, $remaining);
            $minCut     = min($avg * 0.3, $maxCut);
            $lo         = max(1, (int) round($minCut * 100));
            $hi         = max($lo, (int) round($maxCut * 100));
        }

        $cut = random_int($lo, $hi) / 100;

        return round(min($cut, $remaining), 2);
    }

    /**
     * 发起记录懒过期（读侧落失败态）
     *
     * @param array<string, mixed> $join
     */
    protected function joinStatusAfterExpire(int $aid, array $join, int $now): int
    {
        $status = (int) $join['status'];

        if (self::JOIN_ONGOING !== $status) {
            return $status;
        }

        $deadtime = (int) $join['createtime'] + (int) ($join['limitHour'] ?? 12) * 3600;

        if ($now <= $deadtime) {
            return $status;
        }

        Db::name('kanjia_join')->where('id', (int) $join['id'])->where('status', self::JOIN_ONGOING)
            ->update(['status' => self::JOIN_FAILED]);

        return self::JOIN_FAILED;
    }

    /**
     * 砍价进度百分比
     *
     * @param array<string, mixed> $join
     */
    protected function cutPercent(array $join): int
    {
        $sell = (float) ($join['sell_price'] ?? 0);
        $min  = (float) ($join['min_price'] ?? 0);

        if ($sell <= $min) {
            return (float) $join['now_price'] <= $min ? 100 : 0;
        }

        $percent = (int) floor(($sell - (float) $join['now_price']) / ($sell - $min) * 100);

        return max(0, min(100, $percent));
    }

    /**
     * 帮砍奖励描述
     *
     * @param array<string, mixed> $product
     */
    protected function helpgiveText(array $product): string
    {
        $percent = (float) ($product['helpgive_percent'] ?? 0);

        if ($percent <= 0) {
            return '无奖励';
        }

        $unit = 0 === (int) $product['helpgive_type'] ? '积分' : '余额';
        $ff   = 1 === (int) $product['helpgive_ff'] ? '（仅展示）' : '';

        return '帮砍得' . $unit . ' ' . rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.') . '%' . $ff;
    }

    /**
     * 发放积分奖励（member.score + score_log）
     */
    protected function giveScore(int $aid, int $mid, int $score, string $remark): void
    {
        Db::name('member')->where('id', $mid)->where('aid', $aid)->inc('score', $score)->update();

        Db::name('score_log')->insert([
            'aid'         => $aid,
            'mid'         => $mid,
            'score'       => $score,
            'remark'      => $remark,
            'type'        => 1,
            'create_time' => time(),
        ]);
    }

    /**
     * 发放余额奖励（member.money + member_moneylog）
     */
    protected function giveMoney(int $aid, int $mid, float $money, string $remark): void
    {
        $member = Db::name('member')->where('id', $mid)->where('aid', $aid)->find();

        if (empty($member)) {
            return;
        }

        $after = round((float) ($member['money'] ?? 0) + $money, 2);

        Db::name('member')->where('id', $mid)->where('aid', $aid)->update(['money' => $after]);

        Db::name('member_moneylog')->insert([
            'aid'        => $aid,
            'mid'        => $mid,
            'money'      => $money,
            'after'      => $after,
            'createtime' => time(),
            'remark'     => $remark,
            'paytype'    => 'kanjia',
        ]);
    }

    /**
     * payorder 退款登记（原路退款的账本记录：refund_money/refund_time）
     */
    protected function markPayorderRefund(int $aid, string $ordernum, float $refundMoney): void
    {
        if ('' === $ordernum || $refundMoney <= 0) {
            return;
        }

        Db::name('payorder')
            ->where('aid', $aid)
            ->where('type', 'kanjia')
            ->where('ordernum', $ordernum)
            ->where('status', 1)
            ->order('id', 'desc')
            ->limit(1)
            ->update([
                'refund_money' => $refundMoney,
                'refund_time'  => time(),
            ]);
    }

    /**
     * 回补库存（退款/关单）
     *
     * @param array<string, mixed> $order
     */
    protected function restoreStock(array $order): void
    {
        $proid = (int) ($order['proid'] ?? 0);

        if ($proid <= 0) {
            return;
        }

        Db::name('kanjia_product')->where('id', $proid)->where('aid', (int) ($order['aid'] ?? 0))
            ->update([
                'sales' => Db::raw('sales-1'),
                'stock' => Db::raw('stock+1'),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function findProduct(int $aid, int $id): array
    {
        $product = ScopeService::query('kanjia_product', $aid)->where('id', $id)->find();

        if (empty($product)) {
            throw new BusinessException('砍价商品不存在', 404, 404);
        }

        return $product;
    }

    /**
     * @return array<string, mixed>
     */
    protected function findOrder(int $aid, int $orderId): array
    {
        $order = ScopeService::query('kanjia_order', $aid)->where('id', $orderId)->find();

        if (empty($order)) {
            throw new BusinessException('订单不存在', 404, 404);
        }

        return $order;
    }

    protected function joinStatusText(int $status): string
    {
        return match ($status) {
            self::JOIN_DONE    => '已完成',
            self::JOIN_FAILED  => '已失败',
            default            => '进行中',
        };
    }

    protected function orderStatusText(int $status): string
    {
        return match ($status) {
            self::ORDER_PAID    => '已支付',
            self::ORDER_SHIPPED => '已发货',
            self::ORDER_COLLECT => '已收货',
            self::ORDER_CLOSED  => '已关闭',
            default             => '未支付',
        };
    }

    protected function refundStatusText(int $refundStatus): string
    {
        return match ($refundStatus) {
            1      => '退款审核中',
            2      => '已退款',
            3      => '退款驳回',
            default => '',
        };
    }
}
