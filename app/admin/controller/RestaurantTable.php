<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\ScopeService;
use think\facade\Db;

/**
 * 租户后台 - 餐饮餐桌管理（admin 应用，JWT role=admin，aid 走 ScopeService）
 *
 * 餐桌状态机（status）：-1禁用 / 0空闲 / 1预定 / 2入座 / 3清台
 * 关键约束：改为空闲(0)或清台(3)前，必须确保该桌当前管理订单已结算
 *   （restaurant_shop_order.status ∉ [3,4] 且 totalprice > 0 视为未结算，拦截）
 *   改为空闲/清台成功后 orderid 归 0。
 * 桌码二维码：qrcode 内容为指向点餐页的 URL（携带 tableId）。
 */
class RestaurantTable extends BaseController
{
    /** 餐桌合法状态集合 */
    private const STATUSES = [-1, 0, 1, 2, 3];

    /** 批量建桌单次上限，避免误操作刷库 */
    private const MAX_BATCH = 200;

    /**
     * 餐桌列表
     * GET /admin/dining/table/list?keyword=&cid=&status=&page&pagesize
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        $keyword  = trim((string) $this->request->get('keyword', ''));
        $cid      = (int) $this->request->get('cid', 0);
        $status   = $this->request->get('status', '');
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        $query = ScopeService::queryAs('restaurant_table', $aid, 't')
            ->leftJoin('restaurant_table_category c', 'c.id = t.cid')
            ->field('t.*, c.name as category_name');

        if ('' !== $keyword) {
            $query->where('t.name', 'like', '%' . $keyword . '%');
        }
        if ($cid > 0) {
            $query->where('t.cid', $cid);
        }
        if ('' !== $status && '' !== trim((string) $status)) {
            $query->where('t.status', (int) $status);
        }

        $total = (clone $query)->count();
        $rows  = $query->order('t.sort', 'desc')
            ->order('t.id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        foreach ($rows as &$row) {
            $row = $this->formatRow($row);
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
     * 新增/编辑餐桌
     * POST /admin/dining/table/save
     * 编辑时若状态变更为空闲/清台，同样走未结算订单校验
     */
    public function save()
    {
        $aid = ScopeService::aidOf($this->request);

        $id     = (int) $this->request->post('id', 0);
        $name   = trim((string) $this->request->post('name', ''));
        $cid    = (int) $this->request->post('cid', 0);
        $seat   = max(0, (int) $this->request->post('seat', 0));
        $canbook = (int) $this->request->post('canbook', 1) ? 1 : 0;
        $sort   = (int) $this->request->post('sort', 0);
        $status = (int) $this->request->post('status', 0);
        $pic    = trim((string) $this->request->post('pic', ''));
        $printIds = $this->normalizeIdList($this->request->post('print_ids', []));

        if ('' === $name) {
            throw new BusinessException('请填写餐桌名称');
        }
        if (!in_array($status, self::STATUSES, true)) {
            throw new BusinessException('不支持的餐桌状态');
        }

        $now = time();

        if ($id > 0) {
            $table = $this->findTable($aid, $id);

            // 状态变更且目标为空闲/清台时，先校验未结算订单
            $oldStatus = (int) $table['status'];
            if ($status !== $oldStatus && in_array($status, [0, 3], true)) {
                $this->assertSettled($aid, $table);
            }

            $update = [
                'name'       => $name,
                'cid'        => $cid,
                'seat'       => $seat,
                'canbook'    => $canbook,
                'sort'       => $sort,
                'pic'        => $pic,
                'print_ids'  => $printIds,
                'status'     => $status,
                'update_time' => $now,
            ];
            // 空闲/清台时解除订单绑定
            if (in_array($status, [0, 3], true)) {
                $update['orderid'] = 0;
            }

            Db::name('restaurant_table')->where('id', $id)->update($update);

            return $this->success(['id' => $id], '保存成功');
        }

        $tableId = (int) Db::name('restaurant_table')->insertGetId([
            'aid'         => $aid,
            'bid'         => 0,
            'cid'         => $cid,
            'name'        => $name,
            'seat'        => $seat,
            'canbook'     => $canbook,
            'sort'        => $sort,
            'pic'         => $pic,
            'print_ids'   => $printIds,
            'status'      => 0,
            'orderid'     => 0,
            'qrcode'      => '',
            'create_time' => $now,
            'update_time' => $now,
        ]);

        // 生成桌码（点餐页 URL）
        Db::name('restaurant_table')->where('id', $tableId)
            ->update(['qrcode' => $this->buildOrderUrl($tableId)]);

        return $this->success(['id' => $tableId], '创建成功');
    }

    /**
     * 删除餐桌（存在未结算订单时拒绝）
     * POST /admin/dining/table/del {id}
     */
    public function del()
    {
        $aid = ScopeService::aidOf($this->request);

        $id    = (int) $this->request->post('id', 0);
        $table = $this->findTable($aid, $id);

        $this->assertSettled($aid, $table);

        Db::name('restaurant_table')->where('id', $id)->delete();

        return $this->success([], '删除成功');
    }

    /**
     * 修改餐桌状态（状态机）
     * POST /admin/dining/table/setst {id, st}
     */
    public function setst()
    {
        $aid = ScopeService::aidOf($this->request);

        $id    = (int) $this->request->post('id', 0);
        $st    = (int) $this->request->post('st', 0);
        $table = $this->findTable($aid, $id);

        if (!in_array($st, self::STATUSES, true)) {
            throw new BusinessException('不支持的餐桌状态');
        }

        // 改为空闲/清台前校验未结算订单，成功后 orderid 归 0
        if (in_array($st, [0, 3], true)) {
            $this->assertSettled($aid, $table);
            Db::name('restaurant_table')->where('id', $id)
                ->update(['status' => $st, 'orderid' => 0, 'update_time' => time()]);
        } else {
            Db::name('restaurant_table')->where('id', $id)
                ->update(['status' => $st, 'update_time' => time()]);
        }

        return $this->success([], '操作成功');
    }

    /**
     * 批量建桌（数量/前缀/起始号/排除数字跳号/分类/座位数/可否预定）
     * POST /admin/dining/table/create
     */
    public function createTables()
    {
        $aid = ScopeService::aidOf($this->request);

        $makeCount = (int) $this->request->post('makecount', 0);
        $prefix    = trim((string) $this->request->post('prefix', ''));
        $start     = max(1, (int) $this->request->post('start', 1));
        $notHave   = trim((string) $this->request->post('not_have_number', ''));
        $cid       = (int) $this->request->post('cid', 0);
        $seat      = max(0, (int) $this->request->post('seat', 0));
        $canbook   = (int) $this->request->post('canbook', 0) ? 1 : 0;
        $printIds  = $this->normalizeIdList($this->request->post('print_ids', []));

        if ($makeCount <= 0) {
            throw new BusinessException('请输入桌台数量');
        }
        if ($makeCount > self::MAX_BATCH) {
            throw new BusinessException('单次最多创建 ' . self::MAX_BATCH . ' 张桌台');
        }
        if ($cid <= 0) {
            throw new BusinessException('请选择餐桌分类');
        }
        $category = ScopeService::query('restaurant_table_category', $aid)->where('id', $cid)->find();
        if (empty($category)) {
            throw new BusinessException('餐桌分类不存在');
        }

        // 排除数字（如 4,7），命中则跳号
        $notHaveArr = array_values(array_filter(array_map('trim', explode(',', $notHave)), static fn ($v) => '' !== $v));

        $numbers = [];
        $cursor  = $start;
        $guard   = 0;
        while (count($numbers) < $makeCount) {
            if (++$guard > 100000) {
                throw new BusinessException('跳号规则导致无法生成足够桌号，请调整排除数字');
            }
            if ($this->hitExcluded($cursor, $notHaveArr)) {
                $cursor++;
                continue;
            }
            $numbers[] = $cursor;
            $cursor++;
        }

        $now    = time();
        $insert = [];
        foreach ($numbers as $num) {
            // 小于 10 补零，对齐参考系统桌号习惯（A01/A02...）
            $numText = $num < 10 ? '0' . $num : (string) $num;
            $insert[] = [
                'aid'         => $aid,
                'bid'         => 0,
                'cid'         => $cid,
                'name'        => $prefix . $numText,
                'seat'        => $seat,
                'canbook'     => $canbook,
                'print_ids'   => $printIds,
                'status'      => 0,
                'orderid'     => 0,
                'create_time' => $now,
                'update_time' => $now,
            ];
        }

        Db::name('restaurant_table')->insertAll($insert);

        // 回填新建桌台的桌码
        $newIds = Db::name('restaurant_table')
            ->where('aid', $aid)
            ->where('create_time', $now)
            ->where('qrcode', '')
            ->column('id');
        foreach ($newIds as $newId) {
            Db::name('restaurant_table')->where('id', $newId)
                ->update(['qrcode' => $this->buildOrderUrl((int) $newId)]);
        }

        return $this->success(['count' => count($insert)], '创建成功');
    }

    /**
     * 桌码二维码内容（点餐页 URL，携带 tableId）；为空则即时生成并回写
     * GET /admin/dining/table/qrcode?id=
     */
    public function qrcode()
    {
        $aid = ScopeService::aidOf($this->request);

        $id    = (int) $this->request->get('id', 0);
        $table = $this->findTable($aid, $id);

        $qrcode = trim((string) ($table['qrcode'] ?? ''));
        if ('' === $qrcode) {
            $qrcode = $this->buildOrderUrl($id);
            Db::name('restaurant_table')->where('id', $id)->update(['qrcode' => $qrcode]);
        }

        return $this->success(['id' => $id, 'qrcode' => $qrcode, 'name' => (string) ($table['name'] ?? '')]);
    }

    /* ================= 私有辅助 ================= */

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatRow(array $row): array
    {
        $status = (int) ($row['status'] ?? 0);

        return [
            'id'            => (int) ($row['id'] ?? 0),
            'cid'           => (int) ($row['cid'] ?? 0),
            'category_name' => (string) ($row['category_name'] ?? ''),
            'name'          => (string) ($row['name'] ?? ''),
            'pic'           => (string) ($row['pic'] ?? ''),
            'seat'          => (int) ($row['seat'] ?? 0),
            'canbook'       => (int) ($row['canbook'] ?? 1),
            'status'        => $status,
            'status_text'   => $this->statusText($status),
            'orderid'       => (int) ($row['orderid'] ?? 0),
            'qrcode'        => (string) ($row['qrcode'] ?? ''),
            'print_ids'     => $this->splitIdList((string) ($row['print_ids'] ?? '')),
            'sort'          => (int) ($row['sort'] ?? 0),
            'create_time'   => (int) ($row['create_time'] ?? 0),
        ];
    }

    private function statusText(int $status): string
    {
        return match ($status) {
            -1 => '禁用',
            0 => '空闲',
            1 => '预定',
            2 => '入座',
            3 => '清台',
            default => '未知',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function findTable(int $aid, int $id): array
    {
        if ($id <= 0) {
            throw new BusinessException('缺少餐桌ID');
        }

        $table = ScopeService::query('restaurant_table', $aid)->where('id', $id)->find();
        if (empty($table)) {
            throw new BusinessException('餐桌不存在');
        }

        return $table;
    }

    /**
     * 校验餐桌当前管理订单是否已结算；未结算则拦截
     * 未结算口径：status ∉ [3,4] 且 totalprice > 0
     *
     * @param array<string, mixed> $table
     */
    private function assertSettled(int $aid, array $table): void
    {
        $orderId = (int) ($table['orderid'] ?? 0);
        if ($orderId <= 0) {
            return;
        }

        $order = ScopeService::query('restaurant_shop_order', $aid)
            ->where('id', $orderId)
            ->where('tableid', (int) $table['id'])
            ->find();

        if (!empty($order)
            && !in_array((int) $order['status'], [3, 4], true)
            && (float) $order['totalprice'] > 0
        ) {
            throw new BusinessException('请先完成点餐订单 ' . ($order['ordernum'] ?: '#' . $order['id']) . ' 的结算，才可以将餐桌改为空闲或清台');
        }
    }

    /**
     * 判断号码是否命中排除数字（号码字符串包含任一排除数字即跳过）
     *
     * @param string[] $notHaveArr
     */
    private function hitExcluded(int $num, array $notHaveArr): bool
    {
        $numText = (string) $num;
        foreach ($notHaveArr as $ex) {
            if ('' !== $ex && str_contains($numText, $ex)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 桌码内容：点餐页 URL（携带 tableId）
     * 客户端部署基址读平台级配置 sys_system_client_base，缺省取当前域名下 /client/
     */
    private function buildOrderUrl(int $tableId): string
    {
        $base = trim(\app\common\service\TenantConfigService::get(0, 'sys_system_client_base', ''));
        if ('' === $base) {
            $base = $this->request->domain() . '/client';
        }

        return rtrim($base, '/') . '/#/restaurant/index/index?tableId=' . $tableId;
    }

    /**
     * 归一化 ID 数组为逗号串（打印机关联等）
     */
    private function normalizeIdList(mixed $raw): string
    {
        if (is_string($raw)) {
            $raw = array_filter(array_map('trim', explode(',', $raw)), static fn ($v) => '' !== $v);
        }
        if (!is_array($raw)) {
            return '';
        }

        $ids = [];
        foreach ($raw as $v) {
            $v = (int) $v;
            if ($v > 0) {
                $ids[$v] = $v;
            }
        }

        return implode(',', array_values($ids));
    }

    /**
     * @return int[]
     */
    private function splitIdList(string $raw): array
    {
        if ('' === trim($raw)) {
            return [];
        }

        return array_values(array_filter(array_map('intval', explode(',', $raw)), static fn ($v) => $v > 0));
    }
}
