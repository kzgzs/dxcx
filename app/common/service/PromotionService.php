<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;

/**
 * 满减促销服务（P5 第二期）
 *
 * 表口径：promotion（时间均为 Unix 秒）
 * - type 1满额减：full_money 门槛 + cut_money 立减
 * - type 2满件折：full_num 件数门槛 + discount 折扣值（1~9.9），优惠额 = 商品金额 × (1 - discount/10)
 *
 * 计价叠加规则（全局约定）：
 * 商品金额 → 先满减促销（自动择优：所有生效活动中优惠额最大者）
 *          → 再优惠券（券门槛按促销后金额判断）
 *          → 再积分抵扣，实付下限 0.01
 */
class PromotionService
{
    public const TYPE_FULL_CUT  = 1; // 满额减
    public const TYPE_FULL_DISC = 2; // 满件折

    /** 已被订单使用后禁止修改的核心字段（契约键名 => 表列） */
    protected const CORE_FIELDS = [
        'name'       => 'name',
        'type'       => 'type',
        'full_money' => 'full_money',
        'full_num'   => 'full_num',
        'cut_money'  => 'cut_money',
        'discount'   => 'discount',
    ];

    /* ================= admin 租户后台侧 ================= */

    /**
     * 活动列表（status 空=全部；keyword 匹配名称；时间 Unix 秒）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function adminList(int $aid, string $status, string $keyword, int $page, int $pageSize): array
    {
        $query = ScopeService::query('promotion', $aid)->where('deleted', 0);

        if ('' !== $status) {
            $query->where('status', (int) $status);
        }

        if ('' !== $keyword) {
            $query->where('name', 'like', '%' . $keyword . '%');
        }

        $total = (clone $query)->count();

        $rows = $query->order('id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        return [
            'list'     => array_map(fn (array $row) => $this->format($row), $rows),
            'total'    => $total,
            'page'     => $page,
            'pagesize' => $pageSize,
        ];
    }

    /**
     * 新建活动
     *
     * @param array<string, mixed> $data
     * @return array{id: int}
     */
    public function add(int $aid, array $data): array
    {
        $row = $this->buildData($data, null);

        $row['aid']        = $aid;
        $row['status']     = 1;
        $row['deleted']    = 0;
        $row['createtime'] = time();

        return ['id' => (int) Db::name('promotion')->insertGetId($row)];
    }

    /**
     * 编辑活动（已被订单使用时核心字段禁改，口径同券：present 且不同才拒）
     *
     * @param array<string, mixed> $data
     */
    public function edit(int $aid, array $data): void
    {
        $promo = $this->findPromotion($aid, (int) ($data['id'] ?? 0));

        $used     = $this->orderUseCount((int) $promo['id']);
        $coreLocked = $used > 0;
        $update   = [];

        // 生效类型：以提交的 type 为准（未提交用当前值）；无关类型的字段传 0 一律忽略（前端容错）
        $effectiveType = array_key_exists('type', $data) ? (int) $data['type'] : (int) ($promo['type'] ?? 0);
        $relevant      = static fn (string $key): bool => in_array($key, ['name', 'type'], true)
            || (self::TYPE_FULL_CUT === $effectiveType && in_array($key, ['full_money', 'cut_money'], true))
            || (self::TYPE_FULL_DISC === $effectiveType && in_array($key, ['full_num', 'discount'], true));

        foreach (self::CORE_FIELDS as $key => $column) {
            if (!array_key_exists($key, $data) || !$relevant($key)) {
                continue;
            }

            $changed = 'name' === $key
                ? trim((string) $data[$key]) !== (string) ($promo[$column] ?? '')
                : abs((float) $data[$key] - (float) ($promo[$column] ?? 0)) > 0.0001;

            if (!$changed) {
                continue; // 原值回传：容忍
            }

            if ($coreLocked) {
                throw new BusinessException('该活动已被订单使用，禁止修改核心字段（名称/类型/门槛/优惠参数）');
            }

            $update[$column] = 'name' === $key ? trim((string) $data[$key]) : (float) $data[$key];
        }

        if (array_key_exists('status', $data)) {
            $status = (int) $data['status'];

            if (!in_array($status, [0, 1], true)) {
                throw new BusinessException('状态值无效');
            }
            $update['status'] = $status;
        }

        // 时间字段：合并后统一校验 end > start
        $startTime = array_key_exists('start_time', $data) ? (int) $data['start_time'] : (int) ($promo['start_time'] ?? 0);
        $endTime   = array_key_exists('end_time', $data) ? (int) $data['end_time'] : (int) ($promo['end_time'] ?? 0);

        if (array_key_exists('start_time', $data) || array_key_exists('end_time', $data)) {
            if ($startTime <= 0 || $endTime <= 0 || $endTime <= $startTime) {
                throw new BusinessException('结束时间必须晚于开始时间');
            }
            $update['start_time'] = $startTime;
            $update['end_time']   = $endTime;
        }

        // 核心字段变动时按新类型复验参数
        if (!empty(array_intersect_key($update, array_flip(self::CORE_FIELDS)))) {
            $this->validateTypeParams(array_merge($promo, $update));
        }

        if (empty($update)) {
            return;
        }

        Db::name('promotion')->where('id', (int) $promo['id'])->update($update);
    }

    /**
     * 启用/停用
     */
    public function status(int $aid, int $id, int $status): void
    {
        if (!in_array($status, [0, 1], true)) {
            throw new BusinessException('状态值无效');
        }

        $promo = $this->findPromotion($aid, $id);

        Db::name('promotion')->where('id', (int) $promo['id'])->update(['status' => $status]);
    }

    /**
     * 软删除（已被订单使用拒绝）
     */
    public function delete(int $aid, int $id): void
    {
        $promo = $this->findPromotion($aid, $id);

        if ($this->orderUseCount((int) $promo['id']) > 0) {
            throw new BusinessException('该活动已被订单使用，不能删除');
        }

        Db::name('promotion')->where('id', (int) $promo['id'])->update(['deleted' => 1]);
    }

    /* ================= api 侧 ================= */

    /**
     * 生效中活动列表（匿名浏览：status=1、未删除、当前时间在区间内）
     *
     * @return array{list: array}
     */
    public function apiActiveList(int $aid): array
    {
        $now  = time();
        $rows = ScopeService::query('promotion', $aid)
            ->where('status', 1)
            ->where('deleted', 0)
            ->where('start_time', '<=', $now)
            ->where('end_time', '>=', $now)
            ->order('id', 'desc')
            ->select()
            ->toArray();

        return ['list' => array_map(fn (array $row) => $this->format($row), $rows)];
    }

    /* ================= 订单计价联动 ================= */

    /**
     * 自动择优：当前生效（status=1、时间区间内、满足门槛）且优惠额最大的活动
     *
     * @return array{promotion_id: int, promotion_money: float}|null 无可用活动时 null
     */
    public function pickBest(int $aid, float $productPrice, int $num): ?array
    {
        $now  = time();
        $rows = ScopeService::query('promotion', $aid)
            ->where('status', 1)
            ->where('deleted', 0)
            ->where('start_time', '<=', $now)
            ->where('end_time', '>=', $now)
            ->select()
            ->toArray();

        $bestId   = 0;
        $bestCut  = 0.0;

        foreach ($rows as $row) {
            $cut = $this->calcCut($row, $productPrice, $num);

            if ($cut > $bestCut) {
                $bestCut = $cut;
                $bestId  = (int) $row['id'];
            }
        }

        if ($bestId <= 0 || $bestCut <= 0) {
            return null;
        }

        return ['promotion_id' => $bestId, 'promotion_money' => $bestCut];
    }

    /* ================= 内部工具 ================= */

    /**
     * 单个活动的优惠额（不满足门槛返回 0）
     *
     * @param array<string, mixed> $row
     */
    protected function calcCut(array $row, float $productPrice, int $num): float
    {
        if (self::TYPE_FULL_DISC === (int) ($row['type'] ?? 0)) {
            if ($num < (int) ($row['full_num'] ?? 0)) {
                return 0.0;
            }

            $discount = (float) ($row['discount'] ?? 10);

            return round($productPrice * (1 - $discount / 10), 2);
        }

        if ($productPrice < (float) ($row['full_money'] ?? 0)) {
            return 0.0;
        }

        return round((float) ($row['cut_money'] ?? 0), 2);
    }

    /**
     * 新建参数组装与校验（时间 Unix 秒）
     *
     * @param array<string, mixed>      $data
     * @param array<string, mixed>|null $current
     * @return array<string, mixed>
     */
    protected function buildData(array $data, ?array $current): array
    {
        $name = trim((string) ($data['name'] ?? ''));

        if ('' === $name) {
            throw new BusinessException('请填写活动名称');
        }

        $type = (int) ($data['type'] ?? 0);

        if (!in_array($type, [self::TYPE_FULL_CUT, self::TYPE_FULL_DISC], true)) {
            throw new BusinessException('活动类型无效');
        }

        $fullMoney = isset($data['full_money']) ? (float) $data['full_money'] : (float) ($current['full_money'] ?? 0);
        $fullNum   = isset($data['full_num']) ? (int) $data['full_num'] : (int) ($current['full_num'] ?? 0);
        $cutMoney  = isset($data['cut_money']) ? (float) $data['cut_money'] : (float) ($current['cut_money'] ?? 0);
        $discount  = isset($data['discount']) ? (float) $data['discount'] : (float) ($current['discount'] ?? 10);

        if (self::TYPE_FULL_CUT === $type) {
            if ($fullMoney <= 0) {
                throw new BusinessException('满额减必须填写满额门槛');
            }
            if ($cutMoney <= 0) {
                throw new BusinessException('满额减必须填写立减金额');
            }
            if ($cutMoney > $fullMoney) {
                throw new BusinessException('立减金额不能大于满额门槛');
            }
            // 非本类型字段归零，避免脏值入库
            $fullNum  = 0;
            $discount = 10.0;
        } else {
            if ($fullNum <= 0) {
                throw new BusinessException('满件折必须填写满件门槛');
            }
            if ($discount < 1 || $discount > 9.9) {
                throw new BusinessException('满件折折扣值须在 1~9.9 之间');
            }
            $fullMoney = 0.0;
            $cutMoney  = 0.0;
        }

        $startTime = (int) ($data['start_time'] ?? 0);
        $endTime   = (int) ($data['end_time'] ?? 0);

        if ($startTime <= 0 || $endTime <= 0) {
            throw new BusinessException('请填写活动起止时间');
        }
        if ($endTime <= $startTime) {
            throw new BusinessException('结束时间必须晚于开始时间');
        }

        return [
            'name'       => $name,
            'type'       => $type,
            'full_money' => $fullMoney,
            'full_num'   => $fullNum,
            'cut_money'  => $cutMoney,
            'discount'   => $discount,
            'start_time' => $startTime,
            'end_time'   => $endTime,
        ];
    }

    /**
     * 编辑核心字段变动后的类型参数复验
     *
     * @param array<string, mixed> $merged
     */
    protected function validateTypeParams(array $merged): void
    {
        $type = (int) ($merged['type'] ?? 0);

        if (self::TYPE_FULL_CUT === $type) {
            if ((float) ($merged['full_money'] ?? 0) <= 0 || (float) ($merged['cut_money'] ?? 0) <= 0) {
                throw new BusinessException('满额减必须填写门槛与立减金额');
            }
            if ((float) $merged['cut_money'] > (float) $merged['full_money']) {
                throw new BusinessException('立减金额不能大于满额门槛');
            }
        } elseif (self::TYPE_FULL_DISC === $type) {
            if ((int) ($merged['full_num'] ?? 0) <= 0) {
                throw new BusinessException('满件折必须填写满件门槛');
            }
            $discount = (float) ($merged['discount'] ?? 10);

            if ($discount < 1 || $discount > 9.9) {
                throw new BusinessException('满件折折扣值须在 1~9.9 之间');
            }
        } else {
            throw new BusinessException('活动类型无效');
        }
    }

    /**
     * 活动被订单使用次数（shop_order.promotion_id）
     */
    protected function orderUseCount(int $promotionId): int
    {
        return (int) Db::name('shop_order')->where('promotion_id', $promotionId)->count();
    }

    /**
     * 查找本租户未删除的活动（不存在抛 404）
     *
     * @return array<string, mixed>
     */
    protected function findPromotion(int $aid, int $id): array
    {
        if ($id <= 0) {
            throw new BusinessException('缺少活动ID');
        }

        $promo = ScopeService::query('promotion', $aid)
            ->where('id', $id)
            ->where('deleted', 0)
            ->find();

        if (empty($promo)) {
            throw new BusinessException('活动不存在', 404, 404);
        }

        return $promo;
    }

    /**
     * 活动行格式化（契约字段，时间 Unix 秒）
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function format(array $row): array
    {
        return [
            'id'          => (int) ($row['id'] ?? 0),
            'name'        => (string) ($row['name'] ?? ''),
            'type'        => (int) ($row['type'] ?? 1),
            'full_money'  => (float) ($row['full_money'] ?? 0),
            'full_num'    => (int) ($row['full_num'] ?? 0),
            'cut_money'   => (float) ($row['cut_money'] ?? 0),
            'discount'    => (float) ($row['discount'] ?? 10),
            'start_time'  => (int) ($row['start_time'] ?? 0),
            'end_time'    => (int) ($row['end_time'] ?? 0),
            'status'      => (int) ($row['status'] ?? 1),
            'create_time' => (int) ($row['createtime'] ?? 0),
        ];
    }

    /**
     * 时间戳格式化（空值返回空串）
     */
    protected function formatTime(mixed $timestamp): string
    {
        $timestamp = (int) $timestamp;

        return $timestamp > 0 ? date('Y-m-d H:i:s', $timestamp) : '';
    }
}
