<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\CuxiaoService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 商品促销管理（批次4-A 任务186）
 *
 * type 四模式：0仅显示 1满减 2满赠 3换购；C 端下单算价生效（OrderService 挂接）
 */
class Cuxiao extends BaseController
{
    public function __construct(\think\App $app, protected CuxiaoService $cuxiaoService)
    {
        parent::__construct($app);
    }

    /**
     * 活动列表（keyword 名称模糊；type 空=全部）
     * GET /admin/cuxiao/list?page&pagesize&keyword&type
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        $keyword  = trim((string) $this->request->get('keyword', ''));
        $type     = trim((string) $this->request->get('type', ''));
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->cuxiaoService->adminList($aid, $keyword, $type, $page, $pageSize));
    }

    /**
     * 活动详情
     * GET /admin/cuxiao/detail?id=
     */
    public function detail()
    {
        $aid = ScopeService::aidOf($this->request);

        $id = (int) $this->request->get('id', 0);

        if ($id <= 0) {
            throw new BusinessException('缺少活动ID');
        }

        return $this->success($this->cuxiaoService->detail($aid, $id));
    }

    /**
     * 新建活动
     * POST /admin/cuxiao/add {name,type,tip,gettj,fwtype,categoryids,productids,minprice,minnum,money,proid,ggid,starttime,endtime,sort,status}
     */
    public function add()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->cuxiaoService->add($aid, $this->request->post()), '创建成功');
    }

    /**
     * 编辑活动
     * POST /admin/cuxiao/edit {id,...}
     */
    public function edit()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->cuxiaoService->edit($aid, $this->request->post());

        return $this->success([], '保存成功');
    }

    /**
     * 启用/停用
     * POST /admin/cuxiao/status {id,status}
     */
    public function status()
    {
        $aid = ScopeService::aidOf($this->request);

        $id     = (int) $this->request->post('id', 0);
        $status = (int) $this->request->post('status', -1);

        $this->cuxiaoService->status($aid, $id, $status);

        return $this->success([], 1 === $status ? '已启用' : '已停用');
    }

    /**
     * 删除活动（物理删除）
     * POST /admin/cuxiao/delete {id}
     */
    public function delete()
    {
        $aid = ScopeService::aidOf($this->request);

        $id = (int) $this->request->post('id', 0);

        if ($id <= 0) {
            throw new BusinessException('缺少活动ID');
        }

        $this->cuxiaoService->delete($aid, $id);

        return $this->success([], '删除成功');
    }
}
