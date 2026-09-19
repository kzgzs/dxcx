<?php

declare(strict_types=1);

namespace app\api\controller;

use app\common\service\KoulingService;
use app\common\service\ScopeService;

/**
 * 客户端 - 口令/谜语游戏（api 应用，JWT role=member）
 */
class Kouling extends Base
{
    public function __construct(\think\App $app, protected KoulingService $service)
    {
        parent::__construct($app);
    }

    /**
     * 口令页面设置（标题/背景图/规则）
     * GET /api/kouling/set
     */
    public function set()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->service->getSet($aid));
    }

    /**
     * 口令兑换（精确匹配 → 校验 → 发券 + 记录）
     * POST /api/kouling/exchange {kouling}
     */
    public function exchange()
    {
        [$aid, $mid] = $this->memberContext();

        $kouling = trim((string) $this->request->post('kouling', ''));

        return $this->success($this->service->exchange($aid, $mid, $kouling), '兑换成功');
    }

    /**
     * 谜语详情（谜面展示，不下发谜底）
     * GET /api/kouling/riddle?id=
     */
    public function riddle()
    {
        $aid = ScopeService::aidOf($this->request);

        $id = (int) $this->request->get('id', 0);

        return $this->success($this->service->riddleDetail($aid, $id));
    }

    /**
     * 谜语提交（谜底精确匹配 → 校验 → 发奖 + 记录）
     * POST /api/kouling/riddleSubmit {id,kouling}
     */
    public function riddleSubmit()
    {
        [$aid, $mid] = $this->memberContext();

        $id     = (int) $this->request->post('id', 0);
        $answer = trim((string) $this->request->post('kouling', ''));

        return $this->success($this->service->riddleSubmit($aid, $mid, $id, $answer), '竞猜成功');
    }
}
