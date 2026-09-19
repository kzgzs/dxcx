<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;

/**
 * 商城商品卡密服务（对标参考系统 ShopCode：shop_codelist 表）
 *
 * 功能：卡密列表/Excel 导入去重/批量生成 makecode/导出/删除
 * 状态：status 0未售出 1已售出（售出回填由发货链路负责，本模块只做管理）
 */
class CodeListService
{
    /**
     * 卡密分页列表
     *
     * @param string $status ''=全部 0=未售出 1=已售出
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function list(int $aid, int $page, int $pagesize, int $proid, string $content, string $ordernum, string $nickname, string $status): array
    {
        $query = ScopeService::query('shop_codelist', $aid);

        if ($proid > 0) {
            $query->where('proid', $proid);
        }

        $content = trim($content);
        if ('' !== $content) {
            $query->where('content', $content);
        }

        $ordernum = trim($ordernum);
        if ('' !== $ordernum) {
            $query->where('ordernum', $ordernum);
        }

        $nickname = trim($nickname);
        if ('' !== $nickname) {
            $query->whereLike('nickname', '%' . $nickname . '%');
        }

        if ('' !== $status) {
            $query->where('status', (int) $status);
        }

        $total = (clone $query)->count();

        $rows = $query->order('id', 'desc')
            ->page($page, $pagesize)
            ->select()
            ->toArray();

        $list = array_map(static fn (array $row): array => [
            'id'         => (int) $row['id'],
            'proid'      => (int) ($row['proid'] ?? 0),
            'content'    => (string) ($row['content'] ?? ''),
            'ordernum'   => (string) ($row['ordernum'] ?? ''),
            'orderid'    => (int) ($row['orderid'] ?? 0),
            'nickname'   => (string) ($row['nickname'] ?? ''),
            'status'     => (int) ($row['status'] ?? 0),
            'create_time' => (int) ($row['createtime'] ?? 0) > 0 ? date('Y-m-d H:i:s', (int) $row['createtime']) : '',
            'buy_time'   => (int) ($row['buytime'] ?? 0) > 0 ? date('Y-m-d H:i:s', (int) $row['buytime']) : '',
        ], $rows);

        return ['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pagesize];
    }

    /**
     * Excel 导入卡密（首列即卡密内容，同商品下重复内容跳过）
     *
     * @return array{inserted: int, duplicated: int}
     */
    public function importExcel(int $aid, int $proid, string $filePath): array
    {
        $this->findProduct($aid, $proid);

        $rows = ExcelHelper::readRows($filePath);

        if (empty($rows)) {
            throw new BusinessException('Excel 内容为空');
        }

        // 已存在卡密集合（同租户同商品）用于去重
        $exists = Db::name('shop_codelist')
            ->where('aid', $aid)
            ->where('proid', $proid)
            ->column('content');
        $exists = array_flip(array_map('strval', $exists));

        $inserted   = 0;
        $duplicated = 0;
        $now        = time();
        $batch      = [];

        foreach ($rows as $ri => $data) {
            $content = trim((string) ($data[0] ?? ''));

            // 首行若是表头（卡密/内容 字样）跳过
            if (0 === $ri && ('' === $content || str_contains($content, '卡密') || str_contains($content, '内容'))) {
                continue;
            }

            if ('' === $content) {
                continue;
            }

            if (isset($exists[$content])) {
                ++$duplicated;
                continue;
            }

            $exists[$content] = true;
            $batch[]          = [
                'aid'        => $aid,
                'bid'        => 0,
                'proid'      => $proid,
                'content'    => $content,
                'createtime' => $now,
                'status'     => 0,
            ];
            ++$inserted;

            if (count($batch) >= 500) {
                Db::name('shop_codelist')->insertAll($batch);
                $batch = [];
            }
        }

        if (!empty($batch)) {
            Db::name('shop_codelist')->insertAll($batch);
        }

        return ['inserted' => $inserted, 'duplicated' => $duplicated];
    }

    /**
     * 批量生成卡密（对标参考系统 makecode：codetype 1纯数字 2大小写字母 3小写 4大写 5数字+小写 6数字+大写）
     *
     * @return array{created: int}
     */
    public function makeCode(int $aid, int $proid, int $makeCount, int $codeLength, int $codeType): array
    {
        $this->findProduct($aid, $proid);

        if ($makeCount < 1 || $makeCount > 5000) {
            throw new BusinessException('每次生成数量须在5000以内');
        }
        if ($codeLength < 1) {
            throw new BusinessException('卡密长度须大于等于1');
        }

        $lengthLimit = [1 => 20, 2 => 52, 3 => 26, 4 => 26, 5 => 35, 6 => 35];

        if (!isset($lengthLimit[$codeType])) {
            throw new BusinessException('卡密类型取值 1~6');
        }
        if ($codeLength > $lengthLimit[$codeType]) {
            throw new BusinessException('该类型卡密长度须小于等于' . $lengthLimit[$codeType]);
        }

        // 已存在卡密集合防重
        $exists = array_flip(array_map('strval', Db::name('shop_codelist')
            ->where('aid', $aid)
            ->where('proid', $proid)
            ->column('content')));

        $created = 0;
        $now     = time();
        $batch   = [];

        for ($i = 0; $i < $makeCount; ++$i) {
            $code = self::randomCode($codeType, $codeLength);

            if (isset($exists[$code])) {
                continue;
            }

            $exists[$code] = true;
            $batch[]       = [
                'aid'        => $aid,
                'bid'        => 0,
                'proid'      => $proid,
                'content'    => $code,
                'createtime' => $now,
                'status'     => 0,
            ];
            ++$created;

            if (count($batch) >= 500) {
                Db::name('shop_codelist')->insertAll($batch);
                $batch = [];
            }
        }

        if (!empty($batch)) {
            Db::name('shop_codelist')->insertAll($batch);
        }

        return ['created' => $created];
    }

    /**
     * 导出数据行（供 ExcelHelper::download 使用）
     *
     * @return array<int, array<int, mixed>>
     */
    public function exportRows(int $aid, int $proid): array
    {
        $query = ScopeService::query('shop_codelist', $aid);

        if ($proid > 0) {
            $query->where('proid', $proid);
        }

        $rows = $query->order('id', 'desc')->select()->toArray();

        return array_map(static fn (array $v): array => [
            (int) $v['id'],
            (string) ($v['content'] ?? ''),
            (string) ($v['ordernum'] ?? ''),
            (string) ($v['nickname'] ?? ''),
            (int) ($v['status'] ?? 0) === 1 && (int) ($v['buytime'] ?? 0) > 0 ? date('Y-m-d H:i:s', (int) $v['buytime']) : '',
            (int) ($v['status'] ?? 0) === 1 ? '已售出' : '未售出',
        ], $rows);
    }

    /**
     * 批量删除（仅未售出可删）
     *
     * @param array<int, int> $ids
     * @return array{deleted: int}
     */
    public function delete(int $aid, array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $v) => $v > 0));

        if (empty($ids)) {
            throw new BusinessException('缺少要删除的卡密ID');
        }

        $deleted = Db::name('shop_codelist')
            ->where('aid', $aid)
            ->whereIn('id', $ids)
            ->where('status', 0)
            ->delete();

        return ['deleted' => (int) $deleted];
    }

    /* ================= 内部工具 ================= */

    /**
     * 校验商品存在（本租户未删）
     *
     * @return array<string, mixed>
     */
    protected function findProduct(int $aid, int $proid): array
    {
        if ($proid <= 0) {
            throw new BusinessException('请选择商品');
        }

        $product = ScopeService::query('shop_product', $aid)
            ->where('id', $proid)
            ->where('deleted', 0)
            ->find();

        if (empty($product)) {
            throw new BusinessException('商品不存在', 404, 404);
        }

        return (array) $product;
    }

    /**
     * 随机卡密串生成（对标参考系统 make_rand_code）
     */
    protected static function randomCode(int $type, int $length): string
    {
        $pools = [
            1 => '0123456789',
            2 => 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ',
            3 => 'abcdefghijklmnopqrstuvwxyz',
            4 => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
            5 => '0123456789abcdefghijklmnopqrstuvwxyz',
            6 => '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ',
        ];

        $pool  = $pools[$type] ?? $pools[5];
        $max   = strlen($pool) - 1;
        $code  = '';

        for ($i = 0; $i < $length; ++$i) {
            $code .= $pool[random_int(0, $max)];
        }

        return $code;
    }
}
