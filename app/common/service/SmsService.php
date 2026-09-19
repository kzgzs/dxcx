<?php

declare(strict_types=1);

namespace app\common\service;

use think\facade\Db;

/**
 * 短信服务（对标参考系统 Sms 网关：admin_set_sms 单行配置 + smslog 发送记录）
 *
 * 网关：smstype 1=阿里云 2=聚合数据；未配置密钥时拒绝真实下发并记录失败日志
 * 模板：tmpl_xxx 存模板CODE，tmpl_xxx_st 为开关（0/1）
 */
class SmsService
{
    /** admin_set_sms 允许写入的字段白名单 */
    public const CONFIG_FIELDS = [
        'status', 'smstype', 'accesskey', 'accesssecret', 'sdkappid', 'sign_name',
        'tmpl_smscode', 'tmpl_smscode_st',
        'tmpl_orderconfirm', 'tmpl_orderconfirm_st',
        'tmpl_orderpay', 'tmpl_orderpay_st',
        'tmpl_collagesuccess', 'tmpl_collagesuccess_st',
        'tmpl_orderfahuo', 'tmpl_orderfahuo_st',
        'tmpl_tuisuccess', 'tmpl_tuisuccess_st',
        'tmpl_tuierror', 'tmpl_tuierror_st',
        'tmpl_tixiansuccess', 'tmpl_tixiansuccess_st',
        'tmpl_tixianerror', 'tmpl_tixianerror_st',
        'tmpl_fenxiaosuccess', 'tmpl_fenxiaosuccess_st',
        'tmpl_restaurant_booking', 'tmpl_restaurant_booking_st',
        'tmpl_restaurant_booking_fail', 'tmpl_restaurant_booking_fail_st',
        'tmpl_checksuccess', 'tmpl_checksuccess_st',
        'tmpl_checkerror', 'tmpl_checkerror_st',
        'tmpl_yysucess', 'tmpl_yysucess_st',
        'tmpl_recharge', 'tmpl_recharge_st',
        'tmpl_sysmsg_notice', 'tmpl_sysmsg_notice_st',
        'tmpl_checknotice', 'tmpl_checknotice_st',
        'tmpl_send_coupon', 'tmpl_send_coupon_st',
        'tmpl_coupon_expired', 'tmpl_coupon_expired_st',
        'tmpl_admin_operate_money', 'tmpl_admin_operate_money_st',
        'tmpl_admin_recharge_money', 'tmpl_admin_recharge_money_st',
        'tmpl_member_uplevel', 'tmpl_member_uplevel_st',
        'tmpl_member_uplevel_expired', 'tmpl_member_uplevel_expired_st',
        'code_length',
    ];

    /**
     * 读取租户短信配置（不存在则初始化空行）
     */
    public function getConfig(int $aid): array
    {
        $info = Db::name('admin_set_sms')->where('aid', $aid)->find();

        if (empty($info)) {
            Db::name('admin_set_sms')->insert(['aid' => $aid, 'status' => 0, 'smstype' => 1]);
            $info = Db::name('admin_set_sms')->where('aid', $aid)->find();
        }

        return (array) $info;
    }

    /**
     * 保存租户短信配置（白名单字段，trim 后落库）
     */
    public function saveConfig(int $aid, array $data): void
    {
        $this->getConfig($aid);

        $update = [];
        foreach (self::CONFIG_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = is_scalar($data[$field]) ? trim((string) $data[$field]) : $data[$field];
            }
        }

        if ($update) {
            Db::name('admin_set_sms')->where('aid', $aid)->update($update);
        }
    }

    /**
     * 发送短信并写入 smslog
     *
     * @param int    $aid           租户 ID
     * @param string $mobile        手机号（多个用英文逗号分隔）
     * @param string $templateCode  模板 CODE
     * @param array  $params        模板变量（阿里云 JSON / 聚合 #k#=v 拼接）
     * @param string $templateContent 模板内容描述（仅用于日志展示）
     * @return array{success: bool, msg: string}
     */
    public function send(int $aid, string $mobile, string $templateCode, array $params = [], string $templateContent = ''): array
    {
        $config = $this->getConfig($aid);

        if ((int) ($config['status'] ?? 0) !== 1) {
            return $this->logFail($aid, $mobile, $config, $templateCode, $templateContent, $params, '短信功能未开启');
        }
        if ('' === trim($templateCode)) {
            return $this->logFail($aid, $mobile, $config, $templateCode, $templateContent, $params, '未配置模板CODE');
        }

        $smstype = (int) ($config['smstype'] ?? 1);
        try {
            if (1 === $smstype) {
                [$ok, $msg] = $this->sendAliyun($config, $mobile, $templateCode, $params);
            } elseif (2 === $smstype) {
                [$ok, $msg] = $this->sendJuhe($config, $mobile, $templateCode, $params);
            } else {
                [$ok, $msg] = [false, '不支持的短信网关类型'];
            }
        } catch (\Throwable $e) {
            [$ok, $msg] = [false, '发送异常：' . $e->getMessage()];
        }

        $this->logSend($aid, $mobile, $config, $templateCode, $templateContent, $params, $ok, $msg);

        return ['success' => $ok, 'msg' => $msg];
    }

    /**
     * 阿里云短信（dysmsapi 2017-05-25，HMAC-SHA1 签名）
     * @return array{0: bool, 1: string}
     */
    protected function sendAliyun(array $config, string $mobile, string $templateCode, array $params): array
    {
        $accessKeyId     = trim((string) ($config['accesskey'] ?? ''));
        $accessKeySecret = trim((string) ($config['accesssecret'] ?? ''));
        $signName        = trim((string) ($config['sign_name'] ?? ''));

        if ('' === $accessKeyId || '' === $accessKeySecret || '' === $signName) {
            return [false, '阿里云网关未配置完整（AccessKey/密钥/签名）'];
        }

        $query = [
            'AccessKeyId'      => $accessKeyId,
            'Action'           => 'SendSms',
            'Format'           => 'JSON',
            'PhoneNumbers'     => $mobile,
            'RegionId'         => 'cn-hangzhou',
            'SignName'         => $signName,
            'SignatureMethod'  => 'HMAC-SHA1',
            'SignatureNonce'   => bin2hex(random_bytes(16)),
            'SignatureVersion' => '1.0',
            'TemplateCode'     => $templateCode,
            'TemplateParam'    => json_encode((object) $params, JSON_UNESCAPED_UNICODE),
            'Timestamp'        => gmdate('Y-m-d\TH:i:s\Z'),
            'Version'          => '2017-05-25',
        ];
        ksort($query);

        $canonical = '';
        foreach ($query as $k => $v) {
            $canonical .= '&' . $this->aliyunEncode($k) . '=' . $this->aliyunEncode($v);
        }
        $stringToSign = 'GET&%2F&' . $this->aliyunEncode(substr($canonical, 1));
        $query['Signature'] = base64_encode(hash_hmac('sha1', $stringToSign, $accessKeySecret . '&', true));

        $url  = 'https://dysmsapi.aliyuncs.com/?' . http_build_query($query);
        $body = $this->httpGet($url);
        $json = json_decode($body, true) ?: [];

        if (($json['Code'] ?? '') === 'OK') {
            return [true, 'OK biz_id=' . ($json['BizId'] ?? '')];
        }

        return [false, ($json['Code'] ?? 'Error') . '：' . ($json['Message'] ?? '阿里云发送失败')];
    }

    protected function aliyunEncode(string $str): string
    {
        return str_replace(['+', '*', '%7E'], ['%20', '%2A', '~'], urlencode($str));
    }

    /**
     * 聚合数据短信（tpl_value 形如 #code#=123456）
     * @return array{0: bool, 1: string}
     */
    protected function sendJuhe(array $config, string $mobile, string $templateCode, array $params): array
    {
        $key = trim((string) ($config['accesskey'] ?? ''));

        if ('' === $key) {
            return [false, '聚合数据网关未配置 key'];
        }

        $tplValue = '';
        foreach ($params as $k => $v) {
            $tplValue .= '#' . $k . '#=' . $v;
        }

        $post = [
            'key'       => $key,
            'mobile'    => $mobile,
            'tpl_id'    => $templateCode,
            'tpl_value' => $tplValue,
            'dtype'     => 'json',
        ];

        $body = $this->httpPost('https://v.juhe.cn/sms/send', $post);
        $json = json_decode($body, true) ?: [];

        if (($json['error_code'] ?? -1) === 0) {
            return [true, 'OK'];
        }

        return [false, 'error_code=' . ($json['error_code'] ?? '-') . '：' . ($json['reason'] ?? '聚合发送失败')];
    }

    protected function httpGet(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        if (false === $body) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('网络请求失败：' . $err);
        }
        curl_close($ch);

        return (string) $body;
    }

    protected function httpPost(string $url, array $post): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($post),
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        if (false === $body) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('网络请求失败：' . $err);
        }
        curl_close($ch);

        return (string) $body;
    }

    /**
     * 失败快速返回（同时落 smslog 失败记录）
     * @return array{success: bool, msg: string}
     */
    protected function logFail(int $aid, string $mobile, array $config, string $templateCode, string $templateContent, array $params, string $msg): array
    {
        $this->logSend($aid, $mobile, $config, $templateCode, $templateContent, $params, false, $msg);

        return ['success' => false, 'msg' => $msg];
    }

    /**
     * 写入 smslog（status：1成功 2失败）
     */
    protected function logSend(int $aid, string $mobile, array $config, string $templateCode, string $templateContent, array $params, bool $ok, string $msg): void
    {
        try {
            Db::name('smslog')->insert([
                'aid'             => $aid,
                'PhoneNumbers'    => $mobile,
                'num'             => max(1, count(array_filter(explode(',', $mobile)))),
                'SignName'        => (string) ($config['sign_name'] ?? ''),
                'TemplateCode'    => $templateCode,
                'TemplateContent' => $templateContent,
                'TemplateParam'   => json_encode((object) $params, JSON_UNESCAPED_UNICODE),
                'ip'              => request()->ip(),
                'createtime'      => time(),
                'createdate'      => date('Y-m-d'),
                'status'          => $ok ? 1 : 2,
                'err_msg'         => $ok ? '' : mb_substr($msg, 0, 250),
                'successnum'      => $ok ? 1 : 0,
                'errnum'          => $ok ? 0 : 1,
            ]);
        } catch (\Throwable $e) {
            // 日志落库失败不阻断
        }
    }
}
