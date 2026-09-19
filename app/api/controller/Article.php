<?php

declare(strict_types=1);

namespace app\api\controller;

use app\common\exception\BusinessException;
use app\common\service\ArticleService;
use app\common\service\JwtService;
use app\common\service\ScopeService;

/**
 * C 端 - 文章模块（列表/详情/评论/点赞/打赏/分享积分/附件）
 *
 * getartlist/detail 支持匿名访问（白名单见 AuthMiddleware，aid 走 TenantMiddleware）；
 * 其余接口鉴权 role=member，aid/mid 取自 token。
 */
class Article extends Base
{
    public function __construct(\think\App $app, protected ArticleService $articleService)
    {
        parent::__construct($app);
    }

    /**
     * 可选登录上下文（匿名接口用：mid 可为 0）
     *
     * @return array{0: int, 1: int} [aid, mid]
     */
    private function optionalContext(): array
    {
        $payload = (array) ($this->request->authPayload ?? []);

        // 匿名白名单接口不走鉴权中间件，此处自行解析可选 token（无效则按匿名）
        if (!$payload) {
            $authorization = (string) $this->request->header('authorization', '');
            if (str_starts_with($authorization, 'Bearer ')) {
                $decoded = JwtService::decode(trim(substr($authorization, 7)));
                if (is_array($decoded) && 'member' === (string) ($decoded['role'] ?? '')) {
                    $payload = $decoded;
                }
            }
        }

        $mid = (int) ($payload['mid'] ?? ($payload['uid'] ?? 0));
        $aid = isset($payload['aid']) ? (int) $payload['aid'] : ScopeService::aidOf($this->request);

        return [$aid, $mid];
    }

    /**
     * 文章列表（匿名可访问；首页返回分类树 clist；showtj 权限过滤）
     * GET /api/article/getartlist?pagenum&cid&keyword
     */
    public function getartlist()
    {
        [$aid, $mid] = $this->optionalContext();

        return $this->success($this->articleService->apiList(
            $aid,
            max(1, (int) $this->request->get('pagenum', 1)),
            (int) $this->request->get('cid', 0),
            (string) $this->request->get('keyword', ''),
            $mid
        ));
    }

    /**
     * 文章详情（阅读数幂等+1、点赞态、评论数、打赏信息、showtj 权限校验）
     * GET /api/article/detail?id
     */
    public function detail()
    {
        [$aid, $mid] = $this->optionalContext();

        $id = (int) $this->request->get('id', 0);
        if ($id <= 0) {
            throw new BusinessException('缺少文章ID');
        }

        return $this->success($this->articleService->apiDetail($aid, $id, $mid));
    }

    /**
     * 评论列表（含回评与点赞态）
     * GET /api/article/getpllist?sid&pagenum
     */
    public function getpllist()
    {
        [$aid, $mid] = $this->memberContext();

        return $this->success($this->articleService->apiPllist(
            $aid,
            (int) $this->request->get('sid', 0),
            max(1, (int) $this->request->get('pagenum', 1)),
            $mid
        ));
    }

    /**
     * 文章点赞/取消点赞（切换）
     * POST /api/article/zan {id}
     */
    public function zan()
    {
        [$aid, $mid] = $this->memberContext();

        return $this->success($this->articleService->apiZan($aid, $mid, (int) $this->request->post('id', 0)));
    }

    /**
     * 提交评论/回评（pinglun_check=1 待审核，否则直显）
     * POST /api/article/subpinglun {id, type:0评论|1回评, hfid?, content}
     */
    public function subpinglun()
    {
        [$aid, $mid] = $this->memberContext();

        return $this->success(
            $this->articleService->apiSubpinglun(
                $aid,
                $mid,
                is_array($this->request->post()) ? $this->request->post() : []
            ),
            '提交成功'
        );
    }

    /**
     * 评论点赞/取消点赞（切换）
     * POST /api/article/pzan {id}
     */
    public function pzan()
    {
        [$aid, $mid] = $this->memberContext();

        return $this->success($this->articleService->apiPzan($aid, $mid, (int) $this->request->post('id', 0)));
    }

    /**
     * 打赏（type=1 现金生成待支付订单；type=2 积分即时转移）
     * POST /api/article/reward {id, type, num}
     */
    public function reward()
    {
        [$aid, $mid] = $this->memberContext();

        return $this->success(
            $this->articleService->apiReward(
                $aid,
                $mid,
                (int) $this->request->post('id', 0),
                (int) $this->request->post('type', 0),
                $this->request->post('num', 0)
            ),
            '打赏成功'
        );
    }

    /**
     * 分享文章领积分（每日上限 share_score_max_perday）
     * POST /api/article/giveScorenum
     */
    public function giveScorenum()
    {
        [$aid, $mid] = $this->memberContext();

        return $this->success($this->articleService->apiGiveScorenum($aid, $mid));
    }

    /**
     * 收藏附件资源（把文章附件存入我的收藏）
     * POST /api/article/saveResource {id}
     */
    public function saveResource()
    {
        [$aid, $mid] = $this->memberContext();

        return $this->success(
            $this->articleService->apiResourceSave($aid, $mid, (int) $this->request->post('id', 0)),
            '收藏成功'
        );
    }

    /**
     * 我的收藏附件列表
     * GET /api/article/getResourceList?pagenum&keyword
     */
    public function getResourceList()
    {
        [$aid, $mid] = $this->memberContext();

        return $this->success($this->articleService->apiResourceList(
            $aid,
            $mid,
            max(1, (int) $this->request->get('pagenum', 1)),
            (string) $this->request->get('keyword', '')
        ));
    }

    /**
     * 下载收藏的附件（返回附件文件信息）
     * GET /api/article/getFujian?id
     */
    public function getFujian()
    {
        [$aid, $mid] = $this->memberContext();

        return $this->success($this->articleService->apiGetFujian(
            $aid,
            $mid,
            (int) $this->request->get('id', 0)
        ));
    }
}
