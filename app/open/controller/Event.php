<?php

declare(strict_types=1);

namespace app\open\controller;

use app\BaseController;
use app\common\service\OpenPlatformService;
use app\common\service\WxBizCrypt;
use app\common\service\WxBizCryptException;
use think\facade\Log;

/**
 * 开放平台 - 事件推送（第三方平台服务端 URL）
 *
 * GET  /open/event  URL 有效性校验（原样返回 echostr）
 * POST /open/event  解密后按 InfoType 分发（verify_ticket / authorized / unauthorized）
 */
class Event extends BaseController
{
    public function __construct(\think\App $app, protected OpenPlatformService $open)
    {
        parent::__construct($app);
    }

    /**
     * URL 校验：signature/timestamp/nonce/echostr
     */
    public function verify()
    {
        $signature = (string) $this->request->param('signature', '');
        $timestamp = (string) $this->request->param('timestamp', '');
        $nonce     = (string) $this->request->param('nonce', '');
        $echostr   = (string) $this->request->param('echostr', '');

        $config = $this->open->config();
        $token  = (string) $config['token'];

        if ('' === $token) {
            return response('component not configured', 403)->contentType('text/plain');
        }

        if (!WxBizCrypt::checkSignature($token, $timestamp, $nonce, $signature)) {
            Log::warning('open.event 校验失败 signature=' . $signature);

            return response('invalid signature', 403)->contentType('text/plain');
        }

        // 校验通过：原样返回 echostr
        return response($echostr)->contentType('text/plain');
    }

    /**
     * 事件推送：解密后按 InfoType 分发，立即返回 success
     */
    public function receive()
    {
        $config = $this->open->config();

        if ('' === (string) $config['token'] || '' === (string) $config['encoding_aes_key']) {
            Log::error('open.event 组件未配置仍收到推送');

            return response('fail', 400)->contentType('text/plain');
        }

        try {
            $crypt = $this->open->crypt($config);
            $xml   = $crypt->decryptMsg(
                (string) $this->request->param('msg_signature', ''),
                (string) $this->request->param('timestamp', ''),
                (string) $this->request->param('nonce', ''),
                (string) $this->request->getInput()
            );
        } catch (WxBizCryptException $e) {
            Log::error('open.event 解密失败 code=' . $e->getCode() . ' msg=' . $e->getMessage());

            return response('fail', 400)->contentType('text/plain');
        }

        $event    = WxBizCrypt::xmlToArray($xml);
        $infoType = (string) ($event['InfoType'] ?? '');

        switch ($infoType) {
            case 'component_verify_ticket':
                // Redis + DB 流水，立即返回 success
                $this->open->receiveTicket(
                    (string) ($event['ComponentVerifyTicket'] ?? ''),
                    (string) $this->request->ip()
                );
                break;

            case 'authorized':
            case 'updateauthorized':
                $this->open->handleAuthorized($event);
                break;

            case 'unauthorized':
                $this->open->handleUnauthorized($event);
                break;

            default:
                Log::info('open.event 未识别 InfoType=' . $infoType);
                break;
        }

        return response('success')->contentType('text/plain');
    }
}
