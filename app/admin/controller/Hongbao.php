<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\ExcelHelper;
use app\common\service\HongbaoService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 微信红包（批次4-E 任务190 E1-2）
 */
class Hongbao extends BaseController
{
    public function __construct(\think\App $app, protected HongbaoService $service)
    {
        parent::__construct($app);
    }

    /**
     * 活动列表
     * GET /admin/hongbao/list?keyword=&page=&pagesize=
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        $keyword  = trim((string) $this->request->get('keyword', ''));
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->service->activityList($aid, $keyword, $page, $pageSize));
    }

    /**
     * 活动详情
     * GET /admin/hongbao/detail?id=
     */
    public function detail()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->service->activityDetail($aid, (int) $this->request->get('id', 0)));
    }

    /**
     * 活动保存（新增/编辑）
     * POST /admin/hongbao/save
     */
    public function save()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->service->saveActivity($aid, $this->request->post()), '保存成功');
    }

    /**
     * 活动删除
     * POST /admin/hongbao/delete {ids[]}
     */
    public function delete()
    {
        $aid = ScopeService::aidOf($this->request);

        $ids = array_map('intval', (array) $this->request->post('ids', []));

        if (empty($ids)) {
            throw new BusinessException('请选择活动');
        }

        $this->service->deleteActivity($aid, $ids);

        return $this->success([], '删除成功');
    }

    /**
     * 领取记录
     * GET /admin/hongbao/records?hid=&nickname=&page=&pagesize=
     */
    public function records()
    {
        $aid = ScopeService::aidOf($this->request);

        $hid      = (int) $this->request->get('hid', 0);
        $nickname = trim((string) $this->request->get('nickname', ''));
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->service->recordList($aid, $hid, $nickname, $page, $pageSize));
    }

    /**
     * 抽奖码列表
     * GET /admin/hongbao/codelist?hid=&status=&page=&pagesize=
     */
    public function codelist()
    {
        $aid = ScopeService::aidOf($this->request);

        $hid      = (int) $this->request->get('hid', 0);
        $status   = (int) $this->request->get('status', -1);
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->service->codelist($aid, $hid, $status, $page, $pageSize));
    }

    /**
     * 批量生成抽奖码
     * POST /admin/hongbao/makecode {hid,num,length}
     */
    public function makecode()
    {
        $aid = ScopeService::aidOf($this->request);

        $hid    = (int) $this->request->post('hid', 0);
        $num    = (int) $this->request->post('num', 100);
        $length = (int) $this->request->post('length', 10);

        if ($hid <= 0) {
            throw new BusinessException('请选择活动');
        }

        $result = $this->service->makeCodes($aid, $hid, $num, $length);

        return $this->success($result, '成功生成 ' . $result['num'] . ' 个抽奖码');
    }

    /**
     * 抽奖码导入（multipart 字段 file，首列为抽奖码）
     * POST /admin/hongbao/importcode
     */
    public function importcode()
    {
        $aid = ScopeService::aidOf($this->request);

        $hid  = (int) $this->request->post('hid', 0);
        $file = $this->request->file('file');

        if ($hid <= 0) {
            throw new BusinessException('请选择活动');
        }

        if (empty($file)) {
            throw new BusinessException('请上传 Excel 文件');
        }

        $extension = strtolower($file->getOriginalExtension());

        if (!in_array($extension, ['xlsx', 'xls', 'csv'], true)) {
            throw new BusinessException('仅支持 xlsx/xls/csv 格式');
        }

        $rows   = ExcelHelper::readRows($file->getPathname());
        $result = $this->service->importCodes($aid, $hid, $rows);

        return $this->success($result, '导入完成：新增 ' . $result['imported'] . ' 条，跳过 ' . $result['skipped'] . ' 条');
    }

    /**
     * 抽奖码导出
     * GET /admin/hongbao/exportcode?hid=
     */
    public function exportcode()
    {
        $aid = ScopeService::aidOf($this->request);

        $hid = (int) $this->request->get('hid', 0);

        $data = $this->service->codelistExport($aid, $hid);

        return ExcelHelper::download('红包抽奖码' . date('YmdHis'), $data['titles'], $data['rows']);
    }
}
