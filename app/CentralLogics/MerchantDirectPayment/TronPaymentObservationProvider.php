<?php

namespace App\CentralLogics\MerchantDirectPayment;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

final class TronPaymentObservationProvider implements PaymentObservationProvider
{
    private const CONTRACT_FAILURE_RESULTS = [
        'BAD_JUMP_DESTINATION',
        'ILLEGAL_OPERATION',
        'INVALID_CODE',
        'JVM_STACK_OVER_FLOW',
        'OUT_OF_ENERGY',
        'OUT_OF_MEMORY',
        'OUT_OF_TIME',
        'PRECOMPILED_CONTRACT',
        'REVERT',
        'STACK_OVERFLOW',
        'STACK_TOO_LARGE',
        'STACK_TOO_SMALL',
        'TRANSFER_FAILED',
        'UNKNOWN',
    ];

    public function __construct(
        private readonly string $apiBase,
        private readonly ?string $apiKey = null,
        private readonly int $timeoutSeconds = 10,
    ) {}

    public function observe(string $normalizedTxHash): array
    {
        $hash = MerchantDirectPaymentHash::normalize($normalizedTxHash);
        $base = rtrim($this->apiBase, '/');

        try {
            $transactionResponse = $this->request()->get($base.'/v1/transactions/'.$hash);
        } catch (ConnectionException) {
            return $this->unavailable('timeout_or_connection');
        } catch (Throwable) {
            return $this->unavailable('transport_error');
        }

        if (! $transactionResponse->successful()) {
            return $this->unavailable($this->httpReason($transactionResponse->status()));
        }
        try {
            $transactionEnvelope = json_decode(
                $transactionResponse->body(),
                associative: false,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (Throwable) {
            return $this->unavailable('malformed_response');
        }
        if (! is_object($transactionEnvelope)) {
            return $this->unavailable('malformed_response');
        }
        if (($transactionEnvelope->success ?? null) !== true) {
            return $this->unavailable('malformed_response');
        }
        if (! property_exists($transactionEnvelope, 'data')) {
            return $this->unavailable('malformed_response');
        }
        if (! is_array($transactionEnvelope->data)) {
            return $this->unavailable('malformed_response');
        }
        if ($transactionEnvelope->data === []) {
            return [
                'provider_status' => 'not_found',
                'provider_evidence' => ['source' => 'trongrid_v1'],
            ];
        }
        if (count($transactionEnvelope->data) !== 1
            || ! is_object($transactionEnvelope->data[0])) {
            return $this->unavailable('malformed_response');
        }
        $transaction = $transactionResponse->json()['data'][0];
        try {
            $transactionHash = is_string($transaction['txID'] ?? null)
                ? MerchantDirectPaymentHash::normalize($transaction['txID'])
                : null;
        } catch (Throwable) {
            $transactionHash = null;
        }
        if ($transactionHash !== $hash) {
            return $this->unavailable('transaction_mismatch');
        }
        $contractResult = $transaction['ret'][0]['contractRet'] ?? null;
        if (! is_string($contractResult)) {
            return $this->unavailable('malformed_receipt_status');
        }
        if ($contractResult === 'SUCCESS') {
            $receiptStatus = 'success';
        } elseif (in_array($contractResult, self::CONTRACT_FAILURE_RESULTS, true)) {
            $receiptStatus = 'failed';
        } else {
            return $this->unavailable('malformed_receipt_status');
        }

        $eventPageResult = $this->fetchEventPages($base.'/v1/transactions/'.$hash.'/events');
        if ($eventPageResult['reason'] !== null) {
            return $this->unavailable($eventPageResult['reason'], true);
        }

        $events = [];
        foreach ($eventPageResult['events'] as $event) {
            if (! is_array($event) || ($event['event_name'] ?? null) !== 'Transfer') {
                continue;
            }
            $eventTransactionId = $event['transaction_id'] ?? $event['transactionId'] ?? $event['txID'] ?? null;
            try {
                if (! is_string($eventTransactionId)
                    || MerchantDirectPaymentHash::normalize($eventTransactionId) !== $hash) {
                    continue;
                }
            } catch (Throwable) {
                continue;
            }
            if (! array_key_exists('block_number', $event)) {
                continue;
            }
            $eventBlockNumber = $event['block_number'];
            if (is_int($eventBlockNumber) && $eventBlockNumber <= 0) {
                continue;
            }
            if (! is_int($eventBlockNumber)
                && (! is_string($eventBlockNumber)
                    || preg_match('/^[1-9][0-9]*$/D', $eventBlockNumber) !== 1)) {
                continue;
            }
            $result = is_array($event['result'] ?? null) ? $event['result'] : [];
            $amount = $this->normalizeNonNegativeDecimal(
                $result['value'] ?? $result['_value'] ?? $result['amount'] ?? null,
            );
            if ($amount === null) {
                continue;
            }
            $contract = $event['contract_address'] ?? null;
            $from = $result['from'] ?? $result['_from'] ?? null;
            $to = $result['to'] ?? $result['_to'] ?? null;
            if (! $this->isNonEmptyString($contract)
                || ! $this->isNonEmptyString($from)
                || ! $this->isNonEmptyString($to)) {
                continue;
            }
            $eventIndex = $this->normalizeEventIndex($event['event_index'] ?? null);
            if ($eventIndex === null) {
                continue;
            }
            $events[] = [
                'event_index' => $eventIndex,
                'contract' => $contract,
                'from' => $from,
                'to' => $to,
                'amount_atomic' => $amount,
                'block_number' => (string) $eventBlockNumber,
            ];
        }

        $solidified = false;
        $finalityReason = null;
        try {
            $solidityResponse = $this->request()->post($base.'/walletsolidity/gettransactioninfobyid', [
                'value' => $hash,
            ]);
            if (! $solidityResponse->successful()) {
                $finalityReason = $this->httpReason($solidityResponse->status());
            } else {
                $solidity = $solidityResponse->json();
                $solidityTransactionId = is_array($solidity) ? ($solidity['id'] ?? null) : null;
                try {
                    $solidityTransactionMatches = is_string($solidityTransactionId)
                        && MerchantDirectPaymentHash::normalize($solidityTransactionId) === $hash;
                } catch (Throwable) {
                    $solidityTransactionMatches = false;
                }

                $solidityBlockNumber = is_array($solidity) ? ($solidity['blockNumber'] ?? null) : null;
                $solidityBlockNumberIsValid = (is_int($solidityBlockNumber) && $solidityBlockNumber > 0)
                    || (is_string($solidityBlockNumber)
                        && preg_match('/^[1-9][0-9]*$/D', $solidityBlockNumber) === 1);
                $eventBlocksMatch = $solidityBlockNumberIsValid;
                if ($eventBlocksMatch) {
                    $normalizedSolidityBlockNumber = (string) $solidityBlockNumber;
                    foreach ($events as $event) {
                        if ($event['block_number'] !== $normalizedSolidityBlockNumber) {
                            $eventBlocksMatch = false;
                            break;
                        }
                    }
                }
                $solidified = $solidityTransactionMatches && $solidityBlockNumberIsValid && $eventBlocksMatch;
                if (! $solidityTransactionMatches) {
                    $finalityReason = 'solidity_transaction_mismatch';
                } elseif (! $solidityBlockNumberIsValid) {
                    $finalityReason = 'malformed_solidity_block';
                } elseif (! $eventBlocksMatch) {
                    $finalityReason = 'event_block_mismatch';
                }
            }
        } catch (ConnectionException) {
            $finalityReason = 'timeout_or_connection';
        } catch (Throwable) {
            $finalityReason = 'transport_error';
        }

        return [
            'provider_status' => 'ok',
            'receipt_status' => $receiptStatus,
            'events' => $events,
            'solidified' => $solidified,
            'provider_evidence' => array_filter([
                'source' => 'trongrid_v1',
                'finality_source' => 'tron_solidity_node',
                'finality_reason' => $finalityReason,
            ], static fn ($value) => $value !== null),
        ];
    }

    private function fetchEventPages(string $eventsUrl): array
    {
        $baseQuery = [
            'only_confirmed' => 'false',
            'limit' => 200,
            'event_name' => 'Transfer',
        ];
        $events = [];
        $eventIndexes = [];
        $seenFingerprints = [];
        $seenPageHashes = [];
        $fingerprint = null;
        $pageCount = 0;

        while (true) {
            $query = $baseQuery;
            if ($fingerprint !== null) {
                $query['fingerprint'] = $fingerprint;
            }

            try {
                $response = $this->request()->get($eventsUrl, $query);
            } catch (ConnectionException) {
                return ['events' => [], 'reason' => 'pagination_transport_error'];
            } catch (Throwable) {
                return ['events' => [], 'reason' => 'pagination_transport_error'];
            }
            if (! $response->successful()) {
                return ['events' => [], 'reason' => 'pagination_http_error'];
            }

            $payload = $response->json();
            if (! is_array($payload)) {
                return ['events' => [], 'reason' => 'malformed_response'];
            }
            if (($payload['success'] ?? null) !== true) {
                return ['events' => [], 'reason' => 'pagination_malformed_envelope'];
            }
            if (! array_key_exists('data', $payload)
                || ! is_array($payload['data'])) {
                return ['events' => [], 'reason' => 'malformed_response'];
            }

            $page = $payload['data'];
            $pageCount++;
            if (count($page) > 200 || count($events) + count($page) > 1000) {
                return ['events' => [], 'reason' => 'pagination_limit'];
            }

            foreach ($page as $event) {
                if (! is_array($event)) {
                    continue;
                }
                $eventIndex = $this->normalizeEventIndex($event['event_index'] ?? null);
                if ($eventIndex === null) {
                    continue;
                }
                if (isset($eventIndexes[$eventIndex])) {
                    return ['events' => [], 'reason' => 'duplicate_event_index'];
                }
                $eventIndexes[$eventIndex] = true;
            }

            try {
                $pageHash = hash('sha256', json_encode(
                    $this->canonicalize($page),
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
                ));
            } catch (Throwable) {
                return ['events' => [], 'reason' => 'pagination_malformed_page'];
            }
            if (isset($seenPageHashes[$pageHash])) {
                return ['events' => [], 'reason' => 'pagination_repeated_page'];
            }
            $seenPageHashes[$pageHash] = true;
            array_push($events, ...$page);

            $next = $this->nextFingerprint($payload, $eventsUrl, count($page));
            if ($next['reason'] !== null) {
                return ['events' => [], 'reason' => $next['reason']];
            }
            if ($next['fingerprint'] === null) {
                return ['events' => $events, 'reason' => null];
            }
            if ($pageCount >= 5 || count($events) >= 1000) {
                return ['events' => [], 'reason' => 'pagination_limit'];
            }
            if (isset($seenFingerprints[$next['fingerprint']])) {
                return ['events' => [], 'reason' => 'pagination_token_drift'];
            }
            $seenFingerprints[$next['fingerprint']] = true;
            $fingerprint = $next['fingerprint'];
        }
    }

    private function nextFingerprint(array $payload, string $eventsUrl, int $pageSize): array
    {
        $meta = $payload['meta'] ?? null;
        if ($meta !== null && ! is_array($meta)) {
            return ['fingerprint' => null, 'reason' => 'pagination_malformed_meta'];
        }
        $meta = is_array($meta) ? $meta : [];
        if (array_key_exists('has_more', $meta) && ! is_bool($meta['has_more'])) {
            return ['fingerprint' => null, 'reason' => 'pagination_malformed_meta'];
        }

        $explicitTerminal = ($meta['has_more'] ?? null) === false;
        $explicitMore = ($meta['has_more'] ?? null) === true;
        $metaFingerprint = null;
        if (array_key_exists('fingerprint', $meta) && $meta['fingerprint'] !== null && $meta['fingerprint'] !== '') {
            if (! $this->validFingerprint($meta['fingerprint'])) {
                return ['fingerprint' => null, 'reason' => 'pagination_missing_fingerprint'];
            }
            $metaFingerprint = $meta['fingerprint'];
        }

        $linkFingerprint = null;
        if (array_key_exists('links', $meta)) {
            if (! is_array($meta['links'])) {
                return ['fingerprint' => null, 'reason' => 'pagination_bad_next'];
            }
            if (array_key_exists('next', $meta['links'])) {
                $nextLink = $meta['links']['next'];
                if ($nextLink === null || $nextLink === '') {
                    $explicitTerminal = true;
                } else {
                    $link = $this->fingerprintFromNextLink($nextLink, $eventsUrl);
                    if ($link['reason'] !== null) {
                        return ['fingerprint' => null, 'reason' => $link['reason']];
                    }
                    $linkFingerprint = $link['fingerprint'];
                }
            }
        }

        if ($metaFingerprint !== null
            && $linkFingerprint !== null
            && $metaFingerprint !== $linkFingerprint) {
            return ['fingerprint' => null, 'reason' => 'pagination_token_drift'];
        }
        $fingerprint = $metaFingerprint ?? $linkFingerprint;
        if ($explicitTerminal && $fingerprint !== null) {
            return ['fingerprint' => null, 'reason' => 'pagination_token_drift'];
        }
        if ($fingerprint !== null) {
            return ['fingerprint' => $fingerprint, 'reason' => null];
        }
        if ($explicitMore) {
            return ['fingerprint' => null, 'reason' => 'pagination_missing_fingerprint'];
        }
        if ($pageSize < 200 || $explicitTerminal) {
            return ['fingerprint' => null, 'reason' => null];
        }

        return ['fingerprint' => null, 'reason' => 'pagination_missing_fingerprint'];
    }

    private function fingerprintFromNextLink(mixed $nextLink, string $eventsUrl): array
    {
        if (! is_string($nextLink) || trim($nextLink) === '') {
            return ['fingerprint' => null, 'reason' => 'pagination_bad_next'];
        }

        $expected = parse_url($eventsUrl);
        $actual = parse_url($nextLink);
        if (! is_array($expected)
            || ! is_array($actual)
            || strtolower((string) ($actual['scheme'] ?? '')) !== strtolower((string) ($expected['scheme'] ?? ''))
            || strtolower((string) ($actual['host'] ?? '')) !== strtolower((string) ($expected['host'] ?? ''))
            || $this->urlPort($actual) !== $this->urlPort($expected)
            || ($actual['path'] ?? null) !== ($expected['path'] ?? null)
            || isset($actual['user'])
            || isset($actual['pass'])
            || isset($actual['fragment'])) {
            return ['fingerprint' => null, 'reason' => 'pagination_bad_next'];
        }

        $query = $this->strictQuery((string) ($actual['query'] ?? ''));
        if ($query === null
            || count($query) !== 4
            || ($query['only_confirmed'] ?? null) !== 'false'
            || ($query['limit'] ?? null) !== '200'
            || ($query['event_name'] ?? null) !== 'Transfer'
            || ! $this->validFingerprint($query['fingerprint'] ?? null)) {
            return ['fingerprint' => null, 'reason' => 'pagination_bad_next'];
        }

        return ['fingerprint' => $query['fingerprint'], 'reason' => null];
    }

    private function strictQuery(string $queryString): ?array
    {
        if ($queryString === '') {
            return null;
        }

        $query = [];
        foreach (explode('&', $queryString) as $pair) {
            $parts = explode('=', $pair, 2);
            if (count($parts) !== 2) {
                return null;
            }
            $key = rawurldecode($parts[0]);
            if ($key === '' || array_key_exists($key, $query)) {
                return null;
            }
            $query[$key] = rawurldecode($parts[1]);
        }

        return $query;
    }

    private function validFingerprint(mixed $fingerprint): bool
    {
        return is_string($fingerprint)
            && $fingerprint !== ''
            && strlen($fingerprint) <= 2048
            && preg_match('/[\x00-\x1F\x7F]/', $fingerprint) !== 1;
    }

    private function urlPort(array $url): ?int
    {
        if (isset($url['port'])) {
            return (int) $url['port'];
        }

        return strtolower((string) ($url['scheme'] ?? '')) === 'https' ? 443 : 80;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item) => $this->canonicalize($item), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    private function request(): PendingRequest
    {
        $request = Http::timeout($this->timeoutSeconds)->acceptJson();
        if ($this->apiKey !== null && trim($this->apiKey) !== '') {
            $request = $request->withHeaders(['TRON-PRO-API-KEY' => $this->apiKey]);
        }

        return $request;
    }

    private function unavailable(
        string $reason,
        bool $transactionSeen = false,
    ): array {
        return [
            'provider_status' => 'unavailable',
            'provider_evidence' => array_filter([
                'source' => 'trongrid_v1',
                'reason' => $reason,
                'transaction_seen' => $transactionSeen ?: null,
            ]),
        ];
    }

    private function normalizeNonNegativeDecimal(mixed $value): ?string
    {
        if (is_int($value)) {
            return $value >= 0 ? (string) $value : null;
        }
        if (! is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1) {
            return null;
        }

        $normalized = ltrim($value, '0');

        return $normalized === '' ? '0' : $normalized;
    }

    private function isNonEmptyString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private function normalizeEventIndex(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (! is_string($value) || preg_match('/^(0|[1-9][0-9]*)$/D', $value) !== 1) {
            return null;
        }

        $maximum = (string) PHP_INT_MAX;
        if (strlen($value) > strlen($maximum)
            || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) > 0)) {
            return null;
        }

        return (int) $value;
    }

    private function httpReason(int $status): string
    {
        return $status === 429 ? 'rate_limited' : 'http_error';
    }
}
