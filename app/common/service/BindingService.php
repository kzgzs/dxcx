<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;

/**
 * 租户端平台绑定中心服务
 *
 * 八端配置（wx/mp/alipay/app/baidu/qq/h5/toutiao）读取与保存：
 * - 手动绑定：appid 唯一性校验（同一 appid 不可被两个租户手动绑定）
 * - 授权绑定：auth_code 换取后同事务写 admin_setapp_wx|mp(authtype=1) + open_authorizer.aid
 * - 密钥类字段 GET 脱敏（***），POST 空串/*** 沿用原值
 * - 支付宝/百度/H5/APP 私钥公钥剥离 PEM 头尾
 */
class BindingService
{
    /** 脱敏占位（前端回显用；保存时提交该值等同空串=沿用原值） */
    public const MASK = '***';

    /** Redis 键前缀：租户绑定 access_token（appid 维度，7000s） */
    public const KEY_TOKEN = 'binding:access_token:';

    /**
     * 各端配置定义：table=落库表，fields=白名单可编辑字段，secret=密钥类（脱敏），int=整型
     *
     * @var array<string, array{table: string, fields: array<int, string>, secret: array<int, string>, int: array<int, string>, pem: array<int, string>}>
     */
    public const PLATFORMS = [
        'wx' => [
            'table'  => 'admin_setapp_wx',
            'fields' => ['appid', 'appsecret', 'token', 'key', 'nickname', 'headimg', 'qrcode', 'open_appid', 'signature',
                'wxpay', 'wxpay_type', 'wxpay_sub_mchid', 'wxpay_sub_mchid2', 'wxpay_mchid', 'wxpay_mchkey',
                'wxpay_apiclient_cert', 'wxpay_apiclient_key', 'wxpay_serial_no', 'sxpay_mno', 'sxpay_mchkey', 'sxpay_embedded',
                'wxpay_wechatpay_pem', 'transfer_scene_id', 'transfer_scene_type', 'transfer_scene_content',
                'wxpay_mchkey_v3', 'sign_type', 'public_key_id', 'public_key_pem', 'wxpay_plate_serialno'],
            'secret' => ['appsecret', 'wxpay_mchkey', 'wxpay_apiclient_cert', 'wxpay_apiclient_key', 'sxpay_mchkey', 'wxpay_mchkey_v3'],
            'int'    => ['wxpay', 'wxpay_type'],
            'pem'    => [],
        ],
        'mp' => [
            'table'  => 'admin_setapp_mp',
            'fields' => ['appid', 'appsecret', 'token', 'key', 'nickname', 'headimg', 'qrcode', 'open_appid',
                'wxpay', 'wxpay_type', 'wxpay_sub_mchid', 'wxpay_mchid', 'wxpay_mchkey',
                'wxpay_apiclient_cert', 'wxpay_apiclient_key', 'wxpay_serial_no', 'sxpay_mno', 'sxpay_mchkey',
                'wxpay_wechatpay_pem', 'transfer_scene_id', 'transfer_scene_type', 'transfer_scene_content',
                'wxpay_mchkey_v3', 'sign_type', 'public_key_id', 'public_key_pem', 'wxpay_plate_serialno'],
            'secret' => ['appsecret', 'wxpay_mchkey', 'wxpay_apiclient_cert', 'wxpay_apiclient_key', 'sxpay_mchkey', 'wxpay_mchkey_v3'],
            'int'    => ['wxpay', 'wxpay_type'],
            'pem'    => [],
        ],
        'alipay' => [
            'table'  => 'admin_setapp_alipay',
            'fields' => ['appid', 'appsecret', 'publickey', 'nickname', 'headimg', 'qrcode',
                'alipay', 'sxpay', 'sxpay_mno', 'sxpay_mchkey', 'openid_set', 'pay_mode', 'msid'],
            'secret' => ['appsecret', 'publickey', 'sxpay_mchkey'],
            'int'    => ['alipay', 'sxpay', 'openid_set', 'pay_mode'],
            'pem'    => ['appsecret', 'publickey'],
        ],
        'baidu' => [
            'table'  => 'admin_setapp_baidu',
            'fields' => ['appid', 'appkey', 'appsecret', 'nickname', 'headimg', 'qrcode',
                'baidupay', 'pay_appid', 'pay_appkey', 'pay_dealId', 'pay_publickey', 'pay_privatekey'],
            'secret' => ['appsecret', 'appkey', 'pay_appkey', 'pay_publickey', 'pay_privatekey'],
            'int'    => ['baidupay'],
            'pem'    => ['pay_publickey', 'pay_privatekey'],
        ],
        'qq' => [
            'table'  => 'admin_setapp_qq',
            'fields' => ['appid', 'appsecret', 'apptoken', 'nickname', 'headimg', 'qrcode',
                'wxpay', 'wxpay_appid', 'wxpay_type', 'wxpay_sub_mchid', 'wxpay_mchid', 'wxpay_mchkey',
                'wxpay_apiclient_cert', 'wxpay_apiclient_key'],
            'secret' => ['appsecret', 'apptoken', 'wxpay_mchkey', 'wxpay_apiclient_cert', 'wxpay_apiclient_key'],
            'int'    => ['wxpay', 'wxpay_type'],
            'pem'    => [],
        ],
        'toutiao' => [
            'table'  => 'admin_setapp_toutiao',
            'fields' => ['appid', 'appsecret', 'nickname', 'headimg', 'qrcode', 'toutiaopay', 'pay_token', 'pay_mchid', 'pay_salt'],
            'secret' => ['appsecret', 'pay_token', 'pay_salt'],
            'int'    => ['toutiaopay'],
            'pem'    => [],
        ],
        'h5' => [
            'table'  => 'admin_setapp_h5',
            'fields' => ['appid', 'wxpay', 'wxpay_type', 'wxpay_sub_mchid', 'wxpay_mchid', 'wxpay_mchkey',
                'wxpay_apiclient_cert', 'wxpay_apiclient_key', 'alipay', 'ali_appid', 'ali_privatekey', 'ali_publickey', 'alipay_type'],
            'secret' => ['wxpay_mchkey', 'wxpay_apiclient_cert', 'wxpay_apiclient_key', 'ali_privatekey', 'ali_publickey'],
            'int'    => ['wxpay', 'wxpay_type', 'alipay'],
            'pem'    => ['ali_privatekey', 'ali_publickey'],
        ],
        'app' => [
            'table'  => 'admin_setapp_app',
            'fields' => ['appid', 'appsecret', 'wxpay', 'wxpay_type', 'wxpay_sub_mchid', 'wxpay_mchid', 'wxpay_mchkey',
                'wxpay_apiclient_cert', 'wxpay_apiclient_key', 'alipay', 'ali_appid', 'ali_privatekey', 'ali_publickey',
                'androidurl', 'iosurl', 'transfer_scene_id', 'transfer_scene_type', 'transfer_scene_content',
                'wxpay_mchkey_v3', 'wxpay_wechatpay_pem', 'sign_type', 'public_key_id', 'public_key_pem',
                'wxpay_plate_serialno', 'wxpay_serial_no'],
            'secret' => ['appsecret', 'wxpay_mchkey', 'wxpay_apiclient_cert', 'wxpay_apiclient_key', 'ali_privatekey', 'ali_publickey', 'wxpay_mchkey_v3'],
            'int'    => ['wxpay', 'wxpay_type', 'alipay'],
            'pem'    => ['ali_privatekey', 'ali_publickey'],
        ],
    ];

    public function __construct(protected OpenPlatformService $open)
    {
    }

    /* ==================== 总览 ==================== */

    /**
     * 各端绑定状态聚合
     *
     * @return array<string, array<string, mixed>>
     */
    public function overview(int $aid): array
    {
        $result = [];

        foreach (self::PLATFORMS as $key => $def) {
            $row = ScopeService::query($def['table'], $aid)->find();

            $appid = (string) ($row['appid'] ?? '');
            $item  = [
                'bound'    => '' !== $appid,
                'appid'    => $appid,
                'nickname' => (string) ($row['nickname'] ?? ''),
            ];

            if ('wx' === $key || 'mp' === $key) {
                $authtype     = (int) ($row['authtype'] ?? 0);
                $item['authtype']  = $appid ? $authtype : 0;
                $item['mode']      = '' === $appid ? '未绑定' : (1 === $authtype ? '平台授权' : '手动配置');
                $item['headimg']   = (string) ($row['headimg'] ?? '');
                $item['qrcode']    = (string) ($row['qrcode'] ?? '');
                $item['level']     = (int) ($row['level'] ?? 0);
                $item['auth_status'] = null;

                if ('' !== $appid && 1 === $authtype) {
                    $auth = Db::name('open_authorizer')->where('authorizer_appid', $appid)->find();
                    $item['auth_status'] = $auth ? (int) $auth['auth_status'] : null;
                }
            }

            $result[$key] = $item;
        }

        return $result;
    }

    /* ==================== 配置读取/保存 ==================== */

    /**
     * 读取单端配置（密钥类字段脱敏）
     *
     * @return array<string, mixed>
     */
    public function getConfig(int $aid, string $platform): array
    {
        $def = $this->platform($platform);
        $row = ScopeService::query($def['table'], $aid)->find() ?: [];

        $data = [];
        foreach ($def['fields'] as $field) {
            $value = (string) ($row[$field] ?? '');
            if (in_array($field, $def['secret'], true)) {
                $value = '' === $value ? '' : self::MASK;
            }
            $data[$field] = $value;
        }

        // 整型回显
        foreach ($def['int'] as $field) {
            $data[$field] = (int) ($row[$field] ?? 0);
        }

        if ('wx' === $platform || 'mp' === $platform) {
            $data['authtype'] = '' !== $data['appid'] ? (int) ($row['authtype'] ?? 0) : 0;
        }

        return $data;
    }

    /**
     * 保存单端手动配置（upsert by aid；密钥空值/*** 沿用原值）
     *
     * @param array<string, mixed> $post
     */
    public function saveConfig(int $aid, string $platform, array $post): void
    {
        $def      = $this->platform($platform);
        $existing = ScopeService::query($def['table'], $aid)->find();

        $save = [];
        foreach ($def['fields'] as $field) {
            if (!array_key_exists($field, $post)) {
                continue;
            }

            $value = is_scalar($post[$field]) ? trim((string) $post[$field]) : '';

            // 密钥类：空串或脱敏占位 = 沿用原值
            if (in_array($field, $def['secret'], true) && ('' === $value || self::MASK === $value)) {
                continue;
            }

            // PEM 头尾剥离（私钥/公钥入库统一裸 base64）
            if (in_array($field, $def['pem'], true) && '' !== $value) {
                $value = $this->stripPem($value);
            }

            $save[$field] = in_array($field, $def['int'], true) ? (int) $value : $value;
        }

        // wx/mp：商户号格式校验（微信商户号/子商户号均为纯数字）
        foreach (['wxpay_mchid', 'wxpay_sub_mchid', 'wxpay_sub_mchid2'] as $mchField) {
            $mchValue = (string) ($save[$mchField] ?? '');
            if ('' !== $mchValue && !ctype_digit($mchValue)) {
                throw new BusinessException('商户号格式错误（应为纯数字）：' . $mchField);
            }
        }

        // wx/mp：手动保存走 authtype=0，appid 唯一性校验
        if ('wx' === $platform || 'mp' === $platform) {
            $appid = (string) ($save['appid'] ?? $existing['appid'] ?? '');
            if ('' === $appid) {
                throw new BusinessException('请填写 AppID');
            }
            $save['appid'] = $appid;
            $this->assertAppidUnique($def['table'], $aid, $appid);

            // 手动配置覆盖授权状态：清 refresh_token，authtype 归 0
            $save['authtype']      = 0;
            $save['refresh_token'] = '';
        }

        if (empty($save)) {
            throw new BusinessException('没有需要保存的配置项');
        }

        if (empty($existing)) {
            $save['aid'] = $aid;
            ScopeService::query($def['table'], $aid)->insert($save);
        } else {
            ScopeService::query($def['table'], $aid)->where('id', (int) $existing['id'])->update($save);
        }
    }

    /* ==================== 授权绑定（开放平台） ==================== */

    /**
     * 生成开放平台授权链接
     *
     * @param int $authType 1公众号 2小程序
     * @return array{url: string, expires_in: int}
     */
    public function wxAuthUrl(int $aid, int $authType): array
    {
        if (!in_array($authType, [1, 2], true)) {
            throw new BusinessException('授权类型不合法（1公众号/2小程序）');
        }

        return [
            'url'        => $this->open->buildAuthUrl($authType, $aid, (string) $aid),
            'expires_in' => 1800,
        ];
    }

    /**
     * 授权回调落库：同事务写 admin_setapp_wx|mp(authtype=1) + open_authorizer.aid + access_token
     *
     * @return array<string, mixed> 落库后的授权方记录
     */
    public function bindByAuthCode(string $authCode, int $aid): array
    {
        // 微信 API 调用置于事务外（auth_code 一次性，避免事务回滚后重放）
        $authInfo = $this->open->queryAuth($authCode);
        $appid    = (string) ($authInfo['authorizer_appid'] ?? '');
        if ('' === $appid) {
            throw new BusinessException('授权信息缺少 authorizer_appid');
        }
        $info = $this->open->getAuthorizerInfo($appid);

        // 目标表：小程序资料存在 → 小程序(wx)，否则公众号(mp)
        $table = isset($info['mini_program_info']) ? 'admin_setapp_wx' : 'admin_setapp_mp';

        // 冲突校验：该 appid 已被其他租户手动绑定/授权绑定
        $manual = Db::name($table)->where('appid', $appid)->where('authtype', 0)
            ->where('aid', '<>', $aid)->find();
        if (!empty($manual)) {
            throw new BusinessException('该应用已被其他站点手动绑定，无法授权');
        }
        $authed = Db::name('open_authorizer')->where('authorizer_appid', $appid)->find();
        if (!empty($authed) && !empty($authed['aid']) && (int) $authed['aid'] !== $aid) {
            throw new BusinessException('该应用已授权绑定其他站点');
        }

        $level = $this->calcLevel($info);

        return Db::transaction(function () use ($authInfo, $info, $appid, $aid, $table, $level, $authCode) {
            // 1. open_authorizer（aid 落绑定）
            $authorizer = $this->open->saveAuthorizerByAuthInfo($authInfo, $info, $aid, $authCode);

            // 2. 租户绑定表（保留既有支付配置，仅覆盖授权相关字段）
            $set = [
                'appid'         => $appid,
                'authtype'      => 1,
                'level'         => $level,
                'refresh_token' => (string) ($authInfo['authorizer_refresh_token'] ?? ''),
                'nickname'      => (string) ($info['nick_name'] ?? ''),
                'headimg'       => (string) ($info['head_img'] ?? ''),
                'qrcode'        => (string) ($info['qrcode_url'] ?? ''),
            ];
            $row = ScopeService::query($table, $aid)->find();
            if (empty($row)) {
                $set['aid'] = $aid;
                ScopeService::query($table, $aid)->insert($set);
            } else {
                ScopeService::query($table, $aid)->where('id', (int) $row['id'])->update($set);
            }

            // 3. access_token 表兜底（Redis 主存见下）
            $token    = (string) ($authInfo['authorizer_access_token'] ?? '');
            $expire   = time() + 7000;
            $tokenRow = Db::name('access_token')->where('appid', $appid)->find();
            if (empty($tokenRow)) {
                Db::name('access_token')->insert(['appid' => $appid, 'access_token' => $token, 'expires_time' => $expire]);
            } else {
                Db::name('access_token')->where('id', $tokenRow['id'])->update(['access_token' => $token, 'expires_time' => $expire]);
            }

            return $authorizer;
        });
    }

    /**
     * 重新拉取授权信息（authtype=1 的绑定）
     *
     * @return array<string, mixed> 更新后的展示信息
     */
    public function refreshAuth(int $aid, string $platform): array
    {
        if (!in_array($platform, ['wx', 'mp'], true)) {
            throw new BusinessException('仅微信端支持重新拉取授权');
        }

        $def = self::PLATFORMS[$platform];
        $row = ScopeService::query($def['table'], $aid)->find();
        if (empty($row) || 1 !== (int) ($row['authtype'] ?? 0) || '' === (string) ($row['appid'] ?? '')) {
            throw new BusinessException('当前为手动配置或未绑定，无法拉取授权信息');
        }

        $appid = (string) $row['appid'];
        $info  = $this->open->getAuthorizerInfo($appid);
        $level = $this->calcLevel($info);

        $set = [
            'nickname' => (string) ($info['nick_name'] ?? ''),
            'headimg'  => (string) ($info['head_img'] ?? ''),
            'qrcode'   => (string) ($info['qrcode_url'] ?? ''),
            'level'    => $level,
        ];
        ScopeService::query($def['table'], $aid)->where('id', (int) $row['id'])->update($set);

        // 同步 open_authorizer 资料
        Db::name('open_authorizer')->where('authorizer_appid', $appid)->update([
            'nick_name'       => $set['nickname'],
            'head_img'        => $set['headimg'],
            'qrcode_url'      => $set['qrcode'],
            'service_type_id' => (int) ($info['service_type_info']['id'] ?? 0),
            'verify_type_id'  => (int) ($info['verify_type_info']['id'] ?? 0),
            'update_time'     => time(),
        ]);

        return $set + ['appid' => $appid, 'authtype' => 1];
    }

    /* ==================== 工具 ==================== */

    /**
     * 平台定义（非法 key 抛业务异常）
     *
     * @return array{table: string, fields: array<int, string>, secret: array<int, string>, int: array<int, string>, pem: array<int, string>}
     */
    public function platform(string $key): array
    {
        if (!isset(self::PLATFORMS[$key])) {
            throw new BusinessException('不支持的平台端：' . $key);
        }

        return self::PLATFORMS[$key];
    }

    /**
     * 手动绑定唯一性校验：同一 appid 不可被两个租户手动绑定
     */
    protected function assertAppidUnique(string $table, int $aid, string $appid): void
    {
        $exists = Db::name($table)->where('appid', $appid)->where('authtype', 0)
            ->where('aid', '<>', $aid)->find();
        if (!empty($exists)) {
            throw new BusinessException('该 AppID 已被其他站点手动绑定，请核对后重试');
        }
    }

    /**
     * 认证级别：4认证服务号 3认证订阅号 2未认证服务号 1未认证订阅号
     *
     * @param array<string, mixed> $info authorizer_info
     */
    public function calcLevel(array $info): int
    {
        $service = (int) ($info['service_type_info']['id'] ?? 0);
        $verify  = (int) ($info['verify_type_info']['id'] ?? -1);

        if (2 === $service) {
            return $verify > -1 ? 4 : 2;
        }

        return $verify > -1 ? 3 : 1;
    }

    /**
     * 剥离 PEM 头尾与空白（-----BEGIN/END xxx-----、换行空格）
     */
    protected function stripPem(string $value): string
    {
        $value = (string) preg_replace('/-----BEGIN [^-]+-----/i', '', $value);
        $value = (string) preg_replace('/-----END [^-]+-----/i', '', $value);

        return trim((string) preg_replace('/[\s\r\n]+/', '', $value));
    }
}
