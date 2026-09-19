<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\LuckyCollageService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 幸运拼团管理（admin 应用，JWT role=admin，aid 走 ScopeService）
 *
 * 覆盖：系统设置 / 商品分类 / 商品 / 团管理 / 订单 / 评价 / 卡密 / 机器人(kaituan)
 * （超时失败退款由 schedule:run 挂接 LuckyCollageService::dissolveExpiredTeams）
 */
class LuckyCollage extends BaseController
{
    public function __construct(\think\App $app, protected LuckyCollageService $luckyCollageService)
    {
        parent::__construct($app);
    }

    /* ================= 系统设置 ================= */

    /**
     * GET /admin/luckyCollage/set
     */
    public function setGet()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->luckyCollageService->sysset($aid));
    }

    /**
     * POST /admin/luckyCollage/set
     */
    public function setSave()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->luckyCollageService->saveSysset($aid, $this->request->post());

        return $this->success([], '保存成功');
    }

    /* ================= 商品分类 ================= */

    /**
     * GET /admin/luckyCollage/category/list
     */
    public function categoryList()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success(['list' => $this->luckyCollageService->categoryList($aid)]);
    }

    /**
     * POST /admin/luckyCollage/category/save
     */
    public function categorySave()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->luckyCollageService->categorySave($aid, $this->request->post()), '保存成功');
    }

    /**
     * POST /admin/luckyCollage/category/delete {id}
     */
    public function categoryDelete()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->luckyCollageService->categoryDelete($aid, (int) $this->request->post('id', 0));

        return $this->success([], '删除成功');
    }

    /* ================= 商品管理 ================= */

    /**
     * GET /admin/luckyCollage/product/list?page&pagesize&keyword&status&checked
     */
    public function productList()
    {
        $aid = ScopeService::aidOf($this->request);

        $keyword  = trim((string) $this->request->get('keyword', ''));
        $status   = trim((string) $this->request->get('status', ''));
        $checked  = trim((string) $this->request->get('checked', ''));
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;
        $bid      = trim((string) $this->request->get('bid', ''));
        $cid      = (int) $this->request->get('cid', 0);
        $orderField = trim((string) $this->request->get('orderField', ''));
        $orderType  = trim((string) $this->request->get('orderType', ''));

        return $this->success($this->luckyCollageService->productList($aid, $keyword, $status, $checked, $page, $pageSize, $bid, $cid, $orderField, $orderType));
    }

    /**
     * GET /admin/luckyCollage/product/detail?id=
     */
    public function productDetail()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->luckyCollageService->productDetail($aid, (int) $this->request->get('id', 0)));
    }

    /**
     * POST /admin/luckyCollage/product/save（含开奖设置字段与规格行 options）
     */
    public function productSave()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->luckyCollageService->productSave($aid, $this->request->post()), '保存成功');
    }

    /**
     * POST /admin/luckyCollage/product/status {id,status}
     */
    public function productStatus()
    {
        $aid = ScopeService::aidOf($this->request);

        $id     = (int) $this->request->post('id', 0);
        $status = (int) $this->request->post('status', -1);

        $this->luckyCollageService->productBatchStatus($aid, $this->resolveProductIds(), $status);

        return $this->success([], 1 === $status ? '已上架' : '已下架');
    }

    /**
     * POST /admin/luckyCollage/product/check {id,ischecked,reason}
     */
    public function productCheck()
    {
        $aid = ScopeService::aidOf($this->request);

        $id        = (int) $this->request->post('id', 0);
        $ischecked = (int) $this->request->post('ischecked', 0);
        $reason    = trim((string) $this->request->post('reason', ''));

        $this->luckyCollageService->productCheck($aid, $id, $ischecked, $reason);

        return $this->success([], 1 === $ischecked ? '已通过' : '已驳回');
    }

    /**
     * POST /admin/luckyCollage/product/delete {id}
     */
    public function productDelete()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->luckyCollageService->productBatchDelete($aid, $this->resolveProductIds());

        return $this->success([], '删除成功');
    }

    /**
     * 归一化商品 ids 入参：兼容批量 ids[] 与单个 id
     *
     * @return int[]
     */
    private function resolveProductIds(): array
    {
        $ids = $this->request->post('ids');

        if (is_array($ids)) {
            return array_values(array_filter(array_map('intval', $ids)));
        }

        $id = (int) $this->request->post('id', 0);

        return $id > 0 ? [$id] : [];
    }

    /* ================= 团管理 ================= */

    /**
     * GET /admin/luckyCollage/team/list?page&pagesize&status
     */
    public function teamList()
    {
        $aid = ScopeService::aidOf($this->request);

        $status   = trim((string) $this->request->get('status', ''));
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->luckyCollageService->teamList($aid, $status, $page, $pageSize));
    }

    /**
     * POST /admin/luckyCollage/team/delete {id}
     */
    public function teamDelete()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->luckyCollageService->teamDelete($aid, (int) $this->request->post('id', 0));

        return $this->success([], '删除成功');
    }

    /* ================= 订单管理 ================= */

    /**
     * GET /admin/luckyCollage/order/list?page&pagesize&status&buytype&iszj&teamid&keyword
     */
    public function orderList()
    {
        $aid = ScopeService::aidOf($this->request);

        $status   = trim((string) $this->request->get('status', ''));
        $buytype  = trim((string) $this->request->get('buytype', ''));
        $iszj     = trim((string) $this->request->get('iszj', ''));
        $keyword  = trim((string) $this->request->get('keyword', ''));
        $teamId   = (int) $this->request->get('teamid', 0);
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->luckyCollageService->orderList($aid, $status, $buytype, $keyword, $iszj, $teamId, $page, $pageSize));
    }

    /**
     * POST /admin/luckyCollage/order/close {id}
     */
    public function orderClose()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->luckyCollageService->orderClose($aid, (int) $this->request->post('id', 0));

        return $this->success([], '已关闭');
    }

    /**
     * POST /admin/luckyCollage/order/sendExpress {id,express_com,express_no}
     */
    public function orderSendExpress()
    {
        $aid = ScopeService::aidOf($this->request);

        $id         = (int) $this->request->post('id', 0);
        $expressCom = trim((string) $this->request->post('express_com', ''));
        $expressNo  = trim((string) $this->request->post('express_no', ''));

        $this->luckyCollageService->orderSendExpress($aid, $id, $expressCom, $expressNo);

        return $this->success([], '发货成功');
    }

    /* ================= 评价管理 ================= */

    /**
     * GET /admin/luckyCollage/comment/list?page&pagesize
     */
    public function commentList()
    {
        $aid = ScopeService::aidOf($this->request);

        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->luckyCollageService->commentList($aid, $page, $pageSize));
    }

    /**
     * POST /admin/luckyCollage/comment/reply {id,content}
     */
    public function commentReply()
    {
        $aid = ScopeService::aidOf($this->request);

        $id      = (int) $this->request->post('id', 0);
        $content = trim((string) $this->request->post('content', ''));

        $this->luckyCollageService->commentReply($aid, $id, $content);

        return $this->success([], '回复成功');
    }

    /**
     * POST /admin/luckyCollage/comment/delete {id}
     */
    public function commentDelete()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->luckyCollageService->commentDelete($aid, (int) $this->request->post('id', 0));

        return $this->success([], '删除成功');
    }

    /* ================= 卡密管理 ================= */

    /**
     * GET /admin/luckyCollage/code/list?page&pagesize&proid&status
     */
    public function codeList()
    {
        $aid = ScopeService::aidOf($this->request);

        $proid    = (int) $this->request->get('proid', 0);
        $status   = trim((string) $this->request->get('status', ''));
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->luckyCollageService->codelistList($aid, $proid, $status, $page, $pageSize));
    }

    /**
     * POST /admin/luckyCollage/code/import {proid, contents: string[] 或换行分隔文本}
     */
    public function codeImport()
    {
        $aid = ScopeService::aidOf($this->request);

        $proid    = (int) $this->request->post('proid', 0);
        $contents = $this->request->post('contents', []);

        if (!is_array($contents)) {
            $contents = preg_split('/\r\n|\r|\n/', (string) $contents) ?: [];
        }

        return $this->success($this->luckyCollageService->codelistImport($aid, $proid, $contents), '导入完成');
    }

    /**
     * POST /admin/luckyCollage/code/delete {id}
     */
    public function codeDelete()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->luckyCollageService->codelistDelete($aid, (int) $this->request->post('id', 0));

        return $this->success([], '删除成功');
    }

    /* ================= 机器人管理 ================= */

    /**
     * GET /admin/luckyCollage/jiqi/list?page&pagesize
     */
    public function jiqiList()
    {
        $aid = ScopeService::aidOf($this->request);

        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->luckyCollageService->jiqiList($aid, $page, $pageSize));
    }

    /**
     * POST /admin/luckyCollage/jiqi/save {id?,nickname,headimg,status,sort}
     */
    public function jiqiSave()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->luckyCollageService->jiqiSave($aid, $this->request->post()), '保存成功');
    }

    /**
     * POST /admin/luckyCollage/jiqi/delete {id}
     */
    public function jiqiDelete()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->luckyCollageService->jiqiDelete($aid, (int) $this->request->post('id', 0));

        return $this->success([], '删除成功');
    }

    /**
     * 机器人开团/参团（凑人数，满员触发开奖）
     * POST /admin/luckyCollage/jiqi/kaituan {proid,buytype(2发团/3参团),teamid?}
     */
    public function kaituan()
    {
        $aid = ScopeService::aidOf($this->request);

        $proid   = (int) $this->request->post('proid', 0);
        $buytype = (int) $this->request->post('buytype', 2);
        $teamId  = (int) $this->request->post('teamid', 0);

        $result = $this->luckyCollageService->kaituan($aid, $proid, $buytype, $teamId);

        return $this->success($result, !empty($result['kaijiang']) ? '机器人已入团并触发开奖' : '机器人已入团');
    }
}
