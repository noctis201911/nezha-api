<?php

namespace App\CentralLogics\MerchantDirectPayment;

final class MerchantDirectPaymentUsdtAddress
{
    private const BASE58_ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    public static function normalize(string $address, string $network): ?string
    {
        $address = trim($address);

        if ($network === 'bep20') {
            if (! preg_match('/^0x[0-9a-fA-F]{40}$/', $address)) {
                return null;
            }

            $normalized = strtolower($address);

            return $normalized === '0x'.str_repeat('0', 40) ? null : $normalized;
        }

        if ($network !== 'trc20'
            || ! preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $address)) {
            return null;
        }

        $decoded = self::decodeBase58($address);
        if ($decoded === null || strlen($decoded) !== 25) {
            return null;
        }

        $payload = substr($decoded, 0, 21);
        $checksum = substr($decoded, 21, 4);
        $expected = substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4);

        return ord($payload[0]) === 0x41 && hash_equals($expected, $checksum)
            ? $address
            : null;
    }

    public static function fingerprint(string $address, string $network): ?string
    {
        $normalized = self::normalize($address, $network);

        return $normalized === null
            ? null
            : hash('sha256', strtoupper($network).'|'.$normalized);
    }

    private static function decodeBase58(string $input): ?string
    {
        $bytes = [0];

        for ($i = 0, $length = strlen($input); $i < $length; $i++) {
            $digit = strpos(self::BASE58_ALPHABET, $input[$i]);
            if ($digit === false) {
                return null;
            }

            $carry = $digit;
            for ($j = count($bytes) - 1; $j >= 0; $j--) {
                $carry += $bytes[$j] * 58;
                $bytes[$j] = $carry & 0xFF;
                $carry >>= 8;
            }
            while ($carry > 0) {
                array_unshift($bytes, $carry & 0xFF);
                $carry >>= 8;
            }
        }

        $leadingZeros = 0;
        while ($leadingZeros < strlen($input) && $input[$leadingZeros] === '1') {
            $leadingZeros++;
        }
        while ($bytes !== [] && $bytes[0] === 0) {
            array_shift($bytes);
        }

        $decoded = str_repeat("\0", $leadingZeros);
        foreach ($bytes as $byte) {
            $decoded .= chr($byte);
        }

        return $decoded;
    }
}
