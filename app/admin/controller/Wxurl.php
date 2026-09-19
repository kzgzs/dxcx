<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\ScopeService;
use think\facade\Db;

/**
 * 租户后台 - 小程序外部链接
 *
 * 对照 canzhao\app\controller\Wxurl.php 路数重写：
 * - wx_url 本地表 CRUD（name/path/url/days/endtime）
 * - code：52 进制混淆码（照抄参考系统 getcode 算法，按自增 id 生成，天然唯一）
 * - generate_urllink 保持参考系统原样仅留注释，链接走本地 code 落地跳转：
 *   /api/wxurl/go?code=xxx 反查 url 302
 */
class Wxurl extends BaseController
{
    /**
     * 列表（分页 + name/path/url 模糊搜索）
     * GET /admin/wxurl/index
     */
    public function index()
    {
        $aid      = ScopeService::aidOf($this->request);
        $page     = max(1, (int) $this->request->param('page', 1));
        $pagesize = min(100, max(1, (int) $this->request->param('pagesize', 10)));
        $name     = trim((string) $this->request->param('name', ''));
        $path     = trim((string) $this->request->param('path', ''));
        $url      = trim((string) $this->request->param('url', ''));

        $query = ScopeService::query('wx_url', $aid);
        if ('' !== $name) {
            $query->where('name', 'like', '%' . $name . '%');
        }
        if ('' !== $path) {
            $query->where('path', 'like', '%' . $path . '%');
        }
        if ('' !== $url) {
            $query->where('url', 'like', '%' . $url . '%');
        }

        $total = (clone $query)->count();
        $list  = $query->page($page, $pagesize)->order('sort', 'desc')->order('id', 'desc')->select()->toArray();

        // 附加有效期状态（前端展示用）
        $now = time();
        foreach ($list as $k => $v) {
            $list[$k]['expired'] = !empty($v['endtime']) && (int) $v['endtime'] < $now ? 1 : 0;
        }

        return $this->success(['list' => $list, 'total' => $total, 'page' => $page, 'pagesize' => $pagesize]);
    }

    /**
     * 新增/编辑
     * POST /admin/wxurl/save
     */
    public function save()
    {
        $aid  = ScopeService::aidOf($this->request);
        $info = $this->request->post('info/a');
        $info = is_array($info) ? $info : [];

        $id   = (int) ($info['id'] ?? 0);
        $name = trim((string) ($info['name'] ?? ''));
        $path = trim((string) ($info['path'] ?? ''));
        $url  = trim((string) ($info['url'] ?? ''));
        $days = (int) ($info['days'] ?? 30);

        if ('' === $name) {
            return $this->error('请输入链接名称');
        }
        if ('' === $path) {
            return $this->error('请输入小程序路径');
        }
        if ($days < 1 || $days > 365) {
            return $this->error('有效期天数须为 1~365');
        }

        if ($id > 0) {
            $oldinfo = ScopeService::query('wx_url', $aid)->where('id', $id)->find();
            if (empty($oldinfo)) {
                return $this->error('记录不存在');
            }
            // 对照参考系统：编辑以原创建时间为基准重算有效期
            Db::name('wx_url')->where('aid', $aid)->where('id', $id)->update([
                'name'    => $name,
                'path'    => $path,
                'url'     => $url,
                'days'    => $days,
                'endtime' => (int) $oldinfo['createtime'] + 86400 * $days,
            ]);

            return $this->success(['id' => $id], '操作成功');
        }

        /*
        // 微信 URL Link（对照参考系统保留注释：当前以本地 code 落地跳转代替，如需真实短链再启用）
        $url = 'https://api.weixin.qq.com/wxa/generate_urllink?access_token='.\app\common\Wechat::access_token(aid);
        $pathurl = explode('?',$info['path']);
        $postdata = [];
        $postdata['path'] = $pathurl[0];
        if($pathurl[1]){
            $postdata['query'] = $pathurl[1];
        }
        $postdata['expire_type'] = '1';
        $postdata['expire_interval'] = '30';
        $rs = curl_post($url,jsonEncode($postdata));
        $rs = json_decode($rs,true);
        if($rs['errcode']!=0){
            return json(['status'=>0,'msg'=>$rs['errcode'].'：'.$rs['errmsg']]);
        }
        $info['url'] = $rs['url_link'];
        */

        $createtime = time();
        $newId = Db::name('wx_url')->insertGetId([
            'aid'        => $aid,
            'name'       => $name,
            'path'       => $path,
            'url'        => $url,
            'days'       => $days,
            'sort'       => 0,
            'createtime' => $createtime,
            'endtime'    => $createtime + 86400 * $days,
        ]);
        // 落地短码：按 id 生成 52 进制混淆码并回写
        Db::name('wx_url')->where('id', $newId)->update(['code' => $this->getcode((int) $newId)]);

        return $this->success(['id' => (int) $newId, 'code' => $this->getcode((int) $newId)], '操作成功');
    }

    /**
     * 删除（批量）
     * POST /admin/wxurl/del
     */
    public function del()
    {
        $aid  = ScopeService::aidOf($this->request);
        $ids  = $this->request->post('ids/a');
        $ids  = array_values(array_filter(array_map('intval', is_array($ids) ? $ids : [])));
        if (empty($ids)) {
            return $this->error('请选择要删除的记录');
        }

        Db::name('wx_url')->where('aid', $aid)->where('id', 'in', $ids)->delete();

        return $this->success([], '删除成功');
    }

    /**
     * 52 进制混淆短码（照抄参考系统 Wxurl::getcode）
     * id*29+400204032 → 52进制 → 字符替换映射 → 2/6 位互换
     */
    protected function getcode(int $num): string
    {
        $num = $num * 29 + 400204032;
        $ABCstr = '';
        while ($num != 0) {
            $x = $num % 52;
            $ABCstr .= chr(65 + $x);
            $num = intval($num / 52);
        }
        $str = strrev($ABCstr);
        $fromArr = str_split('ABCDEFGHIJKLMNOPQRSTUVWXYZ[\]^_`abcdefghijklmnopqrst');
        $toArr = str_split('wauckFrLGjeyJoZzgCYbdNEmSIAKsflhHODUTtQMpXVqnPvxWRBi');
        $fromto = array_combine($fromArr, $toArr);
        $newstr = [];
        foreach (str_split($str) as $k => $v) {
            if ($k == 1) {
                $newstr[5] = $fromto[$v];
            } elseif ($k == 5) {
                $newstr[1] = $fromto[$v];
            } else {
                $newstr[$k] = $fromto[$v];
            }
        }
        ksort($newstr);

        return implode('', $newstr);
    }
}
