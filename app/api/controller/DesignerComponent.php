<?php

declare(strict_types=1);

namespace app\api\controller;

use app\BaseController;
use app\common\service\ScopeService;
use think\facade\Db;

/**
 * C 端设计器组件统一数据接口（任务247 P27-3）
 *
 * GET /api/designerComponent/fetch?temp=collage&productfrom=1&cid=0&group=0&ids=&limit=6&sortby=sort
 *
 * - 匿名白名单（AuthMiddleware：designercomponent/fetch），aid 走 TenantMiddleware
 * - 按 temp 定位业务商品表，支持 分类(cid)/分组(group)/手动选品(ids)/数量(limit)
 *   参数契约与 B 端设计器对齐：productfrom/cid/group/ids/limit/quanxian/platform
 *   （quanxian/platform 为展示侧过滤参数，取数不参与）
 * - 对照参考系统 canzhao/app/common/System.php 各 temp 取数段（只读参照，口径精简）：
 *   status=1 + ischecked=1（有审核列的表），sort 优先排序
 */
class DesignerComponent extends BaseController
{
    /**
     * temp → 数据表配置
     * table   商品表名（不带前缀）
     * cid     分类列口径：int=整型等值 / find=逗号串 FIND_IN_SET / none=无分类列
     * group   是否有分组列 gid（FIND_IN_SET 匹配）
     * price   价格列（输出为 price）
     * check   是否有 ischecked 审核列
     */
    private const TEMPLATES = [
        'product'      => ['table' => 'shop_product',          'cid' => 'find', 'group' => true,  'price' => 'sell_price',  'check' => true],
        'collage'      => ['table' => 'collage_product',       'cid' => 'int',  'group' => false, 'price' => 'sell_price',  'check' => true],
        'luckycollage' => ['table' => 'lucky_collage_product', 'cid' => 'int',  'group' => false, 'price' => 'sell_price',  'check' => true],
        'seckill'      => ['table' => 'seckill_product',       'cid' => 'none', 'group' => false, 'price' => 'sell_price',  'check' => true],
        'kanjia'       => ['table' => 'kanjia_product',        'cid' => 'none', 'group' => false, 'price' => 'sell_price',  'check' => true],
        'tuangou'      => ['table' => 'tuangou_product',       'cid' => 'int',  'group' => false, 'price' => 'sell_price',  'check' => true],
        'scoreshop'    => ['table' => 'scoreshop_product',     'cid' => 'int',  'group' => false, 'price' => 'money_price', 'check' => true],
        'yuyue'        => ['table' => 'yuyue_product',         'cid' => 'none', 'group' => false, 'price' => 'sell_price',  'check' => true],
        'kecheng'      => ['table' => 'kecheng_list',          'cid' => 'find', 'group' => false, 'price' => 'price',       'check' => false],
        'restaurant'   => ['table' => 'restaurant_product',    'cid' => 'find', 'group' => true,  'price' => 'sell_price',  'check' => true],
        'article'      => ['table' => 'article',               'cid' => 'int',  'group' => false, 'price' => '',            'check' => false],
    ];

    /**
     * 组件数据取数
     * GET /api/designerComponent/fetch
     * 参数：temp(必填) / productfrom(0手动选品) / cid / group / ids(逗号串) / limit / sortby
     * 返回：{code:0,data:{list:[...]}}
     */
    public function fetch()
    {
        $aid  = ScopeService::aidOf($this->request);
        $temp = strtolower(trim((string) $this->request->param('temp', '')));

        if ('' === $temp || !isset(self::TEMPLATES[$temp])) {
            return $this->error('不支持的组件类型：' . $temp);
        }

        $conf = self::TEMPLATES[$temp];

        $productfrom = trim((string) $this->request->param('productfrom', '1'));
        $cid         = (int) $this->request->param('cid', 0);
        $group       = (int) $this->request->param('group', 0);
        $ids         = $this->parseIds((string) $this->request->param('ids', ''));
        $limit       = (int) $this->request->param('limit', 6);
        $limit       = $limit > 0 ? min($limit, 50) : 6;
        $sortby      = trim((string) $this->request->param('sortby', 'sort'));

        $query = Db::name($conf['table'])->where('aid', $aid);

        // 状态过滤：article 表 status=1 同样适用；各表均有 status 列
        $query->where('status', 1);

        if ($conf['check']) {
            $query->where('ischecked', 1);
        }

        if ('0' === $productfrom && [] !== $ids) {
            // 手动选品：按 ids 过滤（输出保持 ids 顺序）
            $rows = $query->where('id', 'in', $ids)->limit(50)->select()->toArray();
            $rows = $this->sortByIds($rows, $ids);
        } else {
            if ([] !== $ids) {
                $query->where('id', 'in', $ids);
            }
            if ($cid > 0 && 'none' !== $conf['cid']) {
                if ('int' === $conf['cid']) {
                    $query->where('cid', $cid);
                } else {
                    $query->whereRaw('FIND_IN_SET(' . $cid . ', `cid`)');
                }
            }
            if ($group > 0 && $conf['group']) {
                $query->whereRaw('FIND_IN_SET(' . $group . ', `gid`)');
            }

            $rows = $query
                ->order($this->orderBy($sortby))
                ->limit($limit)
                ->select()
                ->toArray();
        }

        return $this->success(['list' => array_map(
            fn (array $row): array => $this->format($temp, $conf, $row),
            $rows
        )]);
    }

    /**
     * 逗号串 → 正整数 id 数组（去重保序）
     * @return array<int, int>
     */
    private function parseIds(string $raw): array
    {
        $out = [];
        foreach (explode(',', $raw) as $piece) {
            $id = (int) trim($piece);
            if ($id > 0 && !in_array($id, $out, true)) {
                $out[] = $id;
            }
        }

        return $out;
    }

    /**
     * sortby → order 表达式（口径对齐参考系统 sortby 枚举）
     */
    private function orderBy(string $sortby): string
    {
        return match ($sortby) {
            'sales'      => 'sales desc, sort desc, id desc',
            'createtime' => 'createtime asc, id asc',
            'new'        => 'createtime desc, id desc',
            'rand'       => 'rand()',
            default      => 'sort desc, id desc',
        };
    }

    /**
     * 手动选品结果按传入 ids 顺序排列
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, int>                  $ids
     * @return array<int, array<string, mixed>>
     */
    private function sortByIds(array $rows, array $ids): array
    {
        $pos = array_flip($ids);
        usort($rows, static fn (array $a, array $b): int =>
            ($pos[(int) $a['id']] ?? PHP_INT_MAX) <=> ($pos[(int) $b['id']] ?? PHP_INT_MAX));

        return $rows;
    }

    /**
     * 行数据 → 组件展示口径（id/name/pic/price/market_price/sales + 域扩展字段）
     * @param array<string, mixed>      $conf
     * @param array<string, mixed>      $row
     * @return array<string, mixed>
     */
    private function format(string $temp, array $conf, array $row): array
    {
        $item = [
            'id'           => (int) ($row['id'] ?? 0),
            'name'         => (string) ($row['name'] ?? ''),
            'pic'          => (string) ($row['pic'] ?? ''),
            'price'        => '' !== $conf['price'] ? (float) ($row[$conf['price']] ?? 0) : 0.0,
            'market_price' => (float) ($row['market_price'] ?? 0),
            'sales'        => (int) ($row['sales'] ?? 0),
        ];

        // 域扩展字段（组件按需消费）
        switch ($temp) {
            case 'kanjia':
                $item['min_price'] = (float) ($row['min_price'] ?? 0);
                break;
            case 'scoreshop':
                $item['score_price'] = (int) ($row['score_price'] ?? 0);
                $item['money_price'] = (float) ($row['money_price'] ?? 0);
                break;
            case 'collage':
            case 'luckycollage':
                $item['teamnum'] = (int) ($row['teamnum'] ?? 0);
                break;
            case 'kecheng':
                $item['join_num'] = (int) ($row['join_num'] ?? 0);
                break;
            case 'article':
                $item['subname']   = (string) ($row['subname'] ?? '');
                $item['readcount'] = (int) ($row['readcount'] ?? 0);
                $item['createtime'] = (int) ($row['createtime'] ?? 0);
                break;
            default:
                break;
        }

        return $item;
    }
}
