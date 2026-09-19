<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\PlogService;
use app\common\service\ScopeService;
use think\facade\Db;

/**
 * 租户后台 - 外卖设置（对照参考系统 RestaurantTakeawaySet）
 *
 * 表 restaurant_takeaway_sysset（bid=0）。
 * 广告设置字段（banner/banner_show）并入本页同一字段组，不单独开页。
 */
class RestaurantTakeawaySet extends BaseController
{
    /** 允许保存的字段白名单 */
    private const FIELDS = [
        'banner', 'banner_show', 'pack_fee', 'min_price', 'start_hours', 'end_hours',
        'confirm_auto', 'status', 'autoshdays', 'business_info_show', 'comment_show',
        'open_restaurant_detail_status', 'takeaway_show', 'takeaway_name',
        'business_info_name', 'comment_name', 'alone_hexiao_status', 'auto_send_order',
    ];

    /** 开关类字段（0/1） */
    private const SWITCH_FIELDS = [
        'banner_show', 'confirm_auto', 'status', 'business_info_show', 'comment_show',
        'open_restaurant_detail_status', 'takeaway_show', 'alone_hexiao_status', 'auto_send_order',
    ];

    /**
     * 读取外卖设置
     * GET /admin/restaurant/takeawaySet/index
     */
    public function index()
    {
        $aid  = ScopeService::aidOf($this->request);
        $info = $this->loadSet($aid);

        foreach ($info as $k => $v) {
            if (in_array($k, self::SWITCH_FIELDS, true)) {
                $info[$k] = (int) $v;
            }
        }
        $info['id']         = (int) $info['id'];
        $info['pack_fee']   = round((float) $info['pack_fee'], 2);
        $info['min_price']  = round((float) $info['min_price'], 2);
        $info['autoshdays'] = (int) $info['autoshdays'];

        return $this->success(['info' => $info]);
    }

    /**
     * 保存外卖设置（含广告设置字段组）
     * POST /admin/restaurant/takeawaySet/save  { info }
     */
    public function save()
    {
        $aid  = ScopeService::aidOf($this->request);
        $uid  = (int) ($this->request->authUid ?? 0);
        $post = $this->request->post('info/a', []);

        $data = [];
        foreach (self::FIELDS as $field) {
            if (!array_key_exists($field, $post)) {
                continue;
            }
            $val = $post[$field];
            if (in_array($field, self::SWITCH_FIELDS, true)) {
                $data[$field] = (int) $val === 1 ? 1 : 0;
            } elseif (in_array($field, ['pack_fee', 'min_price'], true)) {
                $data[$field] = round((float) $val, 2);
            } elseif ('autoshdays' === $field) {
                $data[$field] = max(0, (int) $val);
            } else {
                $data[$field] = (string) $val;
            }
        }
        if (empty($data)) {
            return $this->success([], '保存成功');
        }

        $info = $this->loadSet($aid);
        Db::name('restaurant_takeaway_sysset')->where('id', $info['id'])->update($data);

        PlogService::write($aid, $uid, '餐饮外卖设置保存', 0, (string) $this->request->ip());

        return $this->success([], '保存成功');
    }

    /** 读取（不存在则初始化） */
    private function loadSet(int $aid): array
    {
        $info = ScopeService::query('restaurant_takeaway_sysset', $aid)->where('bid', 0)->find();
        if (empty($info)) {
            Db::name('restaurant_takeaway_sysset')->insert(['aid' => $aid, 'bid' => 0]);
            $info = ScopeService::query('restaurant_takeaway_sysset', $aid)->where('bid', 0)->find();
        }
        return $info;
    }
}
