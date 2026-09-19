<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\ScopeService;
use app\common\service\TenantConfigService;

/**
 * 租户后台 - 附件设置（任务256）
 *
 * 口径取证（canzhao 只读）：参考系统租户端附件存储为「租户独立配置，缺省回退平台」——
 * Pic.php 先读 admin.remote（每 aid 一份 JSON），type 未配置时回落 sysset.remote（平台级）。
 *
 * 我方落地：复用 tenant_config 键值表（aid+key 唯一，aid=0 为平台级，超管附件设置即存于此），
 * 租户级配置键 sys_remote_{field} 挂在当前 aid 下，与超管侧（aid=0）字段口径完全一致，
 * 故无需补列/新增迁移（admin_set 超宽单行表不合语义，tenant_config 即独立配置键载体）。
 *
 * 字段全套对齐超管 AttachSetting.vue：storage_type（1本地/2阿里云/3七牛/4腾讯云）
 * + oss_*5 / qiniu_*4 / cos_*6 + thumb 压缩（thumb/thumb_width/thumb_height）
 * + delete_origin / delete_local。
 */
class AttachSet extends BaseController
{
    /** 平台级配置统一用 aid=0（与 app/console/controller/System.php 一致） */
    private const PLATFORM_AID = 0;

    /** 租户级附件配置字段白名单（对齐超管 remote 分组页面字段） */
    private const FIELD_KEYS = [
        'storage_type',      // 存储类型（1 本地/2 阿里云/3 七牛云/4 腾讯云）
        'oss_key',           // 阿里云 Access Key ID
        'oss_secret',        // 阿里云 Access Key Secret
        'oss_bucket',        // 阿里云 Bucket 名称
        'oss_endpoint',      // 阿里云 EndPoint（地域节点）
        'oss_domain',        // 阿里云 Bucket 域名
        'qiniu_accesskey',   // 七牛云 AccessKey
        'qiniu_secretkey',   // 七牛云 SecretKey
        'qiniu_bucket',      // 七牛云 Bucket
        'qiniu_url',         // 七牛云 Url
        'cos_appid',         // 腾讯云 APPID
        'cos_secretid',      // 腾讯云 SecretID
        'cos_secretkey',     // 腾讯云 SecretKEY
        'cos_bucket',        // 腾讯云 Bucket
        'cos_local',         // 腾讯云地域代码
        'cos_url',           // 腾讯云 Url
        'thumb',             // 图片压缩（0 关闭/1 开启）
        'thumb_width',       // 图片最大宽度
        'thumb_height',      // 图片最大高度
        'delete_origin',     // 删除原图（0 关闭/1 开启）
        'delete_local',      // 云存储上传后删除服务器文件（0 关闭/1 开启）
    ];

    /** 各云存储类型生效所需的必填参数（缺一即视为未配置，回退平台） */
    private const CLOUD_REQUIRED = [
        2 => ['oss_key', 'oss_secret', 'oss_bucket', 'oss_endpoint', 'oss_domain'],
        3 => ['qiniu_accesskey', 'qiniu_secretkey', 'qiniu_bucket', 'qiniu_url'],
        4 => ['cos_appid', 'cos_secretid', 'cos_secretkey', 'cos_bucket', 'cos_local', 'cos_url'],
    ];

    /**
     * 读取当前租户的附件配置（回显）
     * GET /admin/attachSet/index
     *
     * 附带只读展示项：服务器单次上传限制（PHP ini）+ PHP 版本
     */
    public function index()
    {
        $aid = ScopeService::aidOf($this->request);

        $data = [];
        foreach (self::FIELD_KEYS as $key) {
            $data[$key] = TenantConfigService::get($aid, self::configKey($key));
        }

        // 附件设置页额外下发 PHP 真实上传限制（只读展示，不可写回配置库）
        $data['upload_max_filesize'] = (string) ini_get('upload_max_filesize');
        $data['php']                 = PHP_VERSION;

        return $this->success($data);
    }

    /**
     * 保存当前租户的附件配置（白名单字段逐键落 tenant_config）
     * POST /admin/attachSet/save
     */
    public function save()
    {
        $aid  = ScopeService::aidOf($this->request);
        $data = $this->body();

        foreach (self::FIELD_KEYS as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }

            $value = trim((string) $data[$key]);

            if (mb_strlen($value) > 255) {
                return $this->error('配置项「' . $key . '」内容过长');
            }

            TenantConfigService::set($aid, self::configKey($key), $value);
        }

        return $this->success([], '保存成功');
    }

    /**
     * 解析当前租户的生效附件存储配置（上传链路用）
     *
     * 对照参考系统 Pic.php 归属逻辑：租户配置了云存储且参数齐备 → 用租户配置；
     * 租户未配置（storage_type 空/1）或云参数不齐 → 回退平台（aid=0）配置；
     * 平台配置同样不齐备时最终回落本地（storage_type=1）。
     *
     * @return array<string, string|int> 全套字段，storage_type 为 int
     */
    public static function resolveEffective(int $aid): array
    {
        $tenant = self::loadConfig($aid);

        if (self::cloudReady($tenant)) {
            return $tenant;
        }

        $platform = self::loadConfig(self::PLATFORM_AID);

        return self::cloudReady($platform) ? $platform : self::forceLocal($tenant);
    }

    /** 读取某 aid 下的全套附件配置（storage_type 规整为 int，缺省 1） */
    private static function loadConfig(int $aid): array
    {
        $data = [];
        foreach (self::FIELD_KEYS as $key) {
            $data[$key] = TenantConfigService::get($aid, self::configKey($key));
        }

        $storageType = (int) $data['storage_type'];
        if ($storageType < 1 || $storageType > 4) {
            $storageType = 1;
        }
        $data['storage_type'] = $storageType;

        return $data;
    }

    /** 云存储配置是否齐备（类型 2/3/4 且对应必填参数全部非空） */
    private static function cloudReady(array $config): bool
    {
        $type = (int) $config['storage_type'];

        if (!isset(self::CLOUD_REQUIRED[$type])) {
            return false;
        }

        foreach (self::CLOUD_REQUIRED[$type] as $key) {
            if ('' === trim((string) $config[$key])) {
                return false;
            }
        }

        return true;
    }

    /** 强制回落本地存储（保留压缩/删除类开关，供上层按需使用） */
    private static function forceLocal(array $config): array
    {
        $config['storage_type'] = 1;

        return $config;
    }

    /** 配置键：与超管侧同字段名（sys_remote_{field}），靠 aid 区分归属 */
    private static function configKey(string $key): string
    {
        return 'sys_remote_' . $key;
    }

    /**
     * 请求体（兼容 JSON 与表单）
     */
    protected function body(): array
    {
        $data = $this->request->post();

        if (empty($data)) {
            $raw = (string) $this->request->getInput();
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }

        return is_array($data) ? $data : [];
    }
}
