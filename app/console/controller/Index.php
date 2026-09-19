<?php

declare(strict_types=1);

namespace app\console\controller;

use app\BaseController;
use app\common\service\TenantService;
use think\facade\Db;

/**
 * 超管控制台应用入口
 */
class Index extends BaseController
{
    public function index()
    {
        return $this->success([
            'app' => 'console',
            'aid' => $this->request->middleware('aid'),
        ]);
    }

    /**
     * 当前超管账号信息（前端刷新路由角色用）
     * GET /console/index/profile
     */
    public function profile()
    {
        $payload = (array) ($this->request->authPayload ?? []);

        return $this->success([
            'id'       => (int) ($payload['uid'] ?? 0),
            'username' => (string) ($payload['un'] ?? ''),
            'isadmin'  => (int) ($payload['isadmin'] ?? 0),
        ]);
    }

    /**
     * 修改当前超管登录密码（校验旧密码，bcrypt 落库）
     * POST /console/index/editPassword body:{old_password, new_password}
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
