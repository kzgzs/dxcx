<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\BindingTokenService;
use app\common\service\OpenPlatformService;
use app\common\service\ScopeService;
use app\common\service\WxApiClient;

/**
 * 租户后台 - 半屏小程序
 *
 * 对照 canzhao\app\controller\Wxembedded.php 路数重写：
 * - 仅代授权（authtype=1）可用；手动接入给出友好提示引导到微信公众平台申请
 * - embedded/initialize：拉取可打开的半屏小程序列表（mock 预置样例）
 * - 前端页面附带配置说明（什么是半屏小程序 / 使用方式 / 申请入口）
 */
class Wxembedded extends BaseController
{
    public function __construct(
        \think\App $app,
        protected OpenPlatformService $open,
        protected WxApiClient $api,
        protected BindingTokenService $bindingToken,
    ) {
        parent::__construct($app);
    }

    /**
     * 半屏小程序初始化（可用列表 + authtype 校验）
     * GET /admin/wxembedded/index
     */
    public function index()
    {
        $aid = ScopeService::aidOf($this->request);

        $row = ScopeService::query('admin_setapp_wx', $aid)->find();
        if (empty($row) || '' === (string) ($row['appid'] ?? '')) {
            return $this->error('请先在[应用-微信-小程序绑定]中完成小程序绑定');
        }

        $authtype = (int) ($row['authtype'] ?? 0);
        if (1 !== $authtype) {
            // 对照参考系统：手动接入不提供接口能力，引导到公众平台手动申请
            return $this->error('半屏小程序仅支持平台代授权的小程序使用；手动接入的请到[微信公众平台-设置-第三方设置-半屏小程序管理]中进行申请');
        }

        if ($this->open->isMock()) {
            // mock：预置两条可用半屏小程序样例
            $list = [
                ['appid' => 'wxmockembedded0001', 'nickname' => 'Mock半屏小程序A', 'status' => 1],
                ['appid' => 'wxmockembedded0002', 'nickname' => 'Mock半屏小程序B', 'status' => 1],
            ];
        } else {
            $token = $this->bindingToken->accessToken('wx', $aid);
            $rs = $this->api->embeddedInitialize($token);
            $errcode = (int) ($rs['errcode'] ?? 0);
            if (0 !== $errcode) {
                return $this->error('半屏小程序初始化失败：' . $errcode . ' ' . (string) ($rs['errmsg'] ?? ''));
            }
            $list = array_values((array) ($rs['list'] ?? []));
        }

        return $this->success([
            'authtype' => $authtype,
            'list'     => $list,
            'count'    => count($list),
            'is_mock'  => $this->open->isMock(),
        ]);
    }
}
