<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\PosterService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 共享海报（admin 应用，JWT role=admin，aid 走 ScopeService）
 *
 * 对照参考系统 ShopPoster / MemberPoster：
 * - type=product 商品海报（按 type+platform 单行）
 * - type=index   会员分享海报（多模板管理：新建默认模板/删除/规则文案）
 * - 画布 content JSON：{poster_bg, poster_data[](img/text/textarea/head/qrwx/qrmp)}
 */
class Poster extends BaseController
{
    public function __construct(\think\App $app, protected PosterService $posterService)
    {
        parent::__construct($app);
    }

    /**
     * 海报模板列表（index 多模板管理用）
     * GET /admin/poster/list?type=&platform=
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->posterService->list(
            $aid,
            (string) $this->request->get('type', 'index'),
            (string) $this->request->get('platform', 'wx')
        ));
    }

    /**
     * 读取海报模板（无模板时自动初始化默认元素模板）
     * GET /admin/poster/get?type=&platform=&id=
     */
    public function get()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->posterService->get(
            $aid,
            (string) $this->request->get('type', 'index'),
            (string) $this->request->get('platform', 'wx'),
            (int) $this->request->get('id', 0)
        ));
    }

    /**
     * 保存海报模板
     * POST /admin/poster/save {type, platform, id, poster_bg, poster_data[], guize, clearhistory}
     */
    public function save()
    {
        $aid = ScopeService::aidOf($this->request);

        $posterData = $this->request->post('poster_data/a', []);

        $result = $this->posterService->save(
            $aid,
            (string) $this->request->post('type', 'index'),
            (string) $this->request->post('platform', 'wx'),
            (int) $this->request->post('id', 0),
            trim((string) $this->request->post('poster_bg', '')),
            is_array($posterData) ? $posterData : [],
            trim((string) $this->request->post('guize', '')),
            (int) $this->request->post('clearhistory', 0)
        );

        return $this->success($result, '保存成功');
    }

    /**
     * 新建海报模板（默认元素模板）
     * POST /admin/poster/add {type, platform}
     */
    public function add()
    {
        $aid = ScopeService::aidOf($this->request);

        $result = $this->posterService->add(
            $aid,
            (string) $this->request->post('type', 'index'),
            (string) $this->request->post('platform', 'wx')
        );

        return $this->success($result, '新建成功');
    }

    /**
     * 删除海报模板
     * POST /admin/poster/del {type, id}
     */
    public function del()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->posterService->delete(
            $aid,
            (string) $this->request->post('type', 'index'),
            (int) $this->request->post('id', 0)
        );

        return $this->success([], '删除成功');
    }

    /**
     * 二维码元素内容（qrwx/qrmp 按 aid 生成的目标链接，真实码图由 C 端运行时生成）
     * GET /admin/poster/qrcode?platform=&mid=
     */
    public function qrcode()
    {
        $aid      = ScopeService::aidOf($this->request);
        $platform = strtolower((string) $this->request->get('platform', 'wx'));
        $mid      = (int) $this->request->get('mid', 0);

        $domain = $this->request->domain();

        if ('wx' === $platform) {
            $url = 'pages/index/index?scene=aid%3D' . $aid . '%26pid%3D' . $mid;
            $tip = '小程序码（C 端按会员 mid 实时生成）';
        } else {
            $url = $domain . '/?aid=' . $aid . '&pid=' . $mid;
            $tip = '公众号二维码（关注锁定推荐关系）';
        }

        return $this->success(['platform' => $platform, 'aid' => $aid, 'url' => $url, 'tip' => $tip]);
    }
}
