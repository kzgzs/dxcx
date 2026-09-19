<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\ScopeService;
use think\facade\Db;

/**
 * 租户后台 - 小程序客服关键字回复（数据面）
 *
 * 对照 canzhao\app\controller\Wxkeyword.php 路数重写，落 wx_keyword 表
 * （与公众号 mp_keyword 同构：keyword/ktype/msgtype/content/sort/status）。
 * 菜单挂位由批次 7-9 统一替换 tenantMenus.ts 时接入，组件见前端 WxKeyword.vue。
 */
class WxKeyword extends BaseController
{
    /** 支持的回复类型（content 非 text 时为 JSON 串） */
    private const MSG_TYPES = ['text', 'image', 'link', 'miniprogrampage'];

    /** 匹配方式：0 全匹配 1 半匹配 2 关注回复 */
    private const K_TYPES = [0, 1, 2];

    /**
     * 列表（分页 + 关键字搜索 + ktype 筛选）
     * GET /admin/wxkeyword/index
     */
    public function index()
    {
        $aid      = ScopeService::aidOf($this->request);
        $page     = max(1, (int) $this->request->param('page', 1));
        $pagesize = min(100, max(1, (int) $this->request->param('pagesize', 10)));
        $keyword  = trim((string) $this->request->param('keyword', ''));
        $ktype    = $this->request->param('ktype', '');

        $query = ScopeService::query('wx_keyword', $aid);
        if ('' !== $keyword) {
            $query->where('keyword', 'like', '%' . $keyword . '%');
        }
        if ('' !== $ktype && in_array((int) $ktype, self::K_TYPES, true)) {
            $query->where('ktype', (int) $ktype);
        }

        $total = (clone $query)->count();
        $list  = $query->page($page, $pagesize)->order('sort', 'desc')->order('id', 'desc')->select()->toArray();

        return $this->success(['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pagesize]);
    }

    /**
     * 新增/编辑
     * POST /admin/wxkeyword/save
     */
    public function save()
    {
        $aid  = ScopeService::aidOf($this->request);
        $info = $this->request->post('info/a');
        $info = is_array($info) ? $info : [];

        $id      = (int) ($info['id'] ?? 0);
        $keyword = trim((string) ($info['keyword'] ?? ''));
        $ktype   = (int) ($info['ktype'] ?? 0);
        $msgtype = trim((string) ($info['msgtype'] ?? 'text'));
        $content = (string) ($info['content'] ?? '');
        $sort    = (int) ($info['sort'] ?? 0);
        $status  = (int) ($info['status'] ?? 1);

        if ('' === $keyword) {
            return $this->error('请输入关键字');
        }
        if (!in_array($msgtype, self::MSG_TYPES, true)) {
            return $this->error('不支持的回复类型');
        }
        if (!in_array($ktype, self::K_TYPES, true)) {
            return $this->error('匹配方式不正确');
        }
        if ('' === trim($content)) {
            return $this->error('回复内容不能为空');
        }

        $data = [
            'keyword' => $keyword,
            'ktype'   => $ktype,
            'msgtype' => $msgtype,
            'content' => $content,
            'sort'    => $sort,
            'status'  => $status,
        ];

        if ($id > 0) {
            $exists = ScopeService::query('wx_keyword', $aid)->where('id', $id)->find();
            if (empty($exists)) {
                return $this->error('记录不存在');
            }
            Db::name('wx_keyword')->where('aid', $aid)->where('id', $id)->update($data);

            return $this->success(['id' => $id], '操作成功');
        }

        $data['aid']        = $aid;
        $data['createtime'] = time();
        $newId = Db::name('wx_keyword')->insertGetId($data);

        return $this->success(['id' => (int) $newId], '操作成功');
    }

    /**
     * 删除（批量）
     * POST /admin/wxkeyword/del
     */
    public function del()
    {
        $aid = ScopeService::aidOf($this->request);
        $ids = $this->request->post('ids/a');
        $ids = array_values(array_filter(array_map('intval', is_array($ids) ? $ids : [])));
        if (empty($ids)) {
            return $this->error('请选择要删除的记录');
        }

        Db::name('wx_keyword')->where('aid', $aid)->where('id', 'in', $ids)->delete();

        return $this->success([], '删除成功');
    }
}
