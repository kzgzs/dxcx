<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\ScopeService;
use think\facade\Db;

/**
 * 租户后台 - 餐饮堂食设置（restaurant_shop_sysset，每租户一行，bid=0）
 *
 * 字段：banner/tea_fee_status/tea_fee_text/tea_fee/start_hours/end_hours 营业时间（支持跨天）/
 *       paytype 逗号串/pay_after 餐后付款/clean_table_status/autoshdays/diancan_text 等
 */
class RestaurantShopSet extends BaseController
{
    private const DEFAULTS = [
        'banner'                         => '',
        'banner_show'                    => 1,
        'business_info_show'             => 1,
        'comment_show'                   => 1,
        'open_restaurant_detail_status'  => 1,
        'tea_fee_status'                 => 0,
        'tea_fee_text'                   => '茶位费',
        'tea_fee'                        => '0.00',
        'start_hours'                    => '00:00',
        'end_hours'                      => '00:00',
        'paytype'                        => 'weixin,alipay,money,cash',
        'pay_after'                      => 1,
        'clean_table_status'             => 0,
        'status'                         => 1,
        'autoshdays'                     => 1,
        'diancan_text'                   => '点餐',
    ];

    /**
     * 读取堂食设置
     * GET /admin/dining/shopset
     */
    public function index()
    {
        $aid = ScopeService::aidOf($this->request);

        $row = ScopeService::query('restaurant_shop_sysset', $aid)->order('id', 'asc')->find();

        $data = self::DEFAULTS;
        if (!empty($row)) {
            foreach (self::DEFAULTS as $k => $v) {
                if (isset($row[$k]) && $row[$k] !== null && '' !== (string) $row[$k]) {
                    $data[$k] = $row[$k];
                }
            }
        }

        // 类型归一
        foreach (['banner_show', 'business_info_show', 'comment_show', 'open_restaurant_detail_status',
            'tea_fee_status', 'pay_after', 'clean_table_status', 'status'] as $k) {
            $data[$k] = (int) $data[$k] === 1 ? 1 : 0;
        }
        $data['tea_fee']    = (float) $data['tea_fee'];
        $data['autoshdays'] = max(1, (int) $data['autoshdays']);
        $data['paytypes']   = array_values(array_filter(explode(',', (string) $data['paytype'])));

        // 营业时间是否跨天（供前端提示）
        $data['cross_day'] = strcmp((string) $data['start_hours'], (string) $data['end_hours']) >= 0 ? 1 : 0;

        return $this->success($data);
    }

    /**
     * 保存堂食设置（不存在则插入，存在则更新）
     * POST /admin/dining/shopset
     */
    public function save()
    {
        $aid = ScopeService::aidOf($this->request);

        $startHours = $this->normalizeHour((string) $this->request->post('start_hours', '00:00'));
        $endHours   = $this->normalizeHour((string) $this->request->post('end_hours', '00:00'));

        $teaFee = (float) $this->request->post('tea_fee', 0);
        if ($teaFee < 0) {
            throw new BusinessException('茶位费不能为负数');
        }

        $autoshdays = (int) $this->request->post('autoshdays', 1);
        if ($autoshdays < 1 || $autoshdays > 365) {
            throw new BusinessException('自动收货天数需在 1-365 之间');
        }

        // 支付方式（数组或逗号串）
        $paytypes = $this->request->post('paytypes', null);
        if (null === $paytypes) {
            $paytypes = (string) $this->request->post('paytype', self::DEFAULTS['paytype']);
        }
        if (is_array($paytypes)) {
            $paytypes = implode(',', array_filter(array_map('strval', $paytypes)));
        }
        $paytypes = implode(',', array_filter(array_map('trim', explode(',', (string) $paytypes))));
        if ('' === $paytypes) {
            throw new BusinessException('请至少选择一种支付方式');
        }

        $data = [
            'banner'                        => trim((string) $this->request->post('banner', '')),
            'banner_show'                   => (int) $this->request->post('banner_show', 1) === 1 ? 1 : 0,
            'business_info_show'            => (int) $this->request->post('business_info_show', 1) === 1 ? 1 : 0,
            'comment_show'                  => (int) $this->request->post('comment_show', 1) === 1 ? 1 : 0,
            'open_restaurant_detail_status' => (int) $this->request->post('open_restaurant_detail_status', 1) === 1 ? 1 : 0,
            'tea_fee_status'                => (int) $this->request->post('tea_fee_status', 0) === 1 ? 1 : 0,
            'tea_fee_text'                  => trim((string) $this->request->post('tea_fee_text', '茶位费')),
            'tea_fee'                       => number_format($teaFee, 2, '.', ''),
            'start_hours'                   => $startHours,
            'end_hours'                     => $endHours,
            'paytype'                       => $paytypes,
            'pay_after'                     => (int) $this->request->post('pay_after', 1) === 1 ? 1 : 0,
            'clean_table_status'            => (int) $this->request->post('clean_table_status', 0) === 1 ? 1 : 0,
            'status'                        => (int) $this->request->post('status', 1) === 1 ? 1 : 0,
            'autoshdays'                    => $autoshdays,
            'diancan_text'                  => trim((string) $this->request->post('diancan_text', '点餐')),
        ];

        // pay_after=1（餐后付款）时清台开关无意义，强制关闭，避免配置矛盾
        if (1 === $data['pay_after']) {
            $data['clean_table_status'] = 0;
        }

        $row = ScopeService::query('restaurant_shop_sysset', $aid)->order('id', 'asc')->find();
        if (!empty($row)) {
            Db::name('restaurant_shop_sysset')->where('id', $row['id'])->update($data);
        } else {
            $data['aid'] = $aid;
            $data['bid'] = 0;
            Db::name('restaurant_shop_sysset')->insertGetId($data);
        }

        return $this->success([], '保存成功');
    }

    /**
     * 校验并归一 HH:MM 格式
     */
    private function normalizeHour(string $text): string
    {
        $text = trim($text);
        if (!preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $text)) {
            throw new BusinessException('营业时间格式不正确（应为 HH:MM）');
        }
        [$h, $m] = explode(':', $text);
        return sprintf('%02d:%02d', (int) $h, (int) $m);
    }
}
