<?php

declare(strict_types=1);

namespace app\console\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\TenantService;

/**
 * 超管控制台 - 租户管理
 */
class Tenant extends BaseController
{
    public function __construct(\think\App $app, protected TenantService $tenantService)
    {
        parent::__construct($app);
    }

    /**
     * 租户列表（分页）
     * GET /console/tenant/list?page=1&pagesize=20&id=&account=&keyword=&status=&sort_field=&sort_order=
     * - id：展示语义（1=admin 虚拟行，N=真实 id N-1）
     * - account：账号/联系人/电话  - keyword：公众号名称/小程序名称
     */
    public function list()
    {
        return $this->success($this->tenantService->tenantList($this->request->param()));
    }

    /**
     * 新增租户
     * POST /console/tenant/add body:{username, company, contacts, phone, remark, expire_days}
     */
    public function add()
    {
        $data = $this->body();

        $this->validate($data, [
            'username'    => 'require|length:2,50',
            'expire_days' => 'require|integer|between:0,3650',
        ]);

        return $this->success($this->tenantService->tenantAdd($data));
    }

    /**
     * 编辑租户
     * POST /console/tenant/edit body:{id, company, contacts, phone, remark}
     */
    public function edit()
    {
        $data = $this->body();

        // id=0 为超管虚拟行编辑（密码/联系人/电话/备注），交由 service 处理
        $this->validate($data, ['id' => 'require|integer']);

        $this->tenantService->tenantEdit($data);

        return $this->success();
    }

    /**
     * 删除租户（软删）
     * POST /console/tenant/delete body:{id}
     */
    public function delete()
    {
        $data = $this->body();

        // id=0 为超管虚拟行，交由 service 抛友好提示
        $this->validate($data, ['id' => 'require|integer']);

        $this->tenantService->tenantDelete((int) $data['id']);

        return $this->success();
    }

    /**
     * 切换租户状态
     * POST /console/tenant/status body:{id, status}（1 正常 / 2 关闭）
     */
    public function status()
    {
        $data = $this->body();

        $this->validate($data, [
            'id'     => 'require|integer|gt:0',
            'status' => 'require|in:1,2',
        ]);

        $this->tenantService->tenantSetStatus((int) $data['id'], (int) $data['status']);

        return $this->success();
    }

    /**
     * 租户续费
     * POST /console/tenant/renew body:{id, days}
     */
    public function renew()
    {
        $data = $this->body();

        $this->validate($data, [
            'id'   => 'require|integer|gt:0',
            'days' => 'require|integer|between:1,3650',
        ]);

        return $this->success($this->tenantService->tenantRenew((int) $data['id'], (int) $data['days']));
    }

    /**
     * 代登录（签发该租户管理员 token）
     * POST /console/tenant/proxyLogin body:{id}
     * id=0 为列表首位 admin 超管虚拟行：签发带最高权限标记的租户壳 token
     */
    public function proxyLogin()
    {
        $data = $this->body();

        if (!array_key_exists('id', $data)) {
            throw new BusinessException('缺少参数 id');
        }

        $id = (int) $data['id'];

        if ($id < 0) {
            throw new BusinessException('参数错误');
        }

        if (0 === $id) {
            return $this->success($this->tenantService->superProxyLogin());
        }

        return $this->success($this->tenantService->tenantProxyLogin($id));
    }

    /**
     * 下载 uni-app 前端代码包（zip 流式下载，runtime 缓存复用）
     * GET /console/tenant/uniappZip
     */
    public function uniappZip()
    {
        $srcDir = root_path() . 'frontend' . DIRECTORY_SEPARATOR . 'uniapp-client' . DIRECTORY_SEPARATOR . 'src';
        if (!is_dir($srcDir)) {
            throw new BusinessException('uni-app 代码目录不存在', 1);
        }

        // 遍历源码目录：收集文件与最新修改时间（缓存指纹）
        $files    = [];
        $maxMtime = 0;
        $baseLen  = strlen($srcDir) + 1;
        $iter     = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iter as $file) {
            if ($file->isFile()) {
                $files[]  = [$file->getPathname(), str_replace('\\', '/', substr($file->getPathname(), $baseLen))];
                $maxMtime = max($maxMtime, $file->getMTime());
            }
        }

        $zipPath = runtime_path() . 'uniapp_src.zip';

        // 源码有变更（新增/修改）才重新打包，否则复用缓存
        if (!is_file($zipPath) || (int) filemtime($zipPath) < $maxMtime) {
            if (!class_exists(\ZipArchive::class)) {
                throw new BusinessException('服务器缺少 ZipArchive 扩展，无法打包', 1);
            }

            $tmp = $zipPath . '.tmp';
            $zip = new \ZipArchive();
            if (true !== $zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE)) {
                throw new BusinessException('zip 临时文件创建失败', 1);
            }
            foreach ($files as [$abs, $rel]) {
                $zip->addFile($abs, 'uniapp-src/' . $rel);
            }
            $zip->close();
            @rename($tmp, $zipPath);
            @touch($zipPath); // 确保缓存 mtime 不早于源码
        }

        return download($zipPath, 'uniapp-code-package.zip');
    }

    /**
     * 请求体（兼容 JSON 与表单）
     */
    protected function body(): array
    {
        $data = $this->request->post();

        return is_array($data) ? $data : [];
    }
}
