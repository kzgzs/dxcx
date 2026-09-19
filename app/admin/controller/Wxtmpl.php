<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\ScopeService;
use app\common\service\TmplConfigService;

/**
 * 租户后台 - 小程序订阅消息设置
 *
 * wx_tmplset 主字段组 + _new 订阅消息字段组读写（支持字段组独立保存）；
 * 获取模板标题（newtmpl/gettitle）、添加模板（newtmpl/addtemplate，tid+kidList，
 * 45026/200012 超限错误分支）。参照 canzhao\app\controller\Wxtmpl.php 重写为纯 API。
 */
class Wxtmpl extends BaseController
{
    public function __construct(\think\App $app, protected TmplConfigService $tmpl)
    {
        parent::__construct($app);
    }

    /**
     * 订阅消息设置：GET 读取 / POST 保存（info 仅传需要保存的字段组即可）
     * GET|POST /admin/wxtmpl/tmplset
     */
    public function tmplset()
    {
        $aid = ScopeService::aidOf($this->request);

        if ($this->request->isPost()) {
            $info = $this->request->post('info/a');
            $this->tmpl->wxTmplsetSave($aid, is_array($info) ? $info : []);

            return $this->success([], '设置成功');
        }

        return $this->success(['info' => $this->tmpl->wxTmplsetGet($aid)]);
    }

    /**
     * 获取模板标题（小程序类目列表 newtmpl/gettitle）
     * GET /admin/wxtmpl/getTitle
     */
    public function getTitle()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success(['list' => $this->tmpl->wxSubscribeTitle($aid)]);
    }

    /**
     * 组合模板并添加至账号（newtmpl/addtemplate，返回 priTmplId）
     * POST /admin/wxtmpl/getTmplId {template_no, keywords: "29,1,2,9"}
     */
    public function getTmplId()
    {
        $aid        = ScopeService::aidOf($this->request);
        $templateNo = (string) $this->request->post('template_no', '');
        $keywords   = (string) $this->request->post('keywords', '');

        $kidList = [];
        foreach (explode(',', $keywords) as $kid) {
            if ('' !== trim($kid)) {
                $kidList[] = (int) $kid;
            }
        }

        return $this->success(['template_id' => $this->tmpl->wxAddTemplate($aid, $templateNo, $kidList)], '添加成功');
    }
}
