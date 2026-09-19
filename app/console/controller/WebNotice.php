<?php

declare(strict_types=1);

namespace app\console\controller;

use app\BaseController;
use think\facade\Db;
use think\facade\View;

/**
 * 超管控制台 - 通知公告
 *
 * 对照参考系统 WebNotice 控制器 + web_notice/index.html：
 * 列表（接收账号/标题/状态筛选、发送时间范围、排序、分页）
 * + 添加（接收范围 + 接收账号 + 标题 + 富文本内容）+ 查看（详情弹窗）+ 删除
 * 数据来源：admin_notice 表
 * status: isread 0 未读 1 已读
 */
class WebNotice extends BaseController
{
    /** 允许排序的字段（参考系统表头可排序列：ID） */
    private const SORT_FIELDS = ['id', 'createtime'];

    /**
     * 通知公告列表（筛选 + 排序 + 分页）
     * GET /console/webnotice/list
     * 参数：page pagesize un title start_date end_date field order isread
     */
    public function list()
    {
        $page     = max(1, (int) $this->request->param('page', 1));
        $pagesize = (int) $this->request->param('pagesize', 15);
        $pagesize = max(1, min(100, $pagesize));

        $query = Db::name('admin_notice')->alias('notice')
            ->join('admin_user user', 'user.id=notice.uid', 'left')
            ->field('user.un as username, notice.*');

        // 类型过滤（仅总后台发送的）
        $query->where('notice.type', 1);

        // 接收账号（模糊，参考系统检索项 un）
        $un = trim((string) $this->request->param('un', ''));
        if ($un !== '') {
            $query->whereLike('user.un', '%' . $un . '%');
        }

        // 标题（模糊，参考系统检索项 title）
        $title = trim((string) $this->request->param('title', ''));
        if ($title !== '') {
            $query->whereLike('notice.title', '%' . $title . '%');
        }

        // 状态：全部/未读/已读（参考系统检索项 isread）
        $isread = $this->request->param('isread', '');
        if ($isread !== '' && $isread !== null) {
            $query->where('notice.isread', (int) $isread);
        }

        // 发送时间范围（参考系统检索项 ctime，含端点日全天）
        $startDate = trim((string) $this->request->param('start_date', ''));
        if ($startDate !== '') {
            $ts = strtotime($startDate);
            if ($ts !== false) {
                $query->where('notice.createtime', '>=', $ts);
            }
        }
        $endDate = trim((string) $this->request->param('end_date', ''));
        if ($endDate !== '') {
            $ts = strtotime($endDate . ' 23:59:59');
            if ($ts !== false) {
                $query->where('notice.createtime', '<=', $ts);
            }
        }

        // 排序：默认 id desc（同参考系统）
        $field = (string) $this->request->param('field', '');
        $order = strtolower((string) $this->request->param('order', ''));
        if ($field !== '' && in_array($field, self::SORT_FIELDS, true) && in_array($order, ['asc', 'desc'], true)) {
            // 同参考系统：join 查询排序字段加 notice. 前缀
            $query->order('notice.' . $field, $order);
        } else {
            $query->order('notice.id', 'desc');
        }

        $total = (clone $query)->count();
        $rows  = $query
            ->page($page, $pagesize)
            ->select()
            ->toArray();

        $list = array_map(static function ($row) {
            $row['id']         = (int) $row['id'];
            $row['uid']        = (int) $row['uid'];
            $row['createtime'] = (int) $row['createtime'];
            $row['isread']     = (int) $row['isread'];
            
            return $row;
        }, $rows);

        return $this->success([
            'list'  => $list,
            'total' => (int) $total,
        ]);
    }

    /**
     * 通知详情（查看）
     * GET /console/webnotice/detail?id=xxx
     */
    public function detail()
    {
        $id = (int) $this->request->param('id', 0);
        if ($id <= 0) {
            return $this->error('参数错误');
        }

        $info = Db::name('admin_notice')
            ->alias('notice')
            ->join('admin_user user', 'user.id=notice.uid', 'left')
            ->field('user.un as username, notice.*')
            ->where('notice.id', $id)
            ->find();

        if (!$info) {
            return $this->error('公告不存在');
        }

        // 格式化时间
        $info['create_time'] = date('Y-m-d H:i', $info['createtime']);

        return $this->success(['info' => $info]);
    }

    /**
     * 接收账号选项（对照参考系统 edit：按接收范围 5 组账号列表）
     * GET /console/webnotice/userList
     */
    public function userList()
    {
        $field = 'id, un';

        // 所有账号（参考系统 userlist，不限状态）
        $userlist = Db::name('admin_user')->field($field)->order('id')->select()->toArray();
        // 平台主账号（参考系统 adminuserlist）
        $adminuserlist = Db::name('admin_user')->where('isadmin', '>=', 1)->where('bid', 0)->where('status', 1)->field($field)->order('id')->select()->toArray();
        // 平台主账号 + 分账号（参考系统 adminuserlist2）
        $adminuserlist2 = Db::name('admin_user')->where('bid', 0)->where('status', 1)->field($field)->order('id')->select()->toArray();
        // 商家主账号（参考系统 businessuserlist）
        $businessuserlist = Db::name('admin_user')->where('isadmin', '>=', 1)->where('bid', '>', 0)->where('status', 1)->field($field)->order('id')->select()->toArray();
        // 商家主账号 + 分账号（参考系统 businessuserlist2）
        $businessuserlist2 = Db::name('admin_user')->where('bid', '>', 0)->where('status', 1)->field($field)->order('id')->select()->toArray();

        return $this->success([
            'userlist'          => $userlist,
            'adminuserlist'     => $adminuserlist,
            'adminuserlist2'    => $adminuserlist2,
            'businessuserlist'  => $businessuserlist,
            'businessuserlist2' => $businessuserlist2,
        ]);
    }

    /**
     * 接收账号选项（按 scope 返回单组，结构 {code:0,data:[...]}，复用 userList 查询逻辑）
     * GET /console/webnotice/receivers?scope=1..5
     * scope: 1 所有账号 2 平台主账号 3 平台主账号+分账号 4 商家主账号 5 商家主账号+分账号
     */
    public function receivers()
    {
        $scope = (int) $this->request->param('scope', 1);
        $field = 'id, un';

        $query = match ($scope) {
            2 => Db::name('admin_user')->where('isadmin', '>=', 1)->where('bid', 0)->where('status', 1),
            3 => Db::name('admin_user')->where('bid', 0)->where('status', 1),
            4 => Db::name('admin_user')->where('isadmin', '>=', 1)->where('bid', '>', 0)->where('status', 1),
            5 => Db::name('admin_user')->where('bid', '>', 0)->where('status', 1),
            default => Db::name('admin_user'),
        };

        $list = $query->field($field)->order('id')->select()->toArray();

        return $this->success($list);
    }

    /**
     * 保存通知公告（新增/编辑）
     * POST /console/webnotice/save body: {sendtype, senduser?, info:{title, content}}
     * sendtype: 1 所有账号 2 平台主账号 3 平台主账号 + 分账号 4 商家主账号 5 商家主账号 + 分账号
     */
    public function save()
    {
        $body = $this->body();
        $info = $body['info'] ?? [];

        if (empty($info['title']) || empty($info['content'])) {
            return $this->error('标题和内容不能为空');
        }

        $sendType = (int) ($body['sendtype'] ?? 1);
        $sendUser = (int) ($body['senduser' . $sendType] ?? 0);

        // 构建发送用户列表
        $userQuery = $this->buildUserQuery($sendType, $sendUser);

        $users = $userQuery->select()->toArray();
        if (empty($users)) {
            return $this->error('没有可发送的用户');
        }

        // 插入记录（循环为每个用户发送一条公告）
        $ids = [];
        foreach ($users as $user) {
            $noticeData = [
                'aid'          => $user['aid'],
                'bid'          => $user['bid'],
                'uid'          => $user['id'],
                'title'        => trim($info['title']),
                'content'      => $info['content'],
                'createtime'   => time(),
                'type'         => 1, // 总后台发送
                'isread'       => 0, // 默认未读
            ];

            $noticeId = Db::name('admin_notice')->insertGetId($noticeData);
            if ($noticeId) {
                $ids[] = $noticeId;
            }
        }

        if (empty($ids)) {
            return $this->error('发布失败');
        }

        return $this->success(['ids' => $ids], '发布公告成功');
    }

    /**
     * 构建发送用户查询条件
     */
    private function buildUserQuery(int $sendType, int $sendUser): \think\db\Query
    {
        if ($sendUser > 0) {
            // 指定单个账号
            return Db::name('admin_user')->where('id', $sendUser);
        }

        // 对照参考系统 save：type1/type2 不限状态，type3/4/5 仅启用账号
        return match ($sendType) {
            1 => Db::name('admin_user'), // 所有账号（平台用户 + 入驻商家）
            2 => Db::name('admin_user')->where('isadmin', '>=', 1)->where('bid', 0), // 平台主账号
            3 => Db::name('admin_user')->where('bid', 0)->where('status', 1), // 平台主账号 + 分账号
            4 => Db::name('admin_user')->where('isadmin', '>=', 1)->where('bid', '>', 0)->where('status', 1), // 商家主账号
            5 => Db::name('admin_user')->where('bid', '>', 0)->where('status', 1), // 商家主账号 + 分账号
            default => Db::name('admin_user') // 默认所有账号
        };
    }

    /**
     * 删除通知公告（支持批量）
     * POST /console/webnotice/delete body: {ids:[..]}
     * 删除文案沿用参考系统：确定要删除吗？删除后无法恢复！
     */
    public function delete()
    {
        $ids = $this->ids($this->body());
        if (empty($ids)) {
            return $this->error('请选择数据');
        }

        Db::name('admin_notice')->whereIn('id', $ids)->delete();

        return $this->success([], '删除成功');
    }

    /**
     * 提取 ids 数组（兼容 [1,2] 与 "1,2"）
     */
    private function ids(array $body): array
    {
        $ids = $body['ids'] ?? [];
        if (is_string($ids)) {
            $ids = explode(',', $ids);
        }
        if (!is_array($ids)) {
            return [];
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function ($v) {
            return $v > 0;
        })));

        return $ids;
    }

    /**
     * 请求体（兼容 JSON 与表单）
     */
    protected function body(): array
    {
        $data = $this->request->post();

        return is_array($data) ? $data : [];
    }
}
