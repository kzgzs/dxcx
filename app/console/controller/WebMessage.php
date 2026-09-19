<?php

declare(strict_types=1);

namespace app\console\controller;

use app\BaseController;
use think\facade\Db;

/**
 * 超管控制台 - 网站留言
 *
 * 对照参考系统 WebMessage 控制器 + web_message/index.html：
 * 列表（联系人/联系方式/提交时间/状态筛选、排序、分页）+ 改状态 + 删除
 * 数据来源：webmessage 表（迁移 20260812000012_misc_tables 已建）
 * status: 0未处理 1已处理
 */
class WebMessage extends BaseController
{
    /** 允许排序的字段（参考系统表头可排序列：ID） */
    private const SORT_FIELDS = ['id', 'createtime'];

    /**
     * 留言列表（筛选 + 排序 + 分页）
     * GET /console/webmessage/list
     * 参数：page pagesize realname tel status start_date end_date field order
     */
    public function list()
    {
        $page     = max(1, (int) $this->request->param('page', 1));
        $pagesize = (int) $this->request->param('pagesize', 15);
        $pagesize = max(1, min(100, $pagesize));

        $query = Db::name('webmessage');

        // 联系人（模糊，参考系统检索项 realname）
        $realname = trim((string) $this->request->param('realname', ''));
        if ($realname !== '') {
            $query->whereLike('realname', '%' . $realname . '%');
        }

        // 联系方式（模糊，参考系统检索项 tel）
        $tel = trim((string) $this->request->param('tel', ''));
        if ($tel !== '') {
            $query->whereLike('tel', '%' . $tel . '%');
        }

        // 状态：全部/未处理/已处理（参考系统检索项 status）
        $status = $this->request->param('status', '');
        if ($status !== '' && $status !== null) {
            $query->where('status', (int) $status);
        }

        // 提交时间范围（参考系统检索项 ctime，含端点日全天）
        $startDate = trim((string) $this->request->param('start_date', ''));
        if ($startDate !== '') {
            $ts = strtotime($startDate);
            if ($ts !== false) {
                $query->where('createtime', '>=', $ts);
            }
        }
        $endDate = trim((string) $this->request->param('end_date', ''));
        if ($endDate !== '') {
            $ts = strtotime($endDate . ' 23:59:59');
            if ($ts !== false) {
                $query->where('createtime', '<=', $ts);
            }
        }

        // 排序：默认 id desc（同参考系统）
        $field = (string) $this->request->param('field', '');
        $order = strtolower((string) $this->request->param('order', ''));
        if ($field !== '' && in_array($field, self::SORT_FIELDS, true) && in_array($order, ['asc', 'desc'], true)) {
            $query->order($field, $order);
        } else {
            $query->order('id', 'desc');
        }

        $total = (clone $query)->count();
        $rows  = $query
            ->field('id,realname,tel,content,ip,createtime,status')
            ->page($page, $pagesize)
            ->select()
            ->toArray();

        $list = array_map(static function ($row) {
            $row['id']         = (int) $row['id'];
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
     * 改状态（支持批量：参考系统「改为已处理/改为未处理」）
     * POST /console/webmessage/setst  body：{ids:[..], st:0|1}
     */
    public function setst()
    {
        $body = $this->body();
        $ids  = $this->ids($body);
        if (empty($ids)) {
            return $this->error('请选择数据');
        }

        $st = (int) ($body['st'] ?? 0) === 1 ? 1 : 0;
        Db::name('webmessage')->whereIn('id', $ids)->update(['status' => $st]);

        return $this->success([], '操作成功');
    }

    /**
     * 删除（支持批量，参考系统文案：确定要删除吗？删除后无法恢复！）
     * POST /console/webmessage/delete  body：{ids:[..]}
     */
    public function delete()
    {
        $ids = $this->ids($this->body());
        if (empty($ids)) {
            return $this->error('请选择数据');
        }

        Db::name('webmessage')->whereIn('id', $ids)->delete();

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
