<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use think\facade\Db;
use think\facade\Request;

/**
 * 租户后台 - 帮助中心
 */
class Help extends BaseController
{
    /**
     * 获取开启的帮助文章列表（标题即问题）
     * GET /admin/help/list
     */
    public function list()
    {
        // 全局共享帮助文章，不移除 aid 过滤
        
        $list = Db::name('help')
            ->where('status', 1)
            ->order('sort', 'asc')
            ->order('id', 'desc')
            ->select()
            ->toArray();

        foreach ($list as &$item) {
            $item['title'] = $item['name'] ?? '';
            unset($item['name']);
        }

        return $this->success($list);
    }

    /**
     * 获取帮助文章详情
     * GET /admin/help/detail?id=
     */
    public function detail()
    {
        $id = (int) Request::get('id', 0);
        
        if ($id <= 0) {
            return $this->error('参数错误');
        }

        $aid = $this->request->aid ?? 0;
        
        $info = Db::name('help')
            ->where('id', $id)
            ->where('aid', $aid)
            ->find();

        if (empty($info)) {
            return $this->error('文章不存在');
        }

        // 增加阅读量
        Db::name('help')->inc('readcount', 1)->where('id', $id)->update();

        $res = $info->toArray();
        $res['title'] = $res['name'] ?? '';
        unset($res['name']);

        return $this->success($res);
    }
}
