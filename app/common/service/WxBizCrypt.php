<?php

declare(strict_types=1);

namespace app\common\service;

/**
 * 微信开放平台消息加解密（参照 canzhao\app\common\WxBizMsgCrypt.php 重写）
 *
 * - AES-256-CBC + PKCS#7（块 32）+ SHA1 签名
 * - 错误码沿用官方示例：-40001 签名错误 / -40002 解析XML失败 / -40004 Key非法
 *   / -40005 AppId校验错误 / -40007 解密失败 ...
 */
class WxBizCrypt
{
    /** AES Key 长度（不含末尾补位的 =） */
    public const AES_KEY_LEN = 43;

    protected string $aesKeyBin;

    /**
     * @param string $token          开发者设置的 Token
     * @param string $encodingAesKey 43 位 EncodingAESKey
     * @param string $appId          校验归属用的 AppId（第三方场景为组件 appid）
     */
    public function __construct(
        protected string $token,
        protected string $encodingAesKey,
        protected string $appId,
    ) {
        if (strlen($encodingAesKey) !== self::AES_KEY_LEN) {
            throw new WxBizCryptException('EncodingAESKey 非法（必须 43 位）', -40004);
        }
        $this->aesKeyBin = base64_decode($encodingAesKey . '=', true) ?: '';
        if ('' === $this->aesKeyBin) {
            throw new WxBizCryptException('EncodingAESKey 非法（Base64 解码失败）', -40004);
        }
    }

    /**
     * URL 校验签名（GET 验证：仅 token/timestamp/nonce 三段）
     */
    public static function checkSignature(string $token, string $timestamp, string $nonce, string $signature): bool
    {
        $arr = [$token, $timestamp, $nonce];
        sort($arr, SORT_STRING);

        return hash_equals(sha1(implode('', $arr)), $signature);
    }

    /**
     * 消息体签名（含密文段，四段排序拼接后 sha1）
     */
    public static function sign(string $token, string $timestamp, string $nonce, string $encrypt): string
    {
        $arr = [$encrypt, $token, $timestamp, $nonce];
        sort($arr, SORT_STRING);

        return sha1(implode('', $arr));
    }

    /**
     * 校验消息真实性并解密
     *
     * @param string $msgSignature URL 参数 msg_signature
     * @param string $timestamp    时间戳
     * @param string $nonce        随机串
     * @param string $postData     POST 原文（XML 或 JSON）
     * @return string 解密后的 XML 明文
     * @throws WxBizCryptException
     */
    public function decryptMsg(string $msgSignature, string $timestamp, string $nonce, string $postData): string
    {
        [$encrypt] = self::extract($postData);

        $signature = self::sign($this->token, $timestamp, $nonce, $encrypt);
        if (!hash_equals($signature, $msgSignature)) {
            throw new WxBizCryptException('签名验证错误', -40001);
        }

        return self::decryptRaw($encrypt, $this->appId, $this->aesKeyBin);
    }

    /**
     * 加密回复消息并打包为 XML
     *
     * @param string $replyMsg  明文 XML
     * @param string $timestamp 时间戳（空则取当前）
     * @param string $nonce     随机串（空则自动生成）
     */
    public function encryptMsg(string $replyMsg, string $timestamp = '', string $nonce = ''): string
    {
        $timestamp = '' === $timestamp ? (string) time() : $timestamp;
        $nonce     = '' === $nonce ? self::randomStr(16) : $nonce;

        $encrypt   = self::encryptRaw($replyMsg, $this->appId, $this->aesKeyBin);
        $signature = self::sign($this->token, $timestamp, $nonce, $encrypt);

        return sprintf(
            "<xml>\n<Encrypt><![CDATA[%s]]></Encrypt>\n<MsgSignature><![CDATA[%s]]></MsgSignature>\n<TimeStamp>%s</TimeStamp>\n<Nonce><![CDATA[%s]]></Nonce>\n</xml>",
            $encrypt,
            $signature,
            $timestamp,
            $nonce
        );
    }

    /**
     * 从 POST 报文（XML 或 JSON）提取密文与 ToUserName
     *
     * @return array{0: string, 1: string} [encrypt, tousername]
     */
    public static function extract(string $postData): array
    {
        // JSON 报文（微信部分推送场景）
        $json = json_decode($postData, true);
        if (is_array($json) && !empty($json['Encrypt'])) {
            return [(string) $json['Encrypt'], (string) ($json['ToUserName'] ?? '')];
        }

        $prev = libxml_use_internal_errors(true);
        try {
            $dom = new \DOMDocument();
            if (!$dom->loadXML($postData, LIBXML_NONET)) {
                throw new WxBizCryptException('XML 解析失败', -40002);
            }
            $encrypt = $dom->getElementsByTagName('Encrypt')->item(0)?->nodeValue ?? '';
            $toUser  = $dom->getElementsByTagName('ToUserName')->item(0)?->nodeValue ?? '';
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }

        if ('' === $encrypt) {
            throw new WxBizCryptException('XML 中缺少 Encrypt 节点', -40002);
        }

        return [$encrypt, $toUser];
    }

    /**
     * XML 转数组（仅一层子节点，重复节点取最后值，够用于微信推送报文）
     *
     * @return array<string, string>
     */
    public static function xmlToArray(string $xml): array
    {
        $prev = libxml_use_internal_errors(true);
        try {
            $obj = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }

        if (false === $obj) {
            throw new WxBizCryptException('XML 解析失败', -40002);
        }

        $result = [];
        foreach ($obj->children() as $child) {
            $result[$child->getName()] = (string) $child;
        }

        return $result;
    }

    /**
     * AES 加密明文（16位随机串 + 网络字节序长度 + 明文 + appid）
     */
    public static function encryptRaw(string $text, string $appId, string $keyBin): string
    {
        $plain  = self::randomStr(16) . pack('N', strlen($text)) . $text . $appId;
        $padded = self::pkcs7Encode($plain);
        $iv     = substr($keyBin, 0, 16);

        $encrypted = openssl_encrypt($padded, 'AES-256-CBC', $keyBin, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
        if (false === $encrypted) {
            throw new WxBizCryptException('AES 加密失败', -40006);
        }

        return base64_encode($encrypted);
    }

    /**
     * AES 解密密文并校验归属 appid
     */
    public static function decryptRaw(string $encrypted, string $appId, string $keyBin): string
    {
        $cipher = base64_decode($encrypted, true);
        if (false === $cipher) {
            throw new WxBizCryptException('Base64 解码失败', -40010);
        }

        $iv = substr($keyBin, 0, 16);
        $plain = openssl_decrypt($cipher, 'AES-256-CBC', $keyBin, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
        if (false === $plain) {
            throw new WxBizCryptException('AES 解密失败', -40007);
        }

        $plain = self::pkcs7Decode($plain);
        if (strlen($plain) < 20) {
            throw new WxBizCryptException('解密结果非法', -40008);
        }

        $content  = substr($plain, 16);
        $lenList  = unpack('N', substr($content, 0, 4));
        $xmlLen   = (int) ($lenList[1] ?? 0);
        $xml      = substr($content, 4, $xmlLen);
        $fromApp  = substr($content, 4 + $xmlLen);

        if ($fromApp !== $appId) {
            throw new WxBizCryptException('AppId 校验错误', -40005);
        }

        return $xml;
    }

    /** PKCS#7 填充（块大小 32） */
    protected static function pkcs7Encode(string $text): string
    {
        $pad = 32 - (strlen($text) % 32);
        if (0 === $pad) {
            $pad = 32;
        }

        return $text . str_repeat(chr($pad), $pad);
    }

    /** PKCS#7 去填充 */
    protected static function pkcs7Decode(string $text): string
    {
        $pad = ord(substr($text, -1));
        if ($pad < 1 || $pad > 32) {
            $pad = 0;
        }

        return substr($text, 0, strlen($text) - $pad);
    }

    /** 随机字符串 */
    public static function randomStr(int $len): string
    {
        $pool = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789abcdefghijklmnopqrstuvwxyz';
        $max  = strlen($pool) - 1;
        $str  = '';
        for ($i = 0; $i < $len; $i++) {
            $str .= $pool[random_int(0, $max)];
        }

        return $str;
    }
}

/**
 * 加解密异常（code 为官方错误码）
 */
class WxBizCryptException extends \RuntimeException
{
}
