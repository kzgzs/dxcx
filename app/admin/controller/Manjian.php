<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\ManjianService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 购物满减设置（对齐参考系统 Manjian/set）
 *
 * 配置 manjian_set 表（按 aid 单行 upsert）：
 * - 满减档位 mjdata JSON [{money购物满X元, jian减Y元}]
 * - 适用范围 fwtype 0所有 1指定类目 2指定商品
 * - 累计消费额满减 total_status / 总开关 status
 */
class Manjian extends BaseController
{
    public function __construct(\think\App $app, protected ManjianService $manjianService)
    {
        parent::__construct($app);
    }

    /**
     * 读取购物满减配置
     * GET /admin/manjian/config
     */
    public function config()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->manjianService->config($aid));
    }

    /**
     * 保存购物满减配置
     * POST /admin/manjian/save {fwtype,categoryids,productids,total_status,status,money[],jian[]}
     */
    public function save()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->manjianService->save($aid, $this->request->post());

        return $this->success([], '保存成功');
    }
}
