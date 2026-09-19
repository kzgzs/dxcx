<?php

declare(strict_types=1);

namespace app\api\controller;

use app\BaseController;
use app\common\service\DesignerService;
use app\common\service\GoodsService;
use app\common\service\JwtService;
use app\common\service\PromotionService;
use think\facade\Db;

/**
 * API 应用入口（面向小程序/H5 等终端）
 */
class Index extends BaseController
{
    public function __construct(
        \think\App $app,
        protected GoodsService $goodsService,
        protected PromotionService $promotionService,
        protected DesignerService $designerService
    ) {
        parent::__construct($app);
    }

    public function index()
    {
        return $this->success([
            'app' => 'api',
            'aid' => $this->request->middleware('aid'),
        ]);
    }

    /**
     * 首页聚合（匿名白名单，aid 走 TenantMiddleware 解析）
     * GET /api/index/home → {banners:[], goods:[], promotions:[]}
     * banners 一期无数据源固定空数组；goods 取上架商品前 10；promotions 取进行中满减
     */
    public function home()
    {
        $aid = (int) $this->request->aid;

        $goods = $aid > 0 ? ($this->goodsService->apiGoodsList($aid, 1, 10, '', '')['list'] ?? []) : [];
        $promotions = $aid > 0 ? ($this->promotionService->apiActiveList($aid)['list'] ?? []) : [];

        return $this->success([
            'banners'    => [],
            'goods'      => $goods,
            'promotions' => $promotions,
        ]);
    }

    /**
     * 系统配置下发（匿名白名单，aid 走 TenantMiddleware 解析）
     * GET /api/index/sysset
     * 租户级 admin_set（店名/客服/主题色）+ shop_sysset（商城开关）
     * + 平台级 tenant_config（aid=0，sys_system_ 前缀）
     */
    public function sysset()
    {
        $aid = (int) $this->request->aid;

        $data = $this->designerService->sysset($aid);
        // 任务308：前端门控（状态 + 访问条件）随下发；匿名白名单下尽力解析可选 token
        [$mid, $levelid] = $this->optionalMember($aid);
        $data['gate'] = $this->designerService->syssetGate($data, $mid, $levelid);

        return $this->success($data);
    }

    /**
     * 最近购买记录（订单播报滚动条数据源，任务308）
     * GET /api/index/recentOrders → {list:[{nickname,headimg,title,createtime}]}
     * 口径：shop_order 已支付未关闭（status 1/2/3）倒序 10 条，join member 取昵称头像
     */
    public function recentOrders()
    {
        $aid = (int) $this->request->aid;
        if ($aid <= 0) {
            return $this->success(['list' => []]);
        }

        $rows = Db::name('shop_order')
            ->where('aid', $aid)
            ->whereIn('status', [1, 2, 3])
            ->order('id', 'desc')
            ->limit(10)
            ->field('id,mid,title,createtime')
            ->select()
            ->toArray();

        $mids = array_values(array_unique(array_filter(array_map(static fn ($r) => (int) ($r['mid'] ?? 0), $rows))));
        $members = [];
        if (!empty($mids)) {
            foreach (Db::name('member')->where('aid', $aid)->whereIn('id', $mids)->field('id,nickname,headimg')->select()->toArray() as $m) {
                $members[(int) $m['id']] = $m;
            }
        }

        $list = [];
        foreach ($rows as $r) {
            $m      = $members[(int) ($r['mid'] ?? 0)] ?? null;
            $list[] = [
                'nickname'   => (string) ($m['nickname'] ?? '用户'),
                'headimg'    => (string) ($m['headimg'] ?? ''),
                'title'      => mb_substr((string) ($r['title'] ?? '商品'), 0, 18),
                'createtime' => (int) ($r['createtime'] ?? 0),
            ];
        }

        return $this->success(['list' => $list]);
    }

    /**
     * 可选会员上下文（匿名白名单接口用：无 token/无效 token 回退匿名 [0,0]）
     *
     * @return array{0: int, 1: int} [mid, levelid]
     */
    protected function optionalMember(int $aid): array
    {
        if ($aid <= 0) {
            return [0, 0];
        }

        $payload = (array) ($this->request->authPayload ?? []);
        if ([] === $payload) {
            $authorization = (string) $this->request->header('authorization', '');
            if (str_starts_with($authorization, 'Bearer ')) {
                $payload = JwtService::decode(trim(substr($authorization, 7))) ?? [];
            }
        }

        $mid = (int) ($payload['mid'] ?? ($payload['uid'] ?? 0));
        if ($mid <= 0) {
            return [0, 0];
        }

        $member = Db::name('member')->where('aid', $aid)->where('id', $mid)->find();
        if (empty($member)) {
            return [0, 0];
        }

        return [$mid, (int) ($member['levelid'] ?? 0)];
    }
}
