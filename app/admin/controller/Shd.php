<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\ScopeService;
use think\facade\Db;
use think\Response;

/**
 * 租户后台 - 送货单（设置读写 + 浏览器打印 HTML 页）
 *
 * ShdSet.php 三字段 shipping_pagetitle/shipping_pagenum/shipping_linenum 存 shop_sysset；
 * ShopOrder.php plshd() 输出 HTML 打印页（@media print 分页，每页行数按 shipping_pagenum 补空行）
 */
class Shd extends BaseController
{
    /**
     * 送货单设置读取
     * GET /admin/shd/set
     */
    public function set()
    {
        $aid = ScopeService::aidOf($this->request);

        $row = Db::name('shop_sysset')->where('aid', $aid)->find();

        return $this->success([
            'shipping_pagetitle' => (string) ($row['shipping_pagetitle'] ?? ''),
            'shipping_pagenum'   => (int) ($row['shipping_pagenum'] ?? 0),
            'shipping_linenum'   => (int) ($row['shipping_linenum'] ?? 0),
        ]);
    }

    /**
     * 送货单设置保存（shop_sysset 行不存在兜底 insert）
     * POST /admin/shd/set {shipping_pagetitle, shipping_pagenum, shipping_linenum}
     */
    public function saveSet()
    {
        $aid = ScopeService::aidOf($this->request);

        $data = [
            'shipping_pagetitle' => trim((string) $this->request->post('shipping_pagetitle', '')),
            'shipping_pagenum'   => max(0, (int) $this->request->post('shipping_pagenum', 0)),
            'shipping_linenum'   => max(0, (int) $this->request->post('shipping_linenum', 0)),
        ];

        $row = Db::name('shop_sysset')->where('aid', $aid)->find();

        if (empty($row)) {
            Db::name('shop_sysset')->insert(array_merge($data, ['aid' => $aid]));
        } else {
            Db::name('shop_sysset')->where('id', (int) $row['id'])->update($data);
        }

        return $this->success([], '保存成功');
    }

    /**
     * 送货单打印页（HTML，浏览器 @media print 方案）
     * GET /admin/shd/print?ids=1,2,3&isopen=1
     *
     * 每单：标题 + 单号 + 客户信息 + 明细表【序号|品名规格|数量(扣退款)|单价|金额|备注】
     *       + 金额汇总 + 留言 + 签收栏；每页 shipping_pagenum 行（不足补空行）
     */
    public function shdPrint()
    {
        $aid = ScopeService::aidOf($this->request);

        $ids    = trim((string) $this->request->get('ids', ''));
        $isopen = trim((string) $this->request->get('isopen', '1'));

        if ('' === $ids) {
            throw new BusinessException('缺少订单ID');
        }

        $orderIds = array_values(array_filter(array_map('intval', explode(',', $ids))));

        if (empty($orderIds)) {
            throw new BusinessException('缺少订单ID');
        }

        $sysset = Db::name('shop_sysset')->where('aid', $aid)->find();

        $pageTitle = trim((string) ($sysset['shipping_pagetitle'] ?? ''));
        $pageNum   = (int) ($sysset['shipping_pagenum'] ?? 0);  // 每页数据行数（0=不分页）
        $lineNum   = (int) ($sysset['shipping_linenum'] ?? 0);  // 每页表格固定行数（0=不固定）

        if ('' === $pageTitle) {
            $pageTitle = '送货单';
        }

        $pages = [];

        foreach ($orderIds as $orderId) {
            $order = ScopeService::query('shop_order', $aid)->where('id', $orderId)->find();

            if (empty($order)) {
                continue;
            }

            $pages[] = $this->buildOrderPages($order, $pageTitle, $pageNum, $lineNum);
        }

        if (empty($pages)) {
            throw new BusinessException('订单不存在');
        }

        $html = $this->renderHtml($pages, '0' === $isopen);

        return Response::create($html, 'html')->contentType('text/html; charset=utf-8');
    }

    /* ================= 内部工具 ================= */

    /**
     * 组装单订单的打印页（明细扣退款数量/金额，按每页行数切分补空行）
     *
     * @param array<string, mixed> $order
     * @return array<int, array<string, mixed>> 页数组
     */
    protected function buildOrderPages(array $order, string $pageTitle, int $pageNum, int $lineNum): array
    {
        $orderId = (int) $order['id'];

        $goodsRows = Db::name('shop_order_goods')
            ->where('orderid', $orderId)
            ->order('id', 'asc')
            ->select()
            ->toArray();

        // 商品行退款金额（已退款成功单，按商品行分摊）
        $refundMoneyByOg = Db::name('shop_refund_order_goods')
            ->alias('og')
            ->join('shop_refund_order o', 'o.id = og.refund_orderid')
            ->where('og.orderid', $orderId)
            ->where('o.refund_status', 1)
            ->group('og.ogid')
            ->column('sum(og.refund_money)', 'ogid');

        $rows     = [];
        $totalNum = 0;
        $totalMoney = 0.0;

        foreach ($goodsRows as $row) {
            $num        = (int) ($row['num'] ?? 0);
            $refundNum  = (int) ($row['refund_num'] ?? 0);
            $effectNum  = $num - $refundNum;

            if ($effectNum <= 0) {
                continue; // 全额退款行不打印
            }

            $rowMoney = round((float) ($row['totalprice'] ?? 0) - (float) ($refundMoneyByOg[$row['id']] ?? 0), 2);
            $totalNum   += $effectNum;
            $totalMoney += $rowMoney;

            $rows[] = [
                'name'  => (string) ($row['name'] ?? '') . ('' !== trim((string) ($row['ggname'] ?? '')) ? '【' . $row['ggname'] . '】' : ''),
                'num'   => $effectNum,
                'price' => (float) ($row['sell_price'] ?? 0),
                'money' => $rowMoney,
                'remark' => (string) ($row['remark'] ?? ''),
            ];
        }

        $orderMoney = round(min((float) ($order['totalprice'] ?? 0), $totalMoney), 2);

        // 汇总信息
        $summary = [
            'total_num'   => $totalNum,
            'total_money' => $orderMoney,
            'money_cn'    => $this->moneyToCn($orderMoney),
            'message'     => (string) ($order['message'] ?? ''),
        ];

        $header = [
            'title'    => $pageTitle,
            'ordernum' => (string) ($order['ordernum'] ?? ''),
            'linkman'  => (string) ($order['linkman'] ?? ''),
            'tel'      => (string) ($order['tel'] ?? ''),
            'address'  => trim(((string) ($order['area'] ?? '')) . ' ' . ((string) ($order['address'] ?? ''))),
            'remark'   => (string) ($order['remark'] ?? ''),
        ];

        // 分页：每页 pageNum 行；不足补空行至 pageNum（lineNum>0 时按 lineNum 固定行数）
        $rowsPerPage = $pageNum > 0 ? $pageNum : (count($rows) ?: 1);
        $fixedRows   = $lineNum > 0 ? max($lineNum, $rowsPerPage) : $rowsPerPage;

        $pages   = [];
        $chunks  = $pageNum > 0 ? array_chunk($rows, $rowsPerPage) : [$rows];

        foreach ($chunks as $index => $chunk) {
            // 补空行
            $padTarget = max($fixedRows, count($chunk));
            while (count($chunk) < $padTarget) {
                $chunk[] = null;
            }

            $pages[] = [
                'header'    => $header,
                'rows'      => $chunk,
                'page_index' => $index + 1,
                'is_last'   => false,
                'summary'   => $summary,
            ];
        }

        if (!empty($pages)) {
            $pages[count($pages) - 1]['is_last'] = true;
        }

        // 总页数回填
        $pageCount = count($pages);
        foreach ($pages as &$page) {
            $page['page_count'] = $pageCount;
        }
        unset($page);

        return $pages;
    }

    /**
     * 渲染完整 HTML 打印页
     *
     * @param array<int, array<int, array<string, mixed>>> $orderPages 每订单的页数组
     */
    protected function renderHtml(array $orderPages, bool $noAutoPrint): string
    {
        $body = '';

        foreach ($orderPages as $pages) {
            foreach ($pages as $page) {
                $body .= $this->renderPage($page);
            }
        }

        $printScript = $noAutoPrint ? '' : '<script>window.onload=function(){setTimeout(function(){window.print();},300);};</script>';

        return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><title>送货单打印</title>'
            . '<style>'
            . 'body{margin:0;padding:0;color:#000;font-family:"Microsoft YaHei",sans-serif;font-size:13px;}'
            . '.shd-page{width:100%;padding:24px 32px;box-sizing:border-box;page-break-after:always;}'
            . '.shd-page:last-child{page-break-after:auto;}'
            . '.shd-title{text-align:center;font-size:22px;font-weight:700;margin:0 0 10px;letter-spacing:6px;}'
            . '.shd-meta{display:flex;justify-content:space-between;margin-bottom:8px;font-size:13px;}'
            . '.shd-customer{border:1px solid #000;border-bottom:none;padding:6px 10px;line-height:1.8;}'
            . '.shd-customer span{margin-right:24px;}'
            . 'table.shd-table{width:100%;border-collapse:collapse;border:1px solid #000;}'
            . '.shd-table th,.shd-table td{border:1px solid #000;padding:5px 8px;text-align:center;min-height:26px;}'
            . '.shd-table th{background:#f0f0f0;font-weight:600;}'
            . '.shd-table td.name{text-align:left;}'
            . '.shd-table tr.empty td{height:26px;}'
            . '.shd-summary{border:1px solid #000;border-top:none;padding:8px 10px;line-height:2;}'
            . '.shd-sign{display:flex;justify-content:space-between;margin-top:24px;padding:0 10px;}'
            . '@media print{body{-webkit-print-color-adjust:exact;}.no-print{display:none;}.shd-page{page-break-after:always;}}'
            . '</style></head><body>'
            . '<div class="no-print" style="padding:8px 32px;background:#fffbe6;border-bottom:1px solid #eee;">'
            . '打印提示：浏览器打印对话框中请选择「无边距」或「最小边距」，缩放选「默认」。'
            . '<button onclick="window.print()" style="margin-left:16px;">打印</button></div>'
            . $body
            . $printScript
            . '</body></html>';
    }

    /**
     * 渲染单页
     *
     * @param array<string, mixed> $page
     */
    protected function renderPage(array $page): string
    {
        $h = $page['header'];

        $html = '<div class="shd-page">';
        $html .= '<div class="shd-title">' . $this->e($h['title']) . '</div>';
        $html .= '<div class="shd-meta">'
            . '<span>订单号：' . $this->e($h['ordernum']) . '</span>'
            . '<span>第 ' . (int) $page['page_index'] . ' 页 / 共 ' . (int) $page['page_count'] . ' 页</span>'
            . '<span>打印日期：' . date('Y-m-d') . '</span>'
            . '</div>';

        $html .= '<div class="shd-customer">'
            . '<span>客户：' . $this->e($h['linkman']) . '</span>'
            . '<span>电话：' . $this->e($h['tel']) . '</span>'
            . '<span>地址：' . $this->e($h['address']) . '</span>'
            . '</div>';

        $html .= '<table class="shd-table"><thead><tr>'
            . '<th style="width:44px;">序号</th><th>品名规格</th><th style="width:64px;">数量</th>'
            . '<th style="width:80px;">单价</th><th style="width:90px;">金额</th><th style="width:120px;">备注</th>'
            . '</tr></thead><tbody>';

        $realSeq = 0;

        foreach ($page['rows'] as $row) {
            if (null === $row) {
                $html .= '<tr class="empty"><td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td></tr>';
                continue;
            }

            ++$realSeq;
            $html .= '<tr>'
                . '<td>' . $realSeq . '</td>'
                . '<td class="name">' . $this->e($row['name']) . '</td>'
                . '<td>' . (int) $row['num'] . '</td>'
                . '<td>' . number_format((float) $row['price'], 2) . '</td>'
                . '<td>' . number_format((float) $row['money'], 2) . '</td>'
                . '<td>' . $this->e($row['remark']) . '</td>'
                . '</tr>';
        }

        $html .= '</tbody></table>';

        // 末页：金额汇总 + 留言 + 签收栏
        if (!empty($page['is_last'])) {
            $s = $page['summary'];

            $html .= '<div class="shd-summary">'
                . '<div>合计数量：<b>' . (int) $s['total_num'] . '</b>　　合计金额：<b>￥' . number_format((float) $s['total_money'], 2) . '</b></div>'
                . '<div>人民币大写：<b>' . $this->e($s['money_cn']) . '</b></div>'
                . '<div>买家留言：' . $this->e($s['message']) . '</div>'
                . ('' !== trim((string) ($h['remark'] ?? '')) ? '<div>商家备注：' . $this->e($h['remark']) . '</div>' : '')
                . '</div>';

            $html .= '<div class="shd-sign">'
                . '<span>收货人签字：______________</span>'
                . '<span>送货人签字：______________</span>'
                . '<span>日期：______年____月____日</span>'
                . '</div>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * HTML 转义
     */
    protected function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * 金额转人民币大写
     */
    protected function moneyToCn(float $money): string
    {
        $money  = round(abs($money), 2);
        $digits = ['零', '壹', '贰', '叁', '肆', '伍', '陆', '柒', '捌', '玖'];
        $iunits = ['', '拾', '佰', '仟'];
        $gunits = ['', '万', '亿', '万亿'];

        $intPart  = (int) floor($money);
        $decCents = (int) round(($money - $intPart) * 100);

        if (0 === $intPart) {
            $intStr = '零';
        } else {
            $groups = [];

            while ($intPart > 0) {
                $groups[] = $intPart % 10000;
                $intPart  = intdiv($intPart, 10000);
            }

            $parts = [];

            foreach ($groups as $gi => $group) {
                if (0 === $group) {
                    continue;
                }

                $gn   = (string) $group;
                $len  = strlen($gn);
                $seg  = '';
                $zero = false;

                for ($i = 0; $i < $len; ++$i) {
                    $d   = (int) $gn[$i];
                    $pos = $len - $i - 1;

                    if (0 === $d) {
                        $zero = true;
                        continue;
                    }

                    if ($zero) {
                        $seg .= '零';
                        $zero = false;
                    }

                    $seg .= $digits[$d] . $iunits[$pos];
                }

                $parts[] = $seg . $gunits[$gi];
            }

            $intStr = implode('', array_reverse($parts));
        }

        $result = $intStr . '元';
        $jiao   = intdiv($decCents, 10);
        $fen    = $decCents % 10;

        if (0 === $jiao && 0 === $fen) {
            $result .= '整';
        } else {
            if ($jiao > 0) {
                $result .= $digits[$jiao] . '角';
            } elseif ($fen > 0) {
                $result .= '零';
            }

            if ($fen > 0) {
                $result .= $digits[$fen] . '分';
            }
        }

        return $result;
    }
}
