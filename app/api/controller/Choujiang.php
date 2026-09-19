<?php

declare(strict_types=1);

namespace app\api\controller;

use app\common\service\LotteryService;

/**
 * 客户端 - 抽奖（api 应用，JWT role=member，aid/mid 取自 token）
 */
class Choujiang extends Base
{
    public function __construct(\think\App $app, protected LotteryService $lotteryService)
    {
        parent::__construct($app);
    }

    /**
     * 抽奖活动详情（含奖品）
     * GET /api/choujiang/detail?hid=
     */
    public function detail()
    {
        [$aid] = $this->memberContext();

        $hid = (int) $this->request->get('hid', 0);

        return $this->success($this->lotteryService->detail($aid, $hid));
    }

    /**
     * 剩余抽奖次数（含分享加次数后的额度）
     * GET /api/choujiang/leftTimes?hid=
     */
    public function leftTimes()
    {
        [$aid, $mid] = $this->memberContext();

        $hid = (int) $this->request->get('hid', 0);

        return $this->success($this->lotteryService->leftTimes($aid, $hid, $mid));
    }

    /**
     * 抽奖动作（次数校验 + 剩余数量加权随机 + 条件更新防超发）
     * POST /api/choujiang/draw {hid,nickname,headimg}
     */
    public function draw()
    {
        [$aid, $mid] = $this->memberContext();

        $hid      = (int) $this->request->post('hid', 0);
        $nickname = trim((string) $this->request->post('nickname', ''));
        $headimg  = trim((string) $this->request->post('headimg', ''));

        return $this->success($this->lotteryService->draw($aid, $hid, $mid, $nickname, $headimg));
    }

    /**
     * 分享加次数
     * POST /api/choujiang/share {hid}
     */
    public function share()
    {
        [$aid, $mid] = $this->memberContext();

        $hid = (int) $this->request->post('hid', 0);

        return $this->success($this->lotteryService->shareAddTimes($aid, $hid, $mid), '分享成功');
    }

    /**
     * 我的中奖记录
     * GET /api/choujiang/records?hid=&page=&pagesize=
     */
    public function records()
    {
        [$aid, $mid] = $this->memberContext();

        $hid      = (int) $this->request->get('hid', 0);
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        $filters = ['mid' => $mid];
        if ($hid > 0) {
            $filters['hid'] = $hid;
        }

        return $this->success($this->lotteryService->recordList($aid, $page, $pageSize, $filters));
    }

    /**
     * 领奖（填写/确认领奖信息）
     * POST /api/choujiang/claim {record_id}
     */
    public function claim()
    {
        [$aid, $mid] = $this->memberContext();

        $recordId = (int) $this->request->post('record_id', 0);

        return $this->success($this->lotteryService->claim($aid, $mid, $recordId), '领奖成功');
    }
}
