<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\PlogService;
use app\common\service\ScopeService;
use think\facade\Db;

/**
 * 租户后台 - 商城设置（shop_sysset 单行大表单）
 *
 * 对照参考系统 ShopSet 控制器 + shop_set/index.html 全字段清单（153 项）：
 * GET 读取（行不存在返回默认值） / POST 保存（白名单过滤，不存在则插入）
 * 仅平台可用（同参考系统 if(bid>0) showmsg）
 * 前端按 tab 分组提交，后端按白名单整体落库，保存回读一致
 */
class ShopSet extends BaseController
{
    /** 整型字段（开关/数量/天数） */
    private const INT_FIELDS = [
        'autoshdays', 'autoclose', 'comment', 'comment_check', 'showjd', 'cancod', 'gwctj',
        'showcommission', 'needzb', 'pay_month', 'hide_sales', 'hide_stock', 'classify_show_stock',
        'canrefund', 'refundpic', 'show_lvupsavemoney', 'coupon_peruselimit', 'price_show_type',
        'return_coupon', 'ordercollect_time', 'scorein_score', 'freightfree', 'cod_precondition',
        'cod_onlinepay', 'is_show_price_unlogin', 'is_show_price_uncheck', 'shop_stock_warning_num',
        'shd_style', 'cost_bili', 'show_shd_remark', 'share_cart', 'share_payment',
        'transfer_order_parent_check', 'transfer_order_parent_check_object', 'product_shop_school',
        'product_thali_school', 'product_nostock_show', 'product_deposit_mode',
        'product_deposit_mode_withdrawalday', 'product_lvxin_replace_remind',
        'product_lvxin_expireday_remind', 'product_lvxin_remind_type', 'giveorder',
        'giveorder_validtime', 'order_form_auto_input_set', 'showgivescore',
        'product_month_createorder_switch', 'split_order', 'buy_message_prompt_status',
        'exchange_page', 'changeprice_status', 'product_show_price_fanwei',
        'product_show_costprice_fanwei', 'product_show_marketprice', 'sign_contract',
        'autoplay_video', 'order_admin_refund_switch', 'order_admin_payorder_switch', 'presell',
        'givescore_fanwei', 'return_component_open', 'exchange_product', 'exchange_product_day',
        'bonus_pool_tuijian_num', 'bonus_pool_cx_days', 'bonus_pool_noreleasetj',
        'bonus_pool_total_mcount', 'coupon_not_used_discount', 'return_sendcoupon',
        'use_ck_coupon_status', 'multi_use_ck_coupon_status', 'product_comment',
        'product_comment_check', 'hide_sellprice', 'hide_cost', 'show_guigetype', 'gwc_showst',
        'show_product_name', 'show_header_pic', 'show_guige', 'show_option_group',
        'prodetailtitle_type', 'show_purchase_order', 'show_promotion_tag',
        'show_promotion_rate_tag', 'autoreturn_hwtime', 'hand_fee_to_score',
        'shopbuy_mendian_showtype', 'cuxiao_default', 'shipping_pagenum', 'shipping_linenum',
        'goods_snapshot', 'snapshot_days', 'level_discount',
    ];

    /** 金额字段（decimal 两位小数） */
    private const DECIMAL_FIELDS = [
        'cod_frontpercent', 'scorein_money', 'score2money', 'freightfreemoney',
        'storemoney_warning_num', 'usdrate', 'order_min_price', 'totalprice_limit',
        'bonus_pool_money_max', 'autoreturn_hwmoney',
    ];

    /** 逗号串字段（前端数组 <-> 后端逗号串） */
    private const LIST_FIELDS = [
        'cod_levelids', 'costprice_levelids', 'list_nocart_platform', 'list_sort_show',
    ];

    /** 长文本字段（富文本/协议，不 trim 长度不截断） */
    private const TEXT_FIELDS = [
        'sign_contract_content', 'hwcontent', 'buy_message_prompt_content', 'tj_productids',
    ];

    /** 普通字符串字段 */
    private const STRING_FIELDS = [
        'name', 'logo', 'codtxt', 'pay_month_txt', 'show_price_unlogin_txt',
        'show_price_uncheck_txt', 'shd_style1_no', 'createorder_toppic', 'createorder_toppic_tourl',
        'order_detail_toppic', 'fastbuy_toppic', 'to86yk_user', 'to86yk_pwd',
        'exchange_page_bgpic', 'exchange_page_tourl', 'sign_contract_name',
        'sign_contract_template', 'commission_desc', 'sellprice_name', 'sellprice_color',
        'cost_name', 'cost_color', 'show_lvup_customize_name', 'show_lvup_customize_url',
        'guige_name', 'gwc_name', 'detail_guangao1', 'detail_guangao1_t', 'detail_guangao2',
        'detail_guangao2_t', 'prodetailtitle_value', 'pingce_url', 'pingce_test_url',
        'pingceinviteCode', 'hwname', 'show_business_customize_name', 'show_button_customize_name',
        'show_button_customize_url', 'return_name', 'return_tel', 'return_province', 'return_city',
        'return_area', 'return_address', 'shipping_pagetitle', 'order_detail_toppic',
        'receiving_address_name', 'receiving_address_tel', 'receiving_address_province',
        'receiving_address_city', 'receiving_address_area', 'receiving_address_address',
    ];

    /** 读取时的默认值（行不存在或字段为空时兜底，同参考系统表默认） */
    private const DEFAULTS = [
        'autoshdays' => 7, 'autoclose' => 60, 'comment' => 1, 'comment_check' => 1,
        'showjd' => 1, 'cancod' => 0, 'gwctj' => 1, 'showcommission' => 0, 'needzb' => 0,
        'codtxt' => '货到付款', 'cod_frontpercent' => '0.00', 'pay_month' => 0,
        'pay_month_txt' => '月结账户', 'hide_sales' => 0, 'hide_stock' => 0,
        'classify_show_stock' => 0, 'canrefund' => 1, 'refundpic' => 0,
        'show_lvupsavemoney' => 0, 'shipping_pagetitle' => '送货单', 'coupon_peruselimit' => 1,
        'price_show_type' => 0, 'return_coupon' => 1, 'shipping_pagenum' => 18,
        'shipping_linenum' => 2, 'is_show_price_unlogin' => 1, 'is_show_price_uncheck' => 1,
        'product_nostock_show' => 1, 'product_show_marketprice' => 1, 'order_admin_refund_switch' => 1,
        'order_admin_payorder_switch' => 1, 'product_comment_check' => 1, 'gwc_showst' => 1,
        'show_product_name' => 1, 'show_header_pic' => 1, 'show_guige' => 1, 'show_option_group' => 1,
        'showgivescore' => 1, 'guige_name' => '规格',
        'goods_snapshot' => 1, 'snapshot_days' => 180, 'level_discount' => 1,
    ];

    /**
     * 读取商城设置（全字段 + 会员等级列表供货到付款/成本价等级多选）
     * GET /admin/shopSet/config
     */
    public function config()
    {
        $aid = $this->aid();
        $this->platformOnly();

        $row = ScopeService::query('shop_sysset', $aid)->order('id', 'asc')->find();

        $fields = $this->allFields();
        $data   = [];
        foreach ($fields as $f) {
            $data[$f] = $row[$f] ?? (self::DEFAULTS[$f] ?? '');
        }

        // 类型归一
        foreach (self::INT_FIELDS as $f) {
            $data[$f] = (int) ($data[$f] ?: 0);
        }
        foreach (self::DECIMAL_FIELDS as $f) {
            $data[$f] = number_format((float) ($data[$f] ?: 0), 2, '.', '');
        }
        foreach (self::LIST_FIELDS as $f) {
            $data[$f] = array_values(array_filter(explode(',', (string) ($data[$f] ?? '')), static fn ($v) => $v !== ''));
        }
        foreach (self::STRING_FIELDS as $f) {
            $data[$f] = (string) ($data[$f] ?? '');
        }
        foreach (self::TEXT_FIELDS as $f) {
            $data[$f] = (string) ($data[$f] ?? '');
        }

        // 会员等级（cod_levelids / costprice_levelids 多选用）
        $levellist = Db::name('member_level')
            ->where('aid', $aid)
            ->field('id, name, can_agent')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        return $this->success([
            'info'      => $data,
            'levellist' => $levellist,
        ]);
    }

    /**
     * 保存商城设置（白名单过滤，整行更新/插入）
     * POST /admin/shopSet/save body:{info:{...}} 或 body:{...}
     */
    public function save()
    {
        $aid = $this->aid();
        $this->platformOnly();

        $body = $this->body();
        $info = isset($body['info']) && is_array($body['info']) ? $body['info'] : $body;

        $data = [];
        foreach (self::INT_FIELDS as $f) {
            if (array_key_exists($f, $info)) {
                $data[$f] = (int) $info[$f];
            }
        }
        foreach (self::DECIMAL_FIELDS as $f) {
            if (array_key_exists($f, $info)) {
                $data[$f] = number_format((float) $info[$f], 2, '.', '');
            }
        }
        foreach (self::LIST_FIELDS as $f) {
            if (array_key_exists($f, $info)) {
                $v = $info[$f];
                if (is_array($v)) {
                    $v = implode(',', array_filter(array_map('strval', $v), static fn ($x) => $x !== ''));
                }
                $data[$f] = (string) $v;
            }
        }
        foreach (self::STRING_FIELDS as $f) {
            if (array_key_exists($f, $info)) {
                $data[$f] = trim((string) $info[$f]);
            }
        }
        foreach (self::TEXT_FIELDS as $f) {
            if (array_key_exists($f, $info)) {
                $data[$f] = (string) $info[$f];
            }
        }

        if (empty($data)) {
            return $this->error('没有可保存的数据');
        }

        // 基本校验（同参考系统 lay-verify=required）
        if (array_key_exists('autoshdays', $data) && $data['autoshdays'] < 1) {
            return $this->error('自动收货天数最小值为1');
        }
        if (array_key_exists('autoclose', $data) && $data['autoclose'] < 0) {
            return $this->error('自动关闭订单分钟数不能为负数');
        }
        if (array_key_exists('snapshot_days', $data) && $data['snapshot_days'] < 0) {
            return $this->error('快照删除天数不能为负数');
        }

        $row = ScopeService::query('shop_sysset', $aid)->order('id', 'asc')->find();
        if (!empty($row)) {
            Db::name('shop_sysset')->where('id', $row['id'])->update($data);
        } else {
            $data['aid'] = $aid;
            Db::name('shop_sysset')->insertGetId($data);
        }

        PlogService::write($aid, $this->uid(), '商城系统设置', $this->bid());

        return $this->success([], '保存成功');
    }

    /** 全部字段清单（白名单 = 四类字段合集） */
    private function allFields(): array
    {
        return array_values(array_unique(array_merge(
            self::INT_FIELDS,
            self::DECIMAL_FIELDS,
            self::LIST_FIELDS,
            self::STRING_FIELDS,
            self::TEXT_FIELDS
        )));
    }

    // ---------- 工具 ----------

    protected function aid(): int
    {
        return ScopeService::aidOf($this->request);
    }

    protected function uid(): int
    {
        return (int) ($this->request->authUid ?? 0);
    }

    protected function bid(): int
    {
        static $bid = null;
        if ($bid === null) {
            $bid = (int) Db::name('admin_user')->where('id', $this->uid())->value('bid');
        }
        return $bid;
    }

    protected function platformOnly(): void
    {
        if ($this->bid() > 0) {
            throw new \app\common\exception\BusinessException('无访问权限', 403, 403);
        }
    }

    protected function body(): array
    {
        $data = $this->request->post();
        return is_array($data) ? $data : [];
    }
}
