<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\ScopeService;
use think\facade\Db;

/**
 * 租户后台 - 视频管理（对照 canzhao app/controller/VideoList.php）
 *
 * video_list 表：type 0本地视频(video_url mp4)/1微信视频号(ext_param json)
 */
class VideoList extends BaseController
{
    /** 类型口径（对照 canzhao typelist） */
    public const TYPE_LIST = [0 => '本地视频', 1 => '微信视频号'];

    /**
     * 视频列表（name/type/status/bid 筛选）
     * GET /admin/videoList/list?page&pagesize&name&type&status&bid
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        $name   = trim((string) $this->request->get('name', ''));
        $type   = trim((string) $this->request->get('type', ''));
        $status = trim((string) $this->request->get('status', ''));

        $query = ScopeService::queryAs('video_list', $aid, 'v');
        ScopeService::applyBid($query, trim((string) $this->request->get('bid', '')), 'v.bid');

        if ('' !== $name) {
            $query->where('v.name', 'like', '%' . $name . '%');
        }
        if ('' !== $type) {
            $query->where('v.type', (int) $type);
        }
        if ('' !== $status) {
            $query->where('v.status', (int) $status);
        }

        $total = (clone $query)->count();
        $rows  = $query->field('v.*')->order('v.sort', 'asc')->order('v.id', 'desc')
            ->page($page, $pageSize)->select()->toArray();

        foreach ($rows as &$row) {
            $row['ext'] = json_decode((string) $row['ext_param'], true) ?: [];
        }
        unset($row);

        return $this->success(['total' => $total, 'list' => $rows]);
    }

    /**
     * 视频详情
     * GET /admin/videoList/detail?id=
     */
    public function detail()
    {
        $aid = ScopeService::aidOf($this->request);
        $id  = (int) $this->request->get('id', 0);

        $row = ScopeService::query('video_list', $aid)->where('id', $id)->find();
        if (empty($row)) {
            throw new BusinessException('视频不存在');
        }
        $row['ext'] = json_decode((string) $row['ext_param'], true) ?: [];

        return $this->success($row);
    }

    /**
     * 视频新增/编辑（ext json_encode 进 ext_param，对照 canzhao save）
     * POST /admin/videoList/save {id?, bid, name, pic, type, video_url, video_duration, ext{feedtype,finderuser,feedid,feedtoken}, status, sort}
     */
    public function save()
    {
        $aid  = ScopeService::aidOf($this->request);
        $data = $this->request->post();
        $data = is_array($data) ? $data : [];

        if ('' === trim((string) ($data['name'] ?? ''))) {
            throw new BusinessException('请填写视频名称');
        }

        $type = (int) ($data['type'] ?? 0);
        if (!isset(self::TYPE_LIST[$type])) {
            throw new BusinessException('非法的视频类型');
        }

        // decimal 字段规整：video_duration 空串/非数字入库会报 Incorrect decimal value（任务234 Bug3 修复）
        $duration = trim((string) ($data['video_duration'] ?? ''));
        $duration = ('' === $duration || !is_numeric($duration)) ? '0' : $duration;

        $record = [
            'bid'            => (int) ($data['bid'] ?? 0),
            'name'           => trim((string) $data['name']),
            'pic'            => (string) ($data['pic'] ?? ''),
            'type'           => $type,
            'video_url'      => (string) ($data['video_url'] ?? ''),
            'video_duration' => $duration,
            'status'         => (int) ($data['status'] ?? 1),
            'sort'           => (int) ($data['sort'] ?? 0),
        ];

        $ext = $data['ext'] ?? [];
        if (is_string($ext)) {
            $ext = json_decode($ext, true) ?: [];
        }
        $record['ext_param'] = json_encode(is_array($ext) ? $ext : [], JSON_UNESCAPED_UNICODE);

        $id = (int) ($data['id'] ?? 0);
        if ($id > 0) {
            $exists = ScopeService::query('video_list', $aid)->where('id', $id)->find();
            if (empty($exists)) {
                throw new BusinessException('视频不存在');
            }
            ScopeService::query('video_list', $aid)->where('id', $id)->update($record);
        } else {
            $record['aid']        = $aid;
            $record['createtime'] = time();
            $id = (int) Db::name('video_list')->insertGetId($record);
        }

        return $this->success(['id' => $id], '保存成功');
    }

    /**
     * 视频删除
     * POST /admin/videoList/delete {ids[]|id}
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
            throw new BusinessException('请选择要删除的视频');
        }

        ScopeService::query('video_list', $aid)->whereIn('id', $ids)->delete();

        return $this->success([], '删除成功');
    }
}
