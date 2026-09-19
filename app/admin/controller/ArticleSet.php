<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\ArticleService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 文章设置（列表样式/标题字数/分享送积分/打赏开关）
 */
class ArticleSet extends BaseController
{
    public function __construct(\think\App $app, protected ArticleService $articleService)
    {
        parent::__construct($app);
    }

    /**
     * 读取文章设置
     * GET /admin/articleset/get
     */
    public function get()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->articleService->getSet($aid));
    }

    /**
     * 保存文章设置
     * POST /admin/articleset/save {listtype, title_size, share_score, share_score_max_perday, reward_status, money_data, score_data}
     */
    public function save()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->articleService->saveSet(
            $aid,
            is_array($this->request->post()) ? $this->request->post() : []
        );

        return $this->success([], '保存成功');
    }
}
