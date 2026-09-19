<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\ScopeService;
use think\facade\Db;

/**
 * 租户后台 - 餐饮常用备注预置（P14-2 餐饮一期订单侧）
 *
 * 表：restaurant_remark（补迁移建表），订单 setremark 的下拉候选来源
 */
class RestaurantRemark extends BaseController
{
    /**
     * 备注列表（keyword 筛选；不分页全量候选 + 分页两种用法合一：page<=0 返回全量）
     * GET /admin/restaurant/remark/list
     */
    public function list()
    {
        $aid     = ScopeService::aidOf($this->request);
        $keyword = trim((string) $this->request->get('keyword', ''));
        $page    = (int) $this->request->get('page', 1);
        $pageSize = (int) $this->request->get('pagesize', 20);
        $pageSize = $pageSize > 0 ? min($pageSize, 200) : 20;

        $query = ScopeService::query('restaurant_remark', $aid);

        if ('' !== $keyword) {
            $query->where('content', 'like', '%' . $keyword . '%');
        }

        $total = (clone $query)->count();

        $q = $query->order('sort', 'desc')->order('id', 'desc');

        if ($page > 0) {
            $q->page($page, $pageSize);
        }

        $rows = $q->select()->toArray();

        return $this->success([
            'list' => array_map(static fn (array $row) => [
                'id'          => (int) ($row['id'] ?? 0),
                'content'     => (string) ($row['content'] ?? ''),
                'sort'        => (int) ($row['sort'] ?? 0),
                'status'      => (int) ($row['status'] ?? 1),
                'create_time' => (int) ($row['createtime'] ?? 0),
            ], $rows),
            'total'    => $total,
            'page'     => max($page, 1),
            'pagesize' => $pageSize,
        ]);
    }

    /**
     * 新增/编辑备注（带 id 为编辑，不带为新增）
     * POST /admin/restaurant/remark/save {id?, content, sort?, status?}
     */
    public function save()
    {
        $aid     = ScopeService::aidOf($this->request);
        $id      = (int) $this->request->post('id', 0);
        $content = trim((string) $this->request->post('content', ''));

        if ('' === $content) {
            throw new BusinessException('请填写备注内容');
        }

        if (mb_strlen($content) > 200) {
            throw new BusinessException('备注内容过长（限200字）');
        }

        $sort   = (int) $this->request->post('sort', 0);
        $status = (int) $this->request->post('status', 1);
        $status = in_array($status, [0, 1], true) ? $status : 1;

        if ($id > 0) {
            $exists = ScopeService::query('restaurant_remark', $aid)->where('id', $id)->find();

            if (empty($exists)) {
                throw new BusinessException('备注不存在', 404, 404);
            }

            Db::name('restaurant_remark')->where('id', $id)->update([
                'content'    => $content,
                'sort'       => $sort,
                'status'     => $status,
                'updatetime' => time(),
            ]);

            return $this->success([], '保存成功');
        }

        $newId = (int) Db::name('restaurant_remark')->insertGetId([
            'aid'        => $aid,
            'bid'        => 0,
            'content'    => $content,
            'sort'       => $sort,
            'status'     => $status,
            'createtime' => time(),
            'updatetime' => time(),
        ]);

        return $this->success(['id' => $newId], '添加成功');
    }

    /**
     * 删除备注
     * POST /admin/restaurant/remark/del {id}
     */
    public function del()
    {
        $aid = ScopeService::aidOf($this->request);
        $id  = (int) $this->request->post('id', 0);

        if ($id <= 0) {
            throw new BusinessException('缺少备注ID');
        }

        $affected = ScopeService::query('restaurant_remark', $aid)->where('id', $id)->delete();

        if ($affected < 1) {
            throw new BusinessException('备注不存在或已被删除', 404, 404);
        }

        return $this->success([], '删除成功');
    }
}
