<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\ScopeService;
use app\common\service\UploadService;
use think\facade\Db;

/**
 * 租户后台 - 通用图片上传 + 素材库（任务290）
 *
 * POST /admin/upload（multipart，字段 file；图片白名单 ≤ 10MB，视频白名单 ≤ 100MB，任务296/301）
 * 存储目录 public/uploads/tenant_{aid}/，按租户隔离
 *
 * 存储归属（任务256）：优先读当前租户附件配置（AttachSet），
 * 租户未配置（storage_type=1 或云参数不齐）时回退平台（超管）配置；
 * 云存储 SDK 未引入时保存本地（对照参考系统 Pic::uploadoss 参数不齐保留本地路径的做法），
 * 保证现有上传零回归。
 *
 * 素材库（任务290）：browser 浏览 / delete 删除 / 分组五接口 / iconrender 图标渲染落盘，
 * 均写 admin_upload / admin_upload_group / iconsvg_history，租户隔离 aid 取自会话。
 */
class Upload extends BaseController
{
    public function __construct(\think\App $app, protected UploadService $uploadService)
    {
        parent::__construct($app);
    }

    public function index()
    {
        // PHP/框架层上传错误（超 upload_max_filesize/post_max_size 等）转为友好业务提示
        try {
            $file = $this->request->file('file');
        } catch (\Throwable $e) {
            throw new BusinessException('文件大小超出服务器 PHP 上传限制（upload_max_filesize/post_max_size），请联系管理员调整', 1);
        }

        if (null === $file) {
            throw new BusinessException('请选择要上传的文件', 1);
        }

        $aid = ScopeService::aidOf($this->request);

        // 解析生效的附件存储配置（租户优先 → 平台回退 → 本地兜底）
        $remote = AttachSet::resolveEffective($aid);

        // 云存储（2阿里云/3七牛/4腾讯云）：当前未引入对应 SDK，
        // 先落本地保证可用（后续接入 SDK 后在此分支推送云端）
        if ((int) $remote['storage_type'] > 1) {
            \think\facade\Log::info('admin.upload 云存储已配置但未引入SDK，回退本地存储 aid=' . $aid . ' type=' . $remote['storage_type']);
        }

        // 任务296：按扩展名分流——视频走 video()（≤100MB），任务311 音频走 audio()（≤20MB），其余按图片校验（≤10MB，任务301 放宽），
        // 修复上传视频误报「图片大小不能超过2MB」
        $ext = strtolower($file->getOriginalExtension());
        if (in_array($ext, UploadService::VIDEO_EXTENSIONS, true)) {
            $result = $this->uploadService->video($file, $aid);
        } elseif (in_array($ext, UploadService::AUDIO_EXTENSIONS, true)) {
            $result = $this->uploadService->audio($file, $aid);
        } else {
            $result = $this->uploadService->image($file, $aid);
        }

        // 任务290：补写 admin_upload 素材记录（出参信封不变，记录写失败不影响上传结果）
        try {
            $this->writeUploadRecord($aid, $result['url'], $file->getOriginalName());
        } catch (\Throwable $e) {
            \think\facade\Log::info('admin.upload 素材记录写入失败 aid=' . $aid . ' err=' . $e->getMessage());
        }

        return $this->success($result);
    }


    /**
     * POST /admin/upload/cert (multipart, field file): wechat pay certificate (.pem/.key)
     * Used by tenant admin Business-Sysset wechat service-provider credentials; reuses
     * UploadService::certificate(), stores under public/uploads/cert/, returns site-relative url.
     */
    public function cert()
    {
        $file = $this->request->file('file');
        if (null === $file) {
            throw new BusinessException('请选择要上传的证书文件', 1);
        }

        return $this->success($this->uploadService->certificate($file), '上传成功');
    }

    // ==================== 素材库（任务290） ====================

    /**
     * GET /admin/upload/browser：素材浏览（gid/keyword/sort/page，28条/页，附两级分组树）
     */
    public function browser()
    {
        $aid     = ScopeService::aidOf($this->request);
        $gid     = (int) $this->request->get('gid', 0);
        $keyword = trim((string) $this->request->get('keyword', ''));
        $sort    = (string) $this->request->get('sort', 'time');
        $page    = max(1, (int) $this->request->get('page', 1));
        $size    = 28;

        $query = ScopeService::query('admin_upload', $aid)->where('isdel', 0);
        if ($gid > 0) {
            $query->where('gid', $gid);
        }
        if ($keyword !== '') {
            $query->whereLike('name', '%' . $keyword . '%');
        }

        $total = (clone $query)->count();
        // 排序：name=名称正序 / old=上传时间正序（最早在前） / 其余=最新在前（任务296）
        $order = match ($sort) {
            'name' => ['name' => 'asc', 'id' => 'desc'],
            'old'  => ['id' => 'asc'],
            default => ['id' => 'desc'],
        };
        $rows  = $query->field('id,name,url,type,width,height,createtime,gid')
            ->order($order)
            ->page($page, $size)
            ->select()
            ->toArray();

        return $this->success([
            'list'   => $rows,
            'total'  => $total,
            'groups' => $this->groupTree($aid),
        ]);
    }

    /**
     * POST /admin/upload/delete：删素材记录 + 物理文件（uploads/ 与 upload/ 双前缀，相对路径+realpath 校验防穿越，任务296）
     */
    public function delete()
    {
        $aid = ScopeService::aidOf($this->request);
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $this->request->post('ids', [])))));
        if (empty($ids)) {
            throw new BusinessException('请选择要删除的素材', 1);
        }

        $rows = ScopeService::query('admin_upload', $aid)->whereIn('id', $ids)->select()->toArray();
        if (empty($rows)) {
            throw new BusinessException('素材不存在或无权操作', 1);
        }

        $publicRoot = app()->getRootPath() . 'public';
        $uploadsRoot = str_replace('\\', '/', realpath($publicRoot . '/uploads'));
        $uploadRoot  = str_replace('\\', '/', realpath($publicRoot . '/upload'));
        foreach ($rows as $row) {
            // 仅删除本地两个素材目录下的物理文件（外链/云存储只删记录）
            $url = (string) ($row['url'] ?? '');
            $root = null;
            if (str_starts_with($url, '/uploads/')) {
                $root = $uploadsRoot;
            } elseif (str_starts_with($url, '/upload/')) {
                $root = $uploadRoot;
            }
            if ($root !== null && $root !== false) {
                $abs = realpath($publicRoot . $url);
                if ($abs !== false && str_starts_with(str_replace('\\', '/', $abs), $root . '/')) {
                    @unlink($abs);
                }
            }
        }

        Db::name('admin_upload')->where('aid', $aid)->whereIn('id', array_column($rows, 'id'))->delete();

        return $this->success(['count' => count($rows)]);
    }

    // ==================== 素材分组 ====================

    /**
     * GET /admin/upload/group：两级分组树
     */
    public function group()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->groupTree($aid));
    }

    /**
     * POST /admin/upload/addgroup：新增分组（两级：pid 必须为顶级组或 0）
     */
    public function addgroup()
    {
        $aid  = ScopeService::aidOf($this->request);
        $pid  = max(0, (int) $this->request->post('pid', 0));
        $name = trim((string) $this->request->post('name', ''));
        if ($name === '') {
            throw new BusinessException('请填写分组名称', 1);
        }
        if ($pid > 0) {
            $parent = ScopeService::query('admin_upload_group', $aid)->where('id', $pid)->find();
            if (empty($parent)) {
                throw new BusinessException('上级分组不存在', 1);
            }
            if ((int) $parent['pid'] !== 0) {
                throw new BusinessException('分组最多支持两级', 1);
            }
        }

        $id = Db::name('admin_upload_group')->insertGetId([
            'aid'        => $aid,
            'uid'        => $this->uidOf(),
            'pid'        => $pid,
            'name'       => $name,
            'sort'       => 0,
            'createtime' => time(),
        ]);

        return $this->success(['id' => $id]);
    }

    /**
     * POST /admin/upload/editgroup：分组改名
     */
    public function editgroup()
    {
        $aid  = ScopeService::aidOf($this->request);
        $id   = (int) $this->request->post('id', 0);
        $name = trim((string) $this->request->post('name', ''));
        if ($id <= 0 || $name === '') {
            throw new BusinessException('参数不完整', 1);
        }

        $affected = ScopeService::query('admin_upload_group', $aid)->where('id', $id)->update(['name' => $name]);
        if ($affected === 0) {
            throw new BusinessException('分组不存在或无权操作', 1);
        }

        return $this->success();
    }

    /**
     * POST /admin/upload/delgroup：删分组（子组一并删除，组内文件归回未分组 gid=0）
     */
    public function delgroup()
    {
        $aid = ScopeService::aidOf($this->request);
        $id  = (int) $this->request->post('id', 0);
        if ($id <= 0) {
            throw new BusinessException('参数不完整', 1);
        }

        $row = ScopeService::query('admin_upload_group', $aid)->where('id', $id)->find();
        if (empty($row)) {
            throw new BusinessException('分组不存在或无权操作', 1);
        }

        $delIds = [$id];
        $children = ScopeService::query('admin_upload_group', $aid)->where('pid', $id)->column('id');
        $delIds = array_merge($delIds, array_map('intval', $children));

        Db::name('admin_upload_group')->whereIn('id', $delIds)->delete();
        Db::name('admin_upload')->where('aid', $aid)->whereIn('gid', $delIds)->update(['gid' => 0]);

        return $this->success();
    }

    /**
     * POST /admin/upload/changegroup：批量移动素材到分组
     */
    public function changegroup()
    {
        $aid = ScopeService::aidOf($this->request);
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $this->request->post('ids', [])))));
        $gid = max(0, (int) $this->request->post('gid', 0));
        if (empty($ids)) {
            throw new BusinessException('请选择素材', 1);
        }
        if ($gid > 0 && empty(ScopeService::query('admin_upload_group', $aid)->where('id', $gid)->find())) {
            throw new BusinessException('目标分组不存在', 1);
        }

        Db::name('admin_upload')->where('aid', $aid)->whereIn('id', $ids)->update(['gid' => $gid]);

        return $this->success();
    }

    // ==================== 图标渲染（任务290） ====================

    /**
     * POST /admin/upload/iconrender：base64 PNG 解码落盘，写 admin_upload + iconsvg_history
     * 云存储 SDK 未引入，与 index 同口径回退本地（后续接入 SDK 后在此分支推送云端）
     */
    public function iconrender()
    {
        $aid    = ScopeService::aidOf($this->request);
        $dataUrl = (string) $this->request->post('dataurl', '');
        $name   = trim((string) $this->request->post('name', 'icon'));
        $width  = max(0, (int) $this->request->post('width', 0));
        $height = max(0, (int) $this->request->post('height', 0));

        $bin = $this->decodeBase64Png($dataUrl);

        $subDir = $aid . '/' . date('Ymd');
        $absDir = app()->getRootPath() . 'public/upload/' . $subDir;
        if (!is_dir($absDir)) {
            mkdir($absDir, 0755, true);
        }
        $fileName = date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.png';
        if (file_put_contents($absDir . '/' . $fileName, $bin) === false) {
            throw new BusinessException('图标保存失败', 1);
        }
        $url = '/upload/' . $subDir . '/' . $fileName;

        $dims = @getimagesizefromstring($bin);
        if (is_array($dims)) {
            $width  = $width > 0 ? $width : (int) $dims[0];
            $height = $height > 0 ? $height : (int) $dims[1];
        }

        $iconId = (int) Db::name('admin_upload')->insertGetId([
            'aid'        => $aid,
            'bid'        => 0,
            'uid'        => $this->uidOf(),
            'name'       => $name,
            'dir'        => 'upload/' . $subDir,
            'url'        => $url,
            'type'       => 'png',
            'size'       => $this->formatSize(strlen($bin)),
            'bsize'      => strlen($bin),
            'width'      => $width,
            'height'     => $height,
            'platform'   => 'ht',
            'isdel'      => 0,
            'gid'        => 0,
            'hash'       => md5($bin),
            'remote_type' => 'local',
            'createtime' => time(),
        ]);

        Db::name('iconsvg_history')->insert([
            'aid'        => $aid,
            'uid'        => $this->uidOf(),
            'iconid'     => $iconId,
            'name'       => $name,
            'show_svg'   => '',
            'pngurl'     => $url,
            'createtime' => time(),
        ]);

        return $this->success(['url' => $url]);
    }

    // ==================== 内部工具 ====================

    /** 会话 uid（与 Index 控制器同口径：JWT payload.uid） */
    protected function uidOf(): int
    {
        $payload = (array) ($this->request->authPayload ?? []);

        return (int) ($payload['uid'] ?? 0);
    }

    /** 写 admin_upload 素材记录（通用上传落库） */
    protected function writeUploadRecord(int $aid, string $url, string $originName): void
    {
        $abs = app()->getRootPath() . 'public' . $url;
        $bin = @file_get_contents($abs);
        $bin = $bin === false ? '' : $bin;

        $width  = 0;
        $height = 0;
        $dims   = @getimagesize($abs);
        if (is_array($dims)) {
            $width  = (int) $dims[0];
            $height = (int) $dims[1];
        }

        Db::name('admin_upload')->insert([
            'aid'        => $aid,
            'bid'        => 0,
            'uid'        => $this->uidOf(),
            'name'       => $originName !== '' ? $originName : basename($url),
            'dir'        => dirname($url),
            'url'        => $url,
            'type'       => strtolower(pathinfo($url, PATHINFO_EXTENSION)),
            'size'       => $this->formatSize(strlen($bin)),
            'bsize'      => strlen($bin),
            'width'      => $width,
            'height'     => $height,
            'platform'   => 'ht',
            'isdel'      => 0,
            'gid'        => 0,
            'hash'       => md5($bin),
            'remote_type' => 'local',
            'createtime' => time(),
        ]);
    }

    /** 两级分组树（顶级 + 子级，组内素材数） */
    protected function groupTree(int $aid): array
    {
        $groups = ScopeService::query('admin_upload_group', $aid)
            ->field('id,pid,name')
            ->order(['sort' => 'asc', 'id' => 'asc'])
            ->select()
            ->toArray();

        $counts = ScopeService::query('admin_upload', $aid)
            ->where('isdel', 0)
            ->where('gid', '>', 0)
            ->group('gid')
            ->column('count(*)', 'gid');

        $byParent = [];
        foreach ($groups as $g) {
            $g['count']    = (int) ($counts[$g['id']] ?? 0);
            $byParent[(int) $g['pid']][] = $g;
        }

        $tree = [];
        foreach ($byParent[0] ?? [] as $top) {
            $top['children'] = $byParent[(int) $top['id']] ?? [];
            $tree[] = $top;
        }

        return $tree;
    }

    /** 解析 data:image/png;base64,...，非 PNG 拒绝 */
    protected function decodeBase64Png(string $dataUrl): string
    {
        if (preg_match('#^data:image/png;base64,(.+)$#s', $dataUrl, $m) !== 1) {
            throw new BusinessException('仅支持 PNG 格式的 base64 图片', 1);
        }
        $bin = base64_decode($m[1], true);
        if ($bin === false || $bin === '') {
            throw new BusinessException('图片数据解码失败', 1);
        }
        if (strlen($bin) > 2 * 1024 * 1024) {
            throw new BusinessException('图片大小不能超过2MB', 1);
        }

        return $bin;
    }

    /** 字节数格式化（与参考系统 size 字段口径一致的展示值） */
    protected function formatSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . 'MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 2) . 'KB';
        }

        return $bytes . 'B';
    }
}