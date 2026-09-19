<?php

declare(strict_types=1);

namespace app\common\service;

/**
 * 微信开放平台 API HTTP 客户端
 *
 * 传输层可注入（构造参数 $transport），便于单测 mock：
 *   签名 fn(string $url, string $method, string $body): string（响应原文）
 * 默认走 curl。所有方法返回微信 JSON 解码后的数组。
 */
class WxApiClient
{
    public const BASE = 'https://api.weixin.qq.com';

    /** @var callable(string,string,string): string */
    protected $transport;

    /**
     * @param callable|null $transport 自定义传输实现（可注入）
     */
    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport ?? \Closure::fromCallable([self::class, 'curlTransport']);
    }

    /**
     * 获取 component_access_token
     */
    public function apiComponentToken(string $appid, string $secret, string $ticket): array
    {
        return $this->postJson('/cgi-bin/component/api_component_token', [
            'component_appid'         => $appid,
            'component_appsecret'     => $secret,
            'component_verify_ticket' => $ticket,
        ]);
    }

    /**
     * 获取预授权码
     */
    public function apiCreatePreAuthCode(string $componentAccessToken, string $appid): array
    {
        return $this->postJson('/cgi-bin/component/api_create_preauthcode?component_access_token=' . urlencode($componentAccessToken), [
            'component_appid' => $appid,
        ]);
    }

    /**
     * 使用授权码换取授权信息
     */
    public function apiQueryAuth(string $componentAccessToken, string $appid, string $authCode): array
    {
        return $this->postJson('/cgi-bin/component/api_query_auth?component_access_token=' . urlencode($componentAccessToken), [
            'component_appid'   => $appid,
            'authorization_code' => $authCode,
        ]);
    }

    /**
     * 获取授权方资料
     */
    public function apiGetAuthorizerInfo(string $componentAccessToken, string $appid, string $authorizerAppid): array
    {
        return $this->postJson('/cgi-bin/component/api_get_authorizer_info?component_access_token=' . urlencode($componentAccessToken), [
            'component_appid'    => $appid,
            'authorizer_appid'   => $authorizerAppid,
        ]);
    }

    /**
     * 获取（刷新）授权方 access_token
     */
    public function apiAuthorizerToken(string $componentAccessToken, string $appid, string $authorizerAppid, string $refreshToken): array
    {
        return $this->postJson('/cgi-bin/component/api_authorizer_token?component_access_token=' . urlencode($componentAccessToken), [
            'component_appid'          => $appid,
            'authorizer_appid'         => $authorizerAppid,
            'authorizer_refresh_token' => $refreshToken,
        ]);
    }

    /* ==================== 代小程序：模板管理 ==================== */

    /** 获取草稿箱列表 */
    public function apiGetTemplateDraftList(string $authorizerAccessToken): array
    {
        return $this->getJson('/wxa/gettemplatedraftlist?access_token=' . urlencode($authorizerAccessToken));
    }

    /** 草稿转为模板 */
    public function apiAddToTemplate(string $componentAccessToken, int $draftId): array
    {
        return $this->postJson('/wxa/addtotemplate?component_access_token=' . urlencode($componentAccessToken), [
            'draft_id' => $draftId,
        ]);
    }

    /** 获取模板列表 */
    public function apiGetTemplateList(string $authorizerAccessToken): array
    {
        return $this->getJson('/wxa/gettemplatelist?access_token=' . urlencode($authorizerAccessToken));
    }

    /* ==================== 代小程序：代码管理与发布 ==================== */

    /** 上传代码 */
    public function apiCommit(string $authorizerAccessToken, int $templateId, string $extJson, string $userVersion, string $userDesc): array
    {
        return $this->postJson('/wxa/commit?access_token=' . urlencode($authorizerAccessToken), [
            'template_id'  => $templateId,
            'ext_json'     => $extJson,
            'user_version' => $userVersion,
            'user_desc'    => $userDesc,
        ]);
    }

    /** 提交审核 */
    public function apiSubmitAudit(string $authorizerAccessToken, array $data): array
    {
        return $this->postJson('/wxa/submit_audit?access_token=' . urlencode($authorizerAccessToken), $data);
    }

    /** 查询审核状态 */
    public function apiGetAuditStatus(string $authorizerAccessToken, int $auditId): array
    {
        return $this->postJson('/wxa/get_auditstatus?access_token=' . urlencode($authorizerAccessToken), [
            'auditid' => $auditId,
        ]);
    }

    /** 撤回代码审核（GET） */
    public function apiUndoAudit(string $authorizerAccessToken): array
    {
        return $this->getJson('/wxa/undocodeaudit?access_token=' . urlencode($authorizerAccessToken));
    }

    /** 加急审核 */
    public function apiSpeedupAudit(string $authorizerAccessToken, int $auditId): array
    {
        return $this->postJson('/wxa/speedupaudit?access_token=' . urlencode($authorizerAccessToken), [
            'auditid' => $auditId,
        ]);
    }

    /** 发布已通过审核的小程序 */
    public function apiRelease(string $authorizerAccessToken): array
    {
        return $this->postJson('/wxa/release?access_token=' . urlencode($authorizerAccessToken), []);
    }

    /** 版本回退 */
    public function apiRevertRelease(string $authorizerAccessToken, string $appVersion): array
    {
        return $this->postJson('/wxa/revertcoderelease?access_token=' . urlencode($authorizerAccessToken), [
            'app_version' => $appVersion,
        ]);
    }

    /** 线上版二维码（GET，返回图片二进制或错误 JSON） */
    public function apiGetQrcode(string $authorizerAccessToken): string
    {
        return $this->getRaw('/wxa/get_qrcode?access_token=' . urlencode($authorizerAccessToken));
    }

    /** 体验版二维码（wxacode 无限接口 env_version=trial，返回图片二进制或错误 JSON） */
    public function apiGetTrialQrcode(string $authorizerAccessToken): string
    {
        return $this->postRaw('/wxa/getwxacodeunlimit?access_token=' . urlencode($authorizerAccessToken), [
            'scene'      => 'dxcx',
            'env_version' => 'trial',
        ]);
    }

    /** 绑定体验者 */
    public function apiBindTester(string $authorizerAccessToken, string $wechatId): array
    {
        return $this->postJson('/wxa/bind_tester?access_token=' . urlencode($authorizerAccessToken), [
            'wechatid' => $wechatId,
        ]);
    }

    /** 代设服务器域名 */
    public function apiModifyDomain(string $authorizerAccessToken, string $action, array $domains): array
    {
        return $this->postJson('/wxa/modify_domain?access_token=' . urlencode($authorizerAccessToken), [
            'action'           => $action,
            'requestdomain'    => (array) ($domains['requestdomain'] ?? []),
            'wsrequestdomain'  => (array) ($domains['wsrequestdomain'] ?? []),
            'uploaddomain'     => (array) ($domains['uploaddomain'] ?? []),
            'downloaddomain'   => (array) ($domains['downloaddomain'] ?? []),
        ]);
    }

    /** 快速配置小程序服务器域名（action set/get） */
    public function apiModifyDomainDirectly(string $authorizerAccessToken, string $action, array $domains = []): array
    {
        $data = ['action' => $action];
        if ('set' === $action) {
            $data['requestdomain']  = array_values(array_filter((array) ($domains['requestdomain'] ?? [])));
            $data['wsrequestdomain'] = array_values(array_filter((array) ($domains['wsrequestdomain'] ?? [])));
            $data['uploaddomain']   = array_values(array_filter((array) ($domains['uploaddomain'] ?? [])));
            $data['downloaddomain'] = array_values(array_filter((array) ($domains['downloaddomain'] ?? [])));
        }

        return $this->postJson('/wxa/modify_domain_directly?access_token=' . urlencode($authorizerAccessToken), $data);
    }

    /** 快速配置小程序业务域名（action set/get） */
    public function apiSetWebviewDomainDirectly(string $authorizerAccessToken, string $action, array $domains = []): array
    {
        $data = ['action' => $action];
        if ('set' === $action) {
            $data['webviewdomain'] = array_values(array_filter((array) ($domains['webviewdomain'] ?? [])));
        }

        return $this->postJson('/wxa/setwebviewdomain_directly?access_token=' . urlencode($authorizerAccessToken), $data);
    }

    /** 获取业务域名校验文件（file_name/file_content） */
    public function apiGetWebviewDomainConfirmFile(string $authorizerAccessToken): array
    {
        return $this->postJson('/wxa/get_webviewdomain_confirmfile?access_token=' . urlencode($authorizerAccessToken), []);
    }

    /** 设置小程序用户隐私保护指引 */
    public function apiSetPrivacySetting(string $authorizerAccessToken, array $data): array
    {
        return $this->postJson('/cgi-bin/component/setprivacysetting?access_token=' . urlencode($authorizerAccessToken), $data);
    }

    /** 查询小程序用户隐私保护指引（privacy_ver 1现网 2开发版） */
    public function apiGetPrivacySetting(string $authorizerAccessToken, int $privacyVer = 1): array
    {
        return $this->postJson('/cgi-bin/component/getprivacysetting?access_token=' . urlencode($authorizerAccessToken), [
            'privacy_ver' => $privacyVer,
        ]);
    }

    /** 设置小程序名称 */
    public function apiSetNickname(string $authorizerAccessToken, array $data): array
    {
        return $this->postJson('/wxa/setnickname?access_token=' . urlencode($authorizerAccessToken), $data);
    }

    /** 修改小程序头像（media 裁剪坐标） */
    public function apiModifyHeadImage(string $authorizerAccessToken, array $data): array
    {
        return $this->postJson('/cgi-bin/account/modifyheadimage?access_token=' . urlencode($authorizerAccessToken), $data);
    }

    /** 修改小程序简介 */
    public function apiModifySignature(string $authorizerAccessToken, string $signature): array
    {
        return $this->postJson('/cgi-bin/account/modifysignature?access_token=' . urlencode($authorizerAccessToken), [
            'signature' => $signature,
        ]);
    }

    /** 查询小程序是否可被搜索（status 0可搜索） */
    public function apiGetSearchStatus(string $authorizerAccessToken): array
    {
        return $this->getJson('/wxa/getwxasearchstatus?access_token=' . urlencode($authorizerAccessToken));
    }

    /** 设置小程序搜索开关（status 0打开 1关闭） */
    public function apiChangeSearchStatus(string $authorizerAccessToken, int $status): array
    {
        return $this->postJson('/wxa/changewxasearchstatus?access_token=' . urlencode($authorizerAccessToken), [
            'status' => $status,
        ]);
    }

    /** ICP 备案：发起人脸核身任务 */
    public function apiCreateIcpVerifyTask(string $authorizerAccessToken): array
    {
        return $this->postJson('/cgi-bin/account/icp/create_icp_verify_task?access_token=' . urlencode($authorizerAccessToken), []);
    }

    /** ICP 备案：查询人脸核身任务结果 */
    public function apiQueryIcpVerifyTask(string $authorizerAccessToken, string $taskId): array
    {
        return $this->postJson('/cgi-bin/account/icp/query_icp_verify_task?access_token=' . urlencode($authorizerAccessToken), [
            'task_id' => $taskId,
        ]);
    }

    /** ICP 备案：申请备案 */
    public function apiApplyIcpFiling(string $authorizerAccessToken, array $data): array
    {
        return $this->postJson('/cgi-bin/account/icp/apply_icp_filing?access_token=' . urlencode($authorizerAccessToken), $data);
    }

    /* ==================== 公众号：自定义菜单 ==================== */

    /** 创建自定义菜单 */
    public function menuCreate(string $authorizerAccessToken, array $menu): array
    {
        return $this->postJson('/cgi-bin/menu/create?access_token=' . urlencode($authorizerAccessToken), $menu);
    }

    /** 删除自定义菜单 */
    public function menuDelete(string $authorizerAccessToken): array
    {
        return $this->getJson('/cgi-bin/menu/delete?access_token=' . urlencode($authorizerAccessToken));
    }

    /** 获取自定义菜单配置（含个性化菜单） */
    public function menuGetCurrentSelfInfo(string $authorizerAccessToken): array
    {
        return $this->getJson('/cgi-bin/get_current_selfmenu_info?access_token=' . urlencode($authorizerAccessToken));
    }

    /* ==================== 公众号：素材管理 ==================== */

    /** 新增临时素材（media/upload，返回 media_id） */
    public function mediaUpload(string $authorizerAccessToken, string $type, string $filePath): array
    {
        return $this->postFile('/cgi-bin/media/upload?access_token=' . urlencode($authorizerAccessToken) . '&type=' . urlencode($type), $filePath);
    }

    /** 新增永久素材（add_material，即取 media_id；type=image/voice/video/thumb） */
    public function addMaterial(string $authorizerAccessToken, string $type, string $filePath): array
    {
        return $this->postFile('/cgi-bin/material/add_material?access_token=' . urlencode($authorizerAccessToken) . '&type=' . urlencode($type), $filePath);
    }

    /** 上传图文消息内的图片（uploadimg，返回 url，不占公众号图片库） */
    public function uploadImg(string $authorizerAccessToken, string $filePath): array
    {
        return $this->postFile('/cgi-bin/media/uploadimg?access_token=' . urlencode($authorizerAccessToken), $filePath);
    }

    /** 新增图文素材（add_news，articles 为文章数组） */
    public function addNews(string $authorizerAccessToken, array $articles): array
    {
        return $this->postJson('/cgi-bin/material/add_news?access_token=' . urlencode($authorizerAccessToken), [
            'articles' => array_values($articles),
        ]);
    }

    /** 修改图文素材（update_news） */
    public function updateNews(string $authorizerAccessToken, string $mediaId, array $article, int $index = 0): array
    {
        return $this->postJson('/cgi-bin/material/update_news?access_token=' . urlencode($authorizerAccessToken), [
            'media_id' => $mediaId,
            'index'    => $index,
            'articles' => $article,
        ]);
    }

    /** 获取素材列表（batchget_material，type=image/video/voice/news） */
    public function batchGetMaterial(string $authorizerAccessToken, string $type, int $offset = 0, int $count = 20): array
    {
        return $this->postJson('/cgi-bin/material/batchget_material?access_token=' . urlencode($authorizerAccessToken), [
            'type'   => $type,
            'offset' => $offset,
            'count'  => min(max($count, 1), 20),
        ]);
    }

    /* ==================== 公众号：粉丝管理 ==================== */

    /** 获取粉丝 openid 列表（分页 next_openid） */
    public function userGet(string $authorizerAccessToken, string $nextOpenid = ''): array
    {
        return $this->getJson('/cgi-bin/user/get?access_token=' . urlencode($authorizerAccessToken)
            . ('' !== $nextOpenid ? '&next_openid=' . urlencode($nextOpenid) : ''));
    }

    /** 获取粉丝基本信息 */
    public function userInfo(string $authorizerAccessToken, string $openid): array
    {
        return $this->getJson('/cgi-bin/user/info?access_token=' . urlencode($authorizerAccessToken) . '&openid=' . urlencode($openid));
    }

    /** 批量获取粉丝信息（user/info/batchget） */
    public function userBatchGet(string $authorizerAccessToken, array $openids): array
    {
        $list = [];
        foreach ($openids as $openid) {
            $list[] = ['openid' => (string) $openid, 'lang' => 'zh_CN'];
        }

        return $this->postJson('/cgi-bin/user/info/batchget?access_token=' . urlencode($authorizerAccessToken), [
            'user_list' => $list,
        ]);
    }

    /* ==================== 公众号：标签管理 ==================== */

    /** 创建标签 */
    public function tagsCreate(string $authorizerAccessToken, string $name): array
    {
        return $this->postJson('/cgi-bin/tags/create?access_token=' . urlencode($authorizerAccessToken), [
            'tag' => ['name' => $name],
        ]);
    }

    /** 编辑标签 */
    public function tagsUpdate(string $authorizerAccessToken, int $id, string $name): array
    {
        return $this->postJson('/cgi-bin/tags/update?access_token=' . urlencode($authorizerAccessToken), [
            'tag' => ['id' => $id, 'name' => $name],
        ]);
    }

    /** 删除标签 */
    public function tagsDelete(string $authorizerAccessToken, int $id): array
    {
        return $this->postJson('/cgi-bin/tags/delete?access_token=' . urlencode($authorizerAccessToken), [
            'tag' => ['id' => $id],
        ]);
    }

    /** 获取公众号已创建的标签 */
    public function tagsGet(string $authorizerAccessToken): array
    {
        return $this->getJson('/cgi-bin/tags/get?access_token=' . urlencode($authorizerAccessToken));
    }

    /** 批量为粉丝打标签 */
    public function tagsBatchTagging(string $authorizerAccessToken, int $tagId, array $openids): array
    {
        return $this->postJson('/cgi-bin/tags/members/batchtagging?access_token=' . urlencode($authorizerAccessToken), [
            'openid_list' => array_values($openids),
            'tagid'       => $tagId,
        ]);
    }

    /** 批量为粉丝取消标签 */
    public function tagsBatchUntagging(string $authorizerAccessToken, int $tagId, array $openids): array
    {
        return $this->postJson('/cgi-bin/tags/members/batchuntagging?access_token=' . urlencode($authorizerAccessToken), [
            'openid_list' => array_values($openids),
            'tagid'       => $tagId,
        ]);
    }

    /** 获取粉丝身上的标签列表 */
    public function tagsGetIdList(string $authorizerAccessToken, string $openid): array
    {
        return $this->postJson('/cgi-bin/tags/getidlist?access_token=' . urlencode($authorizerAccessToken), [
            'openid' => $openid,
        ]);
    }

    /* ==================== 公众号：模板消息 ==================== */

    /** 从行业模板库选择模板添加到账号（api_add_template，返回 template_id） */
    public function apiAddTemplate(string $authorizerAccessToken, string $templateIdShort): array
    {
        return $this->postJson('/cgi-bin/template/api_add_template?access_token=' . urlencode($authorizerAccessToken), [
            'template_id_short' => $templateIdShort,
        ]);
    }

    /** 发送模板消息（message/template/send） */
    public function templateSend(string $authorizerAccessToken, array $data): array
    {
        return $this->postJson('/cgi-bin/message/template/send?access_token=' . urlencode($authorizerAccessToken), $data);
    }

    /**
     * 客服消息通用发送（message/custom/send，自定义 msgtype 报文）
     */
    public function sendCustomerMessage(string $authorizerAccessToken, array $data): array
    {
        return $this->postJson('/cgi-bin/message/custom/send?access_token=' . urlencode($authorizerAccessToken), $data);
    }

    /**
     * 客服文本消息（公众号/小程序通用 message/custom/send）
     */
    public function sendCustomerText(string $authorizerAccessToken, string $openid, string $content): array
    {
        return $this->postJson('/cgi-bin/message/custom/send?access_token=' . urlencode($authorizerAccessToken), [
            'touser'  => $openid,
            'msgtype' => 'text',
            'text'    => ['content' => $content],
        ]);
    }

    /* ==================== 卡券 ==================== */

    /** 创建卡券（card/create） */
    public function cardCreate(string $authorizerAccessToken, array $card): array
    {
        return $this->postJson('/card/create?access_token=' . urlencode($authorizerAccessToken), [
            'card' => $card,
        ]);
    }

    /** 查询卡券详情（card/get） */
    public function cardGet(string $authorizerAccessToken, string $cardId): array
    {
        return $this->postJson('/card/get?access_token=' . urlencode($authorizerAccessToken), [
            'card_id' => $cardId,
        ]);
    }

    /** 拉取会员会员卡资料（card/membercard/userinfo/get） */
    public function cardMembercardUserinfoGet(string $authorizerAccessToken, string $cardId, string $code = '', string $openid = ''): array
    {
        $data = ['card_id' => $cardId];
        if ('' !== $code) {
            $data['code'] = $code;
        }
        if ('' !== $openid) {
            $data['openid'] = $openid;
        }

        return $this->postJson('/card/membercard/userinfo/get?access_token=' . urlencode($authorizerAccessToken), $data);
    }

    /** 创建卡券二维码（card/qrcode/create） */
    public function cardQrcodeCreate(string $authorizerAccessToken, array $actionInfo): array
    {
        return $this->postJson('/card/qrcode/create?access_token=' . urlencode($authorizerAccessToken), [
            'action_name' => $actionInfo['action_name'] ?? 'QR_CARD',
            'expire_seconds' => (int) ($actionInfo['expire_seconds'] ?? 1800),
            'action_info' => $actionInfo['action_info'] ?? [],
        ]);
    }

    /* ==================== 小程序：订阅消息 ==================== */

    /** 组合模板并添加至账号（wxaapi/newtmpl/addtemplate，tid+kidList，返回 priTmplId） */
    public function newTmplAddTemplate(string $authorizerAccessToken, int $tid, array $kidList, int $scene = 2, string $userType = ''): array
    {
        $data = [
            'tid'     => $tid,
            'kidList' => array_values(array_map('intval', $kidList)),
            'scene'   => $scene,
        ];
        if ('' !== $userType) {
            $data['userType'] = $userType;
        }

        return $this->postJson('/wxaapi/newtmpl/addtemplate?access_token=' . urlencode($authorizerAccessToken), $data);
    }

    /** 获取小程序账号类目（wxaapi/newtmpl/gettitle，选模板前置步骤） */
    public function newTmplGetTitle(string $authorizerAccessToken): array
    {
        return $this->getJson('/wxaapi/newtmpl/gettitle?access_token=' . urlencode($authorizerAccessToken));
    }

    /** 发送小程序订阅消息（message/subscribe/send） */
    public function subscribeSend(string $authorizerAccessToken, array $data): array
    {
        return $this->postJson('/cgi-bin/message/subscribe/send?access_token=' . urlencode($authorizerAccessToken), $data);
    }

    /* ==================== 小程序：类目 / URLLink / 半屏 ==================== */

    /** 获取已设置的所有类目（wxa/get_category） */
    public function getCategory(string $authorizerAccessToken): array
    {
        return $this->getJson('/wxa/get_category?access_token=' . urlencode($authorizerAccessToken));
    }

    /** 添加类目（wxa/add_category） */
    public function addCategory(string $authorizerAccessToken, array $data): array
    {
        return $this->postJson('/wxa/add_category?access_token=' . urlencode($authorizerAccessToken), $data);
    }

    /** 删除类目（wxa/delete_category） */
    public function deleteCategory(string $authorizerAccessToken, int $first, int $second): array
    {
        return $this->postJson('/wxa/delete_category?access_token=' . urlencode($authorizerAccessToken), [
            'first'  => $first,
            'second' => $second,
        ]);
    }

    /** 获取小程序 URL Link（wxa/generate_urllink） */
    public function generateUrlLink(string $authorizerAccessToken, array $data): array
    {
        return $this->postJson('/wxa/generate_urllink?access_token=' . urlencode($authorizerAccessToken), $data);
    }

    /** 半屏小程序初始化（embedded/initialize，返回可用半屏小程序 appid 列表） */
    public function embeddedInitialize(string $authorizerAccessToken): array
    {
        return $this->getJson('/wxa/embedded/initialize?access_token=' . urlencode($authorizerAccessToken));
    }

    /* ==================== 小程序：直播 ==================== */

    /**
     * 直播间主播二维码（对标参考系统 Live::getpusherqr → getQRCode，
     * wxacodeunlimit + scene=房间code + page=推流页；返回图片二进制或错误 JSON）
     */
    public function getLivePusherQr(string $authorizerAccessToken, string $scene, string $page = 'pages/live/pusher', string $envVersion = ''): string
    {
        $data = [
            'scene' => $scene,
            'page'  => $page,
        ];
        if ('' !== $envVersion) {
            $data['env_version'] = $envVersion;
        }

        return $this->postRaw('/wxa/getwxacodeunlimit?access_token=' . urlencode($authorizerAccessToken), $data);
    }

    /* ==================== 代认证 / 账号基础 ==================== */

    /** 获取账号基本信息（代认证 getaccountbasicinfo） */
    public function getAccountBasicInfo(string $authorizerAccessToken): array
    {
        return $this->getJson('/cgi-bin/account/getaccountbasicinfo?access_token=' . urlencode($authorizerAccessToken));
    }

    /**
     * 普通公众号/小程序 access_token（手动绑定 authtype=0 场景）
     */
    public function apiCgiToken(string $appid, string $secret): array
    {
        return $this->getJson('/cgi-bin/token?grant_type=client_credential&appid=' . urlencode($appid) . '&secret=' . urlencode($secret));
    }

    /**
     * POST JSON 并解码
     *
     * @return array<string, mixed>
     */
    protected function postJson(string $path, array $data): array
    {
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $this->decodeResponse((string) ($this->transport)(self::BASE . $path, 'POST', $body === false ? '{}' : $body));
    }

    /**
     * POST 原始响应（可能为图片二进制）
     */
    protected function postRaw(string $path, array $data): string
    {
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return (string) ($this->transport)(self::BASE . $path, 'POST', $body === false ? '{}' : $body);
    }

    /**
     * POST 文件（multipart/form-data；body 以 @file: 前缀标记，默认 curl 传输层解析为 CURLFile）
     *
     * @return array<string, mixed>
     */
    protected function postFile(string $path, string $filePath, string $field = 'media'): array
    {
        $marker = '@file:' . json_encode(['field' => $field, 'file' => $filePath], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $this->decodeResponse((string) ($this->transport)(self::BASE . $path, 'POST', (string) $marker));
    }

    /**
     * GET JSON 并解码
     *
     * @return array<string, mixed>
     */
    protected function getJson(string $path): array
    {
        return $this->decodeResponse((string) ($this->transport)(self::BASE . $path, 'GET', ''));
    }

    /**
     * GET 原始响应（可能为图片二进制）
     */
    protected function getRaw(string $path): string
    {
        return (string) ($this->transport)(self::BASE . $path, 'GET', '');
    }

    /**
     * 解码响应（JSON 失败时返回错误结构）
     *
     * @return array<string, mixed>
     */
    protected function decodeResponse(string $resp): array
    {
        $decoded = json_decode($resp, true);

        return is_array($decoded) ? $decoded : ['errcode' => -1, 'errmsg' => '响应解析失败: ' . mb_substr($resp, 0, 200)];
    }

    /**
     * 默认 curl 传输
     */
    protected static function curlTransport(string $url, string $method, string $body): string
    {
        // 文件上传约定：body 以 @file: 开头 → multipart/form-data（media/upload 等）
        $multipart = [];
        if (str_starts_with($body, '@file:')) {
            $meta = json_decode(substr($body, 6), true);
            if (is_array($meta) && is_file((string) ($meta['file'] ?? ''))) {
                $multipart = [(string) ($meta['field'] ?? 'media') => new \CURLFile((string) $meta['file'])];
            }
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_POST           => 'POST' === $method,
            CURLOPT_HTTPHEADER     => 'POST' === $method && empty($multipart) ? ['Content-Type: application/json; charset=utf-8'] : [],
        ]);
        if ('POST' === $method) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, !empty($multipart) ? $multipart : $body);
        }
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);

        return false === $resp ? '{"errcode":-1,"errmsg":"curl error: ' . addslashes($err) . '"}' : (string) $resp;
    }

    /* ==================== 微信代认证专用（任务216 批次7-8，仅追加） ==================== */

    /** 小程序微信认证：提交认证申请（wxa/sec/wxaauth，body 为 auth_data 报文） */
    public function wxaAuth(string $authorizerAccessToken, array $authData): array
    {
        return $this->postJson('/wxa/sec/wxaauth?access_token=' . urlencode($authorizerAccessToken), [
            'auth_data' => $authData,
        ]);
    }

    /** 小程序微信认证：打回重审重新提交（wxa/sec/reauth，taskid 必填） */
    public function wxaReauth(string $authorizerAccessToken, array $authData): array
    {
        return $this->postJson('/wxa/sec/reauth?access_token=' . urlencode($authorizerAccessToken), [
            'auth_data' => $authData,
        ]);
    }

    /** 小程序微信认证：查询认证任务状态（wxa/sec/queryauth） */
    public function queryAuthTask(string $authorizerAccessToken, string $taskid): array
    {
        return $this->postJson('/wxa/sec/queryauth?access_token=' . urlencode($authorizerAccessToken), [
            'taskid' => $taskid,
        ]);
    }

    /** 小程序认证上传补充材料（wxa/sec/uploadauthmaterial，返回 mediaid） */
    public function uploadAuthMaterial(string $authorizerAccessToken, string $filePath): array
    {
        return $this->postFile('/wxa/sec/uploadauthmaterial?access_token=' . urlencode($authorizerAccessToken), $filePath);
    }

    /** 查询个人认证身份选项树（wxa/sec/authidentitytree） */
    public function authIdentityTree(string $authorizerAccessToken): array
    {
        return $this->getJson('/wxa/sec/authidentitytree?access_token=' . urlencode($authorizerAccessToken));
    }

    /** 检查小程序认证名称是否合规（cgi-bin/wxverify/checkwxverifynickname） */
    public function checkWxVerifyNickname(string $authorizerAccessToken, string $nickname): array
    {
        return $this->postJson('/cgi-bin/wxverify/checkwxverifynickname?access_token=' . urlencode($authorizerAccessToken), [
            'nick_name' => $nickname,
        ]);
    }
}
