<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use think\facade\Db;

/**
 * 租户后台 - 音乐列表透传（任务315）
 *
 * admin_music 平台级口径（aid=0）：音乐库由超管控制台 /console/music/* 维护，
 * 租户设计编辑器经本接口透传读取平台音乐列表（无需租户上下文）。
 */
class Music extends BaseController
{
    /**
     * GET /admin/music/list：平台音乐列表透传（keyword 搜索 + page/size 分页；size 上限 100 供编辑器全量拉取）
     */
    public function list()
    {
        $keyword = trim((string) $this->request->get('keyword', ''));
        $page    = max(1, (int) $this->request->get('page', 1));
        $size    = min(100, max(1, (int) $this->request->get('size', 10)));

        $query = Db::name('admin_music')->where('aid', 0);
        if ($keyword !== '') {
            $query->whereLike('name', '%' . $keyword . '%');
        }

        $total = (clone $query)->count();
        $rows  = $query->field('id,name,url,size,duration,createtime')
            ->order(['id' => 'desc'])
            ->page($page, $size)
            ->select()
            ->toArray();

        return $this->success(['list' => $rows, 'total' => $total]);
    }
}
