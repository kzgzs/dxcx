<?php

declare(strict_types=1);

namespace app\common\service;

use think\facade\Db;
use think\facade\Log;

/**
 * 开放平台消息路由服务（/open/msg/{appid}，含全网发布接入检测）
 *
 * 批次7-9 任务217 扩展（对标参考系统 ApiWechat 消息分发路数）：
 * - subscribe：粉丝表 upsert + 会员自动注册/绑定（mpopenid 幂等，unionid 归并），
 *   并按 mp_keyword(ktype=2) 最多 3 条按 sort/id 顺序保序回复（首条被动回复，其余客服消息）
 * - unsubscribe：粉丝/会员置未关注，不删会员
 * - CLICK：菜单点击按 mp_menukey 映射被动回复文本
 * - user_get_card / user_del_card：落 membercard_record 领取记录（与任务214 MembercardService 打通）
 */
class OpenMsgService
{
    /** 全网发布检测官方测试号（5 组 x 公众号/小程序，微信官方文档） */
    public const TEST_APPIDS = [
        'wx570bc396a51b8ff8', 'wx9252c5e0bb1836fc', 'wx8e1097c5bc82cde9', 'wx14550af28c71a144', 'wxa35b9c23cfe664eb',
        'wxd101a85aa106f53e', 'wxc39235c15087f6f3', 'wx7720d01d4b2a4500', 'wx05d483572dcd5d8b', 'wx5910277cae6fd970',
    ];

    /** 全网发布检测关键词 */
    public const TEST_TEXT         = 'TESTCOMPONENT_MSG_TYPE_TEXT';
    public const QUERY_AUTH_PREFIX = 'QUERY_AUTH_CODE:';

    public function __construct(protected OpenPlatformService $open, protected OpenCodeService $code)
    {
    }

    /**
     * 处理解密后的消息
     *
     * @param array<string, string> $msg 明文字段（MsgType/Content/FromUserName/ToUserName...）
     * @return string 被动回复明文 XML（空串=不回复，'success'=仅记录）
     */
    public function handleMsg(string $appid, array $msg): string
    {
        $msgType = (string) ($msg['MsgType'] ?? '');
        $content = (string) ($msg['Content'] ?? '');

        // 审核结果事件（weapp_audit_success / weapp_audit_fail）
        if ('event' === $msgType) {
            return $this->handleEvent($appid, $msg);
        }

        if ('text' === $msgType && self::TEST_TEXT === $content) {
            // 公众号消息管理检测：被动回复 TESTCOMPONENT_MSG_TYPE_TEXT_callback
            return $this->textReplyXml($msg, self::TEST_TEXT . '_callback');
        }

        if ('text' === $msgType && str_starts_with($content, self::QUERY_AUTH_PREFIX)) {
            $authCode = substr($content, strlen(self::QUERY_AUTH_PREFIX));
            // 客服消息检测：先返回空串不被动回复，快路径调客服接口
            $this->queryAuthCodeCustomerReply($appid, $authCode, (string) ($msg['FromUserName'] ?? ''));

            return '';
        }

        Log::info('open.msg 未识别消息 appid=' . $appid . ' type=' . $msgType . ' content=' . mb_substr($content, 0, 100));

        return 'success';
    }

    /**
     * 事件消息分发（代小程序审核结果 + 公众号关注/菜单/卡券事件；
     * 异常仅记日志，保证对微信返回 success）
     */
    protected function handleEvent(string $appid, array $msg): string
    {
        $event = strtolower((string) ($msg['Event'] ?? ''));

        try {
            switch ($event) {
                case 'weapp_audit_success':
                    $this->code->handleAuditSuccess($appid, $msg);
                    break;

                case 'weapp_audit_fail':
                    $this->code->handleAuditFail($appid, $msg);
                    break;

                case 'subscribe':
                    return $this->handleSubscribe($appid, $msg);

                case 'unsubscribe':
                    $this->handleUnsubscribe($appid, $msg);
                    break;

                case 'click':
                    return $this->handleMenuClick($appid, $msg);

                case 'user_get_card':
                    $this->handleUserGetCard($appid, $msg);
                    break;

                case 'user_del_card':
                    $this->handleUserDelCard($appid, $msg);
                    break;

                default:
                    Log::info('open.msg 未识别事件 appid=' . $appid . ' event=' . $event);
                    break;
            }
        } catch (\Throwable $e) {
            Log::error('open.msg 事件处理失败 appid=' . $appid . ' event=' . $event . ' : ' . $e->getMessage());
        }

        return 'success';
    }

    /* ==================== 公众号关注/取关（任务217-1） ==================== */

    /**
     * 关注事件：粉丝落库 + 会员注册/绑定 + 被关注回复（多条保序）
     *
     * @return string 被动回复 XML（无可被动回复时返回空串）
     */
    protected function handleSubscribe(string $appid, array $msg): string
    {
        $openid = (string) ($msg['FromUserName'] ?? '');
        if ('' === $openid) {
            return 'success';
        }

        $aid = $this->resolveAidByAppid($appid);
        if ($aid <= 0) {
            Log::info('open.msg subscribe 未绑定租户 appid=' . $appid . ' openid=' . $openid);

            return 'success';
        }

        $userinfo = $this->fetchMpUserInfo($appid, $openid);
        $memberId = $this->bindMemberOnSubscribe($aid, $openid, $userinfo);
        $this->upsertFansOnEvent($aid, $openid, $userinfo, $memberId);

        Log::info('open.msg subscribe 会员注册/绑定 appid=' . $appid . ' aid=' . $aid
            . ' openid=' . $openid . ' mid=' . $memberId);

        return $this->subscribeAutoReply($aid, $appid, $openid, $msg);
    }

    /** 取消关注：粉丝/会员置未关注（不删会员、不删粉丝记录） */
    protected function handleUnsubscribe(string $appid, array $msg): void
    {
        $openid = (string) ($msg['FromUserName'] ?? '');
        $aid    = $this->resolveAidByAppid($appid);
        if ('' === $openid || $aid <= 0) {
            Log::info('open.msg unsubscribe 跳过 appid=' . $appid . ' openid=' . $openid);

            return;
        }

        Db::name('fans')->where('aid', $aid)->where('openid', $openid)->update(['subscribe' => 0]);
        ScopeService::query('member', $aid)->where('mpopenid', $openid)->update(['subscribe' => 0]);

        Log::info('open.msg unsubscribe appid=' . $appid . ' aid=' . $aid . ' openid=' . $openid);
    }

    /** appid → 租户（租户绑定公众号优先，授权绑定兜底；无绑定返回 0） */
    protected function resolveAidByAppid(string $appid): int
    {
        $aid = (int) (Db::name('admin_setapp_mp')->where('appid', $appid)->value('aid') ?: 0);
        if ($aid > 0) {
            return $aid;
        }

        return (int) (Db::name('open_authorizer')->where('authorizer_appid', $appid)->value('aid') ?: 0);
    }

    /** 拉取公众号用户资料（mock 预置报文；真实 user/info，失败降级最小报文） */
    protected function fetchMpUserInfo(string $appid, string $openid): array
    {
        if ($this->open->isMock()) {
            return [
                'openid'          => $openid,
                'subscribe'       => 1,
                'nickname'        => 'Mock粉丝' . substr($openid, -4),
                'sex'             => 0,
                'headimgurl'      => 'https://mock.dxcx.local/head/' . md5($openid) . '.png',
                'unionid'         => 'MOCK_UNION_' . substr(md5($openid), 0, 12),
                'subscribe_time'  => time(),
                'subscribe_scene' => 'ADD_SCENE_QRCODE',
                'language'        => 'zh_CN',
                'city'            => '',
                'province'        => '',
                'country'         => '',
            ];
        }

        try {
            $rs = $this->open->callWithAuthorizerToken($appid, fn (string $token) => $this->open->api()->userInfo($token, $openid));
            if (0 === (int) ($rs['errcode'] ?? 0) && '' !== (string) ($rs['openid'] ?? '')) {
                return $rs;
            }
            Log::warning('open.msg user/info 失败 appid=' . $appid . ' errcode=' . ($rs['errcode'] ?? '?') . ' errmsg=' . ($rs['errmsg'] ?? ''));
        } catch (\Throwable $e) {
            Log::warning('open.msg user/info 异常 appid=' . $appid . ' : ' . $e->getMessage());
        }

        return ['openid' => $openid, 'subscribe' => 1];
    }

    /**
     * 关注注册会员/绑定（幂等：mpopenid 直查 > unionid 归并 > 自动注册；
     * 注册走既有会员体系口径：nickname/注册赠送 GiveService）
     *
     * @param array<string, mixed> $userinfo
     */
    protected function bindMemberOnSubscribe(int $aid, string $openid, array $userinfo): int
    {
        $member  = ScopeService::query('member', $aid)->where('mpopenid', $openid)->find();
        $unionid = trim((string) ($userinfo['unionid'] ?? ''));

        // unionid 归并：小程序已注册但公众号未绑定的同 unionid 会员
        if (empty($member) && '' !== $unionid) {
            $member = ScopeService::query('member', $aid)
                ->where('unionid', $unionid)
                ->where(static function ($q) {
                    $q->whereNull('mpopenid')->whereOr('mpopenid', '');
                })
                ->find();
            if (!empty($member)) {
                Db::name('member')->where('id', (int) $member['id'])->update(['mpopenid' => $openid]);
                $member['mpopenid'] = $openid;
            }
        }

        $subscribeTime = (int) ($userinfo['subscribe_time'] ?? time());

        if (!empty($member)) {
            $update = ['subscribe' => 1, 'subscribe_time' => $subscribeTime];
            if ('' === trim((string) ($member['nickname'] ?? ''))) {
                $update['nickname'] = $this->mpNickname($openid, $userinfo);
                $update['headimg']  = (string) ($userinfo['headimgurl'] ?? '');
            }
            Db::name('member')->where('id', (int) $member['id'])->update($update);

            return (int) $member['id'];
        }

        $memberId = (int) Db::name('member')->insertGetId([
            'aid'            => $aid,
            'bid'            => 0,
            'mpopenid'       => $openid,
            'unionid'        => $unionid,
            'nickname'       => $this->mpNickname($openid, $userinfo),
            'headimg'        => (string) ($userinfo['headimgurl'] ?? ''),
            'sex'            => ['1' => '男', '2' => '女'][(string) ($userinfo['sex'] ?? 0)] ?? '',
            'tel'            => '',
            'pwd'            => '',
            'score'          => 0,
            'money'          => 0,
            'platform'       => 'mp',
            'subscribe'      => 1,
            'subscribe_time' => $subscribeTime,
            'createtime'     => time(),
        ]);

        // 注册赠送（与小程序静默登录同口径，异常不阻断回调）
        try {
            app(GiveService::class)->grantOnRegister($aid, $memberId);
        } catch (\Throwable $e) {
            Log::error('open.msg 关注注册赠送失败 member#' . $memberId . ': ' . $e->getMessage());
        }

        return $memberId;
    }

    /** 粉丝表 upsert（关注事件口径；member 已存在则昵称优先取会员） */
    protected function upsertFansOnEvent(int $aid, string $openid, array $userinfo, int $memberId): void
    {
        $data = [
            'aid'             => $aid,
            'subscribe'       => 1,
            'openid'          => $openid,
            'unionid'         => (string) ($userinfo['unionid'] ?? ''),
            'language'        => (string) ($userinfo['language'] ?? ''),
            'city'            => (string) ($userinfo['city'] ?? ''),
            'province'        => (string) ($userinfo['province'] ?? ''),
            'country'         => (string) ($userinfo['country'] ?? ''),
            'subscribe_time'  => (int) ($userinfo['subscribe_time'] ?? time()),
            'subscribe_scene' => (string) ($userinfo['subscribe_scene'] ?? ''),
            'messagetime'     => time(),
            'doing'           => 0,
        ];

        $member = $memberId > 0 ? Db::name('member')->where('id', $memberId)->find() : null;
        if (!empty($member) && '' !== trim((string) ($member['nickname'] ?? ''))) {
            $data['nickname']   = (string) $member['nickname'];
            $data['headimgurl'] = (string) ($member['headimg'] ?? '');
            $data['sex']        = '' !== (string) ($member['sex'] ?? '') ? (string) $member['sex'] : '未知';
        } else {
            $data['nickname']   = (string) ($userinfo['nickname'] ?? '');
            $data['headimgurl'] = (string) ($userinfo['headimgurl'] ?? '');
            $data['sex']        = ['1' => '男', '2' => '女'][(string) ($userinfo['sex'] ?? 0)] ?? '未知';
        }

        $exists = Db::name('fans')->where('aid', $aid)->where('openid', $openid)->find();
        if (!empty($exists)) {
            Db::name('fans')->where('id', (int) $exists['id'])->update($data);
        } else {
            Db::name('fans')->insert($data);
        }
    }

    /** 会员昵称（微信资料优先，兜底 微信用户+openid 后4位，与会员体系口径一致） */
    protected function mpNickname(string $openid, array $userinfo): string
    {
        $nickname = trim((string) ($userinfo['nickname'] ?? ''));

        return '' !== $nickname ? $nickname : '微信用户' . substr($openid, -4);
    }

    /* ==================== 被关注回复多条保序（任务217-2） ==================== */

    /**
     * 被关注回复：mp_keyword(ktype=2) 最多 3 条按 sort/id 顺序依次回复；
     * 首条为文本时走被动回复 XML，其余一律客服消息（被动回复仅支持单条）
     *
     * @return string 被动回复 XML；无可被动回复返回空串
     */
    protected function subscribeAutoReply(int $aid, string $appid, string $openid, array $msg): string
    {
        $rows = ScopeService::query('mp_keyword', $aid)
            ->where('ktype', 2)
            ->where('status', 1)
            ->order('sort desc, id asc')
            ->limit(MpService::SUBSCRIBE_MAX)
            ->select()
            ->toArray();

        if (empty($rows)) {
            return '';
        }

        $passive = '';
        foreach ($rows as $i => $row) {
            if (0 === $i && 'text' === (string) ($row['msgtype'] ?? '')) {
                $passive = $this->textReplyXml($msg, (string) ($row['content'] ?? ''));
                continue;
            }
            $this->sendKfMsgByKeyword($appid, $openid, (array) $row);
        }

        return $passive;
    }

    /** 按 mp_keyword 记录组装并发送客服消息（mock 仅记日志） @param array<string, mixed> $row */
    protected function sendKfMsgByKeyword(string $appid, string $openid, array $row): void
    {
        $payload = $this->buildKfPayload($openid, (string) ($row['msgtype'] ?? ''), (string) ($row['content'] ?? ''));
        if (empty($payload)) {
            Log::info('open.msg 客服回复跳过（不支持的类型）appid=' . $appid . ' msgtype=' . ($row['msgtype'] ?? ''));

            return;
        }

        if ($this->open->isMock()) {
            Log::info('open.msg 客服回复(mock) appid=' . $appid . ' openid=' . $openid
                . ' payload=' . (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return;
        }

        try {
            $rs = $this->open->callWithAuthorizerToken($appid, fn (string $token) => $this->open->api()->sendCustomerMessage($token, $payload));
            if (0 !== (int) ($rs['errcode'] ?? 0)) {
                Log::error('open.msg 客服回复失败 appid=' . $appid . ' errcode=' . ($rs['errcode'] ?? '?') . ' errmsg=' . ($rs['errmsg'] ?? ''));
            }
        } catch (\Throwable $e) {
            Log::error('open.msg 客服回复异常 appid=' . $appid . ' : ' . $e->getMessage());
        }
    }

    /**
     * mp_keyword.content → 客服消息报文（content 口径见 MpService::buildReplyContent）
     *
     * @return array<string, mixed> 空数组=不支持的类型
     */
    protected function buildKfPayload(string $openid, string $msgtype, string $content): array
    {
        $json = json_decode($content, true);
        $json = is_array($json) ? $json : [];

        switch ($msgtype) {
            case 'text':
                return ['touser' => $openid, 'msgtype' => 'text', 'text' => ['content' => $content]];

            case 'image':
                return '' !== (string) ($json['MediaId'] ?? '')
                    ? ['touser' => $openid, 'msgtype' => 'image', 'image' => ['media_id' => (string) $json['MediaId']]]
                    : [];

            case 'voice':
                return '' !== (string) ($json['MediaId'] ?? '')
                    ? ['touser' => $openid, 'msgtype' => 'voice', 'voice' => ['media_id' => (string) $json['MediaId']]]
                    : [];

            case 'video':
                return '' !== (string) ($json['MediaId'] ?? '')
                    ? ['touser' => $openid, 'msgtype' => 'video', 'video' => [
                        'media_id'    => (string) $json['MediaId'],
                        'title'       => (string) ($json['title'] ?? ''),
                        'description' => (string) ($json['description'] ?? ''),
                    ]] : [];

            case 'news':
                return ['touser' => $openid, 'msgtype' => 'news', 'news' => ['articles' => [[
                    'title'       => (string) ($json['title'] ?? ''),
                    'description' => (string) ($json['digest'] ?? ''),
                    'url'         => (string) ($json['url'] ?? ''),
                    'picurl'      => (string) ($json['thumb_url'] ?? ''),
                ]]]];

            case 'miniprogrampage':
                return '' !== (string) ($json['MediaId'] ?? '')
                    ? ['touser' => $openid, 'msgtype' => 'miniprogrampage', 'miniprogrampage' => [
                        'title'          => (string) ($json['title'] ?? ''),
                        'appid'          => (string) ($json['appid'] ?? ''),
                        'pagepath'       => (string) ($json['pagepath'] ?? ''),
                        'thumb_media_id' => (string) $json['MediaId'],
                    ]] : [];

            default:
                return [];
        }
    }

    /* ==================== 菜单 CLICK 事件（任务217-3） ==================== */

    /** CLICK 菜单事件：按 mp_menukey 映射被动回复文本（无映射仅记日志） */
    protected function handleMenuClick(string $appid, array $msg): string
    {
        $key = (string) ($msg['EventKey'] ?? '');
        if ('' === $key) {
            return 'success';
        }

        $text = (string) (Db::name('mp_menukey')->where('key', $key)->value('val') ?: '');
        if ('' === $text) {
            Log::info('open.msg CLICK 无映射 appid=' . $appid . ' key=' . $key);

            return 'success';
        }

        return $this->textReplyXml($msg, $text);
    }

    /* ==================== 卡券事件（任务217-4） ==================== */

    /** user_get_card：落会员卡领取记录（幂等：同 aid+card_id+card_code 复用旧记录复位） */
    protected function handleUserGetCard(string $appid, array $msg): void
    {
        $aid    = $this->resolveAidByAppid($appid);
        $openid = (string) ($msg['FromUserName'] ?? '');
        $cardId = (string) ($msg['CardId'] ?? '');
        $code   = (string) ($msg['UserCardCode'] ?? '');

        if ($aid <= 0 || '' === $cardId || '' === $code) {
            Log::info('open.msg user_get_card 跳过 appid=' . $appid . ' card_id=' . $cardId);

            return;
        }

        $member = ScopeService::query('member', $aid)->where('mpopenid', $openid)->find();

        $exists = Db::name('membercard_record')->where('aid', $aid)
            ->where('card_id', $cardId)->where('card_code', $code)->find();
        if (!empty($exists)) {
            Db::name('membercard_record')->where('id', (int) $exists['id'])->update([
                'status'     => 1,
                'mid'        => (int) ($member['id'] ?? 0),
                'createtime' => time(),
            ]);
        } else {
            Db::name('membercard_record')->insert([
                'aid'        => $aid,
                'card_id'    => $cardId,
                'card_code'  => $code,
                'mid'        => (int) ($member['id'] ?? 0),
                'openid'     => $openid,
                'unionid'    => (string) ($member['unionid'] ?? ''),
                'nickname'   => (string) ($member['nickname'] ?? ''),
                'headimg'    => (string) ($member['headimg'] ?? ''),
                'name'       => (string) ($member['realname'] ?? ''),
                'mobile'     => (string) ($member['tel'] ?? ''),
                'status'     => 1,
                'createtime' => time(),
            ]);
        }

        // 会员卡号回写会员（对标参考系统 member.card_id/card_code 口径）
        if (!empty($member)) {
            Db::name('member')->where('id', (int) $member['id'])
                ->update(['card_id' => $cardId, 'card_code' => $code]);
        }

        Log::info('open.msg user_get_card 落库 appid=' . $appid . ' aid=' . $aid
            . ' card_id=' . $cardId . ' code=' . $code . ' openid=' . $openid);
    }

    /** user_del_card：领取记录置删除态（不物理删） */
    protected function handleUserDelCard(string $appid, array $msg): void
    {
        $aid    = $this->resolveAidByAppid($appid);
        $cardId = (string) ($msg['CardId'] ?? '');
        $code   = (string) ($msg['UserCardCode'] ?? '');

        if ($aid <= 0 || '' === $cardId || '' === $code) {
            Log::info('open.msg user_del_card 跳过 appid=' . $appid . ' card_id=' . $cardId);

            return;
        }

        $affected = Db::name('membercard_record')->where('aid', $aid)
            ->where('card_id', $cardId)->where('card_code', $code)
            ->update(['status' => 2]);

        Log::info('open.msg user_del_card appid=' . $appid . ' aid=' . $aid
            . ' card_id=' . $cardId . ' code=' . $code . ' affected=' . $affected);
    }

    /** QUERY_AUTH_CODE 客服回复（快路径；失败仅记日志不打断响应） */
    public function queryAuthCodeCustomerReply(string $appid, string $authCode, string $openid): void
    {
        try {
            // 用推送的 auth_code 换授权信息（测试号自动授权场景）
            $row = $this->open->saveAuthorizerByAuthCode($authCode);

            // mock 模式下预置授权方 appid 可能 != 消息 appid，以落库记录为准
            $target  = $this->open->isMock() ? (string) ($row['authorizer_appid'] ?? $appid) : $appid;
            $content = $authCode . '_from_api';
            $rs = $this->open->callWithAuthorizerToken($target, function (string $token) use ($openid, $content) {
                if ($this->open->isMock()) {
                    return ['errcode' => 0, 'errmsg' => 'mock ok'];
                }

                return $this->open->api()->sendCustomerText($token, $openid, $content);
            });

            Log::info('open.msg QUERY_AUTH_CODE 客服回复动作 appid=' . $appid . ' code=' . $authCode
                . ' openid=' . $openid . ' result=' . json_encode($rs, JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            Log::error('open.msg QUERY_AUTH_CODE 处理失败 appid=' . $appid . ' : ' . $e->getMessage());
        }
    }

    /** 被动文本回复 XML */
    protected function textReplyXml(array $msg, string $content): string
    {
        return sprintf(
            "<xml><ToUserName><![CDATA[%s]]></ToUserName><FromUserName><![CDATA[%s]]></FromUserName><CreateTime>%d</CreateTime><MsgType><![CDATA[text]]></MsgType><Content><![CDATA[%s]]></Content></xml>",
            (string) ($msg['FromUserName'] ?? ''),
            (string) ($msg['ToUserName'] ?? ''),
            time(),
            $content
        );
    }

    /** 是否全网发布测试号 */
    public function isTestAppid(string $appid): bool
    {
        return in_array($appid, self::TEST_APPIDS, true);
    }
}
