<?php

declare(strict_types=1);

namespace app\console\controller;

use app\BaseController;
use app\common\service\OpenCodeService;

/**
 * 超管控制台 - 开放平台代小程序代码管理与发布
 *
 * 契约（前端并行开发，统一 Result）：
 * GET  /console/open/template/list         模板库列表（先调微信同步再读库）
 * POST /console/open/template/addFromDraft 草稿转模板 body:{draft_id}
 * GET  /console/open/template/drafts       草稿箱列表
 * POST /console/open/code/commit           上传代码 body:{authorizer_appid, template_id, user_version, user_desc, aid?}
 * POST /console/open/code/submitAudit      提审 body:{authorizer_appid, ugc_declare?}
 * GET  /console/open/code/auditStatus      审核状态 ?authorizer_appid=&audit_id?
 * POST /console/open/code/undoAudit        撤审 body:{authorizer_appid}
 * POST /console/open/code/speedupAudit     加急 body:{authorizer_appid, audit_id?}
 * POST /console/open/code/release          发布 body:{authorizer_appid}
 * POST /console/open/code/revert           版本回退 body:{authorizer_appid, target_version}
 * GET  /console/open/code/qrcode           二维码 ?authorizer_appid=&type=released|trial -> data:{base64}
 * POST /console/open/code/domain           代设域名 body:{authorizer_appid, action, domains:{request,socket,upload,download}}
 * GET  /console/open/wxalog                发布流水 ?authorizer_appid=&page=
 */
class OpenCode extends BaseController
{
    public function __construct(\think\App $app, protected OpenCodeService $code)
    {
        parent::__construct($app);
    }

    /** 模板库列表（先同步微信再读库） */
    public function templateList()
    {
        return $this->success(['list' => $this->code->templateList()]);
    }

    /** 草稿箱列表 */
    public function drafts()
    {
        return $this->success(['list' => $this->code->draftList()]);
    }

    /** 草稿转模板 */
    public function addFromDraft()
    {
        $data = $this->body();
        $this->validate($data, ['draft_id' => 'require|integer|gt:0'], [
            'draft_id.require' => '请提供草稿 draft_id',
        ]);

        return $this->success($this->code->addFromDraft((int) $data['draft_id']), '已转为模板');
    }

    /** commit 上传代码 */
    public function commit()
    {
        $data = $this->body();
        $this->validate($data, [
            'authorizer_appid' => 'require',
            'template_id'      => 'require|integer|gt:0',
            'user_version'     => 'require',
        ], [
            'authorizer_appid.require' => '请提供 authorizer_appid',
            'template_id.require'      => '请选择模板',
            'user_version.require'     => '请填写版本号',
        ]);

        $row = $this->code->commit(
            (string) $data['authorizer_appid'],
            (int) $data['template_id'],
            (string) $data['user_version'],
            (string) ($data['user_desc'] ?? ''),
            isset($data['aid']) && (int) $data['aid'] > 0 ? (int) $data['aid'] : null,
            $this->operator()
        );

        return $this->success(['wxalog' => $row], '上传成功');
    }

    /** 提审 */
    public function submitAudit()
    {
        $data = $this->body();
        $this->validate($data, ['authorizer_appid' => 'require']);

        $ugc = is_array($data['ugc_declare'] ?? null) ? (array) $data['ugc_declare'] : [];

        return $this->success($this->code->submitAudit((string) $data['authorizer_appid'], $ugc, $this->operator()), '提交成功，等待审核');
    }

    /** 审核状态 */
    public function auditStatus()
    {
        $appid   = (string) $this->request->param('authorizer_appid', '');
        $auditId = (int) $this->request->param('audit_id', 0);
        if ('' === $appid) {
            return $this->error('请提供 authorizer_appid');
        }

        return $this->success($this->code->auditStatus($appid, $auditId > 0 ? $auditId : null));
    }

    /** 撤审 */
    public function undoAudit()
    {
        $data = $this->body();
        $this->validate($data, ['authorizer_appid' => 'require']);
        $this->code->undoAudit((string) $data['authorizer_appid'], $this->operator());

        return $this->success([], '撤销成功');
    }

    /** 加急审核 */
    public function speedupAudit()
    {
        $data = $this->body();
        $this->validate($data, ['authorizer_appid' => 'require']);
        $auditId = (int) ($data['audit_id'] ?? 0);
        $this->code->speedupAudit((string) $data['authorizer_appid'], $auditId > 0 ? $auditId : null, $this->operator());

        return $this->success([], '加急成功');
    }

    /** 发布 */
    public function release()
    {
        $data = $this->body();
        $this->validate($data, ['authorizer_appid' => 'require']);
        $this->code->release((string) $data['authorizer_appid'], $this->operator());

        return $this->success([], '发布成功');
    }

    /** 版本回退 */
    public function revert()
    {
        $data = $this->body();
        $this->validate($data, [
            'authorizer_appid' => 'require',
            'target_version'   => 'require',
        ], ['target_version.require' => '请填写回退目标版本号']);
        $this->code->revert((string) $data['authorizer_appid'], (string) $data['target_version'], $this->operator());

        return $this->success([], '回退成功');
    }

    /** 体验/线上二维码（base64） */
    public function qrcode()
    {
        $appid = (string) $this->request->param('authorizer_appid', '');
        $type  = (string) $this->request->param('type', 'released');
        if ('' === $appid) {
            return $this->error('请提供 authorizer_appid');
        }
        if (!in_array($type, ['released', 'trial'], true)) {
            return $this->error('type 仅支持 released|trial');
        }

        return $this->success(['base64' => $this->code->qrcode($appid, $type)]);
    }

    /** 代设服务器域名 */
    public function domain()
    {
        $data = $this->body();
        $this->validate($data, [
            'authorizer_appid' => 'require',
            'action'           => 'require|in:add,set,delete,get',
        ], ['action.in' => 'action 仅支持 add/set/delete/get']);

        $domains = is_array($data['domains'] ?? null) ? (array) $data['domains'] : [];
        $this->code->modifyDomain((string) $data['authorizer_appid'], (string) $data['action'], $domains, $this->operator());

        return $this->success([], '域名设置成功');
    }

    /** 发布流水分页 */
    public function wxalog()
    {
        $appid = (string) $this->request->param('authorizer_appid', '');
        $page  = (int) $this->request->param('page', 1);

        return $this->success($this->code->wxalogList($appid, $page));
    }

    /** 请求体（兼容 JSON 与表单） */
    protected function body(): array
    {
        $data = $this->request->post();

        return is_array($data) ? $data : [];
    }

    /** 操作人标识（console 超管） */
    protected function operator(): string
    {
        return 'console';
    }
}
