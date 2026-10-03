<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;

/**
 * 购物满减设置服务（对齐参考系统 Manjian/set）
 *
 * 表口径：manjian_set（按 aid 单行 upsert）
 * - mjdata JSON 档位数组 [{money:购物满X元, jian:减Y元}]
 * - fwtype 适用范围 0所有 1指定类目(categoryids) 2指定商品(productids)
 * - total_status 累计消费额满减开关 0关 1开
 * - status 总开关 0关 1开
 */
class ManjianService
{
    /**
     * 读取满减配置
     *
     * @return array<string, mixed>
     */
    public function config(int $aid): array
    {
        $row = Db::name('manjian_set')->where('aid', $aid)->find();

        $mjdata = [];

        if (!empty($row['mjdata'])) {
            $decoded = json_decode((string) $row['mjdata'], true);
            if (is_array($decoded)) {
                foreach ($decoded as $item) {
                    $mjdata[] = [
                        'money' => (float) ($item['money'] ?? 0),
                        'jian'  => (float) ($item['jian'] ?? 0),
                    ];
                }
            }
        }

        return [
            'mjdata'       => $mjdata,
            'fwtype'       => (int) ($row['fwtype'] ?? 0),
            'categoryids'  => (string) ($row['categoryids'] ?? ''),
            'productids'   => (string) ($row['productids'] ?? ''),
            'total_status' => (int) ($row['total_status'] ?? 0),
            'status'       => (int) ($row['status'] ?? 0),
        ];
    }

    /**
     * 保存满减配置（按 aid upsert）
     *
     * @param array<string, mixed> $data
     */
    public function save(int $aid, array $data): void
    {
        $fwtype = (int) ($data['fwtype'] ?? 0);

        if (!in_array($fwtype, [0, 1, 2], true)) {
            throw new BusinessException('适用范围类型无效');
        }

        $categoryids = trim((string) ($data['categoryids'] ?? ''));
        $productids  = trim((string) ($data['productids'] ?? ''));

        if (1 === $fwtype && '' === $categoryids) {
            throw new BusinessException('请选择指定类目');
        }
        if (2 === $fwtype && '' === $productids) {
            throw new BusinessException('请选择指定商品');
        }

        $totalStatus = (int) ($data['total_status'] ?? 0);

        if (!in_array($totalStatus, [0, 1], true)) {
            throw new BusinessException('累计消费额满减开关值无效');
        }

        $status = (int) ($data['status'] ?? 0);

        if (!in_array($status, [0, 1], true)) {
            throw new BusinessException('状态值无效');
        }

        // 档位：只保留 money>0 且 jian>0 的行
        $rows = [];
        $moneys = (array) ($data['money'] ?? []);
        $jians  = (array) ($data['jian'] ?? []);

        foreach ($moneys as $k => $money) {
            $m = (float) $money;
            $j = (float) ($jians[$k] ?? 0);

            if ($m > 0 && $j > 0) {
                $rows[] = ['money' => $m, 'jian' => $j];
            }
        }

        $update = [
            'fwtype'       => $fwtype,
            'categoryids'  => $categoryids,
            'productids'   => $productids,
            'total_status' => $totalStatus,
            'status'       => $status,
            'mjdata'       => json_encode($rows, JSON_UNESCAPED_UNICODE),
        ];

        $exists = Db::name('manjian_set')->where('aid', $aid)->find();

        if ($exists) {
            Db::name('manjian_set')->where('id', (int) $exists['id'])->update($update);
        } else {
            $update['aid']        = $aid;
            $update['createtime']  = time();
            Db::name('manjian_set')->insert($update);
        }
    }
}
