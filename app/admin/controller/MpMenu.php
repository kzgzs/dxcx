<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\MpService;
use app\common\service\PlogService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 公众号自定义菜单（批次7-2 任务210）
 *
 * 对标 canzhao\app\controller\Mpmenu.php：
 * - index 读取 mp_menu.menudata 编辑数据
 * - save 保存编辑数据（不同步）
 * - sync 同步到微信（menuCreate：click 文本写 mp_menukey、素材类换 media_id；空菜单 menuDelete）
 * - fetch 获取线上菜单（get_current_selfmenu_info）回填落库
 * - uploadMedia 菜单/回复素材文件上传（image/voice/video）
 * - newsList 图文素材列表（news 类选择器）
 */
class MpMenu extends BaseController
{
    public function __construct(\think\App $app, protected MpService $mp)
    {
        parent::__construct($app);
    }

    /**
     * 菜单编辑数据
     * GET /admin/mp/menu
     */
    public function index()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success([
            'menudata' => $this->mp->getMenu($aid),
            'appid'    => $this->mp->resolveAppid($aid),
        ]);
    }

    /**
     * 保存菜单编辑数据
     * POST /admin/mp/menu/save {menudata: [...]}
     */
    public function save()
    {
        $aid      = ScopeService::aidOf($this->request);
        $menudata = $this->request->post('menudata/a', []);

        $this->mp->saveMenu($aid, is_array($menudata) ? $menudata : []);
        PlogService::write($aid, (int) ($this->request->authUid ?? 0), '公众号菜单保存');

        return $this->success([], '保存成功');
    }

    /**
     * 同步菜单到微信
     * POST /admin/mp/menu/sync
     */
    public function sync()
    {
        $aid = ScopeService::aidOf($this->request);

        $rs = $this->mp->syncMenu($aid);
        PlogService::write($aid, (int) ($this->request->authUid ?? 0), '公众号菜单发布');

        return $this->success($rs, 'delete' === $rs['action'] ? '线上菜单已删除' : '发布成功');
    }

    /**
     * 获取线上菜单回填
     * POST /admin/mp/menu/fetch
     */
    public function fetch()
    {
        $aid = ScopeService::aidOf($this->request);

        $menudata = $this->mp->fetchOnlineMenu($aid);
        PlogService::write($aid, (int) ($this->request->authUid ?? 0), '获取线上公众号菜单');

        return $this->success(['menudata' => $menudata], '已获取线上菜单');
    }

    /**
     * 素材文件上传（image/voice/video）
     * POST /admin/mp/media/upload（multipart：file + type）
     */
    public function uploadMedia()
    {
        $aid  = ScopeService::aidOf($this->request);
        $file = $this->request->file('file');
        if (null === $file) {
            return $this->error('请选择要上传的素材文件');
        }
        $type = (string) $this->request->post('type', 'image');

        return $this->success($this->mp->uploadMediaFile($file, $aid, $type));
    }

    /**
     * 图文素材列表（news 选择器）
     * GET /admin/mp/material/newslist?page=&limit=
     */
    public function newsList()
    {
        $aid   = ScopeService::aidOf($this->request);
        $page  = (int) $this->request->param('page', 1);
        $limit = (int) $this->request->param('limit', 20);

        $rs = $this->mp->newsList($aid, $page, $limit);

        return $this->success($rs);
    }
}
