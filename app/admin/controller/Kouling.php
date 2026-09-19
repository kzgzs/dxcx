<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\KoulingService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 口令/谜语游戏（批次4-E 任务190 E1-1）
 *
 * type 参数：kouling 口令 / riddle 谜语
 */
class Kouling extends BaseController
{
    public function __construct(\think\App $app, protected KoulingService $service)
    {
        parent::__construct($app);
    }

    /**
     * 页面设置（读取）
     * GET /admin/kouling/set
     */
    public function set()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->service->getSet($aid));
    }

    /**
     * 页面设置（保存）
     * POST /admin/kouling/set
     */
    public function saveSet()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->service->saveSet($aid, $this->request->post());

        return $this->success([], '保存成功');
    }

    /**
     * 活动列表
     * GET /admin/kouling/list?type=&keyword=&page=&pagesize=
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        $type     = $this->typeOf();
        $keyword  = trim((string) $this->request->get('keyword', ''));
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->service->activityList($aid, $type, $keyword, $page, $pageSize));
    }

    /**
     * 活动详情
     * GET /admin/kouling/detail?type=&id=
     */
    public function detail()
    {
        $aid = ScopeService::aidOf($this->request);

        $type = $this->typeOf();
        $id   = (int) $this->request->get('id', 0);

        return $this->success($this->service->activityDetail($aid, $type, $id));
    }

    /**
     * 活动保存（新增/编辑）
     * POST /admin/kouling/save {type,...}
     */
    public function save()
    {
        $aid = ScopeService::aidOf($this->request);

        $data = $this->request->post();
        $type = $this->typeOf((string) ($data['type'] ?? ''));

        return $this->success($this->service->saveActivity($aid, $type, $data), '保存成功');
    }

    /**
     * 活动删除
     * POST /admin/kouling/delete {type, ids[]}
     */
    public function delete()
    {
        $aid = ScopeService::aidOf($this->request);

        $data = $this->request->post();
        $type = $this->typeOf((string) ($data['type'] ?? ''));
        $ids  = array_map('intval', (array) ($data['ids'] ?? []));

        if (empty($ids)) {
            throw new BusinessException('请选择活动');
        }

        $this->service->deleteActivity($aid, $type, $ids);

        return $this->success([], '删除成功');
    }

    /**
     * 参与记录
     * GET /admin/kouling/records?type=&objid=&nickname=&page=&pagesize=
     */
    public function records()
    {
        $aid = ScopeService::aidOf($this->request);

        $type     = $this->typeOf();
        $objId    = (int) $this->request->get('objid', 0);
        $nickname = trim((string) $this->request->get('nickname', ''));
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->service->recordList($aid, $type, $objId, $nickname, $page, $pageSize));
    }

    protected function typeOf(string $fallback = ''): string
    {
        $type = $fallback ?: (string) $this->request->param('type', 'kouling');

        if (!in_array($type, ['kouling', 'riddle'], true)) {
            throw new BusinessException('活动类型无效');
        }

        return $type;
    }
}
