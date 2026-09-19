<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\ScopeService;
use app\common\service\ShortvideoService;

/**
 * 租户后台 - 短视频管理（admin 应用，JWT role=admin，aid 走 ScopeService）
 *
 * 对照参考系统 app/controller/Shortvideo.php：列表/编辑挂商品/审核/上下架/删除；
 * 商户维度通过 bid 参数（''/all/0/gt0/数值）走 ScopeService::applyBid 口径
 */
class Shortvideo extends BaseController
{
    public function __construct(\think\App $app, protected ShortvideoService $shortvideoService)
    {
        parent::__construct($app);
    }

    /**
     * 视频列表（keyword/status/cid/bid 过滤）
     * GET /admin/shortvideo/video/list?page&pagesize&keyword&status&cid&bid
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->shortvideoService->videoList(
            $aid,
            trim((string) $this->request->get('keyword', '')),
            trim((string) $this->request->get('status', '')),
            (int) $this->request->get('cid', 0),
            trim((string) $this->request->get('bid', '')),
            $page,
            $pageSize
        ));
    }

    /**
     * 视频详情（编辑回显）
     * GET /admin/shortvideo/video/detail?id=
     */
    public function detail()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->shortvideoService->videoDetail($aid, (int) $this->request->get('id', 0)));
    }

    /**
     * 视频新增/编辑（挂商品 productids[]；商户视频按 shortvideo_check 回待审）
     * POST /admin/shortvideo/video/save
     */
    public function save()
    {
        $aid = ScopeService::aidOf($this->request);

        $result = $this->shortvideoService->videoSave($aid, $this->request->post());

        return $this->success($result, '保存成功');
    }

    /**
     * 视频批量上下架
     * POST /admin/shortvideo/video/status {ids[],status}
     */
    public function status()
    {
        $aid = ScopeService::aidOf($this->request);

        $ids = (array) $this->request->post('ids/a', []);
        if (empty($ids)) {
            $ids = [(int) $this->request->post('id', 0)];
        }

        $this->shortvideoService->videoStatus($aid, array_map('intval', $ids), (int) $this->request->post('status', 1));

        return $this->success([], '操作成功');
    }

    /**
     * 视频审核（status 1通过 0驳回；驳回需 reason）
     * POST /admin/shortvideo/video/check {id,status,reason}
     */
    public function check()
    {
        $aid = ScopeService::aidOf($this->request);

        $id     = (int) $this->request->post('id', 0);
        $status = (int) $this->request->post('status', 0);
        $reason = trim((string) $this->request->post('reason', ''));

        $this->shortvideoService->videoCheck($aid, $id, $status, $reason);

        return $this->success([], 1 === $status ? '审核通过' : '已驳回');
    }

    /**
     * 视频批量删除（级联评论/点赞日志）
     * POST /admin/shortvideo/video/delete {ids[]}
     */
    public function delete()
    {
        $aid = ScopeService::aidOf($this->request);

        $ids = (array) $this->request->post('ids/a', []);
        if (empty($ids)) {
            $ids = [(int) $this->request->post('id', 0)];
        }

        $this->shortvideoService->videoDelete($aid, array_map('intval', $ids));

        return $this->success([], '删除成功');
    }
}
