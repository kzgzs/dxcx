<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\PlogService;
use app\common\service\ScopeService;
use think\facade\Db;

/**
 * 租户后台 - 商城商品分组（shop_group）
 *
 * 对照参考系统 ShopGroup 控制器：列表（名称/状态筛选+排序分页）/ 编辑回显 / 保存 / 删除
 * 仅平台可用（bid>0 的商户子账号拒绝，同参考系统 initialize 的 bid 校验）
 */
class ShopGroup extends BaseController
{
    /** 允许排序的字段（参考系统表头可排序列） */
    private const SORT_FIELDS = ['id', 'sort', 'createtime'];

    /**
     * 分组列表
     * GET /admin/shopGroup/list?page&pagesize&name&status&field&order
     */
    public function list()
    {
        $aid = $this->aid();
        $this->platformOnly();

        $page     = max(1, (int) $this->request->param('page', 1));
        $pagesize = max(1, min(100, (int) $this->request->param('pagesize', 15)));

        $query = ScopeService::query('shop_group', $aid);

        $name = trim((string) $this->request->param('name', ''));
        if ($name !== '') {
            $query->whereLike('name', '%' . $name . '%');
        }

        $status = $this->request->param('status', '');
        if ($status !== '' && $status !== null) {
            $query->where('status', (int) $status);
        }

        // 排序：默认 sort desc,id（同参考系统）
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
            $row['sort']        = (int) $row['sort'];
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
     * 分组详情（编辑回显）
     * GET /admin/shopGroup/detail?id=
     */
    public function detail()
    {
        $aid = $this->aid();
        $this->platformOnly();

        $id = (int) $this->request->param('id', 0);
        if ($id <= 0) {
            return $this->error('参数错误');
        }

        $info = ScopeService::query('shop_group', $aid)->where('id', $id)->find();
        if (empty($info)) {
            return $this->error('分组不存在');
        }

        return $this->success(['info' => $info]);
    }

    /**
     * 保存分组（新增/编辑）
     * POST /admin/shopGroup/save body:{id?, name, pic, sort, status}
     */
    public function save()
    {
        $aid = $this->aid();
        $this->platformOnly();

        $data = $this->body();

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return $this->error('请填写分组名称');
        }

        $row = [
            'name'   => $name,
            'pic'    => trim((string) ($data['pic'] ?? '')),
            'sort'   => (int) ($data['sort'] ?? 1),
            'status' => (int) ($data['status'] ?? 1) === 1 ? 1 : 0,
        ];

        $id = (int) ($data['id'] ?? 0);
        if ($id > 0) {
            $exists = ScopeService::query('shop_group', $aid)->where('id', $id)->find();
            if (empty($exists)) {
                return $this->error('分组不存在');
            }
            Db::name('shop_group')->where('id', $id)->where('aid', $aid)->update($row);
            PlogService::write($aid, $this->uid(), '编辑商城商品分组' . $id, $this->bid());
        } else {
            $row['aid']        = $aid;
            $row['createtime'] = time();
            $id                = (int) Db::name('shop_group')->insertGetId($row);
            PlogService::write($aid, $this->uid(), '添加商城商品分组' . $id, $this->bid());
        }

        return $this->success(['id' => $id], '操作成功');
    }

    /**
     * 删除分组（支持批量）
     * POST /admin/shopGroup/delete body:{ids:[..]}
     */
    public function delete()
    {
        $aid = $this->aid();
        $this->platformOnly();

        $ids = $this->ids($this->body());
        if (empty($ids)) {
            return $this->error('请选择数据');
        }

        ScopeService::query('shop_group', $aid)->whereIn('id', $ids)->delete();
        PlogService::write($aid, $this->uid(), '商城商品分组删除' . implode(',', $ids), $this->bid());

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

    /** 当前账号所属商户 ID（平台账号为 0） */
    protected function bid(): int
    {
        static $bid = null;
        if ($bid === null) {
            $bid = (int) Db::name('admin_user')->where('id', $this->uid())->value('bid');
        }
        return $bid;
    }

    /** 仅平台可用（同参考系统 if(bid>0) showmsg('无访问权限')） */
    protected function platformOnly(): void
    {
        if ($this->bid() > 0) {
            throw new \app\common\exception\BusinessException('无访问权限', 403, 403);
        }
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
