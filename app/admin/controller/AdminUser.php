<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\PlogService;
use think\facade\Db;

/**
 * 租户后台 - 管理员列表（子账号管理，对标参考系统 User.php）
 *
 * 约定：isadmin 2=平台超管 1=租户主账号 0=子账号；本接口只管理 isadmin<=1 的账号
 */
class AdminUser extends BaseController
{
    /**
     * 管理员账号列表
     * GET /admin/adminUser/list?page=&pagesize=&keyword=
     */
    public function list()
    {
        $aid      = (int) ($this->request->aid ?? 0);
        $page     = max(1, (int) $this->request->get('page', 1));
        $pagesize = min(100, max(1, (int) $this->request->get('pagesize', 10)));
        $keyword  = trim((string) $this->request->get('keyword', ''));

        $query = Db::name('admin_user')->where('aid', $aid)->where('isadmin', '<', 2);

        if ('' !== $keyword) {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('un', '%' . $keyword . '%')
                    ->whereOr('truename', 'like', '%' . $keyword . '%')
                    ->whereOr('mobile', 'like', '%' . $keyword . '%');
            });
        }

        $total = (int) $query->count();
        $list  = $query->order('id')->page($page, $pagesize)->select()->toArray();

        $groupNames = Db::name('admin_user_group')->where('aid', $aid)->column('name', 'id');

        // 创建人名称映射（addid -> un）
        $addNames = Db::name('admin_user')->where('aid', $aid)->column('un', 'id');

        // 绑定会员信息映射（mid -> 昵称/头像）
        $mids = array_values(array_filter(array_map(static fn ($r) => (int) ($r['mid'] ?? 0), $list)));
        $memberMap = [];
        if ($mids) {
            $members = Db::name('member')->whereIn('id', $mids)->field('id,nickname,headimg')->select()->toArray();
            foreach ($members as $m) {
                $memberMap[(int) $m['id']] = $m;
            }
        }

        foreach ($list as &$row) {
            unset($row['pwd'], $row['random_str']);
            $row['groupid']     = (int) ($row['groupid'] ?? 0);
            $row['groupname']   = $row['groupid'] > 0 ? ($groupNames[$row['groupid']] ?? '') : '';
            $row['is_main']     = (int) ($row['isadmin'] ?? 0) >= 1 ? 1 : 0;
            $row['bids']        = (string) ($row['bids'] ?? '');
            $row['createtime']  = (int) ($row['createtime'] ?? 0);
            $row['logintime']   = (int) ($row['logintime'] ?? 0);
            $row['addname']     = (int) ($row['addid'] ?? 0) > 0 ? ($addNames[(int) $row['addid']] ?? '') : '';
            $row['mid']         = (int) ($row['mid'] ?? 0);
            $row['nickname']    = $row['mid'] > 0 ? ($memberMap[$row['mid']]['nickname'] ?? '') : '';
            $row['headimg']     = $row['mid'] > 0 ? ($memberMap[$row['mid']]['headimg'] ?? '') : '';
        }
        unset($row);

        return $this->success(['list' => $list, 'total' => $total]);
    }

    /**
     * 保存管理员账号（新增/编辑）
     * POST /admin/adminUser/save
     * body: {id, un, pwd, truename, mobile, groupid, bids, status, remark, mid}
     */
    public function save()
    {
        $aid = (int) ($this->request->aid ?? 0);
        $uid = (int) ($this->request->authUid ?? 0);

        $id       = (int) $this->request->post('id', 0);
        $un       = trim((string) $this->request->post('un', ''));
        $pwd      = (string) $this->request->post('pwd', '');
        $truename = trim((string) $this->request->post('truename', ''));
        $mobile   = trim((string) $this->request->post('mobile', ''));
        $groupid  = (int) $this->request->post('groupid', 0);
        $bids     = $this->request->post('bids/a', []);
        $status   = (int) $this->request->post('status', 1);
        $remark   = trim((string) $this->request->post('remark', ''));
        $hasMid   = $this->request->has('mid', 'post');
        $mid      = (int) $this->request->post('mid', 0);

        if ('' === $un) {
            return $this->error('请输入登录账号');
        }
        if ('' !== $mobile && 1 !== preg_match('/^1\d{10}$/', $mobile)) {
            return $this->error('手机号格式不正确');
        }

        // 账号唯一（同租户内不重复；全局登录入口按 un 查找，跨租户也不允许重复）
        $dupQuery = Db::name('admin_user')->where('un', $un);
        if ($id > 0) {
            $dupQuery->where('id', '<>', $id);
        }
        if ($dupQuery->find()) {
            return $this->error('该账号已被占用');
        }

        if ($id > 0) {
            $old = Db::name('admin_user')->where('aid', $aid)->where('id', $id)->find();
            if (empty($old)) {
                return $this->error('账号不存在');
            }
            if ((int) $old['isadmin'] >= 1 && $id !== $uid) {
                return $this->error('无权编辑主账号');
            }

            $update = [
                'un'       => $un,
                'truename' => $truename,
                'mobile'   => $mobile,
                'groupid'  => (int) $old['isadmin'] >= 1 ? 0 : $groupid,
                'bids'     => implode(',', array_map('intval', (array) $bids)),
                'status'   => $id === $uid ? 1 : $status,
                'remark'   => $remark,
            ];
            // 会员绑定：仅在显式传参时更新，避免误清空
            if ($hasMid) {
                $update['mid'] = $mid;
            }
            if ('' !== $pwd) {
                if (mb_strlen($pwd) < 6) {
                    return $this->error('密码长度至少 6 位');
                }
                $update['pwd'] = password_hash($pwd, PASSWORD_DEFAULT);
            }

            Db::name('admin_user')->where('id', $id)->update($update);
            PlogService::write($aid, $uid, '编辑管理员账号' . $id);

            return $this->success([], '保存成功');
        }

        if ('' === $pwd) {
            return $this->error('请设置登录密码');
        }
        if (mb_strlen($pwd) < 6) {
            return $this->error('密码长度至少 6 位');
        }

        $newId = Db::name('admin_user')->insertGetId([
            'aid'        => $aid,
            'bid'        => 0,
            'un'         => $un,
            'pwd'        => password_hash($pwd, PASSWORD_DEFAULT),
            'truename'   => $truename,
            'mobile'     => $mobile,
            'groupid'    => $groupid,
            'bids'       => implode(',', array_map('intval', (array) $bids)),
            'status'     => $status,
            'remark'     => $remark,
            'mid'        => $mid,
            'isadmin'    => 0,
            'addid'      => $uid,
            'createtime' => time(),
            'random_str' => bin2hex(random_bytes(8)),
        ]);

        PlogService::write($aid, $uid, '添加管理员账号' . $newId);

        return $this->success(['id' => (int) $newId], '新增成功');
    }

    /**
     * 删除管理员账号（主账号/自身不可删）
     * POST /admin/adminUser/delete body: {id}
     */
    public function delete()
    {
        $aid = (int) ($this->request->aid ?? 0);
        $uid = (int) ($this->request->authUid ?? 0);
        $id  = (int) $this->request->post('id', 0);

        if ($id <= 0) {
            return $this->error('参数错误');
        }
        if ($id === $uid) {
            return $this->error('不能删除当前登录账号');
        }

        $target = Db::name('admin_user')->where('aid', $aid)->where('id', $id)->find();
        if (empty($target)) {
            return $this->error('账号不存在');
        }
        if ((int) $target['isadmin'] >= 1) {
            return $this->error('主账号不可删除');
        }

        Db::name('admin_user')->where('aid', $aid)->where('id', $id)->delete();
        PlogService::write($aid, $uid, '删除管理员账号' . $id);

        return $this->success([], '删除成功');
    }

    /**
     * 解绑会员（置 mid=0）
     * POST /admin/adminUser/jiebang body: {id}
     */
    public function jiebang()
    {
        $aid = (int) ($this->request->aid ?? 0);
        $uid = (int) ($this->request->authUid ?? 0);
        $id  = (int) $this->request->post('id', 0);

        if ($id <= 0) {
            return $this->error('参数错误');
        }

        $target = Db::name('admin_user')->where('aid', $aid)->where('id', $id)->find();
        if (empty($target)) {
            return $this->error('账号不存在');
        }

        Db::name('admin_user')->where('id', $id)->update(['mid' => 0]);
        PlogService::write($aid, $uid, '解绑管理员' . $id . '的会员');

        return $this->success([], '已解绑');
    }

    /**
     * 绑定会员（校验会员存在）
     * POST /admin/adminUser/bindmid body: {id, mid}
     */
    public function bindmid()
    {
        $aid = (int) ($this->request->aid ?? 0);
        $uid = (int) ($this->request->authUid ?? 0);
        $id  = (int) $this->request->post('id', 0);
        $mid = (int) $this->request->post('mid', 0);

        if ($id <= 0 || $mid <= 0) {
            return $this->error('参数错误');
        }

        $target = Db::name('admin_user')->where('aid', $aid)->where('id', $id)->find();
        if (empty($target)) {
            return $this->error('账号不存在');
        }

        $member = Db::name('member')->where('aid', $aid)->where('id', $mid)->find();
        if (empty($member)) {
            return $this->error('会员不存在，请检查会员ID');
        }

        Db::name('admin_user')->where('id', $id)->update(['mid' => $mid]);
        PlogService::write($aid, $uid, '管理员' . $id . '绑定会员' . $mid);

        return $this->success([], '绑定成功');
    }

    /**
     * 手机端管理登录地址（H5 管理助手入口）
     * GET /admin/adminUser/loginurl
     */
    public function loginurl()
    {
        $path = 'admin/index/index';
        $url  = $this->request->domain() . '/h5/#/' . $path;

        return $this->success(['url' => $url, 'path' => $path]);
    }
}
