<?php

declare(strict_types=1);

namespace app\api\controller;

use app\common\exception\BusinessException;
use app\common\service\DesignerService;
use app\common\service\JwtService;
use app\common\service\PayService;
use think\facade\Db;

/**
 * C 端设计器页面消费（匿名白名单，aid 走 TenantMiddleware 解析）
 *
 * 对照参考系统 ApiIndex/index（canzhao 只读）：返回 pageinfo + pagecontent；
 * 本系统口径：pageinfo（首项 topbar）+ content（组件数组），JSON 容错。
 * 下发前按会员身份过滤（P27-5 任务249）：白名单路由中间件不解析 token，
 * 本控制器自行尽力解码 Bearer token 取 mid/等级，quanxian/needlogin 不满足的节点剔除。
 * 任务305：页面级访问判定（强制登录/查看权限/付费查看）随 access 下发，拦截时 content 置空；
 * pagebuy/pagepay 登录接口（非白名单）走统一支付单 order_type=pageview。
 */
class DesignerC extends Base
{
    public function __construct(
        \think\App $app,
        protected DesignerService $designerService,
        protected PayService $payService
    ) {
        parent::__construct($app);
    }

    /**
     * 设计页消费
     * GET /api/designer/page?id=xx&ishome=1|2
     * - 无 id：取当前 aid 下 ishome 口径页（默认 ishome=1 首页；ishome=2 会员中心页，P27-4 任务248）；
     *   无命中返回空结构 code=0（前端走默认兜底）
     * - 有 id：取指定页面（aid 隔离），不存在返回错误
     */
    public function page()
    {
        $aid    = (int) $this->request->aid;
        $id     = (int) $this->request->param('id', 0);
        $ishome = (int) $this->request->param('ishome', 1);

        $page = $this->designerService->fetchPage($aid, $id, $ishome);

        if ([] === $page) {
            if ($id > 0) {
                return $this->error('页面不存在');
            }

            // 无首页：空结构下发，前端走默认首页兜底
            return $this->success($this->designerService->emptyPage());
        }

        // 下发前权限过滤：传入会员上下文（mid/等级），未登录按匿名口径处理
        [$mid, $levelid] = $this->resolveMemberContext($aid);

        // 页面级访问判定（任务305：强制登录/查看权限/付费查看）
        $access = $this->designerService->pageAccess($page, $mid);
        $blocked = !$access['needlogin_ok'] || !$access['perm_ok']
            || ($access['payview'] && !$access['paid']);

        if (!$blocked) {
            $page = $this->designerService->applyMemberFilter($page, $mid, $levelid);
        } else {
            // 拦截页：content 置空（前端按 access 展示登录跳转/无权限遮罩/付费遮罩）
            $page['content'] = [];
        }

        $page['access'] = $access;

        return $this->success($page);
    }

    /**
     * 付费查看下单（登录）：创建/复用待支付购买单
     * POST /api/designer/pagebuy {page_id}
     * - 幂等：同人同页待支付单复用（价格随页面配置同步固化）；已购买直接放行
     */
    public function pagebuy()
    {
        [$aid, $mid] = $this->memberContext();

        $pageId = (int) $this->request->post('page_id', 0);
        if ($pageId <= 0) {
            throw new BusinessException('缺少页面ID');
        }

        $page = $this->designerService->fetchPage($aid, $pageId);
        if ([] === $page) {
            throw new BusinessException('页面不存在', 404, 404);
        }

        $topbar = $page['pageinfo'][0]['params'] ?? [];
        if (1 !== (int) ($topbar['payview'] ?? 0)) {
            throw new BusinessException('该页面无需付费');
        }

        $price = (float) ($topbar['payviewprice'] ?? 0);
        if ($price <= 0) {
            throw new BusinessException('页面付费金额未配置');
        }

        // 已购买：直接放行
        $paidRow = Db::name('designer_page_buy')
            ->where('aid', $aid)->where('mid', $mid)->where('page_id', $pageId)->where('status', 1)
            ->find();
        if (!empty($paidRow)) {
            return $this->success(['paid' => 1, 'orderid' => 0]);
        }

        // 复用待支付单（价格随页面配置同步）
        $buy = Db::name('designer_page_buy')
            ->where('aid', $aid)->where('mid', $mid)->where('page_id', $pageId)->where('status', 0)
            ->find();
        if (empty($buy)) {
            $buyId = (int) Db::name('designer_page_buy')->insertGetId([
                'aid'        => $aid,
                'mid'        => $mid,
                'page_id'    => $pageId,
                'pay_amount' => $price,
                'status'     => 0,
                'pay_type'   => '',
                'createtime' => time(),
                'paytime'    => 0,
            ]);
        } else {
            $buyId = (int) $buy['id'];
            if ((float) $buy['pay_amount'] !== $price) {
                Db::name('designer_page_buy')->where('id', $buyId)->update(['pay_amount' => $price]);
                // 金额变化：作废旧待支付单，下笔支付重建
                Db::name('pay_order')->where('order_type', 'pageview')->where('biz_order_id', $buyId)->where('status', 0)->delete();
            }
        }

        return $this->success(['paid' => 0, 'orderid' => $buyId, 'price' => $price]);
    }

    /**
     * 付费查看支付（登录；mock 模式内部直接入账放行）
     * POST /api/designer/pagepay {orderid, paytype}
     */
    public function pagepay()
    {
        [$aid, $mid] = $this->memberContext();

        $orderid = (int) $this->request->post('orderid', 0);
        $paytype = (string) $this->request->post('paytype', 'wechat');
        if ($orderid <= 0) {
            throw new BusinessException('缺少订单ID');
        }

        return $this->success($this->payService->payBiz($aid, $mid, 'pageview', $orderid, $paytype));
    }

    /**
     * 模板消费（任务278：B端模板卡二维码 → pages/index/main?tid=xx 预览）
     * GET /api/designer/template?tid=xx
     * - 按 tid 取模板（平台模板 aid=0 / 本租户自建），不存在返回错误
     * - 返回结构与 page() 对齐，权限过滤同口径
     */
    public function template()
    {
        $aid = (int) $this->request->aid;
        $tid = (int) $this->request->param('tid', 0);
        if ($tid <= 0) {
            return $this->error('参数错误');
        }

        $page = $this->designerService->fetchTemplate($aid, $tid);
        if ([] === $page) {
            return $this->error('模板不存在');
        }

        // 下发前权限过滤：与 page() 同口径（未登录按匿名处理）
        [$mid, $levelid] = $this->resolveMemberContext($aid);
        $page = $this->designerService->applyMemberFilter($page, $mid, $levelid);

        return $this->success($page);
    }

    /**
     * 尽力解析会员上下文（designer/page 在匿名白名单，中间件不解析 token）
     *
     * 仅接受 role='member' 且 token aid 与当前租户一致的合法 token；
     * 会员行不存在/无效 token/无 token 均回退匿名 [0,0]（公开页全量下发）。
     *
     * @return array{0: int, 1: int} [mid, levelid]
     */
    protected function resolveMemberContext(int $aid): array
    {
        $payload = (array) ($this->request->authPayload ?? []);

        if ([] === $payload) {
            $authorization = (string) $this->request->header('authorization', '');
            if (str_starts_with($authorization, 'Bearer ')) {
                $payload = JwtService::decode(trim(substr($authorization, 7))) ?? [];
            }
        }

        if ([] === $payload || 'member' !== (string) ($payload['role'] ?? '')) {
            return [0, 0];
        }

        // token 租户与当前访问租户不一致：视为匿名（防跨租户身份套用）
        if (isset($payload['aid']) && (int) $payload['aid'] !== $aid) {
            return [0, 0];
        }

        $mid = (int) ($payload['mid'] ?? ($payload['uid'] ?? 0));
        if ($mid <= 0 || $aid <= 0) {
            return [0, 0];
        }

        $member = Db::name('member')->where('aid', $aid)->where('id', $mid)
            ->field('id,levelid')->find();
        if (empty($member)) {
            return [0, 0];
        }

        return [$mid, (int) ($member['levelid'] ?? 0)];
    }

    /**
     * 选项卡子内容读取（任务332：designerpage_tab 表，tabid=tab组件id，tabindexid=选项项id）
     * 口径同 admin Designer::tabGet：按 aid+tabid+tabindexid 取子内容组件数组
     * GET /api/designer/tab/get?tabid=&tabindexid=
     */
    public function tabGet()
    {
        $aid        = (int) $this->request->aid;
        $tabid      = trim((string) $this->request->param('tabid', ''));
        $tabindexid = trim((string) $this->request->param('tabindexid', ''));
        if ($tabid === '' || $tabindexid === '') {
            return $this->error('参数错误');
        }

        $row = Db::name('designerpage_tab')
            ->where('aid', $aid)
            ->where('tabid', $tabid)
            ->where('tabindexid', $tabindexid)
            ->find();

        return $this->success([
            'tabid'      => $tabid,
            'tabindexid' => $tabindexid,
            'content'    => $row ? (json_decode((string) ($row['content'] ?? ''), true) ?: []) : [],
        ]);
    }

    /**
     * 登录页装修消费
     * GET /api/designer/login
     * - 已配置：返回 19 主题字段 data；未配置：返回空对象（C 端走默认样式）
     */
    public function login()
    {
        $aid = (int) $this->request->aid;

        return $this->success((object) $this->designerService->fetchLogin($aid));
    }
}
