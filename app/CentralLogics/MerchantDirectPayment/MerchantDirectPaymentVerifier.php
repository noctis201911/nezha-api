<?php

namespace App\CentralLogics\MerchantDirectPayment;

use Brick\Math\BigInteger;

final class MerchantDirectPaymentVerifier
{
    private const PROVIDER_EVIDENCE_STRING_VALUES = [
        'source' => [
            'bsc_json_rpc',
            'trongrid_v1',
        ],
        'reason' => [
            'duplicate_event_index',
            'http_error',
            'malformed_block_quantity',
            'malformed_receipt',
            'malformed_receipt_status',
            'malformed_response',
            'pagination_bad_next',
            'pagination_http_error',
            'pagination_limit',
            'pagination_malformed_envelope',
            'pagination_malformed_meta',
            'pagination_malformed_page',
            'pagination_missing_fingerprint',
            'pagination_repeated_page',
            'pagination_token_drift',
            'pagination_transport_error',
            'rate_limited',
            'receipt_transaction_mismatch',
            'rpc_error',
            'server_misconfigured',
            'timeout_or_connection',
            'transaction_mismatch',
            'transport_error',
            'wrong_chain',
        ],
        'finality_source' => [
            'finalized_block_tag',
            'tron_solidity_node',
        ],
        'finality_reason' => [
            'canonical_block_mismatch',
            'canonical_block_unavailable',
            'event_block_mismatch',
            'finalized_tag_unavailable',
            'http_error',
            'malformed_solidity_block',
            'rate_limited',
            'rpc_error',
            'solidity_transaction_mismatch',
            'timeout_or_connection',
            'transport_error',
        ],
    ];

    /**
     * Pure evaluation of a provider observation. Transport errors are data, not
     * exceptions, so an unavailable provider can never be projected as unpaid.
     */
    public static function evaluate(
        MerchantDirectPaymentChannel $channel,
        string $expectedTo,
        string $expectedAmountAtomic,
        array $observation,
    ): array {
        $providerStatus = $observation['provider_status'] ?? null;
        if (! is_string($providerStatus)
            || ! in_array($providerStatus, ['ok', 'not_found', 'unavailable'], true)) {
            return self::base('unavailable', 'malformed_observation', $observation);
        }
        if ($providerStatus === 'unavailable') {
            return self::base('unavailable', 'provider_unavailable', $observation);
        }
        if ($providerStatus === 'not_found') {
            return self::base('not_found', 'transaction_not_found', $observation);
        }
        $receiptStatus = $observation['receipt_status'] ?? null;
        if ($receiptStatus === 'failed') {
            return self::base('mismatch', 'transaction_failed', $observation);
        }
        if ($receiptStatus !== 'success') {
            return self::base('unavailable', 'malformed_observation', $observation);
        }
        if ($channel === MerchantDirectPaymentChannel::USDT_BEP20) {
            if (! array_key_exists('finalized_block_number', $observation)
                || ($observation['finalized_block_number'] !== null
                    && ! self::isCanonicalPositiveDecimal($observation['finalized_block_number']))) {
                return self::base('unavailable', 'malformed_observation', $observation);
            }
        } elseif ($channel === MerchantDirectPaymentChannel::USDT_TRC20) {
            if (! array_key_exists('solidified', $observation)
                || ! is_bool($observation['solidified'])) {
                return self::base('unavailable', 'malformed_observation', $observation);
            }
        } else {
            return self::base('unavailable', 'malformed_observation', $observation);
        }

        $events = $observation['events'] ?? null;
        if (! is_array($events)) {
            return self::base('unavailable', 'malformed_observation', $observation);
        }
        $normalizedEvents = [];
        $eventIndexes = [];
        foreach ($events as $event) {
            if (! is_array($event)) {
                return self::base('unavailable', 'malformed_observation', $observation);
            }
            $normalizedEvent = self::normalizeEvent($channel, $event);
            if ($normalizedEvent === null) {
                return self::base('unavailable', 'malformed_observation', $observation);
            }
            if (isset($eventIndexes[$normalizedEvent['event_index']])) {
                return self::base('unavailable', 'malformed_observation', $observation);
            }
            $eventIndexes[$normalizedEvent['event_index']] = true;
            $normalizedEvents[] = $normalizedEvent;
        }
        $events = $normalizedEvents;
        $contractEvents = array_values(array_filter($events, fn (array $event) => self::sameIdentifier(
            $channel,
            (string) ($event['contract'] ?? ''),
            (string) $channel->tokenContract(),
        )));
        if ($contractEvents === []) {
            return self::base('mismatch', 'wrong_contract', $observation);
        }

        $destinationEvents = array_values(array_filter($contractEvents, fn (array $event) => self::sameIdentifier(
            $channel,
            (string) ($event['to'] ?? ''),
            $expectedTo,
        )));
        if ($destinationEvents === []) {
            return self::withEvent(self::base('mismatch', 'wrong_destination', $observation), $contractEvents[0]);
        }

        $exactEvents = array_values(array_filter($destinationEvents, static function (array $event) use ($expectedAmountAtomic) {
            try {
                return BigInteger::of((string) ($event['amount_atomic'] ?? '-1'))
                    ->isEqualTo(BigInteger::of($expectedAmountAtomic));
            } catch (\Throwable) {
                return false;
            }
        }));
        if ($exactEvents === []) {
            return self::withEvent(self::base('mismatch', 'amount_mismatch', $observation), $destinationEvents[0]);
        }
        if (count($exactEvents) !== 1) {
            return self::base('mismatch', 'ambiguous_transfer_event', $observation);
        }

        $event = $exactEvents[0];
        $confirmed = false;
        $evidence = ['source' => 'provider_observation'];
        if ($channel === MerchantDirectPaymentChannel::USDT_TRC20) {
            $confirmed = ($observation['solidified'] ?? false) === true;
            $evidence = [
                'source' => $confirmed ? 'tron_solidity_node' : 'tron_full_node',
                'solidified' => $confirmed,
            ];
        } elseif ($channel === MerchantDirectPaymentChannel::USDT_BEP20) {
            $finalizedBlock = $observation['finalized_block_number'] ?? null;
            try {
                $confirmed = $finalizedBlock !== null
                    && BigInteger::of((string) $event['block_number'])->isLessThanOrEqualTo(BigInteger::of((string) $finalizedBlock));
            } catch (\Throwable) {
                $confirmed = false;
            }
            $evidence = [
                'source' => $confirmed ? 'finalized_block_tag' : 'latest_block_only',
                'transaction_block' => (string) ($event['block_number'] ?? ''),
                'finalized_block' => $finalizedBlock === null ? null : (string) $finalizedBlock,
            ];
        }

        return self::withEvent([
            'status' => $confirmed ? 'confirmed' : 'observed',
            'failure_code' => null,
            'confirmation_evidence' => $evidence,
            'provider_evidence' => self::providerEvidence($observation),
        ], $event);
    }

    private static function base(string $status, ?string $failureCode, array $observation): array
    {
        return [
            'status' => $status,
            'failure_code' => $failureCode,
            'confirmation_evidence' => null,
            'provider_evidence' => self::providerEvidence($observation),
        ];
    }

    private static function providerEvidence(array $observation): ?array
    {
        $raw = $observation['provider_evidence'] ?? null;
        if (! is_array($raw)) {
            return null;
        }

        $evidence = [];
        foreach (['source', 'reason'] as $key) {
            if (in_array($raw[$key] ?? null, self::PROVIDER_EVIDENCE_STRING_VALUES[$key], true)) {
                $evidence[$key] = $raw[$key];
            }
        }
        if (is_int($raw['chain_id'] ?? null) && $raw['chain_id'] > 0) {
            $evidence['chain_id'] = $raw['chain_id'];
        }
        foreach (['finality_source', 'finality_reason'] as $key) {
            if (in_array($raw[$key] ?? null, self::PROVIDER_EVIDENCE_STRING_VALUES[$key], true)) {
                $evidence[$key] = $raw[$key];
            }
        }
        if (is_bool($raw['transaction_seen'] ?? null)) {
            $evidence['transaction_seen'] = $raw['transaction_seen'];
        }

        return $evidence === [] ? null : $evidence;
    }

    private static function withEvent(array $result, array $event): array
    {
        return $result + [
            'event_index' => isset($event['event_index']) ? (int) $event['event_index'] : null,
            'contract' => $event['contract'] ?? null,
            'from' => $event['from'] ?? null,
            'to' => $event['to'] ?? null,
            'amount_atomic' => isset($event['amount_atomic']) ? (string) $event['amount_atomic'] : null,
            'block_number' => isset($event['block_number']) ? (string) $event['block_number'] : null,
        ];
    }

    private static function normalizeEvent(
        MerchantDirectPaymentChannel $channel,
        array $event,
    ): ?array {
        $eventIndex = $event['event_index'] ?? null;
        $amount = $event['amount_atomic'] ?? null;
        $blockNumber = $event['block_number'] ?? null;
        if (! is_int($eventIndex)
            || $eventIndex < 0
            || ! self::isCanonicalPositiveDecimal($amount)
            || ! self::isCanonicalPositiveDecimal($blockNumber)) {
            return null;
        }

        $contract = self::normalizeAddress($channel, $event['contract'] ?? null);
        $from = self::normalizeAddress($channel, $event['from'] ?? null);
        $to = self::normalizeAddress($channel, $event['to'] ?? null);
        if ($contract === null || $from === null || $to === null) {
            return null;
        }

        return [
            'event_index' => $eventIndex,
            'contract' => $contract,
            'from' => $from,
            'to' => $to,
            'amount_atomic' => $amount,
            'block_number' => $blockNumber,
        ];
    }

    private static function normalizeAddress(
        MerchantDirectPaymentChannel $channel,
        mixed $address,
    ): ?string {
        if (! is_string($address) || trim($address) !== $address) {
            return null;
        }
        if ($channel === MerchantDirectPaymentChannel::USDT_BEP20) {
            return preg_match('/^0x[0-9a-fA-F]{40}$/D', $address) === 1
                ? strtolower($address)
                : null;
        }
        if ($channel !== MerchantDirectPaymentChannel::USDT_TRC20) {
            return null;
        }

        return MerchantDirectPaymentUsdtAddress::normalize($address, 'trc20');
    }

    private static function isCanonicalPositiveDecimal(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1;
    }

    private static function sameIdentifier(
        MerchantDirectPaymentChannel $channel,
        string $actual,
        string $expected,
    ): bool {
        if ($channel === MerchantDirectPaymentChannel::USDT_BEP20) {
            return strtolower(trim($actual)) === strtolower(trim($expected));
        }

        return trim($actual) === trim($expected);
    }
}
