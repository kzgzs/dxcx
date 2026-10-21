<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\BindingService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 平台绑定中心
 *
 * 八端配置管理：微信公众号(mp)/微信小程序(wx)/支付宝/百度/抖音/QQ/H5/APP。
 * 微信双端支持「手动配置」与「开放平台代授权」两条绑定路径：
 * - 手动配置：appid+密钥直填，同 appid 不允许两个租户同时手动绑定
 * - 代授权：wxAuthUrl 生成授权页 → /open/auth/callback 回调同事务落库
 */
class Binding extends BaseController
{
    public function __construct(\think\App $app, protected BindingService $binding)
    {
        parent::__construct($app);
    }

    /**
     * 各端绑定状态聚合
     * GET /admin/binding/overview
     */
    public function overview()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->binding->overview($aid));
    }

    /**
     * 读取单端配置（密钥类字段脱敏）
     * GET /admin/binding/config/:platform
     */
    public function config(string $platform = '')
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->binding->getConfig($aid, $platform));
    }

    /**
     * 保存单端手动配置
     * POST /admin/binding/save/:platform
     */
    public function save(string $platform = '')
    {
        $aid = ScopeService::aidOf($this->request);

        $post = $this->request->post();
        if (!is_array($post)) {
            $post = [];
        }

        $this->binding->saveConfig($aid, $platform, $post);

        return $this->success([], '保存成功');
    }

    /**
     * 生成开放平台授权链接
     * GET /admin/binding/wxAuthUrl?auth_type=1|2（1公众号 2小程序）
     */
    public function wxAuthUrl()
    {
        $aid      = ScopeService::aidOf($this->request);
        $authType = (int) $this->request->param('auth_type', 2);

        return $this->success($this->binding->wxAuthUrl($aid, $authType));
    }

    /**
     * 重新拉取授权信息（authtype=1 的微信绑定）
     * POST /admin/binding/refreshAuth {platform: wx|mp}
     */
    public function refreshAuth()
    {
        $aid      = ScopeService::aidOf($this->request);
        $platform = (string) $this->request->post('platform', 'wx');

        return $this->success($this->binding->refreshAuth($aid, $platform), '授权信息已刷新');
    }

    /**
     * 生成小程序代码包（支付宝/百度/QQ/抖音）
     * POST /admin/binding/downloadCode/:platform
     */
    public function downloadCode(string $platform = '')
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->binding->downloadCode($aid, $platform), '代码包已生成');
    }
}
