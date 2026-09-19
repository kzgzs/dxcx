<?php

declare(strict_types=1);

namespace app\api\controller;

use app\common\service\ShortvideoService;

/**
 * 客户端 - 短视频（api 应用，JWT role=member，aid/mid 取自 token）
 *
 * 对照参考系统 ApiShortvideo.php：列表/详情/播放量/评论/点赞/分享/挂商品/用户投稿与我的投稿
 */
class Shortvideo extends Base
{
    public function __construct(\think\App $app, protected ShortvideoService $shortvideoService)
    {
        parent::__construct($app);
    }

    /**
     * 视频列表（page=1 附分类与系统设置；show_business_video=0 仅自营）
     * GET /api/shortvideo/index?bid&cid&keyword&page
     */
    public function index()
    {
        [$aid] = $this->memberContext();

        return $this->success($this->shortvideoService->apiIndex(
            $aid,
            (int) $this->request->get('bid', 0),
            (int) $this->request->get('cid', 0),
            trim((string) $this->request->get('keyword', '')),
            max(1, (int) $this->request->get('page', 1))
        ));
    }

    /**
     * 视频详情（列表循环置顶；iszan/commentnum/挂商品摘要；浏览数自增）
     * GET /api/shortvideo/detail?id=&cid=
     */
    public function detail()
    {
        [$aid, $mid] = $this->memberContext();

        return $this->success($this->shortvideoService->apiDetail(
            $aid,
            $mid,
            (int) $this->request->get('id', 0),
            (int) $this->request->get('cid', 0)
        ));
    }

    /**
     * 播放量累加
     * POST /api/shortvideo/updateviewnum {id}
     */
    public function updateviewnum()
    {
        [$aid] = $this->memberContext();

        $this->shortvideoService->apiUpdateViewNum($aid, (int) $this->request->post('id', 0));

        return $this->success([], 'ok');
    }

    /**
     * 评论列表（每页20，附回复与是否已赞）
     * GET /api/shortvideo/getcommentlist?id=&page=
     */
    public function getcommentlist()
    {
        [$aid, $mid] = $this->memberContext();

        return $this->success($this->shortvideoService->apiCommentList(
            $aid,
            $mid,
            (int) $this->request->get('id', 0),
            max(1, (int) $this->request->get('page', 1))
        ));
    }

    /**
     * 视频点赞/取消（一人一赞 toggle，计数冗余回写）
     * POST /api/shortvideo/zan {id}
     */
    public function zan()
    {
        [$aid, $mid] = $this->memberContext();

        return $this->success($this->shortvideoService->apiZan($aid, $mid, (int) $this->request->post('id', 0)));
    }

    /**
     * 发表评论/回复（评论开关+审核开关；hfid>0 写回复表）
     * POST /api/shortvideo/subpinglun {id,hfid,content}
     */
    public function subpinglun()
    {
        [$aid, $mid] = $this->memberContext();

        $result = $this->shortvideoService->apiSubpinglun(
            $aid,
            $mid,
            (int) $this->request->post('id', 0),
            (int) $this->request->post('hfid', 0),
            trim((string) $this->request->post('content', ''))
        );

        return $this->success($result, (string) ($result['msg'] ?? '发表成功'));
    }

    /**
     * 评论点赞/取消
     * POST /api/shortvideo/pzan {id}
     */
    public function pzan()
    {
        [$aid, $mid] = $this->memberContext();

        return $this->success($this->shortvideoService->apiPzan($aid, $mid, (int) $this->request->post('id', 0)));
    }

    /**
     * 分享数累加
     * POST /api/shortvideo/addsharenum {id}
     */
    public function addsharenum()
    {
        [$aid] = $this->memberContext();

        $this->shortvideoService->apiAddShareNum($aid, (int) $this->request->post('id', 0));

        return $this->success([], 'ok');
    }

    /**
     * 视频挂的商品列表
     * GET /api/shortvideo/getprolist?id=
     */
    public function getprolist()
    {
        [$aid] = $this->memberContext();

        return $this->success($this->shortvideoService->apiGetProList($aid, (int) $this->request->get('id', 0)));
    }

    /**
     * 用户投稿（GET=投稿配置 can_upload/分类；POST=提交视频，upload_check=1 待审）
     * GET /api/shortvideo/uploadvideo?bid=
     * POST /api/shortvideo/uploadvideo {name,url,coverimg,cid,...}
     */
    public function uploadvideo()
    {
        [$aid, $mid] = $this->memberContext();

        if ($this->request->isPost()) {
            $result = $this->shortvideoService->apiUploadVideo(
                $aid,
                $mid,
                (int) $this->request->post('bid', 0),
                $this->request->post()
            );

            return $this->success($result, (string) ($result['msg'] ?? '投稿成功'));
        }

        return $this->success($this->shortvideoService->apiUploadConfig(
            $aid,
            (int) $this->request->get('bid', 0)
        ));
    }

    /**
     * 我的投稿列表（st 状态筛选+各状态计数）
     * GET /api/shortvideo/myupload?st&keyword&page
     */
    public function myupload()
    {
        [$aid, $mid] = $this->memberContext();

        return $this->success($this->shortvideoService->apiMyUpload(
            $aid,
            $mid,
            trim((string) $this->request->get('st', '')),
            trim((string) $this->request->get('keyword', '')),
            max(1, (int) $this->request->get('page', 1))
        ));
    }

    /**
     * 我的投稿上下架（仅本人）
     * POST /api/shortvideo/myuploadsetst {id,status}
     */
    public function myuploadsetst()
    {
        [$aid, $mid] = $this->memberContext();

        $this->shortvideoService->apiMyUploadSetst(
            $aid,
            $mid,
            (int) $this->request->post('id', 0),
            (int) $this->request->post('status', 1)
        );

        return $this->success([], '操作成功');
    }

    /**
     * 我的投稿删除（仅本人）
     * POST /api/shortvideo/myuploaddel {id}
     */
    public function myuploaddel()
    {
        [$aid, $mid] = $this->memberContext();

        $this->shortvideoService->apiMyUploadDel($aid, $mid, (int) $this->request->post('id', 0));

        return $this->success([], '删除成功');
    }

    /**
     * 播放结束（奖励/续播占位，对齐参考系统返回结构）
     * POST /api/shortvideo/playEnd {id}
     */
    public function playEnd()
    {
        [$aid] = $this->memberContext();

        return $this->success($this->shortvideoService->apiPlayEnd($aid, (int) $this->request->post('id', 0)));
    }
}
