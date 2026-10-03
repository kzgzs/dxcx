<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\LotteryService;
use app\common\service\ScopeService;
use util\ExcelUtil;

/**
 * 租户后台 - 抽奖活动（大转盘 dzp / 刮刮卡 ggk，admin 应用，JWT role=admin，aid 走 ScopeService）
 *
 * 覆盖：活动列表/详情/保存/删除/上下架 + 中奖记录列表/领奖核销
 * 概率与防超发核心在 LotteryService（数量即权重，条件更新扣减）
 */
class Choujiang extends BaseController
{
    public function __construct(\think\App $app, protected LotteryService $lotteryService)
    {
        parent::__construct($app);
    }

    /**
     * 活动列表（type 筛选 dzp/ggk，状态/阶段/名称筛选，附参与数/中奖数）
     * GET /admin/choujiang/list?page&pagesize&type&status&phase&name
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        $filters = [
            'type'   => trim((string) $this->request->get('type', '')),
            'status' => trim((string) $this->request->get('status', '')),
            'phase'  => trim((string) $this->request->get('phase', '')),
            'name'   => trim((string) $this->request->get('name', '')),
        ];

        return $this->success($this->lotteryService->actList($aid, $page, $pageSize, $filters));
    }

    /**
     * 活动详情（编辑回显，含奖项数组与推导概率）
     * GET /admin/choujiang/detail?id
     */
    public function detail()
    {
        $aid = ScopeService::aidOf($this->request);

        $id = (int) $this->request->get('id', 0);

        return $this->success($this->lotteryService->detail($aid, $id));
    }

    /**
     * 新增/编辑活动
     * POST /admin/choujiang/save {id?, type, name, banner, starttime, endtime, guize, pertotal,
     *     perday, shareaddnum, sharedaytimes, sharetimes, 分享四件套, status, prizes:[{mc,pic,tp,sl}]}
     */
    public function save()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->lotteryService->save($aid, $this->request->post()), '保存成功');
    }

    /**
     * 删除活动（已有抽奖记录时拒绝）
     * POST /admin/choujiang/delete {id}
     */
    public function delete()
    {
        $aid = ScopeService::aidOf($this->request);

        $id = (int) $this->request->post('id', 0);

        $this->lotteryService->delete($aid, $id);

        return $this->success([], '删除成功');
    }

    /**
     * 上架/下架
     * POST /admin/choujiang/status {id,status}
     */
    public function status()
    {
        $aid = ScopeService::aidOf($this->request);

        $id     = (int) $this->request->post('id', 0);
        $status = (int) $this->request->post('status', 0);

        $this->lotteryService->setStatus($aid, $id, $status);

        return $this->success([], 1 === $status ? '已上架' : '已下架');
    }

    /**
     * 中奖记录列表（会员/奖项/领奖状态/时间筛选）
     * GET /admin/choujiang/records?page&pagesize&hid&nickname&jxmc&status(-1未中奖/0未领/1已领)&start_date&end_date
     */
    public function records()
    {
        $aid = ScopeService::aidOf($this->request);

        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        $filters = [
            'hid'        => (int) $this->request->get('hid', 0),
            'mid'        => (int) $this->request->get('mid', 0),
            'nickname'   => trim((string) $this->request->get('nickname', '')),
            'jxmc'       => trim((string) $this->request->get('jxmc', '')),
            'duijiang'   => trim((string) $this->request->get('duijiang', '')),
            'status'     => trim((string) $this->request->get('status', '')),
            'start_date' => trim((string) $this->request->get('start_date', '')),
            'end_date'   => trim((string) $this->request->get('end_date', '')),
        ];

        return $this->success($this->lotteryService->recordList($aid, $page, $pageSize, $filters));
    }

    /**
     * 删除抽奖记录（单条/批量）
     * POST /admin/choujiang/recordDelete {ids:[...]}
     */
    public function recordDelete()
    {
        $aid = ScopeService::aidOf($this->request);

        $ids = $this->request->post('ids', []);
        if (!is_array($ids)) {
            $ids = [];
        }

        $this->lotteryService->recordDelete($aid, $ids);

        return $this->success([], '删除成功');
    }

    /**
     * 领奖核销（0→1 幂等，首次核销生成核销码）
     * POST /admin/choujiang/recordSetst {id}
     */
    public function recordSetst()
    {
        $aid = ScopeService::aidOf($this->request);

        $id = (int) $this->request->post('id', 0);

        $result = $this->lotteryService->recordVerify($aid, $id);

        return $this->success($result, '核销成功，奖品已发放');
    }

    /**
     * 中奖记录导出（PhpSpreadsheet xlsx 附件下载）
     * GET /admin/choujiang/export?hid&nickname&jxmc&status&start_date&end_date
     */
    public function export()
    {
        $aid = ScopeService::aidOf($this->request);

        set_time_limit(0);
        ini_set('memory_limit', '1024M');

        $query = ScopeService::query('choujiang_record', $aid);

        $hid = (int) $this->request->get('hid', 0);
        if ($hid > 0) {
            $query->where('hid', $hid);
        }
        $mid = (int) $this->request->get('mid', 0);
        if ($mid > 0) {
            $query->where('mid', $mid);
        }
        $nickname = trim((string) $this->request->get('nickname', ''));
        if ('' !== $nickname) {
            $query->whereLike('nickname', '%' . $nickname . '%');
        }
        $jxmc = trim((string) $this->request->get('jxmc', ''));
        if ('' !== $jxmc) {
            $query->whereLike('jxmc', '%' . $jxmc . '%');
        }
        $duijiang = trim((string) $this->request->get('duijiang', ''));
        if ('' !== $duijiang) {
            $query->where(function ($q) use ($duijiang) {
                $q->whereLike('linkman', '%' . $duijiang . '%')
                    ->whereOr('tel', 'like', '%' . $duijiang . '%')
                    ->whereOr('code', 'like', '%' . $duijiang . '%');
            });
        }
        $status = trim((string) $this->request->get('status', ''));
        if ('' !== $status) {
            $status = (int) $status;
            if (-1 === $status) {
                $query->where('jx', 0);
            } else {
                $query->where('status', $status ? 1 : 0)->where('jx', '>', 0);
            }
        }
        $startDate = trim((string) $this->request->get('start_date', ''));
        if ('' !== $startDate && false !== strtotime($startDate)) {
            $query->where('createtime', '>=', strtotime($startDate));
        }
        $endDate = trim((string) $this->request->get('end_date', ''));
        if ('' !== $endDate && false !== strtotime($endDate)) {
            $query->where('createtime', '<', strtotime($endDate) + 86400);
        }

        $rows = $query->order('id', 'desc')->limit(20000)->select()->toArray();

        $titles = ['ID', '活动ID', '会员昵称', '奖项', '奖品名称', '奖品类型', '领奖状态', '核销码', '抽奖时间'];
        $excelRows = [];
        foreach ($rows as $r) {
            $jx = (int) ($r['jx'] ?? 0);
            $excelRows[] = [
                (int) $r['id'],
                (int) ($r['hid'] ?? 0),
                $r['nickname'] ?? '',
                $jx === 0 ? '未中奖' : '奖项' . $jx,
                $r['jxmc'] ?? '',
                ($r['jxtp'] ?? 1) == 2 ? '红包' : '实物',
                $jx === 0 ? '未中奖' : ((int) ($r['status'] ?? 0) === 1 ? '已领取' : '未领取'),
                $r['code'] ?? '',
                !empty($r['createtime']) ? date('Y-m-d H:i:s', (int) $r['createtime']) : '',
            ];
        }

        ExcelUtil::export($titles, $excelRows, '中奖记录_' . date('YmdHis'));
    }
}
