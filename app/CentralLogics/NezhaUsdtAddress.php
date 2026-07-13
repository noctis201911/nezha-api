<?php

namespace App\CentralLogics;

/**
 * USDT 收款地址的纯本地校验与规范化。
 *
 * TRC20 地址必须通过 Base58Check（0x41 网络字节 + 双 SHA-256 校验和）；
 * BEP20 地址必须是非零的 20 字节 EVM 地址，比较时统一为小写。
 * 本类只校验地址编码，不能证明商家实际控制该钱包。
 */
class NezhaUsdtAddress
{
    public const TRC20 = 'TRC20';

    public const BEP20 = 'BEP20';

    private const BASE58_ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    public static function normalizeNetwork($network): ?string
    {
        $value = strtoupper(trim((string) $network));

        if (str_contains($value, 'TRC') || str_contains($value, 'TRON')) {
            return self::TRC20;
        }
        if (str_contains($value, 'BEP') || str_contains($value, 'BSC') || str_contains($value, 'BNB')) {
            return self::BEP20;
        }

        return null;
    }

    public static function inspect($address, $network): array
    {
        $normalizedNetwork = self::normalizeNetwork($network);
        $trimmed = trim((string) $address);

        if ($normalizedNetwork === null) {
            return self::result(false, null, null, 'unsupported_network');
        }
        if ($trimmed === '') {
            return self::result(false, $normalizedNetwork, null, 'empty_address');
        }

        if ($normalizedNetwork === self::TRC20) {
            return self::inspectTron($trimmed);
        }

        return self::inspectEvm($trimmed);
    }

    public static function isValid($address, $network): bool
    {
        return self::inspect($address, $network)['valid'];
    }

    public static function normalize($address, $network): ?string
    {
        $result = self::inspect($address, $network);

        return $result['valid'] ? $result['normalized'] : null;
    }

    public static function equals($left, $right, $network): bool
    {
        $a = self::normalize($left, $network);
        $b = self::normalize($right, $network);

        return $a !== null && $b !== null && hash_equals($a, $b);
    }

    public static function fingerprint($address, $network): ?string
    {
        $normalizedNetwork = self::normalizeNetwork($network);
        $normalizedAddress = self::normalize($address, $normalizedNetwork);

        if ($normalizedNetwork === null || $normalizedAddress === null) {
            return null;
        }

        return hash('sha256', $normalizedNetwork.'|'.$normalizedAddress);
    }

    private static function inspectTron(string $address): array
    {
        if (! preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $address)) {
            return self::result(false, self::TRC20, null, 'invalid_tron_format');
        }

        $decoded = self::decodeBase58($address);
        if ($decoded === null || strlen($decoded) !== 25) {
            return self::result(false, self::TRC20, null, 'invalid_tron_length');
        }

        $payload = substr($decoded, 0, 21);
        $checksum = substr($decoded, 21, 4);
        if (ord($payload[0]) !== 0x41) {
            return self::result(false, self::TRC20, null, 'invalid_tron_network');
        }

        $expectedChecksum = substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4);
        if (! hash_equals($expectedChecksum, $checksum)) {
            return self::result(false, self::TRC20, null, 'invalid_tron_checksum');
        }

        // Base58 大小写敏感，合法 TRC20 地址必须原样保留。
        return self::result(true, self::TRC20, $address, null);
    }

    private static function inspectEvm(string $address): array
    {
        if (! preg_match('/^0x[0-9a-fA-F]{40}$/', $address)) {
            return self::result(false, self::BEP20, null, 'invalid_evm_format');
        }

        $normalized = strtolower($address);
        if ($normalized === '0x'.str_repeat('0', 40)) {
            return self::result(false, self::BEP20, null, 'zero_evm_address');
        }

        return self::result(true, self::BEP20, $normalized, null);
    }

    private static function decodeBase58(string $input): ?string
    {
        $bytes = [0];
        $length = strlen($input);

        for ($i = 0; $i < $length; $i++) {
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
        while ($leadingZeros < $length && $input[$leadingZeros] === '1') {
            $leadingZeros++;
        }
        while (! empty($bytes) && $bytes[0] === 0) {
            array_shift($bytes);
        }

        $decoded = str_repeat("\0", $leadingZeros);
        foreach ($bytes as $byte) {
            $decoded .= chr($byte);
        }

        return $decoded;
    }

    private static function result(bool $valid, ?string $network, ?string $normalized, ?string $error): array
    {
        return [
            'valid' => $valid,
            'network' => $network,
            'normalized' => $normalized,
            'error' => $error,
        ];
    }
}
