<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\KechengExamService;
use app\common\service\ScopeService;
use util\ExcelUtil;

/**
 * 租户后台 - 知识付费·题库管理（批次5-D2 任务198）
 *
 * 选择题/填空题 CRUD + 批量启停用 + Excel 导入（复用 extend/util/ExcelUtil.php）与导出
 */
class KechengTiku extends BaseController
{
    public function __construct(\think\App $app, protected KechengExamService $examService)
    {
        parent::__construct($app);
    }

    /**
     * 题库列表
     * GET /admin/kechengTiku/list?page&pagesize&kcid&mlid&keyword&type&status
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->examService->tikuList(
            $aid,
            (int) $this->request->get('kcid', 0),
            (int) $this->request->get('mlid', 0),
            trim((string) $this->request->get('keyword', '')),
            trim((string) $this->request->get('type', '')),
            trim((string) $this->request->get('status', '')),
            $page,
            $pageSize
        ));
    }

    /**
     * 题库新增/编辑
     * POST /admin/kechengTiku/save
     */
    public function save()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->examService->tikuSave($aid, $this->request->post()), '保存成功');
    }

    /**
     * 题库删除（支持批量 ids）
     * POST /admin/kechengTiku/delete {id|ids}
     */
    public function delete()
    {
        $aid = ScopeService::aidOf($this->request);

        $ids = (array) $this->request->post('ids', []);
        if (empty($ids)) {
            $ids = [(int) $this->request->post('id', 0)];
        }

        $this->examService->tikuDelete($aid, array_map('intval', $ids));

        return $this->success([], '删除成功');
    }

    /**
     * 批量启停用
     * POST /admin/kechengTiku/status {ids,status}
     */
    public function status()
    {
        $aid = ScopeService::aidOf($this->request);

        $ids = array_map('intval', (array) $this->request->post('ids', []));

        $this->examService->tikuStatus($aid, $ids, (int) $this->request->post('status', 0));

        return $this->success([], '操作成功');
    }

    /**
     * 课程下章节选项（题库筛选/编辑下拉）
     * GET /admin/kechengTiku/chapters?kcid
     */
    public function chapters()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->examService->chapterOptions($aid, (int) $this->request->get('kcid', 0)));
    }

    /**
     * Excel 批量导入（multipart 字段 file；对标参考系统 importexcel）
     * POST /admin/kechengTiku/import
     */
    public function import()
    {
        $aid  = ScopeService::aidOf($this->request);
        $file = $this->request->file('file');

        if (empty($file)) {
            throw new BusinessException('请上传excel文件');
        }

        $extension = strtolower($file->getOriginalExtension());
        if (!in_array($extension, ['xlsx', 'xls', 'csv'], true)) {
            throw new BusinessException('仅支持 xlsx/xls/csv 格式的表格文件');
        }
        if ($file->getSize() > 10 * 1024 * 1024) {
            throw new BusinessException('表格文件不能超过10MB');
        }

        $dir      = app()->getRuntimePath() . 'import';
        $saveName = date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . $extension;

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $file->move($dir, $saveName);
        $filePath = $dir . DIRECTORY_SEPARATOR . $saveName;

        try {
            $excelRows = ExcelUtil::import($filePath, 1);
        } catch (\Throwable $e) {
            throw new BusinessException('表格解析失败：' . $e->getMessage());
        } finally {
            if (is_file($filePath)) {
                @unlink($filePath);
            }
        }

        if (empty($excelRows)) {
            throw new BusinessException('表格中没有有效数据');
        }

        $result = $this->examService->tikuImport($aid, $excelRows);

        return $this->success($result, sprintf('成功导入 %d 条数据', $result['success']));
    }

    /**
     * 题库导出（xlsx 下载）
     * GET /admin/kechengTiku/export?kcid&mlid
     */
    public function export()
    {
        $aid = ScopeService::aidOf($this->request);

        $rows = $this->examService->tikuExportRows(
            $aid,
            (int) $this->request->get('kcid', 0),
            (int) $this->request->get('mlid', 0)
        );

        $titles = ['课程id', '章节id', '题型', '题目', '选项A', '选项B', '选项C', '选项D', '正确答案', '分值', '解析'];

        ExcelUtil::export($titles, $rows, '题库导出_' . date('YmdHis'));
    }
}
