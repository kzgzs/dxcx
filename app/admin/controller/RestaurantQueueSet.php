<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\ScopeService;
use think\facade\Db;

/**
 * 租户后台 - 餐饮排队设置（restaurant_queue_sysset）
 *
 * 字段：screen_title 大屏标题/screen_pic 大屏背景/screen_desc 大屏底部文字/
 *       start_hours-end_hours 可取号时段/status 排队总开关
 */
class RestaurantQueueSet extends BaseController
{
    /**
     * 读取排队设置（无记录自动初始化）
     * GET /admin/restaurant/queue/set
     */
    public function index()
    {
        $aid  = ScopeService::aidOf($this->request);
        $info = $this->loadSet($aid);

        $info['id']          = (int) $info['id'];
        $info['status']      = (int) $info['status'];
        $info['start_hours'] = (string) ($info['start_hours'] ?: '00:00');
        $info['end_hours']   = (string) ($info['end_hours'] ?: '00:00');

        return $this->success($info);
    }

    /**
     * 保存排队设置
     * POST /admin/restaurant/queue/set
     */
    public function save()
    {
        $aid  = ScopeService::aidOf($this->request);
        $info = $this->loadSet($aid);
        $post = (array) $this->request->post();

        // 仅更新提交的字段（部分保存不覆盖未提交项）
        $data = [];

        if (array_key_exists('start_hours', $post) || array_key_exists('end_hours', $post)) {
            $startHours = trim((string) $this->request->post('start_hours', (string) ($info['start_hours'] ?: '00:00')));
            $endHours   = trim((string) $this->request->post('end_hours', (string) ($info['end_hours'] ?: '00:00')));
            foreach ([$startHours, $endHours] as $h) {
                if ('' !== $h && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $h)) {
                    throw new BusinessException('时段格式不正确，应为 HH:MM');
                }
            }
            $data['start_hours'] = '' === $startHours ? '00:00' : $startHours;
            $data['end_hours']   = '' === $endHours ? '00:00' : $endHours;
        }

        foreach (['screen_title', 'screen_pic', 'screen_desc'] as $field) {
            if (array_key_exists($field, $post)) {
                $data[$field] = trim((string) $post[$field]);
            }
        }

        if (array_key_exists('status', $post)) {
            $data['status'] = (int) $post['status'] ? 1 : 0;
        }

        if ($data) {
            Db::name('restaurant_queue_sysset')->where('id', (int) $info['id'])->update($data);
        }

        return $this->success([], '保存成功');
    }

    /**
     * 取当前租户排队设置行（不存在则按默认初始化）
     *
     * @return array<string, mixed>
     */
    protected function loadSet(int $aid): array
    {
        $info = ScopeService::query('restaurant_queue_sysset', $aid)->where('bid', 0)->find();
        if (empty($info)) {
            Db::name('restaurant_queue_sysset')->insert([
                'aid'         => $aid,
                'bid'         => 0,
                'status'      => 1,
                'start_hours' => '00:00',
                'end_hours'   => '00:00',
            ]);
            $info = ScopeService::query('restaurant_queue_sysset', $aid)->where('bid', 0)->find();
        }

        return (array) $info;
    }
}
