<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\ScopeService;
use app\common\service\TmplConfigService;

/**
 * 租户后台 - 公众号模板消息
 *
 * 模板消息设置（mp_tmplset）/类目模板消息（mp_tmplset_new）/已添加的模板聚合列表；
 * 添加模板走 TmplConfigService（mock/真实双轨，自动行业设置，45026/48001 错误分支）。
 * 参照 canzhao\app\controller\Mptmpl.php 路数重写为纯 API。
 */
class Mptmpl extends BaseController
{
    public function __construct(\think\App $app, protected TmplConfigService $tmpl)
    {
        parent::__construct($app);
    }

    /**
     * 公众号模板消息设置：GET 读取 / POST 保存
     * GET|POST /admin/mptmpl/tmplset
     */
    public function tmplset()
    {
        $aid = ScopeService::aidOf($this->request);

        if ($this->request->isPost()) {
            $info = $this->request->post('info/a');
            $this->tmpl->mpTmplsetSave($aid, is_array($info) ? $info : []);

            return $this->success([], '设置成功');
        }

        return $this->success(['info' => $this->tmpl->mpTmplsetGet($aid)]);
    }

    /**
     * 类目模板消息设置：GET 读取 / POST 保存
     * GET|POST /admin/mptmpl/tmplsetNew
     */
    public function tmplsetNew()
    {
        $aid = ScopeService::aidOf($this->request);

        if ($this->request->isPost()) {
            $info = $this->request->post('info/a');
            $this->tmpl->mpTmplsetNewSave($aid, is_array($info) ? $info : []);

            return $this->success([], '设置成功');
        }

        return $this->success(['info' => $this->tmpl->mpTmplsetNewGet($aid)]);
    }

    /**
     * 添加行业模板（api_add_template，返回模板ID）
     * POST /admin/mptmpl/getTmplId {template_no}
     */
    public function getTmplId()
    {
        $aid = ScopeService::aidOf($this->request);
        $templateNo = (string) $this->request->post('template_no', '');

        return $this->success(['template_id' => $this->tmpl->mpAddTemplate($aid, $templateNo)], '添加成功');
    }

    /**
     * 添加类目模板（附关键词，自动行业设置，返回模板ID）
     * POST /admin/mptmpl/getTmplIdNew {template_no}
     */
    public function getTmplIdNew()
    {
        $aid = ScopeService::aidOf($this->request);
        $templateNo = (string) $this->request->post('template_no', '');

        return $this->success(['template_id' => $this->tmpl->mpAddTemplateNew($aid, $templateNo)], '添加成功');
    }

    /**
     * 已添加的模板（两张 tmplset 表非空字段聚合）
     * GET /admin/mptmpl/mytmpl
     */
    public function myTmpl()
    {
        $aid  = ScopeService::aidOf($this->request);
        $list = $this->tmpl->myTmplList($aid);

        return $this->success(['list' => $list, 'count' => count($list)]);
    }

    /**
     * 删除已添加模板（清空对应配置字段）
     * POST /admin/mptmpl/mytmplDel {source: mp_tmplset|mp_tmplset_new, field}
     */
    public function myTmplDel()
    {
        $aid    = ScopeService::aidOf($this->request);
        $source = (string) $this->request->post('source', '');
        $field  = (string) $this->request->post('field', '');

        $this->tmpl->myTmplDel($aid, $source, $field);

        return $this->success([], '删除成功');
    }
}
