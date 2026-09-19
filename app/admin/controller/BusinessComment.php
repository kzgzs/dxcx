<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\PlogService;
use app\common\service\ScopeService;
use think\facade\Db;

/**
 * 租户后台 - 店铺评价（对标参考系统 BusinessComment.php：列表/详情/回复/删除/审核回算均分）
 *
 * status：1=审核通过（展示并计入均分） 0=隐藏
 */
class BusinessComment extends BaseController
{
    /**
     * 评价列表
     * GET /admin/businessComment/list?page=&pagesize=&bid=&content=&status=&start=&end=
     */
    public function list()
    {
        $aid      = (int) ($this->request->aid ?? 0);
        $page     = max(1, (int) $this->request->get('page', 1));
        $pagesize = min(100, max(1, (int) $this->request->get('pagesize', 10)));
        $bid      = $this->request->get('bid', '');
        $content  = trim((string) $this->request->get('content', ''));
        $status   = $this->request->get('status', '');
        $start    = trim((string) $this->request->get('start', ''));
        $end      = trim((string) $this->request->get('end', ''));

        $query = Db::name('business_comment')->where('aid', $aid);

        if ('' !== $bid) {
            ScopeService::applyBid($query, (string) $bid, 'bid');
        }
        if ('' !== $content) {
            $query->whereLike('content', '%' . $content . '%');
        }
        if ('' !== $status) {
            $query->where('status', (int) $status);
        }
        if ('' !== $start) {
            $query->where('createtime', '>=', strtotime($start));
        }
        if ('' !== $end) {
            $query->where('createtime', '<', strtotime($end) + 86400);
        }

        $total = (int) $query->count();
        // 排序（对照参考系统 BusinessComment：评分/评价时间/ID 可排序，白名单防注入）
        $sortField = in_array($this->request->get('field', ''), ['id', 'score', 'createtime'], true)
            ? (string) $this->request->get('field')
            : 'id';
        $sortOrder = strtolower(trim((string) $this->request->get('order', 'desc'))) === 'asc' ? 'asc' : 'desc';
        $list  = $query->order($sortField, $sortOrder)->page($page, $pagesize)->select()->toArray();

        // 商户名映射
        $bnameArr = Db::name('business')->where('aid', $aid)->column('name', 'id');

        foreach ($list as &$row) {
            $row['bname'] = $bnameArr[$row['bid']] ?? '';
        }
        unset($row);

        // 商户下拉（过滤用）
        $businessOptions = [];
        foreach ($bnameArr as $bId => $bName) {
            $businessOptions[] = ['id' => (int) $bId, 'name' => (string) $bName];
        }

        return $this->success(['list' => $list, 'total' => $total, 'business_options' => $businessOptions]);
    }

    /**
     * 评价详情
     * GET /admin/businessComment/detail?id=
     */
    public function detail()
    {
        $aid = (int) ($this->request->aid ?? 0);
        $id  = (int) $this->request->get('id', 0);

        $detail = Db::name('business_comment')->where('aid', $aid)->where('id', $id)->find();
        if (empty($detail)) {
            return $this->error('评价不存在');
        }

        $detail['content_pic'] = $detail['content_pic'] ? explode(',', (string) $detail['content_pic']) : [];

        $member = Db::name('member')->where('aid', $aid)->where('id', $detail['mid'])->find();
        $detail['member'] = $member
            ? ['nickname' => $member['nickname'], 'headimg' => $member['headimg']]
            : ['nickname' => $detail['nickname'], 'headimg' => $detail['headimg']];

        return $this->success($detail);
    }

    /**
     * 评价回复
     * POST /admin/businessComment/reply body: {id, content}
     */
    public function reply()
    {
        $aid     = (int) ($this->request->aid ?? 0);
        $uid     = (int) ($this->request->authUid ?? 0);
        $id      = (int) $this->request->post('id', 0);
        $content = trim((string) $this->request->post('content', ''));

        if ($id <= 0 || '' === $content) {
            return $this->error('参数不完整');
        }

        $comment = Db::name('business_comment')->where('aid', $aid)->where('id', $id)->find();
        if (empty($comment)) {
            return $this->error('评价不存在');
        }

        Db::name('business_comment')->where('aid', $aid)->where('id', $id)->update([
            'reply_content' => $content,
            'reply_time'    => time(),
        ]);

        PlogService::write($aid, $uid, '商户评价回复' . $id);

        return $this->success([], '回复成功');
    }

    /**
     * 评价审核（setst：批量改状态并回算商户均分/评价数）
     * POST /admin/businessComment/setst body: {ids: [], status: 0|1}
     */
    public function setst()
    {
        $aid    = (int) ($this->request->aid ?? 0);
        $uid    = (int) ($this->request->authUid ?? 0);
        $ids    = array_map('intval', (array) $this->request->post('ids/a', []));
        $status = (int) $this->request->post('status', 1);

        if (empty($ids)) {
            return $this->error('请选择评价');
        }

        $list = Db::name('business_comment')->where('aid', $aid)->where('id', 'in', $ids)->select()->toArray();
        if (empty($list)) {
            return $this->error('评价不存在');
        }

        $touchedBids = [];
        foreach ($list as $item) {
            Db::name('business_comment')->where('aid', $aid)->where('id', $item['id'])->update(['status' => $status]);
            $touchedBids[(int) $item['bid']] = true;
        }

        // 回算商户评论数与均分（仅统计 status=1）
        foreach (array_keys($touchedBids) as $bid) {
            $count = Db::name('business_comment')->where('aid', $aid)->where('bid', $bid)->where('status', 1)->count();
            $score = Db::name('business_comment')->where('aid', $aid)->where('bid', $bid)->where('status', 1)->avg('score');
            Db::name('business')->where('aid', $aid)->where('id', $bid)->update([
                'comment_num'   => $count,
                'comment_score' => round((float) $score, 1),
            ]);
        }

        PlogService::write($aid, $uid, '商户评价审核' . implode(',', $ids));

        return $this->success([], '操作成功');
    }

    /**
     * 删除评价（批量，删除后回算商户均分）
     * POST /admin/businessComment/delete body: {ids: []}
     */
    public function delete()
    {
        $aid = (int) ($this->request->aid ?? 0);
        $uid = (int) ($this->request->authUid ?? 0);
        $ids = array_map('intval', (array) $this->request->post('ids/a', []));

        if (empty($ids)) {
            return $this->error('请选择评价');
        }

        $list = Db::name('business_comment')->where('aid', $aid)->where('id', 'in', $ids)->select()->toArray();
        if (empty($list)) {
            return $this->error('评价不存在');
        }

        Db::name('business_comment')->where('aid', $aid)->where('id', 'in', $ids)->delete();

        $touchedBids = array_unique(array_map(static fn ($v) => (int) $v['bid'], $list));
        foreach ($touchedBids as $bid) {
            $count = Db::name('business_comment')->where('aid', $aid)->where('bid', $bid)->where('status', 1)->count();
            $score = Db::name('business_comment')->where('aid', $aid)->where('bid', $bid)->where('status', 1)->avg('score');
            Db::name('business')->where('aid', $aid)->where('id', $bid)->update([
                'comment_num'   => $count,
                'comment_score' => round((float) $score, 1),
            ]);
        }

        PlogService::write($aid, $uid, '商户评价删除' . implode(',', $ids));

        return $this->success([], '删除成功');
    }
}
