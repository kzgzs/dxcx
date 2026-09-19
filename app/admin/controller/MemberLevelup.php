<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\MemberLevelupService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 会员升级申请审核（admin 应用，JWT role=admin，aid 走 ScopeService）
 *
 * 对照参考系统 MemberLevel::applyorder/applyshenhe：
 * - 列表过滤 mid/昵称/类型升降级/时间/状态，表单内容「标题^_^值^_^类型」解析展示
 * - 通过：等级变更 + 有效期 + 奖励积分/余额/佣金 + 赠券 + 通知（单事务）
 * - 驳回：已付款按支付方式原路退款
 */
class MemberLevelup extends BaseController
{
    public function __construct(\think\App $app, protected MemberLevelupService $levelupService)
    {
        parent::__construct($app);
    }

    /**
     * 升级申请记录列表
     * GET /admin/memberLevelup/list?page&pagesize&orderid&mid&nickname&type&status&start_time&end_time&formx
     */
    public function list()
    {
        $aid = ScopeService::aidOf($this->request);

        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        $filters = [
            'orderid'    => $this->request->get('orderid', 0),
            'mid'        => $this->request->get('mid', 0),
            'nickname'   => $this->request->get('nickname', ''),
            'type'       => (string) $this->request->get('type', 'all'),
            'status'     => (string) $this->request->get('status', 'all'),
            'start_time' => $this->request->get('start_time', ''),
            'end_time'   => $this->request->get('end_time', ''),
            'formx'      => $this->request->get('formx', ''),
        ];

        return $this->success($this->levelupService->applyList($aid, $filters, $page, $pageSize));
    }

    /**
     * 审核（通过/驳回）
     * POST /admin/memberLevelup/audit {id, st(2通过|3驳回), remark}
     */
    public function audit()
    {
        $aid = ScopeService::aidOf($this->request);

        $id     = (int) $this->request->post('id', 0);
        $st     = (int) $this->request->post('st', 0);
        $remark = trim((string) $this->request->post('remark', ''));

        $result = $this->levelupService->audit($aid, $id, $st, $remark);

        return $this->success($result, 2 === $st ? '已通过审核' : '已驳回申请');
    }
}
