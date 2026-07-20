<?php

namespace App\CentralLogics\MerchantDirectPayment;

use Brick\Math\BigInteger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

final class BscPaymentObservationProvider implements PaymentObservationProvider
{
    public const TRANSFER_TOPIC = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

    public function __construct(
        private readonly string $rpcUrl,
        private readonly int $timeoutSeconds = 10,
    ) {}

    public function observe(string $normalizedTxHash): array
    {
        $hash = '0x'.MerchantDirectPaymentHash::normalize($normalizedTxHash);

        try {
            $chainResponse = Http::timeout($this->timeoutSeconds)->acceptJson()->post($this->rpcUrl, [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'eth_chainId',
                'params' => [],
            ]);
        } catch (ConnectionException) {
            return $this->unavailable('timeout_or_connection');
        } catch (Throwable) {
            return $this->unavailable('transport_error');
        }
        if (! $chainResponse->successful()) {
            return $this->unavailable($this->httpReason($chainResponse->status()));
        }
        $chainPayload = $chainResponse->json();
        $chainId = is_array($chainPayload) && ! isset($chainPayload['error'])
            ? ($chainPayload['result'] ?? null)
            : null;
        $validChainId = is_string($chainId)
            && preg_match('/^0x(?:0|[1-9a-f][0-9a-f]*)$/D', $chainId) === 1;
        if (! $validChainId) {
            return $this->unavailable('server_misconfigured');
        }
        if ($chainId !== '0x38') {
            return $this->unavailable('wrong_chain');
        }
        $providerEvidence = ['source' => 'bsc_json_rpc', 'chain_id' => 56];

        try {
            $receiptResponse = Http::timeout($this->timeoutSeconds)->acceptJson()->post($this->rpcUrl, [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'eth_getTransactionReceipt',
                'params' => [$hash],
            ]);
        } catch (ConnectionException) {
            return $this->unavailable('timeout_or_connection');
        } catch (Throwable) {
            return $this->unavailable('transport_error');
        }

        if (! $receiptResponse->successful()) {
            return $this->unavailable($this->httpReason($receiptResponse->status()));
        }

        $receiptPayload = $receiptResponse->json();
        if (! is_array($receiptPayload) || isset($receiptPayload['error'])) {
            return $this->unavailable('rpc_error');
        }
        if (! array_key_exists('result', $receiptPayload)) {
            return $this->unavailable('malformed_response');
        }
        $receipt = $receiptPayload['result'] ?? null;
        if ($receipt === null) {
            return [
                'provider_status' => 'not_found',
                'provider_evidence' => $providerEvidence,
            ];
        }
        if (! is_array($receipt)) {
            return $this->unavailable('malformed_receipt');
        }
        try {
            $receiptHash = is_string($receipt['transactionHash'] ?? null)
                ? MerchantDirectPaymentHash::normalize($receipt['transactionHash'])
                : null;
        } catch (Throwable) {
            $receiptHash = null;
        }
        if ($receiptHash !== MerchantDirectPaymentHash::normalize($hash)) {
            return $this->unavailable('receipt_transaction_mismatch');
        }
        $receiptStatus = $receipt['status'] ?? null;
        if (! is_string($receiptStatus) || ! in_array($receiptStatus, ['0x0', '0x1'], true)) {
            return $this->unavailable('malformed_receipt_status');
        }
        $receiptBlockNumber = $receipt['blockNumber'] ?? null;
        $receiptBlockNumberDecimal = $this->positiveJsonRpcQuantityToDecimal($receiptBlockNumber);
        if ($receiptBlockNumberDecimal === null) {
            return $this->unavailable('malformed_block_quantity');
        }
        $logs = $receipt['logs'] ?? null;
        if (! is_array($logs)) {
            return $this->unavailable('malformed_response');
        }

        $events = [];
        $eventIndexes = [];
        foreach ($logs as $log) {
            if (! is_array($log)) {
                continue;
            }
            if ($this->positiveJsonRpcQuantityToDecimal($log['blockNumber'] ?? null) === null) {
                return $this->unavailable('malformed_block_quantity');
            }
            $rawEventIndex = $this->canonicalJsonRpcEventIndex($log['logIndex'] ?? null);
            if ($rawEventIndex !== null) {
                if (isset($eventIndexes[$rawEventIndex])) {
                    return $this->unavailable('duplicate_event_index');
                }
                $eventIndexes[$rawEventIndex] = true;
            }

            $event = $this->decodeTransferLog($log, $receipt, $hash);
            if ($event !== null) {
                $events[] = $event;
            }
        }

        $finalizedBlock = null;
        $finalityReason = null;
        $canonicalBlockMatches = false;
        try {
            $canonicalResponse = Http::timeout($this->timeoutSeconds)->acceptJson()->post($this->rpcUrl, [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'eth_getBlockByNumber',
                'params' => [$receiptBlockNumber, false],
            ]);
            if (! $canonicalResponse->successful()) {
                $finalityReason = $this->httpReason($canonicalResponse->status());
            } else {
                $canonicalPayload = $canonicalResponse->json();
                if (is_array($canonicalPayload) && array_key_exists('error', $canonicalPayload)) {
                    $finalityReason = 'rpc_error';
                } else {
                    $canonicalBlock = is_array($canonicalPayload) && is_array($canonicalPayload['result'] ?? null)
                        ? $canonicalPayload['result']
                        : null;
                    $canonicalHash = $canonicalBlock['hash'] ?? null;
                    $canonicalNumber = $canonicalBlock['number'] ?? null;
                    try {
                        $normalizedCanonicalHash = is_string($canonicalHash)
                            ? MerchantDirectPaymentHash::normalize($canonicalHash)
                            : null;
                        $normalizedReceiptBlockHash = is_string($receipt['blockHash'] ?? null)
                            ? MerchantDirectPaymentHash::normalize($receipt['blockHash'])
                            : null;
                    } catch (Throwable) {
                        $normalizedCanonicalHash = null;
                        $normalizedReceiptBlockHash = null;
                    }
                    $canonicalNumberDecimal = $this->positiveJsonRpcQuantityToDecimal($canonicalNumber);
                    if ($canonicalNumberDecimal === null) {
                        return $this->unavailable('malformed_block_quantity');
                    }
                    $canonicalNumberMatches = $canonicalNumberDecimal === $receiptBlockNumberDecimal;
                    if ($normalizedCanonicalHash === null
                        || $normalizedReceiptBlockHash === null
                        || ! $canonicalNumberMatches) {
                        $finalityReason = 'canonical_block_unavailable';
                    } elseif ($normalizedCanonicalHash !== $normalizedReceiptBlockHash) {
                        $finalityReason = 'canonical_block_mismatch';
                    } else {
                        $canonicalBlockMatches = true;
                    }
                }
            }
        } catch (ConnectionException) {
            $finalityReason = 'timeout_or_connection';
        } catch (Throwable) {
            $finalityReason = 'transport_error';
        }

        if ($canonicalBlockMatches) {
            try {
                $finalizedResponse = Http::timeout($this->timeoutSeconds)->acceptJson()->post($this->rpcUrl, [
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'method' => 'eth_getBlockByNumber',
                    'params' => ['finalized', false],
                ]);
                if (! $finalizedResponse->successful()) {
                    $finalityReason = $this->httpReason($finalizedResponse->status());
                } else {
                    $payload = $finalizedResponse->json();
                    if (is_array($payload) && array_key_exists('error', $payload)) {
                        $finalityReason = 'rpc_error';
                    } else {
                        $number = is_array($payload) ? ($payload['result']['number'] ?? null) : null;
                        $finalizedBlock = $this->positiveJsonRpcQuantityToDecimal($number);
                        if ($finalizedBlock === null) {
                            return $this->unavailable('malformed_block_quantity');
                        }
                        $finalityReason = null;
                    }
                }
            } catch (ConnectionException) {
                $finalityReason = 'timeout_or_connection';
            } catch (Throwable) {
                $finalityReason = 'transport_error';
            }
        }

        return [
            'provider_status' => 'ok',
            'receipt_status' => $receiptStatus === '0x1' ? 'success' : 'failed',
            'events' => $events,
            'finalized_block_number' => $finalizedBlock,
            'provider_evidence' => array_filter($providerEvidence + [
                'finality_source' => 'finalized_block_tag',
                'finality_reason' => $finalityReason,
            ], static fn ($value) => $value !== null),
        ];
    }

    private function decodeTransferLog(array $log, array $receipt, string $expectedHash): ?array
    {
        if (array_key_exists('removed', $log) && $log['removed'] !== false) {
            return null;
        }
        $topicsPayload = $log['topics'] ?? null;
        if (! is_array($topicsPayload)) {
            return null;
        }
        $topics = $topicsPayload;
        if (count($topics) !== 3
            || ! is_string($topics[0] ?? null)
            || ! is_string($topics[1] ?? null)
            || ! is_string($topics[2] ?? null)
            || preg_match('/^0x[0-9a-f]{64}$/D', $topics[0]) !== 1
            || preg_match('/^0x[0-9a-f]{64}$/D', $topics[1]) !== 1
            || preg_match('/^0x[0-9a-f]{64}$/D', $topics[2]) !== 1
            || $topics[0] !== self::TRANSFER_TOPIC) {
            return null;
        }

        try {
            $receiptBlockHash = is_string($receipt['blockHash'] ?? null)
                ? MerchantDirectPaymentHash::normalize($receipt['blockHash'])
                : null;
            $logBlockHash = is_string($log['blockHash'] ?? null)
                ? MerchantDirectPaymentHash::normalize($log['blockHash'])
                : null;
            $receiptBlockNumber = $receipt['blockNumber'] ?? null;
            $logBlockNumber = $log['blockNumber'] ?? null;
            $receiptBlockNumberDecimal = $this->positiveJsonRpcQuantityToDecimal($receiptBlockNumber);
            $logBlockNumberDecimal = $this->positiveJsonRpcQuantityToDecimal($logBlockNumber);
            if (! is_string($log['transactionHash'] ?? null)
                || MerchantDirectPaymentHash::normalize($log['transactionHash']) !== MerchantDirectPaymentHash::normalize($expectedHash)
                || $receiptBlockHash === null
                || $logBlockHash !== $receiptBlockHash
                || $receiptBlockNumberDecimal === null
                || $logBlockNumberDecimal !== $receiptBlockNumberDecimal) {
                return null;
            }

            $eventIndex = $this->canonicalJsonRpcEventIndex($log['logIndex'] ?? null);
            if ($eventIndex === null) {
                return null;
            }
            $amountData = $log['data'] ?? null;
            if (! is_string($amountData)
                || preg_match('/^0x[0-9a-fA-F]{64}$/D', $amountData) !== 1) {
                return null;
            }

            return [
                'event_index' => $eventIndex,
                'contract' => strtolower((string) ($log['address'] ?? '')),
                'from' => $this->topicAddress($topics[1]),
                'to' => $this->topicAddress($topics[2]),
                'amount_atomic' => $this->hexToDecimal($amountData),
                'block_number' => $receiptBlockNumberDecimal,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    private function topicAddress(string $topic): string
    {
        $hex = preg_replace('/^0x/i', '', trim($topic));
        if (! is_string($hex) || strlen($hex) < 40) {
            throw new \InvalidArgumentException('Malformed address topic.');
        }

        return '0x'.strtolower(substr($hex, -40));
    }

    private function hexToDecimal(string $hex): string
    {
        $clean = preg_replace('/^0x/i', '', trim($hex));
        if (! is_string($clean) || $clean === '' || ! preg_match('/^[0-9a-f]+$/i', $clean)) {
            throw new \InvalidArgumentException('Malformed hexadecimal integer.');
        }

        return (string) BigInteger::fromBase($clean, 16);
    }

    private function positiveJsonRpcQuantityToDecimal(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^0x[1-9a-f][0-9a-f]*$/D', $value) !== 1) {
            return null;
        }

        return $this->hexToDecimal($value);
    }

    private function canonicalJsonRpcEventIndex(mixed $value): ?int
    {
        if (! is_string($value) || preg_match('/^0x(?:0|[1-9a-f][0-9a-f]*)$/D', $value) !== 1) {
            return null;
        }

        $decimal = BigInteger::fromBase(substr($value, 2), 16);
        if ($decimal->isGreaterThan(BigInteger::of(PHP_INT_MAX))) {
            return null;
        }

        return $decimal->toInt();
    }

    private function unavailable(string $reason): array
    {
        return [
            'provider_status' => 'unavailable',
            'provider_evidence' => [
                'source' => 'bsc_json_rpc',
                'reason' => $reason,
            ],
        ];
    }

    private function httpReason(int $status): string
    {
        return $status === 429 ? 'rate_limited' : 'http_error';
    }
}
