<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\PlogService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 商品审核（多商户）
 *
 * 对标参考系统 ShopProduct：仅审核商户提交的商品（shop_product.bid>0、deleted=0）；
 * ischecked：0=待审核 1=已通过 2=已驳回；setcheckst 通过(1)/驳回(2，带 check_reason)。
 */
class GoodsAudit extends BaseController
{
    /** 列表排序字段白名单 */
    private const SORT_FIELDS = ['id', 'stock', 'sales', 'createtime'];

    /**
     * 待审核商品列表
     * GET /admin/goodsAudit/list?page=&pagesize=&goods_id=&name=&cid=&gid=&ischecked=&bid=&field=&order=
     */
    public function list()
    {
        $aid      = ScopeService::aidOf($this->request);
        $page     = max(1, (int) $this->request->get('page', 1));
        $pagesize = min(100, max(1, (int) $this->request->get('pagesize', 20)));
        $goodsId  = (int) $this->request->get('goods_id', 0);
        $name     = trim((string) $this->request->get('name', ''));
        $cid      = (int) $this->request->get('cid', 0);
        $gid      = (int) $this->request->get('gid', 0);
        $bid      = (int) $this->request->get('bid', 0);
        $ischecked = $this->request->get('ischecked', '');

        $query = ScopeService::queryAs('shop_product', $aid, 'p')
            ->leftJoin('business b', 'b.id = p.bid')
            ->field('p.id,p.bid,p.name,p.pic,p.sell_price,p.market_price,p.stock,p.sales,p.createtime,p.ischecked,p.check_reason,b.name as bname')
            ->where('p.bid', '>', 0)
            ->where('p.deleted', 0);

        if ($goodsId > 0) {
            $query->where('p.id', $goodsId);
        }
        if ('' !== $name) {
            $query->whereLike('p.name', '%' . $name . '%');
        }
        if ($cid > 0) {
            $query->whereRaw("FIND_IN_SET({$cid}, p.cid)");
        }
        if ($gid > 0) {
            $query->whereRaw("FIND_IN_SET({$gid}, p.gid)");
        }
        if ($bid > 0) {
            $query->where('p.bid', $bid);
        }
        if ('' !== $ischecked && $ischecked !== null && in_array((int) $ischecked, [0, 1, 2], true)) {
            $query->where('p.ischecked', (int) $ischecked);
        }

        // 排序（白名单，默认 id desc）
        $field = (string) $this->request->get('field', 'id');
        $order = strtolower((string) $this->request->get('order', 'desc')) === 'asc' ? 'asc' : 'desc';
        if (!in_array($field, self::SORT_FIELDS, true)) {
            $field = 'id';
        }

        $total = (clone $query)->count();
        $rows  = $query->order('p.' . $field, $order)->page($page, $pagesize)->select()->toArray();

        $list = array_map(static function (array $r): array {
            return [
                'id'           => (int) $r['id'],
                'bid'          => (int) $r['bid'],
                'bname'        => (string) ($r['bname'] ?? ''),
                'name'         => (string) ($r['name'] ?? ''),
                'pic'          => (string) ($r['pic'] ?? ''),
                'sell_price'   => (float) ($r['sell_price'] ?? 0),
                'market_price' => (float) ($r['market_price'] ?? 0),
                'stock'        => (int) ($r['stock'] ?? 0),
                'sales'        => (int) ($r['sales'] ?? 0),
                'realsalenum'  => (int) ($r['sales'] ?? 0),
                'ischecked'    => (int) ($r['ischecked'] ?? 0),
                'check_reason' => (string) ($r['check_reason'] ?? ''),
                'createtime'   => (int) ($r['createtime'] ?? 0),
            ];
        }, $rows);

        return $this->success(['list' => $list, 'total' => $total]);
    }

    /**
     * 审核：通过 / 驳回
     * POST /admin/goodsAudit/setcheckst {id, st:1通过|2驳回, reason?}
     */
    public function setcheckst()
    {
        $aid = ScopeService::aidOf($this->request);
        $uid = (int) ($this->request->authUid ?? 0);
        $id  = (int) $this->request->post('id', 0);
        $st  = (int) $this->request->post('st', 0);
        $reason = trim((string) $this->request->post('reason', ''));

        if ($id <= 0) {
            return $this->error('请选择商品');
        }
        if (!in_array($st, [1, 2], true)) {
            return $this->error('审核状态参数错误');
        }
        $product = ScopeService::query('shop_product', $aid)->where('id', $id)->find();
        if (empty($product)) {
            return $this->error('无可操作的商品');
        }
        if ($st === 2 && '' === $reason) {
            return $this->error('请填写驳回原因');
        }

        ScopeService::query('shop_product', $aid)->where('id', $id)->update([
            'ischecked'    => $st,
            'check_reason' => $st === 1 ? '' : $reason,
        ]);
        PlogService::write($aid, $uid, '商城商品审核' . ($st === 1 ? '通过' : '驳回') . $id);

        return $this->success([], $st === 1 ? '已审核通过' : '已驳回');
    }
}
