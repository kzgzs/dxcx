<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\KechengService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 知识付费·课程设置（批次5-D1 任务197）
 */
class KechengSet extends BaseController
{
    public function __construct(\think\App $app, protected KechengService $kechengService)
    {
        parent::__construct($app);
    }

    /**
     * 课程设置读取
     * GET /admin/kechengSet/config
     */
    public function config()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->kechengService->syssetGet($aid));
    }

    /**
     * 课程设置保存
     * POST /admin/kechengSet/save
     */
    public function save()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->kechengService->syssetSave($aid, $this->request->post());

        return $this->success([], '保存成功');
    }
}
