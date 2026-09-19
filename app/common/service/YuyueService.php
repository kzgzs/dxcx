<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;

/**
 * 预约服务（P6 行业扩展第二期 · 预约一期）
 *
 * 表复用（旧表补列，不改既有列语义）：
 * - yuyue_category  项目分类
 * - yuyue_product   项目：cid=分类ID(varchar) sell_price=价格 fwlong=时长(分钟)
 *                   yynum=每时段容量上限(默认1) detail=描述 is_delete=软删(补列)
 * - yuyue_worker    技师：realname=姓名 tel=手机 headimg=头像 cid=技师分类 `desc`=简介
 * - yuyue_order     订单：ordernum=订单号 proname=项目快照 proid=项目ID worker_id=技师
 *                   product_price=原价 coupon_money/pay_amount/score_money/use_score/coupon_id
 *                   book_date/book_time(补列) hexiao_code=核销码 `delete`=软删
 *
 * 订单状态机（代码层统一口径）：0待支付 1已支付(待核销) 2已完成 4已取消 5已退款
 *
 * 【时段模型】固定营业时间 09:00-21:00，每小时一档（09:00~20:00 共12档）；
 * 容量上限取项目 yynum（默认1）；被状态 NOT IN(4,5) 的订单占用达上限则不可约；
 * 指定技师时另校验该技师同时段无未取消订单。下单事务内行锁项目防双约。
 *
 * 券/积分叠加：券门槛按项目金额，积分抵扣走既有管道，实付下限 0.01
 * 支付/回调：pay_order 统一支付单（PayService::payBiz/onPaid 联动，order_type=yuyue）
 * 核销：已支付且核销码匹配 → 已完成；核销后不可退款；仅已支付未核销且预约日期未到可退
 */
class YuyueService
{
    /** 订单状态 */
    public const STATUS_UNPAID    = 0;
    public const STATUS_PAID      = 1;
    public const STATUS_FINISHED  = 2;
    public const STATUS_CANCELLED = 4;
    public const STATUS_REFUNDED  = 5;

    public const STATUS_TEXT = [
        self::STATUS_UNPAID    => '待支付',
        self::STATUS_PAID      => '已支付',
        self::STATUS_FINISHED  => '已完成',
        self::STATUS_CANCELLED => '已取消',
        self::STATUS_REFUNDED  => '已退款',
    ];

    /** 固定时段表：09:00-21:00 每小时一档（档位起点） */
    public const SLOT_TIMES = ['09:00', '10:00', '11:00', '12:00', '13:00', '14:00', '15:00', '16:00', '17:00', '18:00', '19:00', '20:00'];

    public function __construct(protected CouponService $couponService, protected ScoreService $scoreService)
    {
    }

    /* ================= admin：项目分类 ================= */

    /**
     * 分类列表（全量，sort 大者靠前）
     *
     * @return array{list: array}
     */
    public function categoryList(int $aid): array
    {
        $rows = ScopeService::query('yuyue_category', $aid)
            ->order('sort', 'desc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        return ['list' => array_map(fn (array $row) => $this->formatCategory($row), $rows)];
    }

    /**
     * 新建分类
     *
     * @param array<string, mixed> $data
     * @return array{id: int}
     */
    public function categoryAdd(int $aid, array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));

        if ('' === $name) {
            throw new BusinessException('请填写分类名称');
        }

        $status = (int) ($data['status'] ?? 1);

        $id = (int) Db::name('yuyue_category')->insertGetId([
            'aid'        => $aid,
            'bid'        => 0,
            'pid'        => 0,
            'name'       => $name,
            'pic'        => trim((string) ($data['pic'] ?? '')),
            'sort'       => (int) ($data['sort'] ?? 1),
            'status'     => in_array($status, [0, 1], true) ? $status : 1,
            'createtime' => time(),
        ]);

        return ['id' => $id];
    }

    /**
     * 编辑分类（空值视为不修改）
     *
     * @param array<string, mixed> $data
     */
    public function categoryEdit(int $aid, array $data): void
    {
        $category = $this->findCategory($aid, (int) ($data['id'] ?? 0));

        $update = [];

        if ($this->hasValue($data, 'name')) {
            $name = trim((string) $data['name']);

            if ('' === $name) {
                throw new BusinessException('请填写分类名称');
            }
            $update['name'] = $name;
        }

        if ($this->hasValue($data, 'pic')) {
            $update['pic'] = trim((string) $data['pic']);
        }

        if ($this->hasValue($data, 'sort')) {
            $update['sort'] = (int) $data['sort'];
        }

        if ($this->hasValue($data, 'status')) {
            if (!in_array((int) $data['status'], [0, 1], true)) {
                throw new BusinessException('状态值无效');
            }
            $update['status'] = (int) $data['status'];
        }

        if (!empty($update)) {
            Db::name('yuyue_category')->where('id', (int) $category['id'])->update($update);
        }
    }

    /**
     * 删除分类（分类下有项目拒绝）
     */
    public function categoryDelete(int $aid, int $id): void
    {
        $category = $this->findCategory($aid, $id);

        $productCount = ScopeService::query('yuyue_product', $aid)
            ->where('cid', (string) $id)
            ->where('is_delete', 0)
            ->count();

        if ($productCount > 0) {
            throw new BusinessException('该分类下有项目，不能删除');
        }

        Db::name('yuyue_category')->where('id', (int) $category['id'])->delete();
    }

    /* ================= admin：服务商品（服务商品 YuyueProduct） ================= */

    /**
     * 服务商品列表（name 匹配服务名称；status/bid/checked 筛选；orderField/orderType 白名单排序）
     * 行扩充 cname（分类名）、bname（所属商户名，bid=0=平台自营）、审核字段（ischecked/check_reason）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function productList(int $aid, string $name, string $status, string $bid, string $checked, string $orderField, string $orderType, int $page, int $pageSize): array
    {
        $query = ScopeService::query('yuyue_product', $aid)->where('is_delete', 0);

        // 商户维度过滤（任务177 showtype-2：''/all 不过滤，0 自营，gt0 商户）
        ScopeService::applyBid($query, $bid, 'bid');

        if ('' !== $name) {
            $query->where('name', 'like', '%' . $name . '%');
        }
        if ('' !== $status) {
            $query->where('status', (int) $status);
        }
        if ('' !== $checked) {
            $query->where('ischecked', (int) $checked);
        }

        // 排序白名单（id/sales/sort/createtime）
        $orderMap = ['id' => 'id', 'sales' => 'sales', 'sort' => 'sort', 'createtime' => 'createtime'];
        $sortField = $orderMap[$orderField] ?? 'sort';
        $sortDir   = 'asc' === strtolower($orderType) ? 'asc' : 'desc';

        $total = (clone $query)->count();

        $rows = $query->order($sortField, $sortDir)
            ->order('id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        // 分类名批量取（cid 为逗号串，可多分类）
        $catNames = Db::name('yuyue_category')->where('aid', $aid)->column('name', 'id');

        // 所属商户名批量取（bid=0 显示平台自营）
        $bidIds = array_values(array_unique(array_filter(array_map(static fn ($v): int => (int) $v, array_column($rows, 'bid')))));
        $bizNames = [];
        if (!empty($bidIds)) {
            $bizNames = Db::name('business')->where('aid', $aid)->whereIn('id', $bidIds)->column('name', 'id');
        }

        foreach ($rows as $k => $v) {
            $cids = array_filter(explode(',', (string) ($v['cid'] ?? '')));
            $cnameParts = [];
            foreach ($cids as $cid) {
                if (isset($catNames[$cid])) {
                    $cnameParts[] = (string) $catNames[$cid];
                }
            }
            $v['cname'] = implode(' ', $cnameParts);
            $bidVal = (int) ($v['bid'] ?? 0);
            $v['bname'] = $bidVal > 0 ? (string) ($bizNames[$bidVal] ?? '') : '平台自营';
            $rows[$k] = $v;
        }

        return [
            'list'     => array_map(fn (array $row) => $this->formatAdminProduct($row), $rows),
            'total'    => $total,
            'page'     => $page,
            'pagesize' => $pageSize,
        ];
    }

    /**
     * 服务商品详情（编辑表单回显；pics 拆数组）
     *
     * @return array<string, mixed>
     */
    public function productDetail(int $aid, int $id): array
    {
        $product = ScopeService::query('yuyue_product', $aid)
            ->where('id', $id)
            ->where('is_delete', 0)
            ->find();

        if (empty($product)) {
            throw new BusinessException('服务商品不存在', 404, 404);
        }

        $product = (array) $product;
        $product['pics'] = '' !== (string) ($product['pics'] ?? '') ? explode(',', (string) $product['pics']) : [];

        return $product;
    }

    /**
     * 新建服务商品 {name,cid,pic,pics,sellpoint,sell_price,sales,sort,status,detail}
     *
     * @param array<string, mixed> $data
     * @return array{id: int}
     */
    public function productAdd(int $aid, array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));

        if ('' === $name) {
            throw new BusinessException('请填写服务名称');
        }

        $price = $this->parsePrice($data['sell_price'] ?? null, '价格');

        if ($price <= 0) {
            throw new BusinessException('请填写价格（大于0）');
        }

        $this->assertCategory($aid, (int) ($data['cid'] ?? 0));

        $status = (int) ($data['status'] ?? 1);

        $id = (int) Db::name('yuyue_product')->insertGetId([
            'aid'        => $aid,
            'bid'        => 0,
            'name'       => $name,
            'cid'        => (string) ($data['cid'] ?? ''),
            'pic'        => trim((string) ($data['pic'] ?? '')),
            'pics'       => is_array($data['pics'] ?? null) ? implode(',', $data['pics']) : trim((string) ($data['pics'] ?? '')),
            'sellpoint'  => trim((string) ($data['sellpoint'] ?? '')),
            'sell_price' => $price,
            'sales'      => (int) ($data['sales'] ?? 0),
            'sort'       => (int) ($data['sort'] ?? 0),
            'status'     => in_array($status, [0, 1], true) ? $status : 1,
            'detail'     => (string) ($data['detail'] ?? ''),
            'createtime' => time(),
            'is_delete'  => 0,
            'ischecked'  => 1,
        ]);

        return ['id' => $id];
    }

    /**
     * 编辑服务商品（空值视为不修改）
     *
     * @param array<string, mixed> $data
     */
    public function productEdit(int $aid, array $data): void
    {
        $product = $this->findProduct($aid, (int) ($data['id'] ?? 0));

        $update = [];

        if ($this->hasValue($data, 'name')) {
            $name = trim((string) $data['name']);

            if ('' === $name) {
                throw new BusinessException('请填写服务名称');
            }
            $update['name'] = $name;
        }

        if (array_key_exists('cid', $data)) {
            $this->assertCategory($aid, (int) $data['cid']);
            $update['cid'] = (string) $data['cid'];
        }

        if ($this->hasValue($data, 'pic')) {
            $update['pic'] = trim((string) $data['pic']);
        }

        if (array_key_exists('pics', $data)) {
            $update['pics'] = is_array($data['pics']) ? implode(',', $data['pics']) : trim((string) $data['pics']);
        }

        if ($this->hasValue($data, 'sellpoint')) {
            $update['sellpoint'] = trim((string) $data['sellpoint']);
        }

        if ($this->hasValue($data, 'sell_price')) {
            $price = $this->parsePrice($data['sell_price'], '价格');

            if ($price <= 0) {
                throw new BusinessException('价格须大于0');
            }
            $update['sell_price'] = $price;
        }

        if ($this->hasValue($data, 'sales')) {
            $update['sales'] = (int) $data['sales'];
        }

        if ($this->hasValue($data, 'sort')) {
            $update['sort'] = (int) $data['sort'];
        }

        if (array_key_exists('detail', $data)) {
            $update['detail'] = (string) $data['detail'];
        }

        if ($this->hasValue($data, 'status')) {
            if (!in_array((int) $data['status'], [0, 1], true)) {
                throw new BusinessException('状态值无效');
            }
            $update['status'] = (int) $data['status'];
        }

        if (!empty($update)) {
            Db::name('yuyue_product')->where('id', (int) $product['id'])->update($update);
        }
    }

    /**
     * 删除服务商品（批量；逐条保留未完结订单拦截）
     *
     * @param int[] $ids
     */
    public function productDelete(int $aid, array $ids): void
    {
        foreach ($ids as $id) {
            $product = $this->findProduct($aid, $id);

            $openCount = Db::name('yuyue_order')
                ->where('proid', $id)
                ->whereIn('status', [self::STATUS_UNPAID, self::STATUS_PAID])
                ->count();

            if ($openCount > 0) {
                throw new BusinessException('该服务有未完结订单，不能删除');
            }

            Db::name('yuyue_product')->where('id', (int) $product['id'])->update(['is_delete' => 1]);
        }
    }

    /**
     * 上架/下架（批量；逐条做归属校验）
     *
     * @param int[] $ids
     */
    public function productStatus(int $aid, array $ids, int $status): void
    {
        if (!in_array($status, [0, 1], true)) {
            throw new BusinessException('状态值无效');
        }

        foreach ($ids as $id) {
            $product = $this->findProduct($aid, $id);
            Db::name('yuyue_product')->where('id', (int) $product['id'])->update(['status' => $status]);
        }
    }

    /**
     * 服务商品审核（1通过 / 2驳回，驳回必填原因；对齐 CollageService::productCheck 范式）
     */
    public function productCheck(int $aid, int $id, int $ischecked, string $reason): void
    {
        $this->findProduct($aid, $id);

        if (!in_array($ischecked, [1, 2], true)) {
            throw new BusinessException('非法的审核结果');
        }

        if (2 === $ischecked && '' === trim($reason)) {
            throw new BusinessException('请填写驳回原因');
        }

        ScopeService::query('yuyue_product', $aid)->where('id', $id)->update([
            'ischecked'    => $ischecked,
            'check_reason' => 2 === $ischecked ? trim($reason) : '',
        ]);
    }

    /**
     * 复制服务商品（复制 yuyue_product 行；名称加「复制-」前缀，下架、清零销量、置已通过）
     *
     * @return int 新商品ID
     */
    public function procopy(int $aid, int $id): int
    {
        $product = ScopeService::query('yuyue_product', $aid)
            ->where('id', $id)
            ->where('is_delete', 0)
            ->find();

        if (empty($product)) {
            throw new BusinessException('服务商品不存在', 404, 404);
        }

        $data = (array) $product;
        unset($data['id']);
        $data['name']      = '复制-' . (string) $product['name'];
        $data['status']    = 0;
        $data['sales']     = 0;
        $data['ischecked'] = 1;

        return (int) Db::name('yuyue_product')->insertGetId($data);
    }

    /**
     * 分类校验（cid>0 时校验存在；0/空 视为不绑定分类，不抛错）
     */
    protected function assertCategory(int $aid, int $cid): void
    {
        if ($cid <= 0) {
            return;
        }

        $this->findCategory($aid, $cid);
    }

    /**
     * 服务商品列表行格式化（契约键：cname/bname/sell_price/sales/sort/ischecked/check_reason）
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function formatAdminProduct(array $row): array
    {
        return [
            'id'          => (int) ($row['id'] ?? 0),
            'name'        => (string) ($row['name'] ?? ''),
            'pic'         => (string) ($row['pic'] ?? ''),
            'pics'        => '' !== (string) ($row['pics'] ?? '') ? explode(',', (string) $row['pics']) : [],
            'cid'         => (string) ($row['cid'] ?? ''),
            'cname'       => (string) ($row['cname'] ?? ''),
            'bname'       => (string) ($row['bname'] ?? '平台自营'),
            'bid'         => (int) ($row['bid'] ?? 0),
            'sellpoint'   => (string) ($row['sellpoint'] ?? ''),
            'sell_price'  => (float) ($row['sell_price'] ?? 0),
            'sales'       => (int) ($row['sales'] ?? 0),
            'sort'        => (int) ($row['sort'] ?? 0),
            'status'      => (int) ($row['status'] ?? 1),
            'ischecked'   => (int) ($row['ischecked'] ?? 0),
            'check_reason'=> (string) ($row['check_reason'] ?? ''),
            'create_time' => !empty($row['createtime']) ? date('Y-m-d H:i', (int) $row['createtime']) : '',
        ];
    }


    /* ================= admin：技师管理 ================= */

    /**
     * 技师列表（keyword 匹配姓名/手机号；status 筛选）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function workerList(int $aid, string $keyword, string $status, int $page, int $pageSize): array
    {
        $query = ScopeService::query('yuyue_worker', $aid);

        if ('' !== $keyword) {
            $like = '%' . $keyword . '%';
            $query->where(function ($q) use ($like) {
                $q->where('realname', 'like', $like)
                    ->whereOr('tel', 'like', $like);
            });
        }
        if ('' !== $status) {
            $query->where('status', (int) $status);
        }

        $total = (clone $query)->count();

        $rows = $query->order('id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        return [
            'list'     => array_map(fn (array $row) => $this->formatWorker($row), $rows),
            'total'    => $total,
            'page'     => $page,
            'pagesize' => $pageSize,
        ];
    }

    /**
     * 新建技师 {name,pic,phone,category_id(可无),desc,status}
     *
     * @param array<string, mixed> $data
     * @return array{id: int}
     */
    public function workerAdd(int $aid, array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));

        if ('' === $name) {
            throw new BusinessException('请填写技师姓名');
        }

        $status = (int) ($data['status'] ?? 1);

        $id = (int) Db::name('yuyue_worker')->insertGetId([
            'aid'        => $aid,
            'bid'        => 0,
            'realname'   => $name,
            'headimg'    => trim((string) ($data['pic'] ?? '')),
            'tel'        => trim((string) ($data['phone'] ?? '')),
            'cid'        => (int) ($data['category_id'] ?? 0),
            'desc'       => trim((string) ($data['desc'] ?? '')),
            'status'     => in_array($status, [0, 1], true) ? $status : 1,
            'sort'       => 0,
            'createtime' => time(),
        ]);

        return ['id' => $id];
    }

    /**
     * 编辑技师（空值视为不修改）
     *
     * @param array<string, mixed> $data
     */
    public function workerEdit(int $aid, array $data): void
    {
        $worker = $this->findWorker($aid, (int) ($data['id'] ?? 0));

        $update = [];

        if ($this->hasValue($data, 'name')) {
            $name = trim((string) $data['name']);

            if ('' === $name) {
                throw new BusinessException('请填写技师姓名');
            }
            $update['realname'] = $name;
        }

        if ($this->hasValue($data, 'pic')) {
            $update['headimg'] = trim((string) $data['pic']);
        }

        if ($this->hasValue($data, 'phone')) {
            $update['tel'] = trim((string) $data['phone']);
        }

        if ($this->hasValue($data, 'category_id')) {
            $update['cid'] = (int) $data['category_id'];
        }

        if ($this->hasValue($data, 'desc')) {
            $update['desc'] = trim((string) $data['desc']);
        }

        if ($this->hasValue($data, 'status')) {
            if (!in_array((int) $data['status'], [0, 1], true)) {
                throw new BusinessException('状态值无效');
            }
            $update['status'] = (int) $data['status'];
        }

        if (!empty($update)) {
            Db::name('yuyue_worker')->where('id', (int) $worker['id'])->update($update);
        }
    }

    /**
     * 删除技师（有未完结订单拒绝）
     */
    public function workerDelete(int $aid, int $id): void
    {
        $worker = $this->findWorker($aid, $id);

        $openCount = Db::name('yuyue_order')
            ->where('worker_id', $id)
            ->whereIn('status', [self::STATUS_UNPAID, self::STATUS_PAID])
            ->count();

        if ($openCount > 0) {
            throw new BusinessException('该技师有未完结订单，不能删除');
        }

        Db::name('yuyue_worker')->where('id', (int) $worker['id'])->delete();
    }

    /**
     * 启用/停用
     */
    public function workerStatus(int $aid, int $id, int $status): void
    {
        if (!in_array($status, [0, 1], true)) {
            throw new BusinessException('状态值无效');
        }

        $worker = $this->findWorker($aid, $id);

        Db::name('yuyue_worker')->where('id', (int) $worker['id'])->update(['status' => $status]);
    }

    /* ================= admin：预约订单 ================= */

    /**
     * 订单列表（含会员手机/技师姓名；keyword 匹配订单号/项目名/手机号）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function adminOrderList(int $aid, string $status, string $keyword, int $page, int $pageSize): array
    {
        $query = ScopeService::queryAs('yuyue_order', $aid, 'o')
            ->leftJoin('member m', 'm.id = o.mid')
            ->leftJoin('yuyue_worker w', 'w.id = o.worker_id')
            ->field('o.*, m.tel as member_phone, w.realname as worker_name')
            ->where('o.delete', 0);

        if ('' !== $status) {
            $query->where('o.status', (int) $status);
        }

        if ('' !== $keyword) {
            $like = '%' . $keyword . '%';
            $query->where(function ($q) use ($like) {
                $q->where('o.ordernum', 'like', $like)
                    ->whereOr('o.proname', 'like', $like)
                    ->whereOr('m.tel', 'like', $like);
            });
        }

        $total = (clone $query)->count();

        $rows = $query->order('o.id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        return [
            'list'     => array_map(fn (array $row) => $this->formatOrder($row), $rows),
            'total'    => $total,
            'page'     => $page,
            'pagesize' => $pageSize,
        ];
    }

    /**
     * 核销（已支付且核销码匹配 → 已完成）
     */
    public function adminOrderVerify(int $aid, int $id, string $hexiaoCode): void
    {
        $hexiaoCode = trim($hexiaoCode);

        if ('' === $hexiaoCode) {
            throw new BusinessException('请输入核销码');
        }

        $order = $this->findOrder($aid, 0, $id);

        if (self::STATUS_PAID !== (int) $order['status']) {
            throw new BusinessException('仅已支付订单可核销');
        }

        if (!hash_equals((string) ($order['hexiao_code'] ?? ''), $hexiaoCode)) {
            throw new BusinessException('核销码不正确');
        }

        Db::name('yuyue_order')
            ->where('id', $id)
            ->where('status', self::STATUS_PAID)
            ->update(['status' => self::STATUS_FINISHED]);
    }

    /* ================= api 会员侧 ================= */

    /**
     * 项目列表（匿名；分类筛选，仅上架）
     *
     * @return array{list: array}
     */
    public function apiProductList(int $aid, int $categoryId): array
    {
        $query = ScopeService::query('yuyue_product', $aid)
            ->where('status', 1)
            ->where('is_delete', 0);

        if ($categoryId > 0) {
            $query->where('cid', (string) $categoryId);
        }

        $rows = $query->order('sort', 'desc')->order('id', 'desc')->select()->toArray();

        return ['list' => array_map(fn (array $row) => $this->formatProduct($row), $rows)];
    }

    /**
     * 项目详情（匿名）
     *
     * @return array<string, mixed>
     */
    public function apiProductDetail(int $aid, int $id): array
    {
        $product = ScopeService::query('yuyue_product', $aid)
            ->where('id', $id)
            ->where('status', 1)
            ->where('is_delete', 0)
            ->find();

        if (empty($product)) {
            throw new BusinessException('项目不存在或已下架', 404, 404);
        }

        return $this->formatProduct($product);
    }

    /**
     * 技师列表（匿名，仅启用）
     *
     * @return array{list: array}
     */
    public function apiWorkerList(int $aid): array
    {
        $rows = ScopeService::query('yuyue_worker', $aid)
            ->where('status', 1)
            ->order('sort', 'desc')
            ->order('id', 'desc')
            ->select()
            ->toArray();

        return ['list' => array_map(fn (array $row) => $this->formatWorker($row), $rows)];
    }

    /**
     * 时段可约查询（匿名）
     *
     * 固定 09:00-21:00 每小时一档；被状态 NOT IN(4,5) 订单占用达容量（项目 yynum，默认1）
     * 则 available=0；指定技师时按技师维度查占用；当天已过时段不可约
     *
     * @return array{slots: array}
     */
    public function apiSlot(int $aid, int $productId, int $workerId, string $date): array
    {
        $product = ScopeService::query('yuyue_product', $aid)
            ->where('id', $productId)
            ->where('status', 1)
            ->where('is_delete', 0)
            ->find();

        if (empty($product)) {
            throw new BusinessException('项目不存在或已下架', 404, 404);
        }

        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || false === strtotime($date)) {
            throw new BusinessException('日期格式无效（Y-m-d）');
        }

        if ($workerId > 0) {
            $this->findWorker($aid, $workerId);
        }

        // 技师维度：每时段仅 1 单（与 createOrder 的技师占用校验一致）；否则取项目容量 yynum
        $capacity = $workerId > 0 ? 1 : max(1, (int) ($product['yynum'] ?? 1));
        $now      = time();

        // 当日该项目的占用统计（按 book_time 分组；指定技师时按技师维度）
        $occQuery = Db::name('yuyue_order')
            ->where('aid', $aid)
            ->where('proid', $productId)
            ->where('book_date', $date)
            ->whereNotIn('status', [self::STATUS_CANCELLED, self::STATUS_REFUNDED])
            ->field('book_time, count(*) as cnt')
            ->group('book_time');

        if ($workerId > 0) {
            $occQuery->where('worker_id', $workerId);
        }

        $occMap = [];

        foreach ($occQuery->select()->toArray() as $row) {
            $occMap[(string) $row['book_time']] = (int) $row['cnt'];
        }

        $slots = [];

        foreach (self::SLOT_TIMES as $time) {
            $occupied  = $occMap[$time] ?? 0;
            $available = ($occupied < $capacity && strtotime($date . ' ' . $time) > $now) ? 1 : 0;

            $slots[] = ['time' => $time, 'available' => $available];
        }

        return ['slots' => $slots];
    }

    /**
     * 创建预约订单
     *
     * 【并发】事务内行锁项目 + 复检时段占用 < 容量；指定技师另查技师同时段占用
     * 【叠加】券（门槛按项目金额）→ 积分抵扣 → 实付下限 0.01
     *
     * @return array{id: int, order_sn: string, amount: float, coupon_money: float, score_money: float, pay_amount: float, use_score: int, coupon_id: int, hexiao_code: string, book_date: string, book_time: string}
     */
    public function createOrder(int $aid, int $mid, int $productId, int $workerId, string $bookDate, string $bookTime, int $couponId = 0, int $useScore = 0): array
    {
        if ($productId <= 0) {
            throw new BusinessException('请选择预约项目');
        }

        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $bookDate) || false === strtotime($bookDate)) {
            throw new BusinessException('预约日期格式无效（Y-m-d）');
        }

        if (strtotime($bookDate) < strtotime(date('Y-m-d'))) {
            throw new BusinessException('预约日期不能早于今天');
        }

        if (!in_array($bookTime, self::SLOT_TIMES, true)) {
            throw new BusinessException('预约时段无效');
        }

        if (strtotime($bookDate . ' ' . $bookTime) <= time()) {
            throw new BusinessException('该时段已过，请选择其他时段');
        }

        $product = ScopeService::query('yuyue_product', $aid)
            ->where('id', $productId)
            ->where('status', 1)
            ->where('is_delete', 0)
            ->find();

        if (empty($product)) {
            throw new BusinessException('项目不存在或已下架', 404, 404);
        }

        if ($workerId > 0) {
            $this->findWorker($aid, $workerId);
        }

        // ===== 计价 =====
        $amount = round((float) $product['sell_price'], 2);

        if ($amount <= 0) {
            throw new BusinessException('项目价格未配置');
        }

        // ===== 券（门槛按项目金额）与积分（既有管道） =====
        $couponPick  = null;
        $couponMoney = 0.0;

        if ($couponId > 0) {
            $couponPick  = $this->couponService->resolveForOrder($aid, $mid, $couponId, $amount);
            $couponMoney = (float) $couponPick['coupon_money'];
        }

        $afterCoupon = max(round($amount - $couponMoney, 2), 0.0);

        $scoreMoney = 0.0;

        if ($useScore > 0) {
            if (!ScoreService::switchOn($aid)) {
                throw new BusinessException('积分抵扣未开启');
            }

            $ratio = ScoreService::ratioOf($aid);

            if ($ratio < 1) {
                throw new BusinessException('积分抵扣比例未配置');
            }

            $scoreMoney = floor($useScore / $ratio * 100) / 100;
            $maxDeduct  = round($afterCoupon - 0.01, 2);

            if ($scoreMoney > $maxDeduct) {
                $scoreMoney = max($maxDeduct, 0.0);
            }

            if ($scoreMoney <= 0) {
                throw new BusinessException('应付金额不足，无法使用积分抵扣');
            }
        }

        $payAmount = max(round($afterCoupon - $scoreMoney, 2), 0.01);
        $now       = time();

        return Db::transaction(function () use ($aid, $mid, $productId, $workerId, $bookDate, $bookTime, $product, $amount, $couponPick, $couponMoney, $useScore, $scoreMoney, $payAmount, $now) {
            // 时段并发校验：行锁项目 + 复检该时段未取消/未退款订单数 < 容量（防双约）
            Db::name('yuyue_product')->where('id', $productId)->lock(true)->find();

            $capacity = max(1, (int) ($product['yynum'] ?? 1));

            $occupied = Db::name('yuyue_order')
                ->where('aid', $aid)
                ->where('proid', $productId)
                ->where('book_date', $bookDate)
                ->where('book_time', $bookTime)
                ->whereNotIn('status', [self::STATUS_CANCELLED, self::STATUS_REFUNDED])
                ->count();

            if ($occupied >= $capacity) {
                throw new BusinessException('该时段已被预约，请选择其他时段');
            }

            // 指定技师：该技师同时段不可有未取消订单
            if ($workerId > 0) {
                $workerBusy = Db::name('yuyue_order')
                    ->where('aid', $aid)
                    ->where('worker_id', $workerId)
                    ->where('book_date', $bookDate)
                    ->where('book_time', $bookTime)
                    ->whereNotIn('status', [self::STATUS_CANCELLED, self::STATUS_REFUNDED])
                    ->count();

                if ($workerBusy > 0) {
                    throw new BusinessException('该技师此时段已有预约');
                }
            }

            $hexiaoCode = (string) random_int(1000000000, 2147483647);

            $orderId = (int) Db::name('yuyue_order')->insertGetId([
                'aid'           => $aid,
                'bid'           => 0,
                'mid'           => $mid,
                'ordernum'      => '',
                'title'         => (string) ($product['name'] ?? ''),
                'proname'       => (string) ($product['name'] ?? ''),
                'proid'         => $productId,
                'worker_id'     => $workerId,
                'book_date'     => $bookDate,
                'book_time'     => $bookTime,
                'yy_time'       => $bookDate . ' ' . $bookTime,
                'totalprice'    => $payAmount,
                'product_price' => $amount,
                'coupon_money'  => $couponMoney,
                'pay_amount'    => $payAmount,
                'score_money'   => $scoreMoney,
                'use_score'     => $useScore,
                'coupon_id'     => null !== $couponPick ? (int) $couponPick['coupon_id'] : 0,
                'coupon_rid'    => null !== $couponPick ? (int) $couponPick['record_id'] : 0,
                'hexiao_code'   => $hexiaoCode,
                'status'        => self::STATUS_UNPAID,
                'paytime'       => 0,
                'num'           => 1,
                'fwtype'        => 1,
                'delete'        => 0,
                'createtime'    => $now,
            ]);

            $orderSn = 'YY' . date('YmdHis', $now)
                . str_pad((string) ($aid % 10000), 4, '0', STR_PAD_LEFT)
                . str_pad((string) ($orderId % 1000000), 6, '0', STR_PAD_LEFT);

            Db::name('yuyue_order')->where('id', $orderId)->update(['ordernum' => $orderSn]);

            // 用券预锁定（行锁抢占，失败回滚整单）
            if (null !== $couponPick) {
                $this->couponService->bindToOrder((int) $couponPick['record_id'], $orderId, 'yuyue');
            }

            // 积分预扣（取消/退款场景按既有规则处理）
            if ($useScore > 0) {
                $this->scoreService->deductForOrder($aid, $mid, $useScore, '下单抵扣 预约订单' . $orderSn);
            }

            return [
                'id'           => $orderId,
                'order_sn'     => $orderSn,
                'book_date'    => $bookDate,
                'book_time'    => $bookTime,
                'amount'       => $amount,
                'coupon_id'    => null !== $couponPick ? (int) $couponPick['coupon_id'] : 0,
                'coupon_money' => $couponMoney,
                'use_score'    => $useScore,
                'score_money'  => $scoreMoney,
                'pay_amount'   => $payAmount,
                'hexiao_code'  => $hexiaoCode,
            ];
        });
    }

    /**
     * 我的预约订单列表
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function apiOrderList(int $aid, int $mid, string $status, int $page, int $pageSize): array
    {
        $query = ScopeService::queryAs('yuyue_order', $aid, 'o')
            ->leftJoin('yuyue_worker w', 'w.id = o.worker_id')
            ->field('o.*, w.realname as worker_name')
            ->where('o.mid', $mid)
            ->where('o.delete', 0);

        if ('' !== $status) {
            $query->where('o.status', (int) $status);
        }

        $total = (clone $query)->count();

        $rows = $query->order('o.id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        return [
            'list'     => array_map(fn (array $row) => $this->formatOrder($row), $rows),
            'total'    => $total,
            'page'     => $page,
            'pagesize' => $pageSize,
        ];
    }

    /**
     * 申请退款（仅已支付未核销且预约日期未到；核销成功不退；券/积分不回收）
     *
     * @return array{mock: bool, success: bool}
     */
    public function refundOrder(int $aid, int $mid, int $orderId): array
    {
        $order = $this->findOrder($aid, $mid, $orderId);

        if (self::STATUS_PAID !== (int) $order['status']) {
            throw new BusinessException('仅已支付未核销订单可退款');
        }

        $bookDate = (string) ($order['book_date'] ?? '');

        if ('' === $bookDate || strtotime($bookDate) <= strtotime(date('Y-m-d'))) {
            throw new BusinessException('预约日期已到，不可退款');
        }

        $result = PayService::refundBiz(
            'yuyue',
            $orderId,
            (float) $order['pay_amount'],
            'RF' . date('YmdHis') . str_pad((string) ($orderId % 1000000), 6, '0', STR_PAD_LEFT)
        );

        Db::name('yuyue_order')
            ->where('id', $orderId)
            ->where('status', self::STATUS_PAID)
            ->update([
                'status'       => self::STATUS_REFUNDED,
                'refund_money' => (float) $order['pay_amount'],
                'refund_time'  => time(),
            ]);

        return $result;
    }

    /**
     * 支付成功入账（PayService 回调路由调用，幂等：仅 status=0 时生效）
     */
    public function onPaid(int $orderId, string $payType, string $payNum): bool
    {
        return (bool) Db::transaction(function () use ($orderId, $payType, $payNum) {
            $order = Db::name('yuyue_order')->where('id', $orderId)->lock(true)->find();

            if (empty($order) || self::STATUS_UNPAID !== (int) $order['status']) {
                return false; // 已支付/已取消等：幂等不重复处理
            }

            Db::name('yuyue_order')
                ->where('id', $orderId)
                ->where('status', self::STATUS_UNPAID)
                ->update([
                    'status'    => self::STATUS_PAID,
                    'paytime'   => time(),
                    'paytype'   => $payType,
                    'paytypeid' => PayService::PAY_TYPE_IDS[$payType] ?? 0,
                    'paynum'    => $payNum,
                ]);

            // 券核销（幂等）
            $this->couponService->consumeByOrder((int) ($order['coupon_rid'] ?? 0), $orderId);

            // 积分赠送：pay_score × floor(实付)
            $this->scoreService->grantForPay(
                (int) ($order['aid'] ?? 0),
                (int) ($order['mid'] ?? 0),
                (float) ($order['pay_amount'] ?? 0),
                '支付赠送 预约订单' . (string) ($order['ordernum'] ?? '')
            );

            return true;
        });
    }

    /**
     * 已支付超时未接单自动退款（P8 定时任务，对标参考系统 ApiAuto 预约超时退款口径）
     *
     * 开关/阈值：env YUYUE.ACCEPT_TIMEOUT 分钟（默认 60，0=关闭）
     * 判定：status=1（已支付未核销）且 paytime + 阈值 < now
     * 口径：退实付全额，置已退款（status=5，refund_status=2），券/积分不回收（同手动退款规则）
     * 幂等：status=1 条件更新，重复执行不会重复退款
     *
     * @return int 本次退款的订单数
     */
    public function refundAcceptTimeout(): int
    {
        $minutes = (int) env('YUYUE.ACCEPT_TIMEOUT', '60');

        if ($minutes <= 0) {
            return 0; // 开关关闭
        }

        $rows = Db::name('yuyue_order')
            ->where('status', self::STATUS_PAID)
            ->where('paytime', '>', 0)
            ->where('paytime', '<', time() - $minutes * 60)
            ->order('id', 'asc')
            ->limit(500)
            ->select()
            ->toArray();

        $count = 0;

        foreach ($rows as $order) {
            $orderId = (int) ($order['id'] ?? 0);

            if ($orderId <= 0) {
                continue;
            }

            PayService::refundBiz(
                'yuyue',
                $orderId,
                (float) ($order['pay_amount'] ?? $order['totalprice'] ?? 0),
                'RF' . date('YmdHis') . str_pad((string) ($orderId % 1000000), 6, '0', STR_PAD_LEFT)
            );

            $affected = Db::name('yuyue_order')
                ->where('id', $orderId)
                ->where('status', self::STATUS_PAID)
                ->update([
                    'status'        => self::STATUS_REFUNDED,
                    'refund_status' => 2,
                    'refund_money'  => (float) ($order['pay_amount'] ?? $order['totalprice'] ?? 0),
                    'refund_reason' => '超时未接单退款',
                    'refund_time'   => time(),
                ]);

            if (1 === $affected) {
                ++$count;
            }
        }

        return $count;
    }

    /* ================= 内部工具 ================= */

    /**
     * 字段是否有非空值（edit 空值视为不修改）
     *
     * @param array<string, mixed> $data
     */
    protected function hasValue(array $data, string $key): bool
    {
        return array_key_exists($key, $data) && null !== $data[$key] && '' !== trim((string) $data[$key]);
    }

    /**
     * 价格解析（数值、非负）
     */
    protected function parsePrice(mixed $value, string $label): float
    {
        if (null === $value || '' === $value) {
            return 0.0;
        }

        if (!is_numeric($value) || (float) $value < 0) {
            throw new BusinessException($label . '格式无效（须为非负数字）');
        }

        return round((float) $value, 2);
    }

    /**
     * 查找本租户分类（不存在抛 404）
     *
     * @return array<string, mixed>
     */
    protected function findCategory(int $aid, int $id): array
    {
        if ($id <= 0) {
            throw new BusinessException('缺少分类ID');
        }

        $category = ScopeService::query('yuyue_category', $aid)
            ->where('id', $id)
            ->find();

        if (empty($category)) {
            throw new BusinessException('分类不存在', 404, 404);
        }

        return $category;
    }

    /**
     * 查找本租户未删除的项目（不存在抛 404）
     *
     * @return array<string, mixed>
     */
    protected function findProduct(int $aid, int $id): array
    {
        if ($id <= 0) {
            throw new BusinessException('缺少项目ID');
        }

        $product = ScopeService::query('yuyue_product', $aid)
            ->where('id', $id)
            ->where('is_delete', 0)
            ->find();

        if (empty($product)) {
            throw new BusinessException('项目不存在', 404, 404);
        }

        return $product;
    }

    /**
     * 查找本租户技师（不存在抛 404）
     *
     * @return array<string, mixed>
     */
    protected function findWorker(int $aid, int $id): array
    {
        if ($id <= 0) {
            throw new BusinessException('缺少技师ID');
        }

        $worker = ScopeService::query('yuyue_worker', $aid)
            ->where('id', $id)
            ->find();

        if (empty($worker)) {
            throw new BusinessException('技师不存在', 404, 404);
        }

        return $worker;
    }

    /**
     * 查找订单（mid>0 限本人；不存在抛 404）
     *
     * @return array<string, mixed>
     */
    protected function findOrder(int $aid, int $mid, int $orderId): array
    {
        if ($orderId <= 0) {
            throw new BusinessException('缺少订单ID');
        }

        $query = ScopeService::query('yuyue_order', $aid)
            ->where('id', $orderId)
            ->where('delete', 0);

        if ($mid > 0) {
            $query->where('mid', $mid);
        }

        $order = $query->find();

        if (empty($order)) {
            throw new BusinessException('订单不存在', 404, 404);
        }

        return $order;
    }

    /**
     * 分类行格式化
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function formatCategory(array $row): array
    {
        return [
            'id'          => (int) ($row['id'] ?? 0),
            'name'        => (string) ($row['name'] ?? ''),
            'pic'         => (string) ($row['pic'] ?? ''),
            'sort'        => (int) ($row['sort'] ?? 1),
            'status'      => (int) ($row['status'] ?? 1),
            'create_time' => (int) ($row['createtime'] ?? 0),
        ];
    }

    /**
     * 项目行格式化（契约键：price←sell_price desc←detail duration←fwlong category_id←cid）
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function formatProduct(array $row): array
    {
        return [
            'id'          => (int) ($row['id'] ?? 0),
            'category_id' => (int) ($row['cid'] ?? 0),
            'name'        => (string) ($row['name'] ?? ''),
            'pic'         => (string) ($row['pic'] ?? ''),
            'price'       => (float) ($row['sell_price'] ?? 0),
            'desc'        => (string) ($row['detail'] ?? ''),
            'duration'    => (int) ($row['fwlong'] ?? 0),
            'status'      => (int) ($row['status'] ?? 1),
            'create_time' => (int) ($row['createtime'] ?? 0),
        ];
    }

    /**
     * 技师行格式化（契约键：name←realname pic←headimg phone←tel category_id←cid）
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function formatWorker(array $row): array
    {
        return [
            'id'          => (int) ($row['id'] ?? 0),
            'name'        => (string) ($row['realname'] ?? ''),
            'pic'         => (string) ($row['headimg'] ?? ''),
            'phone'       => (string) ($row['tel'] ?? ''),
            'category_id' => (int) ($row['cid'] ?? 0),
            'desc'        => (string) ($row['desc'] ?? ''),
            'status'      => (int) ($row['status'] ?? 1),
            'create_time' => (int) ($row['createtime'] ?? 0),
        ];
    }

    /**
     * 订单行格式化（order_sn←ordernum product_name←proname pay_amount←补列）
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function formatOrder(array $row): array
    {
        $status = (int) ($row['status'] ?? 0);

        return [
            'id'           => (int) ($row['id'] ?? 0),
            'order_sn'     => (string) ($row['ordernum'] ?? ''),
            'mid'          => (int) ($row['mid'] ?? 0),
            'member_phone' => (string) ($row['member_phone'] ?? ''),
            'product_id'   => (int) ($row['proid'] ?? 0),
            'product_name' => (string) ($row['proname'] ?? ''),
            'worker_id'    => (int) ($row['worker_id'] ?? 0),
            'worker_name'  => (string) ($row['worker_name'] ?? ''),
            'book_date'    => (string) ($row['book_date'] ?? ''),
            'book_time'    => (string) ($row['book_time'] ?? ''),
            'amount'       => (float) ($row['product_price'] ?? 0),
            'coupon_money' => (float) ($row['coupon_money'] ?? 0),
            'score_money'  => (float) ($row['score_money'] ?? 0),
            'pay_amount'   => (float) ($row['pay_amount'] ?? 0),
            'use_score'    => (int) ($row['use_score'] ?? 0),
            'status'       => $status,
            'status_text'  => self::STATUS_TEXT[$status] ?? '未知',
            'hexiao_code'  => (string) ($row['hexiao_code'] ?? ''),
            'pay_time'     => (int) ($row['paytime'] ?? 0),
            'create_time'  => (int) ($row['createtime'] ?? 0),
        ];
    }
}
