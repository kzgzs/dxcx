<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\common\service\PlogService;
use app\common\service\ScopeService;
use app\common\exception\BusinessException;
use think\facade\Db;

/**
 * 租户后台 - 点餐评价管理（对照参考系统 RestaurantShopComment）
 *
 * 复用外卖评价基类逻辑，仅换表与操作日志文案
 */
class RestaurantShopComment extends RestaurantTakeawayComment
{
    protected string $table = 'restaurant_shop_comment';

    public function setst()
    {
        $aid = ScopeService::aidOf($this->request);
        $uid = (int) ($this->request->authUid ?? 0);

        $ids = $this->request->post('ids/a', []);
        $st  = (int) $this->request->post('st', 1);
        if (empty($ids)) {
            throw new BusinessException('参数错误');
        }
        $ids = array_map('intval', $ids);

        $list = ScopeService::query($this->table, $aid)
            ->where('bid', 0)->whereIn('id', $ids)
            ->select()->toArray();

        foreach ($list as $v) {
            Db::name($this->table)->where('id', $v['id'])->update(['status' => $st]);
            $this->recalcProduct((int) $aid, (int) $v['proid']);
        }

        PlogService::write($aid, $uid, '餐饮点餐评价审核' . implode(',', $ids), 0, (string) $this->request->ip());

        return $this->success([], '操作成功');
    }

    public function reply()
    {
        $aid = ScopeService::aidOf($this->request);
        $uid = (int) ($this->request->authUid ?? 0);

        $id      = (int) $this->request->post('id', 0);
        $content = trim((string) $this->request->post('content', ''));
        if ($id <= 0 || '' === $content) {
            throw new BusinessException('参数错误');
        }

        $row = ScopeService::query($this->table, $aid)->where('bid', 0)->where('id', $id)->find();
        if (empty($row)) {
            throw new BusinessException('评价不存在');
        }

        Db::name($this->table)->where('id', $id)->update(['reply_content' => $content, 'reply_time' => time()]);

        PlogService::write($aid, $uid, '餐饮点餐评价回复' . $id, 0, (string) $this->request->ip());

        return $this->success([], '回复成功');
    }

    public function del()
    {
        $aid = ScopeService::aidOf($this->request);
        $uid = (int) ($this->request->authUid ?? 0);

        $ids = $this->request->post('ids/a', []);
        if (empty($ids)) {
            throw new BusinessException('参数错误');
        }
        $ids = array_map('intval', $ids);

        $list = ScopeService::query($this->table, $aid)->where('bid', 0)->whereIn('id', $ids)->select()->toArray();
        if (empty($list)) {
            throw new BusinessException('评价不存在');
        }

        Db::name($this->table)->whereIn('id', $ids)->delete();

        foreach ($list as $v) {
            $this->recalcProduct((int) $aid, (int) $v['proid']);
        }

        PlogService::write($aid, $uid, '餐饮点餐评价删除' . implode(',', $ids), 0, (string) $this->request->ip());

        return $this->success([], '删除成功');
    }
}
