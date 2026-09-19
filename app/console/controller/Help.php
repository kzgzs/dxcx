<?php

declare(strict_types=1);

namespace app\console\controller;

use app\BaseController;
use think\facade\Db;

/**
 * 超管控制台 - 帮助中心（文章管理）
 *
 * 对照参考系统 WebHelp 控制器 + web_help/index.html：
 * 列表（标题/状态筛选、排序、分页）+ 添加/编辑 + 改状态 + 删除
 * 数据来源：help 表（迁移 20260812000011_core_tables 已建）
 */
class Help extends BaseController
{
    /** 允许排序的字段（参考系统表头可排序列） */
    private const SORT_FIELDS = ['id', 'readcount', 'sort', 'createtime'];

    /**
     * 文章列表（筛选 + 排序 + 分页）
     * GET /console/help/list
     * 参数：page pagesize name status field order
     */
    public function list()
    {
        $page     = max(1, (int) $this->request->param('page', 1));
        $pagesize = (int) $this->request->param('pagesize', 15);
        $pagesize = max(1, min(100, $pagesize));

        $query = Db::name('help');

        // 标题（模糊，参考系统检索项）
        $name = trim((string) $this->request->param('name', ''));
        if ($name !== '') {
            $query->whereLike('name', '%' . $name . '%');
        }

        // 状态：全部/开启/关闭（参考系统检索项）
        $status = $this->request->param('status', '');
        if ($status !== '' && $status !== null) {
            $query->where('status', (int) $status);
        }

        // 排序：默认 sort desc,id（同参考系统）
        $field = (string) $this->request->param('field', '');
        $order = strtolower((string) $this->request->param('order', ''));
        if ($field !== '' && in_array($field, self::SORT_FIELDS, true) && in_array($order, ['asc', 'desc'], true)) {
            $query->order($field, $order);
        } else {
            $query->order('sort', 'desc')->order('id', 'desc');
        }

        $total = (clone $query)->count();
        $rows  = $query
            ->field('id,name,readcount,sort,createtime,status')
            ->page($page, $pagesize)
            ->select()
            ->toArray();

        $list = array_map(static function ($row) {
            $row['id']         = (int) $row['id'];
            $row['readcount']  = (int) $row['readcount'];
            $row['sort']       = (int) $row['sort'];
            $row['createtime'] = (int) $row['createtime'];
            $row['status']     = (int) $row['status'];

            return $row;
        }, $rows);

        return $this->success([
            'list'  => $list,
            'total' => (int) $total,
        ]);
    }

    /**
     * 文章详情（编辑回显用）
     * GET /console/help/detail?id=xx
     */
    public function detail()
    {
        $id = (int) $this->request->param('id', 0);
        if ($id <= 0) {
            return $this->error('参数错误');
        }

        $row = Db::name('help')
            ->field('id,name,content,readcount,sort,status')
            ->where('id', $id)
            ->find();
        if (empty($row)) {
            return $this->error('文章不存在');
        }

        $row['id']        = (int) $row['id'];
        $row['readcount'] = (int) $row['readcount'];
        $row['sort']      = (int) $row['sort'];
        $row['status']    = (int) $row['status'];

        return $this->success($row);
    }

    /**
     * 添加 / 编辑文章（当页保存，不刷新不跳转）
     * POST /console/help/save
     * body：{id?, name, content, readcount, sort, status}
     */
    public function save()
    {
        $body = $this->body();

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            return $this->error('请输入文章标题');
        }
        if (mb_strlen($name) > 255) {
            return $this->error('文章标题过长');
        }

        $data = [
            'name'      => $name,
            'content'   => (string) ($body['content'] ?? ''),
            'readcount' => max(0, (int) ($body['readcount'] ?? 0)),
            'sort'      => (int) ($body['sort'] ?? 0),
            'status'    => (int) ($body['status'] ?? 0) === 1 ? 1 : 0,
        ];

        $id = (int) ($body['id'] ?? 0);
        if ($id > 0) {
            $exists = Db::name('help')->where('id', $id)->find();
            if (empty($exists)) {
                return $this->error('文章不存在');
            }
            Db::name('help')->where('id', $id)->update($data);
        } else {
            $data['createtime'] = time();
            $id = (int) Db::name('help')->insertGetId($data);
        }

        return $this->success(['id' => $id], '操作成功');
    }

    /**
     * 改状态（支持批量：参考系统「开启/关闭」按钮与行内状态切换共用）
     * POST /console/help/setst  body：{ids:[..], st:0|1}
     */
    public function setst()
    {
        $body = $this->body();
        $ids  = $this->ids($body);
        if (empty($ids)) {
            return $this->error('请选择数据');
        }

        $st = (int) ($body['st'] ?? 0) === 1 ? 1 : 0;
        Db::name('help')->whereIn('id', $ids)->update(['status' => $st]);

        return $this->success([], '操作成功');
    }

    /**
     * 删除（支持批量）
     * POST /console/help/delete  body：{ids:[..]}
     */
    public function delete()
    {
        $ids = $this->ids($this->body());
        if (empty($ids)) {
            return $this->error('请选择数据');
        }

        Db::name('help')->whereIn('id', $ids)->delete();

        return $this->success([], '删除成功');
    }

    /**
     * 提取 ids 数组（兼容 [1,2] 与 "1,2"）
     */
    private function ids(array $body): array
    {
        $ids = $body['ids'] ?? [];
        if (is_string($ids)) {
            $ids = explode(',', $ids);
        }
        if (!is_array($ids)) {
            return [];
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function ($v) {
            return $v > 0;
        })));

        return $ids;
    }

    /**
     * 请求体（兼容 JSON 与表单）
     */
    protected function body(): array
    {
        $data = $this->request->post();

        return is_array($data) ? $data : [];
    }
}
