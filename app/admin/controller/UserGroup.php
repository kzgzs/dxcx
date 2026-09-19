<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\AdminAuthService;
use app\common\service\PlogService;
use think\facade\Db;

/**
 * 租户后台 - 角色权限（权限组管理，对标参考系统 UserGroup.php）
 *
 * auth_data 存储：字符串 'all' 或 JSON 数组（租户客户端菜单 key 集合，勾选树生成）
 */
class UserGroup extends BaseController
{
    /**
     * 权限组列表
     * GET /admin/userGroup/list?page=&pagesize=&name=
     */
    public function list()
    {
        $aid      = (int) ($this->request->aid ?? 0);
        $page     = max(1, (int) $this->request->get('page', 1));
        $pagesize = min(100, max(1, (int) $this->request->get('pagesize', 10)));
        $name     = trim((string) $this->request->get('name', ''));

        $query = Db::name('admin_user_group')->where('aid', $aid);

        if ('' !== $name) {
            $query->whereLike('name', '%' . $name . '%');
        }

        $total = (int) $query->count();
        $list  = $query->order('sort', 'desc')->order('id')->page($page, $pagesize)->select()->toArray();

        // 组内成员数
        $memberCounts = Db::name('admin_user')
            ->where('aid', $aid)
            ->where('groupid', '>', 0)
            ->group('groupid')
            ->column('count(*)', 'groupid');

        foreach ($list as &$row) {
            $authData = (string) ($row['auth_data'] ?? '');
            $row['auth_mode']  = in_array('*', AdminAuthService::parseAuthData($authData), true) ? 'all' : 'keys';
            $row['auth_count'] = $row['auth_mode'] === 'all' ? -1 : count(AdminAuthService::parseAuthData($authData));
            $row['member_count'] = (int) ($memberCounts[$row['id']] ?? 0);
            unset($row['auth_data'], $row['wxauth_data'], $row['notice_auth_data'], $row['hexiao_auth_data']);
        }
        unset($row);

        return $this->success(['list' => $list, 'total' => $total]);
    }

    /**
     * 权限组详情（含 auth_data 解析结果）
     * GET /admin/userGroup/detail?id=
     */
    public function detail()
    {
        $aid = (int) ($this->request->aid ?? 0);
        $id  = (int) $this->request->get('id', 0);

        $info = Db::name('admin_user_group')->where('aid', $aid)->where('id', $id)->find();
        if (empty($info)) {
            return $this->error('权限组不存在');
        }

        $keys = AdminAuthService::parseAuthData((string) ($info['auth_data'] ?? ''));

        $info['auth_mode'] = in_array('*', $keys, true) ? 'all' : 'keys';
        $info['auth_keys'] = $info['auth_mode'] === 'all' ? [] : $keys;
        unset($info['auth_data'], $info['wxauth_data'], $info['notice_auth_data'], $info['hexiao_auth_data']);

        return $this->success($info);
    }

    /**
     * 保存权限组
     * POST /admin/userGroup/save
     * body: {id, name, sort, status, remark, auth_mode: all|keys, auth_keys: []}
     */
    public function save()
    {
        $aid = (int) ($this->request->aid ?? 0);
        $uid = (int) ($this->request->authUid ?? 0);

        $id       = (int) $this->request->post('id', 0);
        $name     = trim((string) $this->request->post('name', ''));
        $sort     = (int) $this->request->post('sort', 0);
        $status   = (int) $this->request->post('status', 1);
        $remark   = trim((string) $this->request->post('remark', ''));
        $authMode = (string) $this->request->post('auth_mode', 'keys');
        $authKeys = $this->request->post('auth_keys/a', []);

        if ('' === $name) {
            return $this->error('请输入权限组名称');
        }

        // auth_data：all 或勾选 key 数组（JSON）
        $authData = 'all' === $authMode
            ? 'all'
            : json_encode(array_values(array_unique(array_filter(array_map('strval', $authKeys)))), JSON_UNESCAPED_UNICODE);

        if ('all' !== $authMode && '[]' === $authData) {
            return $this->error('请至少勾选一项菜单权限');
        }

        $data = [
            'name'      => $name,
            'sort'      => $sort,
            'status'    => $status,
            'remark'    => $remark,
            'auth_data' => $authData,
        ];

        if ($id > 0) {
            $old = Db::name('admin_user_group')->where('aid', $aid)->where('id', $id)->find();
            if (empty($old)) {
                return $this->error('权限组不存在');
            }
            Db::name('admin_user_group')->where('id', $id)->update($data);
            PlogService::write($aid, $uid, '编辑权限组' . $id);

            return $this->success([], '保存成功');
        }

        $data['aid']        = $aid;
        $data['bid']        = 0;
        $data['createtime'] = time();
        $newId = Db::name('admin_user_group')->insertGetId($data);
        PlogService::write($aid, $uid, '添加权限组' . $newId);

        return $this->success(['id' => (int) $newId], '新增成功');
    }

    /**
     * 删除权限组（组内仍有成员时禁止删除）
     * POST /admin/userGroup/delete body: {id}
     */
    public function delete()
    {
        $aid = (int) ($this->request->aid ?? 0);
        $uid = (int) ($this->request->authUid ?? 0);
        $id  = (int) $this->request->post('id', 0);

        if ($id <= 0) {
            return $this->error('参数错误');
        }

        $memberCount = Db::name('admin_user')->where('aid', $aid)->where('groupid', $id)->count();
        if ($memberCount > 0) {
            return $this->error('该权限组下还有 ' . $memberCount . ' 个成员，请先调整成员权限组');
        }

        Db::name('admin_user_group')->where('aid', $aid)->where('id', $id)->delete();
        PlogService::write($aid, $uid, '删除权限组' . $id);

        return $this->success([], '删除成功');
    }

    /**
     * 权限树（租户客户端菜单全量：{name, path, child}，含 hide 按钮级权限点）
     * GET /admin/userGroup/menuTree
     */
    public function menuTree()
    {
        $toAuthTree = static function (array $nodes) use (&$toAuthTree): array {
            $result = [];
            foreach ($nodes as $node) {
                $item = [
                    'name' => (string) ($node['name'] ?? ''),
                    'path' => (string) ($node['key'] ?? ''),
                ];
                if (!empty($node['hide'])) {
                    $item['hide'] = true;
                }
                if (!empty($node['child']) && is_array($node['child'])) {
                    $item['child'] = $toAuthTree($node['child']);
                }
                $result[] = $item;
            }

            return $result;
        };

        return $this->success($toAuthTree(AdminAuthService::tenantMenuTree()));
    }
}
