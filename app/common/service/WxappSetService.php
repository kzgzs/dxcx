<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;

/**
 * 租户后台 - 小程序运营设置服务
 *
 * 改名/头像/简介/搜索开关/服务器域名/业务域名/隐私指引/备案人脸核身：
 * - mock 模式（OpenPlatformService::isMock）全部返回预置成功报文，无凭据可闭环
 * - 真实模式走 WxApiClient（token 经 OpenPlatformService，失效自动重刷）
 * - 每步操作写 open_wxalog 流水
 * 参照 canzhao\app\controller\Wxset.php 的路数简化重写。
 */
class WxappSetService
{
    public function __construct(protected OpenPlatformService $open)
    {
    }

    /* ==================== appid 解析 ==================== */

    /**
     * 租户绑定的小程序 appid（admin_setapp_wx）；mock 无绑定时回落 Mock 授权方
     */
    public function resolveAppid(int $aid): string
    {
        $appid = (string) (Db::name('admin_setapp_wx')->where('aid', $aid)->value('appid') ?: '');
        if ('' === $appid) {
            if (!$this->open->isMock()) {
                throw new BusinessException('请先在平台绑定中心绑定微信小程序');
            }
            $appid = OpenPlatformService::MOCK_APPID;
        }
        $this->ensureAuthorizer($appid, $aid);

        return $appid;
    }

    /** mock 模式兜底：授权方未落库时自动补一条 Mock 授权记录 */
    protected function ensureAuthorizer(string $appid, int $aid): void
    {
        $row = Db::name('open_authorizer')->where('authorizer_appid', $appid)->find();
        if (!empty($row)) {
            if (null === $row['aid'] && $aid > 0) {
                Db::name('open_authorizer')->where('id', $row['id'])->update(['aid' => $aid, 'update_time' => time()]);
            }

            return;
        }
        if (!$this->open->isMock()) {
            throw new BusinessException('该小程序未授权给开放平台：' . $appid);
        }
        $now = time();
        Db::name('open_authorizer')->insert([
            'authorizer_appid' => $appid,
            'aid'              => $aid > 0 ? $aid : null,
            'nick_name'        => OpenPlatformService::MOCK_NICK,
            'head_img'         => '',
            'user_name'        => 'gh_mock0000000000',
            'qrcode_url'       => '',
            'principal_name'   => 'Mock科技有限公司',
            'service_type_id'  => 2,
            'verify_type_id'   => 0,
            'func_info'        => '[]',
            'refresh_token'    => 'MOCK_REFRESH_TOKEN_' . md5($appid),
            'auth_status'      => 1,
            'auth_time'        => $now,
            'create_time'      => $now,
            'update_time'      => $now,
        ]);
    }

    /* ==================== 账号资料（改名/头像/简介/搜索开关） ==================== */

    /** 账号资料概览（本地落库 + 搜索状态） @return array<string, mixed> */
    public function accountInfo(int $aid): array
    {
        $appid = $this->resolveAppid($aid);
        $row   = (array) (Db::name('admin_setapp_wx')->where('aid', $aid)->find() ?: []);
        $auth  = Db::name('open_authorizer')->where('authorizer_appid', $appid)->find() ?: [];

        $searchStatus = 0;
        if (!$this->open->isMock()) {
            $rs = $this->open->callWithAuthorizerToken($appid, fn (string $token) => $this->open->api()->apiGetSearchStatus($token));
            if (0 === (int) ($rs['errcode'] ?? 0)) {
                $searchStatus = (int) ($rs['status'] ?? 0);
            }
        }

        // 认证状态：verify_type_id，0=微信认证（小程序），-1 表示未认证
        $verifyType = (int) ($auth['verify_type_id'] ?? -1);
        $authtype   = (int) ($row['authtype'] ?? 0);

        // 服务类目：授权 func_info 中 27 为小程序服务类目，真实类目需类目接口补充
        $categories = [];
        if (!empty($auth['func_info'])) {
            $func = json_decode((string) $auth['func_info'], true);
            if (is_array($func)) {
                $categories = $func;
            }
        }

        return [
            'appid'          => $appid,
            'nickname'       => (string) ($row['nickname'] ?? '') ?: (string) ($auth['nick_name'] ?? ''),
            'headimg'        => (string) ($row['headimg'] ?? '') ?: (string) ($auth['head_img'] ?? ''),
            'signature'      => (string) ($row['signature'] ?? ''),
            'principal_name' => (string) ($auth['principal_name'] ?? ''),
            'verify_type'    => $verifyType,
            'verified'       => $verifyType >= 0,
            'categories'     => $categories,
            'search_status'  => $searchStatus,
            'authtype'       => $authtype,
            'is_mock'        => $this->open->isMock(),
        ];
    }

    /** 设置小程序名称（真实模式调用 setnickname；名称冲突等错误原样抛出） */
    public function setNickname(int $aid, string $nickname): void
    {
        $nickname = trim($nickname);
        if ('' === $nickname) {
            throw new BusinessException('请填写小程序名称');
        }
        $appid = $this->resolveAppid($aid);
        if (!$this->open->isMock()) {
            $rs = $this->open->callWithAuthorizerToken($appid, fn (string $token) => $this->open->api()->apiSetNickname($token, ['nick_name' => $nickname]));
            $this->checkErr($rs, 'setnickname');
        }
        $this->upsertWxField($aid, 'nickname', $nickname);
        Db::name('open_authorizer')->where('authorizer_appid', $appid)->update(['nick_name' => $nickname, 'update_time' => time()]);
        $this->log($appid, $aid, 'set_nickname', ['ext_json' => json_encode(['nick_name' => $nickname], JSON_UNESCAPED_UNICODE)]);
    }

    /** 设置小程序头像（api 调用桩：头像需微信 media 上传，本地直接落库返回成功） */
    public function setHeadimg(int $aid, string $headimg): void
    {
        if ('' === trim($headimg)) {
            throw new BusinessException('请上传小程序头像');
        }
        $appid = $this->resolveAppid($aid);
        if (!$this->open->isMock()) {
            // 真实链路依赖 media 上传（head_img_media_id），暂以桩实现：仅本地落库
            $this->upsertWxField($aid, 'headimg', $headimg);
            $this->log($appid, $aid, 'set_headimg', ['ext_json' => json_encode(['headimg' => $headimg], JSON_UNESCAPED_SLASHES)]);

            return;
        }
        $this->upsertWxField($aid, 'headimg', $headimg);
        Db::name('open_authorizer')->where('authorizer_appid', $appid)->update(['head_img' => $headimg, 'update_time' => time()]);
        $this->log($appid, $aid, 'set_headimg', ['ext_json' => json_encode(['headimg' => $headimg], JSON_UNESCAPED_SLASHES)]);
    }

    /** 设置小程序简介 */
    public function setSignature(int $aid, string $signature): void
    {
        $signature = trim($signature);
        if ('' === $signature) {
            throw new BusinessException('请填写小程序简介');
        }
        $appid = $this->resolveAppid($aid);
        if (!$this->open->isMock()) {
            $rs = $this->open->callWithAuthorizerToken($appid, fn (string $token) => $this->open->api()->apiModifySignature($token, $signature));
            $this->checkErr($rs, 'modifysignature');
        }
        $this->upsertWxField($aid, 'signature', $signature);
        $this->log($appid, $aid, 'set_signature', ['ext_json' => json_encode(['signature' => $signature], JSON_UNESCAPED_UNICODE)]);
    }

    /** 搜索开关：$open true=允许被搜索 */
    public function setSearchStatus(int $aid, bool $open): void
    {
        $appid  = $this->resolveAppid($aid);
        $status = $open ? 0 : 1;
        if (!$this->open->isMock()) {
            $rs = $this->open->callWithAuthorizerToken($appid, fn (string $token) => $this->open->api()->apiChangeSearchStatus($token, $status));
            $this->checkErr($rs, 'changewxasearchstatus');
        }
        $this->log($appid, $aid, 'search_status', ['ext_json' => json_encode(['status' => $status], JSON_UNESCAPED_UNICODE)]);
    }

    /* ==================== 服务器域名快配 ==================== */

    /**
     * 读取服务器域名配置（mock 返回当前站点域名默认值）
     * @return array{requestdomain: string, wsrequestdomain: string, uploaddomain: string, downloaddomain: string}
     */
    public function serverDomainGet(int $aid): array
    {
        $appid   = $this->resolveAppid($aid);
        $default = str_replace('http://', 'https://', request()->domain());

        if ($this->open->isMock()) {
            return [
                'requestdomain'  => $default,
                'wsrequestdomain' => '',
                'uploaddomain'   => $default,
                'downloaddomain' => $default,
            ];
        }
        $rs = $this->open->callWithAuthorizerToken($appid, fn (string $token) => $this->open->api()->apiModifyDomainDirectly($token, 'get'));
        $this->checkErr($rs, 'modify_domain_directly');

        return [
            'requestdomain'  => implode(';', (array) ($rs['requestdomain'] ?? [])),
            'wsrequestdomain' => implode(';', (array) ($rs['wsrequestdomain'] ?? [])),
            'uploaddomain'   => implode(';', (array) ($rs['uploaddomain'] ?? [])),
            'downloaddomain' => implode(';', (array) ($rs['downloaddomain'] ?? [])),
        ];
    }

    /** 保存服务器域名（分号分隔字符串 → 数组；空项默认回填当前站点域名） */
    public function serverDomainSet(int $aid, array $info): void
    {
        $appid   = $this->resolveAppid($aid);
        $default = str_replace('http://', 'https://', request()->domain());
        $set     = [
            'requestdomain'  => $this->splitDomains((string) ($info['requestdomain'] ?? ''), [$default]),
            'wsrequestdomain' => $this->splitDomains((string) ($info['wsrequestdomain'] ?? ''), []),
            'uploaddomain'   => $this->splitDomains((string) ($info['uploaddomain'] ?? ''), [$default]),
            'downloaddomain' => $this->splitDomains((string) ($info['downloaddomain'] ?? ''), [$default]),
        ];
        $this->assertHttps($set);
        if (!$this->open->isMock()) {
            $rs = $this->open->callWithAuthorizerToken($appid, fn (string $token) => $this->open->api()->apiModifyDomainDirectly($token, 'set', $set));
            $this->checkErr($rs, 'modify_domain_directly');
        }
        $this->log($appid, $aid, 'server_domain', ['ext_json' => json_encode($set, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    }

    /* ==================== 业务域名 + 校验文件 ==================== */

    /** 读取业务域名配置 @return array{webviewdomain: string} */
    public function jumpDomainGet(int $aid): array
    {
        $appid   = $this->resolveAppid($aid);
        $default = str_replace('http://', 'https://', request()->domain());

        if ($this->open->isMock()) {
            return ['webviewdomain' => $default];
        }
        $rs = $this->open->callWithAuthorizerToken($appid, fn (string $token) => $this->open->api()->apiSetWebviewDomainDirectly($token, 'get'));
        $this->checkErr($rs, 'setwebviewdomain_directly');

        return ['webviewdomain' => implode(';', (array) ($rs['webviewdomain'] ?? []))];
    }

    /** 保存业务域名 */
    public function jumpDomainSet(int $aid, string $webviewdomain): void
    {
        $appid   = $this->resolveAppid($aid);
        $default = str_replace('http://', 'https://', request()->domain());
        $set     = ['webviewdomain' => $this->splitDomains($webviewdomain, [$default])];
        $this->assertHttps($set);
        if (!$this->open->isMock()) {
            $rs = $this->open->callWithAuthorizerToken($appid, fn (string $token) => $this->open->api()->apiSetWebviewDomainDirectly($token, 'set', $set));
            $this->checkErr($rs, 'setwebviewdomain_directly');
        }
        $this->log($appid, $aid, 'jump_domain', ['ext_json' => json_encode($set, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    }

    /**
     * 业务域名校验文件（mock 本地生成确定性内容；真实取微信返回）
     * @return array{file_name: string, file_content: string}
     */
    public function jumpDomainConfirmFile(int $aid): array
    {
        $appid = $this->resolveAppid($aid);
        if ($this->open->isMock()) {
            return [
                'file_name'    => 'wxa_mock_' . substr(md5($appid), 0, 12) . '.txt',
                'file_content' => md5($appid . ':dxcx:mock'),
            ];
        }
        $rs = $this->open->callWithAuthorizerToken($appid, fn (string $token) => $this->open->api()->apiGetWebviewDomainConfirmFile($token));
        $this->checkErr($rs, 'get_webviewdomain_confirmfile');

        return [
            'file_name'    => (string) ($rs['file_name'] ?? ''),
            'file_content' => (string) ($rs['file_content'] ?? ''),
        ];
    }

    /* ==================== 隐私指引 ==================== */

    /**
     * 查询隐私指引（mock 返回示例结构）
     * @return array{owner_setting: array, setting_list: array, privacy_keys: array}
     */
    public function privacyGet(int $aid): array
    {
        $appid = $this->resolveAppid($aid);
        if ($this->open->isMock()) {
            return [
                'owner_setting' => [
                    'contact_qq'    => '',
                    'contact_email' => '',
                    'contact_phone' => '',
                    'store_expire_timestamp' => '',
                ],
                'setting_list'  => [],
                'privacy_keys'  => [
                    ['privacy_key' => 'UserInfo', 'privacy_desc' => '用户信息（昵称/头像）'],
                    ['privacy_key' => 'Location', 'privacy_desc' => '位置信息'],
                    ['privacy_key' => 'Address', 'privacy_desc' => '收货地址'],
                    ['privacy_key' => 'PhoneNumber', 'privacy_desc' => '手机号'],
                ],
            ];
        }
        $rs = $this->open->callWithAuthorizerToken($appid, fn (string $token) => $this->open->api()->apiGetPrivacySetting($token, 1));
        $this->checkErr($rs, 'getprivacysetting');

        return [
            'owner_setting' => (array) ($rs['owner_setting'] ?? []),
            'setting_list'  => (array) ($rs['setting_list'] ?? []),
            'privacy_keys'  => (array) ($rs['privacy_desc']['privacy_desc_list'] ?? []),
        ];
    }

    /** 保存隐私指引（setting_list: {key: text} 映射） */
    public function privacySet(int $aid, array $ownerSetting, array $settingMap): void
    {
        $appid       = $this->resolveAppid($aid);
        $settingList = [];
        foreach ($settingMap as $key => $text) {
            $text = trim((string) $text);
            if ('' !== $text) {
                $settingList[] = ['privacy_key' => (string) $key, 'privacy_text' => $text];
            }
        }
        $data = ['owner_setting' => (array) $ownerSetting, 'setting_list' => $settingList];
        if (!$this->open->isMock()) {
            $rs = $this->open->callWithAuthorizerToken($appid, fn (string $token) => $this->open->api()->apiSetPrivacySetting($token, $data));
            $this->checkErr($rs, 'setprivacysetting');
        }
        $this->log($appid, $aid, 'privacy_setting', ['ext_json' => json_encode($data, JSON_UNESCAPED_UNICODE)]);
    }

    /* ==================== ICP 备案（人脸核身桩） ==================== */

    /** 备案资料行（不存在自动初始化） @return array<string, mixed> */
    public function icpInfo(int $aid, string $appid): array
    {
        $info = Db::name('admin_wxicp')->where('aid', $aid)->where('appid', $appid)->find();
        if (empty($info)) {
            $now  = time();
            $id   = Db::name('admin_wxicp')->insertGetId([
                'aid'          => $aid,
                'appid'        => $appid,
                'nrlx_details' => '[]',
                'face_status'  => -1,
                'create_time'  => $now,
            ]);
            $info = Db::name('admin_wxicp')->where('id', $id)->find();
        }

        return (array) $info;
    }

    /** 保存备案资料草稿（白名单前缀字段落库） */
    public function icpSave(int $aid, string $appid, array $data): void
    {
        $this->icpInfo($aid, $appid);
        $allow = [];
        foreach ($data as $key => $val) {
            $key = (string) $key;
            if (preg_match('/^(subject_|principal_|legal_person_|manager_|service_content_types|nrlx_details|app_comment|commitment_letter|business_name_change_letter|applets_other_materials)/', $key)) {
                $allow[$key] = is_array($val) ? json_encode($val, JSON_UNESCAPED_UNICODE) : (string) $val;
            }
        }
        if ($allow) {
            Db::name('admin_wxicp')->where('aid', $aid)->where('appid', $appid)->update($allow);
        }
    }

    /** 发起人脸核身（桩：mock 直接置为已通过；真实模式创建核身任务） */
    public function icpLaunchFaceCheck(int $aid, string $appid): array
    {
        $info = $this->icpInfo($aid, $appid);
        if ((int) $info['face_status'] === 3 && (int) ($info['face_finish_time'] ?: 0) > time()) {
            return ['face_status' => 3, 'msg' => '人脸核身已通过，无需重复发起'];
        }
        if ($this->open->isMock()) {
            Db::name('admin_wxicp')->where('aid', $aid)->where('appid', $appid)->update([
                'face_verify_task_id' => 'MOCK_TASK_' . substr(md5((string) time()), 0, 12),
                'face_is_finish'      => 1,
                'face_status'         => 3,
                'face_send_time'      => time(),
                'face_finish_time'    => time() + 3 * 86400,
            ]);

            return ['face_status' => 3, 'msg' => '人脸核身已通过（Mock）'];
        }
        $rs = $this->open->callWithAuthorizerToken($appid, fn (string $token) => $this->open->api()->apiCreateIcpVerifyTask($token));
        $this->checkErr($rs, 'create_icp_verify_task');
        Db::name('admin_wxicp')->where('aid', $aid)->where('appid', $appid)->update([
            'face_verify_task_id' => (string) ($rs['task_id'] ?? ''),
            'face_is_finish'      => 0,
            'face_status'         => 0,
            'face_send_time'      => time(),
            'face_finish_time'    => time() + 3 * 86400,
        ]);

        return ['face_status' => 0, 'msg' => '核验通知已发送至管理员微信，请及时处理，通知有效期24小时'];
    }

    /** 查询人脸核身结果（桩：mock 直接返回已通过） @return array{face_status: int, msg: string} */
    public function icpQueryFaceCheck(int $aid, string $appid): array
    {
        $info = $this->icpInfo($aid, $appid);
        $text = [-1 => '待发起', 0 => '等待管理员开始核验', 1 => '核验进行中', 2 => '核验失败，请重新发起', 3 => '核验已通过', 4 => '已过期，请重新发起'];
        if ($this->open->isMock()) {
            return ['face_status' => (int) $info['face_status'], 'msg' => $text[(int) $info['face_status']] ?? '待发起'];
        }
        $taskId = (string) ($info['face_verify_task_id'] ?? '');
        if ('' === $taskId) {
            return ['face_status' => -1, 'msg' => '待发起'];
        }
        $rs = $this->open->callWithAuthorizerToken($appid, fn (string $token) => $this->open->api()->apiQueryIcpVerifyTask($token, $taskId));
        $this->checkErr($rs, 'query_icp_verify_task');
        $faceStatus = (int) ($rs['face_status'] ?? 0);
        Db::name('admin_wxicp')->where('aid', $aid)->where('appid', $appid)->update([
            'face_is_finish' => (int) (!empty($rs['is_finish'])),
            'face_status'    => $faceStatus,
        ]);

        return ['face_status' => $faceStatus, 'msg' => $text[$faceStatus] ?? '核验中'];
    }

    /** 提交备案（mock 更新备案状态；真实模式调用 apply_icp_filing 简化载荷） */
    public function icpSubmit(int $aid, string $appid): array
    {
        $info = $this->icpInfo($aid, $appid);
        if ((int) $info['face_status'] !== 3) {
            throw new BusinessException('请先完成人脸核身再提交备案');
        }
        if ((int) ($info['face_finish_time'] ?: 0) < time()) {
            throw new BusinessException('人脸核身结果已过期，请重新核验');
        }
        if ($this->open->isMock()) {
            Db::name('admin_wxicp')->where('aid', $aid)->where('appid', $appid)->update([
                'beian_status' => 'icp_manage_review',
                'beian_reason' => '已提交，等待审核（Mock）',
            ]);

            return ['beian_status' => 'icp_manage_review', 'msg' => '提交成功，等待审核'];
        }
        $payload = [
            'icp_subject'    => $this->icpGroup($info, 'subject_'),
            'icp_principal'  => $this->icpGroup($info, 'principal_'),
            'icp_legal_person' => $this->icpGroup($info, 'legal_person_'),
            'icp_materials'  => ['applets_other_materials' => (string) ($info['applets_other_materials'] ?? '')],
        ];
        $rs = $this->open->callWithAuthorizerToken($appid, fn (string $token) => $this->open->api()->apiApplyIcpFiling($token, $payload));
        $this->checkErr($rs, 'apply_icp_filing');
        Db::name('admin_wxicp')->where('aid', $aid)->where('appid', $appid)->update([
            'beian_status' => 'icp_manage_review',
            'beian_reason' => '',
        ]);

        return ['beian_status' => 'icp_manage_review', 'msg' => '提交成功，等待审核'];
    }

    /* ==================== 工具 ==================== */

    /** admin_setapp_wx 单字段落库（无行先建行，兼容未手动绑定的 mock 租户） */
    protected function upsertWxField(int $aid, string $field, string $value): void
    {
        $exists = Db::name('admin_setapp_wx')->where('aid', $aid)->find();
        if (empty($exists)) {
            Db::name('admin_setapp_wx')->insert(['aid' => $aid, 'appid' => '', 'authtype' => 0, $field => $value]);

            return;
        }
        Db::name('admin_setapp_wx')->where('aid', $aid)->update([$field => $value]);
    }

    /** 备案字段分组（去前缀） @return array<string, mixed> */
    protected function icpGroup(array $info, string $prefix): array
    {
        $out = [];
        foreach ($info as $key => $val) {
            if (str_starts_with((string) $key, $prefix)) {
                $out[substr((string) $key, strlen($prefix))] = $val;
            }
        }

        return $out;
    }

    /** 分号分隔域名转数组，空串回落默认值 @return array<int, string> */
    protected function splitDomains(string $raw, array $fallback): array
    {
        $list = array_values(array_filter(array_map('trim', explode(';', $raw)), fn ($v) => '' !== $v));

        return $list ?: $fallback;
    }

    /** 域名必须 https/wss（微信强制要求） */
    protected function assertHttps(array $sets): void
    {
        foreach ($sets as $domains) {
            foreach ((array) $domains as $domain) {
                if (!preg_match('#^(https|wss)://#i', (string) $domain)) {
                    throw new BusinessException('域名必须以 https:// 或 wss:// 开头：' . $domain);
                }
            }
        }
    }

    /** 微信错误码检查 */
    protected function checkErr(array $rs, string $api): void
    {
        $errcode = (int) ($rs['errcode'] ?? 0);
        if (0 !== $errcode) {
            throw new BusinessException($api . ' 失败[' . $errcode . '] ' . (string) ($rs['errmsg'] ?? ''));
        }
    }

    /** 写操作流水（open_wxalog） */
    protected function log(string $appid, int $aid, string $action, array $extra = []): void
    {
        Db::name('open_wxalog')->insert(array_merge([
            'authorizer_appid' => $appid,
            'aid'              => $aid,
            'action'           => $action,
            'template_id'      => 0,
            'user_version'     => '',
            'user_desc'        => '',
            'audit_id'         => 0,
            'audit_status'     => 0,
            'audit_reason'     => '',
            'ext_json'         => '',
            'operator'         => 'wxapp_set',
            'create_time'      => time(),
        ], $extra));
    }
}
