<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\OpenCodeService;
use app\common\service\ScopeService;
use app\common\service\WxappSetService;
use think\facade\Db;

/**
 * 租户后台 - 小程序代码发布链
 *
 * 六步链路：选模板 → 上传代码(commit) → 提交审核(submitAudit) → 审核状态(getAuditStatus)
 *           → 发布(release) / 撤回(undocodeaudit) / 回退(revertCodeRelease)
 * 附带：体验版二维码、体验者绑定、更新记录流水。
 * 微信调用全部经 OpenCodeService（mock/真实双轨），ext.json 按租户注入 aid/baseurl/底部菜单。
 * 参照 canzhao\app\controller\Binding.php 发布段路数简化重写。
 */
class Wxapp extends BaseController
{
    public function __construct(\think\App $app, protected OpenCodeService $code, protected WxappSetService $wxset)
    {
        parent::__construct($app);
    }

    /**
     * 发布链总览：绑定信息 + 最近一次提审状态 + 更新记录
     * GET /admin/wxapp/overview
     */
    public function overview()
    {
        $aid   = ScopeService::aidOf($this->request);
        $appid = $this->wxset->resolveAppid($aid);

        $last = Db::name('open_wxalog')->where('authorizer_appid', $appid)
            ->where('action', 'submit_audit')->order('id', 'desc')->find();
        $released = Db::name('open_wxalog')->where('authorizer_appid', $appid)
            ->where('action', 'release')->order('id', 'desc')->find();

        return $this->success([
            'appid'     => $appid,
            'nickname'  => (string) (Db::name('open_authorizer')->where('authorizer_appid', $appid)->value('nick_name') ?: ''),
            'is_mock'   => $this->isMock(),
            'last_audit' => $last ?: null,
            'last_release' => $released ?: null,
            'logs'      => $this->code->wxalogList($appid, 1, 10)['list'],
        ]);
    }

    /**
     * 模板列表（超管开放平台模板库同步落库后读取）
     * GET /admin/wxapp/templateList
     */
    public function templateList()
    {
        ScopeService::aidOf($this->request);
        $list = $this->code->templateList();
        if (empty($list)) {
            return $this->error('请先在控制台开放平台设置中添加小程序模板');
        }

        return $this->success(['list' => $list]);
    }

    /**
     * 上传代码（commit）：模板 ID + 版本号/描述，ext.json 注入 aid/baseurl/底部菜单
     * POST /admin/wxapp/commit
     */
    public function commit()
    {
        $aid        = ScopeService::aidOf($this->request);
        $appid      = $this->wxset->resolveAppid($aid);
        $templateId = (int) $this->request->post('template_id', 0);
        $version    = trim((string) $this->request->post('version', '1.0'));
        $desc       = trim((string) $this->request->post('desc', ''));

        if ($templateId <= 0) {
            return $this->error('请选择上传模板');
        }
        if ('' === $version) {
            $version = '1.0';
        }

        // ext.json 注入项：底部菜单/导航自定义等（随模板小程序端读取）
        $extExtra = [
            'homeNavigationCustom'       => (int) $this->request->post('homeNavigationCustom', 0),
            'usercenterNavigationCustom' => (int) $this->request->post('usercenterNavigationCustom', 0),
            'hideHomeButton'             => (int) $this->request->post('hideHomeButton', 0),
            'navigationBarBackgroundColor' => (string) $this->request->post('navigationBarBackgroundColor', '#ffffff'),
            'navigationBarTextStyle'     => (string) $this->request->post('navigationBarTextStyle', 'black'),
        ];

        $log = $this->code->commit($appid, $templateId, $version, $desc, $aid, $this->operator(), $extExtra);

        return $this->success(['log_id' => (int) ($log['id'] ?? 0)], '上传成功');
    }

    /**
     * 提交审核
     * POST /admin/wxapp/submitAudit
     */
    public function submitAudit()
    {
        $aid   = ScopeService::aidOf($this->request);
        $appid = $this->wxset->resolveAppid($aid);

        $rs = $this->code->submitAudit($appid, [], $this->operator());

        return $this->success($rs, '提交成功，请等待审核');
    }

    /**
     * 审核状态查询（默认取最近一次提审）
     * GET /admin/wxapp/getAuditStatus
     */
    public function getAuditStatus()
    {
        $aid     = ScopeService::aidOf($this->request);
        $appid   = $this->wxset->resolveAppid($aid);
        $auditId = (int) $this->request->param('audit_id', 0);

        return $this->success($this->code->auditStatus($appid, $auditId > 0 ? $auditId : null), '刷新成功');
    }

    /**
     * 发布（审核通过后）
     * POST /admin/wxapp/release
     */
    public function release()
    {
        $aid   = ScopeService::aidOf($this->request);
        $appid = $this->wxset->resolveAppid($aid);

        $this->code->release($appid, $this->operator());

        return $this->success([], '发布成功');
    }

    /**
     * 撤回审核
     * POST /admin/wxapp/undocodeaudit
     */
    public function undocodeaudit()
    {
        $aid   = ScopeService::aidOf($this->request);
        $appid = $this->wxset->resolveAppid($aid);

        $this->code->undoAudit($appid, $this->operator());

        return $this->success([], '撤销成功');
    }

    /**
     * 版本回退
     * POST /admin/wxapp/revertCodeRelease
     */
    public function revertCodeRelease()
    {
        $aid         = ScopeService::aidOf($this->request);
        $appid       = $this->wxset->resolveAppid($aid);
        $appVersion  = trim((string) $this->request->post('appversion', ''));

        $this->code->revert($appid, $appVersion, $this->operator());

        return $this->success([], '回退成功');
    }

    /**
     * 体验版二维码（base64 PNG）
     * GET /admin/wxapp/getTrialQrcode
     */
    public function getTrialQrcode()
    {
        $aid   = ScopeService::aidOf($this->request);
        $appid = $this->wxset->resolveAppid($aid);

        return $this->success([
            'qrcode' => 'data:image/png;base64,' . $this->code->qrcode($appid, 'trial'),
        ]);
    }

    /**
     * 绑定体验者
     * POST /admin/wxapp/bindTester
     */
    public function bindTester()
    {
        $aid       = ScopeService::aidOf($this->request);
        $appid     = $this->wxset->resolveAppid($aid);
        $wechatId  = trim((string) $this->request->post('wechatid', ''));

        $this->code->bindTester($appid, $wechatId, $this->operator());

        return $this->success([], '添加成功');
    }

    /**
     * 更新记录（发布流水）
     * GET /admin/wxapp/wxalogList
     */
    public function wxalogList()
    {
        $aid      = ScopeService::aidOf($this->request);
        $appid    = $this->wxset->resolveAppid($aid);
        $page     = (int) $this->request->param('page', 1);
        $pagesize = (int) $this->request->param('pagesize', 20);

        return $this->success($this->code->wxalogList($appid, $page, $pagesize));
    }

    /** 当前操作人（JWT 载荷 username） */
    protected function operator(): string
    {
        $payload = $this->request->authPayload ?? [];

        return is_array($payload) ? (string) ($payload['username'] ?? '') : '';
    }

    /** 是否 mock 模式 */
    protected function isMock(): bool
    {
        return app(\app\common\service\OpenPlatformService::class)->isMock();
    }
}
