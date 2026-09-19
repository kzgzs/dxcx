<?php

declare(strict_types=1);

namespace app\open\controller;

use app\BaseController;
use app\common\service\OpenMsgService;
use app\common\service\OpenPlatformService;
use app\common\service\WxBizCrypt;
use app\common\service\WxBizCryptException;
use think\facade\Log;

/**
 * 开放平台 - 消息路由（全网发布检测入口）
 *
 * POST /open/msg/{appid}
 * 解密按 authorizer 归属兜底：先按组件 appid 解密，失败再按路径 appid 解密
 */
class Msg extends BaseController
{
    public function __construct(\think\App $app, protected OpenPlatformService $open, protected OpenMsgService $msgService)
    {
        parent::__construct($app);
    }

    public function handle($appid)
    {
        $appid  = (string) $appid;
        $config = $this->open->config();
        $token  = (string) $config['token'];
        $aesKey = (string) $config['encoding_aes_key'];

        if ('' === $token || '' === $aesKey) {
            return response('fail', 400)->contentType('text/plain');
        }

        $body       = (string) $this->request->getInput();
        $msgSign    = (string) $this->request->param('msg_signature', '');
        $timestamp  = (string) $this->request->param('timestamp', '');
        $nonce      = (string) $this->request->param('nonce', '');

        // 归属解密：组件 appid 优先，失败回退路径 appid（覆盖官方测试号等场景）
        $xml = null;
        foreach (array_unique(array_filter([(string) $config['component_appid'], $appid])) as $tryAppid) {
            try {
                $crypt = new WxBizCrypt($token, $aesKey, $tryAppid);
                $xml   = $crypt->decryptMsg($msgSign, $timestamp, $nonce, $body);
                break;
            } catch (WxBizCryptException $e) {
                // -40005 appid 不匹配时继续尝试下一个
                if (-40005 !== $e->getCode()) {
                    Log::error('open.msg 解密失败 appid=' . $appid . ' code=' . $e->getCode());

                    return response('fail', 400)->contentType('text/plain');
                }
            }
        }

        if (null === $xml) {
            Log::error('open.msg 解密归属匹配失败 appid=' . $appid);

            return response('fail', 400)->contentType('text/plain');
        }

        try {
            $msg  = WxBizCrypt::xmlToArray($xml);
        } catch (WxBizCryptException) {
            return response('fail', 400)->contentType('text/plain');
        }

        $isTest = $this->msgService->isTestAppid($appid);
        if ($isTest) {
            Log::info('open.msg 全网发布测试号消息 appid=' . $appid);
        }

        $reply = $this->msgService->handleMsg($appid, $msg);

        // 空串：QUERY_AUTH_CODE 分支（客服消息异步回复，不被动回复）
        if ('' === $reply || 'success' === $reply) {
            return response($reply)->contentType('text/plain');
        }

        // 被动回复：加密打包后返回（第三方场景密文归属组件 appid）
        try {
            $encrypted = $this->open->crypt($config)->encryptMsg($reply, $timestamp, $nonce);
        } catch (\Throwable $e) {
            Log::error('open.msg 加密回复失败 appid=' . $appid . ' : ' . $e->getMessage());

            return response('success')->contentType('text/plain');
        }

        return response($encrypted)->contentType('application/xml');
    }
}
