<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\OrderService;
use app\common\service\ScopeService;
use think\facade\Db;

/**
 * 租户后台 - 录入订单（代客下单，对标参考系统 ShopOrderlr）
 *
 * 流程：选会员（可空=游客）→ 添加商品（规格库存校验+等级价）→ 收货信息
 * → 配送方式（freight/mendian 联动）→ 运费/支付方式/支付时间（可补录历史）
 * → save 写 shop_order(platform=admin, status=1 已付款) + shop_order_goods
 */
class ShopOrderlr extends BaseController
{
    public function __construct(\think\App $app, protected OrderService $orderService)
    {
        parent::__construct($app);
    }

    /**
     * 会员查询（录入订单选会员：支持会员ID/手机号/昵称）
     * GET /admin/shopOrderlr/getUser?keyword= 或 ?mid=
     */
    public function getUser()
    {
        $aid = ScopeService::aidOf($this->request);

        $mid = (int) $this->request->get('mid', 0);

        if ($mid > 0) {
            $member = ScopeService::query('member', $aid)->where('id', $mid)->find();

            if (empty($member)) {
                return $this->success(['member' => null]);
            }

            return $this->success(['member' => $this->formatMember($aid, $member)]);
        }

        $keyword = trim((string) $this->request->get('keyword', ''));

        if ('' === $keyword) {
            return $this->success(['list' => []]);
        }

        $query = ScopeService::query('member', $aid);

        if (ctype_digit($keyword)) {
            $query->where(function ($q) use ($keyword) {
                $q->where('id', (int) $keyword)
                    ->whereOr('tel', 'like', '%' . $keyword . '%');
            });
        } else {
            $query->where('nickname', 'like', '%' . $keyword . '%');
        }

        $rows = $query->order('id', 'desc')->limit(20)->select()->toArray();

        return $this->success(['list' => array_map(fn ($m) => $this->formatMember($aid, $m), $rows)]);
    }

    /**
     * 会员收货地址列表（录入订单选择已有地址）
     * GET /admin/shopOrderlr/addressList?mid=
     */
    public function addressList()
    {
        $aid = ScopeService::aidOf($this->request);
        $mid = (int) $this->request->get('mid', 0);

        if ($mid <= 0) {
            return $this->success(['list' => []]);
        }

        $rows = ScopeService::query('member_address', $aid)
            ->where('mid', (string) $mid)
            ->where('deleted', 0)
            ->order('isdefault', 'desc')
            ->order('id', 'desc')
            ->select()
            ->toArray();

        return $this->success(['list' => $rows]);
    }

    /**
     * 商品候选列表（录入订单添加商品：名称/编码模糊搜索）
     * GET /admin/shopOrderlr/goodsOptions?keyword=&page&pagesize
     */
    public function goodsOptions()
    {
        $aid = ScopeService::aidOf($this->request);

        $keyword  = trim((string) $this->request->get('keyword', ''));
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = max(1, min(50, (int) $this->request->get('pagesize', 20)));

        $query = ScopeService::query('shop_product', $aid)
            ->where('status', 1)
            ->where('deleted', 0);

        if ('' !== $keyword) {
            $query->where(function ($q) use ($keyword) {
                $q->where('name', 'like', '%' . $keyword . '%')
                    ->whereOr('procode', 'like', '%' . $keyword . '%');
            });
        }

        $total = (clone $query)->count();

        $rows = $query->field('id,name,pic,sell_price,stock,lvprice,freighttype,givescore_time')
            ->order('id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        return $this->success(['list' => $rows, 'total' => $total]);
    }

    /**
     * 商品规格行（录入订单选规格：含库存与等级价数据）
     * GET /admin/shopOrderlr/guigeOptions?proid=
     */
    public function guigeOptions()
    {
        $aid   = ScopeService::aidOf($this->request);
        $proid = (int) $this->request->get('proid', 0);

        if ($proid <= 0) {
            throw new BusinessException('缺少商品ID');
        }

        $rows = Db::name('shop_guige')
            ->where('aid', $aid)
            ->where('proid', $proid)
            ->field('id,name,pic,sell_price,cost_price,stock,lvprice_data')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        foreach ($rows as &$row) {
            // 等级价 JSON 解包给前端按会员等级取价
            $lv = json_decode((string) ($row['lvprice_data'] ?? ''), true);
            $row['lvprice_data'] = is_array($lv) ? $lv : [];
        }
        unset($row);

        return $this->success(['list' => $rows]);
    }

    /**
     * 保存录入订单
     * POST /admin/shopOrderlr/save
     * body: {mid, items:[{proid,ggid,num}], linkman,tel,province,city,district,address,
     *        freight_id, freightprice, mdid, paytype, paytypeid, paytime}
     */
    public function save()
    {
        $aid = ScopeService::aidOf($this->request);

        $payload = $this->request->post();
        $payload = is_array($payload) ? $payload : [];

        // 操作员账号名（admin_user.un）
        $uid = (int) (($this->request->authPayload ?? [])['uid'] ?? 0);
        $operatorName = $uid > 0
            ? (string) (ScopeService::query('admin_user', $aid)->where('id', $uid)->value('un') ?? '')
            : '';

        $result = $this->orderService->createAdminOrder($aid, $operatorName, $payload);

        return $this->success($result, '录单成功');
    }

    /**
     * 会员信息格式化（录入订单展示用）
     *
     * @param array<string, mixed> $member
     * @return array<string, mixed>
     */
    protected function formatMember(int $aid, array $member): array
    {
        $level = Db::name('member_level')
            ->where('aid', $aid)
            ->where('id', (int) ($member['levelid'] ?? 0))
            ->find();

        return [
            'id'        => (int) $member['id'],
            'nickname'  => (string) ($member['nickname'] ?? ''),
            'headimg'   => (string) ($member['headimg'] ?? ''),
            'tel'       => (string) ($member['tel'] ?? ''),
            'money'     => round((float) ($member['money'] ?? 0), 2),
            'score'     => (int) ($member['score'] ?? 0),
            'levelid'   => (int) ($member['levelid'] ?? 0),
            'levelname' => null !== $level ? (string) ($level['name'] ?? '') : '',
        ];
    }
}
