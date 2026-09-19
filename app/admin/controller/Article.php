<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\ArticleService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 文章管理（列表/编辑/删除/上下架/审核/复制）
 *
 * aid 一律取自 JWT（ScopeService::aidOf），商户视图通过 bid 参数过滤（applyBid）。
 */
class Article extends BaseController
{
    public function __construct(\think\App $app, protected ArticleService $articleService)
    {
        parent::__construct($app);
    }

    /**
     * 文章列表（分页 + 筛选）
     * GET /admin/article/list?page&pagesize&keyword&status&cid&bid&start_time&end_time
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->articleService->list($aid, $page, $pageSize, [
            'keyword'    => (string) $this->request->get('keyword', ''),
            'status'     => (string) $this->request->get('status', ''),
            'cid'        => (int) $this->request->get('cid', 0),
            'bid'        => (string) $this->request->get('bid', ''),
            'start_time' => (int) $this->request->get('start_time', 0),
            'end_time'   => (int) $this->request->get('end_time', 0),
        ]));
    }

    /**
     * 文章详情
     * GET /admin/article/detail?id
     */
    public function detail()
    {
        $aid = ScopeService::aidOf($this->request);

        $id = (int) $this->request->get('id', 0);
        if ($id <= 0) {
            throw new BusinessException('缺少文章ID');
        }

        return $this->success($this->articleService->detail($aid, $id));
    }

    /**
     * 新增/编辑文章
     * POST /admin/article/save {id?, cid, name, subname?, pic?, author?, content?, sort?, status, showtj, ...}
     */
    public function save()
    {
        $aid = ScopeService::aidOf($this->request);

        $post = is_array($this->request->post()) ? $this->request->post() : [];

        return $this->success(
            $this->articleService->save($aid, $post),
            !empty($post['id']) ? '保存成功' : '新增成功'
        );
    }

    /**
     * 删除文章（级联删除评论/回评/点赞/阅读记录）
     * POST /admin/article/delete {ids:[..]}
     */
    public function delete()
    {
        $aid = ScopeService::aidOf($this->request);

        $ids = $this->request->post('ids', []);
        $ids = is_array($ids) ? array_map('intval', $ids) : [];
        if (!$ids) {
            throw new BusinessException('缺少文章ID');
        }

        $this->articleService->delete($aid, $ids);

        return $this->success([], '删除成功');
    }

    /**
     * 批量上/下架
     * POST /admin/article/setst {ids:[..], st:0|1}
     */
    public function setst()
    {
        $aid = ScopeService::aidOf($this->request);

        $ids = $this->request->post('ids', []);
        $ids = is_array($ids) ? array_map('intval', $ids) : [];
        $st  = (int) $this->request->post('st', 0);
        if (!$ids) {
            throw new BusinessException('缺少文章ID');
        }

        $this->articleService->setst($aid, $ids, $st);

        return $this->success([], 1 === $st ? '已上架' : '已下架');
    }

    /**
     * 审核文章（通过/驳回）
     * POST /admin/article/setcheckst {id, st:0待审|1通过|2驳回, reason?}
     */
    public function setcheckst()
    {
        $aid = ScopeService::aidOf($this->request);

        $id = (int) $this->request->post('id', 0);
        if ($id <= 0) {
            throw new BusinessException('缺少文章ID');
        }

        $this->articleService->setcheckst(
            $aid,
            $id,
            (int) $this->request->post('st', 0),
            (string) $this->request->post('reason', '')
        );

        return $this->success([], '操作成功');
    }

    /**
     * 复制文章
     * POST /admin/article/copy {id}
     */
    public function copy()
    {
        $aid = ScopeService::aidOf($this->request);

        $id = (int) $this->request->post('id', 0);
        if ($id <= 0) {
            throw new BusinessException('缺少文章ID');
        }

        return $this->success($this->articleService->copy($aid, $id), '复制成功');
    }
}
