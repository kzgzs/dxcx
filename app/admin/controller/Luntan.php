<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\LuntanService;
use app\common\service\PlogService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 用户论坛：帖子管理 + 系统设置（批次5-B 任务195）
 */
class Luntan extends BaseController
{
    public function __construct(\think\App $app, protected LuntanService $service)
    {
        parent::__construct($app);
    }

    /**
     * 帖子列表
     * GET /admin/luntan/list?status=&cid=&keyword=&starttime=&endtime=&page=&pagesize=
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->service->adminPostList($aid, [
            'status'    => (string) $this->request->get('status', ''),
            'cid'       => (int) $this->request->get('cid', 0),
            'keyword'   => trim((string) $this->request->get('keyword', '')),
            'starttime' => trim((string) $this->request->get('starttime', '')),
            'endtime'   => trim((string) $this->request->get('endtime', '')),
            'page'      => max(1, (int) $this->request->get('page', 1)),
            'pagesize'  => (int) $this->request->get('pagesize', 10),
        ]));
    }

    /**
     * 帖子审核（通过/驳回）
     * POST /admin/luntan/audit {ids[], status: 1通过|2驳回}
     */
    public function audit()
    {
        $aid = ScopeService::aidOf($this->request);

        $ids    = array_map('intval', (array) $this->request->post('ids', []));
        $status = (int) $this->request->post('status', 0);

        if (empty($ids)) {
            throw new BusinessException('请选择帖子');
        }

        $this->service->adminPostAudit($aid, $ids, $status);
        PlogService::write($aid, $this->uid(), '论坛帖子审核' . (1 === $status ? '通过' : '驳回') . implode(',', $ids));

        return $this->success([], 1 === $status ? '已通过审核' : '已驳回');
    }

    /**
     * 帖子置顶/取消置顶
     * POST /admin/luntan/setTop {ids[], st: 0|1}
     */
    public function setTop()
    {
        $aid = ScopeService::aidOf($this->request);

        $ids = array_map('intval', (array) $this->request->post('ids', []));
        $st  = (int) $this->request->post('st', 0);

        if (empty($ids)) {
            throw new BusinessException('请选择帖子');
        }

        $this->service->adminPostSetTop($aid, $ids, $st);
        PlogService::write($aid, $this->uid(), '论坛帖子改置顶状态' . implode(',', $ids));

        return $this->success([], '操作成功');
    }

    /**
     * 帖子删除
     * POST /admin/luntan/delete {ids[]}
     */
    public function delete()
    {
        $aid = ScopeService::aidOf($this->request);

        $ids = array_map('intval', (array) $this->request->post('ids', []));

        if (empty($ids)) {
            throw new BusinessException('请选择帖子');
        }

        $this->service->adminPostDelete($aid, $ids);
        PlogService::write($aid, $this->uid(), '删除论坛帖子' . implode(',', $ids));

        return $this->success([], '删除成功');
    }

    /**
     * 版块选项（帖子筛选下拉用）
     * GET /admin/luntan/categoryOptions
     */
    public function categoryOptions()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->service->adminCategoryList($aid));
    }

    /**
     * 帖子详情（后台编辑回显）
     * GET /admin/luntan/detail?id=
     */
    public function detail()
    {
        $aid = ScopeService::aidOf($this->request);
        $id  = (int) $this->request->get('id', 0);

        return $this->success($this->service->adminPostDetail($aid, $id));
    }

    /**
     * 帖子新增/编辑
     * POST /admin/luntan/save {id?,mid,cid,content,pics,video,readcount,zan,status}
     */
    public function save()
    {
        $aid = ScopeService::aidOf($this->request);

        $id = $this->service->adminPostSave($aid, $this->request->post());
        PlogService::write($aid, $this->uid(), '保存论坛帖子' . $id);

        return $this->success(['id' => $id], '保存成功');
    }

    /**
     * 系统设置读取
     * GET /admin/luntan/sysset
     */
    public function sysset()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->service->adminSysset($aid));
    }

    /**
     * 系统设置保存
     * POST /admin/luntan/syssetSave
     */
    public function syssetSave()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->service->adminSyssetSave($aid, $this->request->post());
        PlogService::write($aid, $this->uid(), '用户论坛系统设置');

        return $this->success([], '设置成功');
    }

    protected function uid(): int
    {
        return (int) ($this->request->authUid ?? 0);
    }
}
