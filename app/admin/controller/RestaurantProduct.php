<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\ScopeService;
use think\facade\Db;

/**
 * 租户后台 - 餐饮菜品管理（restaurant_product + restaurant_product_guige）
 *
 * 一期仅 product_type=0 普通菜品（称重/套餐字段保留不开放）；加料不开放。
 * 上架状态 status：1上架 / 2定时上架(start_time~end_time) / 3时段上架(start_hours~end_hours，支持跨天)
 * 多维上架判定 = status 时段计算 + status_week 周几（FIND_IN_SET）
 * 多分类 cid 为逗号串，筛选走 FIND_IN_SET
 */
class RestaurantProduct extends BaseController
{
    /**
     * 菜品列表（多维上架筛选：status+status_week；cid 逗号串 FIND_IN_SET）
     * GET /admin/dining/dish/list?page&pagesize&keyword&cid&status
     * status：1=在售（多维判定） 0=未在售 空=全部
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        $keyword  = trim((string) $this->request->get('keyword', ''));
        $cid      = (int) $this->request->get('cid', 0);
        $status   = trim((string) $this->request->get('status', ''));
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        $query = ScopeService::query('restaurant_product', $aid)->where('is_delete', 0);

        if ('' !== $keyword) {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('name', '%' . $keyword . '%')
                    ->whereOr('procode', 'like', '%' . $keyword . '%');
            });
        }
        if ($cid > 0) {
            $query->whereRaw('FIND_IN_SET(?, cid)', [$cid]);
        }

        // 多维上架筛选（status 时段 + status_week 周几）
        if ('1' === $status || '0' === $status) {
            [$raw, $bindings] = $this->onSaleRaw();
            if ('1' === $status) {
                $query->whereRaw($raw, $bindings);
            } else {
                $query->whereRaw('NOT (' . $raw . ')', $bindings);
            }
        }

        $total = (clone $query)->count();
        $rows  = $query->order('sort', 'desc')
            ->order('id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        // 分类/区域名称映射
        $catMap = $this->categoryMap($aid);
        $areaMap = ScopeService::query('restaurant_area', $aid)->column('name', 'id');

        foreach ($rows as &$row) {
            $row = $this->formatRow($row, $catMap, $areaMap);
        }
        unset($row);

        return $this->success([
            'list'     => $rows,
            'total'    => $total,
            'page'     => $page,
            'pagesize' => $pageSize,
        ]);
    }

    /**
     * 菜品详情（编辑用，含规格子表）
     * GET /admin/dining/dish/detail?id=
     */
    public function detail()
    {
        $aid = ScopeService::aidOf($this->request);

        $id = (int) $this->request->get('id', 0);
        if ($id <= 0) {
            throw new BusinessException('缺少菜品ID');
        }

        $row = ScopeService::query('restaurant_product', $aid)
            ->where('id', $id)
            ->where('is_delete', 0)
            ->find();
        if (empty($row)) {
            throw new BusinessException('菜品不存在');
        }

        $catMap  = $this->categoryMap($aid);
        $areaMap = ScopeService::query('restaurant_area', $aid)->column('name', 'id');
        $info    = $this->formatRow($row, $catMap, $areaMap);
        $info['detail'] = (string) ($row['detail'] ?? '');

        $guige = ScopeService::query('restaurant_product_guige', $aid)
            ->where('product_id', $id)
            ->order('id', 'asc')
            ->select()
            ->toArray();
        foreach ($guige as &$g) {
            $g['id']           = (int) $g['id'];
            $g['name']         = (string) $g['name'];
            $g['pic']          = (string) ($g['pic'] ?? '');
            $g['market_price'] = (float) $g['market_price'];
            $g['cost_price']   = (float) $g['cost_price'];
            $g['sell_price']   = (float) $g['sell_price'];
            $g['stock']        = (int) $g['stock'];
            $g['stock_daily']  = (int) $g['stock_daily'];
            $g['procode']      = (string) ($g['procode'] ?? '');
        }
        unset($g);
        $info['guige'] = $guige;

        return $this->success($info);
    }

    /**
     * 表单选项：菜品分类（层级）+ 出餐区域
     * GET /admin/dining/dish/options
     */
    public function options()
    {
        $aid = ScopeService::aidOf($this->request);

        $categories = ScopeService::query('restaurant_product_category', $aid)
            ->order('sort', 'desc')
            ->order('id', 'asc')
            ->field('id,pid,name,status')
            ->select()
            ->toArray();

        $areas = ScopeService::query('restaurant_area', $aid)
            ->where('status', 1)
            ->order('sort', 'desc')
            ->order('id', 'asc')
            ->field('id,name')
            ->select()
            ->toArray();

        return $this->success(['categories' => $categories, 'areas' => $areas]);
    }

    /**
     * 新增/编辑菜品（含规格子表全量替换）
     * POST /admin/dining/dish/save
     */
    public function save()
    {
        $aid = ScopeService::aidOf($this->request);

        $id   = (int) $this->request->post('id', 0);
        $name = trim((string) $this->request->post('name', ''));
        if ('' === $name) {
            throw new BusinessException('请填写菜品名称');
        }

        // 多分类（数组 → 逗号串）
        $cids = $this->normalizeIdArray($this->request->post('cid', []));
        if (empty($cids)) {
            throw new BusinessException('请选择菜品分类');
        }
        $catCount = ScopeService::query('restaurant_product_category', $aid)
            ->where('id', 'in', $cids)
            ->count();
        if ($catCount < count($cids)) {
            throw new BusinessException('所选菜品分类不存在');
        }

        $areaId = (int) $this->request->post('area_id', 0);
        if ($areaId > 0) {
            $areaExists = ScopeService::query('restaurant_area', $aid)->where('id', $areaId)->find();
            if (empty($areaExists)) {
                throw new BusinessException('出餐区域不存在');
            }
        }

        $status = (int) $this->request->post('status', 1);
        if (!in_array($status, [0, 1, 2, 3], true)) {
            $status = 1;
        }
        $startTime  = trim((string) $this->request->post('start_time', ''));
        $endTime    = trim((string) $this->request->post('end_time', ''));
        $startHours = trim((string) $this->request->post('start_hours', ''));
        $endHours   = trim((string) $this->request->post('end_hours', ''));
        if (2 === $status && ('' === $startTime || '' === $endTime)) {
            throw new BusinessException('定时上架需填写起止时间');
        }
        if (3 === $status) {
            if (!$this->isHm($startHours) || !$this->isHm($endHours)) {
                throw new BusinessException('时段上架需填写有效的起止时刻（如 09:00）');
            }
        }

        // 周几上架（数组 → 逗号串；空默认全周）
        $weeks = $this->normalizeWeekArray($this->request->post('status_week', []));

        // 规格子表
        $guigeRows = $this->normalizeGuige($this->request->post('guige', []));

        // 有规格时：售价取最低规格价，库存取规格合计（参考口径）
        $sellPrice   = max(0, (float) $this->request->post('sell_price', 0));
        $marketPrice = max(0, (float) $this->request->post('market_price', 0));
        $costPrice   = max(0, (float) $this->request->post('cost_price', 0));
        $stock       = max(0, (int) $this->request->post('stock', 0));
        $stockDaily  = max(0, (int) $this->request->post('stock_daily', 0));
        if (!empty($guigeRows)) {
            $minRow = $guigeRows[0];
            foreach ($guigeRows as $g) {
                if ($g['sell_price'] < $minRow['sell_price']) {
                    $minRow = $g;
                }
            }
            $sellPrice   = $minRow['sell_price'];
            $marketPrice = $minRow['market_price'];
            $costPrice   = $minRow['cost_price'];
            $stock       = array_sum(array_column($guigeRows, 'stock'));
            $stockDaily  = array_sum(array_column($guigeRows, 'stock_daily'));
        }

        // pics 多图（数组 → 逗号串）
        $pics = $this->request->post('pics', []);
        $pics = is_array($pics) ? implode(',', array_filter(array_map('trim', array_map('strval', $pics)), static fn ($v) => '' !== $v)) : trim((string) $pics);

        $data = [
            'name'         => $name,
            'procode'      => trim((string) $this->request->post('procode', '')),
            'sellpoint'    => trim((string) $this->request->post('sellpoint', '')),
            'pic'          => trim((string) $this->request->post('pic', '')),
            'pics'         => $pics,
            'cid'          => implode(',', $cids),
            'area_id'      => $areaId,
            'market_price' => $marketPrice,
            'sell_price'   => $sellPrice,
            'cost_price'   => $costPrice,
            'pack_fee'     => max(0, (float) $this->request->post('pack_fee', 0)),
            'sort'         => (int) $this->request->post('sort', 0),
            'status'       => $status,
            'status_week'  => implode(',', $weeks),
            'start_time'   => $startTime,
            'end_time'     => $endTime,
            'start_hours'  => $startHours,
            'end_hours'    => $endHours,
            'stock'        => $stock,
            'stock_daily'  => $stockDaily,
            'sales'        => max(0, (int) $this->request->post('sales', 0)),
            'limit_per'    => max(0, (int) $this->request->post('limit_per', 0)),
            'limit_takeaway' => max(0, (int) $this->request->post('limit_takeaway', 0)),
            'limit_start'  => max(0, (int) $this->request->post('limit_start', 0)),
            'product_type' => 0, // 一期仅普通菜品（称重/套餐字段保留不开放）
            'detail'       => (string) $this->request->post('detail', ''),
            'update_time'  => time(),
        ];

        $guigeProvided = null !== $this->request->post('guige', null);

        Db::transaction(function () use ($aid, &$id, $data, $guigeRows, $guigeProvided) {
            if ($id > 0) {
                $exists = ScopeService::query('restaurant_product', $aid)
                    ->where('id', $id)
                    ->where('is_delete', 0)
                    ->find();
                if (empty($exists)) {
                    throw new BusinessException('菜品不存在');
                }
                Db::name('restaurant_product')->where('id', $id)->update($data);
            } else {
                $data['aid']         = $aid;
                $data['bid']         = 0;
                $data['ischecked']   = 1;
                $data['create_time'] = time();
                $id = (int) Db::name('restaurant_product')->insertGetId($data);
            }

            // 规格子表：提供 guige 字段时全量替换
            if ($guigeProvided) {
                Db::name('restaurant_product_guige')->where('aid', $aid)->where('product_id', $id)->delete();
                if (!empty($guigeRows)) {
                    $insert = [];
                    foreach ($guigeRows as $g) {
                        $insert[] = [
                            'aid'          => $aid,
                            'product_id'   => $id,
                            'name'         => $g['name'],
                            'pic'          => $g['pic'],
                            'market_price' => $g['market_price'],
                            'cost_price'   => $g['cost_price'],
                            'sell_price'   => $g['sell_price'],
                            'stock'        => $g['stock'],
                            'stock_daily'  => $g['stock_daily'],
                            'procode'      => $g['procode'],
                        ];
                    }
                    Db::name('restaurant_product_guige')->insertAll($insert);
                }
            }
        });

        return $this->success(['id' => $id], '保存成功');
    }

    /**
     * 删除菜品（软删；有未结算订单时拒绝）
     * POST /admin/dining/dish/del {id}
     */
    public function del()
    {
        $aid = ScopeService::aidOf($this->request);

        $id = (int) $this->request->post('id', 0);
        if ($id <= 0) {
            throw new BusinessException('缺少菜品ID');
        }

        $row = ScopeService::query('restaurant_product', $aid)
            ->where('id', $id)
            ->where('is_delete', 0)
            ->find();
        if (empty($row)) {
            throw new BusinessException('菜品不存在');
        }

        // 未结算订单校验：status ∉ [3,4] 且 totalprice > 0
        $openCount = Db::name('restaurant_shop_order_goods')->alias('og')
            ->join('restaurant_shop_order o', 'o.id = og.orderid AND o.aid = og.aid')
            ->where('og.aid', $aid)
            ->where('og.proid', $id)
            ->whereNotIn('o.status', [3, 4])
            ->where('o.totalprice', '>', 0)
            ->count();
        if ($openCount > 0) {
            throw new BusinessException('该菜品存在未结算的订单，暂不能删除');
        }

        Db::name('restaurant_product')->where('id', $id)->update(['is_delete' => 1, 'update_time' => time()]);

        return $this->success([], '删除成功');
    }

    /**
     * 上架/下架（status 1/0）
     * POST /admin/dining/dish/setst {id, st}
     */
    public function setst()
    {
        $aid = ScopeService::aidOf($this->request);

        $id = (int) $this->request->post('id', 0);
        $st = (int) $this->request->post('st', 0);
        if (!in_array($st, [0, 1], true)) {
            throw new BusinessException('不支持的状态值');
        }

        $row = ScopeService::query('restaurant_product', $aid)
            ->where('id', $id)
            ->where('is_delete', 0)
            ->find();
        if (empty($row)) {
            throw new BusinessException('菜品不存在');
        }

        Db::name('restaurant_product')->where('id', $id)
            ->update(['status' => $st, 'update_time' => time()]);

        return $this->success([], 1 === $st ? '已上架' : '已下架');
    }

    /**
     * 改库存（stock/stock_daily）
     * POST /admin/dining/dish/setstock {id, stock, stock_daily}
     */
    public function setstock()
    {
        $aid = ScopeService::aidOf($this->request);

        $id         = (int) $this->request->post('id', 0);
        $stock      = max(0, (int) $this->request->post('stock', 0));
        $stockDaily = max(0, (int) $this->request->post('stock_daily', 0));

        $row = ScopeService::query('restaurant_product', $aid)
            ->where('id', $id)
            ->where('is_delete', 0)
            ->find();
        if (empty($row)) {
            throw new BusinessException('菜品不存在');
        }

        Db::name('restaurant_product')->where('id', $id)
            ->update(['stock' => $stock, 'stock_daily' => $stockDaily, 'update_time' => time()]);

        return $this->success([], '库存已更新');
    }

    /* ================= 私有辅助 ================= */

    /**
     * 多维上架 SQL 片段：status 时段判定 + status_week 周几（FIND_IN_SET）
     *
     * @return array{0: string, 1: array}
     */
    private function onSaleRaw(): array
    {
        $now = time();
        $hm  = date('H:i');
        $week = date('N'); // 1(周一)~7(周日)

        $raw = '('
            . '(`status` = 1'
            . ' OR (`status` = 2 AND `start_time` <> \'\' AND UNIX_TIMESTAMP(`start_time`) <= ? AND UNIX_TIMESTAMP(`end_time`) >= ?)'
            . ' OR (`status` = 3 AND ((`start_hours` < `end_hours` AND `start_hours` <= ? AND `end_hours` >= ?)'
            . ' OR (`start_hours` >= `end_hours` AND (`start_hours` <= ? OR `end_hours` >= ?)))))'
            . ' AND (IFNULL(`status_week`, \'\') = \'\' OR FIND_IN_SET(?, `status_week`))'
            . ')';

        return [$raw, [$now, $now, $hm, $hm, $hm, $hm, $week]];
    }

    /**
     * 单行 PHP 侧在售判定（列表展示用，与 SQL 口径一致）
     *
     * @param array<string, mixed> $row
     */
    private function isOnSale(array $row): bool
    {
        $status = (int) ($row['status'] ?? 0);
        $now    = time();

        $active = false;
        if (1 === $status) {
            $active = true;
        } elseif (2 === $status) {
            $st = strtotime((string) ($row['start_time'] ?? ''));
            $et = strtotime((string) ($row['end_time'] ?? ''));
            $active = false !== $st && false !== $et && $st <= $now && $et >= $now;
        } elseif (3 === $status) {
            $sh = (string) ($row['start_hours'] ?? '');
            $eh = (string) ($row['end_hours'] ?? '');
            $hm = date('H:i');
            if ('' !== $sh && '' !== $eh) {
                $active = $sh < $eh
                    ? ($sh <= $hm && $eh >= $hm)
                    : ($sh <= $hm || $eh >= $hm);
            }
        }

        if (!$active) {
            return false;
        }

        $weeks = trim((string) ($row['status_week'] ?? ''));
        if ('' === $weeks) {
            return true;
        }

        return in_array(date('N'), array_filter(explode(',', $weeks)), true);
    }

    /**
     * @param array<string, mixed>  $row
     * @param array<int, string>    $catMap
     * @param array<int, string>    $areaMap
     * @return array<string, mixed>
     */
    private function formatRow(array $row, array $catMap, array $areaMap): array
    {
        $cidStr = (string) ($row['cid'] ?? '');
        $cids   = array_values(array_filter(array_map('intval', explode(',', $cidStr)), static fn ($v) => $v > 0));
        $cidNames = [];
        foreach ($cids as $cid) {
            if (isset($catMap[$cid])) {
                $cidNames[] = $catMap[$cid];
            }
        }

        $areaId = (int) ($row['area_id'] ?? 0);
        $status = (int) ($row['status'] ?? 0);

        return [
            'id'           => (int) ($row['id'] ?? 0),
            'name'         => (string) ($row['name'] ?? ''),
            'procode'      => (string) ($row['procode'] ?? ''),
            'sellpoint'    => (string) ($row['sellpoint'] ?? ''),
            'pic'          => (string) ($row['pic'] ?? ''),
            'pics'         => array_values(array_filter(explode(',', (string) ($row['pics'] ?? '')), static fn ($v) => '' !== $v)),
            'cid'          => $cids,
            'cid_names'    => implode('，', $cidNames),
            'area_id'      => $areaId,
            'area_name'    => $areaMap[$areaId] ?? '',
            'market_price' => (float) ($row['market_price'] ?? 0),
            'sell_price'   => (float) ($row['sell_price'] ?? 0),
            'cost_price'   => (float) ($row['cost_price'] ?? 0),
            'pack_fee'     => (float) ($row['pack_fee'] ?? 0),
            'sales'        => (int) ($row['sales'] ?? 0),
            'stock'        => (int) ($row['stock'] ?? 0),
            'stock_daily'  => (int) ($row['stock_daily'] ?? 0),
            'limit_per'    => (int) ($row['limit_per'] ?? 0),
            'limit_takeaway' => (int) ($row['limit_takeaway'] ?? 0),
            'limit_start'  => (int) ($row['limit_start'] ?? 0),
            'status'       => $status,
            'status_text'  => [1 => '上架', 2 => '定时上架', 3 => '时段上架'][$status] ?? '下架',
            'status_week'  => array_values(array_filter(explode(',', (string) ($row['status_week'] ?? '')), static fn ($v) => '' !== $v)),
            'start_time'   => (string) ($row['start_time'] ?? ''),
            'end_time'     => (string) ($row['end_time'] ?? ''),
            'start_hours'  => (string) ($row['start_hours'] ?? ''),
            'end_hours'    => (string) ($row['end_hours'] ?? ''),
            'sort'         => (int) ($row['sort'] ?? 0),
            'product_type' => (int) ($row['product_type'] ?? 0),
            'onsale'       => $this->isOnSale($row) ? 1 : 0,
            'create_time'  => (int) ($row['create_time'] ?? 0),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function categoryMap(int $aid): array
    {
        return ScopeService::query('restaurant_product_category', $aid)
            ->column('name', 'id');
    }

    /**
     * @return int[]
     */
    private function normalizeIdArray(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }
        if (!is_array($raw)) {
            return [];
        }

        $ids = [];
        foreach ($raw as $v) {
            $v = (int) $v;
            if ($v > 0) {
                $ids[$v] = $v;
            }
        }

        return array_values($ids);
    }

    /**
     * @return string[]
     */
    private function normalizeWeekArray(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }
        if (!is_array($raw) || empty($raw)) {
            return ['1', '2', '3', '4', '5', '6', '7'];
        }

        $weeks = [];
        foreach ($raw as $v) {
            $v = (string) $v;
            if (in_array($v, ['1', '2', '3', '4', '5', '6', '7'], true)) {
                $weeks[$v] = $v;
            }
        }

        return empty($weeks) ? ['1', '2', '3', '4', '5', '6', '7'] : array_values($weeks);
    }

    /**
     * 规格行归一化（过滤空名行）
     *
     * @return array<int, array<string, mixed>>
     */
    private function normalizeGuige(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $rows = [];
        foreach ($raw as $g) {
            if (!is_array($g)) {
                continue;
            }
            $name = trim((string) ($g['name'] ?? ''));
            if ('' === $name) {
                continue;
            }
            $rows[] = [
                'name'         => $name,
                'pic'          => trim((string) ($g['pic'] ?? '')),
                'market_price' => max(0, (float) ($g['market_price'] ?? 0)),
                'cost_price'   => max(0, (float) ($g['cost_price'] ?? 0)),
                'sell_price'   => max(0, (float) ($g['sell_price'] ?? 0)),
                'stock'        => max(0, (int) ($g['stock'] ?? 0)),
                'stock_daily'  => max(0, (int) ($g['stock_daily'] ?? 0)),
                'procode'      => trim((string) ($g['procode'] ?? '')),
            ];
        }

        return $rows;
    }

    private function isHm(string $v): bool
    {
        return 1 === preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v);
    }
}
