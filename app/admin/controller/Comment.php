<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\CommentService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 商品评价管理（列表/审核/回复/删除/虚拟评价/评价开关）
 *
 * aid 一律取自 JWT（ScopeService::aidOf），杜绝越租户操作；
 * 审核/删除/虚拟评价在 service 内同事务重算商品聚合列。
 */
class Comment extends BaseController
{
    public function __construct(\think\App $app, protected CommentService $commentService)
    {
        parent::__construct($app);
    }

    /**
     * 评价列表（分页 + 筛选 + 顶部统计）
     * GET /admin/comment/list?page&pagesize&keyword&score&status&start_time&end_time
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->commentService->list($aid, $page, $pageSize, [
            'keyword'    => (string) $this->request->get('keyword', ''),
            'score'      => (string) $this->request->get('score', ''),
            'status'     => (string) $this->request->get('status', ''),
            'start_time' => (int) $this->request->get('start_time', 0),
            'end_time'   => (int) $this->request->get('end_time', 0),
            'bid'        => (string) $this->request->get('bid', ''),
            'sort_field' => (string) $this->request->get('sort_field', ''),
            'sort_order' => (string) $this->request->get('sort_order', ''),
        ]));
    }

    /**
     * 审核评价（待审核 → 通过/驳回，同事务重算商品聚合）
     * POST /admin/comment/check {id, status(1通过 2驳回)}
     */
    public function check()
    {
        $aid = ScopeService::aidOf($this->request);

        $id     = (int) $this->request->post('id', 0);
        $status = (int) $this->request->post('status', 0);

        $this->commentService->check($aid, $id, $status);

        return $this->success([], 1 === $status ? '已通过' : '已驳回');
    }

    /**
     * 商家回复（支持回复图片）
     * POST /admin/comment/reply {id, reply_content, reply_content_pic}
     */
    public function reply()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->commentService->reply($aid, is_array($this->request->post()) ? $this->request->post() : []);

        return $this->success([], '回复成功');
    }

    /**
     * 删除评价（物理删除，同事务重算商品聚合）
     * POST /admin/comment/delete {id}
     */
    public function delete()
    {
        $aid = ScopeService::aidOf($this->request);

        $id = (int) $this->request->post('id', 0);
        if ($id <= 0) {
            throw new BusinessException('缺少评价ID');
        }

        $this->commentService->delete($aid, $id);

        return $this->success([], '删除成功');
    }

    /**
     * 批量设置审核状态（已审=1 / 未审=0，可互相切换）
     * POST /admin/comment/batchSet {ids:int[], status:0|1}
     */
    public function batchSet()
    {
        $aid = ScopeService::aidOf($this->request);

        $ids    = $this->request->post('ids', []);
        $status = (int) $this->request->post('status', -1);
        $ids    = is_array($ids) ? $ids : [];

        $count = $this->commentService->batchSetStatus($aid, $ids, $status);

        return $this->success(['count' => $count], 1 === $status ? '已设为已审核' : '已设为未审核');
    }

    /**
     * 批量删除评价（物理删除，同事务重算商品聚合）
     * POST /admin/comment/batchDelete {ids:int[]}
     */
    public function batchDelete()
    {
        $aid = ScopeService::aidOf($this->request);

        $ids = $this->request->post('ids', []);
        $ids = is_array($ids) ? $ids : [];

        $count = $this->commentService->batchDelete($aid, $ids);

        return $this->success(['count' => $count], '删除成功');
    }

    /**
     * 新增/编辑虚拟评价（无订单评价，商品/会员信息手填）
     * POST /admin/comment/save {id?, proid?, proname, propic, ggname?, score, content, content_pic?, nickname, headimg?, createtime?}
     */
    public function save()
    {
        $aid = ScopeService::aidOf($this->request);

        $post = is_array($this->request->post()) ? $this->request->post() : [];

        return $this->success(
            $this->commentService->save($aid, $post),
            !empty($post['id']) ? '保存成功' : '新增成功'
        );
    }

    /**
     * 读取评价开关（shop_sysset：comment 总开关 / comment_check 审核开关）
     * GET /admin/comment/sysset
     */
    public function sysset()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->commentService->getConfig($aid));
    }

    /**
     * 保存评价开关
     * POST /admin/comment/sysset {comment, comment_check}
     */
    public function saveSysset()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->commentService->saveConfig(
            $aid,
            is_array($this->request->post()) ? $this->request->post() : []
        );

        return $this->success([], '保存成功');
    }
}
