<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\PlogService;
use app\common\service\ScopeService;
use think\facade\Db;

/**
 * 租户后台 - 商城商品参数（shop_param）
 *
 * 对照参考系统 ShopParam 控制器：
 * 列表（名称/状态/分类 find_in_set 筛选 + 排序分页，catname 分类名拼接）
 * 编辑回显（cid 逗号串转数组 + params JSON 转数组 + 分类三级树）
 * 保存（cid 数组转逗号串 + params 数组转 JSON）/ 删除
 * type: 0 文本 1 单选 2 多选；params 选项 JSON
 */
class ShopParam extends BaseController
{
    private const SORT_FIELDS = ['id', 'sort', 'createtime'];

    /**
     * 参数列表
     * GET /admin/shopParam/list?page&pagesize&name&status&cid&field&order
     */
    public function list()
    {
        $aid = $this->aid();

        $page     = max(1, (int) $this->request->param('page', 1));
        $pagesize = max(1, min(100, (int) $this->request->param('pagesize', 15)));

        $query = ScopeService::query('shop_param', $aid);

        $name = trim((string) $this->request->param('name', ''));
        if ($name !== '') {
            $query->whereLike('name', '%' . $name . '%');
        }

        $status = $this->request->param('status', '');
        if ($status !== '' && $status !== null) {
            $query->where('status', (int) $status);
        }

        // 分类筛选（同参考系统 find_in_set(cid)）
        $cid = (int) $this->request->param('cid', 0);
        if ($cid > 0) {
            $query->whereRaw('FIND_IN_SET(' . $cid . ', cid)');
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

        // 分类名映射（同参考系统 formatClist）
        $catmap = ScopeService::query('shop_category', $aid)->where('deleted', 0)->column('name', 'id');

        foreach ($rows as &$row) {
            $row['id']         = (int) $row['id'];
            $row['type']       = (int) $row['type'];
            $row['is_required'] = (int) $row['is_required'];
            $row['sort']       = (int) ($row['sort'] ?? 0);
            $row['status']     = (int) $row['status'];
            $row['createtime'] = (int) ($row['createtime'] ?? 0);
            $row['create_time'] = $row['createtime'] > 0 ? date('Y-m-d H:i', $row['createtime']) : '';

            // 所属分类名（catname）
            $names = [];
            foreach (array_filter(explode(',', (string) $row['cid'])) as $c) {
                if (isset($catmap[(int) $c])) {
                    $names[] = $catmap[(int) $c];
                }
            }
            $row['catname'] = implode(' ', $names);
        }
        unset($row);

        return $this->success([
            'list'  => $rows,
            'total' => (int) $total,
        ]);
    }

    /**
     * 分类三级树（参数编辑页分类多选数据源，同参考系统 clist 结构）
     * GET /admin/shopParam/categoryTree
     */
    public function categoryTree()
    {
        $aid = $this->aid();

        $all = ScopeService::query('shop_category', $aid)
            ->where('deleted', 0)
            ->field('id, pid, name')
            ->order('sort', 'desc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        $byPid = [];
        foreach ($all as $row) {
            $byPid[(int) $row['pid']][] = ['id' => (int) $row['id'], 'name' => (string) $row['name']];
        }

        $tree = [];
        foreach ($byPid[0] ?? [] as $one) {
            $one['child'] = [];
            foreach ($byPid[$one['id']] ?? [] as $two) {
                $two['child'] = $byPid[$two['id']] ?? [];
                $one['child'][] = $two;
            }
            $tree[] = $one;
        }

        return $this->success(['tree' => $tree]);
    }

    /**
     * 参数详情（编辑回显：cid 转数组，params JSON 转数组）
     * GET /admin/shopParam/detail?id=
     */
    public function detail()
    {
        $aid = $this->aid();

        $id = (int) $this->request->param('id', 0);
        if ($id <= 0) {
            return $this->error('参数错误');
        }

        $info = ScopeService::query('shop_param', $aid)->where('id', $id)->find();
        if (empty($info)) {
            return $this->error('参数不存在');
        }

        $info['id']     = (int) $info['id'];
        $info['type']   = (int) $info['type'];
        $info['status'] = (int) $info['status'];
        $info['is_required'] = (int) $info['is_required'];
        $info['sort']   = (int) ($info['sort'] ?? 0);
        $info['cid']    = array_values(array_filter(explode(',', (string) $info['cid'])));

        $params = json_decode((string) $info['params'], true);
        $info['params'] = is_array($params) ? $params : [];

        return $this->success(['info' => $info]);
    }

    /**
     * 保存参数（新增/编辑）
     * POST /admin/shopParam/save body:{id?, name, cid:[], type, params:["选项",..], tips, is_required, sort, status}
     */
    public function save()
    {
        $aid = $this->aid();

        $data = $this->body();

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return $this->error('请填写参数名称');
        }

        // 分类多选（数组或逗号串）
        $cid = $data['cid'] ?? [];
        if (is_string($cid)) {
            $cid = explode(',', $cid);
        }
        if (!is_array($cid) || empty($cid)) {
            return $this->error('请选择所属分类');
        }
        $cid = implode(',', array_values(array_unique(array_filter(array_map('intval', $cid), static fn ($v) => $v > 0))));
        if ($cid === '') {
            return $this->error('请选择所属分类');
        }

        $type = (int) ($data['type'] ?? 0);
        if (!in_array($type, [0, 1, 2], true)) {
            return $this->error('参数类型不正确');
        }

        // 选项数据（单选/多选必填，文本可空；对齐参考系统：params 为字符串数组）
        $params = $data['params'] ?? [];
        if (is_string($params)) {
            $decoded = json_decode($params, true);
            $params  = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($params)) {
            $params = [];
        }
        // 过滤空选项行：兼容字符串与 {title|name|value} 对象两种提交
        $params = array_values(array_filter(array_map(static function ($item) {
            if (is_array($item)) {
                return trim((string) ($item['title'] ?? $item['name'] ?? $item['value'] ?? ''));
            }

            return trim((string) $item);
        }, $params), static fn ($v) => $v !== ''));
        if ($type > 0 && empty($params)) {
            return $this->error('单选/多选类型必须填写选项');
        }

        $row = [
            'name'         => $name,
            'cid'          => $cid,
            'type'         => $type,
            'params'       => json_encode($params, JSON_UNESCAPED_UNICODE),
            'tips'         => trim((string) ($data['tips'] ?? '')),
            'is_required'  => (int) ($data['is_required'] ?? 0) === 1 ? 1 : 0,
            'sort'         => (int) ($data['sort'] ?? 0),
            'status'       => (int) ($data['status'] ?? 1) === 1 ? 1 : 0,
        ];

        $id = (int) ($data['id'] ?? 0);
        if ($id > 0) {
            $exists = ScopeService::query('shop_param', $aid)->where('id', $id)->find();
            if (empty($exists)) {
                return $this->error('参数不存在');
            }
            Db::name('shop_param')->where('id', $id)->where('aid', $aid)->update($row);
            PlogService::write($aid, $this->uid(), '编辑商品参数' . $id, $this->bid());
        } else {
            $row['aid']        = $aid;
            $row['bid']        = $this->bid();
            $row['createtime'] = time();
            $id                = (int) Db::name('shop_param')->insertGetId($row);
            PlogService::write($aid, $this->uid(), '添加商品参数' . $id, $this->bid());
        }

        return $this->success(['id' => $id], '操作成功');
    }

    /**
     * 删除参数（支持批量）
     * POST /admin/shopParam/delete body:{ids:[..]}
     */
    public function delete()
    {
        $aid = $this->aid();

        $ids = $this->ids($this->body());
        if (empty($ids)) {
            return $this->error('请选择数据');
        }

        ScopeService::query('shop_param', $aid)->whereIn('id', $ids)->delete();
        PlogService::write($aid, $this->uid(), '商品参数删除' . implode(',', $ids), $this->bid());

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
