<?php

declare(strict_types=1);

namespace app\api\controller;

use app\common\service\FifaService;

/**
 * 客户端 - Fifa 世界杯竞猜（api 应用，JWT role=member）
 */
class Fifa extends Base
{
    public function __construct(\think\App $app, protected FifaService $service)
    {
        parent::__construct($app);
    }

    /**
     * 竞猜首页（按日期分组赛事 + 我的战绩）
     * GET /api/fifa/index
     */
    public function index()
    {
        [$aid, $mid] = $this->memberContext();

        return $this->success($this->service->apiIndex($aid, $mid));
    }

    /**
     * 场次详情（含我的竞猜与竞猜状态）
     * GET /api/fifa/detail?id=
     */
    public function detail()
    {
        [$aid, $mid] = $this->memberContext();

        $hid = (int) $this->request->get('id', 0);

        return $this->success($this->service->apiDetail($aid, $mid, $hid));
    }

    /**
     * 提交竞猜（guesstype 1猜胜负 2猜比分）
     * POST /api/fifa/subguess {hid,guesstype,guess}
     */
    public function subguess()
    {
        [$aid, $mid] = $this->memberContext();

        $hid       = (int) $this->request->post('hid', 0);
        $guesstype = (int) $this->request->post('guesstype', 0);
        $guess     = trim((string) $this->request->post('guess', ''));

        return $this->success($this->service->apiSubguess($aid, $mid, $hid, $guesstype, $guess), '竞猜成功');
    }
}
