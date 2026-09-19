<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\ScopeService;
use app\common\service\TenantConfigService;

/**
 * 租户后台 - 活动日历配置（P24-7 批次8 任务227）
 *
 * canzhao 侧仅菜单+客户端页面（uniapp pagesD/calendar），无后台控制器；
 * 本系统后台只做配置承载：tenant_config 键值表（前缀 huodong_calendar_），不建表。
 */
class HuodongCalendar extends BaseController
{
    /** 配置键前缀 */
    private const PREFIX = 'huodong_calendar_';

    /** 配置字段 => 默认值 */
    private const FIELDS = [
        'status'  => '0',   // 启用开关 0关闭 1开启
        'title'   => '活动日历', // 页面标题
        'bgimg'   => '',    // 顶部背景图
        'notice'  => '',    // 顶部公告
        'desc'    => '',    // 底部说明
    ];

    /**
     * 获取配置
     * GET /admin/huodongCalendar/get
     */
    public function get()
    {
        $aid = ScopeService::aidOf($this->request);

        $data = [];

        foreach (self::FIELDS as $key => $default) {
            $value = TenantConfigService::get($aid, self::PREFIX . $key, $default);

            $data[$key] = 'status' === $key ? (int) $value : $value;
        }

        return $this->success($data);
    }

    /**
     * 保存配置
     * POST /admin/huodongCalendar/save {status, title, bgimg, notice, desc}
     */
    public function save()
    {
        $aid  = ScopeService::aidOf($this->request);
        $post = $this->request->post();

        foreach (self::FIELDS as $key => $default) {
            $value = (string) ($post[$key] ?? $default);

            if ('status' === $key) {
                $value = ((int) $value === 1) ? '1' : '0';
            } else {
                $value = trim($value);
            }

            // tenant_config value 列为 varchar(255)，超长截断保护
            $value = mb_substr($value, 0, 255);

            TenantConfigService::set($aid, self::PREFIX . $key, $value);
        }

        return $this->success([], '保存成功');
    }
}
