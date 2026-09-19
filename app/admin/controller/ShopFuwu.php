<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\PlogService;
use app\common\service\ScopeService;
use think\facade\Db;

/**
 * 租户后台 - 商城商品服务（shop_fuwu）
 *
 * 对照参考系统 ShopFuwu 控制器：列表（名称/状态筛选 + 排序分页）/ 编辑回显 / 保存 / 删除
 * 字段：name 服务名称 / desc 服务说明 / sort / status
 */
class ShopFuwu extends BaseController
{
    private const SORT_FIELDS = ['id', 'sort', 'createtime'];

    /**
     * 服务列表
     * GET /admin/shopFuwu/list?page&pagesize&name&status&field&order
     */
    public function list()
    {
        $aid = $this->aid();

        $page     = max(1, (int) $this->request->param('page', 1));
        $pagesize = max(1, min(100, (int) $this->request->param('pagesize', 15)));

        $query = ScopeService::query('shop_fuwu', $aid);

        $name = trim((string) $this->request->param('name', ''));
        if ($name !== '') {
            $query->whereLike('name', '%' . $name . '%');
        }

        $status = $this->request->param('status', '');
        if ($status !== '' && $status !== null) {
            $query->where('status', (int) $status);
        }

        $field = (string) $this->request->param('field', '');
        $order = strtolower((string) $this->request->param('order', ''));
        if ($field !== '' && in_array($field, self::SORT_FIELDS, true) && in_array($order, ['asc', 'desc'], true)) {
            $query->order($field, $order)->order('id', 'desc');
        } else {
            $query->order('sort', 'desc')->order('id', 'desc');
        }

        $total = (clone $query)->count();
        $rows  = $query->page($page, $pagesize)->select()->toArray();

        foreach ($rows as &$row) {
            $row['id']          = (int) $row['id'];
            $row['status']      = (int) $row['status'];
            $row['sort']        = (int) ($row['sort'] ?? 0);
            $row['createtime']  = (int) ($row['createtime'] ?? 0);
            $row['create_time'] = $row['createtime'] > 0 ? date('Y-m-d H:i', $row['createtime']) : '';
        }
        unset($row);

        return $this->success([
            'list'  => $rows,
            'total' => (int) $total,
        ]);
    }

    /**
     * 服务详情（编辑回显）
     * GET /admin/shopFuwu/detail?id=
     */
    public function detail()
    {
        $aid = $this->aid();

        $id = (int) $this->request->param('id', 0);
        if ($id <= 0) {
            return $this->error('参数错误');
        }

        $info = ScopeService::query('shop_fuwu', $aid)->where('id', $id)->find();
        if (empty($info)) {
            return $this->error('服务不存在');
        }

        return $this->success(['info' => $info]);
    }

    /**
     * 保存服务（新增/编辑）
     * POST /admin/shopFuwu/save body:{id?, name, desc, sort, status}
     */
    public function save()
    {
        $aid = $this->aid();

        $data = $this->body();

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return $this->error('请填写服务名称');
        }

        $row = [
            'name'   => $name,
            'desc'   => trim((string) ($data['desc'] ?? '')),
            'sort'   => (int) ($data['sort'] ?? 1),
            'status' => (int) ($data['status'] ?? 1) === 1 ? 1 : 0,
        ];

        $id = (int) ($data['id'] ?? 0);
        if ($id > 0) {
            $exists = ScopeService::query('shop_fuwu', $aid)->where('id', $id)->find();
            if (empty($exists)) {
                return $this->error('服务不存在');
            }
            Db::name('shop_fuwu')->where('id', $id)->where('aid', $aid)->update($row);
            PlogService::write($aid, $this->uid(), '编辑商城商品服务' . $id, $this->bid());
        } else {
            $row['aid']        = $aid;
            $row['bid']        = $this->bid();
            $row['createtime'] = time();
            $id                = (int) Db::name('shop_fuwu')->insertGetId($row);
            PlogService::write($aid, $this->uid(), '添加商城商品服务' . $id, $this->bid());
        }

        return $this->success(['id' => $id], '操作成功');
    }

    /**
     * 删除服务（支持批量）
     * POST /admin/shopFuwu/delete body:{ids:[..]}
     */
    public function delete()
    {
        $aid = $this->aid();

        $ids = $this->ids($this->body());
        if (empty($ids)) {
            return $this->error('请选择数据');
        }

        ScopeService::query('shop_fuwu', $aid)->whereIn('id', $ids)->delete();
        PlogService::write($aid, $this->uid(), '商城商品服务删除' . implode(',', $ids), $this->bid());

        return $this->success([], '删除成功');
    }

    // ---------- 工具 ----------

    protected function aid(): int
    {
        return ScopeService::aidOf($this->request);
    }

    protected function uid(): int
    {
        return (int) ($this->request->authUid ?? 0);
    }

    protected function bid(): int
    {
        static $bid = null;
        if ($bid === null) {
            $bid = (int) Db::name('admin_user')->where('id', $this->uid())->value('bid');
        }
        return $bid;
    }

    protected function body(): array
    {
        $data = $this->request->post();
        return is_array($data) ? $data : [];
    }

    protected function ids(array $body): array
    {
        $ids = $body['ids'] ?? [];
        if (is_string($ids)) {
            $ids = explode(',', $ids);
        }
        if (!is_array($ids)) {
            return [];
        }
        return array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($v) => $v > 0)));
    }
}
