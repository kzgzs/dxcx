<?php

declare(strict_types=1);

namespace app\common\service;

use think\facade\Env;

/**
 * 图形验证码服务
 *
 * GD 生成 4 位数字字母混合验证码（去掉易混淆的 0/O/1/I/L），
 * 明文存 Redis（key captcha:{id}，TTL 300s），验证为一次性（读取比对后立即删除，防重放）。
 */
class CaptchaService
{
    /** 字符集（去掉易混淆的 0/O/1/I/L） */
    protected const CHARSET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    /** 有效期（秒） */
    protected const TTL = 300;

    /**
     * 生成验证码：返回 {captcha_id, image(data:image/png;base64,...)}
     *
     * @return array{captcha_id: string, image: string}
     */
    public static function create(): array
    {
        $code = self::randomCode(4);
        $id   = md5(uniqid((string) mt_rand(), true));

        self::redis()->setex('captcha:' . $id, self::TTL, $code);

        return [
            'captcha_id' => $id,
            'image'      => self::renderImage($code),
        ];
    }

    /**
     * 校验验证码（不区分大小写，一次性：比对后立即删除）
     */
    public static function verify(string $captchaId, string $captcha): bool
    {
        // id 仅允许 md5 十六进制字符，防止注入任意 key
        $captchaId = strtolower(preg_replace('/[^a-f0-9]/', '', $captchaId));
        $captcha   = trim($captcha);

        if ('' === $captchaId || '' === $captcha) {
            return false;
        }

        $key   = 'captcha:' . $captchaId;
        $redis = self::redis();
        $stored = $redis->get($key);
        $redis->del($key); // 一次性使用，无论对错都删除（防重放/防暴力猜解）

        if (false === $stored || '' === (string) $stored) {
            return false;
        }

        return 0 === strcasecmp((string) $stored, $captcha);
    }

    /**
     * 随机验证码字符串
     */
    protected static function randomCode(int $length): string
    {
        $code = '';
        $max  = strlen(self::CHARSET) - 1;

        for ($i = 0; $i < $length; $i++) {
            $code .= self::CHARSET[mt_rand(0, $max)];
        }

        return $code;
    }

    /**
     * GD 绘制验证码图片，输出 base64 PNG（含 data URI 前缀，前端可直接作 img src）
     */
    protected static function renderImage(string $code): string
    {
        $width  = 110;
        $height = 40;

        $img = imagecreatetruecolor($width, $height);

        // 浅色随机背景
        $bg = imagecolorallocate($img, mt_rand(235, 255), mt_rand(235, 255), mt_rand(235, 255));
        imagefill($img, 0, 0, $bg);

        // 干扰线
        for ($i = 0; $i < 4; $i++) {
            $lineColor = imagecolorallocate($img, mt_rand(100, 200), mt_rand(100, 200), mt_rand(100, 200));
            imageline($img, mt_rand(0, $width), mt_rand(0, $height), mt_rand(0, $width), mt_rand(0, $height), $lineColor);
        }

        // 干扰点
        for ($i = 0; $i < 80; $i++) {
            $dotColor = imagecolorallocate($img, mt_rand(120, 220), mt_rand(120, 220), mt_rand(120, 220));
            imagesetpixel($img, mt_rand(0, $width - 1), mt_rand(0, $height - 1), $dotColor);
        }

        // 逐字绘制（内置 5 号字体 9x15，位置随机抖动）
        $x = 10;
        for ($i = 0, $len = strlen($code); $i < $len; $i++) {
            $textColor = imagecolorallocate($img, mt_rand(0, 120), mt_rand(0, 120), mt_rand(0, 120));
            imagestring($img, 5, $x + mt_rand(-2, 2), mt_rand(4, 18), $code[$i], $textColor);
            $x += 25;
        }

        ob_start();
        imagepng($img);
        $binary = (string) ob_get_clean();
        imagedestroy($img);

        return 'data:image/png;base64,' . base64_encode($binary);
    }

    /**
     * 原生 Redis 连接（与 RegisterService/MemberService 同口径）
     */
    protected static function redis(): \Redis
    {
        static $client = null;

        if (null === $client) {
            $client = new \Redis();
            $client->connect((string) Env::get('REDIS.HOST', '127.0.0.1'), (int) Env::get('REDIS.PORT', 6379), 3.0);

            $password = (string) Env::get('REDIS.PASSWORD', '');
            if ('' !== $password) {
                $client->auth($password);
            }
            $client->select((int) Env::get('REDIS.SELECT', 0));
        }

        return $client;
    }
}
