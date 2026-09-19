<?php

declare(strict_types=1);

namespace app\api\controller;

use app\common\service\LuntanService;

/**
 * 客户端 - 用户论坛（api 应用，JWT role=member，aid/mid 取自 token）
 *
 * 覆盖：首页/列表/详情/发帖状态机/付费置顶/点赞/评论回评/作者自删/我的发帖/挂商品
 */
class Luntan extends Base
{
    public function __construct(\think\App $app, protected LuntanService $luntanService)
    {
        parent::__construct($app);
    }

    /* ================= 浏览 ================= */

    /**
     * 论坛首页（系统设置 + 版块 + 推荐帖）
     * GET /api/luntan/index?page=
     */
    public function index()
    {
        [$aid, $mid] = $this->memberContext();

        return $this->success($this->luntanService->apiIndex($aid, $mid, max(1, (int) $this->request->get('page', 1))));
    }

    /**
     * 帖子列表（版块二级联动 + 关键词）
     * GET /api/luntan/ltlist?cid=&keyword=&page=
     */
    public function ltlist()
    {
        [$aid, $mid] = $this->memberContext();

        return $this->success($this->luntanService->apiLtlist(
            $aid,
            $mid,
            (int) $this->request->get('cid', 0),
            trim((string) $this->request->get('keyword', '')),
            max(1, (int) $this->request->get('page', 1))
        ));
    }

    /**
     * 帖子详情（阅读数+1，含评论/回评/是否已赞）
     * GET /api/luntan/detail?id=&page=
     */
    public function detail()
    {
        [$aid, $mid] = $this->memberContext();

        return $this->success($this->luntanService->apiDetail(
            $aid,
            $mid,
            (int) $this->request->get('id', 0),
            max(1, (int) $this->request->get('page', 1))
        ));
    }

    /**
     * 帖子挂载商品列表
     * GET /api/luntan/getprolist?id=
     */
    public function getprolist()
    {
        [$aid] = $this->memberContext();

        return $this->success($this->luntanService->apiGetprolist($aid, (int) $this->request->get('id', 0)));
    }

    /* ================= 发帖 ================= */

    /**
     * 发帖页表单（版块树 + 置顶套餐 + 发帖权限预判）
     * GET /api/luntan/fatieForm
     */
    public function fatieForm()
    {
        [$aid, $mid] = $this->memberContext();

        return $this->success($this->luntanService->apiFatieForm($aid, $mid));
    }

    /**
     * 版块详情（含扩展配置：发帖收积分/置顶套餐/子版块）
     * GET /api/luntan/getform?cid=
     */
    public function getform()
    {
        [$aid] = $this->memberContext();

        return $this->success($this->luntanService->apiGetform($aid, (int) $this->request->get('cid', 0)));
    }

    /**
     * 发帖（核心状态机：权限校验→审核策略→付费置顶→扣积分→赠券）
     * POST /api/luntan/fatie
     */
    public function fatie()
    {
        [$aid, $mid] = $this->memberContext();

        return $this->success($this->luntanService->apiFatie($aid, $mid, $this->request->post()));
    }

    /**
     * 置顶套餐支付（自写 payorder type=luntan_top，mock 即时入账）
     * POST /api/luntan/payTop {orderid, paytype}
     */
    public function payTop()
    {
        [$aid, $mid] = $this->memberContext();

        return $this->success($this->luntanService->apiPayTop(
            $aid,
            $mid,
            (int) $this->request->post('orderid', 0),
            trim((string) $this->request->post('paytype', 'wechat'))
        ));
    }

    /**
     * 我的发帖记录（全部状态）
     * GET /api/luntan/fatielog?page=
     */
    public function fatielog()
    {
        [$aid, $mid] = $this->memberContext();

        return $this->success($this->luntanService->apiFatielog($aid, $mid, max(1, (int) $this->request->get('page', 1))));
    }

    /* ================= 互动 ================= */

    /**
     * 帖子点赞/取消点赞（toggle）
     * POST /api/luntan/zan {id}
     */
    public function zan()
    {
        [$aid, $mid] = $this->memberContext();

        return $this->success($this->luntanService->apiZan($aid, $mid, (int) $this->request->post('id', 0)));
    }

    /**
     * 提交评论/回评（type=0 评论帖子 / 1 回复评论，pingluncheck 决定待审/直显）
     * POST /api/luntan/subpinglun {id, type, hfid, content}
     */
    public function subpinglun()
    {
        [$aid, $mid] = $this->memberContext();

        return $this->success($this->luntanService->apiSubpinglun(
            $aid,
            $mid,
            (int) $this->request->post('id', 0),
            (int) $this->request->post('type', 0),
            (int) $this->request->post('hfid', 0),
            trim((string) $this->request->post('content', ''))
        ));
    }

    /**
     * 评论点赞/取消点赞（toggle）
     * POST /api/luntan/pzan {id}
     */
    public function pzan()
    {
        [$aid, $mid] = $this->memberContext();

        return $this->success($this->luntanService->apiPzan($aid, $mid, (int) $this->request->post('id', 0)));
    }

    /* ================= 作者自删 ================= */

    /**
     * 删除帖子（仅作者本人）
     * POST /api/luntan/deltie {id}
     */
    public function deltie()
    {
        [$aid, $mid] = $this->memberContext();

        $this->luntanService->apiDeltie($aid, $mid, (int) $this->request->post('id', 0));

        return $this->success([], '删除成功');
    }

    /**
     * 删除评论（仅作者本人）
     * POST /api/luntan/delpinglun {id}
     */
    public function delpinglun()
    {
        [$aid, $mid] = $this->memberContext();

        $this->luntanService->apiDelpinglun($aid, $mid, (int) $this->request->post('id', 0));

        return $this->success([], '删除成功');
    }

    /**
     * 删除回评（仅作者本人）
     * POST /api/luntan/delplreply {id}
     */
    public function delplreply()
    {
        [$aid, $mid] = $this->memberContext();

        $this->luntanService->apiDelplreply($aid, $mid, (int) $this->request->post('id', 0));

        return $this->success([], '删除成功');
    }
}
