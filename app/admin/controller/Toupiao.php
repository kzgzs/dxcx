<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\ScopeService;
use think\facade\Db;

/**
 * 租户后台 - 投票活动（admin 应用，JWT role=admin，aid 走 ScopeService）
 *
 * 覆盖：活动 CRUD / 活动分组 / 报名选手（审核状态机 0待审→1通过|2驳回）/
 *       排行统计 / 投票记录 / 投票防刷（同会员同活动 每日N票、累计N票 可配）
 *
 * 表：toupiao（活动）/ toupiao_group（分组）/ toupiao_join（选手）/ toupiao_help（投票记录）
 */
class Toupiao extends BaseController
{
    // ==================== 活动 ====================

    /**
     * 活动列表（附分组名/通过选手数/累计票数）
     * GET /admin/toupiao/list?page&pagesize&keyword&status&group_id
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;
        $keyword  = trim((string) $this->request->get('keyword', ''));
        $status   = trim((string) $this->request->get('status', ''));
        $groupId  = (int) $this->request->get('group_id', 0);

        $query = ScopeService::query('toupiao', $aid);
        if ('' !== $keyword) {
            $query->whereLike('name', '%' . $keyword . '%');
        }
        if ('' !== $status) {
            $query->where('status', (int) $status);
        }
        if ($groupId > 0) {
            $query->where('group_id', $groupId);
        }

        $total = $query->count();
        $rows  = $query->order('sort', 'desc')->order('id', 'desc')
            ->page($page, $pageSize)->select()->toArray();

        $groupNames = ScopeService::query('toupiao_group', $aid)->column('name', 'id');
        foreach ($rows as $k => $row) {
            $rows[$k]['group_name'] = $groupNames[(int) $row['group_id']] ?? '';
            $rows[$k]['joinnum']    = ScopeService::query('toupiao_join', $aid)
                ->where('hid', $row['id'])->where('status', 1)->count();
            $rows[$k]['helpnum']    = (int) ScopeService::query('toupiao_join', $aid)
                ->where('hid', $row['id'])->where('status', 1)->sum('helpnum');
        }

        return $this->success(['list' => $rows, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize]);
    }

    /**
     * 活动保存（新建/编辑：名称/起止时间/展示方式/投票上限/规则说明等）
     * POST /admin/toupiao/save {id?, name, starttime, endtime, listtype, ...}
     */
    public function save()
    {
        $aid = ScopeService::aidOf($this->request);
        $id  = (int) $this->request->post('id', 0);

        $name = trim((string) $this->request->post('name', ''));
        if ('' === $name) {
            throw new BusinessException('请填写活动名称');
        }

        $starttime = $this->parseTime($this->request->post('starttime'));
        $endtime   = $this->parseTime($this->request->post('endtime'));
        if ($starttime <= 0 || $endtime <= 0) {
            throw new BusinessException('请填写活动起止时间');
        }
        if ($endtime <= $starttime) {
            throw new BusinessException('结束时间必须晚于开始时间');
        }

        $data = [
            'name'         => $name,
            'banner'       => trim((string) $this->request->post('banner', '')),
            'color1'       => trim((string) $this->request->post('color1', '#FD4A46')),
            'color2'       => trim((string) $this->request->post('color2', '#1C83FF')),
            'listtype'     => (int) $this->request->post('listtype', 0),
            'helptext'     => trim((string) $this->request->post('helptext', '投TA一票')),
            'starttime'    => $starttime,
            'endtime'      => $endtime,
            'canapply'     => (int) $this->request->post('canapply', 1),
            'apply_check'  => (int) $this->request->post('apply_check', 1),
            'help_check'   => (int) $this->request->post('help_check', 0),
            'sort'         => (int) $this->request->post('sort', 0),
            'guize'        => (string) $this->request->post('guize', ''),
            'sharetitle'   => trim((string) $this->request->post('sharetitle', '')),
            'sharepic'     => trim((string) $this->request->post('sharepic', '')),
            'sharedesc'    => trim((string) $this->request->post('sharedesc', '')),
            'sharelink'    => trim((string) $this->request->post('sharelink', '')),
            'per_daycount' => max(1, (int) $this->request->post('per_daycount', 1)),
            'per_allcount' => max(0, (int) $this->request->post('per_allcount', 0)),
            'gettj'        => trim((string) $this->request->post('gettj', '-1')),
            'jump_url'     => trim((string) $this->request->post('jump_url', '')),
            'group_id'     => (int) $this->request->post('group_id', 0),
            'status'       => (int) $this->request->post('status', 1),
        ];

        if ($id > 0) {
            $exist = ScopeService::query('toupiao', $aid)->where('id', $id)->find();
            if (!$exist) {
                throw new BusinessException('活动不存在');
            }
            ScopeService::query('toupiao', $aid)->where('id', $id)->update($data);
        } else {
            $data['aid']        = $aid;
            $data['createtime'] = time();
            $id = ScopeService::query('toupiao', $aid)->insertGetId($data);
        }

        return $this->success(['id' => (int) $id], '保存成功');
    }

    /**
     * 活动删除（级联清理选手与投票记录）
     * POST /admin/toupiao/del {id}
     */
    public function del()
    {
        $aid = ScopeService::aidOf($this->request);
        $id  = (int) $this->request->post('id', 0);

        $exist = ScopeService::query('toupiao', $aid)->where('id', $id)->find();
        if (!$exist) {
            throw new BusinessException('活动不存在');
        }

        Db::startTrans();
        try {
            ScopeService::query('toupiao', $aid)->where('id', $id)->delete();
            ScopeService::query('toupiao_join', $aid)->where('hid', $id)->delete();
            ScopeService::query('toupiao_help', $aid)->where('hid', $id)->delete();
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw new BusinessException('删除失败：' . $e->getMessage());
        }

        return $this->success([], '删除成功');
    }

    /**
     * 活动启停
     * POST /admin/toupiao/status {id, status}
     */
    public function status()
    {
        $aid    = ScopeService::aidOf($this->request);
        $id     = (int) $this->request->post('id', 0);
        $status = (int) $this->request->post('status', 0);

        $exist = ScopeService::query('toupiao', $aid)->where('id', $id)->find();
        if (!$exist) {
            throw new BusinessException('活动不存在');
        }

        ScopeService::query('toupiao', $aid)->where('id', $id)
            ->update(['status' => 1 === $status ? 1 : 0]);

        return $this->success([], 1 === $status ? '已开启' : '已关闭');
    }

    // ==================== 分组 ====================

    /**
     * 分组列表（附活动数）
     * GET /admin/toupiao/group/list?keyword
     */
    public function groupList()
    {
        $aid     = ScopeService::aidOf($this->request);
        $keyword = trim((string) $this->request->get('keyword', ''));

        $query = ScopeService::query('toupiao_group', $aid);
        if ('' !== $keyword) {
            $query->whereLike('name', '%' . $keyword . '%');
        }
        $rows = $query->order('sort', 'desc')->order('id', 'desc')->select()->toArray();

        $countMap = ScopeService::query('toupiao', $aid)
            ->field('group_id, count(*) as cnt')->where('group_id', '>', 0)
            ->group('group_id')->select()->toArray();
        $cntByGroup = [];
        foreach ($countMap as $item) {
            $cntByGroup[(int) $item['group_id']] = (int) $item['cnt'];
        }
        foreach ($rows as $k => $row) {
            $rows[$k]['activity_count'] = $cntByGroup[(int) $row['id']] ?? 0;
        }

        return $this->success(['list' => $rows]);
    }

    /**
     * 分组保存（名称同租户内唯一）
     * POST /admin/toupiao/group/save {id?, name, sort, status}
     */
    public function groupSave()
    {
        $aid  = ScopeService::aidOf($this->request);
        $id   = (int) $this->request->post('id', 0);
        $name = trim((string) $this->request->post('name', ''));
        if ('' === $name) {
            throw new BusinessException('请填写分组名称');
        }

        $dup = ScopeService::query('toupiao_group', $aid)->where('name', $name);
        if ($id > 0) {
            $dup->where('id', '<>', $id);
        }
        if ($dup->find()) {
            throw new BusinessException('该分组名称已存在');
        }

        $data = [
            'name'   => $name,
            'sort'   => (int) $this->request->post('sort', 0),
            'status' => (int) $this->request->post('status', 1),
        ];

        if ($id > 0) {
            $exist = ScopeService::query('toupiao_group', $aid)->where('id', $id)->find();
            if (!$exist) {
                throw new BusinessException('分组不存在');
            }
            ScopeService::query('toupiao_group', $aid)->where('id', $id)->update($data);
        } else {
            $data['aid']        = $aid;
            $data['createtime'] = time();
            $id = ScopeService::query('toupiao_group', $aid)->insertGetId($data);
        }

        return $this->success(['id' => (int) $id], '保存成功');
    }

    /**
     * 分组删除（有活动挂靠时拒绝）
     * POST /admin/toupiao/group/del {id}
     */
    public function groupDel()
    {
        $aid = ScopeService::aidOf($this->request);
        $id  = (int) $this->request->post('id', 0);

        $exist = ScopeService::query('toupiao_group', $aid)->where('id', $id)->find();
        if (!$exist) {
            throw new BusinessException('分组不存在');
        }
        $used = ScopeService::query('toupiao', $aid)->where('group_id', $id)->count();
        if ($used > 0) {
            throw new BusinessException('该分组下还有 ' . $used . ' 个活动，请先调整活动分组');
        }

        ScopeService::query('toupiao_group', $aid)->where('id', $id)->delete();

        return $this->success([], '删除成功');
    }

    // ==================== 选手（报名） ====================

    /**
     * 选手列表（附活动名/当前排名；status: 0待审核 1已通过 2已驳回）
     * GET /admin/toupiao/join/list?page&pagesize&hid&status&keyword
     */
    public function joinList()
    {
        $aid = ScopeService::aidOf($this->request);

        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;
        $hid      = (int) $this->request->get('hid', 0);
        $status   = trim((string) $this->request->get('status', ''));
        $keyword  = trim((string) $this->request->get('keyword', ''));

        $query = ScopeService::query('toupiao_join', $aid);
        if ($hid > 0) {
            $query->where('hid', $hid);
        }
        if ('' !== $status) {
            $query->where('status', (int) $status);
        }
        if ('' !== $keyword) {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('name', '%' . $keyword . '%')
                    ->whereOr('number', 'like', '%' . $keyword . '%');
            });
        }

        $total = $query->count();
        $rows  = $query->order('id', 'desc')->page($page, $pageSize)->select()->toArray();

        $hdNames = ScopeService::query('toupiao', $aid)->column('name', 'id');
        foreach ($rows as $k => $row) {
            $rows[$k]['hdname'] = $hdNames[(int) $row['hid']] ?? '';
            $rows[$k]['mingci'] = 1 === (int) $row['status']
                ? $this->rankOf($aid, (int) $row['hid'], (int) $row['helpnum'], (int) $row['id'])
                : 0;
        }

        return $this->success(['list' => $rows, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize]);
    }

    /**
     * 后台添加/编辑选手（默认直接通过并生成编号）
     * POST /admin/toupiao/join/save {id?, hid, name, pic, detail_txt, detail, weixin, status, ...}
     */
    public function joinSave()
    {
        $aid = ScopeService::aidOf($this->request);
        $id  = (int) $this->request->post('id', 0);

        $hid  = (int) $this->request->post('hid', 0);
        $name = trim((string) $this->request->post('name', ''));
        if ($hid <= 0) {
            throw new BusinessException('请选择所属活动');
        }
        if ('' === $name) {
            throw new BusinessException('请填写选手名称');
        }
        if (!ScopeService::query('toupiao', $aid)->where('id', $hid)->find()) {
            throw new BusinessException('活动不存在');
        }

        $data = [
            'hid'        => $hid,
            'name'       => $name,
            'pic'        => trim((string) $this->request->post('pic', '')),
            'pics'       => trim((string) $this->request->post('pics', '')),
            'detail_txt' => (string) $this->request->post('detail_txt', ''),
            'detail'     => (string) $this->request->post('detail', ''),
            'weixin'     => trim((string) $this->request->post('weixin', '')),
            'status'     => (int) $this->request->post('status', 1),
            'reason'     => trim((string) $this->request->post('reason', '')),
            'sort'       => (int) $this->request->post('sort', 0),
        ];

        if ($id > 0) {
            $join = ScopeService::query('toupiao_join', $aid)->where('id', $id)->find();
            if (!$join) {
                throw new BusinessException('选手不存在');
            }
            // 首次通过（含原待审/驳回改通过）且无编号 → 生成编号
            if (1 === $data['status'] && empty($join['number'])) {
                $data['number'] = $this->nextNumber($aid, $data['hid']);
            }
            ScopeService::query('toupiao_join', $aid)->where('id', $id)->update($data);
        } else {
            $data['aid']        = $aid;
            $data['mid']        = 0;
            $data['createtime'] = time();
            if (1 === $data['status']) {
                $data['number'] = $this->nextNumber($aid, $data['hid']);
            }
            $id = ScopeService::query('toupiao_join', $aid)->insertGetId($data);
        }

        return $this->success(['id' => (int) $id], '保存成功');
    }

    /**
     * 报名审核（状态机：0待审核 → 1通过（生成编号）| 2驳回（必填原因）；
     * 已通过的再次通过不重复发编号；驳回可重新提审回到待审后走同一路径）
     * POST /admin/toupiao/join/audit {ids[], status(1|2), reason?}
     */
    public function joinAudit()
    {
        $aid    = ScopeService::aidOf($this->request);
        $ids    = array_values(array_filter(array_map('intval', (array) $this->request->post('ids', []))));
        $status = (int) $this->request->post('status', 0);
        $reason = trim((string) $this->request->post('reason', ''));

        if (empty($ids)) {
            throw new BusinessException('请选择要审核的选手');
        }
        if (!in_array($status, [1, 2], true)) {
            throw new BusinessException('审核状态错误');
        }
        if (2 === $status && '' === $reason) {
            throw new BusinessException('驳回时请填写原因');
        }

        Db::startTrans();
        try {
            foreach ($ids as $jid) {
                $join = ScopeService::query('toupiao_join', $aid)->where('id', $jid)->find();
                if (!$join) {
                    continue;
                }
                $update = ['status' => $status, 'reason' => 1 === $status ? '' : $reason];
                if (1 === $status && empty($join['number'])) {
                    $update['number'] = $this->nextNumber($aid, (int) $join['hid']);
                }
                ScopeService::query('toupiao_join', $aid)->where('id', $jid)->update($update);
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw new BusinessException('审核失败：' . $e->getMessage());
        }

        return $this->success([], 1 === $status ? '已通过' : '已驳回');
    }

    /**
     * 选手删除（同步清理其投票记录）
     * POST /admin/toupiao/join/del {id}
     */
    public function joinDel()
    {
        $aid = ScopeService::aidOf($this->request);
        $id  = (int) $this->request->post('id', 0);

        $join = ScopeService::query('toupiao_join', $aid)->where('id', $id)->find();
        if (!$join) {
            throw new BusinessException('选手不存在');
        }

        Db::startTrans();
        try {
            ScopeService::query('toupiao_join', $aid)->where('id', $id)->delete();
            ScopeService::query('toupiao_help', $aid)->where('joinid', $id)->delete();
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw new BusinessException('删除失败：' . $e->getMessage());
        }

        return $this->success([], '删除成功');
    }

    /**
     * 后台加票（运营补偿/活动造势，直接累加票数）
     * POST /admin/toupiao/join/addpiao {id, num}
     */
    public function joinAddpiao()
    {
        $aid = ScopeService::aidOf($this->request);
        $id  = (int) $this->request->post('id', 0);
        $num = (int) $this->request->post('num', 0);

        if ($num <= 0) {
            throw new BusinessException('加票数必须大于 0');
        }
        $join = ScopeService::query('toupiao_join', $aid)->where('id', $id)->find();
        if (!$join) {
            throw new BusinessException('选手不存在');
        }

        ScopeService::query('toupiao_join', $aid)->where('id', $id)->inc('helpnum', $num)->update();

        return $this->success([], '已加 ' . $num . ' 票');
    }

    // ==================== 统计 / 投票记录 ====================

    /**
     * 排行统计（已通过选手按票数取 TopN）
     * GET /admin/toupiao/stat?hid&top
     */
    public function stat()
    {
        $aid = ScopeService::aidOf($this->request);
        $hid = (int) $this->request->get('hid', 0);
        $top = (int) $this->request->get('top', 10);
        $top = $top > 0 ? min($top, 100) : 10;

        if ($hid <= 0) {
            throw new BusinessException('请选择活动');
        }
        $activity = ScopeService::query('toupiao', $aid)->where('id', $hid)->find();
        if (!$activity) {
            throw new BusinessException('活动不存在');
        }

        $rows = ScopeService::query('toupiao_join', $aid)
            ->where('hid', $hid)->where('status', 1)
            ->order('helpnum', 'desc')->order('id', 'asc')
            ->limit($top)->select()->toArray();

        foreach ($rows as $k => $row) {
            $rows[$k]['mingci'] = $this->rankOf($aid, $hid, (int) $row['helpnum'], (int) $row['id']);
        }

        return $this->success([
            'activity'  => $activity,
            'joinnum'   => ScopeService::query('toupiao_join', $aid)->where('hid', $hid)->where('status', 1)->count(),
            'helpnum'   => (int) ScopeService::query('toupiao_join', $aid)->where('hid', $hid)->where('status', 1)->sum('helpnum'),
            'toplist'   => $rows,
        ]);
    }

    /**
     * 投票记录列表（附活动名/选手名/编号）
     * GET /admin/toupiao/help/list?page&pagesize&hid&joinid
     */
    public function helpList()
    {
        $aid = ScopeService::aidOf($this->request);

        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;
        $hid      = (int) $this->request->get('hid', 0);
        $joinid   = (int) $this->request->get('joinid', 0);

        $query = ScopeService::query('toupiao_help', $aid);
        if ($hid > 0) {
            $query->where('hid', $hid);
        }
        if ($joinid > 0) {
            $query->where('joinid', $joinid);
        }

        $total = $query->count();
        $rows  = $query->order('id', 'desc')->page($page, $pageSize)->select()->toArray();

        $hdNames  = ScopeService::query('toupiao', $aid)->column('name', 'id');
        $joinRows = ScopeService::query('toupiao_join', $aid)->field('id,name,number')->select()->toArray();
        $joinMap  = [];
        foreach ($joinRows as $item) {
            $joinMap[(int) $item['id']] = $item;
        }
        foreach ($rows as $k => $row) {
            $rows[$k]['hdname']   = $hdNames[(int) $row['hid']] ?? '';
            $rows[$k]['joinname'] = $joinMap[(int) $row['joinid']]['name'] ?? '';
            $rows[$k]['number']   = $joinMap[(int) $row['joinid']]['number'] ?? '';
        }

        return $this->success(['list' => $rows, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize]);
    }

    /**
     * 投票动作（防刷核心：同会员同活动 每日 per_daycount 票、累计 per_allcount 票）
     * POST /admin/toupiao/vote {mid, joinid}
     */
    public function vote()
    {
        $aid    = ScopeService::aidOf($this->request);
        $mid    = (int) $this->request->post('mid', 0);
        $joinid = (int) $this->request->post('joinid', 0);
        if ($mid <= 0 || $joinid <= 0) {
            throw new BusinessException('参数错误');
        }

        $join = ScopeService::query('toupiao_join', $aid)->where('id', $joinid)->find();
        if (!$join) {
            throw new BusinessException('选手不存在');
        }
        if (1 !== (int) $join['status']) {
            throw new BusinessException('选手未通过审核，暂不可投票');
        }

        $hid      = (int) $join['hid'];
        $activity = ScopeService::query('toupiao', $aid)->where('id', $hid)->find();
        if (!$activity) {
            throw new BusinessException('活动不存在');
        }
        if (1 !== (int) $activity['status']) {
            throw new BusinessException('活动未开启');
        }
        if ((int) $activity['starttime'] > time()) {
            throw new BusinessException('活动未开始');
        }
        if ((int) $activity['endtime'] < time()) {
            throw new BusinessException('活动已结束');
        }

        // 防刷一：同会员同活动每日票数上限
        $dayStart = strtotime(date('Y-m-d'));
        $perDay   = max(1, (int) $activity['per_daycount']);
        $dayCount = ScopeService::query('toupiao_help', $aid)
            ->where('hid', $hid)->where('mid', $mid)
            ->where('createtime', '>=', $dayStart)->count();
        if ($dayCount >= $perDay) {
            throw new BusinessException('每天最多只能投 ' . $perDay . ' 票');
        }

        // 防刷二：同会员同活动累计票数上限（0 表示不限）
        $perAll = (int) $activity['per_allcount'];
        if ($perAll > 0) {
            $allCount = ScopeService::query('toupiao_help', $aid)
                ->where('hid', $hid)->where('mid', $mid)->count();
            if ($allCount >= $perAll) {
                throw new BusinessException('每人最多只能投 ' . $perAll . ' 票');
            }
        }

        $member = Db::name('member')->where('aid', $aid)->where('id', $mid)->find();

        Db::startTrans();
        try {
            ScopeService::query('toupiao_help', $aid)->insert([
                'aid'        => $aid,
                'mid'        => $mid,
                'headimg'    => $member['headimg'] ?? '',
                'nickname'   => $member['nickname'] ?? ('会员' . $mid),
                'tel'        => '',
                'hid'        => $hid,
                'joinid'     => $joinid,
                'createtime' => time(),
            ]);
            ScopeService::query('toupiao_join', $aid)->where('id', $joinid)
                ->inc('helpnum')->inc('readcount')->update();
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw new BusinessException('投票失败：' . $e->getMessage());
        }

        return $this->success([], '投票成功');
    }

    // ==================== 内部工具 ====================

    /**
     * 生成选手编号（同活动内递增，三位起编：001/002/...）
     */
    private function nextNumber(int $aid, int $hid): string
    {
        $last = ScopeService::query('toupiao_join', $aid)
            ->where('hid', $hid)->order('number', 'desc')->value('number');
        $num  = (int) $last + 1;

        return $num < 100 ? sprintf('%03d', $num) : (string) $num;
    }

    /**
     * 计算名次（票数高者在前；同票按加入先后，与参考系统口径一致）
     */
    private function rankOf(int $aid, int $hid, int $helpnum, int $id): int
    {
        return 1 + ScopeService::query('toupiao_join', $aid)
            ->where('status', 1)->where('hid', $hid)
            ->where(function ($q) use ($helpnum, $id) {
                $q->where('helpnum', '>', $helpnum)
                    ->whereOr(function ($q2) use ($helpnum, $id) {
                        $q2->where('helpnum', $helpnum)->where('id', '<', $id);
                    });
            })
            ->count();
    }

    /**
     * 时间参数解析（兼容 Unix 时间戳与日期字符串）
     */
    private function parseTime(mixed $value): int
    {
        if (is_numeric($value)) {
            return (int) $value;
        }
        $value = trim((string) $value);
        if ('' === $value) {
            return 0;
        }
        $ts = strtotime($value);

        return false === $ts ? 0 : $ts;
    }
}
