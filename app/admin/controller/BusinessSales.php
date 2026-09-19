<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\PlogService;
use app\common\service\ScopeService;
use think\facade\Db;

/**
 * 租户后台 - 商户销量（批次8 任务221）
 *
 * 对标参考系统 Business.php sales()/del_sales()/getSalesAuth()（1300-1376 行）：
 * - business_sales 左连 business 取商户名，bid=0 显示为「平台」
 * - 营业额按 payorder（status=1）分模块汇总 13 类 type，逐模块对照参考系统口径
 * - getSalesAuth：12 模块权限聚合（auth_type!=1 时按 auth_data 逗号串逐项判定）
 */
class BusinessSales extends BaseController
{
    /** 营业额统计的 13 类订单 type（参考系统 sales() type_arr 原序） */
    private const MONEY_TYPES = [
        'shop', 'collage', 'kanjia', 'seckill', 'tuangou', 'scoreshop',
        'lucky_collage', 'cycle', 'yuyue', 'kecheng',
        'restaurant_takeaway', 'restaurant_shop', 'maidan',
    ];

    /** 12 模块权限判定串（参考系统 getSalesAuth() 逐项口径，键为返回字段名） */
    private const SALES_AUTH_RULES = [
        'shop'               => 'ShopProduct/index,ShopProduct/*,ShopCode/*',
        'collage'            => 'CollageProduct/index,CollageProduct/*,CollageCode/*',
        'kanjia'             => 'KanjiaProduct/index,KanjiaProduct/*,KanjiaCode/*',
        'seckill'            => 'SeckillProduct/index,SeckillProduct/*,SeckillCode/*',
        'tuangou'            => 'TuangouProduct/index,TuangouProduct/*,TuangouCode/*',
        'scoreshop'          => 'ScoreshopProduct/index,ScoreshopProduct/*,ScoreshopCode/*',
        'luckyCollage'       => 'LuckyCollageProduct/index,LuckyCollageProduct/*,LuckyCollageCode/*',
        'cycle'              => 'CycleProduct/index,CycleProduct/*,CycleCode/*',
        'yuyue'              => 'YuyueList/index,YuyueList/*',
        'kecheng'            => 'KechengCategory/index,KechengCategory/*',
        'maidan'             => 'Maidan/add,KechengList/*,Maidan/*',
        'restaurantTakeaway' => 'RestaurantTakeawayOrder/index,RestaurantTakeawayOrder/*',
        'restaurantShop'     => 'RestaurantShopOrder/index,RestaurantShopOrder/*',
    ];

    /**
     * 商户销量列表
     * GET /admin/businessSales/list?page=&pagesize=&bid=&name=
     */
    public function list()
    {
        $aid      = ScopeService::aidOf($this->request);
        $page     = max(1, (int) $this->request->get('page', 1));
        $pagesize = min(100, max(1, (int) $this->request->get('pagesize', 10)));
        $bid      = trim((string) $this->request->get('bid', ''));
        $name     = trim((string) $this->request->get('name', ''));

        $query = ScopeService::queryAs('business_sales', $aid, 's')
            ->join('business b', 's.bid=b.id', 'left')
            ->field('s.*,b.name');
        ScopeService::applyBid($query, $bid, 's.bid');
        if ('' !== $name) {
            $query->whereLike('b.name', '%' . $name . '%');
        }

        $total = (clone $query)->count();
        // 排序（对照参考系统 Business/sales：表头排序，白名单防注入，默认 bid 倒序）
        $sortFields = array_merge(
            ['id', 'bid', 'total_sales', 'sales', 'total_money'],
            array_map(static fn (string $t) => $t . '_sales', self::MONEY_TYPES),
            array_map(static fn (string $t) => $t . '_money', self::MONEY_TYPES),
        );
        $field   = trim((string) $this->request->get('field', ''));
        $order   = strtolower(trim((string) $this->request->get('order', ''))) === 'asc' ? 'asc' : 'desc';
        $orderRaw = in_array($field, $sortFields, true) ? 's.' . $field . ' ' . $order : 's.bid desc';
        $list  = $query->orderRaw($orderRaw)->page($page, $pagesize)->select()->toArray();

        // 营业额：当前页商户一次分组聚合（参考系统逐行 13 次查询的同口径合并）
        $moneyMap = [];
        if ($list) {
            $pageBids = array_map(static fn ($r) => (int) $r['bid'], $list);
            $rows = Db::name('payorder')
                ->where('aid', $aid)
                ->whereIn('bid', $pageBids)
                ->whereIn('type', self::MONEY_TYPES)
                ->where('status', 1)
                ->field('bid,type,sum(money - refund_money) as money')
                ->group('bid,type')
                ->select()->toArray();
            foreach ($rows as $r) {
                $moneyMap[(int) $r['bid']][$r['type']] = round((float) $r['money'], 2);
            }
        }

        foreach ($list as &$row) {
            $row['bid'] = (int) $row['bid'];
            if (0 === $row['bid']) {
                $row['name'] = '平台';
            }
            $totalMoney = 0.0;
            foreach (self::MONEY_TYPES as $type) {
                $v = $moneyMap[$row['bid']][$type] ?? 0.0;
                $row[$type . '_money'] = $v;
                $totalMoney += $v;
            }
            $row['total_money'] = round($totalMoney, 2);
        }
        unset($row);

        return $this->success([
            'list'       => $list,
            'total'      => $total,
            'sales_auth' => $this->getSalesAuth($aid),
            'types'      => self::MONEY_TYPES,
        ]);
    }

    /**
     * 删除销量记录（批量，参考系统 del_sales 按 bids 删除）
     * POST /admin/businessSales/delete  {bids:[]}
     */
    public function delete()
    {
        $aid  = ScopeService::aidOf($this->request);
        $uid  = (int) ($this->request->authUid ?? 0);
        $bids = array_map('intval', (array) $this->request->post('bids', []));

        if (empty($bids)) {
            return $this->error('请选择记录');
        }

        ScopeService::query('business_sales', $aid)->whereIn('bid', $bids)->delete();
        PlogService::write($aid, $uid, '删除商户销量' . implode(',', $bids));

        return $this->success([], '删除成功');
    }

    /**
     * 获取每个模块的权限（逐模块对照参考系统 getSalesAuth 1300-1376 行）
     *
     * 口径：auth_type=1 视为全部权限（all=true）；否则按 auth_data JSON 数组
     * 逐项匹配 12 个「控制器/动作」逗号串，命中才置对应模块 true
     */
    private function getSalesAuth(int $aid): array
    {
        $adminUser = Db::name('admin_user')
            ->where('aid', $aid)
            ->where('isadmin', '>', 0)
            ->field('auth_type,auth_data')
            ->find();

        $data = ['all' => true];
        if (empty($adminUser)) {
            return $data;
        }
        if ((int) $adminUser['auth_type'] === 1) {
            return $data;
        }

        $data['all'] = false;
        $adminAuth = json_decode((string) $adminUser['auth_data'], true);
        if (!is_array($adminAuth)) {
            $adminAuth = [];
        }
        foreach (self::SALES_AUTH_RULES as $key => $rule) {
            if (in_array($rule, $adminAuth, true)) {
                $data[$key] = true;
            }
        }

        return $data;
    }
}
