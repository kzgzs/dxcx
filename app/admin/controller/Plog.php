<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\PlogService;
use think\facade\Db;
use think\Response;

/**
 * 租户后台 - 操作日志（对标参考系统 Backstage::plog：列表/删除/导出）
 *
 * 数据范围：主账号可看全租户并按成员过滤；子账号仅可看本人日志
 */
class Plog extends BaseController
{
    /**
     * 操作日志列表
     * GET /admin/plog/list?page=&pagesize=&uid=&remark=&start=&end=
     */
    public function list()
    {
        $payload  = (array) ($this->request->authPayload ?? []);
        $aid      = (int) ($this->request->aid ?? 0);
        $uid      = (int) ($this->request->authUid ?? 0);
        $page     = max(1, (int) $this->request->get('page', 1));
        $pagesize = min(100, max(1, (int) $this->request->get('pagesize', 10)));

        $query = Db::name('plog')->where('aid', $aid);

        // 子账号只看自己；主账号可按成员过滤
        if ((int) ($payload['isadmin'] ?? 0) >= 1) {
            $filterUid = $this->request->get('uid', '');
            if ('' !== $filterUid) {
                $query->where('uid', (int) $filterUid);
            }
        } else {
            $query->where('uid', $uid);
        }

        $remark = trim((string) $this->request->get('remark', ''));
        if ('' !== $remark) {
            $query->whereLike('remark', '%' . $remark . '%');
        }

        $start = trim((string) $this->request->get('start', ''));
        $end   = trim((string) $this->request->get('end', ''));
        if ('' !== $start) {
            $query->where('createtime', '>=', strtotime($start));
        }
        if ('' !== $end) {
            $query->where('createtime', '<', strtotime($end) + 86400);
        }

        $total = (int) $query->count();
        $list  = $query->order('id', 'desc')->page($page, $pagesize)->select()->toArray();

        // 操作人账号映射
        $userArr = Db::name('admin_user')->where('aid', $aid)->column('un', 'id');
        foreach ($list as &$row) {
            $row['un'] = $userArr[$row['uid']] ?? ('uid:' . $row['uid']);
        }
        unset($row);

        // 成员下拉（主账号过滤用）
        $userOptions = [];
        foreach ($userArr as $uId => $un) {
            $userOptions[] = ['id' => (int) $uId, 'un' => (string) $un];
        }

        return $this->success([
            'list'          => $list,
            'total'         => $total,
            'user_options'  => $userOptions,
            'can_filter'    => (int) ($payload['isadmin'] ?? 0) >= 1,
        ]);
    }

    /**
     * 删除日志（批量）
     * POST /admin/plog/delete body: {ids: []}
     */
    public function delete()
    {
        $aid = (int) ($this->request->aid ?? 0);
        $uid = (int) ($this->request->authUid ?? 0);
        $ids = array_map('intval', (array) $this->request->post('ids/a', []));

        if (empty($ids)) {
            return $this->error('请选择日志');
        }

        Db::name('plog')->where('aid', $aid)->where('id', 'in', $ids)->delete();
        PlogService::write($aid, $uid, '删除操作日志');

        return $this->success([], '删除成功');
    }

    /**
     * 导出 CSV（与列表同过滤条件）
     * GET /admin/plog/export
     */
    public function export()
    {
        $payload = (array) ($this->request->authPayload ?? []);
        $aid     = (int) ($this->request->aid ?? 0);
        $uid     = (int) ($this->request->authUid ?? 0);

        $query = Db::name('plog')->where('aid', $aid);

        if ((int) ($payload['isadmin'] ?? 0) >= 1) {
            $filterUid = $this->request->get('uid', '');
            if ('' !== $filterUid) {
                $query->where('uid', (int) $filterUid);
            }
        } else {
            $query->where('uid', $uid);
        }

        $remark = trim((string) $this->request->get('remark', ''));
        if ('' !== $remark) {
            $query->whereLike('remark', '%' . $remark . '%');
        }

        $start = trim((string) $this->request->get('start', ''));
        $end   = trim((string) $this->request->get('end', ''));
        if ('' !== $start) {
            $query->where('createtime', '>=', strtotime($start));
        }
        if ('' !== $end) {
            $query->where('createtime', '<', strtotime($end) + 86400);
        }

        $list    = $query->order('id', 'desc')->limit(10000)->select()->toArray();
        $userArr = Db::name('admin_user')->where('aid', $aid)->column('un', 'id');

        $fp = fopen('php://temp', 'r+');
        // BOM 头解决 Excel 中文乱码
        fwrite($fp, "\xEF\xBB\xBF");
        fputcsv($fp, ['ID', '操作人', '操作内容', 'IP', '时间']);

        foreach ($list as $row) {
            fputcsv($fp, [
                $row['id'],
                $userArr[$row['uid']] ?? ('uid:' . $row['uid']),
                $row['remark'],
                $row['ip'],
                date('Y-m-d H:i:s', (int) $row['createtime']),
            ]);
        }

        rewind($fp);
        $csv = stream_get_contents($fp);
        fclose($fp);

        PlogService::write($aid, $uid, '导出操作日志');

        return Response::create($csv, 'html', 200)
            ->header([
                'Content-Type'        => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="plog_' . date('Ymd_His') . '.csv"',
            ]);
    }
}
