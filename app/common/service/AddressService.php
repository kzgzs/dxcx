<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;

/**
 * 会员收货地址服务（api 应用）
 *
 * 表口径：member_address（旧表复用，补 deleted 软删列）
 * 字段映射：phone→tel、detail→address、is_default→isdefault
 * 隔离：一律 ScopeService::query + mid 双条件，杜绝跨会员/跨租户读写
 */
class AddressService
{
    /**
     * 新增收货地址
     *
     * @return array{id: int}
     */
    public function add(int $aid, int $mid, array $data): array
    {
        $row = $this->buildData($data);

        $now            = time();
        $row['aid']      = $aid;
        $row['mid']      = (string) $mid; // 旧表 mid 为 varchar，沿用口径
        $row['createtime'] = $now;

        $id = (int) ScopeService::query('member_address', $aid)->insertGetId($row);

        if (!empty($data['is_default'])) {
            $this->applyDefault($aid, $mid, $id);
        }

        return ['id' => $id];
    }

    /**
     * 编辑收货地址（仅允许改本人未删除地址）
     *
     * @return array{id: int}
     */
    public function edit(int $aid, int $mid, int $id, array $data): array
    {
        $this->findOwned($aid, $mid, $id);

        $row = $this->buildData($data, true);

        ScopeService::query('member_address', $aid)
            ->where('id', $id)
            ->where('mid', (string) $mid)
            ->update($row);

        if (!empty($data['is_default'])) {
            $this->applyDefault($aid, $mid, $id);
        }

        return ['id' => $id];
    }

    /**
     * 删除收货地址（软删）
     */
    public function delete(int $aid, int $mid, int $id): void
    {
        $this->findOwned($aid, $mid, $id);

        ScopeService::query('member_address', $aid)
            ->where('id', $id)
            ->where('mid', (string) $mid)
            ->update(['deleted' => 1]);
    }

    /**
     * 当前会员地址列表（默认地址置顶）
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(int $aid, int $mid): array
    {
        $rows = ScopeService::query('member_address', $aid)
            ->where('mid', (string) $mid)
            ->where('deleted', 0)
            ->order('isdefault', 'desc')
            ->order('id', 'desc')
            ->select()
            ->toArray();

        return array_map(fn (array $row) => $this->format($row), $rows);
    }

    /**
     * 查找本人名下有效地址（下单时快照用），不存在抛业务异常
     *
     * @return array<string, mixed>
     */
    public function findOwned(int $aid, int $mid, int $id): array
    {
        $row = ScopeService::query('member_address', $aid)
            ->where('id', $id)
            ->where('mid', (string) $mid)
            ->where('deleted', 0)
            ->find();

        if (empty($row)) {
            throw new BusinessException('收货地址不存在', 404, 404);
        }

        return $row;
    }

    /**
     * 组装入库数据（字段校验）
     *
     * @param bool $partial 编辑模式：仅校验并更新传入的字段
     * @return array<string, mixed>
     */
    protected function buildData(array $data, bool $partial = false): array
    {
        $has = static fn (string $key): bool => !$partial || array_key_exists($key, $data);
        $row = [];

        if ($has('name')) {
            $name = trim((string) ($data['name'] ?? ''));
            if ('' === $name) {
                throw new BusinessException('请填写收货人姓名');
            }
            $row['name'] = $name;
        }

        if ($has('phone')) {
            $phone = trim((string) ($data['phone'] ?? ''));
            if ('' === $phone || !preg_match('/^1\d{10}$/', $phone)) {
                throw new BusinessException('手机号格式不正确');
            }
            $row['tel'] = $phone;
        }

        foreach (['province', 'city', 'district'] as $field) {
            if (!$has($field)) {
                continue;
            }
            $value = trim((string) ($data[$field] ?? ''));
            if ('' === $value) {
                throw new BusinessException('请填写完整的省市区信息');
            }
            $row[$field] = $value;
        }

        if ($has('detail')) {
            $detail = trim((string) ($data['detail'] ?? ''));
            if ('' === $detail) {
                throw new BusinessException('请填写详细地址');
            }
            $row['address'] = $detail;
        }

        if ($has('is_default')) {
            $row['isdefault'] = empty($data['is_default']) ? 0 : 1;
        }

        // 新增时必填项兜底（防止调用方漏传）
        if (!$partial && count($row) > 0) {
            foreach (['name', 'tel', 'province', 'city', 'district', 'address'] as $required) {
                if (!isset($row[$required])) {
                    throw new BusinessException('地址信息不完整');
                }
            }
        }

        return $row;
    }

    /**
     * 设置默认地址（同会员仅一条默认）
     */
    protected function applyDefault(int $aid, int $mid, int $id): void
    {
        ScopeService::query('member_address', $aid)
            ->where('mid', (string) $mid)
            ->where('deleted', 0)
            ->where('id', '<>', $id)
            ->where('isdefault', 1)
            ->update(['isdefault' => 0]);

        ScopeService::query('member_address', $aid)
            ->where('id', $id)
            ->update(['isdefault' => 1]);
    }

    /**
     * 输出格式化（对外统一 detail/is_default 口径）
     *
     * @return array<string, mixed>
     */
    protected function format(array $row): array
    {
        return [
            'id'         => (int) $row['id'],
            'name'       => (string) ($row['name'] ?? ''),
            'phone'      => (string) ($row['tel'] ?? ''),
            'province'   => (string) ($row['province'] ?? ''),
            'city'       => (string) ($row['city'] ?? ''),
            'district'   => (string) ($row['district'] ?? ''),
            'detail'     => (string) ($row['address'] ?? ''),
            'is_default' => (int) ($row['isdefault'] ?? 0),
            'create_time' => !empty($row['createtime']) ? date('Y-m-d H:i:s', (int) $row['createtime']) : '',
        ];
    }
}
