<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\ScopeService;
use think\facade\Db;

/**
 * 租户后台 - 名片管理（admin 应用，JWT role=admin，aid 走 ScopeService）
 *
 * 对照 canzhao app/controller/Mingpian.php：
 * 名片列表（浏览/收藏统计）/编辑/删除/浏览记录/收藏记录/名片设置
 */
class Mingpian extends BaseController
{
    /** 默认联系信息字段清单（对标 canzhao defaultSet：手机号/微信号/地址默认必填，自定义字段默认空名隐藏） */
    private const DEFAULT_FIELDS = [
        ['field' => 'tel', 'name' => '手机号', 'icon' => '', 'isshow' => 1, 'required' => 1],
        ['field' => 'weixin', 'name' => '微信号', 'icon' => '', 'isshow' => 1, 'required' => 1],
        ['field' => 'address', 'name' => '地址', 'icon' => '', 'isshow' => 1, 'required' => 1],
        ['field' => 'email', 'name' => '邮箱', 'icon' => '', 'isshow' => 1, 'required' => 0],
        ['field' => 'douyin', 'name' => '抖音号', 'icon' => '', 'isshow' => 1, 'required' => 0],
        ['field' => 'weibo', 'name' => '微博', 'icon' => '', 'isshow' => 1, 'required' => 0],
        ['field' => 'toutiao', 'name' => '头条号', 'icon' => '', 'isshow' => 1, 'required' => 0],
        ['field' => 'field1', 'name' => '', 'icon' => '', 'isshow' => 0, 'required' => 0],
        ['field' => 'field2', 'name' => '', 'icon' => '', 'isshow' => 0, 'required' => 0],
        ['field' => 'field3', 'name' => '', 'icon' => '', 'isshow' => 0, 'required' => 0],
        ['field' => 'field4', 'name' => '', 'icon' => '', 'isshow' => 0, 'required' => 0],
        ['field' => 'field5', 'name' => '', 'icon' => '', 'isshow' => 0, 'required' => 0],
    ];

    /**
     * 名片列表（realname/tel/nickname 筛选，附加浏览/收藏统计）
     * GET /admin/mingpian/list?page&pagesize&realname&tel&nickname
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        $realname = trim((string) $this->request->get('realname', ''));
        $tel      = trim((string) $this->request->get('tel', ''));
        $nickname = trim((string) $this->request->get('nickname', ''));

        $query = ScopeService::queryAs('mingpian', $aid, 'm')
            ->leftJoin('member u', 'u.id = m.mid')
            // u.headimg 用别名，避免覆盖名片自身的 headimg（个人照片）
            ->field('m.*, u.nickname, u.headimg as member_headimg');

        if ('' !== $realname) {
            $query->where('m.realname', 'like', '%' . $realname . '%');
        }
        if ('' !== $tel) {
            $query->where('m.tel', 'like', '%' . $tel . '%');
        }
        if ('' !== $nickname) {
            $query->where('u.nickname', 'like', '%' . $nickname . '%');
        }

        $total = (clone $query)->count();
        $rows  = $query->order('m.id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        // 浏览/收藏统计
        $ids = array_map(static fn ($r) => (int) $r['id'], $rows);
        $readMap = $favMap = [];
        if (!empty($ids)) {
            $readMap = Db::name('mingpian_readlog')
                ->where('aid', $aid)->whereIn('mpid', $ids)
                ->group('mpid')->column('count(*)', 'mpid');
            $favMap = Db::name('mingpian_favorite')
                ->where('aid', $aid)->whereIn('mpid', $ids)
                ->group('mpid')->column('count(*)', 'mpid');
        }

        foreach ($rows as &$row) {
            $row['readcount'] = (int) ($readMap[$row['id']] ?? 0);
            $row['favcount']  = (int) ($favMap[$row['id']] ?? 0);
        }
        unset($row);

        return $this->success(['total' => $total, 'list' => $rows]);
    }

    /**
     * 名片详情
     * GET /admin/mingpian/detail?id=
     */
    public function detail()
    {
        $aid = ScopeService::aidOf($this->request);
        $id  = (int) $this->request->get('id', 0);

        $row = ScopeService::query('mingpian', $aid)->where('id', $id)->find();
        if (empty($row)) {
            throw new BusinessException('名片不存在');
        }

        return $this->success($row);
    }

    /**
     * 名片新增/编辑
     * POST /admin/mingpian/save
     */
    public function save()
    {
        $aid  = ScopeService::aidOf($this->request);
        $data = $this->request->post();
        $data = is_array($data) ? $data : [];

        $allow = ['mid', 'bgpic', 'headimg', 'realname', 'touxian1', 'touxian2', 'touxian3',
            'tel', 'weixin', 'address', 'email', 'douyin', 'weibo', 'toutiao',
            'field1', 'field2', 'field3', 'field4', 'field5',
            'longitude', 'latitude', 'detail', 'sharetitle'];

        $record = [];
        foreach ($allow as $field) {
            if (array_key_exists($field, $data)) {
                $record[$field] = is_scalar($data[$field]) ? (string) $data[$field] : '';
            }
        }

        if ('' === trim((string) ($record['realname'] ?? '')) && empty($data['id'])) {
            throw new BusinessException('请填写姓名');
        }

        $id = (int) ($data['id'] ?? 0);
        if ($id > 0) {
            $exists = ScopeService::query('mingpian', $aid)->where('id', $id)->find();
            if (empty($exists)) {
                throw new BusinessException('名片不存在');
            }
            $record['updatetime'] = time();
            ScopeService::query('mingpian', $aid)->where('id', $id)->update($record);
        } else {
            $record['aid']        = $aid;
            $record['mid']        = (int) ($record['mid'] ?? 0);
            $record['createtime'] = time();
            $record['updatetime'] = time();
            $id = (int) Db::name('mingpian')->insertGetId($record);
        }

        return $this->success(['id' => $id], '保存成功');
    }

    /**
     * 名片删除（级联浏览/收藏记录）
     * POST /admin/mingpian/delete {ids[]|id}
     */
    public function delete()
    {
        $aid = ScopeService::aidOf($this->request);

        $ids = (array) $this->request->post('ids/a', []);
        if (empty($ids)) {
            $ids = [(int) $this->request->post('id', 0)];
        }
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) {
            throw new BusinessException('请选择要删除的名片');
        }

        ScopeService::query('mingpian', $aid)->whereIn('id', $ids)->delete();
        ScopeService::query('mingpian_readlog', $aid)->whereIn('mpid', $ids)->delete();
        ScopeService::query('mingpian_favorite', $aid)->whereIn('mpid', $ids)->delete();

        return $this->success([], '删除成功');
    }

    /**
     * 名片设置（GET 回显 / POST 保存）
     * - GET：无记录时按 canzhao defaultSet 口径初始化入库后返回（状态默认关闭、创建条件所有人）
     * - POST：保存 status/bgbtncolor/bgpics/createtj(implode)/field_list(json)
     * GET|POST /admin/mingpian/set
     */
    public function set()
    {
        $aid = ScopeService::aidOf($this->request);

        if ($this->request->isPost()) {
            return $this->setSave($aid);
        }

        $row = ScopeService::query('mingpian_set', $aid)->find();
        if (empty($row)) {
            // 首次进入初始化（对标 canzhao defaultSet：插入默认 field_list 行）
            $row = [
                'aid'         => $aid,
                'bgpics'      => '',
                'createtj'    => '-1',
                'field_list'  => json_encode(self::DEFAULT_FIELDS, JSON_UNESCAPED_UNICODE),
                'status'      => 0,
                'bgbtncolor'  => 0,
            ];
            Db::name('mingpian_set')->insert($row);
        }

        $row['bgpics']     = array_filter(explode(',', (string) $row['bgpics']));
        $row['createtj']   = array_filter(explode(',', (string) $row['createtj']), static fn ($v) => '' !== $v);
        $row['field_list'] = json_decode((string) $row['field_list'], true) ?: self::DEFAULT_FIELDS;

        return $this->success($row);
    }

    private function setSave(int $aid)
    {
        $data = $this->request->post();
        $data = is_array($data) ? $data : [];

        $fieldList = $data['field_list'] ?? self::DEFAULT_FIELDS;
        if (is_string($fieldList)) {
            $fieldList = json_decode($fieldList, true) ?: self::DEFAULT_FIELDS;
        }

        $createtj = $data['createtj'] ?? '-1';
        if (is_array($createtj)) {
            $createtj = empty($createtj) ? '-1' : implode(',', $createtj);
        }

        $bgpics = $data['bgpics'] ?? '';
        if (is_array($bgpics)) {
            $bgpics = implode(',', $bgpics);
        }

        $record = [
            'bgpics'     => (string) $bgpics,
            'createtj'   => '' === trim((string) $createtj) ? '-1' : (string) $createtj,
            'field_list' => json_encode($fieldList, JSON_UNESCAPED_UNICODE),
            'status'     => (int) ($data['status'] ?? 0),
            'bgbtncolor' => (int) ($data['bgbtncolor'] ?? 0),
        ];

        $exists = ScopeService::query('mingpian_set', $aid)->find();
        if (empty($exists)) {
            $record['aid'] = $aid;
            Db::name('mingpian_set')->insert($record);
        } else {
            ScopeService::query('mingpian_set', $aid)->update($record);
        }

        return $this->success([], '保存成功');
    }

    /**
     * 浏览记录列表（mpid/nickname/mid/ctime 筛选）
     * GET /admin/mingpian/readlog?page&pagesize&mpid&nickname&mid&starttime&endtime
     */
    public function readlog()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->logList($aid, 'mingpian_readlog'));
    }

    /**
     * 收藏记录列表
     * GET /admin/mingpian/favoritelog
     */
    public function favoritelog()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->logList($aid, 'mingpian_favorite'));
    }

    /**
     * 删除浏览/收藏记录
     * POST /admin/mingpian/logdelete {table, ids[]|id}
     */
    public function logdelete()
    {
        $aid   = ScopeService::aidOf($this->request);
        $table = (string) $this->request->post('table', '');
        if (!in_array($table, ['mingpian_readlog', 'mingpian_favorite'], true)) {
            throw new BusinessException('非法的记录类型');
        }

        $ids = (array) $this->request->post('ids/a', []);
        if (empty($ids)) {
            $ids = [(int) $this->request->post('id', 0)];
        }
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) {
            throw new BusinessException('请选择要删除的记录');
        }

        ScopeService::query($table, $aid)->whereIn('id', $ids)->delete();

        return $this->success([], '删除成功');
    }

    /**
     * 浏览/收藏记录通用列表（join member 取昵称头像）
     *
     * @return array{total:int,list:array}
     */
    private function logList(int $aid, string $table): array
    {
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        $mpid     = (int) $this->request->get('mpid', 0);
        $mid      = (int) $this->request->get('mid', 0);
        $nickname = trim((string) $this->request->get('nickname', ''));
        $startTime = trim((string) $this->request->get('starttime', ''));
        $endTime   = trim((string) $this->request->get('endtime', ''));

        $query = ScopeService::queryAs($table, $aid, 'l')
            ->leftJoin('member u', 'u.id = l.mid')
            ->leftJoin('mingpian p', 'p.id = l.mpid')
            ->field('l.*, u.nickname, u.headimg, p.realname');

        if ($mpid > 0) {
            $query->where('l.mpid', $mpid);
        }
        if ($mid > 0) {
            $query->where('l.mid', $mid);
        }
        if ('' !== $nickname) {
            $query->where('u.nickname', 'like', '%' . $nickname . '%');
        }
        if ('' !== $startTime) {
            $query->where('l.createtime', '>=', strtotime($startTime));
        }
        if ('' !== $endTime) {
            $query->where('l.createtime', '<=', strtotime($endTime . ' 23:59:59'));
        }

        $total = (clone $query)->count();
        $rows  = $query->order('l.id', 'desc')->page($page, $pageSize)->select()->toArray();

        return ['total' => $total, 'list' => $rows];
    }
}
