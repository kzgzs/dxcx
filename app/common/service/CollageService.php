<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\constant\ExpressCompany;
use app\common\exception\BusinessException;
use think\facade\Db;

/**
 * 拼团服务（P13-3 客户端营销·多人拼团）
 *
 * 表口径（基线 collage_* 表，金额单位「元」）：
 * - collage_sysset      拼团设置：teamnum 默认成团人数 / teamhour 成团时限（小时）
 *                       / leadermoney 默认团长优惠 / autoshdays 自动收货天数
 *                       / comment_check 评价审核 / timeset 开团场次 / team_refund 团长退款是否解散团
 * - collage_product     拼团商品（teamnum/teamhour/leadermoney/buymax/stock/ischecked 审核）
 * - collage_guige       商品多规格（ks 为规格键）
 * - collage_order_team  团：status 0未支付 1进行中 2成功 3失败；num 当前有效人数
 * - collage_order       拼团订单：status 0未支付 1已支付 2已发货 3已收货 4关闭
 *                       refund_status 0无 1审核中 2已同意 3已驳回；buytype 1单买 2发团 3参团
 *
 * 状态机要点：
 * - 成团判定 confirmTeam()：团内有效订单数（已支付及以后）满 teamnum → 团置 2 成功，
 *   订单保持已支付待发货正常履约
 * - 超时解散 dissolveExpiredTeams()（schedule:run 挂接）：
 *   team.status=1 且 createtime+teamhour*3600 < now → 团置 3 失败，
 *   团内已支付订单（发团/参团）置 4 关闭并「原路退款 + 退抵扣积分 + 扣赠送积分 + 退券」，
 *   单笔团一个事务，整体幂等（团 status=1 条件 + 行锁）
 * - 退款链路复用 PayService::refund()（mock 即时成功，真实模式走微信 V3 原路退款），
 *   同时在 payorder（type=collage）上登记 refund_money/refund_time
 */
class CollageService
{
    /** 订单状态 */
    public const ORDER_UNPAID    = 0;
    public const ORDER_PAID      = 1;
    public const ORDER_SHIPPED   = 2;
    public const ORDER_COLLECTED = 3;
    public const ORDER_CLOSED    = 4;

    /** 团状态 */
    public const TEAM_UNPAID  = 0;
    public const TEAM_ONGOING = 1;
    public const TEAM_SUCCESS = 2;
    public const TEAM_FAILED  = 3;

    public const ORDER_STATUS_TEXT = [0 => '未支付', 1 => '已支付', 2 => '已发货', 3 => '已收货', 4 => '已关闭'];
    public const REFUND_TEXT       = [0 => '', 1 => '退款审核中', 2 => '已退款', 3 => '退款已驳回'];
    public const BUYTYPE_TEXT      = [1 => '单买', 2 => '发团', 3 => '参团'];
    public const TEAM_STATUS_TEXT  = [0 => '未支付', 1 => '拼团中', 2 => '成团成功', 3 => '成团失败'];

    public function __construct(
        protected PayService $payService,
        protected CouponService $couponService,
        protected ScoreService $scoreService
    ) {
    }

    /* ================= 拼团设置 ================= */

    /**
     * 读取拼团设置（无记录时落一条默认行）
     *
     * @return array<string, mixed>
     */
    public function sysset(int $aid): array
    {
        $info = ScopeService::query('collage_sysset', $aid)->find();

        if (empty($info)) {
            $id = ScopeService::query('collage_sysset', $aid)->insertGetId([
                'aid'           => $aid,
                'autoshdays'    => 7,
                'comment'       => 1,
                'comment_check' => 0,
                'showjd'        => 1,
                'team_refund'   => 0,
                'teamnum'       => 3,
                'teamhour'      => 24,
                'leadermoney'   => '0.00',
            ]);

            $info = Db::name('collage_sysset')->where('id', $id)->find();
        }

        return [
            'teamnum'       => (int) ($info['teamnum'] ?? 3),
            'teamhour'      => (int) ($info['teamhour'] ?? 24),
            'leadermoney'   => (float) ($info['leadermoney'] ?? 0),
            'autoshdays'    => (int) ($info['autoshdays'] ?? 7),
            'comment'       => (int) ($info['comment'] ?? 1),
            'comment_check' => (int) ($info['comment_check'] ?? 0),
            'showjd'        => (int) ($info['showjd'] ?? 1),
            'timeset'       => (string) ($info['timeset'] ?? ''),
            'team_refund'   => (int) ($info['team_refund'] ?? 0),
            'pics'          => (string) ($info['pics'] ?? ''),
            'leveldiscount' => (int) ($info['leveldiscount'] ?? 1),
        ];
    }

    /**
     * 保存拼团设置（白名单字段）
     *
     * @param array<string, mixed> $data
     */
    public function saveSysset(int $aid, array $data): void
    {
        $this->sysset($aid); // 确保行存在

        $update = [];

        if (isset($data['teamnum'])) {
            $teamnum = (int) $data['teamnum'];
            $update['teamnum'] = $teamnum >= 2 && $teamnum <= 100 ? $teamnum : throw new BusinessException('成团人数须为 2-100');
        }

        if (isset($data['teamhour'])) {
            $teamhour = (int) $data['teamhour'];
            $update['teamhour'] = $teamhour >= 1 && $teamhour <= 720 ? $teamhour : throw new BusinessException('成团时限须为 1-720 小时');
        }

        if (isset($data['leadermoney'])) {
            $update['leadermoney'] = max(0, (float) $data['leadermoney']);
        }

        if (isset($data['autoshdays'])) {
            $update['autoshdays'] = max(1, (int) $data['autoshdays']);
        }

        if (isset($data['comment_check'])) {
            $update['comment_check'] = (int) $data['comment_check'] === 1 ? 1 : 0;
        }

        if (isset($data['comment'])) {
            $update['comment'] = (int) $data['comment'] === 1 ? 1 : 0;
        }

        if (isset($data['showjd'])) {
            $update['showjd'] = (int) $data['showjd'] === 1 ? 1 : 0;
        }

        if (array_key_exists('timeset', $data)) {
            $update['timeset'] = trim((string) $data['timeset']);
        }

        if (isset($data['team_refund'])) {
            $update['team_refund'] = (int) $data['team_refund'] === 1 ? 1 : 0;
        }

        if (array_key_exists('pics', $data)) {
            $update['pics'] = trim((string) $data['pics']);
        }

        if (isset($data['leveldiscount'])) {
            $update['leveldiscount'] = (int) $data['leveldiscount'] === 1 ? 1 : 0;
        }

        if (!empty($update)) {
            ScopeService::query('collage_sysset', $aid)->update($update);
        }
    }

    /* ================= 拼团商品 ================= */

    /**
     * 商品列表（keyword 匹配名称；status 空=全部；checked 空=全部 0待审核 1已通过 2已驳回）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function productList(int $aid, string $keyword, string $status, string $checked, int $page, int $pageSize, string $bid = '', int $cid = 0, string $orderField = '', string $orderType = ''): array
    {
        $query = ScopeService::query('collage_product', $aid);

        // 商户维度过滤（任务177 showtype-2：''/all 不过滤，0 自营，gt0 商户）
        ScopeService::applyBid($query, $bid, 'bid');

        if ('' !== $keyword) {
            $query->where('name', 'like', '%' . $keyword . '%');
        }

        if ('' !== $status) {
            $query->where('status', (int) $status);
        }

        if ('' !== $checked) {
            $query->where('ischecked', (int) $checked);
        }

        if ($cid > 0) {
            $query->where('cid', $cid);
        }

        // 排序白名单（拒绝任意字段注入）
        $allowedSort = ['id' => 'id', 'sales' => 'sales', 'sort' => 'sort', 'createtime' => 'createtime'];
        $sortCol     = $allowedSort[$orderField] ?? '';
        $sortDir     = 'asc' === strtolower($orderType) ? 'asc' : 'desc';

        $total = (clone $query)->count();

        if ('' !== $sortCol) {
            $query->order($sortCol, $sortDir);
        } else {
            $query->order('sort', 'desc')->order('id', 'desc');
        }

        $rows = $query->page($page, $pageSize)
            ->select()
            ->toArray();

        // 批量补充分类名 cname 与所属商户名 bname（范式同 OrderService/ArticleService）
        $cids = array_values(array_unique(array_filter(array_map(static fn (array $r) => (int) ($r['cid'] ?? 0), $rows))));
        $cnames = [];

        if ($cids) {
            $cnames = Db::name('collage_category')->where('aid', $aid)->whereIn('id', $cids)->column('name', 'id');
        }

        $bidIds = array_values(array_unique(array_filter(array_map(static fn (array $r) => (int) ($r['bid'] ?? 0), $rows))));
        $bnames = [];

        if ($bidIds) {
            $bnames = Db::name('business')->where('aid', $aid)->whereIn('id', $bidIds)->column('name', 'id');
        }

        $list = array_map(function (array $r) use ($cnames, $bnames) {
            $item = $this->formatProduct($r);
            $item['cname'] = (string) ($cnames[(int) $r['cid']] ?? '');
            $item['bname'] = (int) ($r['bid'] ?? 0) > 0
                ? (string) ($bnames[(int) $r['bid']] ?? '')
                : '平台自营';

            return $item;
        }, $rows);

        return [
            'list'     => $list,
            'total'    => $total,
            'page'     => $page,
            'pagesize' => $pageSize,
        ];
    }

    /**
     * 商品详情（含规格行）
     *
     * @return array<string, mixed>
     */
    public function productDetail(int $aid, int $id): array
    {
        $product = $this->findProduct($aid, $id);

        $detail = $this->formatProduct($product);

        $detail['options'] = Db::name('collage_guige')
            ->where('aid', $aid)
            ->where('proid', $id)
            ->order('id', 'asc')
            ->select()
            ->toArray();

        foreach ($detail['options'] as &$row) {
            $row['id']          = (int) ($row['id'] ?? 0);
            $row['name']        = (string) ($row['name'] ?? '');
            $row['sell_price']  = (float) ($row['sell_price'] ?? 0);
            $row['market_price'] = (float) ($row['market_price'] ?? 0);
            $row['cost_price']  = (float) ($row['cost_price'] ?? 0);
            $row['stock']       = (int) ($row['stock'] ?? 0);
            $row['procode']     = (string) ($row['procode'] ?? '');
            $row['weight']      = (float) ($row['weight'] ?? 0);
            $row['pic']         = (string) ($row['pic'] ?? '');
        }
        unset($row);

        return $detail;
    }

    /**
     * 新增/编辑拼团商品（id>0 为编辑；options 为规格行数组，整表同步）
     *
     * @param array<string, mixed> $data
     * @return array{id: int}
     */
    public function productSave(int $aid, array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));

        if ('' === $name) {
            throw new BusinessException('请输入商品名称');
        }

        $teamnum  = (int) ($data['teamnum'] ?? 0);
        $teamhour = (int) ($data['teamhour'] ?? 0);

        if ($teamnum < 2 || $teamnum > 100) {
            throw new BusinessException('成团人数须为 2-100');
        }

        if ($teamhour < 1 || $teamhour > 720) {
            throw new BusinessException('成团时限须为 1-720 小时');
        }

        $options = $data['options'] ?? [];

        if (!is_array($options) || empty($options)) {
            throw new BusinessException('请至少添加一个规格');
        }

        // 规格行整理 + 汇总价格库存（取最低售价为主价格）
        $rows = [];
        $stockTotal = 0;
        $minPrice   = null;

        foreach ($options as $row) {
            $ggName = trim((string) ($row['name'] ?? ''));

            if ('' === $ggName) {
                throw new BusinessException('规格名称不能为空');
            }

            $sellPrice  = max(0, (float) ($row['sell_price'] ?? 0));
            $marketPrice = max(0, (float) ($row['market_price'] ?? 0));
            $costPrice  = max(0, (float) ($row['cost_price'] ?? 0));
            $stock      = max(0, (int) ($row['stock'] ?? 0));

            if ($sellPrice <= 0) {
                throw new BusinessException('规格「' . $ggName . '」拼团价须大于0');
            }

            $rows[] = [
                'name'         => $ggName,
                'sell_price'   => $sellPrice,
                'market_price' => $marketPrice,
                'cost_price'   => $costPrice,
                'stock'        => $stock,
                'procode'      => trim((string) ($row['procode'] ?? '')),
                'weight'       => max(0, (float) ($row['weight'] ?? 0)),
                'pic'          => trim((string) ($row['pic'] ?? '')),
            ];

            $stockTotal += $stock;

            if (null === $minPrice || $sellPrice < $minPrice) {
                $minPrice = $sellPrice;
            }
        }

        $update = [
            'name'        => $name,
            'pic'         => trim((string) ($data['pic'] ?? '')),
            'pics'        => trim((string) ($data['pics'] ?? '')),
            'cid'         => (int) ($data['cid'] ?? 0),
            'sellpoint'   => trim((string) ($data['sellpoint'] ?? '')),
            'teamnum'     => $teamnum,
            'teamhour'    => $teamhour,
            'leadermoney' => max(0, (float) ($data['leadermoney'] ?? 0)),
            'buymax'      => max(0, (int) ($data['buymax'] ?? 0)),
            'sort'        => (int) ($data['sort'] ?? 0),
            'status'      => (int) ($data['status'] ?? 1) === 1 ? 1 : 0,
            'detail'        => (string) ($data['detail'] ?? ''),
            'sell_price'    => (float) $minPrice,
            'stock'         => $stockTotal,
            'goods_id'      => (int) ($data['goods_id'] ?? 0),
            'no_discount'   => (int) ($data['no_discount'] ?? -1),
            'freighttype'   => (int) ($data['freighttype'] ?? 0),
            'leaderscore'    => max(0, (int) ($data['leaderscore'] ?? 0)),
            'commissionset' => (int) ($data['commissionset'] ?? 0),
        ];

        $id = (int) ($data['id'] ?? 0);

        if ($id > 0) {
            $this->findProduct($aid, $id);

            ScopeService::query('collage_product', $aid)->where('id', $id)->update($update);
        } else {
            $update['aid']        = $aid;
            $update['bid']        = 0;
            $update['ischecked']  = 1;
            $update['createtime'] = time();
            $update['sales']      = max(0, (int) ($data['sales'] ?? 0));

            $id = (int) ScopeService::query('collage_product', $aid)->insertGetId($update);
        }

        // 规格整表同步（ks=规格名 md5，删除不在本次提交内的旧规格）
        $keepIds = [];

        foreach ($rows as $row) {
            $ks   = md5($row['name']);
            $ggRow = [
                'proid'        => $id,
                'ks'           => $ks,
                'name'         => $row['name'],
                'sell_price'   => $row['sell_price'],
                'market_price' => $row['market_price'],
                'cost_price'   => $row['cost_price'],
                'stock'        => $row['stock'],
                'procode'      => $row['procode'],
                'weight'       => $row['weight'],
                'pic'          => $row['pic'],
            ];

            $exists = Db::name('collage_guige')
                ->where('aid', $aid)
                ->where('proid', $id)
                ->where('ks', $ks)
                ->find();

            if (!empty($exists)) {
                Db::name('collage_guige')->where('id', (int) $exists['id'])->update($ggRow);
                $keepIds[] = (int) $exists['id'];
            } else {
                $ggRow['aid']  = $aid;
                $keepIds[]     = (int) Db::name('collage_guige')->insertGetId($ggRow);
            }
        }

        Db::name('collage_guige')
            ->where('aid', $aid)
            ->where('proid', $id)
            ->whereNotIn('id', $keepIds ?: [0])
            ->delete();

        return ['id' => $id];
    }

    /**
     * 商品上架/下架
     */
    public function productStatus(int $aid, int $id, int $status): void
    {
        $this->findProduct($aid, $id);

        if (0 !== $status && 1 !== $status) {
            throw new BusinessException('非法的状态');
        }

        ScopeService::query('collage_product', $aid)->where('id', $id)->update(['status' => $status]);
    }

    /**
     * 商品审核（ischecked 1通过 2驳回；驳回须填原因）
     */
    public function productCheck(int $aid, int $id, int $ischecked, string $reason): void
    {
        $this->findProduct($aid, $id);

        if (1 !== $ischecked && 2 !== $ischecked) {
            throw new BusinessException('非法的审核结果');
        }

        if (2 === $ischecked && '' === trim($reason)) {
            throw new BusinessException('请填写驳回原因');
        }

        ScopeService::query('collage_product', $aid)->where('id', $id)->update([
            'ischecked'    => $ischecked,
            'check_reason' => 2 === $ischecked ? trim($reason) : '',
        ]);
    }

    /**
     * 删除商品（连带规格；存在进行中团时拒绝）
     */
    public function productDelete(int $aid, int $id): void
    {
        $this->findProduct($aid, $id);

        $ongoing = Db::name('collage_order_team')
            ->where('aid', $aid)
            ->where('proid', $id)
            ->where('status', self::TEAM_ONGOING)
            ->count();

        if ($ongoing > 0) {
            throw new BusinessException('该商品存在进行中的团，暂不能删除');
        }

        ScopeService::query('collage_product', $aid)->where('id', $id)->delete();
        Db::name('collage_guige')->where('aid', $aid)->where('proid', $id)->delete();
    }

    /**
     * 批量上架/下架（ids 数组；逐条复用单条校验）
     */
    public function productBatchStatus(int $aid, array $ids, int $status): void
    {
        if (0 !== $status && 1 !== $status) {
            throw new BusinessException('非法的状态');
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        if (empty($ids)) {
            throw new BusinessException('请选择要操作的商品');
        }

        foreach ($ids as $id) {
            $this->productStatus($aid, $id, $status);
        }
    }

    /**
     * 批量删除（ids 数组；逐条复用单条业务校验：存在进行中的团禁删）
     */
    public function productBatchDelete(int $aid, array $ids): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        if (empty($ids)) {
            throw new BusinessException('请选择要删除的商品');
        }

        foreach ($ids as $id) {
            $this->productDelete($aid, $id);
        }
    }

    /* ================= 团管理 ================= */

    /**
     * 团列表（status 空=全部 1进行中 2成功 3失败）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function teamList(int $aid, string $status, int $page, int $pageSize, string $ctimeStart = '', string $ctimeEnd = ''): array
    {
        $query = ScopeService::query('collage_order_team', $aid);

        if ('' !== $status) {
            $query->where('status', (int) $status);
        } else {
            $query->where('status', '<>', self::TEAM_UNPAID); // 未支付的空团不展示
        }

        if ('' !== $ctimeStart) {
            $query->where('createtime', '>=', strtotime($ctimeStart));
        }

        if ('' !== $ctimeEnd) {
            $query->where('createtime', '<', strtotime($ctimeEnd) + 86400);
        }

        $total = (clone $query)->count();

        $rows = $query->order('id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        return [
            'list'     => array_map(fn (array $r) => $this->formatTeam($aid, $r), $rows),
            'total'    => $total,
            'page'     => $page,
            'pagesize' => $pageSize,
        ];
    }

    /**
     * 删除团（仅未支付/已成团/已失败的团可删，进行中的团涉及资金禁止删除）
     */
    public function teamDelete(int $aid, int $id): void
    {
        $team = ScopeService::query('collage_order_team', $aid)->where('id', $id)->find();

        if (empty($team)) {
            throw new BusinessException('团不存在', 404, 404);
        }

        if (self::TEAM_ONGOING === (int) $team['status']) {
            throw new BusinessException('进行中的团涉及资金，不允许删除');
        }

        ScopeService::query('collage_order_team', $aid)->where('id', $id)->delete();
    }

    /**
     * 成团判定（支付成功/参团入账后调用）：
     * 团内有效订单数（已支付及以后）满 teamnum → 团置成功（订单保持已支付正常履约）
     *
     * @return array<string, mixed> 最新团信息
     */
    public function confirmTeam(int $aid, int $teamId): array
    {
        return (array) Db::transaction(function () use ($aid, $teamId) {
            $team = Db::name('collage_order_team')
                ->where('aid', $aid)
                ->where('id', $teamId)
                ->lock(true)
                ->find();

            if (empty($team)) {
                throw new BusinessException('团不存在', 404, 404);
            }

            if (self::TEAM_ONGOING !== (int) $team['status']) {
                return $team; // 已终态：幂等返回
            }

            // 有效人数：已支付/已发货/已收货（关闭单不计）
            $num = Db::name('collage_order')
                ->where('aid', $aid)
                ->where('teamid', $teamId)
                ->whereIn('status', [self::ORDER_PAID, self::ORDER_SHIPPED, self::ORDER_COLLECTED])
                ->count();

            $update = ['num' => $num];

            if ($num >= (int) $team['teamnum']) {
                $update['status'] = self::TEAM_SUCCESS; // 满员成团
            }

            Db::name('collage_order_team')->where('id', $teamId)->update($update);

            return array_merge($team, $update);
        });
    }

    /* ================= 拼团订单 ================= */

    /**
     * 订单列表
     *
     * status 口径：''=全部；0-4 订单状态；r1/r2/r3 → refund_status 1/2/3
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function orderList(
        int $aid,
        string $status,
        string $buytype,
        string $keyword,
        int $teamId,
        int $page,
        int $pageSize,
        string $ordernum = '',
        string $linkman = '',
        string $tel = '',
        string $ctimeStart = '',
        string $ctimeEnd = ''
    ): array {
        $query = ScopeService::query('collage_order', $aid);

        if ('' !== $status) {
            if (str_starts_with($status, 'r')) {
                $refund = (int) substr($status, 1);

                if (!in_array($refund, [1, 2, 3], true)) {
                    throw new BusinessException('非法的退款状态');
                }

                $query->where('refund_status', $refund);
            } else {
                $query->where('status', (int) $status);
            }
        }

        if ('' !== $buytype) {
            if (!in_array((int) $buytype, [1, 2, 3], true)) {
                throw new BusinessException('非法的购买类型');
            }

            $query->where('buytype', (int) $buytype);
        }

        if ($teamId > 0) {
            $query->where('teamid', $teamId);
        }

        if ('' !== $ordernum) {
            $query->where('ordernum', 'like', '%' . $ordernum . '%');
        }

        if ('' !== $linkman) {
            $query->where('linkman', 'like', '%' . $linkman . '%');
        }

        if ('' !== $tel) {
            $query->where('tel', 'like', '%' . $tel . '%');
        }

        if ('' !== $ctimeStart) {
            $query->where('createtime', '>=', strtotime($ctimeStart));
        }

        if ('' !== $ctimeEnd) {
            $query->where('createtime', '<', strtotime($ctimeEnd) + 86400);
        }

        if ('' !== $keyword) {
            $like = '%' . $keyword . '%';
            $query->where(function ($q) use ($like) {
                $q->whereOr('ordernum', 'like', $like)
                    ->whereOr('proname', 'like', $like)
                    ->whereOr('linkman', 'like', $like)
                    ->whereOr('tel', 'like', $like);
            });
        }

        $total = (clone $query)->count();

        $rows = $query->order('id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        return [
            'list'     => array_map(fn (array $r) => $this->formatOrder($aid, $r), $rows),
            'total'    => $total,
            'page'     => $page,
            'pagesize' => $pageSize,
        ];
    }

    /**
     * 订单改价（仅未支付订单；改价后沿用原订单号，未生成支付单无副作用）
     */
    public function orderChangePrice(int $aid, int $orderId, float $newPrice): void
    {
        $order = $this->findOrder($aid, $orderId);

        if (self::ORDER_UNPAID !== (int) $order['status']) {
            throw new BusinessException('仅未支付订单可改价');
        }

        if ($newPrice <= 0) {
            throw new BusinessException('订单金额须大于0');
        }

        ScopeService::query('collage_order', $aid)->where('id', $orderId)->update([
            'totalprice' => $newPrice,
        ]);
    }

    /**
     * 关闭订单（仅未支付；回补库存 + 释放占用券）
     */
    public function orderClose(int $aid, int $orderId): void
    {
        Db::transaction(function () use ($aid, $orderId) {
            $order = Db::name('collage_order')
                ->where('aid', $aid)
                ->where('id', $orderId)
                ->lock(true)
                ->find();

            if (empty($order)) {
                throw new BusinessException('订单不存在', 404, 404);
            }

            if (self::ORDER_UNPAID !== (int) $order['status']) {
                throw new BusinessException('仅未支付订单可关闭');
            }

            $this->restoreStock($order);

            // 释放下单占用的优惠券（未核销仅清占用；防御分支幂等）
            $this->couponService->releaseByOrder((int) ($order['coupon_rid'] ?? 0), $orderId);

            Db::name('collage_order')->where('id', $orderId)->update(['status' => self::ORDER_CLOSED]);
        });
    }

    /**
     * 退款审核（pass=1 同意→原路退款+退积分+退券同事务；pass=2 驳回）
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
            ScopeService::query('collage_order', $aid)->where('id', $orderId)->update([
                'refund_status'       => 3,
                'refund_checkremark'  => trim($remark),
                'refund_time'         => time(),
            ]);

            return;
        }

        if (!in_array((int) $order['status'], [self::ORDER_PAID, self::ORDER_SHIPPED], true)) {
            throw new BusinessException('该订单状态不允许退款');
        }

        Db::transaction(function () use ($aid, $orderId, $remark) {
            $locked = Db::name('collage_order')
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
            $this->payService->refund($locked, $refundMoney, 'RC' . (string) $locked['ordernum']);

            // payorder 退款登记（type=collage 原支付单）
            $this->markPayorderRefund($aid, (string) $locked['ordernum'], $refundMoney);

            // 已支付未发货退款回补库存
            if (self::ORDER_PAID === (int) $locked['status']) {
                $this->restoreStock($locked);
            }

            Db::name('collage_order')->where('id', $orderId)->update([
                'status'             => self::ORDER_CLOSED,
                'refund_status'      => 2,
                'refund_money'       => $refundMoney,
                'refund_time'        => time(),
                'refund_checkremark' => trim($remark),
            ]);

            // 退积分 + 退券
            $this->refundScoreAndCoupon($aid, $locked, '订单退款');

            // 团长发起单退款：按设置决定是否解散团（team_refund=1 且本单为团长单）
            $sysset = ScopeService::query('collage_sysset', $aid)->find();

            if (!empty($sysset) && 1 === (int) ($sysset['team_refund'] ?? 0)
                && 2 === (int) ($locked['buytype'] ?? 0) && (int) ($locked['teamid'] ?? 0) > 0) {
                $team = Db::name('collage_order_team')->where('id', (int) $locked['teamid'])->find();

                if (!empty($team) && self::TEAM_ONGOING === (int) $team['status']
                    && (string) $team['mid'] === (string) ($locked['mid'] ?? '')) {
                    $this->dissolveTeamInternal($team, '团长退款解散团');
                }
            }

            // 参团单退款：进行中团人数回退
            if (3 === (int) ($locked['buytype'] ?? 0) && (int) ($locked['teamid'] ?? 0) > 0) {
                $team = Db::name('collage_order_team')->where('id', (int) $locked['teamid'])->find();

                if (!empty($team) && self::TEAM_ONGOING === (int) $team['status'] && (int) $team['num'] > 0) {
                    Db::name('collage_order_team')->where('id', (int) $team['id'])->dec('num', 1)->update();
                }
            }
        });
    }

    /**
     * 发货（status=1 → 2；已发货时仅更新物流信息）
     */
    public function orderSendExpress(int $aid, int $orderId, string $expressCom, string $expressNo): void
    {
        $expressCom = trim($expressCom);
        $expressNo  = trim($expressNo);

        if ('' === $expressCom || '' === $expressNo) {
            throw new BusinessException('请填写快递公司与快递单号');
        }

        $order = $this->findOrder($aid, $orderId);

        if (self::ORDER_PAID === (int) $order['status']) {
            ScopeService::query('collage_order', $aid)->where('id', $orderId)->update([
                'express_com' => $expressCom,
                'express_no'  => $expressNo,
                'send_time'   => time(),
                'status'      => self::ORDER_SHIPPED,
            ]);

            return;
        }

        if (self::ORDER_SHIPPED === (int) $order['status']) {
            // 修改物流信息
            ScopeService::query('collage_order', $aid)->where('id', $orderId)->update([
                'express_com' => $expressCom,
                'express_no'  => $expressNo,
            ]);

            return;
        }

        throw new BusinessException('当前订单状态不可发货');
    }

    /**
     * 订单详情（含下单人/团/收货地址完整字段）
     *
     * @return array<string, mixed>
     */
    public function orderDetail(int $aid, int $orderId): array
    {
        $order = $this->findOrder($aid, $orderId);

        return $this->formatOrder($aid, $order);
    }

    /**
     * 删除订单（兼容单个 id 与批量 ids[]）
     *
     * @param int[] $ids
     */
    public function orderDelete(int $aid, array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));

        if (empty($ids)) {
            throw new BusinessException('请选择要删除的订单');
        }

        ScopeService::query('collage_order', $aid)->whereIn('id', $ids)->delete();
    }

    /**
     * 设置订单备注
     */
    public function orderSetRemark(int $aid, int $orderId, string $content): void
    {
        $this->findOrder($aid, $orderId);

        ScopeService::query('collage_order', $aid)->where('id', $orderId)->update([
            'remark' => trim($content),
        ]);
    }

    /**
     * 改为已支付（后台支付；仅未支付订单）
     */
    public function orderIspay(int $aid, int $orderId): void
    {
        $order = $this->findOrder($aid, $orderId);

        if (self::ORDER_UNPAID !== (int) $order['status']) {
            throw new BusinessException('仅未支付订单可改为已支付');
        }

        ScopeService::query('collage_order', $aid)->where('id', $orderId)->update([
            'status'  => self::ORDER_PAID,
            'paytime' => time(),
            'paytype' => '后台支付',
        ]);
    }

    /**
     * 快递公司下拉（手动发货用；读快递配置字典）
     *
     * @return array{list: array<int, array{name: string, code: string}>}
     */
    public function expressOptions(): array
    {
        $list = [];
        foreach (ExpressCompany::dict() as $name => $code) {
            $list[] = ['name' => $name, 'code' => $code];
        }

        return ['list' => $list];
    }

    /**
     * 直接退款（管理员在订单列表点「退款」，原路退回 + 退积分 + 退券）
     *
     * 区别于 orderRefundCheck：后者处理用户发起的退款申请（refund_status=1）；
     * 本方法是管理员对已支付/已发货订单直接发起退款。
     */
    public function orderRefund(int $aid, int $orderId, string $reason): void
    {
        $order = $this->findOrder($aid, $orderId);

        if (!in_array((int) $order['status'], [self::ORDER_PAID, self::ORDER_SHIPPED], true)) {
            throw new BusinessException('该订单状态不允许退款');
        }

        $team = !empty($order['teamid'])
            ? Db::name('collage_order_team')->where('id', (int) $order['teamid'])->find()
            : null;

        if (!empty($team) && self::TEAM_ONGOING === (int) $team['status']) {
            throw new BusinessException('拼团中不允许退款');
        }

        $reason = trim($reason);

        if ('' === $reason) {
            throw new BusinessException('请填写退款原因');
        }

        Db::transaction(function () use ($aid, $orderId, $reason) {
            $locked = Db::name('collage_order')
                ->where('aid', $aid)
                ->where('id', $orderId)
                ->lock(true)
                ->find();

            if (empty($locked) || !in_array((int) $locked['status'], [self::ORDER_PAID, self::ORDER_SHIPPED], true)) {
                throw new BusinessException('订单状态已变化，请刷新后重试');
            }

            $refundMoney = (float) $locked['totalprice'];

            if ($refundMoney > 0) {
                $this->payService->refund($locked, $refundMoney, 'RC' . (string) $locked['ordernum']);
                $this->markPayorderRefund($aid, (string) $locked['ordernum'], $refundMoney);
            }

            Db::name('collage_order')->where('id', $orderId)->update([
                'status'         => self::ORDER_CLOSED,
                'refund_status'  => 2,
                'refund_money'   => $refundMoney,
                'refund_reason'  => $reason,
                'refund_time'    => time(),
            ]);

            // 已支付未发货退款回补库存
            if (self::ORDER_PAID === (int) $locked['status']) {
                $this->restoreStock($locked);
            }

            $this->refundScoreAndCoupon($aid, $locked, $reason);
        });
    }

    /**
     * 批量发货（Excel 导入：第一列订单号、第二列快递单号，统一快递公司）
     *
     * @param array<int, array<int, string>> $pairs
     * @return array{total: int, success: int, fail: int, items: array}
     */
    public function orderBatchShip(int $aid, string $expressCompany, array $pairs): array
    {
        $expressCompany = trim($expressCompany);

        if ('' === $expressCompany) {
            throw new BusinessException('请选择快递公司');
        }

        $result = ['total' => count($pairs), 'success' => 0, 'fail' => 0, 'items' => []];

        foreach ($pairs as $pair) {
            $ordernum  = trim((string) ($pair[0] ?? ''));
            $expressNo = trim((string) ($pair[1] ?? ''));

            if ('' === $ordernum || '' === $expressNo) {
                ++$result['fail'];
                $result['items'][] = ['ordernum' => $ordernum, 'ok' => false, 'msg' => '订单号或快递单号为空'];
                continue;
            }

            $order = ScopeService::query('collage_order', $aid)->where('ordernum', $ordernum)->find();

            if (empty($order)) {
                ++$result['fail'];
                $result['items'][] = ['ordernum' => $ordernum, 'ok' => false, 'msg' => '订单不存在'];
                continue;
            }

            try {
                $this->orderSendExpress($aid, (int) $order['id'], $expressCompany, $expressNo);
                ++$result['success'];
                $result['items'][] = ['ordernum' => $ordernum, 'ok' => true, 'msg' => '发货成功'];
            } catch (BusinessException $e) {
                ++$result['fail'];
                $result['items'][] = ['ordernum' => $ordernum, 'ok' => false, 'msg' => $e->getMessage()];
            }
        }

        return $result;
    }

    /**
     * 订单导出数据（xlsx 行数据；筛选口径同 orderList）
     *
     * @return array{0: array<int, string>, 1: array<int, array<int, mixed>>}
     */
    public function orderExportData(
        int $aid,
        string $status,
        string $buytype,
        string $ordernum,
        string $linkman,
        string $tel,
        string $ctimeStart,
        string $ctimeEnd,
        int $teamId,
        int $limit = 20000
    ): array {
        $query = ScopeService::query('collage_order', $aid);

        if ('' !== $status && !str_starts_with($status, 'r')) {
            $query->where('status', (int) $status);
        } elseif (str_starts_with($status, 'r')) {
            $query->where('refund_status', (int) substr($status, 1));
        }

        if ('' !== $buytype) {
            $query->where('buytype', (int) $buytype);
        }

        if ($teamId > 0) {
            $query->where('teamid', $teamId);
        }

        if ('' !== $ordernum) {
            $query->where('ordernum', 'like', '%' . $ordernum . '%');
        }

        if ('' !== $linkman) {
            $query->where('linkman', 'like', '%' . $linkman . '%');
        }

        if ('' !== $tel) {
            $query->where('tel', 'like', '%' . $tel . '%');
        }

        if ('' !== $ctimeStart) {
            $query->where('createtime', '>=', strtotime($ctimeStart));
        }

        if ('' !== $ctimeEnd) {
            $query->where('createtime', '<', strtotime($ctimeEnd) + 86400);
        }

        $rows = $query->order('id', 'desc')->limit($limit)->select()->toArray();

        $titles = ['订单号', '商品名称', '规格数量', '商品总价', '实付款', '支付方式', '收货人', '电话', '收货地址', '配送方式', '快递信息', '下单时间', '状态', '来源'];

        $data = [];

        foreach ($rows as $r) {
            $item = $this->formatOrder($aid, $r);
            $data[] = [
                $item['ordernum'],
                $item['proname'],
                trim($item['ggname'] . ' × ' . $item['num']),
                $item['product_price'],
                $item['totalprice'],
                $item['paytype'],
                $item['linkman'],
                $item['tel'],
                $item['address'],
                $item['freight_text'],
                $item['express_com'] ? ($item['express_com'] . ' ' . $item['express_no']) : '',
                date('Y-m-d H:i:s', $item['createtime']),
                $item['status_text'] . ($item['refund_money'] > 0 ? '（已退¥' . $item['refund_money'] . '）' : ''),
                $item['platform'],
            ];
        }

        return [$titles, $data];
    }

    /* ================= 评价管理 ================= */

    /**
     * 评价列表（content 模糊 / ctime 时间范围 / status 审核态）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function commentList(int $aid, string $content, string $ctimeStart, string $ctimeEnd, string $status, int $page, int $pageSize): array
    {
        $query = ScopeService::query('collage_comment', $aid);

        if ('' !== $content) {
            $query->where('content', 'like', '%' . $content . '%');
        }

        if ('' !== $ctimeStart) {
            $query->where('createtime', '>=', strtotime($ctimeStart));
        }

        if ('' !== $ctimeEnd) {
            $query->where('createtime', '<', strtotime($ctimeEnd) + 86400);
        }

        if ('' !== $status) {
            $query->where('status', (int) $status);
        }

        $total = (clone $query)->count();

        $rows = $query->order('id', 'desc')->page($page, $pageSize)->select()->toArray();

        $list = array_map(fn (array $r) => [
            'id'            => (int) ($r['id'] ?? 0),
            'proid'         => (int) ($r['proid'] ?? 0),
            'proname'       => (string) ($r['proname'] ?? ''),
            'propic'        => (string) ($r['propic'] ?? ''),
            'ggname'        => (string) ($r['ggname'] ?? ''),
            'nickname'      => (string) ($r['nickname'] ?? ''),
            'headimg'       => (string) ($r['headimg'] ?? ''),
            'score'         => (int) ($r['score'] ?? 5),
            'content'       => (string) ($r['content'] ?? ''),
            'content_pic'   => (string) ($r['content_pic'] ?? ''),
            'reply_content' => (string) ($r['reply_content'] ?? ''),
            'reply_time'    => (int) ($r['reply_time'] ?? 0),
            'status'        => (int) ($r['status'] ?? 1),
            'createtime'    => (int) ($r['createtime'] ?? 0),
        ], $rows);

        return ['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize];
    }

    /**
     * 评价审核/批量审核（status=1 通过 / 0 不通过），回写商品好评聚合
     */
    public function commentSetst(int $aid, array $ids, int $status): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));

        if (empty($ids)) {
            throw new BusinessException('请选择要操作的评价');
        }

        if (1 !== $status && 0 !== $status) {
            throw new BusinessException('非法的审核状态');
        }

        foreach ($ids as $id) {
            $row = ScopeService::query('collage_comment', $aid)->where('id', $id)->find();

            if (empty($row)) {
                continue;
            }

            ScopeService::query('collage_comment', $aid)->where('id', $id)->update(['status' => $status]);

            $proid = (int) ($row['proid'] ?? 0);

            if ($proid > 0) {
                $avgScore  = (float) ScopeService::query('collage_comment', $aid)->where('proid', $proid)->where('status', 1)->avg('score');
                $num       = (int) ScopeService::query('collage_comment', $aid)->where('proid', $proid)->where('status', 1)->count();
                $haoNum    = (int) ScopeService::query('collage_comment', $aid)->where('proid', $proid)->where('status', 1)->where('score', '>', 3)->count();
                $score     = $num > 0 ? round($avgScore, 2) : 5;
                $haoPercent = $num > 0 ? round($haoNum / $num * 100, 2) : 100;

                ScopeService::query('collage_product', $aid)->where('id', $proid)->update([
                    'comment_score'      => $score,
                    'comment_num'       => $num,
                    'comment_haopercent' => $haoPercent,
                ]);
            }
        }
    }

    /**
     * 评价详情
     *
     * @return array<string, mixed>
     */
    public function commentDetail(int $aid, int $id): array
    {
        $row = ScopeService::query('collage_comment', $aid)->where('id', $id)->find();

        if (empty($row)) {
            throw new BusinessException('评价不存在', 404, 404);
        }

        return [
            'id'            => (int) ($row['id'] ?? 0),
            'proid'         => (int) ($row['proid'] ?? 0),
            'proname'       => (string) ($row['proname'] ?? ''),
            'propic'        => (string) ($row['propic'] ?? ''),
            'ggname'        => (string) ($row['ggname'] ?? ''),
            'nickname'      => (string) ($row['nickname'] ?? ''),
            'headimg'       => (string) ($row['headimg'] ?? ''),
            'score'         => (int) ($row['score'] ?? 5),
            'content'       => (string) ($row['content'] ?? ''),
            'content_pic'   => (string) ($row['content_pic'] ?? ''),
            'reply_content' => (string) ($row['reply_content'] ?? ''),
            'reply_time'    => (int) ($row['reply_time'] ?? 0),
            'status'        => (int) ($row['status'] ?? 1),
            'createtime'    => (int) ($row['createtime'] ?? 0),
        ];
    }

    /**
     * 评价回复
     */
    public function commentReply(int $aid, int $id, string $content): void
    {
        $content = trim($content);

        if ('' === $content) {
            throw new BusinessException('请填写回复内容');
        }

        $comment = ScopeService::query('collage_comment', $aid)->where('id', $id)->find();

        if (empty($comment)) {
            throw new BusinessException('评价不存在');
        }

        ScopeService::query('collage_comment', $aid)->where('id', $id)->update([
            'reply_content' => $content,
            'reply_time'    => time(),
            'status'        => 1, // 回复后自动转为已审核（对齐参考系统）
        ]);
    }

    /**
     * 删除评价（兼容单个 id 与批量 ids[]）
     *
     * @param int[] $ids
     */
    public function commentDelete(int $aid, array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));

        if (empty($ids)) {
            throw new BusinessException('请选择要删除的评价');
        }

        ScopeService::query('collage_comment', $aid)->whereIn('id', $ids)->delete();
    }

    /* ================= 定时任务：超时解散 ================= */

    /**
     * 扫描超时进行中的团并解散（schedule:run 挂接，幂等）
     *
     * 判定：team.status=1 且 createtime + teamhour*3600 < now
     *
     * @return int 解散团数量
     */
    public function dissolveExpiredTeams(int $batch = 100): int
    {
        $now = time();

        $teams = Db::name('collage_order_team')
            ->where('status', self::TEAM_ONGOING)
            ->whereRaw("createtime + teamhour * 3600 < {$now}")
            ->order('id', 'asc')
            ->limit($batch)
            ->select()
            ->toArray();

        $count = 0;

        foreach ($teams as $team) {
            try {
                $this->dissolveTeam((int) $team['aid'], (int) $team['id'], '拼团超时失败');
                ++$count;
            } catch (\Throwable) {
                // 单团失败不影响其他团（状态条件+行锁保证不会重复解散）
            }
        }

        return $count;
    }

    /**
     * 解散团（对外入口：独立事务）
     *
     * @return int 退款订单数
     */
    public function dissolveTeam(int $aid, int $teamId, string $reason): int
    {
        $team = ScopeService::query('collage_order_team', $aid)->where('id', $teamId)->find();

        if (empty($team)) {
            throw new BusinessException('团不存在', 404, 404);
        }

        return $this->dissolveTeamInternal($team, $reason);
    }

    /**
     * 解散团核心（整体同事务：团置失败 + 订单关闭 + 原路退款 + 退积分 + 退券）
     *
     * @param array<string, mixed> $team
     * @return int 退款订单数
     */
    protected function dissolveTeamInternal(array $team, string $reason): int
    {
        return (int) Db::transaction(function () use ($team, $reason) {
            // 行锁 + status=1 条件：并发/重复执行幂等拦截
            $locked = Db::name('collage_order_team')
                ->where('id', (int) $team['id'])
                ->lock(true)
                ->find();

            if (empty($locked) || self::TEAM_ONGOING !== (int) $locked['status']) {
                return 0;
            }

            Db::name('collage_order_team')->where('id', (int) $locked['id'])->update([
                'status' => self::TEAM_FAILED,
            ]);

            // 团内已支付的发团/参团订单逐笔退款关闭（单买单不受团失败影响，正常履约）
            $orders = Db::name('collage_order')
                ->where('aid', (int) $locked['aid'])
                ->where('teamid', (int) $locked['id'])
                ->where('status', self::ORDER_PAID)
                ->whereIn('buytype', [2, 3])
                ->select()
                ->toArray();

            foreach ($orders as $order) {
                $this->refundOrderInternal($order, $reason);
            }

            return count($orders);
        });
    }

    /**
     * 单笔订单退款关闭（须在事务内调用）：
     * 订单置关闭 + 原路退款（PayService，mock 兼容）+ payorder 登记 + 退抵扣积分 + 扣赠送积分 + 退券
     *
     * @param array<string, mixed> $order
     */
    protected function refundOrderInternal(array $order, string $reason): void
    {
        $orderId     = (int) ($order['id'] ?? 0);
        $aid         = (int) ($order['aid'] ?? 0);
        $refundMoney = (float) ($order['totalprice'] ?? 0);
        $now         = time();

        Db::name('collage_order')->where('id', $orderId)->update([
            'status'        => self::ORDER_CLOSED,
            'refund_status' => 2,
            'refund_money'  => $refundMoney,
            'refund_reason' => $reason,
            'refund_time'   => $now,
        ]);

        if ($refundMoney > 0) {
            // 原路退款（mock 即时成功；真实模式微信 V3 原路退款）
            $this->payService->refund($order, $refundMoney, 'RC' . (string) ($order['ordernum'] ?? ''));

            // payorder 退款登记（type=collage 原支付单）
            $this->markPayorderRefund($aid, (string) ($order['ordernum'] ?? ''), $refundMoney);
        }

        // 回补库存
        $this->restoreStock($order);

        // 退积分 + 退券
        $this->refundScoreAndCoupon($aid, $order, $reason);
    }

    /**
     * 退积分 + 退券：
     * - 下单抵扣积分 scoredkscore 原数返还（流水 type=3）
     * - 消费赠送积分 givescore 扣回（余额不足时按余额上限扣，避免退款链路被积分卡死）
     * - 优惠券 coupon_rid 释放回未使用（幂等）
     *
     * @param array<string, mixed> $order
     */
    protected function refundScoreAndCoupon(int $aid, array $order, string $reason): void
    {
        $mid = (int) ($order['mid'] ?? 0);

        // 机器人订单无会员资产
        if ($mid > 0 && 0 === (int) ($order['isjiqiren'] ?? 0)) {
            // 抵扣积分返还
            if ((int) ($order['scoredkscore'] ?? 0) > 0) {
                $this->scoreService->refundForCancel($aid, $mid, (int) $order['scoredkscore'], $reason . '返还抵扣积分');
            }

            // 扣回消费赠送积分（余额不足时按余额上限扣）
            $gift = (int) ($order['givescore'] ?? 0);

            if ($gift > 0) {
                $balance = (int) Db::name('member')->where('aid', $aid)->where('id', $mid)->value('score');
                $deduct  = min($gift, max(0, $balance));

                if ($deduct > 0) {
                    $this->scoreService->adjust($aid, $mid, -$deduct, $reason . '扣除消费赠送积分');
                }
            }
        }

        // 优惠券返还（已核销回退未使用 / 未使用仅清占用）
        $this->couponService->releaseByOrder((int) ($order['coupon_rid'] ?? 0), (int) ($order['id'] ?? 0));
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
            ->where('type', 'collage')
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
     * 回补库存（商品 + 规格；数量>0 才处理）
     *
     * @param array<string, mixed> $order
     */
    protected function restoreStock(array $order): void
    {
        $num = (int) ($order['num'] ?? 0);

        if ($num <= 0) {
            return;
        }

        $proid = (int) ($order['proid'] ?? 0);
        $ggid  = (int) ($order['ggid'] ?? 0);

        if ($proid > 0) {
            Db::name('collage_product')->where('id', $proid)->inc('stock', $num)->update();
        }

        if ($ggid > 0) {
            Db::name('collage_guige')->where('id', $ggid)->inc('stock', $num)->update();
        }
    }

    /* ================= C 端核心链路（api 预留） ================= */

    /**
     * C 端商品列表（仅上架且审核通过）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function apiProductList(int $aid, int $page, int $pageSize, string $keyword = ''): array
    {
        $query = ScopeService::query('collage_product', $aid)
            ->where('status', 1)
            ->where('ischecked', 1);

        if ('' !== trim($keyword)) {
            $query->where('name', 'like', '%' . trim($keyword) . '%');
        }

        if ($cid > 0) {
            $query->where('cid', $cid);
        }

        // 排序白名单（拒绝任意字段注入）
        $allowedSort = ['id' => 'id', 'sales' => 'sales', 'sort' => 'sort', 'createtime' => 'createtime'];
        $sortCol     = $allowedSort[$orderField] ?? '';
        $sortDir     = 'asc' === strtolower($orderType) ? 'asc' : 'desc';

        $total = (clone $query)->count();

        if ('' !== $sortCol) {
            $query->order($sortCol, $sortDir);
        } else {
            $query->order('sort', 'desc')->order('id', 'desc');
        }

        $rows = $query->page($page, $pageSize)
            ->select()
            ->toArray();

        // 批量补充分类名 cname 与所属商户名 bname（范式同 OrderService/ArticleService）
        $cids = array_values(array_unique(array_filter(array_map(static fn (array $r) => (int) ($r['cid'] ?? 0), $rows))));
        $cnames = [];

        if ($cids) {
            $cnames = Db::name('collage_category')->where('aid', $aid)->whereIn('id', $cids)->column('name', 'id');
        }

        $bidIds = array_values(array_unique(array_filter(array_map(static fn (array $r) => (int) ($r['bid'] ?? 0), $rows))));
        $bnames = [];

        if ($bidIds) {
            $bnames = Db::name('business')->where('aid', $aid)->whereIn('id', $bidIds)->column('name', 'id');
        }

        $list = array_map(function (array $r) use ($cnames, $bnames) {
            $item = $this->formatProduct($r);
            $item['cname'] = (string) ($cnames[(int) $r['cid']] ?? '');
            $item['bname'] = (int) ($r['bid'] ?? 0) > 0
                ? (string) ($bnames[(int) $r['bid']] ?? '')
                : '平台自营';

            return $item;
        }, $rows);

        return [
            'list'     => $list,
            'total'    => $total,
            'page'     => $page,
            'pagesize' => $pageSize,
        ];
    }

    /**
     * C 端商品详情（仅上架且审核通过；含规格行与进行中团）
     *
     * @return array<string, mixed>
     */
    public function apiProductDetail(int $aid, int $id): array
    {
        $detail = $this->productDetail($aid, $id);

        if (1 !== (int) ($detail['status'] ?? 0) || 1 !== (int) ($detail['ischecked'] ?? 0)) {
            throw new BusinessException('商品不存在或已下架', 404, 404);
        }

        $detail['teams'] = $this->apiOngoingTeams($aid, $id);

        return $detail;
    }

    /**
     * 商品进行中团列表（未超时且未满员，供参团选择）
     *
     * @return array<int, array<string, mixed>>
     */
    public function apiOngoingTeams(int $aid, int $proid, int $limit = 10): array
    {
        $now = time();

        $teams = Db::name('collage_order_team')
            ->where('aid', $aid)
            ->where('proid', $proid)
            ->where('status', self::TEAM_ONGOING)
            ->whereRaw('createtime + teamhour * 3600 > ' . $now)
            ->order('id', 'desc')
            ->limit($limit)
            ->select()
            ->toArray();

        return array_map(fn (array $t) => $this->formatTeam($aid, $t), $teams);
    }

    /**
     * 团详情（成员/剩余时间）
     *
     * @return array<string, mixed>
     */
    public function apiTeamDetail(int $aid, int $teamId): array
    {
        $team = ScopeService::query('collage_order_team', $aid)->where('id', $teamId)->find();

        if (empty($team)) {
            throw new BusinessException('团不存在', 404, 404);
        }

        return $this->formatTeam($aid, $team);
    }

    /**
     * C 端下单（buytype 1单买 2发团 3参团；条件扣减防超卖 + 限购校验 + 写团）
     *
     * @param array<string, mixed> $extra linkman/tel/area/address/message/platform/freight_price
     * @return array{orderid: int, ordernum: string, totalprice: float, status: int, teamid: int}
     */
    public function apiCreateOrder(int $aid, int $mid, int $proid, int $buytype, int $teamId, int $ggid, int $num, array $extra = []): array
    {
        if (!in_array($buytype, [1, 2, 3], true)) {
            throw new BusinessException('非法的购买类型');
        }

        $num = max(1, $num);

        return Db::transaction(function () use ($aid, $mid, $proid, $buytype, $teamId, $ggid, $num, $extra) {
            $product = Db::name('collage_product')
                ->where('aid', $aid)->where('id', $proid)
                ->where('status', 1)->where('ischecked', 1)
                ->lock(true)->find();

            if (empty($product)) {
                throw new BusinessException('拼团商品不存在或已下架');
            }

            // 限购：有效单累计 + 本次 ≤ buymax（0 不限）
            $buymax = (int) ($product['buymax'] ?? 0);

            if ($buymax > 0) {
                $bought = (int) Db::name('collage_order')
                    ->where('aid', $aid)->where('mid', $mid)->where('proid', $proid)
                    ->whereIn('status', [self::ORDER_UNPAID, self::ORDER_PAID, self::ORDER_SHIPPED, self::ORDER_COLLECTED])
                    ->sum('num');

                if ($bought + $num > $buymax) {
                    throw new BusinessException('超出限购数量（每人限拼 ' . $buymax . ' 件）');
                }
            }

            // 参团校验：团进行中、未超时、未满员、未重复参团
            $team = null;

            if (3 === $buytype) {
                if ($teamId <= 0) {
                    throw new BusinessException('缺少团ID');
                }

                $team = Db::name('collage_order_team')
                    ->where('aid', $aid)->where('id', $teamId)->lock(true)->find();

                if (empty($team)) {
                    throw new BusinessException('团不存在', 404, 404);
                }
                if (self::TEAM_ONGOING !== (int) $team['status']) {
                    throw new BusinessException('该团已结束，请选择其他团');
                }
                if (time() > (int) $team['createtime'] + (int) $team['teamhour'] * 3600) {
                    throw new BusinessException('该团已超时，请选择其他团');
                }
                if ((int) $team['proid'] !== $proid) {
                    throw new BusinessException('团与商品不匹配');
                }

                $validNum = (int) Db::name('collage_order')
                    ->where('aid', $aid)->where('teamid', $teamId)
                    ->whereIn('status', [self::ORDER_UNPAID, self::ORDER_PAID, self::ORDER_SHIPPED, self::ORDER_COLLECTED])
                    ->count();

                if ($validNum >= (int) $team['teamnum']) {
                    throw new BusinessException('该团已满员');
                }

                $joined = Db::name('collage_order')
                    ->where('aid', $aid)->where('mid', $mid)->where('teamid', $teamId)
                    ->whereIn('status', [self::ORDER_UNPAID, self::ORDER_PAID, self::ORDER_SHIPPED, self::ORDER_COLLECTED])
                    ->find();

                if (!empty($joined)) {
                    throw new BusinessException('您已在该团中，请勿重复参团');
                }
            }

            // 规格行
            $ggname = '';

            if ($ggid > 0) {
                $guige = Db::name('collage_guige')
                    ->where('aid', $aid)->where('id', $ggid)->where('proid', $proid)->find();

                if (empty($guige)) {
                    throw new BusinessException('商品规格不存在');
                }

                $ggname = (string) ($guige['name'] ?? '');
            }

            // 库存扣减（条件更新防超卖：商品 + 规格成对）
            $affected = Db::name('collage_product')
                ->where('aid', $aid)->where('id', $proid)->where('stock', '>=', $num)
                ->dec('stock', $num)->inc('sales', $num)->update();

            if ($affected <= 0) {
                throw new BusinessException('库存不足，已被抢光了');
            }

            if ($ggid > 0) {
                $ggAffected = Db::name('collage_guige')
                    ->where('aid', $aid)->where('id', $ggid)->where('stock', '>=', $num)
                    ->dec('stock', $num)->inc('sales', $num)->update();

                if ($ggAffected <= 0) {
                    // 规格库存不足：回补商品侧，维持扣减成对
                    Db::name('collage_product')
                        ->where('aid', $aid)->where('id', $proid)->where('sales', '>=', $num)
                        ->inc('stock', $num)->dec('sales', $num)->update();

                    throw new BusinessException('该规格库存不足');
                }
            }

            // 价格：团长减优惠（不低0）
            $sellPrice  = (float) ($product['sell_price'] ?? 0);
            $leaderCut  = 2 === $buytype ? min((float) ($product['leadermoney'] ?? 0), $sellPrice * $num) : 0.0;
            $freightFee = round(max(0, (float) ($extra['freight_price'] ?? 0)), 2);
            $totalPrice = round(max(0, $sellPrice * $num - $leaderCut) + $freightFee, 2);
            $ordernum   = date('ymdHis') . $aid . random_int(1000, 9999);

            // 发团先写团（status=0 未支付，支付入账后转进行中）
            if (2 === $buytype) {
                $teamId = (int) Db::name('collage_order_team')->insertGetId([
                    'aid'        => $aid,
                    'bid'        => (int) ($product['bid'] ?? 0),
                    'mid'        => (string) $mid,
                    'proid'      => $proid,
                    'teamhour'   => (int) ($product['teamhour'] ?? 24),
                    'teamnum'    => (int) ($product['teamnum'] ?? 3),
                    'status'     => self::TEAM_UNPAID,
                    'num'        => 0,
                    'createtime' => time(),
                ]);
            }

            $orderId = (int) Db::name('collage_order')->insertGetId([
                'aid'           => $aid,
                'bid'           => (int) ($product['bid'] ?? 0),
                'mid'           => $mid,
                'ordernum'      => $ordernum,
                'buytype'       => $buytype,
                'teamid'        => $teamId > 0 ? $teamId : 0,
                'title'         => (string) $product['name'],
                'proid'         => $proid,
                'proname'       => (string) $product['name'],
                'propic'        => (string) ($product['pic'] ?? ''),
                'ggid'          => $ggid,
                'ggname'        => $ggname,
                'num'           => $num,
                'cost_price'    => (float) ($product['cost_price'] ?? 0),
                'sell_price'    => $sellPrice,
                'totalprice'    => $totalPrice,
                'product_price' => round($sellPrice * $num, 2),
                'freight_price' => $freightFee,
                'leader_money'  => round($leaderCut, 2),
                'createtime'    => time(),
                'status'        => self::ORDER_UNPAID,
                'linkman'       => trim((string) ($extra['linkman'] ?? '')),
                'tel'           => trim((string) ($extra['tel'] ?? '')),
                'area'          => trim((string) ($extra['area'] ?? '')),
                'address'       => trim((string) ($extra['address'] ?? '')),
                'message'       => trim((string) ($extra['message'] ?? '')),
                'freight_text'  => trim((string) ($extra['freight_text'] ?? '包邮')),
                'hexiao_code'   => strtoupper(bin2hex(random_bytes(8))),
                'platform'      => (string) ($extra['platform'] ?? 'wx'),
            ]);

            // 支付单（type=collage）
            Db::name('payorder')->insert([
                'aid'        => $aid,
                'bid'        => (int) ($product['bid'] ?? 0),
                'mid'        => $mid,
                'orderid'    => $orderId,
                'ordernum'   => $ordernum,
                'title'      => (string) $product['name'],
                'money'      => $totalPrice,
                'score'      => 0,
                'type'       => 'collage',
                'createtime' => time(),
                'status'     => 0,
                'platform'   => (string) ($extra['platform'] ?? 'wx'),
            ]);

            // mock 模式即时支付成功（真实模式由支付回调走 payCollageOrder）
            if (PayService::isMock()) {
                $this->payCollageOrder($aid, $orderId, 'wechat', 'MOCK' . time() . random_int(1000, 9999));
            }

            return [
                'orderid'    => $orderId,
                'ordernum'   => $ordernum,
                'totalprice' => $totalPrice,
                'status'     => PayService::isMock() ? self::ORDER_PAID : self::ORDER_UNPAID,
                'teamid'     => $teamId > 0 ? $teamId : 0,
            ];
        });
    }

    /**
     * 拼团订单支付成功入账（幂等：仅 status=0 → 1）
     *
     * 入账副作用：发团单 → 团 0→1 转进行中；发团/参团单 → confirmTeam 成团判定
     */
    public function payCollageOrder(int $aid, int $orderId, string $payType, string $payNum): bool
    {
        $order = ScopeService::query('collage_order', $aid)->where('id', $orderId)->find();

        if (empty($order) || self::ORDER_UNPAID !== (int) $order['status']) {
            return false;
        }

        $affected = Db::name('collage_order')
            ->where('aid', $aid)->where('id', $orderId)->where('status', self::ORDER_UNPAID)
            ->update([
                'status'    => self::ORDER_PAID,
                'paytype'   => $payType,
                'paytypeid' => PayService::PAY_TYPE_IDS[$payType] ?? 0,
                'paynum'    => $payNum,
                'paytime'   => time(),
            ]);

        if ($affected <= 0) {
            return false;
        }

        Db::name('payorder')
            ->where('aid', $aid)->where('type', 'collage')->where('ordernum', (string) $order['ordernum'])
            ->where('status', 0)
            ->update([
                'status'    => 1,
                'paytype'   => $payType,
                'paytypeid' => PayService::PAY_TYPE_IDS[$payType] ?? 0,
                'paynum'    => $payNum,
                'paytime'   => time(),
            ]);

        $teamId  = (int) ($order['teamid'] ?? 0);
        $buytype = (int) ($order['buytype'] ?? 0);

        if ($teamId > 0 && in_array($buytype, [2, 3], true)) {
            // 发团单入账：团 0→1 转进行中（条件更新幂等）
            if (2 === $buytype) {
                Db::name('collage_order_team')
                    ->where('aid', $aid)->where('id', $teamId)->where('status', self::TEAM_UNPAID)
                    ->update(['status' => self::TEAM_ONGOING]);
            }

            // 成团判定（有效单满 teamnum → 团成功）
            $this->confirmTeam($aid, $teamId);
        }

        return true;
    }

    /**
     * 我的拼团订单（C 端分页）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function apiMyOrders(int $aid, int $mid, string $status, int $page, int $pageSize): array
    {
        $query = ScopeService::query('collage_order', $aid)->where('mid', $mid);

        if ('' !== $status) {
            $query->where('status', (int) $status);
        }

        $total = (clone $query)->count();

        $rows = $query->order('id', 'desc')->page($page, $pageSize)->select()->toArray();

        return [
            'list'     => array_map(fn (array $r) => $this->formatOrder($aid, $r), $rows),
            'total'    => $total,
            'page'     => $page,
            'pagesize' => $pageSize,
        ];
    }

    /* ================= 内部工具 ================= */

    /**
     * @return array<string, mixed>
     */
    protected function findProduct(int $aid, int $id): array
    {
        if ($id <= 0) {
            throw new BusinessException('缺少商品ID');
        }

        $product = ScopeService::query('collage_product', $aid)->where('id', $id)->find();

        if (empty($product)) {
            throw new BusinessException('商品不存在', 404, 404);
        }

        return $product;
    }

    /**
     * @return array<string, mixed>
     */
    protected function findOrder(int $aid, int $orderId): array
    {
        if ($orderId <= 0) {
            throw new BusinessException('缺少订单ID');
        }

        $order = ScopeService::query('collage_order', $aid)->where('id', $orderId)->find();

        if (empty($order)) {
            throw new BusinessException('订单不存在', 404, 404);
        }

        return $order;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function formatProduct(array $row): array
    {
        return [
            'id'           => (int) ($row['id'] ?? 0),
            'name'         => (string) ($row['name'] ?? ''),
            'pic'          => (string) ($row['pic'] ?? ''),
            'pics'         => (string) ($row['pics'] ?? ''),
            'cid'          => (int) ($row['cid'] ?? 0),
            'sellpoint'    => (string) ($row['sellpoint'] ?? ''),
            'sell_price'   => (float) ($row['sell_price'] ?? 0),
            'market_price' => (float) ($row['market_price'] ?? 0),
            'stock'        => (int) ($row['stock'] ?? 0),
            'sales'        => (int) ($row['sales'] ?? 0),
            'teamnum'      => (int) ($row['teamnum'] ?? 3),
            'teamhour'     => (int) ($row['teamhour'] ?? 24),
            'leadermoney'  => (float) ($row['leadermoney'] ?? 0),
            'buymax'       => (int) ($row['buymax'] ?? 0),
            'sort'         => (int) ($row['sort'] ?? 0),
            'status'       => (int) ($row['status'] ?? 0),
            'ischecked'    => (int) ($row['ischecked'] ?? 1),
            'check_reason' => (string) ($row['check_reason'] ?? ''),
            'detail'       => (string) ($row['detail'] ?? ''),
            'goods_id'      => (int) ($row['goods_id'] ?? 0),
            'no_discount'   => (int) ($row['no_discount'] ?? -1),
            'freighttype'   => (int) ($row['freighttype'] ?? 0),
            'leaderscore'   => (int) ($row['leaderscore'] ?? 0),
            'commissionset' => (int) ($row['commissionset'] ?? 0),
            'createtime'   => (int) ($row['createtime'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function formatTeam(int $aid, array $row): array
    {
        $teamId   = (int) ($row['id'] ?? 0);
        $status   = (int) ($row['status'] ?? 0);
        $teamnum  = (int) ($row['teamnum'] ?? 0);
        $createtime = (int) ($row['createtime'] ?? 0);
        $teamhour = (int) ($row['teamhour'] ?? 0);

        $product = Db::name('collage_product')
            ->where('aid', $aid)
            ->where('id', (int) ($row['proid'] ?? 0))
            ->find();

        $productName = (string) ($product['name'] ?? '');
        $productPic  = (string) ($product['pic'] ?? '');

        // 团员列表（机器人订单标记机器人昵称；脱敏手机号）
        $members = [];
        $orders  = Db::name('collage_order')
            ->where('aid', $aid)
            ->where('teamid', $teamId)
            ->whereIn('status', [self::ORDER_PAID, self::ORDER_SHIPPED, self::ORDER_COLLECTED])
            ->select()
            ->toArray();

        foreach ($orders as $order) {
            $m        = $this->buyerOf($aid, $order);
            $tel      = (string) ($order['tel'] ?? '');
            $m['tel_mask'] = $this->maskTel($tel);
            $members[] = $m;
        }

        // 未占位的团员位置补空头像
        $need = max(0, $teamnum - count($members));
        for ($i = 0; $i < $need; ++$i) {
            $members[] = ['mid' => 0, 'nickname' => '', 'headimg' => '', 'isjiqiren' => 0, 'tel_mask' => ''];
        }

        return [
            'id'            => $teamId,
            'proid'         => (int) ($row['proid'] ?? 0),
            'product_name'  => $productName,
            'product_pic'   => $productPic,
            'teamnum'       => $teamnum,
            'num'           => count($orders),
            'status'        => $status,
            'status_text'   => self::TEAM_STATUS_TEXT[$status] ?? '未知',
            'members'       => $members,
            'createtime'    => $createtime,
            'deadline'      => $createtime + $teamhour * 3600,
        ];
    }

    /** 手机号脱敏：178****11 */
    protected function maskTel(string $tel): string
    {
        $tel = trim($tel);
        $len = strlen($tel);
        if ($len < 7) {
            return $tel;
        }

        return substr($tel, 0, 3) . '****' . substr($tel, -2);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function formatOrder(int $aid, array $row): array
    {
        $status = (int) ($row['status'] ?? 0);
        $refund = (int) ($row['refund_status'] ?? 0);
        $buytype = (int) ($row['buytype'] ?? 1);

        $team = null;

        if ((int) ($row['teamid'] ?? 0) > 0) {
            $teamRow = Db::name('collage_order_team')->where('id', (int) $row['teamid'])->find();

            if (!empty($teamRow)) {
                $team = [
                    'id'          => (int) $teamRow['id'],
                    'status'      => (int) ($teamRow['status'] ?? 0),
                    'status_text' => self::TEAM_STATUS_TEXT[(int) ($teamRow['status'] ?? 0)] ?? '未知',
                    'teamnum'     => (int) ($teamRow['teamnum'] ?? 0),
                    'num'         => (int) ($teamRow['num'] ?? 0),
                ];
            }
        }

        return [
            'id'              => (int) ($row['id'] ?? 0),
            'ordernum'        => (string) ($row['ordernum'] ?? ''),
            'buytype'         => $buytype,
            'buytype_text'    => self::BUYTYPE_TEXT[$buytype] ?? '未知',
            'teamid'          => (int) ($row['teamid'] ?? 0),
            'team'            => $team,
            'proname'         => (string) ($row['proname'] ?? ''),
            'propic'          => (string) ($row['propic'] ?? ''),
            'ggname'          => (string) ($row['ggname'] ?? ''),
            'num'             => (int) ($row['num'] ?? 0),
            'sell_price'      => (float) ($row['sell_price'] ?? 0),
            'product_price'   => (float) ($row['product_price'] ?? ($row['sell_price'] ?? 0) * (int) ($row['num'] ?? 1)),
            'totalprice'      => (float) ($row['totalprice'] ?? 0),
            'refund_money'    => (float) ($row['refund_money'] ?? 0),
            'refund_reason'   => (string) ($row['refund_reason'] ?? ''),
            'status'          => $status,
            'status_text'     => self::ORDER_STATUS_TEXT[$status] ?? '未知',
            'refund_status'   => $refund,
            'refund_text'     => self::REFUND_TEXT[$refund] ?? '',
            'buyer'           => $this->buyerOf($aid, $row),
            'linkman'         => (string) ($row['linkman'] ?? ''),
            'tel'             => (string) ($row['tel'] ?? ''),
            'area'            => (string) ($row['area'] ?? ''),
            'address'         => trim(((string) ($row['area'] ?? '')) . ' ' . ((string) ($row['address'] ?? ''))),
            'freight_text'    => (string) ($row['freight_text'] ?? '包邮'),
            'platform'        => $this->platformName((string) ($row['platform'] ?? '')),
            'express_com'     => (string) ($row['express_com'] ?? ''),
            'express_no'      => (string) ($row['express_no'] ?? ''),
            'paytype'         => (string) ($row['paytype'] ?? ''),
            'paytime'         => (int) ($row['paytime'] ?? 0),
            'send_time'       => (int) ($row['send_time'] ?? 0),
            'createtime'      => (int) ($row['createtime'] ?? 0),
        ];
    }

    /** 来源平台文案（对标参考 getplatformname） */
    protected function platformName(string $platform): string
    {
        return match ($platform) {
            'wx'        => '微信公众号',
            'wxapp'     => '微信小程序',
            'h5'        => '手机H5',
            'app'       => 'APP',
            'alipay'    => '支付宝',
            default     => '' !== $platform ? $platform : '-',
        };
    }

    /**
     * 下单人信息（机器人订单从 collage_jiqilist 取昵称头像并标记）
     *
     * @param array<string, mixed> $order
     * @return array{mid: int, nickname: string, headimg: string, isjiqiren: int}
     */
    protected function buyerOf(int $aid, array $order): array
    {
        if (1 === (int) ($order['isjiqiren'] ?? 0)) {
            $robot = Db::name('collage_jiqilist')
                ->where('aid', $aid)
                ->where('id', (int) ($order['mid'] ?? 0))
                ->find();

            return [
                'mid'       => (int) ($order['mid'] ?? 0),
                'nickname'  => !empty($robot) ? ((string) $robot['nickname'] . '(机器人)') : '机器人',
                'headimg'   => (string) ($robot['headimg'] ?? ''),
                'isjiqiren' => 1,
            ];
        }

        $member = Db::name('member')
            ->where('aid', $aid)
            ->where('id', (int) ($order['mid'] ?? 0))
            ->field('id, nickname, headimg, tel')
            ->find();

        return [
            'mid'       => (int) ($order['mid'] ?? 0),
            'nickname'  => (string) ($member['nickname'] ?? ''),
            'headimg'   => (string) ($member['headimg'] ?? ''),
            'isjiqiren' => 0,
        ];
    }
}
