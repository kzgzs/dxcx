<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;
use think\facade\Log;

/**
 * 开放平台代小程序代码管理与发布服务
 * 全部微信调用经 WxApiClient（token 走 OpenPlatformService，42001 自动重刷）；
 * mock 模式下所有微信 API 返回预置成功报文；每步操作写 open_wxalog 流水。
 * 参照 canzhao\app\controller\Binding.php / Wechat.php 简化重写。
 */
class OpenCodeService
{
    /** 审核状态：0已上传 1审核中 2审核通过 3已发布 4审核驳回 */
    public const AUDIT_UPLOADED = 0;
    public const AUDIT_ING      = 1;
    public const AUDIT_PASS     = 2;
    public const AUDIT_RELEASED = 3;
    public const AUDIT_REJECT   = 4;

    /** mock 二维码（1x1 PNG base64） */
    protected const MOCK_QRCODE_B64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    public function __construct(protected OpenPlatformService $open)
    {
    }

    /** 草稿箱列表 @return array<int, array<string, mixed>> */
    public function draftList(): array
    {
        $this->open->requireEnabled();
        if ($this->open->isMock()) {
            return [[
                'draft_id'           => 1001,
                'user_version'       => '9.9.9',
                'user_desc'          => 'Mock草稿箱版本（开发者小程序上传）',
                'source_miniprogram' => 'wxMOCKDEV0001',
                'create_time'        => time() - 86400,
            ]];
        }
        $rs = $this->open->api()->apiGetTemplateDraftList($this->open->componentAccessToken());
        $this->checkErr($rs, 'gettemplatedraftlist');

        return (array) ($rs['draft_list'] ?? []);
    }

    /** 草稿转模板，成功后同步模板库 @return array{template_id: int, draft_id: int} */
    public function addFromDraft(int $draftId): array
    {
        $this->open->requireEnabled();
        if ($this->open->isMock()) {
            $templateId = 90000 + $draftId;
        } else {
            $rs = $this->open->api()->apiAddToTemplate($this->open->componentAccessToken(), $draftId);
            $this->checkErr($rs, 'addtotemplate');
            $templateId = (int) ($rs['template_id'] ?? 0);
        }
        $this->syncTemplateList();

        return ['template_id' => $templateId, 'draft_id' => $draftId];
    }

    /** 模板库列表：先调微信同步落 open_template，再读库 @return array<int, array<string, mixed>> */
    public function templateList(): array
    {
        $this->open->requireEnabled();
        $this->syncTemplateList();

        return Db::name('open_template')->order('template_id', 'desc')->select()->toArray();
    }

    /** 微信 gettemplatelist -> open_template 全量同步 */
    public function syncTemplateList(): void
    {
        if ($this->open->isMock()) {
            $list = [[
                'template_id'             => 91001,
                'user_version'            => '9.9.9',
                'user_desc'               => 'Mock模板（草稿转模板）',
                'create_time'             => time() - 3600,
                'source_miniprogram'      => 'wxMOCKDEV0001',
                'source_miniprogram_name' => 'Mock开发者小程序',
                'draft_id'                => 1001,
            ]];
        } else {
            $rs = $this->open->api()->apiGetTemplateList($this->open->componentAccessToken());
            $this->checkErr($rs, 'gettemplatelist');
            $list = (array) ($rs['template_list'] ?? []);
        }
        $now     = time();
        $keepIds = [];
        foreach ($list as $tpl) {
            $templateId = (int) ($tpl['template_id'] ?? 0);
            if ($templateId <= 0) {
                continue;
            }
            $keepIds[] = $templateId;
            $save = [
                'draft_id'                => (int) ($tpl['draft_id'] ?? 0),
                'user_version'            => (string) ($tpl['user_version'] ?? ''),
                'user_desc'               => (string) ($tpl['user_desc'] ?? ''),
                'source_miniprogram'      => (string) ($tpl['source_miniprogram'] ?? ''),
                'source_miniprogram_name' => (string) ($tpl['source_miniprogram_name'] ?? ''),
                'wx_create_time'          => (int) ($tpl['create_time'] ?? 0),
                'update_time'             => $now,
            ];
            $existing = Db::name('open_template')->where('template_id', $templateId)->find();
            if ($existing) {
                Db::name('open_template')->where('id', $existing['id'])->update($save);
            } else {
                $save['template_id'] = $templateId;
                $save['create_time'] = $now;
                Db::name('open_template')->insert($save);
            }
        }
        // 微信侧已删除的模板同步移除
        $del = Db::name('open_template');
        if ($keepIds) {
            $del->whereNotIn('template_id', $keepIds);
        }
        $del->delete();
    }

    /** commit 上传代码（ext_json 按租户动态注入），$extExtra 合并进 ext（底部菜单/导航自定义等），返回 wxalog 落库记录 */
    public function commit(string $appid, int $templateId, string $userVersion, string $userDesc, ?int $aid = null, string $operator = '', array $extExtra = []): array
    {
        $row = $this->requireAuthorizer($appid);
        $aid = $aid ?? (null !== $row['aid'] ? (int) $row['aid'] : null);

        $ext     = $this->buildExtJson($appid, $aid, (string) $row['nick_name'], $extExtra);
        $extJson = str_replace('\\/', '/', json_encode($ext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        if (!$this->open->isMock()) {
            $rs = $this->open->callWithAuthorizerToken($appid, fn (string $token) => $this->open->api()->apiCommit($token, $templateId, $extJson, $userVersion, $userDesc));
            $this->checkErr($rs, 'commit');
        }
        $logId = $this->log($appid, 'commit', [
            'aid'          => $aid,
            'template_id'  => $templateId,
            'user_version' => $userVersion,
            'user_desc'    => $userDesc,
            'ext_json'     => $extJson,
            'operator'     => $operator,
        ]);

        return (array) Db::name('open_wxalog')->where('id', $logId)->find();
    }

    /** 提审（item_list 简化默认，ugc_declare 可选） @return array{audit_id: int} */
    public function submitAudit(string $appid, array $ugcDeclare = [], string $operator = ''): array
    {
        $row  = $this->requireAuthorizer($appid);
        $data = ['item_list' => [], 'privacy_api_not_use' => true];
        if ($ugcDeclare) {
            $data['ugc_declare'] = $ugcDeclare;
        }
        if ($this->open->isMock()) {
            $auditId = random_int(420000000, 429999999);
        } else {
            $rs = $this->open->callWithAuthorizerToken($appid, fn (string $token) => $this->open->api()->apiSubmitAudit($token, $data));
            $this->checkErr($rs, 'submit_audit');
            $auditId = (int) ($rs['auditid'] ?? 0);
        }
        $this->log($appid, 'submit_audit', [
            'aid'          => $this->aidOf($row),
            'audit_id'     => $auditId,
            'audit_status' => self::AUDIT_ING,
            'ext_json'     => json_encode($data, JSON_UNESCAPED_UNICODE),
            'operator'     => $operator,
        ]);

        return ['audit_id' => $auditId];
    }

    /** 查询审核状态（默认取最近一次提审），返回微信 status/reason + 本地 audit_status */
    public function auditStatus(string $appid, ?int $auditId = null): array
    {
        $this->requireAuthorizer($appid);
        if (null === $auditId || $auditId <= 0) {
            $auditId = (int) (Db::name('open_wxalog')
                ->where('authorizer_appid', $appid)->where('action', 'submit_audit')
                ->order('id', 'desc')->value('audit_id') ?: 0);
        }
        if ($auditId <= 0) {
            throw new BusinessException('暂无提审记录，无法查询审核状态');
        }
        if ($this->open->isMock()) {
            $rs = ['errcode' => 0, 'status' => 0, 'reason' => '']; // mock：审核通过
        } else {
            $rs = $this->open->callWithAuthorizerToken($appid, fn (string $token) => $this->open->api()->apiGetAuditStatus($token, $auditId));
            $this->checkErr($rs, 'get_auditstatus');
        }
        // 微信 status=0 审核通过 / status=1 审核驳回
        $status = (int) ($rs['status'] ?? -1);
        $local  = self::AUDIT_ING;
        if (0 === $status) {
            $local = self::AUDIT_PASS;
        } elseif (1 === $status) {
            $local = self::AUDIT_REJECT;
        }
        Db::name('open_wxalog')->where('authorizer_appid', $appid)->where('audit_id', $auditId)
            ->update(['audit_status' => $local, 'audit_reason' => (string) ($rs['reason'] ?? '')]);

        return ['audit_id' => $auditId, 'status' => $status, 'reason' => (string) ($rs['reason'] ?? ''), 'audit_status' => $local];
    }

    /** 撤审 */
    public function undoAudit(string $appid, string $operator = ''): void
    {
        $row = $this->requireAuthorizer($appid);
        if (!$this->open->isMock()) {
            $rs = $this->open->callWithAuthorizerToken($appid, fn (string $token) => $this->open->api()->apiUndoAudit($token));
            $this->checkErr($rs, 'undocodeaudit');
        }
        Db::name('open_wxalog')->where('authorizer_appid', $appid)
            ->where('action', 'submit_audit')->where('audit_status', self::AUDIT_ING)
            ->update(['audit_status' => self::AUDIT_UPLOADED]);
        $this->log($appid, 'undo_audit', ['aid' => $this->aidOf($row), 'operator' => $operator]);
    }

    /** 加急审核 */
    public function speedupAudit(string $appid, ?int $auditId = null, string $operator = ''): void
    {
        $row = $this->requireAuthorizer($appid);
        if (null === $auditId || $auditId <= 0) {
            $auditId = (int) (Db::name('open_wxalog')
                ->where('authorizer_appid', $appid)->where('action', 'submit_audit')
                ->order('id', 'desc')->value('audit_id') ?: 0);
        }
        if ($auditId <= 0) {
            throw new BusinessException('暂无提审记录，无法加急');
        }
        if (!$this->open->isMock()) {
            $rs = $this->open->callWithAuthorizerToken($appid, fn (string $token) => $this->open->api()->apiSpeedupAudit($token, $auditId));
            $this->checkErr($rs, 'speedupaudit');
        }
        $this->log($appid, 'speedup_audit', ['aid' => $this->aidOf($row), 'audit_id' => $auditId, 'operator' => $operator]);
    }

    /** 发布 */
    public function release(string $appid, string $operator = ''): void
    {
        $row = $this->requireAuthorizer($appid);
        if (!$this->open->isMock()) {
            $rs = $this->open->callWithAuthorizerToken($appid, fn (string $token) => $this->open->api()->apiRelease($token));
            $this->checkErr($rs, 'release');
        }
        Db::name('open_wxalog')->where('authorizer_appid', $appid)
            ->where('action', 'submit_audit')->where('audit_status', self::AUDIT_PASS)
            ->order('id', 'desc')->limit(1)
            ->update(['audit_status' => self::AUDIT_RELEASED]);
        $this->log($appid, 'release', ['aid' => $this->aidOf($row), 'audit_status' => self::AUDIT_RELEASED, 'operator' => $operator]);
    }

    /** 版本回退 */
    public function revert(string $appid, string $targetVersion, string $operator = ''): void
    {
        $row = $this->requireAuthorizer($appid);
        if ('' === trim($targetVersion)) {
            throw new BusinessException('请填写回退目标版本号');
        }
        if (!$this->open->isMock()) {
            $rs = $this->open->callWithAuthorizerToken($appid, fn (string $token) => $this->open->api()->apiRevertRelease($token, $targetVersion));
            $this->checkErr($rs, 'revertcoderelease');
        }
        $this->log($appid, 'revert', ['aid' => $this->aidOf($row), 'user_version' => $targetVersion, 'operator' => $operator]);
    }

    /** 体验/线上二维码（base64），$type released|trial */
    public function qrcode(string $appid, string $type = 'released'): string
    {
        $this->requireAuthorizer($appid);
        if ($this->open->isMock()) {
            return self::MOCK_QRCODE_B64;
        }
        $fetch = function (string $token) use ($type): string {
            return 'trial' === $type
                ? $this->open->api()->apiGetTrialQrcode($token)
                : $this->open->api()->apiGetQrcode($token);
        };
        $raw = $fetch($this->open->authorizerAccessToken($appid));
        // 错误时微信返回 JSON，token 失效强刷重试一次
        if (str_starts_with(ltrim($raw), '{')) {
            $rs = json_decode($raw, true);
            if (in_array((int) ($rs['errcode'] ?? 0), [40001, 42001, 40014], true)) {
                $raw = $fetch($this->open->authorizerAccessToken($appid, true));
            }
            if (str_starts_with(ltrim($raw), '{')) {
                $rs = json_decode($raw, true);
                $this->checkErr(is_array($rs) ? $rs : ['errcode' => -1], 'get_qrcode');
            }
        }

        return base64_encode($raw);
    }

    /** 绑定体验者 */
    public function bindTester(string $appid, string $wechatId, string $operator = ''): void
    {
        $row = $this->requireAuthorizer($appid);
        if ('' === trim($wechatId)) {
            throw new BusinessException('请填写体验者微信号');
        }
        if (!$this->open->isMock()) {
            $rs = $this->open->callWithAuthorizerToken($appid, fn (string $token) => $this->open->api()->apiBindTester($token, $wechatId));
            $this->checkErr($rs, 'bind_tester');
        }
        $this->log($appid, 'bind_tester', [
            'aid'      => $this->aidOf($row),
            'ext_json' => json_encode(['wechatid' => $wechatId], JSON_UNESCAPED_UNICODE),
            'operator' => $operator,
        ]);
    }

    /** 代设服务器域名 $action add|set|delete|get；$domains {request:[],socket:[],upload:[],download:[]} */
    public function modifyDomain(string $appid, string $action, array $domains, string $operator = ''): void
    {
        $row = $this->requireAuthorizer($appid);
        if (!in_array($action, ['add', 'set', 'delete', 'get'], true)) {
            throw new BusinessException('action 仅支持 add/set/delete/get');
        }
        $payload = [
            'requestdomain'   => array_values((array) ($domains['request'] ?? [])),
            'wsrequestdomain' => array_values((array) ($domains['socket'] ?? [])),
            'uploaddomain'    => array_values((array) ($domains['upload'] ?? [])),
            'downloaddomain'  => array_values((array) ($domains['download'] ?? [])),
        ];
        if (!$this->open->isMock()) {
            $rs = $this->open->callWithAuthorizerToken($appid, fn (string $token) => $this->open->api()->apiModifyDomain($token, $action, $payload));
            $this->checkErr($rs, 'modify_domain');
        }
        $this->log($appid, 'domain', [
            'aid'      => $this->aidOf($row),
            'ext_json' => json_encode(['action' => $action, 'domains' => $domains], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'operator' => $operator,
        ]);
    }

    /** 审核通过事件：记流水；autofb=1 时自动发布 */
    public function handleAuditSuccess(string $appid, array $msg): void
    {
        $this->log($appid, 'audit_success', [
            'aid'          => $this->aidOfAppid($appid),
            'audit_status' => self::AUDIT_PASS,
            'ext_json'     => json_encode(['SuccTime' => (string) ($msg['SuccTime'] ?? '')], JSON_UNESCAPED_UNICODE),
            'operator'     => 'wx_event',
        ]);
        $this->markLatestAudit($appid, self::AUDIT_PASS);
        if ((int) ($this->open->config()['autofb'] ?? 0) === 1) {
            try {
                $this->release($appid, 'autofb');
                Log::info('open.code 审核通过自动发布 appid=' . $appid);
            } catch (\Throwable $e) {
                Log::error('open.code 自动发布失败 appid=' . $appid . ' : ' . $e->getMessage());
            }
        }
    }

    /** 审核驳回事件：记录驳回原因 */
    public function handleAuditFail(string $appid, array $msg): void
    {
        $reason = mb_substr((string) ($msg['Reason'] ?? ''), 0, 500);
        $this->log($appid, 'audit_fail', [
            'aid'          => $this->aidOfAppid($appid),
            'audit_status' => self::AUDIT_REJECT,
            'audit_reason' => $reason,
            'ext_json'     => json_encode(['FailTime' => (string) ($msg['FailTime'] ?? '')], JSON_UNESCAPED_UNICODE),
            'operator'     => 'wx_event',
        ]);
        $this->markLatestAudit($appid, self::AUDIT_REJECT, $reason);
    }

    /** 发布流水分页 @return array{list: array, total: int, page: int, pagesize: int} */
    public function wxalogList(string $appid, int $page = 1, int $pagesize = 20): array
    {
        $page     = max(1, $page);
        $pagesize = max(1, min(100, $pagesize));
        $query    = Db::name('open_wxalog')->order('id', 'desc');
        if ('' !== $appid) {
            $query->where('authorizer_appid', $appid);
        }
        $total = $query->count();
        $rows  = $query->page($page, $pagesize)->select()->toArray();

        return ['list' => $rows, 'total' => $total, 'page' => $page, 'pagesize' => $pagesize];
    }

    /** ext_json 组装（按租户动态注入，结构参照 canzhao Binding.php 简化版）；$extExtra 合并进 ext 节点 @return array<string, mixed> */
    public function buildExtJson(string $appid, ?int $aid, string $nickname, array $extExtra = []): array
    {
        $ext = array_merge([
            'aid'     => $aid,
            'baseurl' => str_replace('http://', 'https://', request()->domain()),
        ], $extExtra);

        return [
            'extAppid'             => $appid,
            '__usePrivacyCheck__'  => true,
            'ext'                  => $ext,
            'window'               => [
                'navigationBarBackgroundColor' => '#ffffff',
                'navigationBarTextStyle'       => 'black',
                'navigationBarTitleText'       => $nickname,
                'backgroundColor'              => '#f8f8f8',
                'backgroundTextStyle'          => 'dark',
                'enablePullDownRefresh'        => true,
            ],
            'requiredPrivateInfos' => ['chooseAddress', 'chooseLocation'],
        ];
    }

    /** 授权方存在且授权中，否则抛业务异常 */
    protected function requireAuthorizer(string $appid): array
    {
        $row = Db::name('open_authorizer')->where('authorizer_appid', $appid)->find();
        if (empty($row)) {
            throw new BusinessException('未授权的小程序：' . $appid);
        }
        if ((int) $row['auth_status'] !== 1) {
            throw new BusinessException('该小程序授权已取消：' . $appid);
        }

        return $row;
    }

    /** 微信错误码检查（errcode!=0 抛业务异常） */
    protected function checkErr(array $rs, string $api): void
    {
        $errcode = (int) ($rs['errcode'] ?? 0);
        if (0 !== $errcode) {
            throw new BusinessException($api . ' 失败[' . $errcode . '] ' . (string) ($rs['errmsg'] ?? ''));
        }
    }

    /** 更新最近一次提审记录状态 */
    protected function markLatestAudit(string $appid, int $status, string $reason = ''): void
    {
        $save = ['audit_status' => $status];
        if ('' !== $reason) {
            $save['audit_reason'] = $reason;
        }
        Db::name('open_wxalog')->where('authorizer_appid', $appid)
            ->where('action', 'submit_audit')->where('audit_status', self::AUDIT_ING)
            ->order('id', 'desc')->limit(1)->update($save);
    }

    /** 记录里的 aid（可能为 null） */
    protected function aidOf(array $row): ?int
    {
        return null !== $row['aid'] ? (int) $row['aid'] : null;
    }

    /** 按 appid 查 aid（事件场景，未落库返回 null） */
    protected function aidOfAppid(string $appid): ?int
    {
        $aid = (int) (Db::name('open_authorizer')->where('authorizer_appid', $appid)->value('aid') ?: 0);

        return $aid > 0 ? $aid : null;
    }

    /** 写发布流水 */
    protected function log(string $appid, string $action, array $extra = []): int
    {
        $row = array_merge([
            'authorizer_appid' => $appid,
            'aid'              => null,
            'action'           => $action,
            'template_id'      => 0,
            'user_version'     => '',
            'user_desc'        => '',
            'audit_id'         => 0,
            'audit_status'     => 0,
            'audit_reason'     => '',
            'ext_json'         => '',
            'operator'         => '',
            'create_time'      => time(),
        ], $extra);

        return (int) Db::name('open_wxalog')->insertGetId($row);
    }
}
