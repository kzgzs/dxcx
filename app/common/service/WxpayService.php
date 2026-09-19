<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\facade\Db;
use think\facade\Log;

/**
 * 微信支付 V3 业务编排（真实链路：下单/回调/退款/分账/查单补偿）
 *
 * 模式切换（读租户 admin_setapp_wx.wxpay_type）：
 * - 0 直连：用租户自身商户号（wxpay_mchid/证书/wxpay_mchkey_v3），POST /v3/pay/transactions/jsapi
 * - 1 服务商：平台 wxpayset 服务商凭据（tenant_config aid=0 sys_wxpay_*）+ 租户 sub_mchid，
 *   POST /v3/pay/partner/transactions/jsapi，抽成下单带 settle_info.profit_sharing=true
 *
 * 数据落库口径（对齐参考系统）：
 * - payorder      统一真实渠道支付单（金额比对基准 total_fee == money*100）
 * - wxpay_log     支付记录（退款时 sub_mchid 从这里取，保证原路退回）
 * - wxrefund_log  退款记录（退款回调回写状态 + payorder.refund_money）
 * - wxpay_fzlog   分账记录（接收方=超管抽成配置的服务商商户号）
 * - payorder_log  回调原始记录
 *
 * 本类全部静态方法，避免与 PayService 构造注入产生循环依赖。
 */
class WxpayService
{
    /** 微信分账比例上限（平台硬限制 30%） */
    private const PROFIT_SHARING_MAX_RATIO = 0.30;

    /* ================= 配置装载 ================= */

    /**
     * 装载租户微信支付配置（直连/服务商双模式）
     *
     * @return array{mode: string, wxpay_type: int, appid: string, mchid: string,
     *               sp_appid: string, sub_appid: string, sub_mchid: string,
     *               serial_no: string, private_key: string, apiv3_key: string,
     *               platform_cert: string}
     */
    public static function tenantConfig(int $aid): array
    {
        $appinfo = Db::name('admin_setapp_wx')->where('aid', $aid)->find();

        if (empty($appinfo) || 1 !== (int) ($appinfo['wxpay'] ?? 0)) {
            throw new BusinessException('请先配置并开启微信支付');
        }

        $wxpayType = (int) ($appinfo['wxpay_type'] ?? 0);

        if (1 === $wxpayType) {
            return self::partnerConfig($aid, $appinfo);
        }

        // 直连模式：租户自身商户号
        $apiv3Key = (string) ($appinfo['wxpay_mchkey_v3'] ?: ($appinfo['wxpay_mchkey'] ?? ''));
        $config   = [
            'mode'          => 'direct',
            'wxpay_type'    => 0,
            'appid'         => (string) ($appinfo['appid'] ?? ''),
            'mchid'         => (string) ($appinfo['wxpay_mchid'] ?? ''),
            'sp_appid'      => '',
            'sub_appid'     => '',
            'sub_mchid'     => '',
            'serial_no'     => (string) ($appinfo['wxpay_serial_no'] ?? ''),
            'private_key'   => self::readPem((string) ($appinfo['wxpay_apiclient_key'] ?? ''), '商户API私钥'),
            'apiv3_key'     => $apiv3Key,
            'platform_cert' => self::readPem((string) ($appinfo['wxpay_wechatpay_pem'] ?? ''), '平台证书', true),
        ];

        self::assertConfig($config);

        return $config;
    }

    /**
     * 服务商模式配置：平台 wxpayset 服务商凭据 + 租户 sub_mchid
     *
     * @param array<string, mixed> $appinfo
     * @return array<string, string|int>
     */
    private static function partnerConfig(int $aid, array $appinfo): array
    {
        $get = static fn (string $key): string => TenantConfigService::get(0, 'sys_wxpay_' . $key);

        if ('' === $get('mchid') || '' === (string) ($appinfo['wxpay_sub_mchid'] ?? '')) {
            throw new BusinessException('服务商模式需配置平台服务商商户号与租户特约商户号(sub_mchid)');
        }

        $config = [
            'mode'          => 'partner',
            'wxpay_type'    => 1,
            'appid'         => '',
            'mchid'         => $get('mchid'),            // 服务商商户号（签名主体）
            'sp_appid'      => $get('appid'),
            'sub_appid'     => (string) ($appinfo['appid'] ?? ''),
            'sub_mchid'     => (string) $appinfo['wxpay_sub_mchid'],
            'serial_no'     => $get('serial_no'),
            'private_key'   => self::readPem($get('apiclient_key'), '服务商API私钥'),
            'apiv3_key'     => $get('mchkey'),           // wxpayset.mchkey 口径为 APIv3 密钥
            'platform_cert' => self::readPem($get('wechatpay_pem'), '平台证书', true),
        ];

        self::assertConfig($config);

        return $config;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function assertConfig(array $config): void
    {
        if ('' === (string) $config['mchid']) {
            throw new BusinessException('微信支付商户号未配置');
        }
        if ('' === (string) $config['apiv3_key']) {
            throw new BusinessException('微信支付APIv3密钥未配置');
        }
        if ('' === (string) $config['appid'] && '' === (string) $config['sp_appid']) {
            throw new BusinessException('微信支付AppID未配置');
        }
    }

    /**
     * 读取证书 PEM 内容（数据库存相对路径：/uploads/cert/xxx.pem 或 upload/{aid}/xxx.pem）
     */
    private static function readPem(string $path, string $label, bool $optional = false): string
    {
        $path = trim($path);

        if ('' === $path) {
            if ($optional) {
                return '';
            }
            throw new BusinessException('请先配置微信支付' . $label . '路径');
        }

        $root   = app()->getRootPath();
        $public = app()->getPublicPath();
        $trim   = ltrim($path, '/');

        foreach ([$path, $root . $trim, $public . $trim] as $candidate) {
            if (is_file($candidate)) {
                $content = (string) file_get_contents($candidate);
                if ('' === $content) {
                    throw new BusinessException('微信支付' . $label . '文件内容为空');
                }

                return $content;
            }
        }

        if ($optional) {
            return '';
        }

        throw new BusinessException('微信支付' . $label . '文件不存在：' . $path);
    }

    /**
     * 构造 V3 客户端（可覆盖 sub_mchid：退款原路场景从原 wxpay_log 取）
     *
     * @param array<string, mixed> $config
     */
    public static function client(array $config, string $overrideSubMchid = ''): WxpayV3Client
    {
        if ('' !== $overrideSubMchid) {
            $config['sub_mchid'] = $overrideSubMchid;
        }

        return new WxpayV3Client($config);
    }

    /**
     * 回调地址（下单/退款各自独立端点，带 aid 定位租户配置）
     */
    public static function notifyUrl(int $aid, string $kind = 'pay'): string
    {
        // parse_ini_file 不去除行内 # 注释，取首段容错
        $domain = trim(strtok((string) env('PAY.NOTIFY_DOMAIN', ''), '#'));

        if ('' === $domain) {
            try {
                $domain = (string) app('request')->domain();
            } catch (\Throwable) {
                $domain = '';
            }
        }

        if ('' === $domain) {
            throw new BusinessException('支付回调域名未配置（env PAY.NOTIFY_DOMAIN）');
        }

        $path = 'refund' === $kind ? '/api/pay/notify/wechatRefund' : '/api/pay/notify/wechat';

        return rtrim($domain, '/') . $path . '?aid=' . $aid;
    }

    /* ================= 下单 ================= */

    /**
     * 微信 V3 JSAPI 下单（shop 订单 ordernum / 行业订单 out_trade_no 通用）
     *
     * @param string $orderType shop|carhailing|hotel|yuyue|restaurant
     * @return array{mock: false, status: int, ordernum: string, prepay_id: string, pay: array<string, string>}
     */
    public static function createOrder(
        int $aid,
        int $mid,
        string $ordernum,
        float $money,
        string $description,
        string $orderType
    ): array {
        $totalFee = (int) round($money * 100);

        if ($totalFee <= 0) {
            throw new BusinessException('支付金额必须大于0');
        }

        $config = self::tenantConfig($aid);

        $openid = (string) Db::name('member')->where('id', $mid)->value('wxopenid');
        if ('' === $openid) {
            throw new BusinessException('会员未绑定微信，无法使用微信支付');
        }

        // 服务商模式抽成金额（下单标记 profit_sharing，支付成功后资金冻结待分账）
        $choucheng = 'partner' === $config['mode'] ? self::chouchengAmount($money) : 0.0;

        $client   = self::client($config);
        $prepayId = $client->jsapiOrder([
            'out_trade_no'   => $ordernum,
            'description'    => mb_substr($description ?: '订单支付', 0, 60),
            'notify_url'     => self::notifyUrl($aid),
            'total_fee'      => $totalFee,
            'openid'         => $openid,
            'profit_sharing' => $choucheng > 0,
        ]);

        // payorder：真实渠道支付单（金额比对基准；已有待支付单则复用）
        $payorder = Db::name('payorder')
            ->where('aid', $aid)->where('ordernum', $ordernum)->where('status', 0)->find();

        if (empty($payorder)) {
            Db::name('payorder')->insert([
                'aid'          => $aid,
                'mid'          => $mid,
                'ordernum'     => $ordernum,
                'title'        => $description,
                'money'        => $money,
                'type'         => $orderType,
                'createtime'   => time(),
                'status'       => 0,
                'paytypeid'    => PayService::PAY_TYPE_IDS['wechat'],
                'paytype'      => 'wechat',
                'platform'     => 'wx',
                'wxpay_typeid' => (int) $config['wxpay_type'],
            ]);
        } else {
            Db::name('payorder')->where('id', (int) $payorder['id'])->update([
                'money'        => $money,
                'wxpay_typeid' => (int) $config['wxpay_type'],
            ]);
        }

        // wxpay_log：支付记录（退款时 sub_mchid 从这里取，原路退回）
        Db::name('wxpay_log')->insert([
            'aid'           => $aid,
            'mid'           => $mid,
            'openid'        => $openid,
            'tablename'     => $orderType,
            'ordernum'      => $ordernum,
            'mch_id'        => (string) $config['mchid'],
            'sub_mchid'     => (string) $config['sub_mchid'],
            'total_fee'     => $money,
            'fenzhangmoney' => $choucheng,
            'isfenzhang'    => 0,
            'createtime'    => time(),
            'platform'      => 'wx',
            'paysetjson'    => json_encode([
                'mode'      => $config['mode'],
                'appid'     => $config['appid'] ?: $config['sp_appid'],
                'mchid'     => $config['mchid'],
                'sub_mchid' => $config['sub_mchid'],
            ], JSON_UNESCAPED_UNICODE),
        ]);

        return [
            'mock'      => false,
            'status'    => 0,
            'ordernum'  => $ordernum,
            'prepay_id' => $prepayId,
            'pay'       => $client->buildLaunchParams($prepayId),
        ];
    }

    /* ================= 支付回调 ================= */

    /**
     * 支付回调处理（验签先行 → 解密 → 幂等入账）
     *
     * 流程：时间戳偏移≤300s + 平台证书 SHA256-RSA 验签 → AES-256-GCM 解密 resource（apiv3密钥）
     *       → payorder_log 记录 → 金额比对 total == money*100（不符拒付告警）
     *       → 复用 PayService::notifyByOutTradeNo 幂等入账 → {code:SUCCESS}
     *
     * @param array<string, string> $headers 请求头（小写键）
     * @return array{http_code: int, body: array{code: string, message: string}}
     */
    public static function handleNotify(array $headers, string $rawBody, int $aidHint = 0): array
    {
        $timestamp = (string) ($headers['wechatpay-timestamp'] ?? '');
        $nonce     = (string) ($headers['wechatpay-nonce'] ?? '');
        $signature = (string) ($headers['wechatpay-signature'] ?? '');

        if ('' === $timestamp || '' === $nonce || '' === $signature || '' === $rawBody) {
            return ['http_code' => 403, 'body' => ['code' => 'FAIL', 'message' => '验签参数缺失']];
        }

        $resource = self::verifyAndDecrypt($headers, $rawBody, $aidHint);

        if (null === $resource) {
            // 验签失败直接 403，不入库
            return ['http_code' => 403, 'body' => ['code' => 'FAIL', 'message' => '验签失败']];
        }

        $ordernum      = (string) ($resource['out_trade_no'] ?? '');
        $transactionId = (string) ($resource['transaction_id'] ?? '');

        Db::name('payorder_log')->insert(['ordernum' => $ordernum ?: ($resource['id'] ?? '')]);

        $payorder = Db::name('payorder')->where('ordernum', $ordernum)->order('id', 'desc')->find();

        if (empty($payorder)) {
            return ['http_code' => 200, 'body' => ['code' => 'FAIL', 'message' => '订单不存在']];
        }

        // 金额比对：渠道金额（分）必须等于 payorder.money*100，不符拒付并告警
        $channelTotal = (int) ($resource['amount']['total'] ?? -1);
        $localTotal   = (int) round((float) $payorder['money'] * 100);

        if ($channelTotal !== $localTotal) {
            Log::error('wxpay_notify_amount_mismatch', [
                'ordernum' => $ordernum, 'channel' => $channelTotal, 'local' => $localTotal,
            ]);

            return ['http_code' => 200, 'body' => ['code' => 'FAIL', 'message' => '金额不符']];
        }

        if ('SUCCESS' === (string) ($resource['trade_state'] ?? '')) {
            // 幂等入账：payorder 0→1，再路由到 shop/行业订单入账
            Db::name('payorder')->where('id', (int) $payorder['id'])->where('status', 0)->update([
                'status'   => 1,
                'paynum'   => $transactionId,
                'paytype'  => 'wechat',
                'paytime'  => time(),
            ]);

            Db::name('wxpay_log')->where('ordernum', $ordernum)
                ->where('transaction_id', '')->update(['transaction_id' => $transactionId]);

            app(PayService::class)->notifyByOutTradeNo($ordernum, 'wechat', $transactionId);
        }

        return ['http_code' => 200, 'body' => ['code' => 'SUCCESS', 'message' => '成功']];
    }

    /**
     * 退款回调处理（REFUND.SUCCESS/ABNORMAL/CLOSED）
     *
     * 回写 wxrefund_log 状态 + payorder.refund_money（幂等：仅首次 SUCCESS 累加）
     *
     * @param array<string, string> $headers
     * @return array{http_code: int, body: array{code: string, message: string}}
     */
    public static function handleRefundNotify(array $headers, string $rawBody, int $aidHint = 0): array
    {
        $resource = self::verifyAndDecrypt($headers, $rawBody, $aidHint);

        if (null === $resource) {
            return ['http_code' => 403, 'body' => ['code' => 'FAIL', 'message' => '验签失败']];
        }

        Db::name('payorder_log')->insert(['ordernum' => (string) ($resource['out_refund_no'] ?? ($resource['id'] ?? ''))]);

        $outRefundNo  = (string) ($resource['out_refund_no'] ?? '');
        $refundStatus = (string) ($resource['refund_status'] ?? '');
        $refundLog    = Db::name('wxrefund_log')->where('out_refund_no', $outRefundNo)->find();

        if (empty($refundLog)) {
            return ['http_code' => 200, 'body' => ['code' => 'FAIL', 'message' => '退款单不存在']];
        }

        if ('SUCCESS' === $refundStatus) {
            if (0 === (int) $refundLog['status']) {
                Db::name('wxrefund_log')->where('id', (int) $refundLog['id'])
                    ->where('status', 0)->update(['status' => 1]);

                // payorder.refund_money 累加（退款成功口径以回调为准）
                Db::name('payorder')
                    ->where('ordernum', (string) ($resource['out_trade_no'] ?? $refundLog['ordernum']))
                    ->where('status', 1)
                    ->inc('refund_money', (float) $refundLog['refundmoney'])
                    ->update(['refund_time' => time()]);
            }
        } elseif (in_array($refundStatus, ['ABNORMAL', 'CLOSED', 'REFUND_CLOSED'], true)) {
            Db::name('wxrefund_log')->where('id', (int) $refundLog['id'])->update([
                'status' => 2,
                'errmsg' => $refundStatus,
            ]);
        }

        return ['http_code' => 200, 'body' => ['code' => 'SUCCESS', 'message' => '成功']];
    }

    /**
     * 验签 + 解密：按 aid 定位（或遍历已开启微信支付租户）尝试平台证书验签与 apiv3 解密
     *
     * @param array<string, string> $headers
     * @return array<string, mixed>|null 解密后的 resource；验签失败返回 null
     */
    private static function verifyAndDecrypt(array $headers, string $rawBody, int $aidHint): ?array
    {
        $timestamp = (string) ($headers['wechatpay-timestamp'] ?? '');
        $nonce     = (string) ($headers['wechatpay-nonce'] ?? '');
        $signature = (string) ($headers['wechatpay-signature'] ?? '');

        $candidates = [];

        if ($aidHint > 0) {
            try {
                $candidates[] = self::tenantConfig($aidHint);
            } catch (BusinessException) {
                return null;
            }
        } else {
            // 回调未带 aid：遍历所有已开启微信支付的租户逐一验签
            $aids = Db::name('admin_setapp_wx')->where('wxpay', 1)->column('aid');
            foreach ($aids as $aid) {
                try {
                    $candidates[] = self::tenantConfig((int) $aid);
                } catch (BusinessException) {
                    continue;
                }
            }
        }

        $body = (array) (json_decode($rawBody, true) ?: []);
        $ciphertext    = (string) ($body['resource']['ciphertext'] ?? '');
        $resourceNonce = (string) ($body['resource']['nonce'] ?? '');
        $aad           = (string) ($body['resource']['associated_data'] ?? '');

        if ('' === $ciphertext) {
            return null;
        }

        foreach ($candidates as $config) {
            if ('' === (string) $config['platform_cert']) {
                continue;
            }

            $verified = WxpayV3Client::verifyNotify(
                $timestamp,
                $nonce,
                $rawBody,
                $signature,
                (string) $config['platform_cert']
            );

            if (!$verified) {
                continue;
            }

            $plain = WxpayV3Client::decryptResource(
                $ciphertext,
                (string) $config['apiv3_key'],
                $resourceNonce,
                $aad
            );

            if ('' !== $plain) {
                return (array) (json_decode($plain, true) ?: []);
            }
        }

        return null;
    }

    /* ================= 退款 ================= */

    /**
     * 微信 V3 退款（原路退回）
     *
     * 关键点：sub_mchid 从原支付 wxpay_log 取（非当前配置），保证服务商模式下原路退；
     *        已分账订单先 /v3/profitsharing/return-orders 回退分账资金再退款。
     *
     * @return array{mock: false, success: bool, channel_status: string}
     */
    public static function refund(string $ordernum, float $refundMoney, string $refundSn, string $reason = ''): array
    {
        $refundFee = (int) round($refundMoney * 100);

        if ($refundFee <= 0) {
            throw new BusinessException('退款金额必须大于0');
        }

        $payLog = Db::name('wxpay_log')->where('ordernum', $ordernum)->order('id', 'desc')->find();

        if (empty($payLog) || '' === (string) ($payLog['transaction_id'] ?? '')) {
            throw new BusinessException('未找到微信支付记录，无法原路退款');
        }

        $aid    = (int) $payLog['aid'];
        $config = self::tenantConfig($aid);
        $client = self::client($config, (string) ($payLog['sub_mchid'] ?? ''));

        $totalFee = (int) round((float) $payLog['total_fee'] * 100);
        $refunded = (int) round((float) ($payLog['refund_money'] ?? 0) * 100);

        if ($refunded + $refundFee > $totalFee) {
            throw new BusinessException('退款金额超出可退余额');
        }

        // 已分账订单：先回退分账资金（接收方为平台服务商商户号）
        self::returnProfitSharingBeforeRefund($payLog, $config);

        $result = $client->refund([
            'out_trade_no'  => $ordernum,
            'out_refund_no' => $refundSn,
            'total_fee'     => $totalFee,
            'refund_fee'    => $refundFee,
            'reason'        => $reason,
            'notify_url'    => self::notifyUrl($aid, 'refund'),
            'sub_mchid'     => (string) ($payLog['sub_mchid'] ?? ''),
        ]);

        $channelStatus = (string) ($result['status'] ?? '');
        $accepted      = in_array($channelStatus, ['SUCCESS', 'PROCESSING'], true);

        Db::name('wxrefund_log')->insert([
            'aid'         => $aid,
            'mch_id'      => (string) ($payLog['mch_id'] ?? ''),
            'ordernum'    => $ordernum,
            'out_refund_no' => $refundSn,
            'totalprice'  => (float) $payLog['total_fee'],
            'refundmoney' => $refundMoney,
            'createtime'  => (string) time(),
            'status'      => 'SUCCESS' === $channelStatus ? 1 : ($accepted ? 0 : 2),
            'errmsg'      => $accepted ? '' : (string) ($result['message'] ?? $channelStatus),
        ]);

        // wxpay_log 累计已退金额（渠道受理即累计，回调 SUCCESS 再写 payorder.refund_money）
        Db::name('wxpay_log')->where('id', (int) $payLog['id'])->inc('refund_money', $refundMoney)->update();

        if ('SUCCESS' === $channelStatus) {
            Db::name('payorder')->where('ordernum', $ordernum)->where('status', 1)
                ->inc('refund_money', $refundMoney)->update(['refund_time' => time()]);
        }

        if (!$accepted) {
            throw new BusinessException('微信退款受理失败：' . (string) ($result['message'] ?? $channelStatus));
        }

        return ['mock' => false, 'success' => true, 'channel_status' => $channelStatus];
    }

    /**
     * 退款前回退分账（wxpay_fzlog isfenzhang=1 的记录逐条回退，回退后置 3）
     *
     * @param array<string, mixed> $payLog
     * @param array<string, mixed> $config
     */
    private static function returnProfitSharingBeforeRefund(array $payLog, array $config): void
    {
        if ('partner' !== $config['mode']) {
            return;
        }

        $fzLogs = Db::name('wxpay_fzlog')
            ->where('ordernum', (string) $payLog['ordernum'])
            ->where('isfenzhang', 1)
            ->select()->toArray();

        if ([] === $fzLogs) {
            return;
        }

        $client   = self::client($config, (string) ($payLog['sub_mchid'] ?? ''));
        $totalFee = (int) round((float) $payLog['total_fee'] * 100);

        foreach ($fzLogs as $fz) {
            $amount = (int) round((float) $fz['fenzhangmoney'] * 100);

            if ($amount <= 0) {
                continue;
            }

            $client->profitSharingReturn([
                'order_id'     => (string) ($fz['fz_ordernum'] ?? ''),
                'out_order_no' => (string) ($fz['out_order_no'] ?? ''),
                'out_return_no' => 'HT' . date('YmdHis') . str_pad((string) ((int) $fz['id'] % 1000000), 6, '0', STR_PAD_LEFT),
                'return_mchid' => (string) $config['mchid'],
                'amount'       => $amount,
                'total'        => $totalFee,
                'description'  => '退款分账回退',
                'sub_mchid'    => (string) ($payLog['sub_mchid'] ?? ''),
            ]);

            Db::name('wxpay_fzlog')->where('id', (int) $fz['id'])->update(['isfenzhang' => 3]);
        }

        Db::name('wxpay_log')->where('id', (int) $payLog['id'])->update(['isfenzhang' => 3]);
    }

    /* ================= 分账（抽成） ================= */

    /**
     * 单笔分账（接收方=超管 wxpayset 服务商商户号；分账金额≤30% 前置校验）
     *
     * @return array{success: bool, message: string}
     */
    public static function profitSharing(string $ordernum): array
    {
        $payLog = Db::name('wxpay_log')
            ->where('ordernum', $ordernum)
            ->where('isfenzhang', 0)
            ->where('fenzhangmoney', '>', 0)
            ->order('id', 'desc')
            ->find();

        if (empty($payLog)) {
            return ['success' => false, 'message' => '无待分账记录'];
        }

        $aid    = (int) $payLog['aid'];
        $config = self::tenantConfig($aid);

        if ('partner' !== $config['mode']) {
            return ['success' => false, 'message' => '仅服务商模式支持分账'];
        }

        $totalFee = (int) round((float) $payLog['total_fee'] * 100);
        $amount   = (int) round((float) $payLog['fenzhangmoney'] * 100);

        // 分账比例 ≤30% 前置校验
        if ($amount > (int) floor($totalFee * self::PROFIT_SHARING_MAX_RATIO)) {
            Db::name('wxpay_log')->where('id', (int) $payLog['id'])->update([
                'isfenzhang' => 2, 'fz_errmsg' => '分账金额超出30%上限',
            ]);

            return ['success' => false, 'message' => '分账金额超出30%上限'];
        }

        $outOrderNo = 'FZ' . date('YmdHis') . str_pad((string) ((int) $payLog['id'] % 1000000), 6, '0', STR_PAD_LEFT);

        $result = self::client($config, (string) ($payLog['sub_mchid'] ?? ''))->profitSharing([
            'appid'          => (string) ($config['sub_appid'] ?: $config['sp_appid']),
            'transaction_id' => (string) $payLog['transaction_id'],
            'out_order_no'   => $outOrderNo,
            'sub_mchid'      => (string) ($payLog['sub_mchid'] ?? ''),
            'receivers'      => [[
                'type'        => 'MERCHANT_ID',
                'account'     => (string) $config['mchid'], // 服务商商户号接收抽成
                'amount'      => $amount,
                'description' => '平台抽成',
            ]],
            'unset_profit_sharing' => true,
        ]);

        $status  = (string) ($result['state'] ?? '');
        $success = in_array($status, ['FINISHED', 'PROCESSING'], true);

        Db::name('wxpay_fzlog')->insert([
            'aid'            => $aid,
            'mid'            => (int) ($payLog['mid'] ?? 0),
            'logid'          => (int) $payLog['id'],
            'openid'         => (string) ($payLog['openid'] ?? ''),
            'tablename'      => (string) ($payLog['tablename'] ?? ''),
            'ordernum'       => $ordernum,
            'mch_id'         => (string) $config['mchid'],
            'sub_mchid'      => (string) ($payLog['sub_mchid'] ?? ''),
            'transaction_id' => (string) ($payLog['transaction_id'] ?? ''),
            'out_order_no'   => $outOrderNo,
            'receiversjson'  => json_encode($result['receivers'] ?? [], JSON_UNESCAPED_UNICODE),
            'createtime'     => time(),
            'fenzhangmoney'  => (float) $payLog['fenzhangmoney'],
            'isfenzhang'     => 'FINISHED' === $status ? 1 : ($success ? 0 : 2),
            'fz_ordernum'    => (string) ($result['order_id'] ?? ''),
            'fz_errmsg'      => $success ? '' : (string) ($result['message'] ?? $status),
        ]);

        if ($success) {
            Db::name('wxpay_log')->where('id', (int) $payLog['id'])->update([
                'isfenzhang' => 1,
                'fz_ordernum' => (string) ($result['order_id'] ?? ''),
            ]);
        } else {
            Db::name('wxpay_log')->where('id', (int) $payLog['id'])->update([
                'isfenzhang' => 2,
                'fz_errmsg'  => (string) ($result['message'] ?? $status),
            ]);
        }

        return ['success' => $success, 'message' => $success ? '分账请求成功' : (string) ($result['message'] ?? $status)];
    }

    /**
     * 分账批量扫描（定时任务调度：待分账且已有微信交易号的支付记录）
     *
     * @return array{scanned: int, done: int}
     */
    public static function profitSharingScan(int $limit = 100): array
    {
        $rows = Db::name('wxpay_log')
            ->where('isfenzhang', 0)
            ->where('fenzhangmoney', '>', 0)
            ->where('transaction_id', '<>', '')
            ->order('id', 'asc')
            ->limit($limit)
            ->column('ordernum');

        $done = 0;

        foreach ($rows as $ordernum) {
            try {
                if (self::profitSharing((string) $ordernum)['success']) {
                    ++$done;
                }
            } catch (\Throwable $e) {
                Log::error('wxpay_profit_sharing_fail', ['ordernum' => $ordernum, 'error' => $e->getMessage()]);
            }
        }

        return ['scanned' => count($rows), 'done' => $done];
    }

    /* ================= 查单补偿 ================= */

    /**
     * 按渠道查单补偿单笔 pay_order（真实模式对账捞回）
     *
     * @param array<string, mixed> $payOrder pay_order 行
     * @return bool true=本次捞回入账
     */
    public static function reconcileRow(array $payOrder): bool
    {
        $outTradeNo = (string) ($payOrder['out_trade_no'] ?? '');
        $aid        = (int) ($payOrder['aid'] ?? 0);

        try {
            $config = self::tenantConfig($aid);
            $result = self::client($config)->queryByOutTradeNo($outTradeNo);
        } catch (\Throwable $e) {
            Log::error('wxpay_reconcile_query_fail', ['out_trade_no' => $outTradeNo, 'error' => $e->getMessage()]);

            return false;
        }

        $tradeState = (string) ($result['trade_state'] ?? '');

        if ('SUCCESS' !== $tradeState) {
            return false; // 渠道侧未支付：不处理，等待用户支付或超时取消
        }

        // 金额比对后再入账
        $channelTotal = (int) ($result['amount']['total'] ?? -1);
        $localTotal   = (int) round((float) ($payOrder['pay_amount'] ?? 0) * 100);

        if ($channelTotal !== $localTotal) {
            Log::error('wxpay_reconcile_amount_mismatch', [
                'out_trade_no' => $outTradeNo, 'channel' => $channelTotal, 'local' => $localTotal,
            ]);

            return false;
        }

        $transactionId = (string) ($result['transaction_id'] ?? '');

        Db::name('payorder')->where('ordernum', $outTradeNo)->where('status', 0)->update([
            'status'  => 1,
            'paynum'  => $transactionId,
            'paytype' => 'wechat',
            'paytime' => time(),
        ]);

        Db::name('wxpay_log')->where('ordernum', $outTradeNo)
            ->where('transaction_id', '')->update(['transaction_id' => $transactionId]);

        return app(PayService::class)->notifyByOutTradeNo($outTradeNo, 'wechat', $transactionId)['handled'];
    }

    /* ================= 抽成计算 ================= */

    /**
     * 平台抽成金额（超管 wxpayset：chouchengset 0不抽成/1按比例/2固定）
     */
    public static function chouchengAmount(float $money): float
    {
        $get  = static fn (string $key, string $default = ''): string => TenantConfigService::get(0, 'sys_wxpay_' . $key, $default);
        $set  = (int) $get('chouchengset', '0');

        $amount = match ($set) {
            1 => max((float) $get('chouchengrate', '0') / 100 * $money, 0),
            2 => (float) $get('chouchengmoney', '0'),
            default => 0.0,
        };

        // 按比例抽成时的最低抽成金额兜底
        if (1 === $set) {
            $min = (float) $get('chouchengmin', '0');
            if ($amount > 0 && $min > 0 && $amount < $min) {
                $amount = $min;
            }
        }

        $amount = round($amount, 2);

        // 微信分账 30% 硬上限 + 不得超过订单金额
        $cap = round($money * self::PROFIT_SHARING_MAX_RATIO, 2);

        return min($amount, $cap, $money);
    }
}
