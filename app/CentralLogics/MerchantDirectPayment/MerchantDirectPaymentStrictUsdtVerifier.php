<?php

namespace App\CentralLogics\MerchantDirectPayment;

use App\CentralLogics\NezhaUsdtAddress;

/** Pure, fail-closed evaluation of normalized USDT provider observations. */
final class MerchantDirectPaymentStrictUsdtVerifier
{
    public const BSC_USDT = '0x55d398326f99059ff775485246999027b3197955';

    public const TRON_USDT = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';

    /**
     * @return array{status:string,failure_code:?string,event_index?:int,from?:string,to?:string,amount_atomic?:string,block_number?:string,provider_evidence:?array}
     */
    public static function evaluate(
        string $channel,
        string $expectedTo,
        string $expectedAmountAtomic,
        array $observation
    ): array {
        $network = self::network($channel);
        if ($network === null || ! self::canonicalPositiveInteger($expectedAmountAtomic)) {
            return self::result('unavailable', 'invalid_expectation', $observation);
        }
        $normalizedExpectedTo = NezhaUsdtAddress::normalize($expectedTo, $network);
        if ($normalizedExpectedTo === null) {
            return self::result('unavailable', 'invalid_expectation', $observation);
        }

        $providerStatus = $observation['provider_status'] ?? null;
        if ($providerStatus === 'unavailable') {
            return self::result('unavailable', 'provider_unavailable', $observation);
        }
        if ($providerStatus === 'not_found') {
            return self::result('not_found', 'transaction_not_found', $observation);
        }
        if ($providerStatus !== 'ok') {
            return self::result('unavailable', 'malformed_observation', $observation);
        }
        if (($observation['receipt_status'] ?? null) === 'failed') {
            return self::result('mismatch', 'transaction_failed', $observation);
        }
        if (($observation['receipt_status'] ?? null) !== 'success') {
            return self::result('unavailable', 'malformed_observation', $observation);
        }

        if ($network === NezhaUsdtAddress::BEP20) {
            $finalizedBlock = $observation['finalized_block_number'] ?? null;
            if (! self::canonicalPositiveInteger($finalizedBlock)) {
                return self::result('unavailable', 'finality_unavailable', $observation);
            }
        } elseif (($observation['solidified'] ?? null) !== true) {
            return self::result('observed', 'not_solidified', $observation);
        }

        $events = $observation['events'] ?? null;
        if (! is_array($events)) {
            return self::result('unavailable', 'malformed_observation', $observation);
        }

        $contract = self::tokenContract($channel);
        $matches = [];
        $seenIndexes = [];
        foreach ($events as $event) {
            $normalized = self::normalizeEvent($network, $event);
            if ($normalized === null || isset($seenIndexes[$normalized['event_index']])) {
                return self::result('unavailable', 'malformed_observation', $observation);
            }
            $seenIndexes[$normalized['event_index']] = true;
            if (! self::identifierEquals($network, $normalized['contract'], $contract)) {
                continue;
            }
            if (! NezhaUsdtAddress::equals($normalized['to'], $normalizedExpectedTo, $network)) {
                continue;
            }
            if ($normalized['amount_atomic'] !== $expectedAmountAtomic) {
                continue;
            }
            $matches[] = $normalized;
        }

        if (count($matches) !== 1) {
            return self::result('mismatch', count($matches) === 0 ? 'transfer_mismatch' : 'ambiguous_transfer_event', $observation);
        }
        $event = $matches[0];
        if ($network === NezhaUsdtAddress::BEP20
            && self::compareUnsignedIntegers($event['block_number'], (string) $observation['finalized_block_number']) > 0) {
            return self::withEvent(self::result('observed', 'not_finalized', $observation), $event);
        }

        return self::withEvent(self::result('confirmed', null, $observation), $event);
    }

    public static function network(string $channel): ?string
    {
        return match ($channel) {
            MerchantDirectPaymentLateCasePolicy::CHANNEL_USDT_BEP20 => NezhaUsdtAddress::BEP20,
            MerchantDirectPaymentLateCasePolicy::CHANNEL_USDT_TRC20 => NezhaUsdtAddress::TRC20,
            default => null,
        };
    }

    public static function tokenContract(string $channel): ?string
    {
        return match ($channel) {
            MerchantDirectPaymentLateCasePolicy::CHANNEL_USDT_BEP20 => self::BSC_USDT,
            MerchantDirectPaymentLateCasePolicy::CHANNEL_USDT_TRC20 => self::TRON_USDT,
            default => null,
        };
    }

    public static function tokenDecimals(string $channel): ?int
    {
        return match ($channel) {
            MerchantDirectPaymentLateCasePolicy::CHANNEL_USDT_BEP20 => 18,
            MerchantDirectPaymentLateCasePolicy::CHANNEL_USDT_TRC20 => 6,
            default => null,
        };
    }

    private static function normalizeEvent(string $network, mixed $event): ?array
    {
        if (! is_array($event)
            || ! is_int($event['event_index'] ?? null)
            || $event['event_index'] < 0
            || ! self::canonicalPositiveInteger($event['amount_atomic'] ?? null)
            || ! self::canonicalPositiveInteger($event['block_number'] ?? null)) {
            return null;
        }
        $contract = NezhaUsdtAddress::normalize($event['contract'] ?? null, $network);
        $from = NezhaUsdtAddress::normalize($event['from'] ?? null, $network);
        $to = NezhaUsdtAddress::normalize($event['to'] ?? null, $network);
        if ($contract === null || $from === null || $to === null) {
            return null;
        }

        return [
            'event_index' => $event['event_index'],
            'contract' => $contract,
            'from' => $from,
            'to' => $to,
            'amount_atomic' => $event['amount_atomic'],
            'block_number' => $event['block_number'],
        ];
    }

    private static function result(string $status, ?string $failureCode, array $observation): array
    {
        $evidence = $observation['provider_evidence'] ?? null;
        if (! is_array($evidence)) {
            $evidence = null;
        } else {
            $evidence = array_intersect_key($evidence, array_flip([
                'source', 'reason', 'chain_id', 'finality_source', 'finality_reason', 'transaction_seen',
            ]));
            foreach ($evidence as $key => $value) {
                if ((! is_string($value) && ! is_int($value) && ! is_bool($value) && $value !== null)
                    || (is_string($value) && strlen($value) > 120)) {
                    unset($evidence[$key]);
                }
            }
        }

        return [
            'status' => $status,
            'failure_code' => $failureCode,
            'provider_evidence' => $evidence,
        ];
    }

    private static function withEvent(array $result, array $event): array
    {
        return $result + $event;
    }

    private static function canonicalPositiveInteger(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1;
    }

    private static function compareUnsignedIntegers(string $left, string $right): int
    {
        return strlen($left) <=> strlen($right) ?: strcmp($left, $right);
    }

    private static function identifierEquals(string $network, string $left, ?string $right): bool
    {
        return $right !== null && NezhaUsdtAddress::equals($left, $right, $network);
    }
}
