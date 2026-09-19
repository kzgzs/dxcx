<?php

declare(strict_types=1);

namespace app\console\controller;

use app\BaseController;
use think\facade\Db;

/**
 * 超管控制台 - 系统升级（对照参考系统 WebUpgrade / view/web_upgrade/index.html）
 *
 * 参考系统页面结构：当前版本与最新版本对比提示 + 升级操作确认 + 升级日志时间轴。
 * 在线升级需远程升级包源，当前无远程源做占位：检测更新仅对比本地 version.php，
 * 执行升级在有可用更新时记录升级日志（upgrade_log）。
 */
class Upgrade extends BaseController
{
    /**
     * 页面信息：当前版本 / 最新版本 / 是否需要升级 / 版本说明
     * GET /console/upgrade/info
     */
    public function info()
    {
        $myversion   = $this->readVersion();
        $newversion  = $this->fetchNewVersion($myversion);
        $needupgrade = version_compare($newversion, $myversion, '>') ? 1 : 0;

        return $this->success([
            'myversion'   => $myversion,
            'newversion'  => $newversion,
            'needupgrade' => $needupgrade,
            'remark'      => $needupgrade ? $this->upgradeRemark($newversion) : '',
        ]);
    }

    /**
     * 检测更新（当页提示结果，对照参考系统 getfilllist 对比逻辑的占位实现）
     * POST /console/upgrade/check
     */
    public function check()
    {
        $myversion   = $this->readVersion();
        $newversion  = $this->fetchNewVersion($myversion);
        $needupgrade = version_compare($newversion, $myversion, '>') ? 1 : 0;

        // 文案对照参考系统 web_upgrade/index.html
        $message = $needupgrade
            ? '最新版本：V' . $newversion . '，您的当前版本为：V' . $myversion . '，请点击升级按钮进行升级'
            : '最新版本为：' . $newversion . '，您当前版本为最新版，无须升级！';

        return $this->success([
            'myversion'   => $myversion,
            'newversion'  => $newversion,
            'needupgrade' => $needupgrade,
            'message'     => $message,
        ]);
    }

    /**
     * 执行升级（对照参考系统 doup/updatefile/doupsql，无远程升级源时占位）
     * POST /console/upgrade/doup
     */
    public function doup()
    {
        $myversion   = $this->readVersion();
        $newversion  = $this->fetchNewVersion($myversion);
        $needupgrade = version_compare($newversion, $myversion, '>') ? 1 : 0;

        if (!$needupgrade) {
            return $this->error('您当前版本为最新版，无须升级！');
        }

        // 在线升级占位：暂无远程升级包源，接入后此处执行文件对比更新与数据库升级
        Db::name('upgrade_log')->insert([
            'version'    => $newversion,
            'remark'     => $this->upgradeRemark($newversion),
            'createtime' => time(),
        ]);

        return $this->success(['version' => $newversion], '升级成功');
    }

    /**
     * 升级日志（对照参考系统 getupgradelog，时间轴展示）
     * GET /console/upgrade/log
     */
    public function log()
    {
        $list = Db::name('upgrade_log')
            ->field('id,version,remark,createtime')
            ->order('id', 'desc')
            ->select()
            ->toArray();

        return $this->success($list);
    }

    /**
     * 当前版本号（读根目录 version.php，对照参考系统 file_get_contents(version.php)）
     */
    private function readVersion(): string
    {
        $file = $this->app->getRootPath() . 'version.php';
        if (is_file($file)) {
            $content = trim((string) file_get_contents($file));
            if ('' !== $content) {
                return $content;
            }
        }

        return '1.0.0';
    }

    /**
     * 获取最新版本号（占位：暂无远程升级源，返回本地版本即最新版；
     * 支持 runtime/upgrade_remote_version.txt 本地模拟远程版本号，仅供联调测试）
     */
    private function fetchNewVersion(string $myversion): string
    {
        $stub = $this->app->getRuntimePath() . 'upgrade_remote_version.txt';
        if (is_file($stub)) {
            $remote = trim((string) file_get_contents($stub));
            if ('' !== $remote) {
                return $remote;
            }
        }

        return $myversion;
    }

    /**
     * 版本说明（占位：暂无远程升级源返回的版本说明）
     */
    private function upgradeRemark(string $version): string
    {
        return '';
    }
}
