<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use think\facade\Db;
use think\facade\Request;

/**
 * 租户后台 - 通知公告
 */
class Notice extends BaseController
{
    /**
     * 获取当前用户的公告列表及未读数
     * GET /admin/notice/mine
     */
    public function mine()
    {
        $aid = $this->request->aid ?? 0;
        $uid = (int) ($this->request->authUid ?? 0);

        if ($uid <= 0) {
            return $this->error('用户 ID 错误');
        }

        // 查询未读数（仅统计超管发布的公告 type=1）
        $unreadCount = Db::name('admin_notice')
            ->where('type', 1)
            ->where('uid', $uid)
            ->where('isread', 0)
            ->count();

    // 查询公告列表（仅当前登录用户自己的公告行，避免同一公告多接收用户行重复显示）
        $list = Db::name('admin_notice')
            ->where('type', 1)
            ->where('uid', $uid)
            ->order('createtime', 'desc')
            ->limit(50)
            ->select()
            ->toArray();

        foreach ($list as &$item) {
            $item['title'] = $item['title'] ?? '';
            $item['time'] = date('Y-m-d H:i:s', $item['createtime'] ?? time());
            $item['is_unread'] = (int) ($item['isread'] ?? 0) === 0 ? true : false;
            unset($item['createtime'], $item['isread']);
        }

        return $this->success([
            'unread_count' => (int) $unreadCount,
            'list' => $list,
        ]);
    }

    /**
     * 获取公告详情
     * GET /admin/notice/detail?id=
     */
    public function detail()
    {
        $id = (int) Request::get('id', 0);
        
        if ($id <= 0) {
            return $this->error('参数错误');
        }

        $aid = $this->request->aid ?? 0;
        $uid = (int) ($this->request->authUid ?? 0);

        if ($uid <= 0) {
            return $this->error('用户 ID 错误');
        }

        $info = Db::name('admin_notice')
            ->where('id', $id)
            ->where('aid', $aid)
            ->where('uid', $uid)
            ->find();

        if (empty($info)) {
            return $this->error('公告不存在');
        }

        $res = $info->toArray();
        $res['time'] = date('Y-m-d H:i:s', $res['createtime'] ?? time());

        return $this->success($res);
    }

    /**
     * 标记公告为已读
     * POST /admin/notice/read
     */
    public function read()
    {
        $id = (int) Request::post('id', 0);
        
        if ($id <= 0) {
            return $this->error('参数错误');
        }

        $aid = $this->request->aid ?? 0;
        $uid = (int) ($this->request->authUid ?? 0);

        if ($uid <= 0) {
            return $this->error('用户 ID 错误');
        }

        // 检查是否已读
        $notice = Db::name('admin_notice')
            ->where('id', $id)
            ->where('aid', $aid)
            ->where('uid', $uid)
            ->find();

        if (empty($notice)) {
            return $this->error('公告不存在');
        }

        if ((int) ($notice['isread'] ?? 0) === 1) {
            return $this->success([], '已经阅读过此公告');
        }

        // 更新已读状态和时间
        Db::name('admin_notice')
            ->where('id', $id)
            ->update([
                'isread' => 1,
                'readtime' => time(),
            ]);

        // 重新查询未读数
        $unreadCount = Db::name('admin_notice')
            ->where('aid', $aid)
            ->where('uid', $uid)
            ->where('isread', 0)
            ->count();

        return $this->success([
            'unread_count' => (int) $unreadCount,
        ], '标记成功');
    }
}
