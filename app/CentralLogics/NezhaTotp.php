<?php

namespace App\CentralLogics;

/**
 * 哪吒 - 零依赖 TOTP (RFC 6238) 实现, 用于 Admin 后台两步验证。
 * 兼容 Google Authenticator / 微软 Authenticator / 1Password 等标准认证器。
 * SHA1 / 6 位 / 30 秒周期。
 */
class NezhaTotp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; // base32

    /** 生成新的 base32 密钥(默认 32 字符 = 160 bit) */
    public static function generateSecret(int $length = 32): string
    {
        $secret = '';
        $bytes = random_bytes($length);
        for ($i = 0; $i < $length; $i++) {
            $secret .= self::ALPHABET[ord($bytes[$i]) & 31];
        }
        return $secret;
    }

    /** 验证一个 6 位验证码, 允许前后各 $window 个时间步的容错(防时钟漂移) */
    public static function verify(string $secret, string $code, int $window = 1): bool
    {
        return self::matchingCounter($secret, $code, $window) !== null;
    }

    /**
     * 返回匹配的 30 秒时间步，供资金地址交易级验证做数据库防重放。
     * 只返回计数器，不返回或记录验证码；普通登录仍可继续使用 verify()。
     */
    public static function matchingCounter(string $secret, string $code, int $window = 1): ?int
    {
        $code = preg_replace('/\s+/', '', $code);
        if (!preg_match('/^\d{6}$/', $code)) {
            return null;
        }

        $timeStep = (int) floor(time() / 30);
        for ($i = -$window; $i <= $window; $i++) {
            $counter = $timeStep + $i;
            $candidate = self::codeAt($secret, $counter);
            if ($candidate !== '' && hash_equals($candidate, $code)) {
                return $counter;
            }
        }

        return null;
    }

    /** 计算指定时间步的验证码(供 verify + 测试用) */
    public static function codeAt(string $secret, int $counter): string
    {
        $key = self::base32Decode($secret);
        if ($key === '') {
            return '';
        }
        // 8 字节大端计数器
        $binCounter = pack('N*', ($counter >> 32) & 0xffffffff, $counter & 0xffffffff);
        $hash = hash_hmac('sha1', $binCounter, $key, true);
        $offset = ord($hash[19]) & 0x0f;
        $value = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);
        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private static function base32Decode(string $b32): string
    {
        $b32 = strtoupper(rtrim($b32, '='));
        $buffer = 0;
        $bitsLeft = 0;
        $out = '';
        $len = strlen($b32);
        for ($i = 0; $i < $len; $i++) {
            $val = strpos(self::ALPHABET, $b32[$i]);
            if ($val === false) {
                continue;
            }
            $buffer = ($buffer << 5) | $val;
            $bitsLeft += 5;
            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $out .= chr(($buffer >> $bitsLeft) & 0xff);
            }
        }
        return $out;
    }

    /** 构造 otpauth:// URI(认证器扫码用) */
    public static function otpauthUri(string $secret, string $label, string $issuer): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $label)
            . '?secret=' . $secret
            . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=6&period=30';
    }
}
