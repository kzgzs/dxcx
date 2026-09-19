<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Excel 读写工具（PhpSpreadsheet 封装）
 *
 * - readRows：读取首个 Sheet 为二维数组（行 => 列索引值）
 * - download：生成 .xlsx 并以附件响应下载
 */
class ExcelHelper
{
    /**
     * 读取 Excel 首个 Sheet 全部行为二维数组
     *
     * @return array<int, array<int, mixed>>
     */
    public static function readRows(string $filePath): array
    {
        if ('' === $filePath || !is_file($filePath)) {
            throw new BusinessException('文件不存在或已失效');
        }

        try {
            $spreadsheet = IOFactory::load($filePath);
            $rows        = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
        } catch (\Throwable $e) {
            throw new BusinessException('Excel 解析失败：' . $e->getMessage());
        }

        // 过滤整行全空的行
        $result = [];
        foreach ($rows as $row) {
            $hasValue = false;
            foreach ($row as $cell) {
                if (null !== $cell && '' !== trim((string) $cell)) {
                    $hasValue = true;
                    break;
                }
            }
            if ($hasValue) {
                $result[] = array_values($row);
            }
        }

        return $result;
    }

    /**
     * 生成 xlsx 附件下载响应
     *
     * @param array<int, string>             $titles 表头
     * @param array<int, array<int, mixed>>  $rows   数据行
     */
    public static function download(string $filename, array $titles, array $rows): \think\Response
    {
        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();

        foreach ($titles as $ci => $title) {
            $sheet->setCellValue([$ci + 1, 1], (string) $title);
        }

        foreach ($rows as $ri => $row) {
            foreach ($row as $ci => $cell) {
                $sheet->setCellValue([$ci + 1, $ri + 2], is_scalar($cell) ? $cell : (string) $cell);
            }
        }

        $writer = new Xlsx($spreadsheet);

        ob_start();
        $writer->save('php://output');
        $content = (string) ob_get_clean();

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return response($content, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . rawurlencode($filename) . '"',
            'Cache-Control'       => 'max-age=0',
        ]);
    }
}
