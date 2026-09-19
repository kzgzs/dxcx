<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\AdminAuthService;
use app\common\service\TenantService;
use think\facade\Db;

/**
 * 租户后台应用入口
 */
class Index extends BaseController
{
    public function __construct(\think\App $app, protected AdminAuthService $authService)
    {
        parent::__construct($app);
    }

    public function index()
    {
        return $this->success([
            'app' => 'admin',
            'aid' => $this->request->aid ?? 0,
        ]);
    }

    /**
     * 当前登录账号信息（含所属租户摘要）
     * GET /admin/index/profile
     */
    public function profile()
    {
        return $this->success($this->authService->profile((array) ($this->request->authPayload ?? [])));
    }

    /**
     * 修改当前租户账号登录密码（校验旧密码，bcrypt 落库）
     * POST /admin/index/editPassword body:{old_password, new_password}
     */
    public function editPassword()
    {
        $payload = (array) ($this->request->authPayload ?? []);
        $uid     = (int) ($payload['uid'] ?? 0);

        $oldPassword = trim((string) $this->request->post('old_password', ''));
        $newPassword = (string) $this->request->post('new_password', '');

        if ('' === $oldPassword || '' === $newPassword) {
            return $this->error('请填写完整密码信息');
        }
        if (mb_strlen($newPassword) < 6 || mb_strlen($newPassword) > 32) {
            return $this->error('新密码长度需为 6-32 位');
        }

        $user = Db::name('admin_user')->where('id', $uid)->find();
        if (empty($user)) {
            return $this->error('账号不存在');
        }
        if (!TenantService::verifyPassword($oldPassword, (string) $user['pwd'])) {
            return $this->error('原密码不正确');
        }

        Db::name('admin_user')->where('id', $uid)->update([
            'pwd' => password_hash($newPassword, PASSWORD_DEFAULT),
        ]);

        return $this->success();
    }
}
