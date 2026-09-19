<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;

/**
 * 商户与门店服务（admin 租户后台）
 *
 * 表口径：
 * - merchant 商户表（新建；旧表 business 为分销商，不复用）
 *   status 1启用 0停用，deleted 软删
 * - mendian 门店表（基线复用，补 merchant_id/contacts/deleted 列）
 *   phone→tel、lng→longitude、lat→latitude
 */
class MerchantService
{
    /* ================= 商户 ================= */

    /**
     * 商户列表（keyword 匹配名称/联系人/电话；status 空=全部；附门店数）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function list(int $aid, string $keyword, string $status, int $page, int $pageSize): array
    {
        $query = ScopeService::query('merchant', $aid)->where('deleted', 0);

        if ('' !== $status) {
            $query->where('status', (int) $status);
        }

        if ('' !== $keyword) {
            $like = '%' . $keyword . '%';
            $query->where(function ($q) use ($like) {
                $q->whereOr('name', 'like', $like)
                    ->whereOr('contacts', 'like', $like)
                    ->whereOr('phone', 'like', $like);
            });
        }

        $total = (clone $query)->count();

        $rows = $query->order('id', 'desc')->page($page, $pageSize)->select()->toArray();

        // 门店计数（仅未删除门店）
        $ids      = array_map(static fn (array $r) => (int) $r['id'], $rows);
        $countMap = [];

        if (!empty($ids)) {
            $counts = ScopeService::query('mendian', $aid)
                ->whereIn('merchant_id', $ids)
                ->where('deleted', 0)
                ->field('merchant_id, count(*) as cnt')
                ->group('merchant_id')
                ->select()
                ->toArray();

            foreach ($counts as $c) {
                $countMap[(int) $c['merchant_id']] = (int) $c['cnt'];
            }
        }

        $list = array_map(function (array $r) use ($countMap) {
            $one = $this->formatMerchant($r);
            $one['store_count'] = $countMap[(int) $r['id']] ?? 0;

            return $one;
        }, $rows);

        return ['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize];
    }

    /**
     * 新增商户
     *
     * @return array{id: int}
     */
    public function add(int $aid, array $data): array
    {
        $row = $this->buildMerchantData($data, false);

        $row['aid']        = $aid;
        $row['createtime'] = time();

        $id = (int) ScopeService::query('merchant', $aid)->insertGetId($row);

        return ['id' => $id];
    }

    /**
     * 编辑商户
     *
     * @return array{id: int}
     */
    public function edit(int $aid, int $id, array $data): array
    {
        $this->findMerchant($aid, $id);

        $row = $this->buildMerchantData($data, true);

        if (!empty($row)) {
            ScopeService::query('merchant', $aid)->where('id', $id)->update($row);
        }

        return ['id' => $id];
    }

    /**
     * 删除商户（软删；名下仍有门店时拒绝）
     */
    public function delete(int $aid, int $id): void
    {
        $this->findMerchant($aid, $id);

        $storeCount = (int) ScopeService::query('mendian', $aid)
            ->where('merchant_id', $id)
            ->where('deleted', 0)
            ->count();

        if ($storeCount > 0) {
            throw new BusinessException('该商户下仍有门店，请先删除或转移门店');
        }

        ScopeService::query('merchant', $aid)->where('id', $id)->update(['deleted' => 1]);
    }

    /* ================= 门店 ================= */

    /**
     * 门店列表（keyword 匹配门店名/联系人/电话；merchant_id 筛选；带 merchant_name）
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function storeList(int $aid, string $keyword, int $merchantId, int $page, int $pageSize): array
    {
        $query = ScopeService::queryAs('mendian', $aid, 's')
            ->leftJoin('merchant mc', 'mc.id = s.merchant_id')
            ->field('s.*, mc.name as merchant_name')
            ->where('s.deleted', 0);

        if ($merchantId > 0) {
            $query->where('s.merchant_id', $merchantId);
        }

        if ('' !== $keyword) {
            $like = '%' . $keyword . '%';
            $query->where(function ($q) use ($like) {
                $q->whereOr('s.name', 'like', $like)
                    ->whereOr('s.contacts', 'like', $like)
                    ->whereOr('s.tel', 'like', $like);
            });
        }

        $total = (clone $query)->count();

        $rows = $query->order('s.id', 'desc')->page($page, $pageSize)->select()->toArray();

        return [
            'list'     => array_map(fn (array $r) => $this->formatStore($r), $rows),
            'total'    => $total,
            'page'     => $page,
            'pagesize' => $pageSize,
        ];
    }

    /**
     * 新增门店（商户必须存在且启用）
     *
     * @return array{id: int}
     */
    public function storeAdd(int $aid, array $data): array
    {
        $row = $this->buildStoreData($data, false);

        // 所属商户必须存在且启用
        $merchant = ScopeService::query('merchant', $aid)
            ->where('id', (int) $row['merchant_id'])
            ->where('deleted', 0)
            ->find();

        if (empty($merchant)) {
            throw new BusinessException('所属商户不存在');
        }

        if (1 !== (int) $merchant['status']) {
            throw new BusinessException('所属商户已停用，无法新增门店');
        }

        $row['aid']        = $aid;
        $row['createtime'] = time();

        $id = (int) ScopeService::query('mendian', $aid)->insertGetId($row);

        return ['id' => $id];
    }

    /**
     * 编辑门店
     *
     * @return array{id: int}
     */
    public function storeEdit(int $aid, int $id, array $data): array
    {
        $this->findStore($aid, $id);

        $row = $this->buildStoreData($data, true);

        if (!empty($row)) {
            ScopeService::query('mendian', $aid)->where('id', $id)->update($row);
        }

        return ['id' => $id];
    }

    /**
     * 删除门店（软删）
     */
    public function storeDelete(int $aid, int $id): void
    {
        $this->findStore($aid, $id);

        ScopeService::query('mendian', $aid)->where('id', $id)->update(['deleted' => 1]);
    }

    /* ================= 内部工具 ================= */

    /**
     * @return array<string, mixed>
     */
    protected function findMerchant(int $aid, int $id): array
    {
        $row = ScopeService::query('merchant', $aid)->where('id', $id)->where('deleted', 0)->find();

        if (empty($row)) {
            throw new BusinessException('商户不存在', 404, 404);
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    protected function findStore(int $aid, int $id): array
    {
        $row = ScopeService::query('mendian', $aid)->where('id', $id)->where('deleted', 0)->find();

        if (empty($row)) {
            throw new BusinessException('门店不存在', 404, 404);
        }

        return $row;
    }

    /**
     * 商户入库数据组装
     *
     * @return array<string, mixed>
     */
    protected function buildMerchantData(array $data, bool $partial): array
    {
        $has = static fn (string $key): bool => !$partial || array_key_exists($key, $data);
        $row = [];

        if ($has('name')) {
            $name = trim((string) ($data['name'] ?? ''));

            if ('' === $name) {
                throw new BusinessException('请填写商户名称');
            }

            $row['name'] = $name;
        }

        if ($has('logo')) {
            $row['logo'] = trim((string) ($data['logo'] ?? ''));
        }

        if ($has('contacts')) {
            $row['contacts'] = trim((string) ($data['contacts'] ?? ''));
        }

        if ($has('phone')) {
            $phone = trim((string) ($data['phone'] ?? ''));

            if ('' !== $phone && !preg_match('/^1\d{10}$/', $phone)) {
                throw new BusinessException('手机号格式不正确');
            }

            $row['phone'] = $phone;
        }

        if ($has('remark')) {
            $row['remark'] = trim((string) ($data['remark'] ?? ''));
        }

        if ($has('status')) {
            $row['status'] = (int) ($data['status'] ?? 1) === 1 ? 1 : 0;
        } elseif (!$partial) {
            $row['status'] = 1;
        }

        return $row;
    }

    /**
     * 门店入库数据组装（lng→longitude、lat→latitude、phone→tel）
     *
     * @return array<string, mixed>
     */
    protected function buildStoreData(array $data, bool $partial): array
    {
        $has = static fn (string $key): bool => !$partial || array_key_exists($key, $data);
        $row = [];

        if ($has('merchant_id')) {
            $merchantId = (int) ($data['merchant_id'] ?? 0);

            if ($merchantId <= 0) {
                throw new BusinessException('请选择所属商户');
            }

            $row['merchant_id'] = $merchantId;
        }

        if ($has('name')) {
            $name = trim((string) ($data['name'] ?? ''));

            if ('' === $name) {
                throw new BusinessException('请填写门店名称');
            }

            $row['name'] = $name;
        }

        if ($has('contacts')) {
            $row['contacts'] = trim((string) ($data['contacts'] ?? ''));
        }

        if ($has('phone')) {
            $phone = trim((string) ($data['phone'] ?? ''));

            if ('' !== $phone && !preg_match('/^1\d{10}$/', $phone)) {
                throw new BusinessException('手机号格式不正确');
            }

            $row['tel'] = $phone;
        }

        foreach (['province', 'city', 'district', 'address'] as $field) {
            if ($has($field)) {
                $row[$field] = trim((string) ($data[$field] ?? ''));
            }
        }

        if ($has('lng')) {
            $lng = trim((string) ($data['lng'] ?? ''));

            // 编辑时空串/null 视为不修改，不覆盖已有经度
            if (!$partial || '' !== $lng) {
                $row['longitude'] = $lng;
            }
        }

        if ($has('lat')) {
            $lat = trim((string) ($data['lat'] ?? ''));

            // 编辑时空串/null 视为不修改，不覆盖已有纬度
            if (!$partial || '' !== $lat) {
                $row['latitude'] = $lat;
            }
        }

        if ($has('status')) {
            $row['status'] = (int) ($data['status'] ?? 1) === 1 ? 1 : 0;
        } elseif (!$partial) {
            $row['status'] = 1;
        }

        // 新增兜底校验
        if (!$partial) {
            if (!isset($row['merchant_id'])) {
                throw new BusinessException('请选择所属商户');
            }
            if (!isset($row['name'])) {
                throw new BusinessException('请填写门店名称');
            }
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function formatMerchant(array $row): array
    {
        return [
            'id'          => (int) $row['id'],
            'name'        => (string) ($row['name'] ?? ''),
            'logo'        => (string) ($row['logo'] ?? ''),
            'contacts'    => (string) ($row['contacts'] ?? ''),
            'phone'       => (string) ($row['phone'] ?? ''),
            'remark'      => (string) ($row['remark'] ?? ''),
            'status'      => (int) ($row['status'] ?? 1),
            'create_time' => $this->formatTime($row['createtime'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function formatStore(array $row): array
    {
        return [
            'id'            => (int) $row['id'],
            'merchant_id'   => (int) ($row['merchant_id'] ?? 0),
            'merchant_name' => (string) ($row['merchant_name'] ?? ''),
            'name'          => (string) ($row['name'] ?? ''),
            'contacts'      => (string) ($row['contacts'] ?? ''),
            'phone'         => (string) ($row['tel'] ?? ''),
            'province'      => (string) ($row['province'] ?? ''),
            'city'          => (string) ($row['city'] ?? ''),
            'district'      => (string) ($row['district'] ?? ''),
            'address'       => (string) ($row['address'] ?? ''),
            'lng'           => (string) ($row['longitude'] ?? ''),
            'lat'           => (string) ($row['latitude'] ?? ''),
            'status'        => (int) ($row['status'] ?? 1),
            'create_time'   => $this->formatTime($row['createtime'] ?? null),
        ];
    }

    protected function formatTime(mixed $timestamp): string
    {
        $timestamp = (int) $timestamp;

        return $timestamp > 0 ? date('Y-m-d H:i:s', $timestamp) : '';
    }
}
