<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\MpService;
use app\common\service\PlogService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 公众号自动回复（批次7-2 任务210）
 *
 * 对标 canzhao\app\controller\Mpkeyword.php：
 * - index/save/del 关键字回复 CRUD（ktype 0完全匹配 1包含匹配）
 * - subscribe/subscribeSave/subscribeDel 被关注回复（ktype=2，最多3条）
 * msgtype 七种：text/image/voice/video/music/news/miniprogrampage，
 * 素材类保存时自动换取 MediaId 落 content JSON，文本类直存。
 */
class MpKeyword extends BaseController
{
    public function __construct(\think\App $app, protected MpService $mp)
    {
        parent::__construct($app);
    }

    /**
     * 关键字回复列表
     * GET /admin/mp/keyword/index?page=&limit=&keyword=
     */
    public function index()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->mp->keywordList($aid, [
            'page'    => (int) $this->request->param('page', 1),
            'limit'   => (int) $this->request->param('limit', 15),
            'keyword' => (string) $this->request->param('keyword', ''),
        ]));
    }

    /**
     * 关键字回复保存（新增/编辑）
     * POST /admin/mp/keyword/save
     */
    public function save()
    {
        $aid  = ScopeService::aidOf($this->request);
        $post = $this->request->post();
        if (!is_array($post)) {
            $post = [];
        }

        $id  = $this->mp->keywordSave($aid, $post);
        $uid = (int) ($this->request->authUid ?? 0);
        PlogService::write($aid, $uid, ((int) ($post['id'] ?? 0) > 0 ? '编辑' : '添加') . '公众号关键字回复' . $id);

        return $this->success(['id' => $id], '操作成功');
    }

    /**
     * 关键字回复删除
     * POST /admin/mp/keyword/del {ids: [...]}
     */
    public function del()
    {
        $aid = ScopeService::aidOf($this->request);
        $ids = $this->request->post('ids/a', []);

        $this->mp->keywordDelete($aid, is_array($ids) ? $ids : []);
        PlogService::write($aid, (int) ($this->request->authUid ?? 0), '删除公众号关键字回复' . implode(',', array_map('intval', (array) $ids)));

        return $this->success([], '删除成功');
    }

    /**
     * 被关注回复列表（ktype=2，最多3条）
     * GET /admin/mp/keyword/subscribe
     */
    public function subscribe()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success([
            'list'  => $this->mp->subscribeList($aid),
            'limit' => MpService::SUBSCRIBE_MAX,
        ]);
    }

    /**
     * 被关注回复保存（新增时校验3条上限）
     * POST /admin/mp/keyword/subscribeSave
     */
    public function subscribeSave()
    {
        $aid  = ScopeService::aidOf($this->request);
        $post = $this->request->post();
        if (!is_array($post)) {
            $post = [];
        }

        $id  = $this->mp->subscribeSave($aid, $post);
        $uid = (int) ($this->request->authUid ?? 0);
        PlogService::write($aid, $uid, ((int) ($post['id'] ?? 0) > 0 ? '编辑' : '添加') . '公众号被关注回复' . $id);

        return $this->success(['id' => $id], '操作成功');
    }

    /**
     * 被关注回复删除
     * POST /admin/mp/keyword/subscribeDel {ids: [...]}
     */
    public function subscribeDel()
    {
        $aid = ScopeService::aidOf($this->request);
        $ids = $this->request->post('ids/a', []);

        $this->mp->subscribeDelete($aid, is_array($ids) ? $ids : []);
        PlogService::write($aid, (int) ($this->request->authUid ?? 0), '删除公众号被关注回复' . implode(',', array_map('intval', (array) $ids)));

        return $this->success([], '删除成功');
    }
}
