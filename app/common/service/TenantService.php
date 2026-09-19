<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;

/**
 * 租户（admin 表）管理服务 —— 超管控制台使用
 *
 * 约定：
 * - admin 表 = 租户表（username/company/contacts/phone/deleted 为 20260812000015 迁移补充列）
 * - admin_user 表 = 登录账号表（un/pwd），isadmin=2 为平台超管
 * - 密码存储兼容两种格式：bcrypt（password_hash）与旧系统 md5
 * - 租户删除为软删（deleted=1），禁止物理删除
 */
class TenantService
{
    /** 租户状态：1 正常 2 关闭 */
    public const STATUS_NORMAL = 1;
    public const STATUS_CLOSED = 2;

    /**
     * 超管控制台登录（校验 admin_user 且 isadmin=2）
     *
     * @return array{token: string, userinfo: array{id: int, username: string, isadmin: int}}
     */
    public function login(string $username, string $password): array
    {
        $user = Db::name('admin_user')->where('un', $username)->find();

        if (empty($user) || !self::verifyPassword($password, (string) $user['pwd'])) {
            throw new BusinessException('账号或密码错误', 401, 401);
        }

        if ((int) $user['isadmin'] !== 2) {
            throw new BusinessException('非超管账号，禁止登录控制台', 401, 401);
        }

        if ((int) $user['status'] !== 1) {
            throw new BusinessException('账号已被禁用', 401, 401);
        }

        $token = JwtService::sign([
            'uid'     => (int) $user['id'],
            'aid'     => (int) ($user['aid'] ?? 0),
            'un'      => (string) $user['un'],
            'isadmin' => (int) $user['isadmin'],
            'role'    => 'console',
        ]);

        return [
            'token'    => $token,
            'userinfo' => [
                'id'       => (int) $user['id'],
                'username' => (string) $user['un'],
                'isadmin'  => (int) $user['isadmin'],
            ],
        ];
    }

    /**
     * 控制台仪表盘（跨租户聚合，表无数据时返回 0）
     * 四卡统计（对齐产品首页）：平台会员总数 / 平台商品数量 / 平台订单数与金额 / 平台收款金额
     */
    public function dashboard(): array
    {
        $now        = time();
        $dayStart   = strtotime(date('Y-m-d'));   // 今日 0 点
        $yestStart  = $dayStart - 86400;          // 昨日 0 点
        $monthStart = strtotime(date('Y-m-01'));  // 本月 1 号 0 点

        // 卡 1：平台会员总数（昨日新增 / 本月新增）
        $memberTotal = $this->safeCount('member');
        $memberYest  = $this->safeCount('member', [['createtime', '>=', $yestStart], ['createtime', '<', $dayStart]]);
        $memberMonth = $this->safeCount('member', [['createtime', '>=', $monthStart]]);

        // 卡 2：平台商品数量（未上架 / 已上架）
        $goodsOn  = $this->safeCount('shop_product', [['status', '=', 1]]);
        $goodsOff = $this->safeCount('shop_product', [['status', '=', 0]]);

        // 卡 3：平台订单数 / 订单金额（昨日新增 笔/金额，本月新增 笔/金额）
        $orderTotal  = $this->safeCount('shop_order');
        $orderAmount = $this->safeSum('shop_order', 'totalprice', [['status', 'in', [1, 2, 3]]]);
        [$orderYestCount, $orderYestAmount] = $this->safeCountSum('shop_order', 'totalprice', [['createtime', '>=', $yestStart], ['createtime', '<', $dayStart]]);
        [$orderMonthCount, $orderMonthAmount] = $this->safeCountSum('shop_order', 'totalprice', [['createtime', '>=', $monthStart]]);

        // 卡 4：平台收款金额（已支付流水，昨日新增 / 本月新增）
        $payTotal = $this->safeSum('pay_transaction', 'money', [['status', '=', 1]]);
        $payYest  = $this->safeSum('pay_transaction', 'money', [['status', '=', 1], ['paytime', '>=', $yestStart], ['paytime', '<', $dayStart]]);
        $payMonth = $this->safeSum('pay_transaction', 'money', [['status', '=', 1], ['paytime', '>=', $monthStart]]);

        return [
            // 旧版卡片字段（兼容保留）
            'tenant_total'   => $this->safeCount('admin', [['deleted', '=', 0]]),
            'tenant_normal'  => $this->safeCount('admin', [['deleted', '=', 0], ['status', '=', self::STATUS_NORMAL]]),
            'tenant_expired' => $this->safeCount('admin', [['deleted', '=', 0], ['endtime', '>', 0], ['endtime', '<', $now]]),
            'member_total'   => $memberTotal,
            'order_total'    => $orderTotal,
            'order_amount'   => round($orderAmount, 2),
            // 四卡统计字段
            'member_yesterday'     => $memberYest,
            'member_month'         => $memberMonth,
            'goods_total'          => $goodsOn + $goodsOff,
            'goods_on'             => $goodsOn,
            'goods_off'            => $goodsOff,
            'order_yesterday_count'  => $orderYestCount,
            'order_yesterday_amount' => round($orderYestAmount, 2),
            'order_month_count'      => $orderMonthCount,
            'order_month_amount'     => round($orderMonthAmount, 2),
            'pay_total'   => round($payTotal, 2),
            'pay_yesterday' => round($payYest, 2),
            'pay_month'     => round($payMonth, 2),
        ];
    }

    /**
     * 租户分页列表
     *
     * 首位固定展示平台超管 admin 虚拟行（id=0 / is_super=1，展示 ID 为 1）：
     * 真实租户展示 ID = 真实 id + 1（前端展示层映射，操作仍用真实 id）。
     *
     * 筛选参数（$p）：
     * - id：展示语义反解，1=匹配 admin 虚拟行，N(>=2)=匹配真实 id=N-1
     * - account：模糊匹配 账号/联系人/电话
     * - keyword：模糊匹配 绑定公众号/小程序名称（open_authorizer）
     * - status：''=全部 1=正常 2=关闭
     * - sort_field/sort_order：logintime|createtime|endtime + asc|desc，默认 id 降序
     *
     * @return array{list: array, total: int, page: int, pagesize: int}
     */
    public function tenantList(array $p): array
    {
        $page      = max(1, (int) ($p['page'] ?? 1));
        $pagesize  = max(1, min(100, (int) ($p['pagesize'] ?? 20)));
        $idFilter  = trim((string) ($p['id'] ?? ''));
        $account   = trim((string) ($p['account'] ?? ''));
        $keyword   = trim((string) ($p['keyword'] ?? ''));
        $status    = trim((string) ($p['status'] ?? ''));
        $sortField = (string) ($p['sort_field'] ?? '');
        $sortAsc   = 'asc' === strtolower((string) ($p['sort_order'] ?? ''));

        // 绑定公众号/小程序（开放平台授权方）
        $bindMap = $this->loadBindings();

        // 关键字=公众号名称/小程序名称：先筛出命中的租户 id 集合
        $keywordAids = null;
        if ('' !== $keyword) {
            $keywordAids = [];
            foreach ($bindMap as $aid => $bind) {
                foreach (array_merge($bind['mp'], $bind['wx']) as $name) {
                    if (false !== stripos($name, $keyword)) {
                        $keywordAids[] = $aid;
                        break;
                    }
                }
            }
        }

        $query = Db::name('admin')->where('deleted', 0);

        // ID 筛选按展示语义反解
        if ('' !== $idFilter) {
            $n = (int) $idFilter;
            if ($n >= 2) {
                $query->where('id', $n - 1);
            } else {
                $query->whereRaw('1=0'); // 1=admin 虚拟行专属，0/负数无匹配
            }
        }

        if ('' !== $account) {
            $like = '%' . $account . '%';
            $query->where(function ($q) use ($like) {
                $q->whereLike('username', $like)
                    ->whereOr('contacts', 'like', $like)
                    ->whereOr('phone', 'like', $like);
            });
        }

        if ('' !== $status) {
            $query->where('status', (int) $status);
        }

        if (null !== $keywordAids) {
            $query->whereIn('id', empty($keywordAids) ? [0] : $keywordAids);
        }

        $rows = $query->select()->toArray();

        // 登录时间：取各租户主管理员账号的 logintime
        $loginMap = $this->loadLoginTimes(array_map(static fn (array $r): int => (int) $r['id'], $rows));

        $now     = time();
        $tenants = [];
        foreach ($rows as $row) {
            $aid        = (int) $row['id'];
            $endtime    = (int) ($row['endtime'] ?? 0);
            $createtime = (int) ($row['createtime'] ?? 0);
            $logints    = (int) ($loginMap[$aid] ?? 0);
            $bind       = $bindMap[$aid] ?? ['mp' => [], 'wx' => []];

            $tenants[] = [
                'id'         => $aid,
                'username'   => (string) ($row['username'] ?? ''),
                'company'    => (string) ($row['company'] ?? ''),
                'contacts'   => (string) ($row['contacts'] ?? ''),
                'phone'      => (string) ($row['phone'] ?? ''),
                'remark'     => (string) ($row['remark'] ?? ''),
                'logintime'  => $logints > 0 ? date('Y-m-d H:i', $logints) : '',
                'bind_mp'    => implode('、', $bind['mp']),
                'bind_wx'    => implode('、', $bind['wx']),
                'createtime' => $createtime > 0 ? date('Y-m-d H:i', $createtime) : '',
                'endtime'    => $endtime > 0 ? date('Y-m-d H:i', $endtime) : '',
                'status'     => (int) ($row['status'] ?? self::STATUS_NORMAL),
                'is_expired' => $endtime > 0 && $endtime < $now,
                'is_super'   => 0,
                '_sort'      => ['logintime' => $logints, 'createtime' => $createtime, 'endtime' => $endtime],
            ];
        }

        // 排序：默认 id 降序；登录/创建/到期时间可排序（虚拟行始终钉在首位）
        if (in_array($sortField, ['logintime', 'createtime', 'endtime'], true)) {
            usort($tenants, static function (array $a, array $b) use ($sortField, $sortAsc): int {
                $va = (int) $a['_sort'][$sortField];
                $vb = (int) $b['_sort'][$sortField];

                return $sortAsc ? $va <=> $vb : $vb <=> $va;
            });
        } else {
            usort($tenants, static fn (array $a, array $b): int => $b['id'] <=> $a['id']);
        }

        // admin 虚拟行是否命中当前筛选
        $adminRow = $this->adminRowMatchesFilter($idFilter, $account, $keyword, $status)
            ? $this->adminVirtualRow()
            : null;

        $total = count($tenants) + (null !== $adminRow ? 1 : 0);
        $start = ($page - 1) * $pagesize;

        if (null !== $adminRow) {
            // 虚拟行占据全局第 0 位：首页首行展示，后续页偏移量整体前移 1
            $slice = 0 === $start
                ? array_merge([$adminRow], array_slice($tenants, 0, $pagesize - 1))
                : array_slice($tenants, $start - 1, $pagesize);
        } else {
            $slice = array_slice($tenants, $start, $pagesize);
        }

        foreach ($slice as &$item) {
            unset($item['_sort']);
        }
        unset($item);

        return [
            'list'     => $slice,
            'total'    => $total,
            'page'     => $page,
            'pagesize' => $pagesize,
        ];
    }

    /**
     * admin 超管虚拟行（列表首位，展示 ID=1）
     */
    protected function adminVirtualRow(): array
    {
        $super   = Db::name('admin_user')->where('isadmin', 2)->order('id', 'asc')->find();
        $logints = (int) ($super['logintime'] ?? 0);

        return [
            'id'         => 0,
            'username'   => 'admin',
            'company'    => '平台超管',
            'contacts'   => TenantConfigService::get(0, 'super_contacts'),
            'phone'      => TenantConfigService::get(0, 'super_phone'),
            'remark'     => TenantConfigService::get(0, 'super_remark', '平台超级管理员（最高权限）'),
            'logintime'  => $logints > 0 ? date('Y-m-d H:i', $logints) : '',
            'bind_mp'    => '',
            'bind_wx'    => '',
            'createtime' => '',
            'endtime'    => '永久',
            'status'     => self::STATUS_NORMAL,
            'is_expired' => false,
            'is_super'   => 1,
        ];
    }

    /**
     * admin 虚拟行是否命中 ID/账号/关键字/状态 筛选
     */
    protected function adminRowMatchesFilter(string $idFilter, string $account, string $keyword, string $status): bool
    {
        // ID 筛选：仅输入 1 时命中虚拟行
        if ('' !== $idFilter && 1 !== (int) $idFilter) {
            return false;
        }

        // 状态筛选：虚拟行视为正常（1），筛选关闭时不展示
        if ('' !== $status && (int) $status !== self::STATUS_NORMAL) {
            return false;
        }

        // 关键字=绑定公众号/小程序名称：超管无绑定，不命中
        if ('' !== $keyword) {
            return false;
        }

        if ('' !== $account) {
            foreach (['admin', '平台超管', '平台超级管理员'] as $field) {
                if (false !== stripos($field, $account)) {
                    return true;
                }
            }

            return false;
        }

        return true;
    }

    /**
     * 加载授权方绑定关系：aid => ['mp' => 公众号名称[], 'wx' => 小程序名称[]]
     * 公众号有原始 ID（gh_ 前缀），小程序无，以此区分
     *
     * @return array<int, array{mp: string[], wx: string[]}>
     */
    protected function loadBindings(): array
    {
        try {
            $rows = Db::name('open_authorizer')
                ->where('auth_status', 1)
                ->field('aid, nick_name, user_name')
                ->select()
                ->toArray();
        } catch (\Throwable) {
            return [];
        }

        $map = [];
        foreach ($rows as $row) {
            $aid  = (int) ($row['aid'] ?? 0);
            $name = trim((string) ($row['nick_name'] ?? ''));
            if ($aid <= 0 || '' === $name) {
                continue;
            }
            $isMp = str_starts_with((string) ($row['user_name'] ?? ''), 'gh_');
            if (!isset($map[$aid])) {
                $map[$aid] = ['mp' => [], 'wx' => []];
            }
            $map[$aid][$isMp ? 'mp' : 'wx'][] = $name;
        }

        return $map;
    }

    /**
     * 各租户主管理员登录时间映射：aid => logintime
     *
     * @param int[] $aids
     * @return array<int, int>
     */
    protected function loadLoginTimes(array $aids): array
    {
        if (empty($aids)) {
            return [];
        }

        try {
            $rows = Db::name('admin_user')
                ->whereIn('aid', $aids)
                ->order('isadmin', 'desc')
                ->order('id', 'asc')
                ->field('aid, logintime')
                ->select()
                ->toArray();
        } catch (\Throwable) {
            return [];
        }

        $map = [];
        foreach ($rows as $row) {
            $aid = (int) $row['aid'];
            if (!isset($map[$aid])) {
                $map[$aid] = (int) ($row['logintime'] ?? 0);
            }
        }

        return $map;
    }

    /**
     * 新增租户
     *
     * @param array $data {username, company, contacts, phone, remark, expire_days, status?, password?}
     * @return array{id: int}
     */
    public function tenantAdd(array $data): array
    {
        $username = trim((string) ($data['username'] ?? ''));

        if ($this->usernameExists($username)) {
            throw new BusinessException('租户账号已存在', 1);
        }

        $status = (int) ($data['status'] ?? self::STATUS_NORMAL);
        if ($status !== self::STATUS_NORMAL && $status !== self::STATUS_CLOSED) {
            throw new BusinessException('状态值只允许 1 或 2', 1);
        }

        $expireDays = (int) ($data['expire_days'] ?? 0);
        $now        = time();

        $id = Db::name('admin')->insertGetId([
            'username'   => $username,
            'company'    => trim((string) ($data['company'] ?? '')),
            'contacts'   => trim((string) ($data['contacts'] ?? '')),
            'phone'      => trim((string) ($data['phone'] ?? '')),
            'remark'     => trim((string) ($data['remark'] ?? '')),
            'status'     => $status,
            'endtime'    => $now + $expireDays * 86400,
            'createtime' => $now,
            'deleted'    => 0,
        ]);

        // 指定初始密码：预创建租户管理员账号
        $password = trim((string) ($data['password'] ?? ''));
        if ('' !== $password) {
            $user = $this->createTenantAdmin((int) $id);
            Db::name('admin_user')->where('id', (int) $user['id'])->update([
                'pwd' => password_hash($password, PASSWORD_DEFAULT),
            ]);
        }

        return ['id' => (int) $id];
    }

    /**
     * 编辑租户（账号/密码/联系人/电话/备注/到期时间/状态，当页保存）
     *
     * id=0 为平台超管虚拟行：密码改超管账号，联系人/电话/备注存 tenant_config(aid=0)
     *
     * @param array $data {id, username?, password?, contacts, phone, remark, endtime?, status?}
     */
    public function tenantEdit(array $data): void
    {
        $id       = (int) ($data['id'] ?? 0);
        $password = trim((string) ($data['password'] ?? ''));

        if ($id <= 0) {
            if ('' !== $password) {
                $super = Db::name('admin_user')->where('isadmin', 2)->order('id', 'asc')->find();
                if (empty($super)) {
                    throw new BusinessException('未找到平台超管账号', 1);
                }
                Db::name('admin_user')->where('id', (int) $super['id'])->update([
                    'pwd' => password_hash($password, PASSWORD_DEFAULT),
                ]);
            }
            TenantConfigService::set(0, 'super_contacts', trim((string) ($data['contacts'] ?? '')));
            TenantConfigService::set(0, 'super_phone', trim((string) ($data['phone'] ?? '')));
            if ('' !== trim((string) ($data['remark'] ?? ''))) {
                TenantConfigService::set(0, 'super_remark', trim((string) $data['remark']));
            }

            return;
        }

        $tenant = $this->findTenant($id);

        $username = trim((string) ($data['username'] ?? ''));
        if ('' !== $username && $this->usernameExists($username, $id)) {
            throw new BusinessException('租户账号已存在', 1);
        }

        $update = [
            'contacts' => trim((string) ($data['contacts'] ?? '')),
            'phone'    => trim((string) ($data['phone'] ?? '')),
            'remark'   => trim((string) ($data['remark'] ?? '')),
        ];
        if ('' !== $username) {
            $update['username'] = $username;
        }
        if (isset($data['status']) && '' !== (string) $data['status']) {
            $st = (int) $data['status'];
            if ($st !== self::STATUS_NORMAL && $st !== self::STATUS_CLOSED) {
                throw new BusinessException('状态值只允许 1 或 2', 1);
            }
            $update['status'] = $st;
        }
        $endtime = trim((string) ($data['endtime'] ?? ''));
        if ('' !== $endtime) {
            $ts = strtotime($endtime);
            if (false === $ts) {
                throw new BusinessException('到期时间格式无效', 1);
            }
            $update['endtime'] = $ts;
        }

        Db::name('admin')->where('id', $tenant['id'])->update($update);

        if ('' !== $password) {
            $user = Db::name('admin_user')
                ->where('aid', $tenant['id'])
                ->order('isadmin', 'desc')
                ->order('id', 'asc')
                ->find();
            if (empty($user)) {
                $created = $this->createTenantAdmin((int) $tenant['id']);
                $user    = Db::name('admin_user')->where('id', (int) $created['id'])->find();
            }
            Db::name('admin_user')->where('id', (int) $user['id'])->update([
                'pwd' => password_hash($password, PASSWORD_DEFAULT),
            ]);
        }
    }

    /**
     * 删除租户（软删）；平台超管账号不可删除
     */
    public function tenantDelete(int $id): void
    {
        if ($id <= 0) {
            throw new BusinessException('平台超管账号不可删除', 1);
        }

        $tenant = $this->findTenant($id);

        Db::name('admin')->where('id', $tenant['id'])->update(['deleted' => 1]);
    }

    /**
     * 切换租户状态（1 正常 / 2 关闭）
     */
    public function tenantSetStatus(int $id, int $status): void
    {
        if ($status !== self::STATUS_NORMAL && $status !== self::STATUS_CLOSED) {
            throw new BusinessException('状态值只允许 1 或 2', 1);
        }

        $tenant = $this->findTenant($id);

        Db::name('admin')->where('id', $tenant['id'])->update(['status' => $status]);
    }

    /**
     * 租户续费：在「现有到期时间与当前时间的较大者」基础上加 days 天
     *
     * @return array{endtime: int}
     */
    public function tenantRenew(int $id, int $days): array
    {
        $tenant = $this->findTenant($id);

        $base    = max((int) $tenant['endtime'], time());
        $endtime = $base + $days * 86400;

        Db::name('admin')->where('id', $tenant['id'])->update(['endtime' => $endtime]);

        return ['endtime' => $endtime];
    }

    /**
     * 代登录：签发该租户管理员身份的 JWT（role=admin）
     * 租户无 admin_user 记录时自动创建默认账号
     *
     * @return array{token: string, username: string}
     */
    public function tenantProxyLogin(int $id): array
    {
        $tenant = $this->findTenant($id);

        // 平台超管账号（首个 isadmin=2 行）的 aid 指向租户1，代登录不得复用它，
        // 否则 token 的 uid/un 变成超管，租户壳顶栏会错误显示 admin
        $superId = (int) (Db::name('admin_user')->where('isadmin', 2)->order('id', 'asc')->value('id') ?? 0);

        $user = Db::name('admin_user')
            ->where('aid', $tenant['id'])
            ->where('id', '<>', $superId)
            ->order('isadmin', 'desc')
            ->order('id', 'asc')
            ->find();

        if (empty($user)) {
            $user = $this->createTenantAdmin((int) $tenant['id']);
        }

        $token = JwtService::sign([
            'uid'     => (int) $user['id'],
            'aid'     => (int) $tenant['id'],
            'un'      => (string) $user['un'],
            'isadmin' => (int) ($user['isadmin'] ?? 1),
            'role'    => 'admin',
        ]);

        return [
            'token'    => $token,
            'username' => (string) $user['un'],
        ];
    }

    /**
     * 超管代登录（列表首位 admin 虚拟行专用）：
     * 签发带最高权限标记（super=1, isadmin=2）的租户壳 token（role=admin），
     * 以上线的首个正常租户为数据上下文（aid），租户壳鉴权识别 super 标记后放行全部菜单。
     *
     * @return array{token: string, username: string}
     */
    public function superProxyLogin(): array
    {
        $superUser = Db::name('admin_user')
            ->where('isadmin', 2)
            ->where('status', 1)
            ->order('id', 'asc')
            ->find();

        if (empty($superUser)) {
            throw new BusinessException('未找到平台超管账号', 1);
        }

        // 数据上下文：优先首个正常租户，其次任未删除租户
        $tenant = Db::name('admin')
            ->where('deleted', 0)
            ->where('status', self::STATUS_NORMAL)
            ->order('id', 'asc')
            ->find();

        if (empty($tenant)) {
            $tenant = Db::name('admin')->where('deleted', 0)->order('id', 'asc')->find();
        }

        if (empty($tenant)) {
            throw new BusinessException('暂无可进入的租户，请先新增租户', 1);
        }

        $token = JwtService::sign([
            'uid'     => (int) $superUser['id'],
            'aid'     => (int) $tenant['id'],
            'un'      => (string) ($superUser['un'] ?? 'admin'),
            'isadmin' => 2,
            'super'   => 1,
            'role'    => 'admin',
        ]);

        return [
            'token'    => $token,
            'username' => (string) ($superUser['un'] ?? 'admin'),
        ];
    }

    /**
     * 查询未删除的租户，不存在则抛 404
     *
     * @return array 租户记录
     */
    protected function findTenant(int $id): array
    {
        $tenant = Db::name('admin')->where('id', $id)->where('deleted', 0)->find();

        if (empty($tenant)) {
            throw new BusinessException('租户不存在', 404, 404);
        }

        return $tenant;
    }

    /**
     * username 唯一性校验（含历史数据，避免软删记录冲突）
     */
    protected function usernameExists(string $username, int $exceptId = 0): bool
    {
        if ('' === $username) {
            return false;
        }

        $query = Db::name('admin')->where('username', $username);

        if ($exceptId > 0) {
            $query->where('id', '<>', $exceptId);
        }

        return $query->count() > 0;
    }

    /**
     * 为租户自动创建默认管理员账号（un=租户账号 或 admin{id}，默认密码 123456）
     *
     * @return array 新建的 admin_user 记录
     */
    protected function createTenantAdmin(int $aid): array
    {
        $tenantUsername = (string) (Db::name('admin')->where('id', $aid)->value('username') ?? '');
        $un             = '' !== $tenantUsername ? $tenantUsername : 'admin' . $aid;

        // 极端情况下避免 un 冲突
        if (Db::name('admin_user')->where('un', $un)->count() > 0) {
            $un = 'tenant' . $aid . substr((string) microtime(true), -4);
        }

        $now = time();
        $id  = Db::name('admin_user')->insertGetId([
            'aid'        => $aid,
            'bid'        => 0,
            'un'         => $un,
            'pwd'        => password_hash('123456', PASSWORD_DEFAULT),
            'status'     => 1,
            'isadmin'    => 1,
            'createtime' => $now,
            'logintime'  => null,
            'random_str' => bin2hex(random_bytes(8)),
        ]);

        return [
            'id'      => $id,
            'un'      => $un,
            'isadmin' => 1,
        ];
    }

    /**
     * 安全计数：表不存在等异常时返回 0
     */
    protected function safeCount(string $table, array $where = []): int
    {
        try {
            return (int) Db::name($table)->where($where)->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * 安全求和：表不存在等异常时返回 0
     */
    protected function safeSum(string $table, string $field, array $where = []): float
    {
        try {
            return (float) Db::name($table)->where($where)->sum($field);
        } catch (\Throwable) {
            return 0.0;
        }
    }

    /**
     * 安全计数+求和：返回 [count, sum]
     *
     * @return array{0: int, 1: float}
     */
    protected function safeCountSum(string $table, string $field, array $where = []): array
    {
        try {
            $row = Db::name($table)->where($where)
                ->field('COUNT(*) AS cnt, SUM(' . $field . ') AS total')
                ->find();

            return [(int) ($row['cnt'] ?? 0), (float) ($row['total'] ?? 0)];
        } catch (\Throwable) {
            return [0, 0.0];
        }
    }

    /**
     * 密码校验：兼容 bcrypt 与旧系统 md5
     */
    public static function verifyPassword(string $password, string $hash): bool
    {
        if ('' === $hash) {
            return false;
        }

        if (str_starts_with($hash, '$2y$') || str_starts_with($hash, '$2a$') || str_starts_with($hash, '$2b$')) {
            return password_verify($password, $hash);
        }

        return hash_equals($hash, md5($password));
    }
}
