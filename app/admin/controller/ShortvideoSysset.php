<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\ScopeService;
use app\common\service\ShortvideoService;

/**
 * 租户后台 - 短视频系统设置
 *
 * 对照参考系统 app/controller/ShortvideoSysset.php：
 * list_type 列表样式 / can_upload 投稿开关 / upload_maxsize / upload_maxduration /
 * upload_check 投稿审核 / show_business_video 商户视频展示
 */
class ShortvideoSysset extends BaseController
{
    public function __construct(\think\App $app, protected ShortvideoService $shortvideoService)
    {
        parent::__construct($app);
    }

    /**
     * 获取系统设置（无记录自动初始化）
     * GET /admin/shortvideo/sysset/get
     */
    public function get()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->shortvideoService->sysset($aid));
    }

    /**
     * 保存系统设置
     * POST /admin/shortvideo/sysset/save
     */
    public function save()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->shortvideoService->saveSysset($aid, $this->request->post());

        return $this->success([], '保存成功');
    }
}
