<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\FenhongService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 分红体系（admin 应用，任务183 批次3-C）
 *
 * 三套分红页（团队/股东/区域）共用接口：
 * - 时段预览（上月/本月/昨日/今日/全部）
 * - 结算生成（扫描未分红订单 → member_fenhonglog status=0 待发放）
 * - 待发放记录列表 + 发放审核（通过入账 / 驳回）+ 一键发放
 *
 * type 取值：fenhong（股东）/ teamfenhong（团队）/ areafenhong（区域）
 */
class Fenhong extends BaseController
{
    /** 合法分红类型 */
    private const TYPES = ['fenhong', 'teamfenhong', 'areafenhong'];

    public function __construct(\think\App $app, protected FenhongService $fenhongService)
    {
        parent::__construct($app);
    }

    /**
     * 时段预览（只读试算）
     * GET /admin/fenhong/preview?type=teamfenhong&range=2
     */
    public function preview()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->fenhongService->preview(
            $aid,
            $this->typeOf(),
            (int) $this->request->get('range', 10)
        ));
    }

    /**
     * 结算生成（三套同算：扫描 og.isfenhong=0 → 标 isfenhong=1 → 写 member_fenhonglog status=0）
     * POST /admin/fenhong/settle {range}
     */
    public function settle()
    {
        $aid = ScopeService::aidOf($this->request);
        $range = (int) $this->request->post('range', 10);
        [$start, $end] = $this->fenhongService->rangeBounds($range);

        $result = $this->fenhongService->jiesuan($aid, $start, $end);

        return $this->success($result, sprintf('结算完成：扫描订单行 %d，生成分红记录 %d 条', $result['ogcount'], $result['logs']));
    }

    /**
     * 分红记录列表（按类型 + 状态）
     * GET /admin/fenhong/loglist?type=fenhong&status=0&page=1&pagesize=10
     */
    public function loglist()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->fenhongService->logList(
            $aid,
            $this->typeOf(),
            '' === (string) $this->request->get('status', '0') ? -1 : (int) $this->request->get('status', '0'),
            (int) $this->request->get('page', 1),
            (int) $this->request->get('pagesize', 10)
        ));
    }

    /**
     * 发放审核（对标参考系统 examineSend）：st=1 通过入账 / st=2 驳回
     * POST /admin/fenhong/examinesend {id, st}
     */
    public function examinesend()
    {
        $aid = ScopeService::aidOf($this->request);
        $id  = (int) $this->request->post('id', 0);
        $st  = (int) $this->request->post('st', 0);

        if ($id <= 0) {
            throw new BusinessException('缺少记录ID');
        }

        $result = $this->fenhongService->examineSend($aid, $id, $st, (int) ($this->request->authUid ?? 0));

        return $this->success($result, $result['msg']);
    }

    /**
     * 一键发放：按类型批量审核通过全部待发放记录
     * POST /admin/fenhong/sendall {type}
     */
    public function sendall()
    {
        $aid = ScopeService::aidOf($this->request);

        $result = $this->fenhongService->sendAll($aid, $this->typeOf(), (int) ($this->request->authUid ?? 0));

        return $this->success($result, sprintf('发放完成：%d 条入账，%d 条跳过', $result['sent'], $result['skipped']));
    }

    /**
     * 解析并校验分红类型参数
     */
    private function typeOf(): string
    {
        $type = (string) $this->request->param('type', 'teamfenhong');

        if (!in_array($type, self::TYPES, true)) {
            throw new BusinessException('非法的分红类型');
        }

        return $type;
    }
}
