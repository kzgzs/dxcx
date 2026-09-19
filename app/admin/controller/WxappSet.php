<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\ScopeService;
use app\common\service\WxappSetService;

/**
 * 租户后台 - 小程序运营设置
 *
 * 账号资料（改名/头像/简介/搜索开关）、服务器域名快配、业务域名（校验文件生成下载）、
 * 隐私指引、ICP 备案信息 CRUD（人脸核身桩）。
 * mock/真实双轨经 WxappSetService；参照 canzhao\app\controller\Wxset.php 路数简化重写。
 */
class WxappSet extends BaseController
{
    public function __construct(\think\App $app, protected WxappSetService $wxset)
    {
        parent::__construct($app);
    }

    /* ==================== 账号资料 ==================== */

    /**
     * 账号资料概览（名称/头像/简介/搜索状态）
     * GET /admin/wxappSet/accountInfo
     */
    public function accountInfo()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->wxset->accountInfo($aid));
    }

    /**
     * 设置小程序名称
     * POST /admin/wxappSet/setNickname
     */
    public function setNickname()
    {
        $aid = ScopeService::aidOf($this->request);
        $this->wxset->setNickname($aid, (string) $this->request->post('nickname', ''));

        return $this->success([], '修改成功');
    }

    /**
     * 设置小程序头像（api 调用桩，本地落库）
     * POST /admin/wxappSet/setHeadimg
     */
    public function setHeadimg()
    {
        $aid = ScopeService::aidOf($this->request);
        $this->wxset->setHeadimg($aid, (string) $this->request->post('headimg', ''));

        return $this->success([], '修改成功');
    }

    /**
     * 设置小程序简介
     * POST /admin/wxappSet/setSignature
     */
    public function setSignature()
    {
        $aid = ScopeService::aidOf($this->request);
        $this->wxset->setSignature($aid, (string) $this->request->post('signature', ''));

        return $this->success([], '修改成功');
    }

    /**
     * 搜索开关（open 1=允许被搜索 0=关闭）
     * POST /admin/wxappSet/setSearchStatus
     */
    public function setSearchStatus()
    {
        $aid = ScopeService::aidOf($this->request);
        $this->wxset->setSearchStatus($aid, (int) $this->request->post('open', 1) === 1);

        return $this->success([], '修改成功');
    }

    /* ==================== 服务器域名快配 ==================== */

    /**
     * 服务器域名：GET 读取 / POST 保存（request/uploadFile/downloadFile/webSocket）
     * GET|POST /admin/wxappSet/serverDomain
     */
    public function serverDomain()
    {
        $aid = ScopeService::aidOf($this->request);

        if ($this->request->isPost()) {
            $info = $this->request->post('info/a');
            $this->wxset->serverDomainSet($aid, is_array($info) ? $info : []);

            return $this->success([], '设置成功，发布小程序后生效');
        }

        return $this->success($this->wxset->serverDomainGet($aid));
    }

    /* ==================== 业务域名 + 校验文件 ==================== */

    /**
     * 业务域名：GET 读取 / POST 保存
     * GET|POST /admin/wxappSet/jumpDomain
     */
    public function jumpDomain()
    {
        $aid = ScopeService::aidOf($this->request);

        if ($this->request->isPost()) {
            $this->wxset->jumpDomainSet($aid, (string) $this->request->post('webviewdomain', ''));

            return $this->success([], '设置成功，发布小程序后生效');
        }

        return $this->success($this->wxset->jumpDomainGet($aid));
    }

    /**
     * 生成业务域名校验文件（写 public/ 下，返回下载链接）
     * GET /admin/wxappSet/jumpDomainFile
     */
    public function jumpDomainFile()
    {
        $aid  = ScopeService::aidOf($this->request);
        $file = $this->wxset->jumpDomainConfirmFile($aid);

        $fileName = basename((string) $file['file_name']);
        if ('' === $fileName || !preg_match('/^[\w\-]+\.(txt|TXT)$/', $fileName)) {
            return $this->error('校验文件名不合法');
        }
        file_put_contents(public_path() . $fileName, (string) $file['file_content']);

        return $this->success([
            'file_name' => $fileName,
            'url'       => request()->domain() . '/' . $fileName,
            'content'   => (string) $file['file_content'],
        ], '校验文件已生成');
    }

    /* ==================== 隐私指引 ==================== */

    /**
     * 隐私指引：GET 查询 / POST 保存（setprivacysetting）
     * GET|POST /admin/wxappSet/privacy
     */
    public function privacy()
    {
        $aid = ScopeService::aidOf($this->request);

        if ($this->request->isPost()) {
            $ownerSetting = $this->request->post('owner_setting/a');
            $settingList  = $this->request->post('setting_list/a');
            $this->wxset->privacySet($aid, is_array($ownerSetting) ? $ownerSetting : [], is_array($settingList) ? $settingList : []);

            return $this->success([], '设置成功，发布小程序后生效');
        }

        return $this->success($this->wxset->privacyGet($aid));
    }

    /* ==================== ICP 备案 ==================== */

    /**
     * 备案信息读取（不存在自动初始化）
     * GET /admin/wxappSet/icpInfo
     */
    public function icpInfo()
    {
        $aid   = ScopeService::aidOf($this->request);
        $appid = $this->wxset->resolveAppid($aid);

        return $this->success($this->wxset->icpInfo($aid, $appid));
    }

    /**
     * 备案信息保存（草稿）
     * POST /admin/wxappSet/icpSave
     */
    public function icpSave()
    {
        $aid   = ScopeService::aidOf($this->request);
        $appid = $this->wxset->resolveAppid($aid);
        $post  = $this->request->post();
        $this->wxset->icpSave($aid, $appid, is_array($post) ? $post : []);

        return $this->success([], '保存成功');
    }

    /**
     * 发起人脸核身（桩）
     * POST /admin/wxappSet/icpFaceLaunch
     */
    public function icpFaceLaunch()
    {
        $aid   = ScopeService::aidOf($this->request);
        $appid = $this->wxset->resolveAppid($aid);

        return $this->success($this->wxset->icpLaunchFaceCheck($aid, $appid));
    }

    /**
     * 查询人脸核身结果（桩）
     * GET /admin/wxappSet/icpFaceQuery
     */
    public function icpFaceQuery()
    {
        $aid   = ScopeService::aidOf($this->request);
        $appid = $this->wxset->resolveAppid($aid);

        return $this->success($this->wxset->icpQueryFaceCheck($aid, $appid));
    }

    /**
     * 提交备案申请
     * POST /admin/wxappSet/icpSubmit
     */
    public function icpSubmit()
    {
        $aid   = ScopeService::aidOf($this->request);
        $appid = $this->wxset->resolveAppid($aid);

        return $this->success($this->wxset->icpSubmit($aid, $appid));
    }
}
