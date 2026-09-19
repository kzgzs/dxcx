<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\CodeListService;
use app\common\service\ExcelHelper;
use app\common\service\ScopeService;

/**
 * 租户后台 - 商城商品卡密（对标参考系统 ShopCode：列表/导入/生成/导出/删除）
 */
class ShopCode extends BaseController
{
    public function __construct(\think\App $app, protected CodeListService $codeListService)
    {
        parent::__construct($app);
    }

    /**
     * 卡密列表（分页筛选）
     * GET /admin/shopCode/list?page&pagesize&proid&content&ordernum&nickname&status(0/1/空)
     */
    public function list()
    {
        $status = $this->request->param('status', '');

        return $this->success($this->codeListService->list(
            $this->aid(),
            max(1, (int) $this->request->param('page', 1)),
            max(1, min(100, (int) $this->request->param('pagesize', 20))),
            (int) $this->request->param('proid', 0),
            (string) $this->request->param('content', ''),
            (string) $this->request->param('ordernum', ''),
            (string) $this->request->param('nickname', ''),
            '' === $status || null === $status ? '' : (string) $status
        ));
    }

    /**
     * Excel 导入卡密（multipart 字段 file，首列为卡密内容）
     * POST /admin/shopCode/import {proid, file}
     */
    public function importexcel()
    {
        $proid = (int) $this->request->post('proid', 0);
        $file  = $this->request->file('file');

        if (empty($file)) {
            return $this->error('请上传 Excel 文件');
        }

        $extension = strtolower($file->getOriginalExtension());
        if (!in_array($extension, ['xlsx', 'xls', 'csv'], true)) {
            return $this->error('仅支持 xlsx/xls/csv 格式');
        }

        $result = $this->codeListService->importExcel($this->aid(), $proid, $file->getPathname());

        $msg = '成功新增' . $result['inserted'] . '条数据';
        if ($result['duplicated'] > 0) {
            $msg .= '，重复' . $result['duplicated'] . '条数据';
        }

        return $this->success($result, $msg);
    }

    /**
     * 批量生成卡密
     * POST /admin/shopCode/make {proid, makecount, codelength, codetype}
     */
    public function makecode()
    {
        $data = $this->request->post();
        $data = is_array($data) ? $data : [];

        $result = $this->codeListService->makeCode(
            $this->aid(),
            (int) ($data['proid'] ?? 0),
            (int) ($data['makecount'] ?? 0),
            (int) ($data['codelength'] ?? 0),
            (int) ($data['codetype'] ?? 5)
        );

        return $this->success($result, '成功生成' . $result['created'] . '条数据');
    }

    /**
     * 卡密导出（xlsx 下载）
     * GET /admin/shopCode/export?proid=
     */
    public function exportexcel()
    {
        $proid = (int) $this->request->param('proid', 0);

        $rows = $this->codeListService->exportRows($this->aid(), $proid);

        return ExcelHelper::download(
            '商品卡密_' . date('YmdHis') . '.xlsx',
            ['序号', '卡密', '订单号', '昵称', '购买时间', '状态'],
            $rows
        );
    }

    /**
     * 批量删除卡密（仅未售出可删）
     * POST /admin/shopCode/delete {ids:[...]}
     */
    public function delete()
    {
        $ids = $this->request->post('ids', []);

        if (!is_array($ids)) {
            $ids = [];
        }

        return $this->success($this->codeListService->delete($this->aid(), $ids), '删除成功');
    }

    /**
     * 当前租户 ID（JWT 注入，不可伪造）
     */
    protected function aid(): int
    {
        return ScopeService::aidOf($this->request);
    }
}
