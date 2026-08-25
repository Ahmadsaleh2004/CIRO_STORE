<?php

namespace App\Core;

class Totp
{
    private const SECRET_LENGTH = 20;

    public static function generateSecret(): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; // Base32 alphabet
        $secret = '';
        for ($i = 0; $i < self::SECRET_LENGTH; $i++) {
            $secret .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $secret;
    }

    public static function getQrCodeUrl(string $secret, string $accountEmail, string $issuer = 'Store'): string
    {
        $label = rawurlencode("{$issuer}:{$accountEmail}");
        $otpauth = "otpauth://totp/{$label}?secret={$secret}&issuer=" . rawurlencode($issuer);
        // نستخدم API عام مجاني لتوليد صورة QR (بدون أي تكلفة، لا يحتاج حساب)
        return 'https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=' . urlencode($otpauth);
    }

    public static function verifyCode(string $secret, string $code): bool
    {
        $code = trim($code);
        if (!preg_match('/^\d{6}$/', $code)) return false;

        // نسمح بفارق دقيقة واحدة قبل/بعد (لفروقات الساعة البسيطة)
        for ($offset = -1; $offset <= 1; $offset++) {
            $timeSlice = floor(time() / 30) + $offset;
            if (self::generateCode($secret, $timeSlice) === $code) {
                return true;
            }
        }
        return false;
    }

    private static function generateCode(string $secret, int $timeSlice): string
    {
        $secretKey = self::base32Decode($secret);
        $time = pack('N*', 0) . pack('N*', $timeSlice);
        $hash = hash_hmac('sha1', $time, $secretKey, true);
        $offset = ord($hash[19]) & 0xf;
        $value = (
            ((ord($hash[$offset + 0]) & 0x7f) << 24) |
            ((ord($hash[$offset + 1]) & 0xff) << 16) |
            ((ord($hash[$offset + 2]) & 0xff) << 8) |
            (ord($hash[$offset + 3]) & 0xff)
        );
        return str_pad((string)($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private static function base32Decode(string $secret): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = strtoupper($secret);
        $binaryString = '';
        foreach (str_split($secret) as $char) {
            $pos = strpos($chars, $char);
            if ($pos === false) continue;
            $binaryString .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $bytes = '';
        foreach (str_split($binaryString, 8) as $byte) {
            if (strlen($byte) === 8) {
                $bytes .= chr(bindec($byte));
            }
        }
        return $bytes;
    }
}