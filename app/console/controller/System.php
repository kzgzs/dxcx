<?php

declare(strict_types=1);

namespace app\console\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\TenantConfigService;
use app\common\service\UploadService;
use think\facade\Cache;
use think\facade\Db;

/**
 * 超管控制台 - 系统设置 / 版本 / 缓存
 *
 * 全局配置复用 tenant_config 键值表（aid=0 代表平台级），
 * key 统一前缀 sys_{group}_{field}，白名单管控防止任意键写入。
 */
class System extends BaseController
{
    /**
     * 各配置分组允许的字段白名单
     * wxpay = 微信支付服务商配置；sxpay = 随行付服务商配置；
     * base = 站点基础设置；remote = 附件存储
     */
    private const GROUP_KEYS = [
        // 微信支付服务商配置（对照参考系统 WebSystem/wxpayset 字段口径）
        'wxpay'  => [
            'mchname',        // 服务商名称
            'appid',          // 服务商AppID
            'mchid',          // 支付商户号
            'mchkey',         // 支付密钥（V3 口径为 APIv3 密钥，敏感）
            'apiclient_cert', // PEM证书路径
            'apiclient_key',  // 证书密钥路径
            'serial_no',      // 商户API证书序列号（V3 请求签名用）
            'wechatpay_pem',  // 微信支付平台证书路径（V3 回调验签用）
            'chouchengset',   // 默认抽成（0 不抽成/1 按百分百抽成/2 固定抽成）
            'chouchengrate',  // 抽成比例(%)
            'chouchengmin',   // 最低抽成金额(元)
            'chouchengmoney', // 抽成金额(元)（固定抽成）
        ],
        // 随行付服务商配置（对照参考系统 WebSystem/sxpayset 字段口径）
        'sxpay'  => [
            'mchname',    // 服务商名称
            'orgId',      // 机构号
            'orgIdOne',   // 一级机构号（选填，后台申请分账时使用）
            'publicKey',  // 公钥（敏感）
            'privateKey', // 私钥（敏感）
            'signType',   // 密钥类型 RSA/RSA2
            'feepercent', // 进件费率
        ],
        // 系统基础设置（对照参考系统 web_system/set.html）
        'system' => [
            'system_name',                     // 系统名称（新字段名）
            'logo',                            // LOGO
            'ico',                             // 浏览器 favicon 图标
            'admin_loginbg',                   // 后台登录页背景图（新字段名）
            'copyright',                       // 版权设置
            'beian',                           // ICP 备案号
            'gongan_text',                     // 公安备案文字（新字段名）
            'gongan_code',                     // 公安备案号（新字段名）
            'showweb',                         // 官网显示模板 (0/1/2)
            'company',                         // 企业名称
            'address',                         // 地址
            'email',                           // 邮箱
            'tel',                             // 电话
            'weixin',                          // 微信
            'sitemap',                         // 网站地图
            'fastregxcx',                      // 快速注册小程序 (1 开启/0 关闭)
            'reg_open',                        // 开启注册 (0/1)
            'reg_agreement_url',               // 注册协议链接（新字段名）
            'reg_valid_days',                  // 注册会员有效期 (天)（新字段名）
            'sms_verify',                      // 注册短信验证码 (0/1)（新字段名）
            'open_login_page',                 // 开启独立登录地址 (0/1)
            'check_login_page',                // 独立入口强制编码 (0/1)
            'sms_login',                       // 开启短信登录 (0/1/2)
            'kuaidi_appcode',                  // 快递查询 AppCode（新字段名）
            'map_qq_key',                      // 腾讯地图 key（新字段名）
            'map_amap_key',                    // 高德地图 key（新字段名）
            'map_amap_jscode',                 // 高德地图安全密钥（新字段名）
            'wx_verify_type',                  // 微信认证 (0 关闭/1 服务商 + 自费/2 服务商额度)（新字段名）
            'cron_key',                        // 计划任务访问密钥（ApiAuto 定时任务 URL 校验用，首次读取自动生成）
        ],
        'base'   => ['site_name', 'service_phone', 'service_email', 'icp'],
        'remote' => [
            'storage_type',                  // 存储类型（1 本地/2 阿里云/3 七牛云/4 腾讯云）
            'oss_key',                       // 阿里云 Access Key ID
            'oss_secret',                    // 阿里云 Access Key Secret
            'oss_bucket',                    // 阿里云 Bucket 名称
            'oss_endpoint',                  // 阿里云EndPoint（地域节点）
            'oss_domain',                    // 阿里云Bucket域名
            'qiniu_accesskey',               // 七牛云AccessKey
            'qiniu_secretkey',               // 七牛云SecretKey
            'qiniu_bucket',                  // 七牛云Bucket
            'qiniu_url',                     // 七牛云Url
            'cos_appid',                     // 腾讯云 APPID
            'cos_secretid',                  // 腾讯云 SecretID
            'cos_secretkey',                 // 腾讯云 SecretKEY
            'cos_bucket',                    // 腾讯云 Bucket
            'cos_local',                     // 腾讯云地域代码
            'cos_url',                       // 腾讯云 Url
            'thumb',                         // 图片压缩（0 关闭/1 开启）
            'thumb_width',                   // 图片最大宽度
            'thumb_height',                  // 图片最大高度
            'delete_origin',                 // 删除原图（0 关闭/1 开启）
            'thumb_quality',                 // 图片质量（0-100）
            'delete_local',                  // 云存储上传后删除服务器文件（0 关闭/1 开启）
            'file_image_limit',              // 图片上传限制（MB）
            'file_video_limit',              // 视频上传限制（MB）
            'file_other_limit',              // 其他文件上传限制（MB）
            'file_limit_user',               // 用户独立设置限制（0 关闭/1 开启）
            'file_upload_limit',             // 用户附件上限（MB）
        ],
    ];

    /** 平台级配置统一用 aid=0 */
    private const PLATFORM_AID = 0;

    /** 配置分组中文名（测试获取缓存数据的分类下拉） */
    private const GROUP_NAMES = [
        'system' => '系统设置',
        'base'   => '站点基础',
        'remote' => '附件存储',
        'wxpay'  => '微信支付服务商',
        'sxpay'  => '随行付服务商',
    ];

    /** 各分组敏感字段（GET 下发脱敏，保存时掩码原样返回则保留原值） */
    private const SECRET_KEYS = [
        'wxpay' => ['mchkey'],
        'sxpay' => ['publicKey', 'privateKey'],
    ];

    /** 敏感字段脱敏掩码（前端原样回传代表不修改） */
    private const SECRET_MASK = '******';

    /**
     * 清理缓存页勾选项（对照参考系统清理缓存页 5 个复选框）
     * global=全局缓存；console_system=控制台配置缓存；
     * tenant_*=所有租户对应分类的配置缓存
     */
    private const CLEAR_ITEMS = [
        'global'          => '全局缓存',
        'console_system'  => '控制台-系统设置',
        'tenant_system'   => '所有用户-系统-系统设置',
        'tenant_shop'     => '所有用户-商城-系统设置',
        'tenant_merchant' => '所有用户-商户-系统设置',
    ];

    /**
     * 租户配置分类 => tenant_config 键前缀
     * （tenant_config 为键值表无 group 列，按键前缀归入 系统/商城/商户 三类）
     */
    private const TENANT_GROUP_PREFIXES = [
        'tenant_system'   => ['sys_'],
        'tenant_shop'     => ['shop_', 'score_', 'promotion_', 'coupon_', 'goods_'],
        'tenant_merchant' => ['merchant_', 'business_', 'store_'],
    ];

    /**
     * 缓存项白名单（对照参考系统后台缓存构成：数据缓存 + 模板缓存 + 文件缓存 + 会话缓存）
     * dir 为空表示缓存驱动（Redis 键值缓存），否则为 runtime 各应用下的同名子目录
     */
    private const CACHE_ITEMS = [
        'data'     => ['name' => '数据缓存', 'desc' => 'Redis 键值缓存（配置、查询等业务缓存）', 'dir' => ''],
        'template' => ['name' => '模板缓存', 'desc' => '模板编译生成的缓存文件', 'dir' => 'temp'],
        'file'     => ['name' => '文件缓存', 'desc' => '运行时生成的文件缓存', 'dir' => 'cache'],
        'session'  => ['name' => '会话缓存', 'desc' => '文件会话数据', 'dir' => 'session'],
    ];

    /**
     * 读取某组全局配置
     * GET /console/system/config?group=wxpay|base|remote
     */
    public function config()
    {
        $group = (string) $this->request->param('group', '');

        if (!isset(self::GROUP_KEYS[$group])) {
            return $this->error('未知的配置分组');
        }

        $data = [];
        foreach (self::GROUP_KEYS[$group] as $key) {
            $data[$key] = TenantConfigService::get(self::PLATFORM_AID, $this->configKey($group, $key));
        }

        // 计划任务密钥：首次读取时自动生成（对照参考系统 ApiAuto 计划任务 URL 的 key 参数）
        if ('system' === $group && '' === $data['cron_key']) {
            $data['cron_key'] = bin2hex(random_bytes(16));
            TenantConfigService::set(self::PLATFORM_AID, $this->configKey($group, 'cron_key'), $data['cron_key']);
        }

        // 附件设置页额外下发 PHP 真实上传限制（只读展示，不可写回配置库）
        if ('remote' === $group) {
            $data['upload_max_filesize'] = (string) ini_get('upload_max_filesize');
        }

        // 敏感字段脱敏：有值下发掩码，前端据此显示“已隐藏内容，点击查看或编辑”
        foreach (self::SECRET_KEYS[$group] ?? [] as $secretKey) {
            if ('' !== $data[$secretKey]) {
                $data[$secretKey] = self::SECRET_MASK;
            }
        }

        return $this->success($data);
    }

    /**
     * 保存某组全局配置
     * POST /console/system/config body:{group, data:{key:value}}
     */
    public function saveConfig()
    {
        $body  = $this->body();
        $group = (string) ($body['group'] ?? '');

        if (!isset(self::GROUP_KEYS[$group])) {
            return $this->error('未知的配置分组');
        }

        $data = $body['data'] ?? [];
        if (!is_array($data)) {
            return $this->error('配置内容格式不正确');
        }

        foreach (self::GROUP_KEYS[$group] as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $value = trim((string) $data[$key]);

            // 敏感字段：掩码原样回传代表未修改，保留原值
            if (in_array($key, self::SECRET_KEYS[$group] ?? [], true)) {
                if (self::SECRET_MASK === $value) {
                    continue;
                }
                // 公私钥去头去尾去回车，一行字符串（对照参考系统 sxpayset 保存逻辑）
                if ('sxpay' === $group) {
                    $value = (string) preg_replace('/\s/', '', $value);
                }
            }

            // 证书路径校验 .pem 后缀（对照参考系统 wxpayset 保存逻辑）
            if ('wxpay' === $group && in_array($key, ['apiclient_cert', 'apiclient_key'], true)
                && '' !== $value && !str_ends_with($value, '.pem')) {
                return $this->error('apiclient_cert' === $key ? 'PEM证书格式错误' : '证书密钥格式错误');
            }

            $maxLen = in_array($key, self::SECRET_KEYS[$group] ?? [], true) ? 4096 : 255;
            if (mb_strlen($value) > $maxLen) {
                return $this->error('配置项「' . $key . '」内容过长');
            }
            TenantConfigService::set(self::PLATFORM_AID, $this->configKey($group, $key), $value);
        }

        return $this->success();
    }

    /**
     * 查看敏感配置原文（密钥类字段点击“查看或编辑”时调用）
     * GET /console/system/revealSecret?group=wxpay|sxpay&field=
     */
    public function revealSecret()
    {
        $group = (string) $this->request->param('group', '');
        $field = (string) $this->request->param('field', '');

        if (!isset(self::GROUP_KEYS[$group])) {
            return $this->error('未知的配置分组');
        }
        if (!in_array($field, self::SECRET_KEYS[$group] ?? [], true)) {
            return $this->error('该字段不支持查看');
        }

        return $this->success([
            'value' => TenantConfigService::get(self::PLATFORM_AID, $this->configKey($group, $field)),
        ]);
    }

    /**
     * 微信支付证书上传（apiclient_cert.pem / apiclient_key.pem）
     * POST /console/system/uploadCert  multipart，字段 file
     * 存储 public/uploads/cert/，返回站点相对路径
     */
    public function uploadCert()
    {
        $file = $this->request->file('file');
        if (!$file) {
            return $this->error('请选择要上传的证书文件');
        }

        try {
            $result = (new UploadService())->certificate($file);
        } catch (BusinessException $e) {
            return $this->error($e->getMessage());
        }

        return $this->success($result, '上传成功');
    }

    /**
     * 当前系统版本号（优先读根目录 version.php）
     * GET /console/system/version
     */
    public function version()
    {
        $version = '1.0.0';
        $file    = $this->app->getRootPath() . 'version.php';
        if (is_file($file)) {
            $content = trim((string) file_get_contents($file));
            if ('' !== $content) {
                $version = $content;
            }
        }

        return $this->success([
            'version' => $version,
            'php'     => PHP_VERSION,
            'think'   => $this->app->version(),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
        ]);
    }

    /**
     * 缓存统计：返回各缓存项大小（cache、temp、session 目录文件数与体积，Redis 键数）
     * GET /console/system/cacheStat
     */
    public function cacheStat()
    {
        $items = [];
        foreach (self::CACHE_ITEMS as $key => $meta) {
            $items[] = $this->itemStat($key);
        }

        return $this->success(['items' => $items]);
    }

    /**
     * 分项清理缓存
     * POST /console/system/clearCacheItem body:{item:data|template|file|session}
     */
    public function clearCacheItem()
    {
        $body = $this->body();
        $item = (string) ($body['item'] ?? '');

        if (!isset(self::CACHE_ITEMS[$item])) {
            return $this->error('未知的缓存项');
        }

        [$message] = $this->clearItem($item);

        return $this->success([
            'item'    => $this->itemStat($item),
            'message' => $message,
        ]);
    }

    /**
     * 一键清理缓存：全部缓存项（默认缓存驱动 + runtime 下各缓存目录）
     * POST /console/system/clearCache
     */
    public function clearCache()
    {
        $results   = [];
        $fileCount = 0;

        foreach (array_keys(self::CACHE_ITEMS) as $item) {
            [$message, $files] = $this->clearItem($item);
            $results[]  = $message;
            $fileCount += $files;
        }

        return $this->success([
            'results'    => $results,
            'file_count' => $fileCount,
            'items'      => $this->cacheStatItems(),
        ]);
    }

    /**
     * 按勾选项清理缓存（对照参考系统清理缓存页：复选框提交，返回每项清理明细）
     * POST /console/system/clearCacheByItems body:{items:[global|console_system|tenant_system|tenant_shop|tenant_merchant]}
     */
    public function clearCacheByItems()
    {
        $body  = $this->body();
        $items = $body['items'] ?? [];

        if (!is_array($items) || [] === $items) {
            return $this->error('请选择要清理的内容');
        }

        $details = [];
        foreach ($items as $item) {
            $item = (string) $item;
            if (!isset(self::CLEAR_ITEMS[$item])) {
                return $this->error('未知的清理项：' . $item);
            }
            $details[] = [
                'key'     => $item,
                'name'    => self::CLEAR_ITEMS[$item],
                'message' => $this->clearByItem($item),
            ];
        }

        return $this->success(['details' => $details]);
    }

    /**
     * 配置分组列表（测试获取缓存数据 - 设置分类下拉）
     * GET /console/system/cacheGroups
     */
    public function cacheGroups()
    {
        $groups = [];
        foreach (self::GROUP_KEYS as $group => $keys) {
            $groups[] = [
                'group' => $group,
                'name'  => self::GROUP_NAMES[$group] ?? $group,
            ];
        }

        return $this->success(['groups' => $groups]);
    }

    /**
     * 某分组下的参数列表（测试获取缓存数据 - 设置参数下拉，联动分类）
     * GET /console/system/cacheKeys?group=
     */
    public function cacheKeys()
    {
        $group = (string) $this->request->param('group', '');

        if (!isset(self::GROUP_KEYS[$group])) {
            return $this->error('未知的配置分组');
        }

        return $this->success(['keys' => array_values(self::GROUP_KEYS[$group])]);
    }

    /**
     * 测试获取缓存数据：返回某配置参数的当前缓存值与库中值
     * GET /console/system/cacheQuery?group=&key=
     */
    public function cacheQuery()
    {
        $group = (string) $this->request->param('group', '');
        $key   = (string) $this->request->param('key', '');

        if (!isset(self::GROUP_KEYS[$group])) {
            return $this->error('未知的配置分组');
        }
        if (!in_array($key, self::GROUP_KEYS[$group], true)) {
            return $this->error('未知的配置参数');
        }

        $full   = $this->configKey($group, $key);
        $cached = TenantConfigService::cachedValue(self::PLATFORM_AID, $full);
        $db     = TenantConfigService::dbValue(self::PLATFORM_AID, $full);

        return $this->success([
            'group'       => $group,
            'key'         => $key,
            'cache_hit'   => null !== $cached,
            'cache_value' => $cached,
            'db_value'    => $db,
            'value'       => $cached ?? $db ?? '',
        ]);
    }

    /**
     * 执行单个勾选项的清理，返回结果文案
     */
    private function clearByItem(string $item): string
    {
        // 全局缓存：Redis 缓存整体清空 + runtime/cache 文件夹清空
        if ('global' === $item) {
            $redisMsg = 'Redis 缓存清理完成';
            try {
                Cache::clear();
            } catch (\Throwable $e) {
                $redisMsg = 'Redis 缓存清理失败：' . $e->getMessage();
            }

            $count = 0;
            foreach ($this->runtimeAppDirs() as $dir) {
                $target = $dir . DIRECTORY_SEPARATOR . 'cache';
                if (is_dir($target)) {
                    $count += $this->clearDir($target);
                }
            }

            return $redisMsg . '，runtime/cache 已清空（删除 ' . $count . ' 个文件）';
        }

        // 控制台-系统设置：清平台级（aid=0）各配置分组的缓存
        if ('console_system' === $item) {
            $pairs = [];
            foreach (self::GROUP_KEYS as $group => $keys) {
                foreach ($keys as $key) {
                    $pairs[] = [self::PLATFORM_AID, $this->configKey($group, $key)];
                }
            }
            $count = TenantConfigService::forgetPairs($pairs);

            return '控制台配置缓存已清理（命中 ' . $count . ' 条）';
        }

        // 所有用户-*：按租户批量清对应分类的配置缓存
        $prefixes = self::TENANT_GROUP_PREFIXES[$item] ?? [];
        $aids     = Db::name('admin')->where('deleted', 0)->column('id');

        if ([] === $aids || [] === $prefixes) {
            return '已清理 0 个租户的配置缓存';
        }

        $query = Db::name('tenant_config')->whereIn('aid', $aids)->field('aid, `key`');
        $rows  = $query->select()->toArray();

        $pairs = [];
        foreach ($rows as $row) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with((string) $row['key'], $prefix)) {
                    $pairs[] = [(int) $row['aid'], (string) $row['key']];
                    break;
                }
            }
        }

        $count = TenantConfigService::forgetPairs($pairs);

        return '已清理 ' . count($aids) . ' 个租户的配置缓存（命中 ' . $count . ' 条）';
    }

    /**
     * 全部缓存项统计
     */
    private function cacheStatItems(): array
    {
        $items = [];
        foreach (array_keys(self::CACHE_ITEMS) as $key) {
            $items[] = $this->itemStat($key);
        }

        return $items;
    }

    /**
     * 单个缓存项统计：key/name/desc/count/size/size_text
     */
    private function itemStat(string $key): array
    {
        $meta = self::CACHE_ITEMS[$key];
        $row  = ['key' => $key, 'name' => $meta['name'], 'desc' => $meta['desc']];

        if ('' === $meta['dir']) {
            // Redis 缓存驱动：统计带缓存前缀的键数
            try {
                $count         = $this->redisKeyCount();
                $row['count']  = $count;
                $row['size']   = 0;
                $row['size_text'] = $count . ' 个缓存键';
            } catch (\Throwable $e) {
                $row['count']  = 0;
                $row['size']   = 0;
                $row['size_text'] = '连接失败';
            }

            return $row;
        }

        [$count, $size] = $this->dirStat($meta['dir']);
        $row['count']     = $count;
        $row['size']      = $size;
        $row['size_text'] = $count > 0 ? $count . ' 个文件 / ' . $this->formatSize($size) : '0 个文件';

        return $row;
    }

    /**
     * 清理单个缓存项，返回 [结果文案, 删除文件数]
     * @return array{0:string,1:int}
     */
    private function clearItem(string $item): array
    {
        $meta = self::CACHE_ITEMS[$item];

        if ('' === $meta['dir']) {
            try {
                Cache::clear();

                return [$meta['name'] . '清理完成', 0];
            } catch (\Throwable $e) {
                return [$meta['name'] . '清理失败：' . $e->getMessage(), 0];
            }
        }

        $count = 0;
        foreach ($this->runtimeAppDirs() as $dir) {
            $target = $dir . DIRECTORY_SEPARATOR . $meta['dir'];
            if (is_dir($target)) {
                $count += $this->clearDir($target);
            }
        }

        return [$meta['name'] . '清理完成，已删除 ' . $count . ' 个文件', $count];
    }

    /**
     * Redis 缓存键数量（只统计带缓存前缀的键，不扫描会话/队列等其他键）
     */
    private function redisKeyCount(): int
    {
        /** @var \Redis $redis */
        $redis  = Cache::store('redis')->handler();
        $prefix = (string) config('cache.stores.redis.prefix', '');

        $redis->setOption(\Redis::OPT_SCAN, \Redis::SCAN_RETRY);
        $count  = 0;
        $cursor = null;
        while (false !== ($keys = $redis->scan($cursor, '*', 500))) {
            foreach ($keys as $key) {
                if ('' === $prefix || str_starts_with((string) $key, $prefix)) {
                    ++$count;
                }
            }
        }

        return $count;
    }

    /**
     * runtime 下各应用目录（多应用模式下 getRuntimePath 已指向单应用目录，须用根 runtime）
     */
    private function runtimeAppDirs(): array
    {
        $base = $this->app->getRootPath() . 'runtime' . DIRECTORY_SEPARATOR;
        $dirs = glob($base . '*') ?: [];

        return array_values(array_filter($dirs, 'is_dir'));
    }

    /**
     * 统计 runtime 各应用下 {sub} 目录的文件数与体积
     * @return array{0:int,1:int}
     */
    private function dirStat(string $sub): array
    {
        $count = 0;
        $size  = 0;
        foreach ($this->runtimeAppDirs() as $dir) {
            $target = $dir . DIRECTORY_SEPARATOR . $sub;
            if (!is_dir($target)) {
                continue;
            }
            [$c, $s] = $this->dirSize($target);
            $count += $c;
            $size  += $s;
        }

        return [$count, $size];
    }

    /**
     * 递归统计目录文件数与字节数
     * @return array{0:int,1:int}
     */
    private function dirSize(string $dir): array
    {
        $count = 0;
        $size  = 0;
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                [$c, $s] = $this->dirSize($path);
                $count += $c;
                $size  += $s;
            } elseif (is_file($path)) {
                ++$count;
                $size += (int) filesize($path);
            }
        }

        return [$count, $size];
    }

    /**
     * 字节体积转可读文案
     */
    private function formatSize(int $size): string
    {
        if ($size >= 1048576) {
            return round($size / 1048576, 2) . ' MB';
        }
        if ($size >= 1024) {
            return round($size / 1024, 2) . ' KB';
        }

        return $size . ' B';
    }

    /**
     * 递归删除目录下的文件（保留目录本身），返回删除文件数
     */
    private function clearDir(string $dir): int
    {
        $count = 0;
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $count += $this->clearDir($path);
                @rmdir($path);
            } elseif (@unlink($path)) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * 平台配置键：sys_{group}_{key}
     */
    private function configKey(string $group, string $key): string
    {
        return 'sys_' . $group . '_' . $key;
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
