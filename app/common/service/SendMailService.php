<?php

declare(strict_types=1);

namespace app\common\service;

use think\facade\Env;
use think\facade\Log;

/**
 * 邮件发送服务
 *
 * 发送优先级：
 * 1. PHPMailer（vendor 已安装且 .env 配置了 SMTP）
 * 2. 原生 fsockopen 轻量 SMTP（.env 配置了 SMTP 但无 PHPMailer）
 * 3. PHP 原生 mail()（SMTP 未配置时兜底）
 *
 * .env SMTP 配置（[MAIL] 段或顶层 MAIL_* 均可）：
 * MAIL_HOST / MAIL_PORT / MAIL_USERNAME / MAIL_PASSWORD /
 * MAIL_ENCRYPTION(ssl|tls|空) / MAIL_FROM_ADDRESS / MAIL_FROM_NAME
 *
 * 任何失败均返回 false 并记日志，不抛异常。
 */
class SendMailService
{
    /**
     * 发送邮件
     *
     * @param string $to      收件人邮箱
     * @param string $subject 邮件主题
     * @param string $body    邮件正文（HTML）
     * @param string $cc      抄送邮箱（可多个，逗号分隔，可空）
     * @return bool 是否发送成功
     */
    public static function send(string $to, string $subject, string $body, string $cc = ''): bool
    {
        $to = trim($to);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $cc = trim($cc);
        // 抄送与收件人相同则不抄送
        if ($cc !== '' && strcasecmp($cc, $to) === 0) {
            $cc = '';
        }

        try {
            $smtp = self::smtpConfig();

            if (!empty($smtp['host'])) {
                // 优先 PHPMailer
                if (class_exists('\PHPMailer\PHPMailer\PHPMailer')) {
                    return self::sendByPhpMailer($to, $subject, $body, $cc, $smtp);
                }
                // 无 PHPMailer：原生 fsockopen SMTP
                return self::sendByNativeSmtp($to, $subject, $body, $cc, $smtp);
            }

            // SMTP 未配置：原生 mail() 兜底
            return self::sendByNativeMail($to, $subject, $body, $cc);
        } catch (\Throwable $e) {
            Log::error('[SendMail] 邮件发送异常：' . $e->getMessage());
            return false;
        }
    }

    /**
     * 读取 .env SMTP 配置（支持 [MAIL] 段与顶层 MAIL_* 两种写法）
     * @return array{host:string,port:int,username:string,password:string,encryption:string,from_address:string,from_name:string}
     */
    private static function smtpConfig(): array
    {
        $get = static function (string $key, string $default = ''): string {
            // [MAIL] 段写法：MAIL.HOST；顶层写法：MAIL_HOST
            $val = Env::get('MAIL.' . $key, null);
            if ($val === null || $val === '') {
                $val = Env::get('MAIL_' . $key, $default);
            }
            return trim((string) $val);
        };

        return [
            'host'         => $get('HOST'),
            'port'         => (int) ($get('PORT', '465') ?: 465),
            'username'     => $get('USERNAME'),
            'password'     => $get('PASSWORD'),
            'encryption'   => strtolower($get('ENCRYPTION', 'ssl')),
            'from_address' => $get('FROM_ADDRESS') ?: $get('USERNAME'),
            'from_name'    => $get('FROM_NAME', '商城系统'),
        ];
    }

    /**
     * PHPMailer SMTP 发送
     */
    private static function sendByPhpMailer(string $to, string $subject, string $body, string $cc, array $smtp): bool
    {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->CharSet  = 'UTF-8';
        $mail->Host     = $smtp['host'];
        $mail->Port     = $smtp['port'];
        $mail->SMTPAuth = true;
        $mail->Username = $smtp['username'];
        $mail->Password = $smtp['password'];
        if ($smtp['encryption'] === 'ssl') {
            $mail->SMTPSecure = 'ssl';
        } elseif ($smtp['encryption'] === 'tls') {
            $mail->SMTPSecure = 'tls';
        } else {
            $mail->SMTPAutoTLS = false;
            $mail->SMTPSecure  = '';
        }
        $mail->setFrom($smtp['from_address'], $smtp['from_name']);
        $mail->addAddress($to);
        if ($cc !== '') {
            foreach (array_filter(array_map('trim', explode(',', $cc))) as $addr) {
                $mail->addCC($addr);
            }
        }
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->send();
        return true;
    }

    /**
     * 原生 fsockopen 轻量 SMTP 发送（支持 ssl/tls/明文）
     */
    private static function sendByNativeSmtp(string $to, string $subject, string $body, string $cc, array $smtp): bool
    {
        $host = $smtp['host'];
        if ($smtp['encryption'] === 'ssl') {
            $host = 'ssl://' . $host;
        }

        $fp = @fsockopen($host, $smtp['port'], $errno, $errstr, 10);
        if (!$fp) {
            Log::error("[SendMail] SMTP 连接失败：{$errstr}({$errno})");
            return false;
        }
        stream_set_timeout($fp, 15);

        $expect = static function (array $codes) use ($fp): string {
            $data = '';
            while ($line = fgets($fp, 515)) {
                $data .= $line;
                if (isset($line[3]) && $line[3] === ' ') {
                    break;
                }
            }
            $code = (int) substr($data, 0, 3);
            if (!in_array($code, $codes, true)) {
                throw new \RuntimeException('SMTP 应答异常：' . trim($data));
            }
            return $data;
        };
        $cmd = static function (string $command, array $codes) use ($fp, $expect): string {
            fwrite($fp, $command . "\r\n");
            return $expect($codes);
        };

        try {
            $expect([220]);
            $cmd('EHLO localhost', [250]);
            if ($smtp['encryption'] === 'tls') {
                $cmd('STARTTLS', [220]);
                stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                $cmd('EHLO localhost', [250]);
            }
            $cmd('AUTH LOGIN', [334]);
            $cmd(base64_encode($smtp['username']), [334]);
            $cmd(base64_encode($smtp['password']), [235]);

            $from = $smtp['from_address'] ?: $smtp['username'];
            $cmd('MAIL FROM:<' . $from . '>', [250]);

            $recipients = array_merge(
                [$to],
                array_filter(array_map('trim', explode(',', $cc)))
            );
            foreach ($recipients as $rcpt) {
                $cmd('RCPT TO:<' . $rcpt . '>', [250, 251]);
            }

            $cmd('DATA', [354]);

            $headers   = 'From: ' . $smtp['from_name'] . ' <' . $from . ">\r\n";
            $headers  .= 'To: <' . $to . ">\r\n";
            if ($cc !== '') {
                $headers .= 'Cc: ' . $cc . "\r\n";
            }
            $headers  .= 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n";
            $headers  .= "MIME-Version: 1.0\r\n";
            $headers  .= "Content-Type: text/html; charset=UTF-8\r\n";
            $headers  .= "Content-Transfer-Encoding: base64\r\n";
            $headers  .= 'Date: ' . date('r') . "\r\n";

            // 正文 base64 分行，防止 SMTP 单行超长；行首 '.' 做点填充转义
            $data = $headers . "\r\n" . chunk_split(base64_encode($body), 76, "\r\n");
            $data = preg_replace('/^\./m', '..', $data);
            fwrite($fp, $data . "\r\n.\r\n");
            $expect([250]);

            $cmd('QUIT', [221]);
            fclose($fp);
            return true;
        } catch (\Throwable $e) {
            fclose($fp);
            Log::error('[SendMail] SMTP 发送失败：' . $e->getMessage());
            return false;
        }
    }

    /**
     * PHP 原生 mail() 兜底发送
     */
    private static function sendByNativeMail(string $to, string $subject, string $body, string $cc): bool
    {
        if (!function_exists('mail')) {
            Log::error('[SendMail] mail() 不可用且未配置 SMTP，邮件未发送');
            return false;
        }

        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        if ($cc !== '') {
            $headers .= 'Cc: ' . $cc . "\r\n";
        }

        $ok = @mail($to, $subject, $body, $headers);
        if (!$ok) {
            Log::error('[SendMail] mail() 发送失败，收件人：' . $to);
        }
        return (bool) $ok;
    }
}
