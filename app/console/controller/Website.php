<?php

declare(strict_types=1);

namespace app\console\controller;

use app\BaseController;
use app\common\service\SendMailService;
use app\common\service\TenantConfigService;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Log;
use think\facade\View;
use think\Response;

/**
 * 纯域名官网（免登录公开页）
 *
 * 对照参考系统 index/index2 官网模板（canzhao/app/view/index、index2）：
 * - GET  / 与 /index        官网首页（showweb：0 关闭 / 1 模板一 / 2 模板二，默认模板二）
 * - GET  /index/help?id=xx  帮助文章详情页（读取控制台帮助中心 status=1 文章）
 * - POST /index/message     网站留言提交（落 webmessage 表，控制台「网站留言」可见）
 *
 * 配置来源：tenant_config（aid=0，key 前缀 sys_system_）
 * 模板目录：项目根 app/view/（独立于 console 应用视图，见 render()）
 */
class Website extends BaseController
{
    /** 平台级配置统一用 aid=0 */
    private const PLATFORM_AID = 0;

    /** 官网渲染所需的系统设置字段（白名单，前缀 sys_system_） */
    private const CFG_FIELDS = [
        'system_name', 'logo', 'ico', 'copyright', 'beian',
        'gongan_text', 'gongan_code', 'company', 'address',
        'email', 'tel', 'weixin', 'showweb', 'reg_open',
    ];

    /**
     * 官网首页
     * GET / 、/index
     */
    public function index()
    {
        $cfg     = $this->loadConfig();
        $showweb = trim((string) $cfg['showweb']);

        // 官网关闭：渲染关闭提示页
        if ($showweb === '0') {
            return $this->render('index/closed', ['cfg' => $cfg]);
        }

        $helps = Db::name('help')
            ->where('status', 1)
            ->order('sort', 'desc')
            ->order('id', 'desc')
            ->field('id,name,readcount,createtime')
            ->limit(12)
            ->select()
            ->toArray();

        // showweb=1 模板一；其余（含空值、2）默认模板二
        $template = $showweb === '1' ? 'index/index' : 'index2/index';

        // 前端单页应用地址：生产同域部署留空（按钮指向同域 /#/login），本地 dev 配 http://localhost:5173
        $frontendUrl = rtrim(trim((string) env('FRONTEND_URL', '')), '/');

        return $this->render($template, [
            'cfg'     => $cfg,
            'helps'   => $helps,
            'reg_open' => $cfg['reg_open'] === '1' ? 1 : 0,
            'frontend_url' => $frontendUrl,
        ]);
    }

    /**
     * 帮助文章详情页（公开，readcount+1）
     * GET /index/help?id=xx
     */
    public function help()
    {
        $cfg = $this->loadConfig();
        $id  = (int) $this->request->param('id', 0);

        $article = null;
        if ($id > 0) {
            $article = Db::name('help')
                ->where('id', $id)
                ->where('status', 1)
                ->field('id,name,content,readcount,createtime')
                ->find();
        }

        if (!empty($article)) {
            Db::name('help')->where('id', $id)->inc('readcount')->update();
            $article['readcount'] = (int) $article['readcount'] + 1;
        }

        return $this->render('index/help', [
            'cfg'     => $cfg,
            'article' => $article,
        ]);
    }

    /**
     * 网站留言提交（游客免登录，落 webmessage 表）
     * POST /index/message  参数：realname 姓名 / tel 联系方式 / content 内容
     */
    public function message()
    {
        $realname = trim((string) $this->request->post('realname', ''));
        $tel      = trim((string) $this->request->post('tel', ''));
        $content  = trim((string) $this->request->post('content', ''));

        if ($realname === '') {
            return $this->error('请填写您的姓名');
        }
        if (mb_strlen($realname) > 50) {
            return $this->error('姓名过长');
        }
        if ($tel !== '' && mb_strlen($tel) > 50) {
            return $this->error('联系方式过长');
        }
        if ($content === '') {
            return $this->error('请填写留言内容');
        }
        if (mb_strlen($content) > 1000) {
            return $this->error('留言内容过长（最多 1000 字）');
        }

        // 简易频控：同一 IP 10 秒内仅可提交一次
        $ip       = (string) $this->request->ip();
        $limitKey = 'webmsg_' . md5($ip);
        try {
            if (Cache::get($limitKey) !== null) {
                return $this->error('提交过于频繁，请稍后再试');
            }
            Cache::set($limitKey, 1, 10);
        } catch (\Throwable $e) {
            // 缓存不可用时不阻断提交
        }

        Db::name('webmessage')->insert([
            'realname'   => $realname,
            'tel'        => $tel,
            'content'    => $content,
            'ip'         => $ip,
            'createtime' => time(),
            'status'     => 0,
        ]);

        // 留言入库成功后发送邮件通知（失败不阻塞提交）
        try {
            $this->notifyByMail($realname, $tel, $content, $ip);
        } catch (\Throwable $e) {
            Log::error('[Website] 留言通知邮件异常：' . $e->getMessage());
        }

        return $this->success([], '留言提交成功，我们会尽快与您联系');
    }

    /**
     * 留言邮件通知：收件人=系统设置企业邮箱（sys_system_email），
     * 抄送=超管邮箱（admin_user 表无邮箱字段时不抄送）
     */
    private function notifyByMail(string $realname, string $tel, string $content, string $ip): void
    {
        $to = trim((string) TenantConfigService::get(self::PLATFORM_AID, 'sys_system_email', ''));
        if ($to === '') {
            return;
        }

        $cc = $this->superAdminEmail();

        $telRow = $tel !== ''
            ? '<tr><td style="padding:12px;border:1px solid #ddd;font-weight:bold;width:110px">联系方式</td><td style="padding:12px;border:1px solid #ddd">' . htmlspecialchars($tel) . '</td></tr>'
            : '';

        $body = '<div style="font-family:Arial,\'Microsoft YaHei\',sans-serif;line-height:1.6;color:#333;max-width:600px">'
            . '<h2 style="color:#b5432c">新的网站留言</h2>'
            . '<table style="width:100%;border-collapse:collapse;margin-top:16px">'
            . '<tr><td style="padding:12px;border:1px solid #ddd;font-weight:bold;width:110px">留言人</td><td style="padding:12px;border:1px solid #ddd">' . htmlspecialchars($realname) . '</td></tr>'
            . $telRow
            . '<tr><td style="padding:12px;border:1px solid #ddd;font-weight:bold">留言内容</td><td style="padding:12px;border:1px solid #ddd">' . nl2br(htmlspecialchars($content)) . '</td></tr>'
            . '<tr><td style="padding:12px;border:1px solid #ddd;font-weight:bold">提交 IP</td><td style="padding:12px;border:1px solid #ddd">' . htmlspecialchars($ip) . '</td></tr>'
            . '<tr><td style="padding:12px;border:1px solid #ddd;font-weight:bold">提交时间</td><td style="padding:12px;border:1px solid #ddd">' . date('Y-m-d H:i:s') . '</td></tr>'
            . '</table>'
            . '<p style="margin-top:16px;font-size:12px;color:#666">请及时在控制台【网站留言】模块查看和处理。</p>'
            . '</div>';

        SendMailService::send($to, '[网站留言] ' . $realname, $body, $cc);
    }

    /**
     * 超管邮箱：admin_user 表主账号邮箱（表无 email 字段或无邮箱时返回空串）
     */
    private function superAdminEmail(): string
    {
        try {
            $fields = Db::getTableFields(Db::name('admin_user')->getTable());
            if (!in_array('email', $fields, true)) {
                return '';
            }
            $email = Db::name('admin_user')
                ->where('isadmin', 1)
                ->where('status', 1)
                ->order('id', 'asc')
                ->value('email');
            $email = trim((string) $email);
            return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * 读取平台级系统设置（官网渲染所需字段）
     * @return array<string, string>
     */
    private function loadConfig(): array
    {
        $cfg = [];
        foreach (self::CFG_FIELDS as $field) {
            $cfg[$field] = TenantConfigService::get(self::PLATFORM_AID, 'sys_system_' . $field, '');
        }

        // 兜底展示名
        if ($cfg['system_name'] === '') {
            $cfg['system_name'] = '鼎极多端商城系统';
        }

        return $cfg;
    }

    /**
     * 渲染官网模板（模板目录：项目根 app/view/，独立于 console 应用视图）
     */
    private function render(string $template, array $vars): Response
    {
        $viewPath = $this->app->getRootPath() . 'app' . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR;
        $this->app->config->set(['view_path' => $viewPath], 'view');

        return response(View::fetch($template, $vars));
    }
}
