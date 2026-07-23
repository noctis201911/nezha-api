<?php

namespace App\CentralLogics;

/**
 * Exact decimal/atomic helpers for USDT. No float enters refund routing.
 */
class NezhaAtomicAmount
{
    public static function decimalToAtomic(string $amount, int $decimals): string
    {
        $amount = trim($amount);
        if ($decimals < 0 || $decimals > 30
            || ! preg_match('/^\d+(?:\.\d+)?$/', $amount)) {
            throw new \DomainException('invalid_decimal_amount');
        }

        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        if (strlen($fraction) > $decimals
            && trim(substr($fraction, $decimals), '0') !== '') {
            throw new \DomainException('amount_precision_exceeded');
        }
        $fraction = str_pad(substr($fraction, 0, $decimals), $decimals, '0');

        return self::normalizeInteger($whole.$fraction);
    }

    public static function atomicToDecimal(string $atomic, int $decimals): string
    {
        $atomic = self::normalizeInteger($atomic);
        if ($decimals === 0) {
            return $atomic;
        }
        $padded = str_pad($atomic, $decimals + 1, '0', STR_PAD_LEFT);
        $whole = substr($padded, 0, -$decimals);
        $fraction = rtrim(substr($padded, -$decimals), '0');

        return $fraction === '' ? $whole : $whole.'.'.$fraction;
    }

    public static function prorateFloor(
        string $paidAtomic,
        string $refundAmd,
        string $refundableAmdSnapshot
    ): string {
        $paidAtomic = self::normalizeInteger($paidAtomic);
        $refundMinor = self::decimalToAtomic($refundAmd, 2);
        $refundableMinor = self::decimalToAtomic($refundableAmdSnapshot, 2);
        if ($paidAtomic === '0' || $refundMinor === '0' || $refundableMinor === '0') {
            throw new \DomainException('refund_amount_snapshot_invalid');
        }
        if (! function_exists('bcmul') || ! function_exists('bcdiv') || ! function_exists('bccomp')) {
            throw new \DomainException('exact_amount_math_unavailable');
        }

        $boundedRefund = bccomp($refundMinor, $refundableMinor, 0) > 0
            ? $refundableMinor
            : $refundMinor;

        return self::normalizeInteger(
            bcdiv(bcmul($paidAtomic, $boundedRefund, 0), $refundableMinor, 0)
        );
    }

    public static function compare(string $left, string $right): int
    {
        $left = self::normalizeInteger($left);
        $right = self::normalizeInteger($right);
        if (function_exists('bccomp')) {
            return bccomp($left, $right, 0);
        }
        if (strlen($left) !== strlen($right)) {
            return strlen($left) <=> strlen($right);
        }

        return strcmp($left, $right);
    }

    public static function normalizeInteger(string $value): string
    {
        $value = trim($value);
        if (! preg_match('/^\d+$/', $value)) {
            throw new \DomainException('invalid_atomic_amount');
        }

        return ltrim($value, '0') ?: '0';
    }

    public static function refundableAmdSnapshot($order): string
    {
        if (! function_exists('bcsub') || ! function_exists('bccomp')) {
            throw new \DomainException('exact_amount_math_unavailable');
        }

        $raw = static fn (string $field): string => self::currencyAmount(
            $order->getRawOriginal($field) ?? $order->{$field} ?? '0'
        );
        $total = $raw('order_amount');
        $refund = $total;
        foreach (['delivery_charge', 'dm_tips', 'additional_charge', 'extra_packaging_amount'] as $field) {
            $refund = bcsub($refund, $raw($field), 2);
        }
        if (bccomp($refund, '0.00', 2) < 0) {
            return '0.00';
        }
        if (bccomp($refund, $total, 2) > 0) {
            return $total;
        }

        return bcadd($refund, '0', 2);
    }

    public static function currencyAmount($value): string
    {
        $amount = trim((string) $value);
        if ($amount === '') {
            $amount = '0';
        }
        $minor = self::decimalToAtomic($amount, 2);
        $padded = str_pad($minor, 3, '0', STR_PAD_LEFT);
        $exact = substr($padded, 0, -2).'.'.substr($padded, -2);

        return function_exists('bcadd')
            ? bcadd($exact, '0', 2)
            : $exact;
    }
}
