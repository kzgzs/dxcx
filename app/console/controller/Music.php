<?php

declare(strict_types=1);

namespace app\console\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\UploadService;
use think\facade\Db;

/**
 * 超管控制台 - 音乐控制（任务315）
 *
 * admin_music 以 aid=0 为平台级口径：控制台维护，租户设计编辑器经 /admin/music/list 透传消费。
 * list 分页+搜索 / add 名称+音频URL 入库 / del 删除（本地文件一并删，外链只删记录）
 * / clean 一键清理全部音乐（本地文件一并删） / upload 音频上传（mp3/wav/m4a/aac ≤20MB）
 */
class Music extends BaseController
{
    public function __construct(\think\App $app, protected UploadService $uploadService)
    {
        parent::__construct($app);
    }

    /** 平台级口径：aid=0 */
    protected function platformQuery()
    {
        return Db::name('admin_music')->where('aid', 0);
    }

    /**
     * GET /console/music/list：平台音乐列表（keyword 搜索 + page/size 分页）
     */
    public function list()
    {
        $keyword = trim((string) $this->request->get('keyword', ''));
        $page    = max(1, (int) $this->request->get('page', 1));
        $size    = min(100, max(1, (int) $this->request->get('size', 10)));

        $query = $this->platformQuery();
        if ($keyword !== '') {
            $query->whereLike('name', '%' . $keyword . '%');
        }

        $total = (clone $query)->count();
        $rows  = $query->field('id,name,url,size,duration,createtime')
            ->order(['id' => 'desc'])
            ->page($page, $size)
            ->select()
            ->toArray();

        return $this->success(['list' => $rows, 'total' => $total]);
    }

    /**
     * POST /console/music/add：添加音乐（上传入库或外链直填，name+url 必填）
     */
    public function add()
    {
        $name = trim((string) $this->request->post('name', ''));
        $url  = trim((string) $this->request->post('url', ''));
        if ($name === '') {
            throw new BusinessException('请填写音乐名称', 1);
        }
        if ($url === '') {
            throw new BusinessException('请上传或填写音频地址', 1);
        }
        // 外链口径：必须 http(s) 开头（与前端校验同口径）
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) && !preg_match('#^https?://#i', $url)) {
            throw new BusinessException('音频链接仅支持 http/https 地址', 1);
        }

        $id = Db::name('admin_music')->insertGetId([
            'aid'        => 0,
            'name'       => $name,
            'url'        => $url,
            'size'       => trim((string) $this->request->post('size', '')),
            'duration'   => max(0, (int) $this->request->post('duration', 0)),
            'createtime' => time(),
        ]);

        return $this->success(['id' => $id]);
    }

    /**
     * POST /console/music/del：删除音乐（删记录；本地 uploads/ 物理文件一并删，外链只删记录）
     */
    public function del()
    {
        $id = (int) $this->request->post('id', 0);
        if ($id <= 0) {
            throw new BusinessException('参数不完整', 1);
        }

        $row = $this->platformQuery()->where('id', $id)->find();
        if (empty($row)) {
            throw new BusinessException('音乐不存在', 1);
        }

        $this->removeLocalFile((string) ($row['url'] ?? ''));
        Db::name('admin_music')->where('aid', 0)->where('id', $id)->delete();

        return $this->success();
    }

    /**
     * POST /console/music/clean：清理音频——删除全部平台音乐记录，本地文件存在则一并删除
     */
    public function clean()
    {
        $rows = $this->platformQuery()->field('id,url')->select()->toArray();
        foreach ($rows as $row) {
            $this->removeLocalFile((string) ($row['url'] ?? ''));
        }
        Db::name('admin_music')->where('aid', 0)->delete();

        return $this->success(['count' => count($rows)]);
    }

    /**
     * POST /console/music/upload：音频上传（mp3/wav/m4a/aac ≤20MB，落平台目录 uploads/platform/）
     */
    public function upload()
    {
        $file = $this->request->file('file');
        if (null === $file) {
            throw new BusinessException('请选择要上传的音频文件', 1);
        }

        return $this->success($this->uploadService->audio($file, 0));
    }

    /** 本地 uploads/ 目录物理文件删除（相对路径 + realpath 校验防穿越；外链/云地址跳过） */
    protected function removeLocalFile(string $url): void
    {
        if (!str_starts_with($url, '/uploads/')) {
            return;
        }
        $publicRoot  = app()->getRootPath() . 'public';
        $uploadsRoot = str_replace('\\', '/', realpath($publicRoot . '/uploads'));
        $abs         = realpath($publicRoot . $url);
        if ($uploadsRoot !== false && $abs !== false && str_starts_with(str_replace('\\', '/', $abs), $uploadsRoot . '/')) {
            @unlink($abs);
        }
    }
}
