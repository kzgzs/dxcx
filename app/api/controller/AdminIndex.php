<?php

declare(strict_types=1);

namespace app\api\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\JwtService;
use app\common\service\PlogService;
use app\common\service\ScopeService;
use app\common\service\StatService;
use app\common\service\TenantService;
use think\facade\Db;

/**
 * 手机端管理后端（api 应用，任务270）
 *
 * 约定：
 * - 响应走统一 Result（code=0 成功）
 * - 鉴权：login/autologin 免 token（AuthMiddleware 白名单）；其余接口取
 *   header 'token' 或 query token，查 cache 前缀 adminidx_{token} => admin_id（7天）
 * - 密码口径复用 TenantService::verifyPassword（bcrypt / 旧 md5 兼容），
 *   写入口径 password_hash(PASSWORD_DEFAULT)，同 AdminUser::save
 * - 统计口径复用 StatService::dashboard（B端首页面板）
 * - 登录日志复用 PlogService::write（remark=手机端登录）
 * - 权限组：groupid>0 时读取 admin_user_group 权限数据预留
 *   TODO: 按 wxauth_data（手机端权限集）做接口级细粒度拦截，本期不做
 */
class AdminIndex extends BaseController
{
    /** 手机端 token 有效期：7 天 */
    private const TOKEN_TTL = 7 * 86400;

    /** 当前登录管理员（admin() 守卫通过后填充） */
    protected array $adminUser = [];

    public function __construct(\think\App $app, protected StatService $statService)
    {
        parent::__construct($app);
    }

    // ================= 登录 =================

    /**
     * 账号密码登录
     * POST /api/adminidx/login {un,pwd}
     * 返回 {code:0,data:{token,admin:{id,un,isadmin,groupid,mid}}}
     */
    public function login()
    {
        $un  = trim((string) $this->request->post('un', ''));
        $pwd = (string) $this->request->post('pwd', '');

        if ('' === $un || '' === $pwd) {
            return $this->error('请输入账号和密码');
        }

        $user = Db::name('admin_user')->where('un', $un)->find();

        // 密码校验复用现有口径（bcrypt / 旧 md5 兼容）
        if (empty($user) || !TenantService::verifyPassword($pwd, (string) $user['pwd'])) {
            return $this->error('账号或密码错误', 401);
        }

        if ((int) ($user['status'] ?? 0) !== 1) {
            return $this->error('账号未启用', 401);
        }

        if ((int) ($user['isadmin'] ?? 0) === 2) {
            return $this->error('平台超管账号不可登录手机端管理', 403);
        }

        return $this->success($this->issueToken($user, '手机端登录'), '登录成功');
    }

    /**
     * B端 admin JWT 换发手机端 token
     * POST /api/adminidx/autologin {jwt}
     * JWT 校验复用 JwtService::decode（同 AuthMiddleware），仅认 role=admin
     */
    public function autologin()
    {
        $jwt = trim((string) $this->request->post('jwt', ''));

        if ('' === $jwt) {
            return $this->error('参数错误');
        }

        $payload = JwtService::decode($jwt);

        if (null === $payload || (string) ($payload['role'] ?? '') !== 'admin') {
            return $this->error('登录凭证无效', 401);
        }

        $user = Db::name('admin_user')->where('id', (int) ($payload['uid'] ?? 0))->find();

        if (empty($user) || (int) ($user['status'] ?? 0) !== 1) {
            return $this->error('账号不存在或未启用', 401);
        }

        return $this->success($this->issueToken($user, '手机端登录'), '登录成功');
    }

    // ================= 首页统计 =================

    /**
     * 首页统计（口径复用 StatService::dashboard，与B端首页一致）
     * GET /api/adminidx/stats
     * 今日收款金额/今日订单数/订单五状态计数（待付款/待发货/待收货/退款售后/已完成）
     */
    public function stats()
    {
        $user = $this->admin();
        $d    = $this->statService->dashboard((int) $user['aid']);

        return $this->success([
            'today_money'  => $d['today_money'],
            'today_orders' => $d['today_orders'],
            'wait_pay'     => $d['wait_pay'],
            'wait_ship'    => $d['wait_ship'],
            'wait_receive' => $d['shipped'],
            'wait_refund'  => $d['wait_refund'],
            'finished'     => $d['received'],
        ]);
    }

    // ================= 订单 =================

    /**
     * 订单列表
     * GET /api/adminidx/orderlist?st=&page=
     * st：0待付款 1待发货 2待收货 3已完成 4已取消 refund(售后)=存在申请中退款单的订单
     */
    public function orderlist()
    {
        $user     = $this->admin();
        $aid      = (int) $user['aid'];
        $st       = trim((string) $this->request->param('st', ''));
        $page     = max(1, (int) $this->request->param('page', 1));
        $pagesize = 10;

        $query = ScopeService::queryAs('shop_order', $aid, 'o')
            ->leftJoin('member m', 'm.id = o.mid')
            ->field('o.id, o.ordernum, o.status, o.totalprice, o.createtime, o.paytime, m.nickname as member_nickname, m.tel as member_tel')
            ->where('o.delete', 0);

        if ('refund' === $st || 'shouhou' === $st) {
            // 售后口径：存在申请中退款单（refund_status=2）的订单，同 StatService 待退款口径
            $oids = ScopeService::query('shop_refund_order', $aid)
                ->where('deleted', 0)
                ->where('refund_status', 2)
                ->column('orderid');
            $query->whereIn('o.id', $oids ?: [0]);
        } elseif ('' !== $st) {
            $query->where('o.status', (int) $st);
        }

        $total = (clone $query)->count();

        $rows = $query->order('o.id', 'desc')
            ->page($page, $pagesize)
            ->select()
            ->toArray();

        // 商品缩略：每单取第一件商品图
        $picMap = [];
        $ids    = array_map(static fn (array $r) => (int) $r['id'], $rows);

        if ($ids) {
            $goodsRows = Db::name('shop_order_goods')
                ->whereIn('orderid', $ids)
                ->order('id', 'asc')
                ->field('orderid, pic')
                ->select()
                ->toArray();

            foreach ($goodsRows as $g) {
                $picMap[(int) $g['orderid']] ??= (string) $g['pic'];
            }
        }

        $statusText = [0 => '待付款', 1 => '待发货', 2 => '待收货', 3 => '已完成', 4 => '已取消'];

        $list = [];
        foreach ($rows as $r) {
            $list[] = [
                'id'         => (int) $r['id'],
                'ordernum'   => (string) $r['ordernum'],
                'status'     => (int) $r['status'],
                'statustext' => $statusText[(int) $r['status']] ?? '',
                'money'      => round((float) $r['totalprice'], 2),
                'member'     => (string) (('' !== (string) ($r['member_nickname'] ?? '')) ? $r['member_nickname'] : ($r['member_tel'] ?? '')),
                'time'       => (int) (((int) ($r['paytime'] ?? 0)) > 0 ? $r['paytime'] : $r['createtime']),
                'pic'        => $picMap[(int) $r['id']] ?? '',
            ];
        }

        return $this->success(['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pagesize]);
    }

    // ================= 商品 =================

    /**
     * 商品列表
     * GET /api/adminidx/productlist?page=
     */
    public function productlist()
    {
        $user     = $this->admin();
        $aid      = (int) $user['aid'];
        $page     = max(1, (int) $this->request->param('page', 1));
        $pagesize = 10;

        $query = ScopeService::query('shop_product', $aid)
            ->where('deleted', 0)
            ->field('id, name, pic, sell_price, stock, status, createtime');

        $total = (clone $query)->count();

        $rows = $query->order('sort', 'desc')
            ->order('id', 'desc')
            ->page($page, $pagesize)
            ->select()
            ->toArray();

        $list = array_map(static fn (array $r): array => [
            'id'     => (int) $r['id'],
            'name'   => (string) ($r['name'] ?? ''),
            'pic'    => (string) ($r['pic'] ?? ''),
            'price'  => round((float) ($r['sell_price'] ?? 0), 2),
            'stock'  => (int) ($r['stock'] ?? 0),
            'status' => (int) ($r['status'] ?? 0),
        ], $rows);

        return $this->success(['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pagesize]);
    }

    /**
     * 商品上下架
     * POST /api/adminidx/productstatus {id,status}（status：0下架 1上架）
     */
    public function productstatus()
    {
        $user   = $this->admin();
        $aid    = (int) $user['aid'];
        $id     = (int) $this->request->post('id', 0);
        $status = (int) $this->request->post('status', -1);

        if ($id <= 0) {
            return $this->error('缺少商品ID');
        }
        if ($status !== 0 && $status !== 1) {
            return $this->error('状态值只允许 0 或 1');
        }

        $found = ScopeService::query('shop_product', $aid)
            ->where('deleted', 0)
            ->where('id', $id)
            ->find();

        if (empty($found)) {
            return $this->error('商品不存在');
        }

        Db::name('shop_product')->where('id', $id)->update(['status' => $status]);

        PlogService::write($aid, (int) $user['id'], '手机端' . (1 === $status ? '上架' : '下架') . '商品' . $id);

        return $this->success([], 1 === $status ? '上架成功' : '下架成功');
    }

    // ================= 会员 =================

    /**
     * 会员列表（头像/昵称/手机/余额/积分）
     * GET /api/adminidx/memberlist?page=
     */
    public function memberlist()
    {
        $user     = $this->admin();
        $aid      = (int) $user['aid'];
        $page     = max(1, (int) $this->request->param('page', 1));
        $pagesize = 10;

        $query = ScopeService::query('member', $aid)
            ->field('id, nickname, headimg, tel, money, score');

        $total = (clone $query)->count();

        $rows = $query->order('id', 'desc')
            ->page($page, $pagesize)
            ->select()
            ->toArray();

        $list = array_map(static fn (array $r): array => [
            'id'       => (int) $r['id'],
            'headimg'  => (string) ($r['headimg'] ?? ''),
            'nickname' => (string) ($r['nickname'] ?? ''),
            'tel'      => (string) ($r['tel'] ?? ''),
            'money'    => round((float) ($r['money'] ?? 0), 2),
            'score'    => (int) ($r['score'] ?? 0),
        ], $rows);

        return $this->success(['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pagesize]);
    }

    // ================= 财务 =================

    /**
     * 财务概览（口径同B端首页金额概览 StatService::dashboard）
     * GET /api/adminidx/finances
     * 会员余额总计/充值总额/消费总额/已退款
     */
    public function finances()
    {
        $user = $this->admin();
        $aid  = (int) $user['aid'];

        $member = ScopeService::query('member', $aid)
            ->field('coalesce(sum(money), 0) as balance_total, coalesce(sum(buymoney), 0) as spend_total')
            ->find();

        // 充值总额：recharge_order 已支付（status=1）
        $rechargeTotal = (float) ScopeService::query('recharge_order', $aid)
            ->where('status', 1)
            ->sum('money');

        // 已退款金额：refund_status=1 的 refund_money 合计（同 StatService 口径）
        $refundMoney = (float) ScopeService::query('shop_refund_order', $aid)
            ->where('deleted', 0)
            ->where('refund_status', 1)
            ->sum('refund_money');

        return $this->success([
            'balance_total'  => round((float) ($member['balance_total'] ?? 0), 2),
            'recharge_total' => round($rechargeTotal, 2),
            'spend_total'    => round((float) ($member['spend_total'] ?? 0), 2),
            'refund_money'   => round($refundMoney, 2),
        ]);
    }

    // ================= 密码 =================

    /**
     * 修改登录密码（校验旧密码后更新，写入口径同 AdminUser::save）
     * POST /api/adminidx/setpwd {oldpwd,newpwd}
     */
    public function setpwd()
    {
        $user   = $this->admin();
        $oldpwd = (string) $this->request->post('oldpwd', '');
        $newpwd = (string) $this->request->post('newpwd', '');

        if ('' === $oldpwd || '' === $newpwd) {
            return $this->error('请输入旧密码和新密码');
        }

        if (!TenantService::verifyPassword($oldpwd, (string) $user['pwd'])) {
            return $this->error('旧密码不正确');
        }

        if (mb_strlen($newpwd) < 6) {
            return $this->error('新密码长度至少 6 位');
        }

        Db::name('admin_user')->where('id', (int) $user['id'])->update([
            'pwd' => password_hash($newpwd, PASSWORD_DEFAULT),
        ]);

        PlogService::write((int) $user['aid'], (int) $user['id'], '手机端修改登录密码');

        return $this->success([], '密码修改成功');
    }

    // ================= 内部工具 =================

    /**
     * 登录守卫：header 'token' 或 query token → cache adminidx_{token} 取 admin_id
     * 无效一律 BusinessException code=401 提示重新登录
     *
     * @return array 当前管理员记录（admin_user 行）
     */
    protected function admin(): array
    {
        if (!empty($this->adminUser)) {
            return $this->adminUser;
        }

        $token = trim((string) $this->request->header('token', ''));

        if ('' === $token) {
            $token = trim((string) $this->request->param('token', ''));
        }

        // 32位十六进制校验同时防 cache key 注入
        if ('' === $token || 1 !== preg_match('/^[a-f0-9]{32}$/', $token)) {
            throw new BusinessException('未登录或登录已过期，请重新登录', 401, 401);
        }

        $uid = (int) cache('adminidx_' . $token);

        if ($uid <= 0) {
            throw new BusinessException('登录已过期，请重新登录', 401, 401);
        }

        $user = Db::name('admin_user')->where('id', $uid)->where('status', 1)->find();

        if (empty($user)) {
            throw new BusinessException('账号不存在或未启用，请重新登录', 401, 401);
        }

        // 权限组预留：groupid>0 时权限以 admin_user_group 为准（本期不做细粒度拦截）
        // TODO: 按 wxauth_data（手机端权限集）对各接口做细粒度拦截，本期预留
        if ((int) ($user['groupid'] ?? 0) > 0) {
            $group = Db::name('admin_user_group')->where('id', (int) $user['groupid'])->find();

            $user['wxauth_data']      = $group ? (string) ($group['wxauth_data'] ?? '') : '';
            $user['notice_auth_data'] = $group ? (string) ($group['notice_auth_data'] ?? '') : '';
            $user['hexiao_auth_data'] = $group ? (string) ($group['hexiao_auth_data'] ?? '') : '';
        }

        $this->adminUser = $user;

        return $user;
    }

    /**
     * 签发手机端 token：32位随机串，写 cache adminidx_{token}=admin_id（7天），
     * 更新 logintime/ip，记登录日志
     *
     * @return array{token: string, admin: array{id: int, un: string, isadmin: int, groupid: int, mid: int}}
     */
    protected function issueToken(array $user, string $logRemark): array
    {
        $token = bin2hex(random_bytes(16)); // 32位随机串

        cache('adminidx_' . $token, (int) $user['id'], self::TOKEN_TTL);

        Db::name('admin_user')->where('id', (int) $user['id'])->update([
            'logintime' => time(),
            'ip'        => $this->request->ip(),
        ]);

        PlogService::write((int) ($user['aid'] ?? 0), (int) $user['id'], $logRemark, 0, $this->request->ip());

        return [
            'token' => $token,
            'admin' => [
                'id'      => (int) $user['id'],
                'un'      => (string) ($user['un'] ?? ''),
                'isadmin' => (int) ($user['isadmin'] ?? 0),
                'groupid' => (int) ($user['groupid'] ?? 0),
                'mid'     => (int) ($user['mid'] ?? 0),
            ],
        ];
    }
}