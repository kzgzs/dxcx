<?php

declare(strict_types=1);

namespace app\console\controller;

use app\BaseController;
use app\common\service\OpenCodeService;
use app\common\service\OpenPlatformService;
use app\common\service\WxBizCrypt;
use think\facade\Db;

/**
 * 超管控制台 - 微信开放平台第三方平台配置
 *
 * 契约（前端并行开发）：
 * GET  /console/open/config             读取组件配置 + 展示用 URL + 只读域名/IP白名单
 * POST /console/open/config             保存配置（appsecret 空串=不修改）
 * POST /console/open/uploadTemplate     上传代码到小程序模板库（草稿箱最新草稿转模板）
 * POST /console/open/uploadVerifyFile   上传域名校验文件（txt 写入 public 根目录）
 * GET  /console/open/authorizer/list    授权方分页列表（昵称/AppID/账号类型筛选）
 * GET  /console/open/authorizer/detail  授权方完整详情
 * POST /console/open/authorizer/refresh 刷新授权方令牌（mock 模拟轮换）
 * POST /console/open/bindUrl            生成扫码授权页 URL
 * POST /console/open/unbind             解除授权（auth_status 置 0）
 */
class Open extends BaseController
{
    public function __construct(\think\App $app, protected OpenPlatformService $open)
    {
        parent::__construct($app);
    }

    /**
     * 组件配置读取（对照参考系统 WebSystem/component：只读项自动生成）
     */
    public function config()
    {
        $config = $this->open->config();

        // 消息校验Token/消息加解密Key 为空时自动生成并落库（参考系统：random(32)/random(43)，保证回显稳定）
        $gen = [];
        if ('' === (string) $config['token']) {
            $gen['token'] = WxBizCrypt::randomStr(32);
        }
        if ('' === (string) $config['encoding_aes_key']) {
            $gen['encoding_aes_key'] = WxBizCrypt::randomStr(43);
        }
        if ($gen) {
            if (!empty($config['id'])) {
                Db::name('open_component')->where('id', $config['id'])
                    ->update($gen + ['update_time' => time()]);
            }
            $config = array_merge($config, $gen);
        }

        $domain = $this->request->domain();

        return $this->success([
            'status'              => (int) $config['status'],
            'component_appid'     => (string) $config['component_appid'],
            'component_appsecret' => (string) $config['component_appsecret'],
            'appsecret_masked'    => '' !== (string) $config['component_appsecret'] ? '****' : '',
            'token'               => (string) $config['token'],
            'encoding_aes_key'    => (string) $config['encoding_aes_key'],
            'kfappid'             => (string) ($config['kfappid'] ?? ''),
            'ip_whitelist'        => (string) $config['ip_whitelist'],
            'auth_domain'         => (string) $config['auth_domain'],
            'mock_mode'           => (int) $config['mock_mode'],
            'autofb'              => (int) ($config['autofb'] ?? 0),
            // 只读项：当前站点域名（去协议头，参考系统：str_replace http/https）
            'domain'              => str_replace(['http://', 'https://'], '', $domain),
            // 只读项：服务器IP（参考系统：gethostbyname(SERVER_NAME)，可以 .env SERVER.IP 覆盖）
            'server_ip'           => $this->serverIp(),
            // 展示用（供界面复制/配置到微信开放平台）
            'event_url'           => $domain . '/open/event',
            'msg_url_pattern'     => $domain . '/open/msg/$APPID$',
            'callback_url'        => $domain . '/open/auth/callback',
        ]);
    }

    /**
     * 服务器出口IP：.env SERVER.IP 优先，否则解析域名/SERVER_NAME（对照参考系统 gethostbyname），
     * 本机 SERVER_NAME 为绑定地址（0.0.0.0）时回退 request 域名主机名，再回退本机 hostname
     */
    protected function serverIp(): string
    {
        $cfg = (string) env('SERVER.IP', '');
        if ('' !== $cfg) {
            return $cfg;
        }

        $names = [];
        $host  = (string) (parse_url($this->request->domain(), PHP_URL_HOST) ?: '');
        if ('' !== $host && false === filter_var($host, FILTER_VALIDATE_IP)) {
            $names[] = $host;
        }
        $serverName = (string) ($_SERVER['SERVER_NAME'] ?? '');
        if ('' !== $serverName && false === filter_var($serverName, FILTER_VALIDATE_IP)) {
            $names[] = $serverName;
        }
        foreach ($names as $name) {
            $ip = (string) @gethostbyname($name);
            if ('' !== $ip && $ip !== $name && '0.0.0.0' !== $ip) {
                return $ip;
            }
        }

        // 兜底：本机 hostname 解析（服务器局域网/出口IP）
        $ip = (string) @gethostbyname((string) gethostname());

        return ('' !== $ip && '0.0.0.0' !== $ip) ? $ip : '127.0.0.1';
    }

    /**
     * 组件配置保存（对照参考系统：token 3-32位英文数字、key 43位英文数字）
     */
    public function saveConfig()
    {
        $data = $this->body();

        $this->validate($data, [
            'status'           => 'require|in:0,1',
            'token'            => 'require|regex:/^[A-Za-z0-9]{3,32}$/',
            'encoding_aes_key' => 'require|regex:/^[A-Za-z0-9]{43}$/',
            'mock_mode'        => 'in:0,1',
            'autofb'           => 'in:0,1',
        ], [
            'token.require'            => '请填写消息校验Token',
            'token.regex'              => '消息校验Token必须为英文或者数字，长度为3到32个字符',
            'encoding_aes_key.require' => '请填写消息加解密Key',
            'encoding_aes_key.regex'   => '消息加解密Key必须为英文或者数字，长度为43个字符',
        ]);

        $this->open->saveConfig($data);

        return $this->success([], '设置成功');
    }

    /**
     * 上传代码到小程序模板库（对照参考系统 WebSystem/component op=upload 的微信侧链路）：
     * 校验开发小程序AppID -> component_access_token -> 草稿箱列表 -> 最新草稿 addtotemplate
     * 无真实凭据/草稿箱为空时返回友好错误
     */
    public function uploadTemplate(OpenCodeService $code)
    {
        $config = $this->open->config();
        if ('' === trim((string) ($config['kfappid'] ?? ''))) {
            return $this->error('请填写开发小程序AppID');
        }

        try {
            $drafts = $code->draftList();
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
        if (empty($drafts)) {
            return $this->error('草稿箱为空，请先使用开发小程序（微信开发者工具）上传代码到开放平台草稿箱');
        }

        // 参考系统：按 create_time 倒序取最新草稿
        usort($drafts, static fn (array $a, array $b): int => (int) ($b['create_time'] ?? 0) <=> (int) ($a['create_time'] ?? 0));
        $draftId = (int) ($drafts[0]['draft_id'] ?? 0);
        if ($draftId <= 0) {
            return $this->error('草稿箱数据异常，未获取到草稿ID');
        }

        try {
            $rs = $code->addFromDraft($draftId);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }

        return $this->success($rs, '上传成功，最新草稿已添加到小程序模板库');
    }

    /**
     * 上传域名校验文件（对照参考系统 WebSystem/uploadjstxt）：
     * 校验 txt 扩展名 + 纯英文数字内容 + 文件名安全，写入 public 根目录
     */
    public function uploadVerifyFile()
    {
        $file = $this->request->file('file');
        if (!$file || !$file->isValid()) {
            return $this->error('请选择文件');
        }

        $name = (string) $file->getOriginalName();
        // 拒绝浏览器/系统重命名（副本）文件，校验文件名与微信下发一致
        if (preg_match('/\(\d+\)|副本/', $name)) {
            return $this->error('文件名不一致,请重新上传');
        }
        $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        if ('txt' !== $ext) {
            return $this->error('上传文件不合法,请重新上传');
        }
        // 文件名安全：仅允许字母数字 _ - . ，防目录穿越
        if (!preg_match('/^[A-Za-z0-9_.\-]+$/', $name)) {
            return $this->error('上传文件不合法,请重新上传');
        }
        // 参考系统：校验文件内容必须为纯英文数字
        $content = (string) file_get_contents($file->getPathname());
        if (!preg_match('/^[A-Za-z0-9]+$/', $content)) {
            return $this->error('上传文件不合法,请重新上传');
        }

        $dest = $this->app->getRootPath() . 'public' . DIRECTORY_SEPARATOR . $name;
        if (false === file_put_contents($dest, $content)) {
            return $this->error('写入失败，请检查站点 public 根目录写权限');
        }

        return $this->success([
            'filename' => $name,
            'url'      => $this->request->domain() . '/' . $name,
        ], '上传成功');
    }

    /**
     * 授权方分页列表（tenant_username 关联 admin 表）
     * 筛选：nick_name / authorizer_appid（模糊），account_type=official公众号|mini小程序
     */
    public function authorizerList()
    {
        $page     = max(1, (int) $this->request->param('page', 1));
        $pagesize = max(1, min(100, (int) $this->request->param('pagesize', 20)));

        $nickName    = trim((string) $this->request->param('nick_name', ''));
        $appid       = trim((string) $this->request->param('authorizer_appid', ''));
        $accountType = trim((string) $this->request->param('account_type', ''));

        $query = Db::name('open_authorizer')->alias('oa')
            ->leftJoin('admin a', 'a.id = oa.aid')
            ->field('oa.id, oa.aid, oa.authorizer_appid, oa.nick_name, oa.head_img, oa.principal_name, '
                . 'oa.verify_type_id, oa.service_type_id, oa.func_info, oa.auth_status, oa.auth_time, '
                . 'oa.unauth_time, a.username AS tenant_username')
            ->order('oa.id', 'desc');

        if ('' !== $nickName) {
            $query->whereLike('oa.nick_name', '%' . $nickName . '%');
        }
        if ('' !== $appid) {
            $query->whereLike('oa.authorizer_appid', '%' . $appid . '%');
        }
        // 账号类型：微信 service_type_id 0/1/2=公众号系 3=小程序
        if ('official' === $accountType) {
            $query->whereIn('oa.service_type_id', [0, 1, 2]);
        } elseif ('mini' === $accountType) {
            $query->where('oa.service_type_id', 3);
        }

        $total = $query->count();
        $rows  = $query->page($page, $pagesize)->select()->toArray();

        foreach ($rows as &$row) {
            $decoded               = json_decode((string) $row['func_info'], true);
            $row['func_info']      = is_array($decoded) ? $decoded : [];
            $row['auth_time']      = (int) $row['auth_time'];
            $row['unauth_time']    = (int) $row['unauth_time'];
            $row['auth_status']    = (int) $row['auth_status'];
            $row['account_type']   = 3 === (int) $row['service_type_id'] ? 'mini' : 'official';
        }
        unset($row);

        return $this->success([
            'list'     => $rows,
            'total'    => $total,
            'page'     => $page,
            'pagesize' => $pagesize,
        ]);
    }

    /**
     * 授权方完整详情（弹窗展示用，refresh_token 脱敏）
     */
    public function authorizerDetail()
    {
        $id = (int) $this->request->param('id', 0);
        if ($id <= 0) {
            return $this->error('参数错误：缺少 id');
        }

        $row = Db::name('open_authorizer')->alias('oa')
            ->leftJoin('admin a', 'a.id = oa.aid')
            ->field('oa.*, a.username AS tenant_username')
            ->where('oa.id', $id)
            ->find();
        if (empty($row)) {
            return $this->error('授权方不存在', 404);
        }

        $decoded          = json_decode((string) $row['func_info'], true);
        $row['func_info'] = is_array($decoded) ? $decoded : [];

        foreach (['auth_time', 'unauth_time', 'create_time', 'update_time', 'auth_status', 'account_status', 'service_type_id', 'verify_type_id'] as $k) {
            $row[$k] = (int) $row[$k];
        }
        $row['aid']           = null === $row['aid'] ? null : (int) $row['aid'];
        $row['account_type']  = 3 === $row['service_type_id'] ? 'mini' : 'official';
        // refresh_token 脱敏：仅保留前 8 位
        $raw                  = (string) $row['refresh_token'];
        $row['refresh_token'] = '' === $raw ? '' : substr($raw, 0, 8) . '****';
        unset($row['refresh_token_backup']);

        return $this->success($row);
    }

    /**
     * 刷新授权方令牌
     * mock 模式：模拟微信轮换 refresh_token（旧值转备份）；真实模式：调微信接口强刷
     */
    public function refreshToken()
    {
        $data = $this->body();

        $this->validate($data, ['id' => 'require|integer|gt:0']);

        $row = Db::name('open_authorizer')->where('id', (int) $data['id'])->find();
        if (empty($row)) {
            return $this->error('授权方不存在', 404);
        }
        if (1 !== (int) $row['auth_status']) {
            return $this->error('该账号已取消授权，无法刷新令牌');
        }

        $appid = (string) $row['authorizer_appid'];

        if ($this->open->isMock()) {
            // 模拟刷新：轮换 refresh_token 并清理 access_token 缓存
            $newRefresh = 'MOCK_REFRESH_TOKEN_' . md5($appid . uniqid('', true));
            Db::name('open_authorizer')->where('id', $row['id'])->update([
                'refresh_token_backup' => (string) $row['refresh_token'],
                'refresh_token'        => $newRefresh,
                'refresh_alert'        => '',
                'update_time'          => time(),
            ]);
            $this->open->redis()->del('open:authorizer_access_token:' . $appid);
        } else {
            // 真实模式：强制刷新（内部调 api_authorizer_token 并回写新 refresh_token）
            $this->open->authorizerAccessToken($appid, true);
        }

        return $this->success([], '令牌刷新成功');
    }

    /**
     * 生成扫码授权页 URL（mock 模式返回模拟授权页地址）
     */
    public function bindUrl()
    {
        $data = $this->body();

        $this->validate($data, [
            'auth_type' => 'require|in:1,2,3',
            'tenant_id' => 'integer|egt:0',
        ], [
            'auth_type.require' => '请选择授权类型',
            'auth_type.in'      => '授权类型仅支持 1公众号/2小程序/3都有',
        ]);

        $tenantId = (int) ($data['tenant_id'] ?? 0);
        $url      = $this->open->buildAuthUrl((int) $data['auth_type'], $tenantId > 0 ? $tenantId : null);

        return $this->success(['url' => $url]);
    }

    /**
     * 解除授权（软标记）
     */
    public function unbind()
    {
        $data = $this->body();

        $this->validate($data, ['id' => 'require|integer|gt:0']);

        $row = Db::name('open_authorizer')->where('id', (int) $data['id'])->find();
        if (empty($row)) {
            return $this->error('授权方不存在', 404);
        }

        Db::name('open_authorizer')->where('id', $row['id'])->update([
            'auth_status' => 0,
            'unauth_time' => time(),
            'update_time' => time(),
        ]);

        return $this->success();
    }

    /**
     * 请求体（兼容 JSON 与表单）
     */
    protected function body(): array
    {
        $data = $this->request->post();

        return is_array($data) ? $data : [];
    }
}
