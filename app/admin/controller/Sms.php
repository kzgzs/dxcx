<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\PlogService;
use app\common\service\SmsService;
use think\facade\Db;

/**
 * 租户后台 - 短信设置（对标参考系统 Sms.php：网关配置 + 模板开关 + 发送记录）
 */
class Sms extends BaseController
{
    public function __construct(\think\App $app, protected SmsService $smsService)
    {
        parent::__construct($app);
    }

    /**
     * 读取短信配置（admin_set_sms 单行）
     * GET /admin/sms/set
     */
    public function set()
    {
        $aid = (int) ($this->request->aid ?? 0);

        return $this->success($this->smsService->getConfig($aid));
    }

    /**
     * 保存短信配置
     * POST /admin/sms/set body: {各白名单字段}
     */
    public function saveSet()
    {
        $aid = (int) ($this->request->aid ?? 0);
        $uid = (int) ($this->request->authUid ?? 0);

        $data = (array) $this->request->post('info/a', []);
        if (empty($data)) {
            $data = $this->request->post();
        }

        $this->smsService->saveConfig($aid, $data);
        PlogService::write($aid, $uid, '短信设置');

        return $this->success([], '保存成功');
    }

    /**
     * 发送记录列表（smslog）
     * GET /admin/sms/sendlog?page=&pagesize=&status=&start=&end=
     */
    public function sendlog()
    {
        $aid      = (int) ($this->request->aid ?? 0);
        $page     = max(1, (int) $this->request->get('page', 1));
        $pagesize = min(100, max(1, (int) $this->request->get('pagesize', 10)));
        $status   = $this->request->get('status', '');
        $start    = trim((string) $this->request->get('start', ''));
        $end      = trim((string) $this->request->get('end', ''));
        $phone    = trim((string) $this->request->get('phone', ''));

        $query = Db::name('smslog')->where('aid', $aid);

        if ('' !== $status) {
            $query->where('status', (int) $status);
        }
        if ('' !== $phone) {
            $query->whereLike('PhoneNumbers', '%' . $phone . '%');
        }
        if ('' !== $start) {
            $query->where('createtime', '>=', strtotime($start));
        }
        if ('' !== $end) {
            $query->where('createtime', '<', strtotime($end) + 86400);
        }

        $total = (int) $query->count();
        $list  = $query->order('id', 'desc')->page($page, $pagesize)->select()->toArray();

        return $this->success(['list' => $list, 'total' => $total]);
    }

    /**
     * 测试发送（按模板 key 取配置的模板CODE，真实走网关并落 smslog）
     * POST /admin/sms/test body: {mobile, tmpl_key}
     */
    public function test()
    {
        $aid     = (int) ($this->request->aid ?? 0);
        $mobile  = trim((string) $this->request->post('mobile', ''));
        $tmplKey = trim((string) $this->request->post('tmpl_key', ''));

        if (1 !== preg_match('/^1\d{10}$/', $mobile)) {
            return $this->error('手机号格式不正确');
        }
        if ('' === $tmplKey || 1 !== preg_match('/^[a-z_]+$/', $tmplKey)) {
            return $this->error('模板标识不正确');
        }

        $config = $this->smsService->getConfig($aid);
        $code   = trim((string) ($config['tmpl_' . $tmplKey] ?? ''));

        if ('' === $code) {
            return $this->error('该模板未配置模板CODE');
        }
        if ((int) ($config['tmpl_' . $tmplKey . '_st'] ?? 0) !== 1) {
            return $this->error('该模板开关未开启');
        }

        $result = $this->smsService->send($aid, $mobile, $code, ['code' => '000000'], '测试发送');

        return $result['success']
            ? $this->success([], '发送成功')
            : $this->error($result['msg']);
    }
}
