<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\PlogService;
use app\common\service\ScopeService;
use think\facade\Db;

/**
 * 租户后台 - 商户管理（多商户，business 表）
 *
 * 对齐参考系统 Business：
 * - status：0 待审核 / 1 已通过 / 2 已驳回 / 3 已过期；is_open：1 营业 / 0 停业
 * - 每个商户的主账号落在 admin_user（isadmin=1、bid=商户id），登录账号 un / 密码 pwd
 * - 后台菜单权限 auth_data、手机查看 wxauth_data、通知 notice_auth_data、核销 hexiao_auth_data（均为 key 数组 JSON）
 */
class Business extends BaseController
{
    /** 列表排序白名单 */
    private const SORT_FIELDS = ['id', 'sort', 'money', 'viewnum', 'endtime', 'createtime'];

    /** business 可写字段白名单 */
    private const INFO_FIELDS = [
        'cid', 'name', 'feepercent', 'wxpayst', 'linkman', 'linktel', 'tel', 'kfurl',
        'logo', 'pics', 'desc', 'content', 'province', 'city', 'district', 'address',
        'longitude', 'latitude', 'kdniao_intsend', 'zhengming', 'status', 'sort', 'is_open',
    ];

    /**
     * 商户列表
     */
    public function list()
    {
        $aid      = ScopeService::aidOf($this->request);
        $page     = max(1, (int) $this->request->get('page', 1));
        $pagesize = min(100, max(1, (int) $this->request->get('pagesize', 20)));
        $id       = (int) $this->request->get('id', 0);
        $cid      = (int) $this->request->get('cid', 0);
        $name     = trim((string) $this->request->get('name', ''));
        $status   = $this->request->get('status', '');
        $isOpen   = $this->request->get('is_open', '');

        $query = ScopeService::query('business', $aid);
        if ($id > 0) {
            $query->where('id', $id);
        }
        if ($cid > 0) {
            $query->whereRaw("FIND_IN_SET({$cid}, cid)");
        }
        if ('' !== $name) {
            $query->whereLike('name', '%' . $name . '%');
        }
        if ('' !== $status && null !== $status) {
            $query->where('status', (int) $status);
        }
        if ('' !== $isOpen && null !== $isOpen) {
            $query->where('is_open', (int) $isOpen);
        }

        $field = (string) $this->request->get('field', 'sort');
        $order = strtolower((string) $this->request->get('order', 'desc')) === 'asc' ? 'asc' : 'desc';
        if (!in_array($field, self::SORT_FIELDS, true)) {
            $field = 'sort';
        }

        $total = (clone $query)->count();
        $rows  = $query->order($field, $order)->order('id', 'desc')->page($page, $pagesize)->select()->toArray();

        // 分类名映射
        $catMap = ScopeService::query('business_category', $aid)->column('name', 'id');
        // 主账号（店主登录账号）映射
        $bids   = array_map(static fn (array $r) => (int) $r['id'], $rows);
        $unMap  = [];
        if (!empty($bids)) {
            $accRows = Db::name('admin_user')->where('aid', $aid)->whereIn('bid', $bids)->where('isadmin', 1)->field('bid,un')->select()->toArray();
            foreach ($accRows as $a) {
                $unMap[(int) $a['bid']] = (string) $a['un'];
            }
        }

        $list = array_map(static function (array $r) use ($catMap, $unMap) {
            $cnames = [];
            foreach (explode(',', (string) ($r['cid'] ?? '')) as $c) {
                $c = (int) trim($c);
                if ($c > 0 && isset($catMap[$c])) {
                    $cnames[] = $catMap[$c];
                }
            }
            return [
                'id'         => (int) $r['id'],
                'cid'        => (string) ($r['cid'] ?? ''),
                'cname'      => implode(',', $cnames),
                'name'       => (string) ($r['name'] ?? ''),
                'logo'       => (string) ($r['logo'] ?? ''),
                'pics'       => (string) ($r['pics'] ?? ''),
                'province'   => (string) ($r['province'] ?? ''),
                'city'       => (string) ($r['city'] ?? ''),
                'district'   => (string) ($r['district'] ?? ''),
                'address'    => (string) ($r['address'] ?? ''),
                'linkman'    => (string) ($r['linkman'] ?? ''),
                'linktel'    => (string) ($r['linktel'] ?? ''),
                'tel'        => (string) ($r['tel'] ?? ''),
                'un'         => (string) ($unMap[(int) $r['id']] ?? ''),
                'money'      => (float) ($r['money'] ?? 0),
                'viewnum'    => (int) ($r['viewnum'] ?? 0),
                'feepercent' => (float) ($r['feepercent'] ?? 0),
                'sort'       => (int) ($r['sort'] ?? 0),
                'status'     => (int) ($r['status'] ?? 1),
                'is_open'    => (int) ($r['is_open'] ?? 1),
                'createtime' => (int) ($r['createtime'] ?? 0),
                'endtime'    => (int) ($r['endtime'] ?? 0),
            ];
        }, $rows);

        return $this->success(['list' => $list, 'total' => $total]);
    }

    /**
     * 详情（编辑回显）：business + 主账号 + 权限
     */
    public function detail()
    {
        $aid = ScopeService::aidOf($this->request);
        $id  = (int) $this->request->get('id', 0);
        if ($id <= 0) {
            throw new BusinessException('缺少商户ID');
        }
        $info = ScopeService::query('business', $aid)->where('id', $id)->find();
        if (empty($info)) {
            throw new BusinessException('商户不存在', 404, 404);
        }
        $acc = Db::name('admin_user')->where('aid', $aid)->where('bid', $id)->where('isadmin', 1)->find();

        $info['cid_arr'] = array_values(array_filter(array_map('intval', explode(',', (string) ($info['cid'] ?? '')))));
        $info['pics_arr'] = array_values(array_filter(explode(',', (string) ($info['pics'] ?? ''))));
        $info['zhengming_arr'] = array_values(array_filter(explode(',', (string) ($info['zhengming'] ?? ''))));
        $info['endtime_str'] = (int) ($info['endtime'] ?? 0) > 0 ? date('Y-m-d', (int) $info['endtime']) : '';

        return $this->success([
            'info' => $info,
            'account' => [
                'id'               => (int) ($acc['id'] ?? 0),
                'un'               => (string) ($acc['un'] ?? ''),
                'auth_data'        => $this->jsonArr($acc['auth_data'] ?? null),
                'wxauth_data'      => $this->jsonArr($acc['wxauth_data'] ?? null),
                'notice_auth_data' => $this->jsonArr($acc['notice_auth_data'] ?? null),
                'hexiao_auth_data' => $this->jsonArr($acc['hexiao_auth_data'] ?? null),
            ],
        ]);
    }

    /**
     * 新增 / 编辑保存
     */
    public function save()
    {
        $aid   = ScopeService::aidOf($this->request);
        $uid   = (int) ($this->request->authUid ?? 0);
        $info  = (array) $this->request->post('info/a', []);
        $uinfo = (array) $this->request->post('uinfo/a', []);

        $name = trim((string) ($info['name'] ?? ''));
        if ('' === $name) {
            throw new BusinessException('请填写商户名称');
        }
        $un = trim((string) ($uinfo['un'] ?? ''));
        if ('' === $un) {
            throw new BusinessException('请填写登录账号');
        }

        // 组装 business 白名单字段
        $row = ['name' => $name];
        foreach (self::INFO_FIELDS as $k) {
            if ($k === 'name' || !array_key_exists($k, $info)) {
                continue;
            }
            $row[$k] = $this->normalizeField($k, $info[$k]);
        }
        // 到期时间
        $endtime = trim((string) ($info['endtime'] ?? ''));
        $row['endtime'] = '' !== $endtime ? strtotime($endtime) : 0;
        // 分类（单选；兼容数组）
        if (isset($info['cid'])) {
            $row['cid'] = is_array($info['cid']) ? implode(',', array_map('intval', $info['cid'])) : (string) $info['cid'];
        }

        // 权限 JSON
        $authJson = fn ($key) => json_encode(array_values(array_map('strval', (array) $this->request->post($key . '/a', []))), JSON_UNESCAPED_UNICODE);
        $authData       = $authJson('auth_data');
        $wxauthData     = $authJson('wxauth_data');
        $noticeAuthData = $authJson('notice_auth_data');
        $hexiaoAuthData = $authJson('hexiao_auth_data');

        $id = (int) ($info['id'] ?? 0);
        if ($id > 0) {
            $exists = ScopeService::query('business', $aid)->where('id', $id)->find();
            if (empty($exists)) {
                throw new BusinessException('商户不存在');
            }
            // 账号唯一（排除自身）
            $acc = Db::name('admin_user')->where('aid', $aid)->where('bid', $id)->where('isadmin', 1)->find();
            $dup = Db::name('admin_user')->where('aid', $aid)->where('un', $un)->where('id', '<>', (int) ($acc['id'] ?? 0))->find();
            if ($dup) {
                throw new BusinessException('该登录账号已存在');
            }
            ScopeService::query('business', $aid)->where('id', $id)->update($row);

            if ($acc) {
                $accRow = [
                    'un'               => $un,
                    'auth_data'        => $authData,
                    'wxauth_data'      => $wxauthData,
                    'notice_auth_data' => $noticeAuthData,
                    'hexiao_auth_data' => $hexiaoAuthData,
                ];
                $pwd = (string) ($uinfo['pwd'] ?? '');
                if ('' !== $pwd) {
                    if (mb_strlen($pwd) < 6) {
                        throw new BusinessException('登录密码长度至少 6 位');
                    }
                    $accRow['pwd'] = password_hash($pwd, PASSWORD_DEFAULT);
                }
                Db::name('admin_user')->where('aid', $aid)->where('id', (int) $acc['id'])->update($accRow);
            }
            PlogService::write($aid, $uid, '编辑商户' . $id);
            return $this->success(['id' => $id], '保存成功');
        }

        // 账号唯一
        $dup = Db::name('admin_user')->where('aid', $aid)->where('un', $un)->find();
        if ($dup) {
            throw new BusinessException('该登录账号已存在');
        }
        $pwd = (string) ($uinfo['pwd'] ?? '');
        if ('' === $pwd) {
            throw new BusinessException('请设置登录密码');
        }
        if (mb_strlen($pwd) < 6) {
            throw new BusinessException('登录密码长度至少 6 位');
        }

        $row['aid']        = $aid;
        $row['createtime'] = time();
        if (!isset($row['status'])) {
            $row['status'] = 1;
        }
        if (!isset($row['is_open'])) {
            $row['is_open'] = 1;
        }
        $bid = (int) ScopeService::query('business', $aid)->insertGetId($row);

        Db::name('admin_user')->insert([
            'aid'              => $aid,
            'bid'              => $bid,
            'un'               => $un,
            'pwd'              => password_hash($pwd, PASSWORD_DEFAULT),
            'truename'         => (string) ($uinfo['truename'] ?? ''),
            'mobile'           => (string) ($uinfo['mobile'] ?? ''),
            'groupid'          => 0,
            'bids'             => '',
            'status'           => 1,
            'remark'           => '',
            'mid'              => 0,
            'isadmin'          => 1,
            'addid'            => $uid,
            'createtime'       => time(),
            'random_str'       => bin2hex(random_bytes(8)),
            'auth_data'        => $authData,
            'wxauth_data'      => $wxauthData,
            'notice_auth_data' => $noticeAuthData,
            'hexiao_auth_data' => $hexiaoAuthData,
        ]);
        PlogService::write($aid, $uid, '添加商户' . $bid);

        return $this->success(['id' => $bid], '添加成功');
    }

    /**
     * 批量删除（存在未发货订单拒绝）
     */
    public function delete()
    {
        $aid = ScopeService::aidOf($this->request);
        $ids = array_values(array_filter(array_map('intval', (array) $this->request->post('ids/a', []))));
        if (empty($ids)) {
            throw new BusinessException('请选择商户');
        }
        $pending = Db::name('shop_order')->where('aid', $aid)->whereIn('bid', $ids)->where('status', 1)->find();
        if ($pending) {
            throw new BusinessException('存在未发货订单的商户，不能删除');
        }
        ScopeService::query('business', $aid)->whereIn('id', $ids)->delete();
        Db::name('admin_user')->where('aid', $aid)->whereIn('bid', $ids)->delete();
        Db::name('business_moneylog')->where('aid', $aid)->whereIn('bid', $ids)->delete();
        // 名下商品下架
        Db::name('shop_product')->where('aid', $aid)->whereIn('bid', $ids)->update(['status' => 0]);
        PlogService::write($aid, (int) ($this->request->authUid ?? 0), '删除商户' . implode(',', $ids));
        return $this->success([], '删除成功');
    }

    /**
     * 批量改审核状态（0待审核/1通过/2驳回/3过期），驳回可带原因
     */
    public function setst()
    {
        $aid    = ScopeService::aidOf($this->request);
        $ids    = array_values(array_filter(array_map('intval', (array) $this->request->post('ids/a', []))));
        $st     = (int) $this->request->post('st', 1);
        $reason = trim((string) $this->request->post('reason', ''));
        if (empty($ids)) {
            throw new BusinessException('请选择商户');
        }
        $update = ['status' => $st];
        if ('' !== $reason) {
            $update['reason'] = $reason;
        }
        ScopeService::query('business', $aid)->whereIn('id', $ids)->update($update);
        return $this->success([], '操作成功');
    }

    /**
     * 批量改营业状态（1营业/0停业）
     */
    public function setopen()
    {
        $aid = ScopeService::aidOf($this->request);
        $ids = array_values(array_filter(array_map('intval', (array) $this->request->post('ids/a', []))));
        $st  = (int) $this->request->post('st', 1) === 1 ? 1 : 0;
        if (empty($ids)) {
            throw new BusinessException('请选择商户');
        }
        ScopeService::query('business', $aid)->whereIn('id', $ids)->update(['is_open' => $st]);
        return $this->success([], $st === 1 ? '已营业' : '已停业');
    }

    /**
     * 余额充值
     */
    public function recharge()
    {
        $aid   = ScopeService::aidOf($this->request);
        $id    = (int) $this->request->post('id', 0);
        $money = round((float) $this->request->post('money', 0), 2);
        if ($id <= 0) {
            throw new BusinessException('缺少商户ID');
        }
        if ($money == 0.0) {
            throw new BusinessException('请输入充值金额');
        }
        $b = ScopeService::query('business', $aid)->where('id', $id)->find();
        if (empty($b)) {
            throw new BusinessException('未找到该商户');
        }
        ScopeService::query('business', $aid)->where('id', $id)->inc('money', $money)->update();
        Db::name('business_moneylog')->insert([
            'aid'        => $aid,
            'bid'        => $id,
            'money'      => $money,
            'type'       => '平台充值',
            'remark'     => '平台充值',
            'createtime' => time(),
        ]);
        PlogService::write($aid, (int) ($this->request->authUid ?? 0), '商户充值' . $id . ':' . $money);
        return $this->success([], '充值成功');
    }

    /**
     * 登录地址（电脑端/手机端，二维码由前端生成）
     */
    public function loginUrl()
    {
        $origin = (string) $this->request->header('origin', '');
        if ('' === $origin) {
            $referer = (string) $this->request->header('referer', '');
            if ('' !== $referer && preg_match('#^(https?://[^/]+)#', $referer, $m)) {
                $origin = $m[1];
            }
        }
        if ('' === $origin) {
            $origin = $this->request->scheme() . '://' . $this->request->host();
        }
        $pc = $origin . '/#/login';
        return $this->success(['pc_url' => $pc, 'mobile_url' => $pc]);
    }

    /* ============ 工具 ============ */

    private function jsonArr(mixed $v): array
    {
        if (is_array($v)) {
            return array_values(array_map('strval', $v));
        }
        if (is_string($v) && '' !== $v) {
            $d = json_decode($v, true);
            return is_array($d) ? array_values(array_map('strval', $d)) : [];
        }
        return [];
    }

    private function normalizeField(string $key, mixed $v): mixed
    {
        return match ($key) {
            'feepercent' => round((float) $v, 2),
            'wxpayst', 'status', 'sort', 'is_open', 'kdniao_intsend' => (int) $v,
            'pics', 'zhengming' => is_array($v) ? implode(',', array_map('strval', $v)) : (string) $v,
            default => is_scalar($v) ? (string) $v : '',
        };
    }
}
