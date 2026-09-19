<?php

declare(strict_types=1);

namespace app\api\controller;

use app\BaseController;
use think\facade\Db;

/**
 * C 端 - 小程序外部链接落地页
 *
 * 批次 7-5（任务213）：按 code 反查 wx_url 并 302 跳转（对照参考系统本地码落地路数）。
 * 白名单接口（无需登录），见 AuthMiddleware api 白名单。
 */
class Wxurl extends BaseController
{
    /**
     * 落地跳转：/api/wxurl/go?code=xxx
     * 有效 → 302 到 wx_url.url；无效/过期 → 友好提示页
     */
    public function go()
    {
        $code = trim((string) $this->request->param('code', ''));

        $row = '' === $code ? null : Db::name('wx_url')->where('code', $code)->find();

        if (empty($row)) {
            return $this->page('链接不存在或已被删除');
        }
        if (!empty($row['endtime']) && (int) $row['endtime'] < time()) {
            return $this->page('链接已过期，请联系商家获取新链接');
        }

        $url = trim((string) ($row['url'] ?? ''));
        if ('' === $url) {
            return $this->page('链接暂不可用，请联系商家');
        }

        return redirect($url);
    }

    /** 简易提示页（避免 JSON 给到浏览器访客） */
    protected function page(string $msg): \think\Response
    {
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>链接提示</title></head>'
            . '<body style="margin:0;background:#f5f6f7;font-family:sans-serif">'
            . '<div style="max-width:480px;margin:20vh auto;text-align:center;color:#606266;font-size:15px">'
            . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8')
            . '</div></body></html>';

        return response($html, 404)->contentType('text/html; charset=utf-8');
    }
}
