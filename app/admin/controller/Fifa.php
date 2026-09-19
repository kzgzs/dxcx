<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\FifaService;
use app\common\service\ScopeService;

/**
 * 租户后台 - Fifa 世界杯竞猜（批次4-E 任务190 E1-3）
 *
 * 赛事手工录入（外部 API 已过时）+ 竞猜设置 + 竞猜记录 + 开奖
 */
class Fifa extends BaseController
{
    public function __construct(\think\App $app, protected FifaService $service)
    {
        parent::__construct($app);
    }

    /**
     * 赛事列表
     * GET /admin/fifa/list?keyword=&page=&pagesize=
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        $keyword  = trim((string) $this->request->get('keyword', ''));
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->service->matchList($aid, $keyword, $page, $pageSize));
    }

    /**
     * 赛事详情
     * GET /admin/fifa/detail?id=
     */
    public function detail()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->service->matchDetail($aid, (int) $this->request->get('id', 0)));
    }

    /**
     * 赛事录入/编辑
     * POST /admin/fifa/save
     */
    public function save()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->service->saveMatch($aid, $this->request->post()), '保存成功');
    }

    /**
     * 赛事删除
     * POST /admin/fifa/delete {ids[]}
     */
    public function delete()
    {
        $aid = ScopeService::aidOf($this->request);

        $ids = array_map('intval', (array) $this->request->post('ids', []));

        if (empty($ids)) {
            throw new BusinessException('请选择赛事');
        }

        $this->service->deleteMatch($aid, $ids);

        return $this->success([], '删除成功');
    }

    /**
     * 竞猜设置（读取）
     * GET /admin/fifa/set
     */
    public function set()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->service->getSet($aid));
    }

    /**
     * 竞猜设置（保存）
     * POST /admin/fifa/set
     */
    public function saveSet()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->service->saveSet($aid, $this->request->post());

        return $this->success([], '保存成功');
    }

    /**
     * 竞猜记录
     * GET /admin/fifa/records?hid=&nickname=&page=&pagesize=
     */
    public function records()
    {
        $aid = ScopeService::aidOf($this->request);

        $hid      = (int) $this->request->get('hid', 0);
        $nickname = trim((string) $this->request->get('nickname', ''));
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->service->recordList($aid, $hid, $nickname, $page, $pageSize));
    }

    /**
     * 竞猜记录删除
     * POST /admin/fifa/recordDelete {ids[]}
     */
    public function recordDelete()
    {
        $aid = ScopeService::aidOf($this->request);

        $ids = array_map('intval', (array) $this->request->post('ids', []));

        if (empty($ids)) {
            throw new BusinessException('请选择记录');
        }

        $this->service->deleteRecords($aid, $ids);

        return $this->success([], '删除成功');
    }

    /**
     * 开奖（按已录入常规赛比分判定并发放积分）
     * POST /admin/fifa/open {hid}
     */
    public function open()
    {
        $aid = ScopeService::aidOf($this->request);

        $hid = (int) $this->request->post('hid', 0);

        if ($hid <= 0) {
            throw new BusinessException('请选择赛事');
        }

        $result = $this->service->openMatch($aid, $hid);

        return $this->success($result, '开奖完成：共 ' . $result['total'] . ' 条竞猜，猜中胜负 ' . $result['win1'] . ' 条，猜中比分 ' . $result['win2'] . ' 条');
    }
}
