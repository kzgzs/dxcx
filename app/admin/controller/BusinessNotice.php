<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\PlogService;
use app\common\service\ScopeService;
use think\facade\Db;

/**
 * 租户后台 - 商家通知公告（admin_notice 表，type=2 商家公告）
 *
 * 对标参考系统 BusinessNotice：接收人为 admin_user 中 bid>0 的商户账号；
 * sendtype：4=仅商家主账号(isadmin>=1) 5=商家主账号+分账号；
 * senduser：0=范围内全部，>0=仅指定账号；status：1开启 0关闭（批量上下架）。
 */
class BusinessNotice extends BaseController
{
    /** 列表排序字段白名单（防注入），默认 id desc */
    private const SORT_FIELDS = ['id', 'createtime', 'isread'];

    /**
     * 公告列表
     * GET /admin/businessNotice/list?page=&pagesize=&un=&title=&isread=&start=&end=&field=&order=
     */
    public function list()
    {
        $aid      = ScopeService::aidOf($this->request);
        $page     = max(1, (int) $this->request->get('page', 1));
        $pagesize = min(100, max(1, (int) $this->request->get('pagesize', 10)));
        $un       = trim((string) $this->request->get('un', ''));
        $title    = trim((string) $this->request->get('title', ''));
        $isread   = $this->request->get('isread', '');
        $start    = trim((string) $this->request->get('start', ''));
        $end      = trim((string) $this->request->get('end', ''));

        $query = ScopeService::queryAs('admin_notice', $aid, 'notice')
            ->join('admin_user user', 'user.id=notice.uid', 'left')
            ->field('user.un,notice.*')
            ->where('notice.type', 2)
            ->where('notice.bid', '>', 0);
        if ('' !== $un) {
            $query->whereLike('user.un', '%' . $un . '%');
        }
        if ('' !== $title) {
            $query->whereLike('notice.title', '%' . $title . '%');
        }
        if ('' !== $isread && $isread !== null) {
            $query->where('notice.isread', (int) $isread);
        }
        if ('' !== $start) {
            $query->where('notice.createtime', '>=', strtotime($start));
        }
        if ('' !== $end) {
            $query->where('notice.createtime', '<', strtotime($end) + 86400);
        }

        // 排序（白名单字段 + asc/desc）
        $field = (string) $this->request->get('field', 'id');
        $order = strtolower((string) $this->request->get('order', 'desc')) === 'asc' ? 'asc' : 'desc';
        if (!in_array($field, self::SORT_FIELDS, true)) {
            $field = 'id';
        }
        $query->order('notice.' . $field, $order);

        $total = (clone $query)->count();
        $list  = $query->page($page, $pagesize)->select()->toArray();

        foreach ($list as &$row) {
            $row['id']         = (int) $row['id'];
            $row['bid']        = (int) $row['bid'];
            $row['uid']        = (int) $row['uid'];
            $row['isread']     = (int) $row['isread'];
            $row['status']     = (int) ($row['status'] ?? 1);
            $row['createtime'] = (int) $row['createtime'];
        }
        unset($row);

        return $this->success(['list' => $list, 'total' => $total]);
    }

    /**
     * 公告详情
     * GET /admin/businessNotice/detail?id=
     */
    public function detail()
    {
        $aid = ScopeService::aidOf($this->request);
        $id  = (int) $this->request->get('id', 0);

        $info = ScopeService::query('admin_notice', $aid)->where('id', $id)->where('type', 2)->find();
        if (empty($info)) {
            return $this->error('公告不存在');
        }
        $info['id']         = (int) $info['id'];
        $info['isread']     = (int) ($info['isread'] ?? 0);
        $info['status']     = (int) ($info['status'] ?? 1);
        $info['createtime'] = (int) $info['createtime'];
        $info['un']         = (string) Db::name('admin_user')->where('id', $info['uid'])->value('un');

        return $this->success($info);
    }

    /**
     * 接收账号选项（添加公告弹窗两个下拉的数据源）
     * GET /admin/businessNotice/receivers
     * 返回 main=商家主账号（isadmin>=1）；all=商家主账号+分账号
     */
    public function receivers()
    {
        $aid = ScopeService::aidOf($this->request);

        $main = Db::name('admin_user')
            ->where('aid', $aid)->where('bid', '>', 0)->where('isadmin', '>=', 1)->where('status', 1)
            ->field('id,un')->order('id', 'asc')->select()->toArray();
        $all = Db::name('admin_user')
            ->where('aid', $aid)->where('bid', '>', 0)->where('status', 1)
            ->field('id,un')->order('id', 'asc')->select()->toArray();

        $map = static function (array $rows): array {
            return array_map(static function ($r) {
                return ['id' => (int) $r['id'], 'un' => (string) $r['un']];
            }, $rows);
        };

        return $this->success(['main' => $map($main), 'all' => $map($all)]);
    }

    /**
     * 发布公告（按接收范围逐用户插入公告行；senduser>0 时仅发给指定账号）
     * POST /admin/businessNotice/save  {title, content, sendtype:4|5, senduser:0|id}
     */
    public function save()
    {
        $aid      = ScopeService::aidOf($this->request);
        $uid      = (int) ($this->request->authUid ?? 0);
        $title    = trim((string) $this->request->post('title', ''));
        $content  = (string) $this->request->post('content', '');
        $sendtype = (int) $this->request->post('sendtype', 4);
        $senduser = (int) $this->request->post('senduser', 0);

        if ('' === $title) {
            return $this->error('请填写公告标题');
        }
        if ('' === trim(strip_tags($content))) {
            return $this->error('请填写公告内容');
        }
        if (!in_array($sendtype, [4, 5], true)) {
            return $this->error('接收范围参数错误');
        }

        // 接收用户：4=商家主账号（isadmin>=1）；5=主账号+分账号
        $query = Db::name('admin_user')
            ->where('aid', $aid)
            ->where('bid', '>', 0)
            ->where('status', 1)
            ->field('id,aid,bid');
        if ($sendtype === 4) {
            $query->where('isadmin', '>=', 1);
        }
        // 指定单个接收账号
        if ($senduser > 0) {
            $query->where('id', $senduser);
        }
        $userlist = $query->select()->toArray();
        if (empty($userlist)) {
            return $this->error('未找到可接收的商家账号');
        }

        $now   = time();
        $count = 0;
        foreach ($userlist as $user) {
            Db::name('admin_notice')->insert([
                'aid'        => $user['aid'],
                'bid'        => $user['bid'],
                'uid'        => $user['id'],
                'title'      => $title,
                'content'    => $content,
                'type'       => 2,
                'isread'     => 0,
                'status'     => 1,
                'createtime' => $now,
            ]);
            $count++;
        }

        PlogService::write($aid, $uid, '给商家发公告');

        return $this->success(['count' => $count], '发布成功');
    }

    /**
     * 批量开启/关闭公告（上下架）
     * POST /admin/businessNotice/setst  {ids:[], st:1|0}
     */
    public function setst()
    {
        $aid = ScopeService::aidOf($this->request);
        $uid = (int) ($this->request->authUid ?? 0);
        $ids = array_map('intval', (array) $this->request->post('ids', []));
        $st  = (int) $this->request->post('st', 1) === 1 ? 1 : 0;

        if (empty($ids)) {
            return $this->error('请选择公告');
        }

        ScopeService::query('admin_notice', $aid)->where('type', 2)->whereIn('id', $ids)->update([
            'status' => $st,
        ]);
        PlogService::write($aid, $uid, ($st ? '开启' : '关闭') . '商家公告' . implode(',', $ids));

        return $this->success([], $st ? '已开启' : '已关闭');
    }

    /**
     * 删除公告（批量）
     * POST /admin/businessNotice/delete  {ids:[]}
     */
    public function delete()
    {
        $aid = ScopeService::aidOf($this->request);
        $uid = (int) ($this->request->authUid ?? 0);
        $ids = array_map('intval', (array) $this->request->post('ids', []));

        if (empty($ids)) {
            return $this->error('请选择公告');
        }

        ScopeService::query('admin_notice', $aid)->where('type', 2)->whereIn('id', $ids)->delete();
        PlogService::write($aid, $uid, '删除商家公告' . implode(',', $ids));

        return $this->success([], '删除成功');
    }
}
