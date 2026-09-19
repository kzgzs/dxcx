<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\ActivityCommentService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 拼团评价 / 秒杀评价（批次4-E 任务190 E2-4）
 *
 * type 参数：collage 拼团评价 / seckill 秒杀评价
 */
class ActivityComment extends BaseController
{
    public function __construct(\think\App $app, protected ActivityCommentService $service)
    {
        parent::__construct($app);
    }

    /**
     * 评价列表（含顶部统计）
     * GET /admin/activityComment/list?type=&page=&pagesize=&keyword=&score=&status=&start_time=&end_time=
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        $type = $this->typeOf();

        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        $filters = [
            'keyword'    => trim((string) $this->request->get('keyword', '')),
            'score'      => (int) $this->request->get('score', 0),
            'status'     => (int) $this->request->get('status', -1),
            'start_time' => (int) $this->request->get('start_time', 0),
            'end_time'   => (int) $this->request->get('end_time', 0),
        ];

        return $this->success($this->service->list($aid, $type, $page, $pageSize, $filters));
    }

    /**
     * 审核（通过/驳回）
     * POST /admin/activityComment/check {type,id,status}
     */
    public function check()
    {
        $aid = ScopeService::aidOf($this->request);

        $data   = $this->request->post();
        $type   = $this->typeOf((string) ($data['type'] ?? ''));
        $id     = (int) ($data['id'] ?? 0);
        $status = (int) ($data['status'] ?? 1);

        $this->service->check($aid, $type, $id, $status);

        return $this->success([], '操作成功');
    }

    /**
     * 回复
     * POST /admin/activityComment/reply {type,id,reply_content,reply_content_pic}
     */
    public function reply()
    {
        $aid = ScopeService::aidOf($this->request);

        $data = $this->request->post();
        $type = $this->typeOf((string) ($data['type'] ?? ''));

        $this->service->reply($aid, $type, $data);

        return $this->success([], '回复成功');
    }

    /**
     * 删除
     * POST /admin/activityComment/delete {type,id}
     */
    public function delete()
    {
        $aid = ScopeService::aidOf($this->request);

        $data = $this->request->post();
        $type = $this->typeOf((string) ($data['type'] ?? ''));
        $id   = (int) ($data['id'] ?? 0);

        if ($id <= 0) {
            throw new BusinessException('请选择评价');
        }

        $this->service->delete($aid, $type, $id);

        return $this->success([], '删除成功');
    }

    protected function typeOf(string $fallback = ''): string
    {
        $type = $fallback ?: (string) $this->request->param('type', '');

        if (!in_array($type, ['collage', 'seckill'], true)) {
            throw new BusinessException('评价类型无效');
        }

        return $type;
    }
}
