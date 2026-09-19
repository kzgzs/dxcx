<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\PlogService;
use app\common\service\ScopeService;
use think\facade\Db;

/**
 * 租户后台 - 餐饮设置（对照参考系统 Restaurant::sysset，表 restaurant_admin_set）
 *
 * 字段组：点餐评价/外卖设置/排队叫号接入/收银台支付方式
 */
class RestaurantSysset extends BaseController
{
    /** 允许保存的字段白名单（以本库实际列为准） */
    private const FIELDS = [
        'shop_comment', 'shop_comment_check', 'shop_showcommission',
        'takeaway_autoclose', 'takeaway_comment', 'takeaway_comment_check', 'takeaway_showcommission',
        'queue_appid', 'queue_secretid', 'queue_secretkey', 'queue_voicetype',
        'business_cashdesk_wxpay_type', 'business_cashdesk_sxpay_type', 'business_cashdesk_alipay_type',
        'business_cashdesk_yue', 'business_cashdesk_cashpay',
    ];

    /** 0/1 开关字段 */
    private const SWITCH_FIELDS = [
        'shop_comment', 'shop_comment_check', 'shop_showcommission',
        'takeaway_comment', 'takeaway_comment_check', 'takeaway_showcommission',
        'business_cashdesk_yue', 'business_cashdesk_cashpay',
    ];

    /** 文本字段 */
    private const TEXT_FIELDS = ['queue_appid', 'queue_secretid', 'queue_secretkey', 'queue_voicetype'];

    /**
     * 读取设置
     * GET /admin/restaurant/sysset/index
     */
    public function index()
    {
        $aid  = ScopeService::aidOf($this->request);
        $info = $this->loadSet($aid);

        foreach (self::FIELDS as $field) {
            if (!isset($info[$field])) {
                continue;
            }
            if (in_array($field, self::TEXT_FIELDS, true)) {
                $info[$field] = (string) $info[$field];
            } else {
                $info[$field] = (int) $info[$field];
            }
        }
        $info['id'] = (int) $info['id'];

        return $this->success(['info' => $info]);
    }

    /**
     * 保存设置
     * POST /admin/restaurant/sysset/save  { info }
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
            if (in_array($field, self::TEXT_FIELDS, true)) {
                $data[$field] = trim((string) $post[$field]);
            } elseif (in_array($field, self::SWITCH_FIELDS, true)) {
                $data[$field] = (int) $post[$field] === 1 ? 1 : 0;
            } elseif ('takeaway_autoclose' === $field) {
                $data[$field] = max(0, (int) $post[$field]);
            } else {
                $data[$field] = max(0, min(3, (int) $post[$field]));
            }
        }
        if (empty($data)) {
            return $this->success([], '保存成功');
        }

        $info = $this->loadSet($aid);
        Db::name('restaurant_admin_set')->where('id', $info['id'])->update($data);

        PlogService::write($aid, $uid, '餐饮系统设置保存', 0, (string) $this->request->ip());

        return $this->success([], '保存成功');
    }

    /** 读取（不存在则初始化） */
    private function loadSet(int $aid): array
    {
        $info = ScopeService::query('restaurant_admin_set', $aid)->find();
        if (empty($info)) {
            Db::name('restaurant_admin_set')->insert(['aid' => $aid]);
            $info = ScopeService::query('restaurant_admin_set', $aid)->find();
        }
        return $info;
    }
}
