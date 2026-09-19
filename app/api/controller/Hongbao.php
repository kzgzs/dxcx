<?php

declare(strict_types=1);

namespace app\api\controller;

use app\common\service\HongbaoService;
use app\common\service\ScopeService;

/**
 * 客户端 - 微信红包（api 应用，JWT role=member）
 */
class Hongbao extends Base
{
    public function __construct(\think\App $app, protected HongbaoService $service)
    {
        parent::__construct($app);
    }

    /**
     * 活动信息
     * GET /api/hongbao/detail?id=
     */
    public function detail()
    {
        $aid = ScopeService::aidOf($this->request);

        $hid = (int) $this->request->get('id', 0);

        return $this->success($this->service->detail($aid, $hid));
    }

    /**
     * 红包领取（随机金额 + 预算扣减 + 限次校验；wx_transfer mock 发放，失败落 status=2）
     * POST /api/hongbao/receive {id,cjcode}
     */
    public function receive()
    {
        [$aid, $mid] = $this->memberContext();

        $hid    = (int) $this->request->post('id', 0);
        $cjcode = trim((string) $this->request->post('cjcode', ''));

        $result = $this->service->receive($aid, $mid, $hid, $cjcode);

        return $this->success($result, $result['msg']);
    }
}
