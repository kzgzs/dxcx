<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\constant\ExpressCompany;
use app\common\exception\BusinessException;
use app\common\Kdniao;
use think\facade\Db;

/**
 * 幸运拼团服务（P20-3 任务188）
 *
 * 表口径（基线 lucky_collage_* 表，金额单位「元」）：
 * - lucky_collage_sysset      系统设置：robot_get 机器人是否可中奖 / tklx 未中奖退款路线
 *                             / comment_check 评价审核 / pics 轮播图
 * - lucky_collage_product     商品（teamnum/teamhour + 开奖设置：gua_num 中奖名额、
 *                             zjids 指定中奖/bzids 指定不中/zjmax 单人中奖上限、
 *                             zjjl_type 中奖奖励类型、fy_type 未中奖返还方式、tklx 退款路线）
 * - lucky_collage_guige       商品多规格（ks 为规格键）
 * - lucky_collage_order_team  团：status 0未支付 1进行中 2开奖完成 3超时失败
 * - lucky_collage_order       订单：status 0未支付 1已支付 2已发货 3已收货 4关闭；
 *                             iszj 0未开奖 1中奖 2未中奖；isjiqiren=1 时 mid 存 jiqilist id
 * - lucky_collage_jiqilist    机器人昵称头像池
 * - lucky_collage_codelist    在线卡密库（freight_type=4 中奖发货取码）
 *
 * 与普通拼团差异（开奖制）：
 * - 满员即开奖（非成团发货）：kaijiang() 组抽奖池（robot_get=0 时机器人必不中）→
 *   zjids 指定中奖/bzids 指定不中/zjmax 上限校验 → 随机补足 gua_num 名额 →
 *   中奖发奖（余额/积分/佣金/券）+扣库存+发货；未中奖按 bzjl_type 奖励 +
 *   fy_type 红包返还 + tklx 退款路线（1原路退/2退余额/3不退）
 * - 支付触发链 payLuckyCollageOrder()：锁团行 num+1 → 满员开奖 / 超员自动退款 /
 *   同会员同团其余未支付单关闭
 * - 机器人：后台 kaituan() 从 jiqilist 随机抽取发团/参团凑人数，满员同样触发开奖
 * - 超时：dissolveExpiredTeams()（schedule:run 挂接）按 product.failtklx 退款
 *
 * 库存口径（对齐参考系统）：下单不扣库存，开奖时仅中奖订单扣减（未中奖退款不占货）
 */
class LuckyCollageService
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
    public const TEAM_STATUS_TEXT  = [0 => '未支付', 1 => '拼团中', 2 => '拼团成功', 3 => '拼团失败'];
    public const ISZJ_TEXT         = [0 => '待开奖', 1 => '中奖', 2 => '未中奖'];

    public function __construct(
        protected PayService $payService,
        protected CouponService $couponService,
        protected ScoreService $scoreService
    ) {
    }

    /* ================= 系统设置 ================= */

    /**
     * 读取系统设置（无记录时落一条默认行）
     *
     * @return array<string, mixed>
     */
    public function sysset(int $aid): array
    {
        $info = ScopeService::query('lucky_collage_sysset', $aid)->find();

        if (empty($info)) {
            $id = ScopeService::query('lucky_collage_sysset', $aid)->insertGetId([
                'aid'           => $aid,
                'autoshdays'    => 7,
                'comment'       => 1,
                'comment_check' => 1,
                'showjd'        => 1,
                'robot_get'     => 0,
                'tklx'          => 1,
            ]);

            $info = Db::name('lucky_collage_sysset')->where('id', $id)->find();
        }

        return [
            'pics'          => (string) ($info['pics'] ?? ''),
            'autoshdays'    => (int) ($info['autoshdays'] ?? 7),
            'comment'       => (int) ($info['comment'] ?? 1),
            'comment_check' => (int) ($info['comment_check'] ?? 1),
            'showjd'        => (int) ($info['showjd'] ?? 1),
            'member_discount' => (int) ($info['member_discount'] ?? 1),
            'robot_get'     => (int) ($info['robot_get'] ?? 0),
            'tklx'          => (int) ($info['tklx'] ?? 1),
        ];
    }

    /**
     * 保存系统设置（白名单字段）
     *
     * @param array<string, mixed> $data
     */
    public function saveSysset(int $aid, array $data): void
    {
        $this->sysset($aid); // 确保行存在

        $update = [];

        if (array_key_exists('pics', $data)) {
            $update['pics'] = trim((string) $data['pics']);
        }

        if (isset($data['autoshdays'])) {
            $update['autoshdays'] = max(1, (int) $data['autoshdays']);
        }

        if (isset($data['comment'])) {
            $update['comment'] = (int) $data['comment'] === 1 ? 1 : 0;
        }

        if (isset($data['comment_check'])) {
            $update['comment_check'] = (int) $data['comment_check'] === 1 ? 1 : 0;
        }

        if (isset($data['showjd'])) {
            $update['showjd'] = (int) $data['showjd'] === 1 ? 1 : 0;
        }

        if (isset($data['member_discount'])) {
            $update['member_discount'] = (int) $data['member_discount'] === 1 ? 1 : 0;
        }

        if (isset($data['robot_get'])) {
            $update['robot_get'] = (int) $data['robot_get'] === 1 ? 1 : 0;
        }

        if (isset($data['tklx'])) {
            $tklx = (int) $data['tklx'];
            $update['tklx'] = in_array($tklx, [1, 2, 3], true) ? $tklx : 1;
        }

        if (!empty($update)) {
            ScopeService::query('lucky_collage_sysset', $aid)->update($update);
        }
    }

    /* ================= 商品分类 ================= */

    /**
     * @return array<int, array<string, mixed>>
     */
    public function categoryList(int $aid): array
    {
        $rows = ScopeService::query('lucky_collage_category', $aid)
            ->order('sort', 'desc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        return array_map(fn (array $r) => [
            'id'         => (int) ($r['id'] ?? 0),
            'name'       => (string) ($r['name'] ?? ''),
            'pic'        => (string) ($r['pic'] ?? ''),
            'status'     => (int) ($r['status'] ?? 1),
            'sort'       => (int) ($r['sort'] ?? 1),
            'createtime' => (int) ($r['createtime'] ?? 0),
        ], $rows);
    }

    /**
     * @param array<string, mixed> $data
     * @return array{id: int}
     */
    public function categorySave(int $aid, array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));

        if ('' === $name) {
            throw new BusinessException('请输入分类名称');
        }

        $row = [
            'name'   => $name,
            'pic'    => trim((string) ($data['pic'] ?? '')),
            'status' => (int) ($data['status'] ?? 1) === 1 ? 1 : 0,
            'sort'   => (int) ($data['sort'] ?? 1),
        ];

        $id = (int) ($data['id'] ?? 0);

        if ($id > 0) {
            ScopeService::query('lucky_collage_category', $aid)->where('id', $id)->update($row);
        } else {
            $row['aid']        = $aid;
            $row['createtime'] = time();
            $id                = (int) ScopeService::query('lucky_collage_category', $aid)->insertGetId($row);
        }

        return ['id' => $id];
    }

    public function categoryDelete(int $aid, int $id): void
    {
        $used = ScopeService::query('lucky_collage_product', $aid)->where('cid', $id)->count();

        if ($used > 0) {
            throw new BusinessException('该分类下存在商品，暂不能删除');
        }

        ScopeService::query('lucky_collage_category', $aid)->where('id', $id)->delete();
    }

    /* ================= 商品管理 ================= */

    /**
     * 商品列表（keyword 匹配名称；status/checked 空=全部）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function productList(int $aid, string $keyword, string $status, string $checked, int $page, int $pageSize, string $bid = '', int $cid = 0, string $orderField = '', string $orderType = ''): array
    {
        $query = ScopeService::query('lucky_collage_product', $aid);

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

        // 批量补充分类名 cname 与所属商户名 bname
        $cids = array_values(array_unique(array_filter(array_map(static fn (array $r) => (int) ($r['cid'] ?? 0), $rows))));
        $cnames = [];

        if ($cids) {
            $cnames = Db::name('lucky_collage_category')->where('aid', $aid)->whereIn('id', $cids)->column('name', 'id');
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

        $detail['options'] = Db::name('lucky_collage_guige')
            ->where('aid', $aid)
            ->where('proid', $id)
            ->order('id', 'asc')
            ->select()
            ->toArray();

        foreach ($detail['options'] as &$row) {
            $row['id']           = (int) ($row['id'] ?? 0);
            $row['name']         = (string) ($row['name'] ?? '');
            $row['sell_price']   = (float) ($row['sell_price'] ?? 0);
            $row['market_price'] = (float) ($row['market_price'] ?? 0);
            $row['cost_price']   = (float) ($row['cost_price'] ?? 0);
            $row['stock']        = (int) ($row['stock'] ?? 0);
            $row['weight']       = (int) ($row['weight'] ?? 0);
            $row['procode']      = (string) ($row['procode'] ?? '');
            $row['pic']          = (string) ($row['pic'] ?? '');
        }
        unset($row);

        return $detail;
    }

    /**
     * 新增/编辑商品（含开奖设置字段；options 规格行整表同步）
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

        $guaNum = max(0, (int) ($data['gua_num'] ?? 1));

        if ($guaNum > $teamnum) {
            throw new BusinessException('中奖名额不能大于成团人数');
        }

        $options = $data['options'] ?? [];

        if (!is_array($options) || empty($options)) {
            throw new BusinessException('请至少添加一个规格');
        }

        // 规格行整理 + 汇总（取最低售价为主价格）
        $rows       = [];
        $stockTotal = 0;
        $minPrice   = null;

        foreach ($options as $row) {
            $ggName = trim((string) ($row['name'] ?? ''));

            if ('' === $ggName) {
                throw new BusinessException('规格名称不能为空');
            }

            $sellPrice   = max(0, (float) ($row['sell_price'] ?? 0));
            $marketPrice = max(0, (float) ($row['market_price'] ?? 0));
            $costPrice   = max(0, (float) ($row['cost_price'] ?? 0));
            $stock       = max(0, (int) ($row['stock'] ?? 0));
            $weight      = max(0, (int) ($row['weight'] ?? 0));
            $procode     = trim((string) ($row['procode'] ?? ''));
            $pic         = trim((string) ($row['pic'] ?? ''));

            if ($sellPrice <= 0) {
                throw new BusinessException('规格「' . $ggName . '」拼团价须大于0');
            }

            $rows[] = [
                'name'         => $ggName,
                'sell_price'   => $sellPrice,
                'market_price' => $marketPrice,
                'cost_price'   => $costPrice,
                'stock'        => $stock,
                'weight'       => $weight,
                'procode'      => $procode,
                'pic'          => $pic,
            ];

            $stockTotal += $stock;

            if (null === $minPrice || $sellPrice < $minPrice) {
                $minPrice = $sellPrice;
            }
        }

        $fyType = (int) ($data['fy_type'] ?? 1);
        $tklx   = (int) ($data['tklx'] ?? 1);

        // 归一化：24 项字段清单未涉及的旧字段补默认值，避免直接取键触发 Undefined key
        foreach ([
            'failtklx' => 1, 'zjjl_type' => 1, 'bzjl_type' => 1,
            'tzjl_type' => 1, 'sharejltype' => 1, 'freighttype' => 1,
        ] as $dk => $dv) {
            if (!isset($data[$dk])) {
                $data[$dk] = $dv;
            }
        }

        $update = [
            'name'          => $name,
            'pic'           => trim((string) ($data['pic'] ?? '')),
            'pics'          => trim((string) ($data['pics'] ?? '')),
            'cid'           => (int) ($data['cid'] ?? 0),
            'sellpoint'     => trim((string) ($data['sellpoint'] ?? '')),
            'teamnum'       => $teamnum,
            'teamhour'      => $teamhour,
            'leadermoney'   => max(0, (float) ($data['leadermoney'] ?? 0)),
            'buymax'        => max(0, (int) ($data['buymax'] ?? 0)),
            'sort'          => (int) ($data['sort'] ?? 0),
            'status'        => (int) ($data['status'] ?? 1) === 1 ? 1 : 0,
            'detail'        => (string) ($data['detail'] ?? ''),
            'sell_price'    => (float) $minPrice,
            'stock'         => $stockTotal,
            // 开奖设置
            'gua_num'       => $guaNum,
            'zjids'         => $this->cleanIds((string) ($data['zjids'] ?? '')),
            'bzids'         => $this->cleanIds((string) ($data['bzids'] ?? '')),
            'zjmax'         => max(0, (int) ($data['zjmax'] ?? 0)),
            'fy_type'       => 2 === $fyType ? 2 : 1,
            'fy_money'      => max(0, (float) ($data['fy_money'] ?? 0)),
            'fy_money_val'  => max(0, (float) ($data['fy_money_val'] ?? 0)),
            'red_give_mode' => (int) ($data['red_give_mode'] ?? 1) === 2 ? 2 : 1,
            'tklx'          => in_array($tklx, [1, 2, 3], true) ? $tklx : 1,
            'failtklx'      => in_array((int) ($data['failtklx'] ?? 1), [1, 2, 3], true) ? (int) $data['failtklx'] : 1,
            'zjjl_type'     => in_array((int) ($data['zjjl_type'] ?? 1), [1, 2, 3, 4], true) ? (int) $data['zjjl_type'] : 1,
            'zj_money'      => max(0, (float) ($data['zj_money'] ?? 0)),
            'zjscore'       => max(0, (float) ($data['zjscore'] ?? 0)),
            'zjcommission'  => max(0, (float) ($data['zjcommission'] ?? 0)),
            'zj_yhqids'     => $this->cleanIds((string) ($data['zj_yhqids'] ?? '')),
            'bzjl_type'     => in_array((int) ($data['bzjl_type'] ?? 1), [1, 2, 3, 4], true) ? (int) $data['bzjl_type'] : 1,
            'bzj_score'     => max(0, (int) ($data['bzj_score'] ?? 0)),
            'bzj_commission' => (string) max(0, (float) ($data['bzj_commission'] ?? 0)),
            'perlimitdan'   => max(0, (int) ($data['perlimitdan'] ?? 0)),
            'leaderscore'   => max(0, (int) ($data['leaderscore'] ?? 0)),
            'sales'         => max(0, (int) ($data['sales'] ?? 0)),
            'procode'       => trim((string) ($data['procode'] ?? '')),
            'fuwupoint'     => trim((string) ($data['fuwupoint'] ?? '')),
            'tzjl_type'     => in_array((int) ($data['tzjl_type'] ?? 1), [1, 2, 3, 4], true) ? (int) $data['tzjl_type'] : 1,
            'member_money'  => max(0, (float) ($data['member_money'] ?? 0)),
            'zstzcommission' => max(0, (float) ($data['zstzcommission'] ?? 0)),
            'zstz_yhqids'   => $this->cleanIds((string) ($data['zstz_yhqids'] ?? '')),
            'sharejltype'   => in_array((int) ($data['sharejltype'] ?? 1), [1, 2, 3, 4], true) ? (int) $data['sharejltype'] : 1,
            'sharemoney'    => max(0, (float) ($data['sharemoney'] ?? 0)),
            'sharescore'    => max(0, (int) ($data['sharescore'] ?? 0)),
            'sharecommission' => max(0, (float) ($data['sharecommission'] ?? 0)),
            'share_yhqids'  => $this->cleanIds((string) ($data['share_yhqids'] ?? '')),
            'tsktnum'       => max(0, (int) ($data['tsktnum'] ?? 0)),
            'isktdate'      => (int) ($data['isktdate'] ?? 0) === 1 ? 1 : 0,
            'contact_require' => (int) ($data['contact_require'] ?? 0) === 1 ? 1 : 0,
            'freightcontent' => (string) ($data['freightcontent'] ?? ''),
            'bzj_yhqids'    => $this->cleanIds((string) ($data['bzj_yhqids'] ?? '')),
            'freighttype'   => in_array((int) ($data['freighttype'] ?? 1), [1, 2, 3, 4], true) ? (int) $data['freighttype'] : 1,
            // 24 项字段清单新增
            'goods_id'      => (int) ($data['goods_id'] ?? 0),
            'no_discount'   => in_array((int) ($data['no_discount'] ?? -1), [-1, 0, 1], true) ? (int) $data['no_discount'] : -1,
            'freightid'     => max(0, (int) ($data['freightid'] ?? 0)),
            'commissionset' => in_array((int) ($data['commissionset'] ?? 0), [0, 1, 2, 3, -1], true) ? (int) $data['commissionset'] : 0,
            'commission1'   => max(0, (float) ($data['commission1'] ?? 0)),
            'commission2'   => max(0, (float) ($data['commission2'] ?? 0)),
            'commission3'   => max(0, (float) ($data['commission3'] ?? 0)),
            'guigedata'     => (string) ($data['guigedata'] ?? ''),
        ];

        $id = (int) ($data['id'] ?? 0);

        if ($id > 0) {
            $this->findProduct($aid, $id);

            ScopeService::query('lucky_collage_product', $aid)->where('id', $id)->update($update);
        } else {
            $update['aid']        = $aid;
            $update['bid']        = 0;
            $update['ischecked']  = 1;
            $update['createtime'] = time();

            $id = (int) ScopeService::query('lucky_collage_product', $aid)->insertGetId($update);
        }

        // 规格整表同步（ks=规格名 md5）
        $keepIds = [];

        foreach ($rows as $row) {
            $ks    = md5($row['name']);
            $ggRow = [
                'proid'        => $id,
                'ks'           => $ks,
                'name'         => $row['name'],
                'sell_price'   => $row['sell_price'],
                'market_price' => $row['market_price'],
                'cost_price'   => $row['cost_price'],
                'stock'        => $row['stock'],
                'weight'       => $row['weight'],
                'procode'      => $row['procode'],
                'pic'          => $row['pic'],
            ];

            $exists = Db::name('lucky_collage_guige')
                ->where('aid', $aid)
                ->where('proid', $id)
                ->where('ks', $ks)
                ->find();

            if (!empty($exists)) {
                Db::name('lucky_collage_guige')->where('id', (int) $exists['id'])->update($ggRow);
                $keepIds[] = (int) $exists['id'];
            } else {
                $ggRow['aid'] = $aid;
                $keepIds[]    = (int) Db::name('lucky_collage_guige')->insertGetId($ggRow);
            }
        }

        Db::name('lucky_collage_guige')
            ->where('aid', $aid)
            ->where('proid', $id)
            ->whereNotIn('id', $keepIds ?: [0])
            ->delete();

        return ['id' => $id];
    }

    public function productStatus(int $aid, int $id, int $status): void
    {
        $this->findProduct($aid, $id);

        if (0 !== $status && 1 !== $status) {
            throw new BusinessException('非法的状态');
        }

        ScopeService::query('lucky_collage_product', $aid)->where('id', $id)->update(['status' => $status]);
    }

    public function productCheck(int $aid, int $id, int $ischecked, string $reason): void
    {
        $this->findProduct($aid, $id);

        if (1 !== $ischecked && 2 !== $ischecked) {
            throw new BusinessException('非法的审核结果');
        }

        if (2 === $ischecked && '' === trim($reason)) {
            throw new BusinessException('请填写驳回原因');
        }

        ScopeService::query('lucky_collage_product', $aid)->where('id', $id)->update([
            'ischecked'    => $ischecked,
            'check_reason' => 2 === $ischecked ? trim($reason) : '',
        ]);
    }

    public function productDelete(int $aid, int $id): void
    {
        $this->findProduct($aid, $id);

        $ongoing = Db::name('lucky_collage_order_team')
            ->where('aid', $aid)
            ->where('proid', $id)
            ->where('status', self::TEAM_ONGOING)
            ->count();

        if ($ongoing > 0) {
            throw new BusinessException('该商品存在进行中的团，暂不能删除');
        }

        ScopeService::query('lucky_collage_product', $aid)->where('id', $id)->delete();
        Db::name('lucky_collage_guige')->where('aid', $aid)->where('proid', $id)->delete();
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
     * 团列表（status 空=全部）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function teamList(int $aid, string $status, int $page, int $pageSize, string $ctimeStart = '', string $ctimeEnd = ''): array
    {
        $query = ScopeService::query('lucky_collage_order_team', $aid);

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
     * 删除团（仅未支付/已开奖/已失败可删）
     */
    public function teamDelete(int $aid, array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));

        if (empty($ids)) {
            throw new BusinessException('请选择要删除的团');
        }

        $ongoing = ScopeService::query('lucky_collage_order_team', $aid)
            ->whereIn('id', $ids)
            ->where('status', self::TEAM_ONGOING)
            ->count();

        if ($ongoing > 0) {
            throw new BusinessException('进行中的团涉及资金，不允许删除');
        }

        ScopeService::query('lucky_collage_order_team', $aid)->whereIn('id', $ids)->delete();
    }

    /* ================= 订单管理 ================= */

    /**
     * 订单列表（status ''=全部 0-4 / r前缀退款态；iszj ''=全部 0/1/2）
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
        $query = ScopeService::query('lucky_collage_order', $aid);

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
     * 关闭订单（仅未支付；释放占用券）
     */
    public function orderClose(int $aid, int $orderId): void
    {
        Db::transaction(function () use ($aid, $orderId) {
            $order = Db::name('lucky_collage_order')
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

            $this->couponService->releaseByOrder((int) ($order['coupon_rid'] ?? 0), $orderId);

            Db::name('lucky_collage_order')->where('id', $orderId)->update([
                'status' => self::ORDER_CLOSED,
                'teamid' => 0,
            ]);
        });
    }

    /**
     * 中奖订单发货（status=1 → 2）
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
            ScopeService::query('lucky_collage_order', $aid)->where('id', $orderId)->update([
                'express_com' => $expressCom,
                'express_no'  => $expressNo,
                'send_time'   => time(),
                'status'      => self::ORDER_SHIPPED,
            ]);

            return;
        }

        if (self::ORDER_SHIPPED === (int) $order['status']) {
            ScopeService::query('lucky_collage_order', $aid)->where('id', $orderId)->update([
                'express_com' => $expressCom,
                'express_no'  => $expressNo,
            ]);

            return;
        }

        throw new BusinessException('当前订单状态不可发货');
    }

    /**
     * 订单详情
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

        ScopeService::query('lucky_collage_order', $aid)->whereIn('id', $ids)->delete();
    }

    /**
     * 订单改价（仅未支付）
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

        ScopeService::query('lucky_collage_order', $aid)->where('id', $orderId)->update([
            'totalprice' => $newPrice,
        ]);
    }

    /**
     * 设置订单备注
     */
    public function orderSetRemark(int $aid, int $orderId, string $content): void
    {
        $this->findOrder($aid, $orderId);

        ScopeService::query('lucky_collage_order', $aid)->where('id', $orderId)->update([
            'remark' => trim($content),
        ]);
    }

    /**
     * 改为已支付（后台支付；仅未支付）
     */
    public function orderIspay(int $aid, int $orderId): void
    {
        $order = $this->findOrder($aid, $orderId);

        if (self::ORDER_UNPAID !== (int) $order['status']) {
            throw new BusinessException('仅未支付订单可改为已支付');
        }

        ScopeService::query('lucky_collage_order', $aid)->where('id', $orderId)->update([
            'status'  => self::ORDER_PAID,
            'paytime' => time(),
            'paytype' => '后台支付',
        ]);
    }

    /* ================= 评价管理 ================= */

    /**
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function commentList(int $aid, string $content, string $ctimeStart, string $ctimeEnd, string $status, int $page, int $pageSize): array
    {
        $query = ScopeService::query('lucky_collage_comment', $aid);

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
            'proname'       => (string) ($r['proname'] ?? ''),
            'propic'        => (string) ($r['propic'] ?? ''),
            'ggname'        => (string) ($r['ggname'] ?? ''),
            'nickname'      => (string) ($r['nickname'] ?? ''),
            'headimg'       => (string) ($r['headimg'] ?? ''),
            'score'         => (int) ($r['score'] ?? 5),
            'content'       => (string) ($r['content'] ?? ''),
            'content_pic'   => (string) ($r['content_pic'] ?? ''),
            'reply_content' => (string) ($r['reply_content'] ?? ''),
            'status'        => (int) ($r['status'] ?? 1),
            'createtime'    => (int) ($r['createtime'] ?? 0),
        ], $rows);

        return ['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize];
    }

    public function commentReply(int $aid, int $id, string $content): void
    {
        $content = trim($content);

        if ('' === $content) {
            throw new BusinessException('请填写回复内容');
        }

        ScopeService::query('lucky_collage_comment', $aid)->where('id', $id)->update([
            'reply_content' => $content,
            'reply_time'    => time(),
        ]);
    }

    public function commentDelete(int $aid, int $id): void
    {
        ScopeService::query('lucky_collage_comment', $aid)->where('id', $id)->delete();
    }

    /**
     * 评价审核/批量审核（status=1 通过 / 0 不通过）
     *
     * @param int[] $ids
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
            ScopeService::query('lucky_collage_comment', $aid)->where('id', $id)->update(['status' => $status]);
        }
    }

    /**
     * 评价详情
     *
     * @return array<string, mixed>
     */
    public function commentDetail(int $aid, int $id): array
    {
        $row = ScopeService::query('lucky_collage_comment', $aid)->where('id', $id)->find();

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

    /* ================= 卡密管理 ================= */

    /**
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function codelistList(int $aid, int $proid, string $status, int $page, int $pageSize): array
    {
        $query = ScopeService::query('lucky_collage_codelist', $aid);

        if ($proid > 0) {
            $query->where('proid', $proid);
        }

        if ('' !== $status) {
            $query->where('status', (int) $status);
        }

        $total = (clone $query)->count();

        $rows = $query->order('id', 'desc')->page($page, $pageSize)->select()->toArray();

        $list = array_map(fn (array $r) => [
            'id'         => (int) ($r['id'] ?? 0),
            'proid'      => (int) ($r['proid'] ?? 0),
            'proname'    => (string) Db::name('lucky_collage_product')->where('id', (int) ($r['proid'] ?? 0))->value('name'),
            'content'    => (string) ($r['content'] ?? ''),
            'ordernum'   => (string) ($r['ordernum'] ?? ''),
            'status'     => (int) ($r['status'] ?? 0),
            'nickname'   => (string) ($r['nickname'] ?? ''),
            'createtime' => (int) ($r['createtime'] ?? 0),
            'buytime'    => (int) ($r['buytime'] ?? 0),
        ], $rows);

        return ['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize];
    }

    /**
     * 导入卡密（按行去重，跳过已存在内容）
     *
     * @param array<int, string> $contents
     * @return array{added: int, skipped: int}
     */
    public function codelistImport(int $aid, int $proid, array $contents): array
    {
        $this->findProduct($aid, $proid);

        $exists = Db::name('lucky_collage_codelist')
            ->where('aid', $aid)
            ->where('proid', $proid)
            ->column('content');

        $existsMap = array_flip(array_map('strval', $exists));
        $added     = 0;
        $skipped   = 0;
        $seen      = [];

        foreach ($contents as $content) {
            $content = trim((string) $content);

            if ('' === $content || isset($seen[$content])) {
                ++$skipped;

                continue;
            }

            $seen[$content] = true;

            if (isset($existsMap[$content])) {
                ++$skipped;

                continue;
            }

            Db::name('lucky_collage_codelist')->insert([
                'aid'        => $aid,
                'proid'      => $proid,
                'content'    => $content,
                'status'     => 0,
                'createtime' => time(),
            ]);

            ++$added;
        }

        return ['added' => $added, 'skipped' => $skipped];
    }

    /**
     * 删除卡密（仅未售出）
     */
    public function codelistDelete(int $aid, int $id): void
    {
        $row = ScopeService::query('lucky_collage_codelist', $aid)->where('id', $id)->find();

        if (empty($row)) {
            throw new BusinessException('卡密不存在', 404, 404);
        }

        if (0 !== (int) ($row['status'] ?? 0)) {
            throw new BusinessException('已售出的卡密不能删除');
        }

        Db::name('lucky_collage_codelist')->where('id', $id)->delete();
    }

    /* ================= 机器人管理 ================= */

    /**
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function jiqiList(int $aid, int $page, int $pageSize): array
    {
        $query = ScopeService::query('lucky_collage_jiqilist', $aid);

        $total = (clone $query)->count();

        $rows = $query->order('id', 'desc')->page($page, $pageSize)->select()->toArray();

        $list = array_map(fn (array $r) => [
            'id'         => (int) ($r['id'] ?? 0),
            'nickname'   => (string) ($r['nickname'] ?? ''),
            'headimg'    => (string) ($r['headimg'] ?? ''),
            'status'     => (int) ($r['status'] ?? 1),
            'sort'       => (int) ($r['sort'] ?? 1),
            'createtime' => (int) ($r['createtime'] ?? 0),
        ], $rows);

        return ['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{id: int}
     */
    public function jiqiSave(int $aid, array $data): array
    {
        $nickname = trim((string) ($data['nickname'] ?? ''));

        if ('' === $nickname) {
            throw new BusinessException('请输入机器人昵称');
        }

        $row = [
            'nickname' => $nickname,
            'headimg'  => trim((string) ($data['headimg'] ?? '')),
            'status'   => (int) ($data['status'] ?? 1) === 1 ? 1 : 0,
            'sort'     => (int) ($data['sort'] ?? 1),
        ];

        $id = (int) ($data['id'] ?? 0);

        if ($id > 0) {
            ScopeService::query('lucky_collage_jiqilist', $aid)->where('id', $id)->update($row);
        } else {
            $row['aid']        = $aid;
            $row['createtime'] = time();
            $id                = (int) ScopeService::query('lucky_collage_jiqilist', $aid)->insertGetId($row);
        }

        return ['id' => $id];
    }

    public function jiqiDelete(int $aid, int $id): void
    {
        ScopeService::query('lucky_collage_jiqilist', $aid)->where('id', $id)->delete();
    }

    /**
     * 后台机器人开团/参团（凑人数）：buytype 2发团 3参团
     *
     * 机器人从 jiqilist 随机抽取（排除已在目标团/进行中团的机器人），
     * 订单 isjiqiren=1、status=1 直接已支付（paytype=后台支付），凑满 teamnum 同样触发开奖
     *
     * @return array{team_id: int, order_id: int, kaijiang: bool}
     */
    public function kaituan(int $aid, int $proid, int $buytype, int $teamId): array
    {
        if (!in_array($buytype, [2, 3], true)) {
            throw new BusinessException('非法的操作类型');
        }

        $product = $this->findProduct($aid, $proid);

        if (1 !== (int) ($product['status'] ?? 0)) {
            throw new BusinessException('商品未上架');
        }

        $robots = Db::name('lucky_collage_jiqilist')
            ->where('aid', $aid)
            ->where('status', 1)
            ->select()
            ->toArray();

        if (empty($robots)) {
            throw new BusinessException('请先在机器人管理中配置机器人');
        }

        return Db::transaction(function () use ($aid, $proid, $buytype, $teamId, $product, $robots) {
            if (2 === $buytype) {
                // 发团：排除已在该商品进行中团内的机器人
                $busyTeamIds = Db::name('lucky_collage_order_team')
                    ->where('aid', $aid)
                    ->where('proid', $proid)
                    ->where('status', self::TEAM_ONGOING)
                    ->column('id');

                $busyMids = [];

                if (!empty($busyTeamIds)) {
                    $busyMids = array_map('intval', Db::name('lucky_collage_order')
                        ->where('aid', $aid)
                        ->where('isjiqiren', 1)
                        ->whereIn('teamid', $busyTeamIds)
                        ->column('mid'));
                }

                $pool = array_filter($robots, fn (array $r) => !in_array((int) $r['id'], $busyMids, true));

                if (empty($pool)) {
                    throw new BusinessException('没有可用机器人（均在团中）');
                }

                $robot   = $pool[array_rand($pool)];
                $robotId = (int) $robot['id'];

                $teamId = (int) Db::name('lucky_collage_order_team')->insertGetId([
                    'aid'        => $aid,
                    'bid'        => (int) ($product['bid'] ?? 0),
                    'mid'        => (string) $robotId,
                    'proid'      => $proid,
                    'teamhour'   => (int) ($product['teamhour'] ?? 24),
                    'teamnum'    => (int) ($product['teamnum'] ?? 3),
                    'status'     => self::TEAM_ONGOING,
                    'num'        => 0,
                    'createtime' => time(),
                ]);
            } else {
                // 参团：锁定一个进行中且未满员未超时的团
                $now = time();

                $query = Db::name('lucky_collage_order_team')
                    ->where('aid', $aid)
                    ->where('proid', $proid)
                    ->where('status', self::TEAM_ONGOING)
                    ->whereRaw('createtime + teamhour * 3600 > ' . $now)
                    ->whereColumn('num', '<', 'teamnum');

                if ($teamId > 0) {
                    $query->where('id', $teamId);
                }

                $team = $query->lock(true)->find();

                if (empty($team)) {
                    throw new BusinessException('没有可参与的团（均已满员或超时），请先用机器人发团');
                }

                $teamId = (int) $team['id'];
            }

            // 排除已在该团的机器人后随机抽取
            $inTeamMids = array_map('intval', Db::name('lucky_collage_order')
                ->where('aid', $aid)
                ->where('teamid', $teamId)
                ->where('isjiqiren', 1)
                ->whereIn('status', [self::ORDER_UNPAID, self::ORDER_PAID])
                ->column('mid'));

            $pool = array_filter($robots, fn (array $r) => !in_array((int) $r['id'], $inTeamMids, true));

            if (empty($pool)) {
                throw new BusinessException('没有可用机器人（均已在该团中）');
            }

            $robot   = $pool[array_rand($pool)];
            $robotId = (int) $robot['id'];

            $guige = Db::name('lucky_collage_guige')
                ->where('aid', $aid)
                ->where('proid', $proid)
                ->order('id', 'asc')
                ->find();

            $sellPrice = $guige
                ? (float) $guige['sell_price']
                : (float) ($product['sell_price'] ?? 0);
            $ggId   = $guige ? (int) $guige['id'] : 0;
            $ggName = $guige ? (string) $guige['name'] : '';
            $freightType = (int) ($product['freighttype'] ?? 1);
            $freightText = [1 => '到店自提', 2 => '普通快递', 3 => '自动发货', 4 => '在线卡密'][$freightType] ?? '普通快递';

            $orderId = (int) Db::name('lucky_collage_order')->insertGetId([
                'aid'           => $aid,
                'bid'           => (int) ($product['bid'] ?? 0),
                'mid'           => $robotId,
                'ordernum'      => date('ymdHis') . $aid . random_int(1000, 9999),
                'buytype'       => $buytype,
                'teamid'        => $teamId,
                'title'         => (string) $product['name'],
                'proid'         => $proid,
                'proname'       => (string) $product['name'],
                'propic'        => (string) ($product['pic'] ?? ''),
                'ggid'          => $ggId,
                'ggname'        => $ggName,
                'num'           => 1,
                'sell_price'    => $sellPrice,
                'totalprice'    => $sellPrice,
                'product_price' => $sellPrice,
                'freight_type'  => $freightType,
                'freight_text'  => $freightText,
                'freight_price' => 0,
                'createtime'    => time(),
                'status'        => self::ORDER_PAID,
                'isjiqiren'     => 1,
                'iszj'          => 0,
                'paytype'       => '后台支付',
                'paytime'       => time(),
                'platform'      => 'admin',
            ]);

            $kaijiang = $this->bumpTeamAndMaybeKaijiang($teamId, $orderId);

            return ['team_id' => $teamId, 'order_id' => $orderId, 'kaijiang' => $kaijiang];
        });
    }

    /**
     * 团人数+1，满员则置成功并开奖（事务内调用）
     */
    protected function bumpTeamAndMaybeKaijiang(int $teamId, int $orderId): bool
    {
        $team = Db::name('lucky_collage_order_team')->where('id', $teamId)->lock(true)->find();

        if (empty($team) || self::TEAM_ONGOING !== (int) $team['status']) {
            return false;
        }

        $newNum = (int) $team['num'] + 1;

        if ($newNum >= (int) $team['teamnum']) {
            Db::name('lucky_collage_order_team')->where('id', $teamId)->update([
                'num'    => (int) $team['teamnum'],
                'status' => self::TEAM_SUCCESS,
            ]);

            $order = Db::name('lucky_collage_order')->where('id', $orderId)->find();

            if (!empty($order)) {
                $this->kaijiang($order);
            }

            return true;
        }

        Db::name('lucky_collage_order_team')->where('id', $teamId)->update(['num' => $newNum]);

        return false;
    }

    /* ================= 开奖结算（核心） ================= */

    /**
     * 幸运拼团开奖（团满员后调用，须在事务上下文内执行）
     *
     * 流程：组抽奖池（robot_get=0 时机器人必不中）→ zjids 指定中奖 / bzids 指定不中 /
     * zjmax 单人中奖上限校验 → 随机补足剩余中奖名额（gua_num - 指定数）→
     * 中奖：扣库存 + 发奖（余额/积分/佣金/券）+ 发货（卡密/自动发货）+ iszj=1；
     * 未中奖：bzjl_type 奖励 + fy_type 红包返还 + tklx 退款路线 + 退积分退券 + iszj=2
     *
     * @param array<string, mixed> $order 团内任一订单行（取 aid/proid/teamid）
     */
    public function kaijiang(array $order): void
    {
        $aid = (int) ($order['aid'] ?? 0);

        $product = Db::name('lucky_collage_product')->where('id', (int) $order['proid'])->find();

        if (empty($product)) {
            return;
        }

        $orderlist = Db::name('lucky_collage_order')
            ->where('teamid', (int) $order['teamid'])
            ->where('status', self::ORDER_PAID)
            ->select()
            ->toArray();

        if (empty($orderlist)) {
            return;
        }

        $sysset = Db::name('lucky_collage_sysset')->where('aid', $aid)->find();

        // 抽奖池：会员记正 mid，机器人记负 jiqilist id
        $teamarr1 = [];
        $bzjarr   = [];
        $zjarr    = [];

        foreach ($orderlist as $ov) {
            if (1 === (int) ($ov['isjiqiren'] ?? 0)) {
                $teamarr1[] = -((int) $ov['mid']);

                if (0 === (int) ($sysset['robot_get'] ?? 0)) {
                    $bzjarr[] = -((int) $ov['mid']); // 机器人不参与中奖
                }
            } else {
                $teamarr1[] = (int) $ov['mid'];

                // 单人中奖上限校验（zjmax>0 时生效）
                $zjmax = (int) ($product['zjmax'] ?? 0);

                if ($zjmax > 0) {
                    $zjnum = Db::name('lucky_collage_order')
                        ->where('aid', $aid)
                        ->where('mid', (int) $ov['mid'])
                        ->where('proid', (int) $order['proid'])
                        ->where('iszj', 1)
                        ->where('isjiqiren', 0)
                        ->count();

                    if ($zjnum >= $zjmax) {
                        $bzjarr[] = (int) $ov['mid'];
                    }
                }
            }
        }

        // 指定不中/指定中奖（仅保留实际在本团内的 id）
        if (!empty($product['bzids'])) {
            foreach (explode(',', (string) $product['bzids']) as $bid2) {
                $val = (int) $bid2;

                if ($val > 0 && in_array($val, $teamarr1, true)) {
                    $bzjarr[] = $val;
                }
            }
        }

        if (!empty($product['zjids'])) {
            foreach (explode(',', (string) $product['zjids']) as $zid) {
                $val = (int) $zid;

                if ($val > 0 && in_array($val, $teamarr1, true)) {
                    $zjarr[] = $val;
                }
            }
        }

        $teamarr1 = array_values(array_diff($teamarr1, $zjarr));  // 去掉指定中奖
        $teamarr  = array_values(array_diff($teamarr1, $bzjarr)); // 去掉指定不中

        // 随机抽取剩余中奖名额
        $syzjnum  = max(0, min((int) $product['gua_num'] - count($zjarr), count($teamarr)));
        $newteam  = [];

        if ($syzjnum > 0 && count($teamarr) > 0) {
            $selected = array_rand($teamarr, $syzjnum);
            $selected = is_array($selected) ? $selected : [$selected];

            foreach ($selected as $s) {
                $newteam[] = $teamarr[$s];
                unset($teamarr[$s]);
            }
        }

        $winners = array_merge($zjarr, $newteam);
        $losers  = array_merge($bzjarr, array_values($teamarr));

        /* ---- 中奖处理 ---- */
        $stockUsed = 0;

        foreach ($winners as $v) {
            $orderinfo = $this->findTeamOrder((int) $order['teamid'], (int) abs($v), $v > 0 ? 0 : 1);

            if (empty($orderinfo)) {
                continue;
            }

            // 中奖扣库存（规格 + 商品）
            $onum = (int) ($orderinfo['num'] ?? 1);

            if ((int) ($orderinfo['ggid'] ?? 0) > 0) {
                Db::name('lucky_collage_guige')->where('id', (int) $orderinfo['ggid'])->dec('stock', $onum)->update();
            }

            $stockUsed += $onum;

            if ($v > 0) {
                // 中奖奖励（zjjl_type 1余额 2积分 3佣金 4券）
                $this->grantWinnerReward($aid, (int) $orderinfo['mid'], $product);
            }

            // 发货（freight_type 3自动发货 / 4在线卡密；实物留待后台发货）
            $this->autoShipWinner($aid, $orderinfo);

            Db::name('lucky_collage_order')->where('id', (int) $orderinfo['id'])->update(['iszj' => 1]);
        }

        if ($stockUsed > 0) {
            Db::name('lucky_collage_product')->where('id', (int) $product['id'])
                ->where('stock', '>=', $stockUsed)->dec('stock', $stockUsed)->update();
            Db::name('lucky_collage_product')->where('id', (int) $product['id'])->inc('sales', $stockUsed)->update();
        }

        /* ---- 未中奖处理 ---- */
        foreach ($losers as $t) {
            $orderinfo = $this->findTeamOrder((int) $order['teamid'], (int) abs($t), $t > 0 ? 0 : 1, true);

            if (empty($orderinfo)) {
                continue;
            }

            $orderId = (int) $orderinfo['id'];

            if ($t < 0) {
                // 机器人：仅关单标记未中奖
                Db::name('lucky_collage_order')->where('id', $orderId)->update([
                    'status' => self::ORDER_CLOSED,
                    'iszj'   => 2,
                ]);

                continue;
            }

            $mid = (int) $orderinfo['mid'];

            // 未中奖奖励（bzjl_type 1余额 2积分 3佣金 4券）
            $this->grantLoserReward($aid, $mid, $product);

            // fy_type 红包返还（1按比例 sell_price*fy_money/100，2固定 fy_money_val）
            $money = 1 === (int) $product['fy_type']
                ? (float) number_format((float) $product['sell_price'] * (float) $product['fy_money'] / 100, 2, '.', '')
                : (float) $product['fy_money_val'];

            if ($money > 0) {
                $this->giveRedPacketInternal($aid, $mid, $money, $orderinfo, $product);
            }

            Db::name('lucky_collage_order')->where('id', $orderId)->update(['money' => $money]);

            // tklx 退款路线（3 不退款：订单保留已支付仅标记未中奖）
            if (3 === (int) $product['tklx']) {
                Db::name('lucky_collage_order')->where('id', $orderId)->update(['iszj' => 2]);

                continue;
            }

            $this->refundByRoute($orderinfo, (int) $product['tklx'], '拼团未中奖退款');
            $this->refundAssetsInternal($aid, $orderinfo, '拼团未中奖退款');

            Db::name('lucky_collage_order')->where('id', $orderId)->update([
                'status'        => self::ORDER_CLOSED,
                'refund_status' => 2,
                'refund_time'   => time(),
                'refund_money'  => (float) ($orderinfo['totalprice'] ?? 0),
                'refund_reason' => '拼团未中奖退款',
                'iszj'          => 2,
            ]);
        }
    }

    /**
     * 取团内指定成员的已支付订单（isJiqi 0会员 1机器人；onlyUnset 仅取未开奖行）
     *
     * @return array<string, mixed>
     */
    protected function findTeamOrder(int $teamId, int $mid, int $isJiqi, bool $onlyUnset = false): array
    {
        $query = Db::name('lucky_collage_order')
            ->where('teamid', $teamId)
            ->where('mid', $mid)
            ->where('status', self::ORDER_PAID)
            ->where('isjiqiren', $isJiqi);

        if ($onlyUnset) {
            $query->where('iszj', 0);
        }

        return (array) ($query->find() ?: []);
    }

    /**
     * 中奖奖励发放（zjjl_type 1余额 zj_money / 2积分 zjscore / 3佣金 zjcommission / 4券 zj_yhqids）
     *
     * @param array<string, mixed> $product
     */
    protected function grantWinnerReward(int $aid, int $mid, array $product): void
    {
        switch ((int) ($product['zjjl_type'] ?? 1)) {
            case 1:
                $this->addMoneyInternal($aid, $mid, (float) ($product['zj_money'] ?? 0), '参加拼团中奖奖励余额');
                break;
            case 2:
                $this->addScoreInternal($aid, $mid, (int) (float) ($product['zjscore'] ?? 0), '参加拼团中奖奖励积分');
                break;
            case 3:
                $this->addCommissionInternal($aid, $mid, (float) ($product['zjcommission'] ?? 0), '参加拼团中奖奖励佣金');
                break;
            case 4:
                $this->grantCouponsInternal($aid, $mid, (string) ($product['zj_yhqids'] ?? ''));
                break;
        }
    }

    /**
     * 未中奖奖励发放（bzjl_type 1余额 fy_money_val / 2积分 bzj_score / 3佣金 bzj_commission / 4券 bzj_yhqids）
     *
     * @param array<string, mixed> $product
     */
    protected function grantLoserReward(int $aid, int $mid, array $product): void
    {
        switch ((int) ($product['bzjl_type'] ?? 1)) {
            case 1:
                $this->addMoneyInternal($aid, $mid, (float) ($product['fy_money_val'] ?? 0), '参加拼团未中奖奖励余额');
                break;
            case 2:
                $this->addScoreInternal($aid, $mid, (int) ($product['bzj_score'] ?? 0), '参加拼团未中奖奖励积分');
                break;
            case 3:
                $this->addCommissionInternal($aid, $mid, (float) ($product['bzj_commission'] ?? 0), '参加拼团未中奖奖励佣金');
                break;
            case 4:
                $this->grantCouponsInternal($aid, $mid, (string) ($product['bzj_yhqids'] ?? ''));
                break;
        }
    }

    /**
     * 中奖订单自动发货（freight_type 3 自动发货文案 / 4 在线卡密取码）
     *
     * @param array<string, mixed> $orderinfo
     */
    protected function autoShipWinner(int $aid, array $orderinfo): void
    {
        $freightType = (int) ($orderinfo['freight_type'] ?? 0);

        if (3 === $freightType) {
            $freightContent = (string) Db::name('lucky_collage_product')
                ->where('id', (int) $orderinfo['proid'])
                ->value('freightcontent');

            Db::name('lucky_collage_order')->where('id', (int) $orderinfo['id'])->update([
                'freight_content' => $freightContent,
                'status'          => self::ORDER_SHIPPED,
                'send_time'       => time(),
            ]);
        } elseif (4 === $freightType) {
            $num      = (int) ($orderinfo['num'] ?? 1);
            $codelist = Db::name('lucky_collage_codelist')
                ->where('aid', $aid)
                ->where('proid', (int) $orderinfo['proid'])
                ->where('status', 0)
                ->order('id', 'asc')
                ->limit($num)
                ->select()
                ->toArray();

            if (count($codelist) >= $num) {
                $member = Db::name('member')->where('aid', $aid)->where('id', (int) $orderinfo['mid'])->find();
                $parts  = [];

                foreach ($codelist as $codeinfo) {
                    $parts[] = (string) $codeinfo['content'];

                    Db::name('lucky_collage_codelist')->where('id', (int) $codeinfo['id'])->update([
                        'orderid'    => (int) $orderinfo['id'],
                        'ordernum'   => (string) $orderinfo['ordernum'],
                        'headimg'    => (string) ($member['headimg'] ?? ''),
                        'nickname'   => (string) ($member['nickname'] ?? ''),
                        'buytime'    => time(),
                        'status'     => 1,
                    ]);
                }

                Db::name('lucky_collage_order')->where('id', (int) $orderinfo['id'])->update([
                    'freight_content' => implode("\r\n", $parts),
                    'status'          => self::ORDER_SHIPPED,
                    'send_time'       => time(),
                ]);
            }
        }
    }

    /* ================= 支付触发链 ================= */

    /**
     * 幸运拼团订单支付成功入账（幂等：仅 status=0 → 1）
     *
     * 入账副作用（对标参考系统 Payorder::lucky_collage_pay）：
     * 锁团行 num+1 → 满员置成功并 kaijiang；超员自动退款关单；
     * 同会员同团其余未支付单关闭
     */
    public function payLuckyCollageOrder(int $aid, int $orderId, string $payType, string $payNum): bool
    {
        $order = ScopeService::query('lucky_collage_order', $aid)->where('id', $orderId)->find();

        if (empty($order) || self::ORDER_UNPAID !== (int) $order['status']) {
            return false;
        }

        $affected = Db::name('lucky_collage_order')
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
            ->where('aid', $aid)->where('type', 'lucky_collage')->where('ordernum', (string) $order['ordernum'])
            ->where('status', 0)
            ->update([
                'status'    => 1,
                'paytype'   => $payType,
                'paytypeid' => PayService::PAY_TYPE_IDS[$payType] ?? 0,
                'paynum'    => $payNum,
                'paytime'   => time(),
            ]);

        $order     = array_merge($order, ['status' => self::ORDER_PAID, 'paytype' => $payType]);
        $teamId    = (int) ($order['teamid'] ?? 0);
        $buytype   = (int) ($order['buytype'] ?? 0);

        if ($teamId > 0 && in_array($buytype, [2, 3], true)) {
            Db::transaction(function () use ($aid, $teamId, $buytype, $order, $orderId) {
                $team = Db::name('lucky_collage_order_team')
                    ->where('aid', $aid)
                    ->where('id', $teamId)
                    ->lock(true)
                    ->find();

                if (empty($team)) {
                    return;
                }

                // 发团单入账：团 0→1 转进行中
                if (2 === $buytype && self::TEAM_UNPAID === (int) $team['status']) {
                    Db::name('lucky_collage_order_team')->where('id', $teamId)->update(['status' => self::TEAM_ONGOING]);
                    $team['status'] = self::TEAM_ONGOING;
                }

                if (self::TEAM_ONGOING === (int) $team['status']) {
                    $newNum  = (int) $team['num'] + 1;
                    $teamnum = (int) $team['teamnum'];

                    if ($newNum === $teamnum) {
                        // 满员开奖
                        Db::name('lucky_collage_order_team')->where('id', $teamId)->update([
                            'num'    => $newNum,
                            'status' => self::TEAM_SUCCESS,
                        ]);

                        $this->kaijiang($order);
                    } elseif ($newNum > $teamnum) {
                        // 超员：本单自动退款关单（团人数不回补，保持满员）
                        Db::name('lucky_collage_order')->where('id', $orderId)->update([
                            'status'        => self::ORDER_CLOSED,
                            'refund_status' => 2,
                            'refund_money'  => (float) ($order['totalprice'] ?? 0),
                            'refund_reason' => '拼团已满员，超员自动退款',
                            'refund_time'   => time(),
                            'teamid'        => 0,
                        ]);

                        $this->refundByRoute($order, 1, '拼团已满员，超员自动退款');
                        $this->refundAssetsInternal($aid, $order, '拼团已满员，超员自动退款');

                        return;
                    } else {
                        Db::name('lucky_collage_order_team')->where('id', $teamId)->update(['num' => $newNum]);
                    }
                } elseif (in_array((int) $team['status'], [self::TEAM_SUCCESS, self::TEAM_FAILED], true)) {
                    // 团已开奖/已失败的迟到支付（并发场景）：自动退款关单
                    Db::name('lucky_collage_order')->where('id', $orderId)->update([
                        'status'        => self::ORDER_CLOSED,
                        'refund_status' => 2,
                        'refund_money'  => (float) ($order['totalprice'] ?? 0),
                        'refund_reason' => '拼团已结束，迟到支付自动退款',
                        'refund_time'   => time(),
                        'teamid'        => 0,
                    ]);

                    $this->refundByRoute($order, 1, '拼团已结束，迟到支付自动退款');
                    $this->refundAssetsInternal($aid, $order, '拼团已结束，迟到支付自动退款');

                    return;
                }

                // 关闭同会员同团其余未支付单（防重复参团）
                $otherIds = Db::name('lucky_collage_order')
                    ->where('aid', $aid)
                    ->where('mid', (int) $order['mid'])
                    ->where('teamid', $teamId)
                    ->where('id', '<>', $orderId)
                    ->where('isjiqiren', 0)
                    ->where('status', self::ORDER_UNPAID)
                    ->column('id');

                foreach ($otherIds as $oid) {
                    $other = Db::name('lucky_collage_order')->where('id', (int) $oid)->find();

                    if (!empty($other)) {
                        $this->couponService->releaseByOrder((int) ($other['coupon_rid'] ?? 0), (int) $oid);
                    }

                    Db::name('lucky_collage_order')->where('id', (int) $oid)->update([
                        'status' => self::ORDER_CLOSED,
                        'teamid' => 0,
                    ]);
                }
            });
        }

        return true;
    }

    /**
     * 按退款路线执行退款（route 1原路退回 2退余额 3不退款）
     *
     * @param array<string, mixed> $order
     */
    protected function refundByRoute(array $order, int $route, string $reason): void
    {
        $refundMoney = (float) ($order['totalprice'] ?? 0);

        if ($refundMoney <= 0 || 3 === $route) {
            return;
        }

        $aid = (int) ($order['aid'] ?? 0);
        $mid = (int) ($order['mid'] ?? 0);

        if (2 === $route) {
            $this->addMoneyInternal($aid, $mid, $refundMoney, $reason);

            return;
        }

        // 原路退款（mock 即时成功；真实模式微信 V3 原路退款）
        if (1 === (int) ($order['isjiqiren'] ?? 0)) {
            return; // 机器人无真实支付
        }

        $this->payService->refund($order, $refundMoney, 'RC' . (string) ($order['ordernum'] ?? ''));
        $this->markPayorderRefund($aid, (string) ($order['ordernum'] ?? ''), $refundMoney);
    }

    /**
     * 退资产：抵扣积分返还 + 扣赠送积分 + 释放占用券
     *
     * @param array<string, mixed> $order
     */
    protected function refundAssetsInternal(int $aid, array $order, string $reason): void
    {
        $mid = (int) ($order['mid'] ?? 0);

        if ($mid > 0 && 0 === (int) ($order['isjiqiren'] ?? 0)) {
            if ((int) ($order['scoredkscore'] ?? 0) > 0) {
                $this->scoreService->refundForCancel($aid, $mid, (int) $order['scoredkscore'], $reason . '返还抵扣积分');
            }

            $gift = (int) ($order['givescore'] ?? 0);

            if ($gift > 0) {
                $balance = (int) Db::name('member')->where('aid', $aid)->where('id', $mid)->value('score');
                $deduct  = min($gift, max(0, $balance));

                if ($deduct > 0) {
                    $this->scoreService->adjust($aid, $mid, -$deduct, $reason . '扣除消费赠送积分');
                }
            }
        }

        $this->couponService->releaseByOrder((int) ($order['coupon_rid'] ?? 0), (int) ($order['id'] ?? 0));
    }

    /**
     * payorder 退款登记（type=lucky_collage 原支付单）
     */
    protected function markPayorderRefund(int $aid, string $ordernum, float $refundMoney): void
    {
        if ('' === $ordernum || $refundMoney <= 0) {
            return;
        }

        Db::name('payorder')
            ->where('aid', $aid)
            ->where('type', 'lucky_collage')
            ->where('ordernum', $ordernum)
            ->where('status', 1)
            ->order('id', 'desc')
            ->limit(1)
            ->update([
                'refund_money' => $refundMoney,
                'refund_time'  => time(),
            ]);
    }

    /* ================= 定时任务：超时扫描 ================= */

    /**
     * 扫描超时进行中的团并失败退款（schedule:run 挂接，幂等）
     *
     * 判定：team.status=1 且 createtime + teamhour*3600 < now；
     * 团置 3 失败，团内已支付订单按 product.failtklx（1原路退/2退余额/3不退）退款关闭
     *
     * @return int 失败团数量
     */
    public function dissolveExpiredTeams(int $batch = 100): int
    {
        $now = time();

        $teams = Db::name('lucky_collage_order_team')
            ->where('status', self::TEAM_ONGOING)
            ->whereRaw("createtime + teamhour * 3600 < {$now}")
            ->order('id', 'asc')
            ->limit($batch)
            ->select()
            ->toArray();

        $count = 0;

        foreach ($teams as $team) {
            try {
                // 返回 -1 表示已被处理（幂等跳过），>=0 为本次解散
                if ($this->dissolveTeamInternal($team) >= 0) {
                    ++$count;
                }
            } catch (\Throwable) {
                // 单团失败不影响其他团（状态条件+行锁保证不会重复处理）
            }
        }

        return $count;
    }

    /**
     * 解散超时团核心（整体同事务：团置失败 + 逐单退款关闭）
     *
     * @param array<string, mixed> $team
     * @return int 退款订单数
     */
    protected function dissolveTeamInternal(array $team): int
    {
        return (int) Db::transaction(function () use ($team) {
            $locked = Db::name('lucky_collage_order_team')
                ->where('id', (int) $team['id'])
                ->lock(true)
                ->find();

            if (empty($locked) || self::TEAM_ONGOING !== (int) $locked['status']) {
                return -1; // 已被处理，幂等跳过
            }

            Db::name('lucky_collage_order_team')->where('id', (int) $locked['id'])->update([
                'status' => self::TEAM_FAILED,
            ]);

            $product = Db::name('lucky_collage_product')->where('id', (int) $locked['proid'])->find();
            $route   = (int) ($product['failtklx'] ?? 1);

            $orders = Db::name('lucky_collage_order')
                ->where('aid', (int) $locked['aid'])
                ->where('teamid', (int) $locked['id'])
                ->where('status', self::ORDER_PAID)
                ->select()
                ->toArray();

            foreach ($orders as $row) {
                if (1 === (int) ($row['isjiqiren'] ?? 0)) {
                    Db::name('lucky_collage_order')->where('id', (int) $row['id'])->update([
                        'status'        => self::ORDER_CLOSED,
                        'refund_reason' => '拼团超时失败',
                    ]);

                    continue;
                }

                Db::name('lucky_collage_order')->where('id', (int) $row['id'])->update([
                    'status'        => self::ORDER_CLOSED,
                    'refund_status' => 3 === $route ? 0 : 2,
                    'refund_money'  => 3 === $route ? 0 : (float) ($row['totalprice'] ?? 0),
                    'refund_reason' => '拼团超时失败退款',
                    'refund_time'   => time(),
                ]);

                $this->refundByRoute($row, $route, '拼团超时失败退款');
                $this->refundAssetsInternal((int) $locked['aid'], $row, '拼团超时失败退款');
            }

            return count($orders);
        });
    }

    /* ================= C 端核心链路 ================= */

    /**
     * C 端商品列表（仅上架且审核通过）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function apiProductList(int $aid, int $page, int $pageSize, string $keyword = ''): array
    {
        $query = ScopeService::query('lucky_collage_product', $aid)
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

        // 批量补充分类名 cname 与所属商户名 bname
        $cids = array_values(array_unique(array_filter(array_map(static fn (array $r) => (int) ($r['cid'] ?? 0), $rows))));
        $cnames = [];

        if ($cids) {
            $cnames = Db::name('lucky_collage_category')->where('aid', $aid)->whereIn('id', $cids)->column('name', 'id');
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
     * C 端商品详情（含规格行与进行中团）
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

        $teams = Db::name('lucky_collage_order_team')
            ->where('aid', $aid)
            ->where('proid', $proid)
            ->where('status', self::TEAM_ONGOING)
            ->whereRaw('createtime + teamhour * 3600 > ' . $now)
            ->whereColumn('num', '<', 'teamnum')
            ->order('id', 'desc')
            ->limit($limit)
            ->select()
            ->toArray();

        return array_map(fn (array $t) => $this->formatTeam($aid, $t), $teams);
    }

    /**
     * 团详情（含成员与开奖结果）
     *
     * @return array<string, mixed>
     */
    public function apiTeamDetail(int $aid, int $teamId): array
    {
        $team = ScopeService::query('lucky_collage_order_team', $aid)->where('id', $teamId)->find();

        if (empty($team)) {
            throw new BusinessException('团不存在', 404, 404);
        }

        return $this->formatTeam($aid, $team);
    }

    /**
     * C 端下单（buytype 1单买 2发团 3参团）
     *
     * 库存口径（对齐参考系统）：下单不扣库存，开奖时仅中奖订单扣减
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
            $product = Db::name('lucky_collage_product')
                ->where('aid', $aid)->where('id', $proid)
                ->where('status', 1)->where('ischecked', 1)
                ->lock(true)->find();

            if (empty($product)) {
                throw new BusinessException('幸运拼团商品不存在或已下架');
            }

            // 限购：有效单累计 + 本次 ≤ buymax（0 不限）
            $buymax = (int) ($product['buymax'] ?? 0);

            if ($buymax > 0) {
                $bought = (int) Db::name('lucky_collage_order')
                    ->where('aid', $aid)->where('mid', $mid)->where('proid', $proid)
                    ->where('isjiqiren', 0)
                    ->whereIn('status', [self::ORDER_UNPAID, self::ORDER_PAID, self::ORDER_SHIPPED, self::ORDER_COLLECTED])
                    ->sum('num');

                if ($bought + $num > $buymax) {
                    throw new BusinessException('超出限购数量（每人限拼 ' . $buymax . ' 件）');
                }
            }

            // 参团校验：团进行中、未超时、未满员、未重复参团
            if (3 === $buytype) {
                if ($teamId <= 0) {
                    throw new BusinessException('缺少团ID');
                }

                $team = Db::name('lucky_collage_order_team')
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

                $validNum = (int) Db::name('lucky_collage_order')
                    ->where('aid', $aid)->where('teamid', $teamId)
                    ->whereIn('status', [self::ORDER_UNPAID, self::ORDER_PAID])
                    ->count();

                if ($validNum >= (int) $team['teamnum']) {
                    throw new BusinessException('该团已满员');
                }

                $joined = Db::name('lucky_collage_order')
                    ->where('aid', $aid)->where('mid', $mid)->where('teamid', $teamId)
                    ->whereIn('status', [self::ORDER_UNPAID, self::ORDER_PAID])
                    ->find();

                if (!empty($joined)) {
                    throw new BusinessException('您已在该团中，请勿重复参团');
                }
            }

            // 规格行
            $ggname = '';

            if ($ggid > 0) {
                $guige = Db::name('lucky_collage_guige')
                    ->where('aid', $aid)->where('id', $ggid)->where('proid', $proid)->find();

                if (empty($guige)) {
                    throw new BusinessException('商品规格不存在');
                }

                $ggname = (string) ($guige['name'] ?? '');
            }

            // 价格：团长减优惠（不低0）
            $sellPrice  = (float) ($product['sell_price'] ?? 0);
            $leaderCut  = 2 === $buytype ? min((float) ($product['leadermoney'] ?? 0), $sellPrice * $num) : 0.0;
            $freightFee = round(max(0, (float) ($extra['freight_price'] ?? 0)), 2);
            $totalPrice = round(max(0, $sellPrice * $num - $leaderCut) + $freightFee, 2);
            $ordernum   = date('ymdHis') . $aid . random_int(1000, 9999);

            // 发团先写团（status=0 未支付，支付入账后转进行中）
            if (2 === $buytype) {
                $teamId = (int) Db::name('lucky_collage_order_team')->insertGetId([
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

            $orderId = (int) Db::name('lucky_collage_order')->insertGetId([
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
                'freight_type'  => (int) ($product['freighttype'] ?? 1),
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

            // 支付单（type=lucky_collage）
            Db::name('payorder')->insert([
                'aid'        => $aid,
                'bid'        => (int) ($product['bid'] ?? 0),
                'mid'        => $mid,
                'orderid'    => $orderId,
                'ordernum'   => $ordernum,
                'title'      => (string) $product['name'],
                'money'      => $totalPrice,
                'score'      => 0,
                'type'       => 'lucky_collage',
                'createtime' => time(),
                'status'     => 0,
                'platform'   => (string) ($extra['platform'] ?? 'wx'),
            ]);

            // mock 模式即时支付成功（真实模式由支付回调走 payLuckyCollageOrder）
            if (PayService::isMock()) {
                $this->payLuckyCollageOrder($aid, $orderId, 'wechat', 'MOCK' . time() . random_int(1000, 9999));
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
     * 我的幸运拼团订单（C 端分页）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function apiMyOrders(int $aid, int $mid, string $status, int $page, int $pageSize): array
    {
        $query = ScopeService::query('lucky_collage_order', $aid)->where('mid', $mid)->where('isjiqiren', 0);

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

    /**
     * 开奖结果（订单维度：本人中奖状态 + 团成员中奖情况）
     *
     * @return array<string, mixed>
     */
    public function apiDrawResult(int $aid, int $mid, int $orderId): array
    {
        $order = ScopeService::query('lucky_collage_order', $aid)
            ->where('id', $orderId)
            ->where('mid', $mid)
            ->where('isjiqiren', 0)
            ->find();

        if (empty($order)) {
            throw new BusinessException('订单不存在', 404, 404);
        }

        $result = [
            'order'    => $this->formatOrder($aid, $order),
            'team'     => null,
            'members'  => [],
        ];

        if ((int) ($order['teamid'] ?? 0) > 0) {
            $team = Db::name('lucky_collage_order_team')->where('id', (int) $order['teamid'])->find();

            if (!empty($team)) {
                $result['team'] = $this->formatTeam($aid, $team);
            }

            $members = Db::name('lucky_collage_order')
                ->where('aid', $aid)
                ->where('teamid', (int) $order['teamid'])
                ->where('status', '<>', self::ORDER_UNPAID)
                ->select()
                ->toArray();

            foreach ($members as $m) {
                $buyer          = $this->buyerOf($aid, $m);
                $buyer['iszj']  = (int) ($m['iszj'] ?? 0);
                $buyer['iszj_text'] = self::ISZJ_TEXT[$buyer['iszj']] ?? '待开奖';

                $result['members'][] = $buyer;
            }
        }

        return $result;
    }

    /* ================= 资产入账工具 ================= */

    /**
     * 未中奖红包发放（red_give_mode 1返到余额 / 2返到微信零钱）
     *
     * 返微信零钱：mock 即时成功落 wx_transfer_log；真实模式预留微信 V3 商家转账桩
     *
     * @param array<string, mixed> $order
     * @param array<string, mixed> $product
     */
    protected function giveRedPacketInternal(int $aid, int $mid, float $money, array $order, array $product): void
    {
        if (2 !== (int) ($product['red_give_mode'] ?? 1)) {
            $this->addMoneyInternal($aid, $mid, $money, '拼团未中奖奖励余额');

            return;
        }

        if (!PayService::isMock()) {
            // 真实模式：微信 V3 商家转账（POST /v3/transfer/batches），与提现打款同口径
            throw new BusinessException('真实微信 V3 转账接入开发中（TODO）', 501, 501);
        }

        $payNum = 'MOCK' . time() . random_int(1000, 9999);

        Db::name('wx_transfer_log')->insert([
            'aid'              => $aid,
            'mid'              => $mid,
            'ordernum'         => (string) ($order['ordernum'] ?? ''),
            'money'            => $money,
            'realname'         => '',
            'desc'             => '幸运拼团未中奖红包',
            'data_tbl'         => 'lucky_collage_order',
            'data_id'          => (int) ($order['id'] ?? 0),
            'status_code'      => 'SUCCESS',
            'createtime'       => time(),
            'state'            => 'SUCCESS',
            'transfer_bill_no' => $payNum,
            'message'          => '',
        ]);
    }

    protected function addMoneyInternal(int $aid, int $mid, float $money, string $remark): void
    {
        if ($money <= 0 || $mid <= 0) {
            return;
        }

        $member = Db::name('member')->where('aid', $aid)->where('id', $mid)->lock(true)->find();

        if (empty($member)) {
            return;
        }

        $after = round((float) ($member['money'] ?? 0) + $money, 2);

        Db::name('member')->where('aid', $aid)->where('id', $mid)->update(['money' => $after]);

        Db::name('member_moneylog')->insert([
            'aid'        => $aid,
            'mid'        => $mid,
            'money'      => $money,
            'after'      => $after,
            'createtime' => time(),
            'remark'     => $remark,
            'paytype'    => 'lucky_collage',
        ]);
    }

    protected function addScoreInternal(int $aid, int $mid, int $score, string $remark): void
    {
        if ($score <= 0 || $mid <= 0) {
            return;
        }

        $this->scoreService->adjust($aid, $mid, $score, $remark);
    }

    protected function addCommissionInternal(int $aid, int $mid, float $commission, string $remark): void
    {
        if ($commission <= 0 || $mid <= 0) {
            return;
        }

        $member = Db::name('member')->where('aid', $aid)->where('id', $mid)->lock(true)->find();

        if (empty($member)) {
            return;
        }

        $after = round((float) ($member['commission'] ?? 0) + $commission, 2);

        Db::name('member')->where('aid', $aid)->where('id', $mid)->update([
            'commission'      => $after,
            'totalcommission' => round((float) ($member['totalcommission'] ?? 0) + $commission, 2),
        ]);

        Db::name('member_commissionlog')->insert([
            'aid'        => $aid,
            'mid'        => $mid,
            'frommid'    => $mid,
            'commission' => $commission,
            'after'      => $after,
            'createtime' => time(),
            'remark'     => $remark,
            'fhtype'     => 'luckycollage',
        ]);
    }

    protected function grantCouponsInternal(int $aid, int $mid, string $ids): void
    {
        if ('' === trim($ids) || $mid <= 0) {
            return;
        }

        foreach (explode(',', $ids) as $cid) {
            $cid = (int) $cid;

            if ($cid <= 0) {
                continue;
            }

            try {
                $this->couponService->receive($aid, $mid, $cid);
            } catch (\Throwable) {
                // 限领/库存不足不阻断开奖主链路
            }
        }
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

        $product = ScopeService::query('lucky_collage_product', $aid)->where('id', $id)->find();

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

        $order = ScopeService::query('lucky_collage_order', $aid)->where('id', $orderId)->find();

        if (empty($order)) {
            throw new BusinessException('订单不存在', 404, 404);
        }

        return $order;
    }

    /**
     * 清洗逗号分隔的 id 串（去重去非正数）
     */
    protected function cleanIds(string $ids): string
    {
        $out = [];

        foreach (explode(',', $ids) as $id) {
            $id = (int) $id;

            if ($id > 0 && !in_array($id, $out, true)) {
                $out[] = $id;
            }
        }

        return implode(',', $out);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function formatProduct(array $row): array
    {
        return [
            'id'             => (int) ($row['id'] ?? 0),
            'name'           => (string) ($row['name'] ?? ''),
            'pic'            => (string) ($row['pic'] ?? ''),
            'pics'           => (string) ($row['pics'] ?? ''),
            'cid'            => (int) ($row['cid'] ?? 0),
            'sellpoint'      => (string) ($row['sellpoint'] ?? ''),
            'sell_price'     => (float) ($row['sell_price'] ?? 0),
            'market_price'   => (float) ($row['market_price'] ?? 0),
            'stock'          => (int) ($row['stock'] ?? 0),
            'sales'          => (int) ($row['sales'] ?? 0),
            'teamnum'        => (int) ($row['teamnum'] ?? 3),
            'teamhour'       => (int) ($row['teamhour'] ?? 24),
            'leadermoney'    => (float) ($row['leadermoney'] ?? 0),
            'buymax'         => (int) ($row['buymax'] ?? 0),
            'sort'           => (int) ($row['sort'] ?? 0),
            'status'         => (int) ($row['status'] ?? 0),
            'ischecked'      => (int) ($row['ischecked'] ?? 1),
            'check_reason'   => (string) ($row['check_reason'] ?? ''),
            'detail'         => (string) ($row['detail'] ?? ''),
            'freighttype'    => (int) ($row['freighttype'] ?? 1),
            'gua_num'        => (int) ($row['gua_num'] ?? 1),
            'zjids'          => (string) ($row['zjids'] ?? ''),
            'bzids'          => (string) ($row['bzids'] ?? ''),
            'zjmax'          => (int) ($row['zjmax'] ?? 0),
            'fy_type'        => (int) ($row['fy_type'] ?? 1),
            'fy_money'       => (float) ($row['fy_money'] ?? 0),
            'fy_money_val'   => (float) ($row['fy_money_val'] ?? 0),
            'red_give_mode'  => (int) ($row['red_give_mode'] ?? 1),
            'tklx'           => (int) ($row['tklx'] ?? 1),
            'failtklx'       => (int) ($row['failtklx'] ?? 1),
            'zjjl_type'      => (int) ($row['zjjl_type'] ?? 1),
            'zj_money'       => (float) ($row['zj_money'] ?? 0),
            'zjscore'        => (float) ($row['zjscore'] ?? 0),
            'zjcommission'   => (float) ($row['zjcommission'] ?? 0),
            'zj_yhqids'      => (string) ($row['zj_yhqids'] ?? ''),
            'bzjl_type'      => (int) ($row['bzjl_type'] ?? 1),
            'bzj_score'      => (int) ($row['bzj_score'] ?? 0),
            'bzj_commission' => (float) ($row['bzj_commission'] ?? 0),
            'bzj_yhqids'     => (string) ($row['bzj_yhqids'] ?? ''),
            'procode'        => (string) ($row['procode'] ?? ''),
            'fuwupoint'      => (string) ($row['fuwupoint'] ?? ''),
            'perlimitdan'    => (int) ($row['perlimitdan'] ?? 0),
            'leaderscore'    => (int) ($row['leaderscore'] ?? 0),
            'member_money'   => (float) ($row['member_money'] ?? 0),
            'zstzcommission' => (float) ($row['zstzcommission'] ?? 0),
            'zstz_yhqids'    => (string) ($row['zstz_yhqids'] ?? ''),
            'sharejltype'    => (int) ($row['sharejltype'] ?? 1),
            'sharemoney'     => (float) ($row['sharemoney'] ?? 0),
            'sharescore'     => (int) ($row['sharescore'] ?? 0),
            'sharecommission' => (float) ($row['sharecommission'] ?? 0),
            'share_yhqids'   => (string) ($row['share_yhqids'] ?? ''),
            'tsktnum'        => (int) ($row['tsktnum'] ?? 0),
            'isktdate'       => (int) ($row['isktdate'] ?? 0),
            'contact_require' => (int) ($row['contact_require'] ?? 0),
            'freightcontent' => (string) ($row['freightcontent'] ?? ''),
            'goods_id'       => (int) ($row['goods_id'] ?? 0),
            'no_discount'    => (int) ($row['no_discount'] ?? -1),
            'freightid'      => (int) ($row['freightid'] ?? 0),
            'commissionset'  => (int) ($row['commissionset'] ?? 0),
            'commission1'    => (float) ($row['commission1'] ?? 0),
            'commission2'    => (float) ($row['commission2'] ?? 0),
            'commission3'   => (float) ($row['commission3'] ?? 0),
            'guigedata'      => (string) ($row['guigedata'] ?? ''),
            'createtime'     => (int) ($row['createtime'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function formatTeam(int $aid, array $row): array
    {
        $teamId     = (int) ($row['id'] ?? 0);
        $status     = (int) ($row['status'] ?? 0);
        $createtime = (int) ($row['createtime'] ?? 0);
        $teamhour   = (int) ($row['teamhour'] ?? 0);

        $productRow = Db::name('lucky_collage_product')
            ->where('aid', $aid)
            ->where('id', (int) ($row['proid'] ?? 0))
            ->field('id,name,pic')
            ->find();
        $productName = (string) ($productRow['name'] ?? '');
        $productPic  = (string) ($productRow['pic'] ?? '');

        // 团员列表（含中奖标记）
        $members = [];
        $orders  = Db::name('lucky_collage_order')
            ->where('aid', $aid)
            ->where('teamid', $teamId)
            ->whereIn('status', [self::ORDER_PAID, self::ORDER_SHIPPED, self::ORDER_COLLECTED, self::ORDER_CLOSED])
            ->select()
            ->toArray();

        foreach ($orders as $orderRow) {
            $buyer            = $this->buyerOf($aid, $orderRow);
            $buyer['iszj']    = (int) ($orderRow['iszj'] ?? 0);
            $buyer['iszj_text'] = self::ISZJ_TEXT[$buyer['iszj']] ?? '待开奖';

            $members[] = $buyer;
        }

        $realNum = count($members);

        // 未占位的团员位置补空头像（对齐点大参团人列）
        $need = max(0, (int) ($row['teamnum'] ?? 0) - $realNum);

        for ($i = 0; $i < $need; ++$i) {
            $members[] = ['mid' => 0, 'nickname' => '', 'headimg' => '', 'isjiqiren' => 0, 'iszj' => 0, 'iszj_text' => ''];
        }

        return [
            'id'           => $teamId,
            'proid'        => (int) ($row['proid'] ?? 0),
            'product_name' => $productName,
            'product_pic'  => $productPic,
            'teamnum'      => (int) ($row['teamnum'] ?? 0),
            'num'          => $realNum,
            'status'       => $status,
            'status_text'  => self::TEAM_STATUS_TEXT[$status] ?? '未知',
            'members'      => $members,
            'createtime'   => $createtime,
            'deadline'     => $createtime + $teamhour * 3600,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function formatOrder(int $aid, array $row): array
    {
        $status  = (int) ($row['status'] ?? 0);
        $refund  = (int) ($row['refund_status'] ?? 0);
        $buytype = (int) ($row['buytype'] ?? 1);
        $iszj    = (int) ($row['iszj'] ?? 0);

        $freightType = (int) ($row['freight_type'] ?? 0);

        return [
            'id'             => (int) ($row['id'] ?? 0),
            'ordernum'       => (string) ($row['ordernum'] ?? ''),
            'buytype'        => $buytype,
            'buytype_text'   => self::BUYTYPE_TEXT[$buytype] ?? '未知',
            'teamid'         => (int) ($row['teamid'] ?? 0),
            'proname'        => (string) ($row['proname'] ?? ''),
            'propic'         => (string) ($row['propic'] ?? ''),
            'ggname'         => (string) ($row['ggname'] ?? ''),
            'num'            => (int) ($row['num'] ?? 0),
            'sell_price'     => (float) ($row['sell_price'] ?? 0),
            'product_price'  => (float) ($row['product_price'] ?? ((float) ($row['sell_price'] ?? 0) * (int) ($row['num'] ?? 1))),
            'freight_price'  => (float) ($row['freight_price'] ?? 0),
            'totalprice'     => (float) ($row['totalprice'] ?? 0),
            'scoredk_money'  => (float) ($row['scoredk_money'] ?? 0),
            'leveldk_money'  => (float) ($row['leveldk_money'] ?? 0),
            'leader_money'   => (float) ($row['leader_money'] ?? 0),
            'coupon_money'   => (float) ($row['coupon_money'] ?? 0),
            'refund_money'   => (float) ($row['refund_money'] ?? 0),
            'refund_reason'  => (string) ($row['refund_reason'] ?? ''),
            'status'         => $status,
            'status_text'    => self::ORDER_STATUS_TEXT[$status] ?? '未知',
            'refund_status'  => $refund,
            'refund_text'    => self::REFUND_TEXT[$refund] ?? '',
            'iszj'           => $iszj,
            'iszj_text'      => self::ISZJ_TEXT[$iszj] ?? '待开奖',
            'money'          => (float) ($row['money'] ?? 0),
            'isjiqiren'      => (int) ($row['isjiqiren'] ?? 0),
            'buyer'          => $this->buyerOf($aid, $row),
            'linkman'        => (string) ($row['linkman'] ?? ''),
            'tel'            => (string) ($row['tel'] ?? ''),
            'area'           => (string) ($row['area'] ?? ''),
            'address'        => trim(((string) ($row['area'] ?? '')) . ' ' . ((string) ($row['address'] ?? ''))),
            'message'        => (string) ($row['message'] ?? ''),
            'remark'         => (string) ($row['remark'] ?? ''),
            'freight_type'   => $freightType,
            'freight_text'   => (string) ($row['freight_text'] ?? ''),
            'platform'       => $this->platformName((string) ($row['platform'] ?? '')),
            'express_com'    => (string) ($row['express_com'] ?? ''),
            'express_no'     => (string) ($row['express_no'] ?? ''),
            'hexiao_code'    => (string) ($row['hexiao_code'] ?? ''),
            'paytype'        => (string) ($row['paytype'] ?? ''),
            'paytime'        => (int) ($row['paytime'] ?? 0),
            'send_time'      => (int) ($row['send_time'] ?? 0),
            'collect_time'   => (int) ($row['collect_time'] ?? 0),
            'createtime'     => (int) ($row['createtime'] ?? 0),
            'parent1commission' => (float) ($row['parent1commission'] ?? 0),
            'parent2commission' => (float) ($row['parent2commission'] ?? 0),
            'parent3commission' => (float) ($row['parent3commission'] ?? 0),
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
     * 下单人信息（机器人订单从 lucky_collage_jiqilist 取昵称头像并标记）
     *
     * @param array<string, mixed> $order
     * @return array{mid: int, nickname: string, headimg: string, isjiqiren: int}
     */
    protected function buyerOf(int $aid, array $order): array
    {
        if (1 === (int) ($order['isjiqiren'] ?? 0)) {
            $robot = Db::name('lucky_collage_jiqilist')
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

    /* ================= 订单补充操作（对齐点大） ================= */

    /**
     * 快递公司下拉（手动发货用）
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

            $order = ScopeService::query('lucky_collage_order', $aid)->where('ordernum', $ordernum)->find();

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
     * 到店自提核销（freight_type=1；已支付/已发货 → 已收货）
     */
    public function orderHexiao(int $aid, int $orderId): void
    {
        $order = $this->findOrder($aid, $orderId);

        if (1 !== (int) ($order['freight_type'] ?? 0)) {
            throw new BusinessException('仅到店自提订单可核销');
        }

        if (!in_array((int) $order['status'], [self::ORDER_PAID, self::ORDER_SHIPPED], true)) {
            throw new BusinessException('当前订单状态不可核销');
        }

        ScopeService::query('lucky_collage_order', $aid)->where('id', $orderId)->update([
            'status'       => self::ORDER_COLLECTED,
            'collect_time' => time(),
        ]);
    }

    /**
     * 确认收货（快递配送；已发货 → 已收货）
     */
    public function orderCollect(int $aid, int $orderId): void
    {
        $order = $this->findOrder($aid, $orderId);

        if (self::ORDER_SHIPPED !== (int) $order['status']) {
            throw new BusinessException('仅已发货订单可确认收货');
        }

        ScopeService::query('lucky_collage_order', $aid)->where('id', $orderId)->update([
            'status'       => self::ORDER_COLLECTED,
            'collect_time' => time(),
        ]);
    }

    /**
     * 查物流（快递鸟 1002 即时查询）
     *
     * @return array{express_com: string, express_no: string, state: string, traces: array}
     */
    public function orderGetExpress(int $aid, int $orderId): array
    {
        $order = $this->findOrder($aid, $orderId);

        $expressCom = (string) ($order['express_com'] ?? '');
        $expressNo  = (string) ($order['express_no'] ?? '');

        if ('' === $expressCom || '' === $expressNo) {
            throw new BusinessException('该订单暂无物流信息');
        }

        $shipperCode = (string) (array_flip(ExpressCompany::dict())[$expressCom] ?? $expressCom);
        $kdniao      = new Kdniao($aid);
        $res         = $kdniao->trace($shipperCode, $expressNo, (string) ($order['ordernum'] ?? ''));

        if (1 !== (int) ($res['status'] ?? 0)) {
            throw new BusinessException((string) ($res['msg'] ?? '物流查询失败'));
        }

        $data = $res['data'];

        return [
            'express_com' => $expressCom,
            'express_no'  => $expressNo,
            'state'       => (string) ($data['State'] ?? ''),
            'traces'      => array_map(static function (array $t): array {
                return ['time' => (string) ($t['AcceptTime'] ?? ''), 'station' => (string) ($t['AcceptStation'] ?? '')];
            }, (array) ($data['Traces'] ?? [])),
        ];
    }

    /**
     * 送货单打印数据（按订单自动生成售货单）
     *
     * @return array<string, mixed>
     */
    public function orderShdData(int $aid, int $orderId): array
    {
        $order = $this->findOrder($aid, $orderId);
        $item  = $this->formatOrder($aid, $order);

        $goodsRows = [[
            'name'  => trim($item['proname'] . ($item['ggname'] !== '' ? '（' . $item['ggname'] . '）' : '')),
            'num'   => $item['num'],
            'price' => $item['sell_price'],
            'money' => $item['product_price'],
        ]];

        // 送货单打印样式（系统-送货单设置：标题/每页行数/品名行数）
        $sysset = Db::name('shop_sysset')->where('aid', $aid)->field('shipping_pagetitle,shipping_pagenum,shipping_linenum')->find();

        return [
            'title'             => (string) ($sysset['shipping_pagetitle'] ?? '') !== '' ? (string) $sysset['shipping_pagetitle'] : '送货单',
            'pagenum'           => max(1, (int) ($sysset['shipping_pagenum'] ?? 8)),
            'linenum'           => max(0, (int) ($sysset['shipping_linenum'] ?? 2)),
            'ordernum'          => $item['ordernum'],
            'linkman'           => $item['linkman'],
            'tel'               => $item['tel'],
            'address'           => $item['address'],
            'createtime'        => $item['createtime'],
            'goods'             => $goodsRows,
            'freight_price'     => $item['freight_price'],
            'totalprice'        => $item['totalprice'],
            'total_upper'       => $this->moneyUpper((float) $item['totalprice']),
            'message'           => $item['message'],
        ];
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
        $query = ScopeService::query('lucky_collage_order', $aid);

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

        $titles = ['订单号', '商品名称', '规格数量', '商品总价', '运费', '实付款', '支付方式', '收货人', '电话', '收货地址', '配送方式', '快递信息', '下单时间', '状态', '来源'];

        $data = [];

        foreach ($rows as $r) {
            $v = $this->formatOrder($aid, $r);
            $data[] = [
                $v['ordernum'],
                $v['proname'],
                trim($v['ggname'] . ' × ' . $v['num']),
                $v['product_price'],
                $v['freight_price'],
                $v['totalprice'],
                $v['paytype'],
                $v['linkman'],
                $v['tel'],
                $v['address'],
                $v['freight_text'],
                $v['express_com'] !== '' ? ($v['express_com'] . ' ' . $v['express_no']) : '',
                date('Y-m-d H:i:s', $v['createtime']),
                $v['status_text'] . ($v['refund_money'] > 0 ? '（已退¥' . $v['refund_money'] . '）' : ''),
                $v['platform'],
            ];
        }

        return [$titles, $data];
    }

    /**
     * 退款审核（用户发起退款申请 refund_status=1）
     * pass=1 同意→原路退款+退积分+退券同事务；pass=2 驳回
     */
    public function orderRefundCheck(int $aid, int $orderId, int $pass, string $remark): void
    {
        if (1 !== $pass && 2 !== $pass) {
            throw new BusinessException('非法的审核结果');
        }

        $order = $this->findOrder($aid, $orderId);

        if (1 !== (int) ($order['refund_status'] ?? 0)) {
            throw new BusinessException('该订单无待审核的退款申请');
        }

        if (2 === $pass) {
            ScopeService::query('lucky_collage_order', $aid)->where('id', $orderId)->update([
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
            $locked = Db::name('lucky_collage_order')
                ->where('aid', $aid)
                ->where('id', $orderId)
                ->lock(true)
                ->find();

            if (empty($locked) || 1 !== (int) ($locked['refund_status'] ?? 0)
                || !in_array((int) $locked['status'], [self::ORDER_PAID, self::ORDER_SHIPPED], true)) {
                throw new BusinessException('订单状态已变化，请刷新后重试');
            }

            $refundMoney = (float) ($locked['refund_money'] ?? 0) > 0
                ? (float) $locked['refund_money']
                : (float) $locked['totalprice'];

            $this->refundByRoute($locked, 1, '订单退款');
            $this->refundAssetsInternal($aid, $locked, '订单退款');

            if (self::ORDER_PAID === (int) $locked['status'] && 1 === (int) ($locked['iszj'] ?? 0)) {
                $this->restoreLuckyStock($locked);
            }

            Db::name('lucky_collage_order')->where('id', $orderId)->update([
                'status'             => self::ORDER_CLOSED,
                'refund_status'      => 2,
                'refund_money'       => $refundMoney,
                'refund_time'        => time(),
                'refund_checkremark' => trim($remark),
            ]);
        });
    }

    /**
     * 直接退款（管理员对已支付/已发货的中奖订单发起）
     */
    public function orderRefund(int $aid, int $orderId, string $reason): void
    {
        $order = $this->findOrder($aid, $orderId);

        if (!in_array((int) $order['status'], [self::ORDER_PAID, self::ORDER_SHIPPED], true)) {
            throw new BusinessException('该订单状态不允许退款');
        }

        $reason = trim($reason);

        if ('' === $reason) {
            throw new BusinessException('请填写退款原因');
        }

        Db::transaction(function () use ($aid, $orderId, $reason) {
            $locked = Db::name('lucky_collage_order')
                ->where('aid', $aid)
                ->where('id', $orderId)
                ->lock(true)
                ->find();

            if (empty($locked) || !in_array((int) $locked['status'], [self::ORDER_PAID, self::ORDER_SHIPPED], true)) {
                throw new BusinessException('订单状态已变化，请刷新后重试');
            }

            $refundMoney = (float) $locked['totalprice'];

            $this->refundByRoute($locked, 1, '订单退款');
            $this->refundAssetsInternal($aid, $locked, $reason);

            if (self::ORDER_PAID === (int) $locked['status'] && 1 === (int) ($locked['iszj'] ?? 0)) {
                $this->restoreLuckyStock($locked);
            }

            Db::name('lucky_collage_order')->where('id', $orderId)->update([
                'status'        => self::ORDER_CLOSED,
                'refund_status' => 2,
                'refund_money'  => $refundMoney,
                'refund_reason' => $reason,
                'refund_time'   => time(),
            ]);
        });
    }

    /**
     * 回补库存（商品 + 规格；仅中奖订单退款时）
     *
     * @param array<string, mixed> $order
     */
    protected function restoreLuckyStock(array $order): void
    {
        $num = (int) ($order['num'] ?? 0);

        if ($num <= 0) {
            return;
        }

        if ((int) ($order['proid'] ?? 0) > 0) {
            Db::name('lucky_collage_product')->where('id', (int) $order['proid'])->inc('stock', $num)->update();
        }

        if ((int) ($order['ggid'] ?? 0) > 0) {
            Db::name('lucky_collage_guige')->where('id', (int) $order['ggid'])->inc('stock', $num)->update();
        }
    }

    /** 金额转中文大写（送货单用） */
    protected function moneyUpper(float $money): string
    {
        $money = round($money, 2);

        if ($money <= 0) {
            return '零元整';
        }

        $digits = ['零', '壹', '贰', '叁', '肆', '伍', '陆', '柒', '捌', '玖'];
        $units  = ['分', '角', '元', '拾', '佰', '仟', '万', '拾', '佰', '仟', '亿'];
        $str    = number_format($money, 2, '.', '');
        $parts  = explode('.', $str);
        $zheng  = (int) $parts[0];
        $fen    = (int) ($parts[1] ?? 0);
        $result = '';

        if ($zheng > 0) {
            $zs = (string) $zheng;
            $len = strlen($zs);

            for ($i = 0; $i < $len; ++$i) {
                $n = (int) $zs[$i];
                $u = $units[$len - $i + 1];
                $result .= $digits[$n] . $u;
            }

            $result .= '整';
        }

        if ($fen > 0) {
            $jiao = intdiv($fen, 10);
            $fc   = $fen % 10;

            if ($jiao > 0) {
                $result .= $digits[$jiao] . '角';
            }

            if ($fc > 0) {
                $result .= $digits[$fc] . '分';
            }
        }

        return str_replace(['零拾', '零佰', '零仟', '零万', '零亿'], ['零', '零', '零', '万', '亿'], $result);
    }
}

