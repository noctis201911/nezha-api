<?php

namespace App\CentralLogics\MerchantDirectPayment;

use InvalidArgumentException;

final class MerchantDirectPaymentHash
{
    public static function normalize(string $hash): string
    {
        $normalized = strtolower(trim($hash));
        if (str_starts_with($normalized, '0x')) {
            $normalized = substr($normalized, 2);
        }

        if (! preg_match('/^[0-9a-f]{64}$/', $normalized)) {
            throw new InvalidArgumentException('Transaction hash must contain exactly 64 hexadecimal characters.');
        }

        return $normalized;
    }
}
