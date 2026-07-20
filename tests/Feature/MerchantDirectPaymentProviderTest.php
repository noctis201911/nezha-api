<?php

namespace Tests\Feature;

use App\CentralLogics\MerchantDirectPayment\BscPaymentObservationProvider;
use App\CentralLogics\MerchantDirectPayment\DefaultMerchantDirectPaymentUsdtObservationGateway;
use App\CentralLogics\MerchantDirectPayment\MerchantDirectPaymentChannel;
use App\CentralLogics\MerchantDirectPayment\MerchantDirectPaymentLateCasePolicy as Policy;
use App\CentralLogics\MerchantDirectPayment\MerchantDirectPaymentVerifier;
use App\CentralLogics\MerchantDirectPayment\TronPaymentObservationProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MerchantDirectPaymentProviderTest extends TestCase
{
    private const HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const TRON_FROM = 'T9yD14Nj9j7xAB4dbGeiX9h8unkKHxuWwb';

    private const TRON_DESTINATION = 'TLa2f6VPqDgRE67v1736s7bJ8Ray5wYjU7';

    private const TRON_OTHER_DESTINATION = 'TKHuVq1oKVruCGLvqVexFs6dawKv6fQgFs';

    public function test_default_gateway_rejects_an_invalid_hash_without_contacting_a_provider(): void
    {
        Http::preventStrayRequests();

        $observation = (new DefaultMerchantDirectPaymentUsdtObservationGateway)->observe(
            Policy::CHANNEL_USDT_BEP20,
            ''
        );

        $this->assertSame('unavailable', $observation['provider_status']);
        $this->assertSame('invalid_transaction_hash', $observation['provider_evidence']['reason']);
        $this->assertArrayNotHasKey('attested_transaction_hash', $observation);
        Http::assertNothingSent();
    }

    public function test_bsc_provider_verifies_mainnet_chain_id_before_reading_the_receipt(): void
    {
        $methods = [];
        Http::fake(function (Request $request) use (&$methods) {
            $method = $request->data()['method'] ?? null;
            $methods[] = $method;

            return match ($method) {
                'eth_chainId' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => '0x38',
                ]),
                'eth_getTransactionReceipt' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => null,
                ]),
                default => Http::response([], 500),
            };
        });

        $observation = (new BscPaymentObservationProvider('https://bsc-rpc.example.test'))->observe(self::HASH);

        $this->assertSame(['eth_chainId', 'eth_getTransactionReceipt'], $methods);
        $this->assertSame('not_found', $observation['provider_status']);
        $this->assertSame(56, $observation['provider_evidence']['chain_id']);
    }

    public function test_bsc_provider_rejects_a_noncanonical_integer_mainnet_chain_id(): void
    {
        Http::fake(function (Request $request) {
            return ($request->data()['method'] ?? null) === 'eth_chainId'
                ? Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => 56])
                : Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => null]);
        });

        $observation = (new BscPaymentObservationProvider('https://bsc-rpc.example.test'))->observe(self::HASH);

        $this->assertSame('unavailable', $observation['provider_status']);
        $this->assertSame('server_misconfigured', $observation['provider_evidence']['reason']);
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request) => ($request->data()['method'] ?? null) === 'eth_getTransactionReceipt');
    }

    public function test_bsc_provider_rejects_noncanonical_string_mainnet_chain_ids(): void
    {
        foreach (['uppercase_prefix' => '0X38', 'leading_zero' => '0x038', 'empty' => ''] as $case => $chainId) {
            Http::fake(function (Request $request) use ($chainId) {
                return ($request->data()['method'] ?? null) === 'eth_chainId'
                    ? Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $chainId])
                    : Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => null]);
            });

            $observation = (new BscPaymentObservationProvider('https://bsc-rpc.example.test'))->observe(self::HASH);

            $this->assertSame('unavailable', $observation['provider_status'], $case);
            $this->assertSame('server_misconfigured', $observation['provider_evidence']['reason'], $case);
            Http::assertSentCount(1);
            Http::assertNotSent(fn (Request $request) => ($request->data()['method'] ?? null) === 'eth_getTransactionReceipt');
        }
    }

    public function test_bsc_provider_does_not_treat_a_missing_receipt_result_as_not_found(): void
    {
        Http::fakeSequence()
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x38'])
            ->push(['jsonrpc' => '2.0', 'id' => 1]);

        $observation = (new BscPaymentObservationProvider('https://bsc-rpc.example.test'))->observe(self::HASH);

        $this->assertSame('unavailable', $observation['provider_status']);
        $this->assertSame('malformed_response', $observation['provider_evidence']['reason']);
        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request) => ($request->data()['method'] ?? null) === 'eth_getBlockByNumber');
    }

    public function test_bsc_provider_rejects_a_valid_non_mainnet_chain_before_reading_receipt_evidence(): void
    {
        Http::fake(function (Request $request) {
            return ($request->data()['method'] ?? null) === 'eth_chainId'
                ? Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x1'])
                : Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['status' => '0x1']]);
        });

        $observation = (new BscPaymentObservationProvider('https://bsc-rpc.example.test'))->observe(self::HASH);

        $this->assertSame('unavailable', $observation['provider_status']);
        $this->assertSame('wrong_chain', $observation['provider_evidence']['reason']);
        $this->assertArrayNotHasKey('events', $observation);
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request) => ($request->data()['method'] ?? null) === 'eth_getTransactionReceipt');
    }

    public function test_bsc_provider_rejects_missing_or_malformed_chain_identity(): void
    {
        Http::fakeSequence()
            ->push(['jsonrpc' => '2.0', 'id' => 1])
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => '56']);

        $missing = (new BscPaymentObservationProvider('https://bsc-rpc.example.test'))->observe(self::HASH);
        $malformed = (new BscPaymentObservationProvider('https://bsc-rpc.example.test'))->observe(self::HASH);

        $this->assertSame('unavailable', $missing['provider_status']);
        $this->assertSame('server_misconfigured', $missing['provider_evidence']['reason']);
        $this->assertSame('unavailable', $malformed['provider_status']);
        $this->assertSame('server_misconfigured', $malformed['provider_evidence']['reason']);
        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request) => ($request->data()['method'] ?? null) === 'eth_getTransactionReceipt');
    }

    public function test_bsc_chain_identity_rate_limit_is_unavailable_without_receipt_evidence(): void
    {
        Http::fake(Http::response([], 429));

        $observation = (new BscPaymentObservationProvider('https://bsc-rpc.example.test'))->observe(self::HASH);

        $this->assertSame('unavailable', $observation['provider_status']);
        $this->assertSame('rate_limited', $observation['provider_evidence']['reason']);
        $this->assertArrayNotHasKey('events', $observation);
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request) => ($request->data()['method'] ?? null) === 'eth_getTransactionReceipt');
    }

    public function test_bsc_connection_exception_details_are_not_exposed_in_verification_evidence(): void
    {
        $secretUrl = 'https://rpc.example/SECRET_TOKEN?project=internal';
        Http::fake(fn () => throw new ConnectionException('Connection failed for '.$secretUrl));

        $observation = (new BscPaymentObservationProvider($secretUrl))->observe(self::HASH);
        $verification = MerchantDirectPaymentVerifier::evaluate(
            MerchantDirectPaymentChannel::USDT_BEP20,
            '0x'.str_repeat('1', 40),
            '1',
            $observation,
        );
        $evidence = json_encode($verification['provider_evidence'], JSON_THROW_ON_ERROR);

        $this->assertSame('unavailable', $verification['status']);
        $this->assertSame([
            'source' => 'bsc_json_rpc',
            'reason' => 'timeout_or_connection',
        ], $verification['provider_evidence']);
        $this->assertStringNotContainsString('SECRET_TOKEN', $evidence);
        $this->assertStringNotContainsString('rpc.example', $evidence);
        $this->assertStringNotContainsString('exception', $evidence);
    }

    public function test_bsc_provider_rejects_a_receipt_bound_to_another_transaction(): void
    {
        Http::fake(function (Request $request) {
            return match ($request->data()['method'] ?? null) {
                'eth_chainId' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x38']),
                'eth_getTransactionReceipt' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => [
                        'transactionHash' => '0x'.str_repeat('b', 64),
                        'status' => '0x1',
                        'blockNumber' => '0x64',
                        'logs' => [$this->bscLog(0, '0x'.str_repeat('1', 40), '0x1')],
                    ],
                ]),
                default => Http::response([], 500),
            };
        });

        $observation = (new BscPaymentObservationProvider('https://bsc-rpc.example.test'))->observe(self::HASH);

        $this->assertSame('unavailable', $observation['provider_status']);
        $this->assertSame('receipt_transaction_mismatch', $observation['provider_evidence']['reason']);
        $this->assertArrayNotHasKey('events', $observation);
        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request) => ($request->data()['method'] ?? null) === 'eth_getBlockByNumber');
    }

    public function test_bsc_provider_rejects_a_receipt_without_a_transaction_hash(): void
    {
        Http::fake(function (Request $request) {
            return match ($request->data()['method'] ?? null) {
                'eth_chainId' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x38']),
                'eth_getTransactionReceipt' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => [
                        'blockHash' => '0x'.str_repeat('c', 64),
                        'status' => '0x1',
                        'blockNumber' => '0x64',
                        'logs' => [$this->bscLog(0, '0x'.str_repeat('1', 40), '0x1')],
                    ],
                ]),
                default => Http::response([], 500),
            };
        });

        $observation = (new BscPaymentObservationProvider('https://bsc-rpc.example.test'))->observe(self::HASH);

        $this->assertSame('unavailable', $observation['provider_status']);
        $this->assertSame('receipt_transaction_mismatch', $observation['provider_evidence']['reason']);
        $this->assertArrayNotHasKey('events', $observation);
        Http::assertSentCount(2);
    }

    public function test_bsc_provider_rejects_a_malformed_receipt_transaction_hash(): void
    {
        foreach (['short_hash' => '0x1234', 'boolean' => true] as $case => $transactionHash) {
            Http::fake(function (Request $request) use ($transactionHash) {
                return match ($request->data()['method'] ?? null) {
                    'eth_chainId' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x38']),
                    'eth_getTransactionReceipt' => Http::response([
                        'jsonrpc' => '2.0',
                        'id' => 1,
                        'result' => [
                            'transactionHash' => $transactionHash,
                            'blockHash' => '0x'.str_repeat('c', 64),
                            'status' => '0x1',
                            'blockNumber' => '0x64',
                            'logs' => [$this->bscLog(0, '0x'.str_repeat('1', 40), '0x1')],
                        ],
                    ]),
                    default => Http::response([], 500),
                };
            });

            $observation = (new BscPaymentObservationProvider('https://bsc-rpc.example.test'))->observe(self::HASH);

            $this->assertSame('unavailable', $observation['provider_status'], $case);
            $this->assertSame('receipt_transaction_mismatch', $observation['provider_evidence']['reason'], $case);
            $this->assertArrayNotHasKey('events', $observation, $case);
            Http::assertSentCount(2);
        }
    }

    public function test_bsc_provider_rejects_scalar_receipt_logs(): void
    {
        Http::fake(function (Request $request) {
            return match ($request->data()['method'] ?? null) {
                'eth_chainId' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x38']),
                'eth_getTransactionReceipt' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => [
                        'transactionHash' => '0x'.self::HASH,
                        'blockHash' => '0x'.str_repeat('c', 64),
                        'status' => '0x1',
                        'blockNumber' => '0x64',
                        'logs' => 'not-an-array',
                    ],
                ]),
                default => Http::response([], 500),
            };
        });

        $observation = (new BscPaymentObservationProvider('https://bsc-rpc.example.test'))->observe(self::HASH);

        $this->assertSame('unavailable', $observation['provider_status']);
        $this->assertSame('malformed_response', $observation['provider_evidence']['reason']);
        $this->assertArrayNotHasKey('events', $observation);
        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request) => ($request->data()['method'] ?? null) === 'eth_getBlockByNumber');
    }

    public function test_bsc_missing_receipt_status_is_unavailable_through_the_verifier_pipeline(): void
    {
        Http::fake(function (Request $request) {
            return match ($request->data()['method'] ?? null) {
                'eth_chainId' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x38']),
                'eth_getTransactionReceipt' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => [
                        'transactionHash' => '0x'.self::HASH,
                        'blockHash' => '0x'.str_repeat('c', 64),
                        'blockNumber' => '0x64',
                        'logs' => [],
                    ],
                ]),
                default => Http::response([], 500),
            };
        });

        $observation = (new BscPaymentObservationProvider('https://bsc-rpc.example.test'))->observe(self::HASH);
        $verification = MerchantDirectPaymentVerifier::evaluate(
            MerchantDirectPaymentChannel::USDT_BEP20,
            '0x'.str_repeat('1', 40),
            '1',
            $observation,
        );

        $this->assertSame('unavailable', $observation['provider_status']);
        $this->assertSame('malformed_receipt_status', $observation['provider_evidence']['reason']);
        $this->assertSame('unavailable', $verification['status']);
        $this->assertSame('provider_unavailable', $verification['failure_code']);
        $this->assertArrayNotHasKey('events', $observation);
        Http::assertSentCount(2);
    }

    public function test_bsc_receipt_status_accepts_only_exact_json_rpc_success_or_failure_values(): void
    {
        $invalidStatuses = ['boolean' => true, 'integer' => 1, 'uppercase' => '0X1', 'unknown' => '0x2'];
        $statuses = [...array_values($invalidStatuses), '0x0'];
        $receiptCalls = 0;
        Http::fake(function (Request $request) use ($statuses, &$receiptCalls) {
            return match ($request->data()['method'] ?? null) {
                'eth_chainId' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x38']),
                'eth_getTransactionReceipt' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => [
                        'transactionHash' => '0x'.self::HASH,
                        'blockHash' => '0x'.str_repeat('c', 64),
                        'blockNumber' => '0x64',
                        'status' => $statuses[$receiptCalls++],
                        'logs' => [],
                    ],
                ]),
                default => Http::response([], 500),
            };
        });

        foreach ($invalidStatuses as $case => $status) {

            $observation = (new BscPaymentObservationProvider('https://bsc-rpc.example.test'))->observe(self::HASH);

            $this->assertSame('unavailable', $observation['provider_status'], $case);
            $this->assertSame('malformed_receipt_status', $observation['provider_evidence']['reason'], $case);
        }

        $failed = (new BscPaymentObservationProvider('https://bsc-rpc.example.test'))->observe(self::HASH);

        $this->assertSame('ok', $failed['provider_status']);
        $this->assertSame('failed', $failed['receipt_status']);
        $this->assertSame(count($statuses), $receiptCalls);
        Http::assertSentCount((count($invalidStatuses) * 2) + 3);
    }

    public function test_bsc_provider_ignores_a_log_with_scalar_topics(): void
    {
        $log = array_replace(
            $this->bscLog(1, '0x'.str_repeat('1', 40), '0x1'),
            ['topics' => 'not-an-array'],
        );
        Http::fake(function (Request $request) use ($log) {
            return match ($request->data()['method'] ?? null) {
                'eth_chainId' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x38']),
                'eth_getTransactionReceipt' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => [
                        'transactionHash' => '0x'.self::HASH,
                        'blockHash' => '0x'.str_repeat('c', 64),
                        'status' => '0x1',
                        'blockNumber' => '0x64',
                        'logs' => [$log],
                    ],
                ]),
                'eth_getBlockByNumber' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => ['number' => '0x64'],
                ]),
                default => Http::response([], 500),
            };
        });

        $observation = (new BscPaymentObservationProvider('https://bsc-rpc.example.test'))->observe(self::HASH);
        $verification = MerchantDirectPaymentVerifier::evaluate(
            MerchantDirectPaymentChannel::USDT_BEP20,
            '0x'.str_repeat('1', 40),
            '1',
            $observation,
        );

        $this->assertSame('ok', $observation['provider_status']);
        $this->assertSame([], $observation['events']);
        $this->assertSame('mismatch', $verification['status']);
    }

    public function test_bsc_provider_does_not_reindex_mixed_type_topics(): void
    {
        $log = $this->bscLog(1, '0x'.str_repeat('1', 40), '0x1');
        $topics = $log['topics'];
        $log['topics'] = [$topics[0], 123, $topics[1], $topics[2]];

        Http::fake(function (Request $request) use ($log) {
            $method = $request->data()['method'] ?? null;
            $params = $request->data()['params'] ?? null;

            return match (true) {
                $method === 'eth_chainId' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x38']),
                $method === 'eth_getTransactionReceipt' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => [
                        'transactionHash' => '0x'.self::HASH,
                        'blockHash' => '0x'.str_repeat('c', 64),
                        'status' => '0x1',
                        'blockNumber' => '0x64',
                        'logs' => [$log],
                    ],
                ]),
                $method === 'eth_getBlockByNumber' && $params === ['0x64', false] => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => [
                        'number' => '0x64',
                        'hash' => '0x'.str_repeat('c', 64),
                    ],
                ]),
                $method === 'eth_getBlockByNumber' && $params === ['finalized', false] => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => ['number' => '0x64'],
                ]),
                default => Http::response([], 500),
            };
        });

        $observation = (new BscPaymentObservationProvider('https://bsc-rpc.example.test'))->observe(self::HASH);
        $verification = MerchantDirectPaymentVerifier::evaluate(
            MerchantDirectPaymentChannel::USDT_BEP20,
            '0x'.str_repeat('1', 40),
            '1',
            $observation,
        );

        $this->assertSame([], $observation['events']);
        $this->assertNotSame('confirmed', $verification['status']);
    }

    public function test_bsc_provider_does_not_fabricate_an_index_for_a_log_without_log_index(): void
    {
        $missingIndex = $this->bscLog(0, '0x'.str_repeat('1', 40), '0x1');
        unset($missingIndex['logIndex']);

        Http::fake(function (Request $request) use ($missingIndex) {
            return match ($request->data()['method'] ?? null) {
                'eth_chainId' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x38']),
                'eth_getTransactionReceipt' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => [
                        'transactionHash' => '0x'.self::HASH,
                        'blockHash' => '0x'.str_repeat('c', 64),
                        'status' => '0x1',
                        'blockNumber' => '0x64',
                        'logs' => [$missingIndex],
                    ],
                ]),
                'eth_getBlockByNumber' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => ['number' => '0x64', 'hash' => '0x'.str_repeat('c', 64)],
                ]),
                default => Http::response([], 500),
            };
        });

        $observation = (new BscPaymentObservationProvider('https://bsc-rpc.example.test'))->observe(self::HASH);

        $this->assertSame('ok', $observation['provider_status']);
        $this->assertSame([], $observation['events']);
    }

    public function test_bsc_provider_ignores_noncanonical_or_out_of_range_log_indices(): void
    {
        $invalidIndices = [
            'integer' => 1,
            'leading_zero' => '0x00',
            'uppercase_prefix' => '0X0',
            'non_hex' => '0xg',
            'negative' => '-0x1',
            'out_of_signed_integer_range' => '0x8000000000000000',
        ];

        foreach ($invalidIndices as $case => $invalidIndex) {
            $log = $this->bscLog(0, '0x'.str_repeat('1', 40), '0x1');
            $log['logIndex'] = $invalidIndex;

            Http::fake(function (Request $request) use ($log) {
                return match ($request->data()['method'] ?? null) {
                    'eth_chainId' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x38']),
                    'eth_getTransactionReceipt' => Http::response([
                        'jsonrpc' => '2.0',
                        'id' => 1,
                        'result' => [
                            'transactionHash' => '0x'.self::HASH,
                            'blockHash' => '0x'.str_repeat('c', 64),
                            'status' => '0x1',
                            'blockNumber' => '0x64',
                            'logs' => [$log],
                        ],
                    ]),
                    'eth_getBlockByNumber' => Http::response([
                        'jsonrpc' => '2.0',
                        'id' => 1,
                        'result' => ['number' => '0x64', 'hash' => '0x'.str_repeat('c', 64)],
                    ]),
                    default => Http::response([], 500),
                };
            });

            $observation = (new BscPaymentObservationProvider('https://bsc-rpc.example.test'))->observe(self::HASH);

            $this->assertSame('ok', $observation['provider_status'], $case);
            $this->assertSame([], $observation['events'], $case);
        }
    }

    public function test_bsc_provider_ignores_a_transfer_log_with_extra_topics(): void
    {
        $log = $this->bscLog(1, '0x'.str_repeat('1', 40), '0x1');
        $log['topics'][] = '0x'.str_repeat('f', 64);

        Http::fake(function (Request $request) use ($log) {
            return match ($request->data()['method'] ?? null) {
                'eth_chainId' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x38']),
                'eth_getTransactionReceipt' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => [
                        'transactionHash' => '0x'.self::HASH,
                        'blockHash' => '0x'.str_repeat('c', 64),
                        'status' => '0x1',
                        'blockNumber' => '0x64',
                        'logs' => [$log],
                    ],
                ]),
                'eth_getBlockByNumber' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => [
                        'number' => '0x64',
                        'hash' => '0x'.str_repeat('c', 64),
                    ],
                ]),
                default => Http::response([], 500),
            };
        });

        $observation = (new BscPaymentObservationProvider('https://bsc-rpc.example.test'))->observe(self::HASH);
        $verification = MerchantDirectPaymentVerifier::evaluate(
            MerchantDirectPaymentChannel::USDT_BEP20,
            '0x'.str_repeat('1', 40),
            '1',
            $observation,
        );

        $this->assertSame([], $observation['events']);
        $this->assertNotSame('confirmed', $verification['status']);
    }

    public function test_bsc_provider_ignores_a_transfer_log_with_malformed_topic_words(): void
    {
        $log = $this->bscLog(1, '0x'.str_repeat('1', 40), '0x1');
        $log['topics'][1] = '0x'.str_repeat('a', 66);

        Http::fake(function (Request $request) use ($log) {
            return match ($request->data()['method'] ?? null) {
                'eth_chainId' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x38']),
                'eth_getTransactionReceipt' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => [
                        'transactionHash' => '0x'.self::HASH,
                        'blockHash' => '0x'.str_repeat('c', 64),
                        'status' => '0x1',
                        'blockNumber' => '0x64',
                        'logs' => [$log],
                    ],
                ]),
                'eth_getBlockByNumber' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => [
                        'number' => '0x64',
                        'hash' => '0x'.str_repeat('c', 64),
                    ],
                ]),
                default => Http::response([], 500),
            };
        });

        $observation = (new BscPaymentObservationProvider('https://bsc-rpc.example.test'))->observe(self::HASH);
        $verification = MerchantDirectPaymentVerifier::evaluate(
            MerchantDirectPaymentChannel::USDT_BEP20,
            '0x'.str_repeat('1', 40),
            '1',
            $observation,
        );

        $this->assertSame([], $observation['events']);
        $this->assertNotSame('confirmed', $verification['status']);
    }

    public function test_bsc_provider_uses_only_non_removed_logs_bound_to_the_receipt(): void
    {
        $valid = $this->bscLog(1, '0x'.str_repeat('1', 40), '0x1');
        $wrongTransaction = array_replace($valid, [
            'logIndex' => '0x2',
            'transactionHash' => '0x'.str_repeat('b', 64),
        ]);
        $wrongBlock = array_replace($valid, [
            'logIndex' => '0x3',
            'blockHash' => '0x'.str_repeat('d', 64),
        ]);
        $removed = array_replace($valid, ['logIndex' => '0x4', 'removed' => true]);

        Http::fake(function (Request $request) use ($valid, $wrongTransaction, $wrongBlock, $removed) {
            return match ($request->data()['method'] ?? null) {
                'eth_chainId' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x38']),
                'eth_getTransactionReceipt' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => [
                        'transactionHash' => '0x'.self::HASH,
                        'blockHash' => '0x'.str_repeat('c', 64),
                        'status' => '0x1',
                        'blockNumber' => '0x64',
                        'logs' => [$valid, $wrongTransaction, $wrongBlock, $removed],
                    ],
                ]),
                'eth_getBlockByNumber' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => ['number' => '0x64'],
                ]),
                default => Http::response([], 500),
            };
        });

        $observation = (new BscPaymentObservationProvider('https://bsc-rpc.example.test'))->observe(self::HASH);

        $this->assertSame('ok', $observation['provider_status']);
        $this->assertCount(1, $observation['events']);
        $this->assertSame(1, $observation['events'][0]['event_index']);
    }

    public function test_bsc_provider_fails_closed_when_transaction_bound_logs_repeat_an_event_index(): void
    {
        $first = $this->bscLog(4, '0x'.str_repeat('1', 40), '0x1');
        $second = $this->bscLog(4, '0x'.str_repeat('2', 40), '0x2');

        Http::fake(function (Request $request) use ($first, $second) {
            return match ($request->data()['method'] ?? null) {
                'eth_chainId' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x38']),
                'eth_getTransactionReceipt' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => [
                        'transactionHash' => '0x'.self::HASH,
                        'blockHash' => '0x'.str_repeat('c', 64),
                        'status' => '0x1',
                        'blockNumber' => '0x64',
                        'logs' => [$first, $second],
                    ],
                ]),
                default => Http::response([], 500),
            };
        });

        $observation = (new BscPaymentObservationProvider('https://bsc-rpc.example.test'))->observe(self::HASH);

        $this->assertSame('unavailable', $observation['provider_status']);
        $this->assertSame('duplicate_event_index', $observation['provider_evidence']['reason']);
        $this->assertArrayNotHasKey('events', $observation);
        Http::assertSentCount(2);
    }

    public function test_bsc_duplicate_index_ledger_precedes_transaction_and_event_semantics(): void
    {
        $valid = $this->bscLog(4, '0x'.str_repeat('1', 40), '0x1');
        $invalidLogs = [];
        $invalidLogs['wrong_transaction'] = array_replace($valid, [
            'transactionHash' => '0x'.str_repeat('b', 64),
        ]);
        $invalidLogs['wrong_contract'] = array_replace($valid, [
            'address' => '0x'.str_repeat('9', 40),
        ]);
        $invalidLogs['malformed'] = array_replace($valid, ['data' => true]);
        $irrelevant = $valid;
        $irrelevant['topics'][0] = '0x'.str_repeat('0', 64);
        $invalidLogs['irrelevant'] = $irrelevant;

        $receipts = [];
        foreach ($invalidLogs as $invalid) {
            $receipts[] = [$valid, $invalid];
            $receipts[] = [$invalid, $valid];
        }
        $receiptCalls = 0;

        Http::fake(function (Request $request) use ($receipts, &$receiptCalls) {
            $method = $request->data()['method'] ?? null;
            if ($method === 'eth_chainId') {
                return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x38']);
            }
            if ($method === 'eth_getTransactionReceipt') {
                return Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => [
                        'transactionHash' => '0x'.self::HASH,
                        'blockHash' => '0x'.str_repeat('c', 64),
                        'status' => '0x1',
                        'blockNumber' => '0x64',
                        'logs' => $receipts[$receiptCalls++],
                    ],
                ]);
            }

            return Http::response([], 500);
        });

        $provider = new BscPaymentObservationProvider('https://bsc-rpc.example.test');
        foreach (array_keys($invalidLogs) as $case) {
            foreach (['valid_first', 'invalid_first'] as $order) {
                $observation = $provider->observe(self::HASH);

                $this->assertSame('unavailable', $observation['provider_status'], $case.' '.$order);
                $this->assertSame(
                    'duplicate_event_index',
                    $observation['provider_evidence']['reason'],
                    $case.' '.$order,
                );
                $this->assertArrayNotHasKey('events', $observation, $case.' '.$order);
            }
        }
        $this->assertSame(count($receipts), $receiptCalls);
        Http::assertSentCount(count($receipts) * 2);
    }

    public function test_bsc_provider_ignores_logs_with_non_boolean_removed_values(): void
    {
        $removedString = array_replace(
            $this->bscLog(1, '0x'.str_repeat('1', 40), '0x1'),
            ['removed' => 'true'],
        );
        $removedInteger = array_replace(
            $this->bscLog(2, '0x'.str_repeat('1', 40), '0x1'),
            ['removed' => 1],
        );

        Http::fake(function (Request $request) use ($removedString, $removedInteger) {
            return match ($request->data()['method'] ?? null) {
                'eth_chainId' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x38']),
                'eth_getTransactionReceipt' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => [
                        'transactionHash' => '0x'.self::HASH,
                        'blockHash' => '0x'.str_repeat('c', 64),
                        'status' => '0x1',
                        'blockNumber' => '0x64',
                        'logs' => [$removedString, $removedInteger],
                    ],
                ]),
                'eth_getBlockByNumber' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => [
                        'number' => '0x64',
                        'hash' => '0x'.str_repeat('c', 64),
                    ],
                ]),
                default => Http::response([], 500),
            };
        });

        $observation = (new BscPaymentObservationProvider('https://bsc-rpc.example.test'))->observe(self::HASH);
        $verification = MerchantDirectPaymentVerifier::evaluate(
            MerchantDirectPaymentChannel::USDT_BEP20,
            '0x'.str_repeat('1', 40),
            '1',
            $observation,
        );

        $this->assertSame([], $observation['events']);
        $this->assertNotSame('confirmed', $verification['status']);
    }

    public function test_bsc_provider_does_not_emit_an_event_for_malformed_block_hash_evidence(): void
    {
        $log = array_replace(
            $this->bscLog(1, '0x'.str_repeat('1', 40), '0x1'),
            ['blockHash' => 'not-a-block-hash'],
        );

        Http::fake(function (Request $request) use ($log) {
            return match ($request->data()['method'] ?? null) {
                'eth_chainId' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x38']),
                'eth_getTransactionReceipt' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => [
                        'transactionHash' => '0x'.self::HASH,
                        'blockHash' => 'not-a-block-hash',
                        'status' => '0x1',
                        'blockNumber' => '0x64',
                        'logs' => [$log],
                    ],
                ]),
                'eth_getBlockByNumber' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => ['number' => '0x64'],
                ]),
                default => Http::response([], 500),
            };
        });

        $observation = (new BscPaymentObservationProvider('https://bsc-rpc.example.test'))->observe(self::HASH);
        $verification = MerchantDirectPaymentVerifier::evaluate(
            MerchantDirectPaymentChannel::USDT_BEP20,
            '0x'.str_repeat('1', 40),
            '1',
            $observation,
        );

        $this->assertSame('ok', $observation['provider_status']);
        $this->assertSame([], $observation['events']);
        $this->assertSame('mismatch', $verification['status']);
    }

    public function test_bsc_provider_ignores_a_log_with_a_mismatched_canonical_block_height(): void
    {
        $mismatched = array_replace(
            $this->bscLog(2, '0x'.str_repeat('2', 40), '0x1'),
            ['blockNumber' => '0x63'],
        );

        Http::fake(function (Request $request) use ($mismatched) {
            return match ($request->data()['method'] ?? null) {
                'eth_chainId' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x38']),
                'eth_getTransactionReceipt' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => [
                        'transactionHash' => '0x'.self::HASH,
                        'blockHash' => '0x'.str_repeat('c', 64),
                        'status' => '0x1',
                        'blockNumber' => '0x64',
                        'logs' => [$mismatched],
                    ],
                ]),
                'eth_getBlockByNumber' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => ['number' => '0x64'],
                ]),
                default => Http::response([], 500),
            };
        });

        $observation = (new BscPaymentObservationProvider('https://bsc-rpc.example.test'))->observe(self::HASH);
        $verification = MerchantDirectPaymentVerifier::evaluate(
            MerchantDirectPaymentChannel::USDT_BEP20,
            '0x'.str_repeat('1', 40),
            '1',
            $observation,
        );

        $this->assertSame([], $observation['events']);
        $this->assertSame('mismatch', $verification['status']);
    }

    public function test_bsc_provider_requires_a_positive_canonical_receipt_block_height(): void
    {
        $receipts = [];
        foreach ([
            'missing' => [null, '0x64'],
            'zero' => ['0x0', '0x0'],
            'malformed' => ['not-a-block', 'not-a-block'],
        ] as $case => [$receiptBlockNumber, $logBlockNumber]) {
            $log = array_replace(
                $this->bscLog(1, '0x'.str_repeat('1', 40), '0x1'),
                ['blockNumber' => $logBlockNumber],
            );
            $receipt = [
                'transactionHash' => '0x'.self::HASH,
                'blockHash' => '0x'.str_repeat('c', 64),
                'status' => '0x1',
                'logs' => [$log],
            ];
            if ($receiptBlockNumber !== null) {
                $receipt['blockNumber'] = $receiptBlockNumber;
            }
            $receipts[$case] = $receipt;
        }

        $caseIndex = 0;
        $cases = array_keys($receipts);
        $calls = [];
        Http::fake(function (Request $request) use (&$calls, &$caseIndex, $cases, $receipts) {
            $method = $request->data()['method'] ?? null;
            $calls[] = [$method, $request->data()['params'] ?? null];
            if ($method === 'eth_chainId') {
                return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x38']);
            }
            if ($method === 'eth_getTransactionReceipt') {
                $case = $cases[$caseIndex++];

                return Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => $receipts[$case],
                ]);
            }

            return Http::response([], 500);
        });

        foreach ($cases as $case) {
            $observation = (new BscPaymentObservationProvider('https://bsc-rpc.example.test'))->observe(self::HASH);
            $verification = MerchantDirectPaymentVerifier::evaluate(
                MerchantDirectPaymentChannel::USDT_BEP20,
                '0x'.str_repeat('1', 40),
                '1',
                $observation,
            );

            $this->assertSame('unavailable', $observation['provider_status'], $case);
            $this->assertSame('malformed_block_quantity', $observation['provider_evidence']['reason'], $case);
            $this->assertSame('unavailable', $verification['status'], $case);
            $this->assertArrayNotHasKey('events', $observation, $case);
        }

        $expectedCalls = [];
        foreach ($cases as $_case) {
            $expectedCalls[] = ['eth_chainId', []];
            $expectedCalls[] = ['eth_getTransactionReceipt', ['0x'.self::HASH]];
        }
        $this->assertSame(count($cases), $caseIndex);
        $this->assertSame($expectedCalls, $calls);
        Http::assertSentCount(count($expectedCalls));
    }

    public function test_bsc_non_canonical_block_quantities_are_unavailable_and_cannot_confirm(): void
    {
        foreach ($this->invalidBscBlockQuantities() as $case => $quantity) {
            $observation = $this->observeBscBlockQuantities($quantity, '0x64', '0x64', '0x64');
            $verification = MerchantDirectPaymentVerifier::evaluate(
                MerchantDirectPaymentChannel::USDT_BEP20,
                '0x'.str_repeat('1', 40),
                '1',
                $observation,
            );

            $this->assertSame('unavailable', $observation['provider_status'], $case);
            $this->assertSame('malformed_block_quantity', $observation['provider_evidence']['reason'], $case);
            $this->assertSame('unavailable', $verification['status'], $case);
            $this->assertArrayNotHasKey('events', $observation, $case);
            Http::assertSentCount(2);
            Http::assertNotSent(
                fn (Request $request) => ($request->data()['method'] ?? null) === 'eth_getBlockByNumber',
            );
        }
    }

    public function test_bsc_non_canonical_log_block_quantity_is_unavailable(): void
    {
        foreach ($this->invalidBscBlockQuantities() as $case => $quantity) {
            $observation = $this->observeBscBlockQuantities('0x64', $quantity, '0x64', '0x64');

            $this->assertSame('unavailable', $observation['provider_status'], $case);
            $this->assertSame('malformed_block_quantity', $observation['provider_evidence']['reason'], $case);
            $this->assertArrayNotHasKey('events', $observation, $case);
            Http::assertSentCount(2);
            Http::assertNotSent(
                fn (Request $request) => ($request->data()['method'] ?? null) === 'eth_getBlockByNumber',
            );
        }
    }

    public function test_bsc_non_canonical_canonical_block_quantity_is_unavailable(): void
    {
        foreach ($this->invalidBscBlockQuantities() as $case => $quantity) {
            $observation = $this->observeBscBlockQuantities('0x64', '0x64', $quantity, '0x64');

            $this->assertSame('unavailable', $observation['provider_status'], $case);
            $this->assertSame('malformed_block_quantity', $observation['provider_evidence']['reason'], $case);
            $this->assertArrayNotHasKey('events', $observation, $case);
            Http::assertSentCount(3);
            Http::assertNotSent(
                fn (Request $request) => ($request->data()['params'] ?? null) === ['finalized', false],
            );
        }
    }

    public function test_bsc_non_canonical_finalized_block_quantity_is_unavailable(): void
    {
        foreach ($this->invalidBscBlockQuantities() as $case => $quantity) {
            $observation = $this->observeBscBlockQuantities('0x64', '0x64', '0x64', $quantity);

            $this->assertSame('unavailable', $observation['provider_status'], $case);
            $this->assertSame('malformed_block_quantity', $observation['provider_evidence']['reason'], $case);
            $this->assertArrayNotHasKey('events', $observation, $case);
            Http::assertSentCount(4);
        }
    }

    public function test_bsc_provider_decodes_transfer_logs_and_uses_the_finalized_block_tag(): void
    {
        Http::fake(function (Request $request) {
            $method = $request->data()['method'] ?? null;
            $params = $request->data()['params'] ?? null;

            return match (true) {
                $method === 'eth_chainId' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => '0x38',
                ]),
                $method === 'eth_getTransactionReceipt' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => [
                        'transactionHash' => '0x'.self::HASH,
                        'blockHash' => '0x'.str_repeat('c', 64),
                        'status' => '0x1',
                        'blockNumber' => '0x64',
                        'logs' => [
                            $this->bscLog(2, '0x'.str_repeat('8', 40), '0x22b1c8c1227a0000'),
                            $this->bscLog(7, '0x'.str_repeat('1', 40), '0x22b1c8c1227a0000'),
                        ],
                    ],
                ]),
                $method === 'eth_getBlockByNumber' && $params === ['0x64', false] => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => [
                        'number' => '0x64',
                        'hash' => '0x'.str_repeat('c', 64),
                    ],
                ]),
                $method === 'eth_getBlockByNumber' && $params === ['finalized', false] => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => ['number' => '0x64'],
                ]),
                default => Http::response([], 500),
            };
        });

        $observation = (new BscPaymentObservationProvider('https://bsc-rpc.example.test'))->observe(self::HASH);

        $this->assertSame('ok', $observation['provider_status']);
        $this->assertSame('success', $observation['receipt_status']);
        $this->assertSame('100', $observation['finalized_block_number']);
        $this->assertCount(2, $observation['events']);
        $this->assertSame(7, $observation['events'][1]['event_index']);
        $this->assertSame('2500000000000000000', $observation['events'][1]['amount_atomic']);
        $this->assertSame('0x'.str_repeat('1', 40), $observation['events'][1]['to']);

        Http::assertSentCount(4);
        Http::assertSent(fn (Request $request) => ($request->data()['method'] ?? null) === 'eth_getBlockByNumber'
            && ($request->data()['params'] ?? null) === ['finalized', false]);
    }

    public function test_bsc_transfer_data_must_be_an_exact_32_byte_abi_word(): void
    {
        $observation = $this->observeBscTransferData([
            'boolean' => true,
            'integer' => 1,
            'null' => null,
            'short' => '0x01',
            'long' => '0x'.str_repeat('0', 65),
            'missing_prefix' => str_repeat('0', 63).'1',
            'odd_length' => '0x'.str_repeat('0', 62).'1',
            'non_hex' => '0x'.str_repeat('0', 63).'g',
            'valid_uppercase_hex' => '0x'.str_repeat('0', 62).'AF',
        ]);

        $this->assertCount(1, $observation['events']);
        $this->assertSame(8, $observation['events'][0]['event_index']);
        $this->assertSame('175', $observation['events'][0]['amount_atomic']);
    }

    public function test_bsc_receipt_rate_limit_is_unavailable(): void
    {
        Http::fakeSequence()
            ->push([
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => '0x38',
            ])
            ->push([], 429);

        $unavailable = (new BscPaymentObservationProvider('https://bsc-rpc.example.test'))->observe(self::HASH);
        $this->assertSame('unavailable', $unavailable['provider_status']);
        $this->assertSame('rate_limited', $unavailable['provider_evidence']['reason']);
    }

    public function test_bsc_finality_rate_limit_preserves_the_receipt_as_observed(): void
    {
        Http::fakeSequence()
            ->push([
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => '0x38',
            ])
            ->push([
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => [
                    'transactionHash' => '0x'.self::HASH,
                    'blockHash' => '0x'.str_repeat('c', 64),
                    'status' => '0x1',
                    'blockNumber' => '0x64',
                    'logs' => [$this->bscLog(0, '0x'.str_repeat('1', 40), '0x1')],
                ],
            ])
            ->push([
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => [
                    'number' => '0x64',
                    'hash' => '0x'.str_repeat('c', 64),
                ],
            ])
            ->push([], 429);

        $observed = (new BscPaymentObservationProvider('https://bsc-rpc.example.test'))->observe(self::HASH);
        $this->assertSame('ok', $observed['provider_status']);
        $this->assertNull($observed['finalized_block_number']);
        $this->assertSame('rate_limited', $observed['provider_evidence']['finality_reason']);
        Http::assertSentCount(4);
    }

    public function test_bsc_canonical_block_hash_mismatch_cannot_confirm_the_receipt(): void
    {
        Http::fake(function (Request $request) {
            $method = $request->data()['method'] ?? null;
            $params = $request->data()['params'] ?? null;

            return match (true) {
                $method === 'eth_chainId' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => '0x38',
                ]),
                $method === 'eth_getTransactionReceipt' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => [
                        'transactionHash' => '0x'.self::HASH,
                        'blockHash' => '0x'.str_repeat('c', 64),
                        'status' => '0x1',
                        'blockNumber' => '0x64',
                        'logs' => [$this->bscLog(0, '0x'.str_repeat('1', 40), '0x1')],
                    ],
                ]),
                $method === 'eth_getBlockByNumber' && $params === ['0x64', false] => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => [
                        'number' => '0x64',
                        'hash' => '0x'.str_repeat('d', 64),
                    ],
                ]),
                $method === 'eth_getBlockByNumber' && $params === ['finalized', false] => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => ['number' => '0x64'],
                ]),
                default => Http::response([], 500),
            };
        });

        $observation = (new BscPaymentObservationProvider('https://bsc-rpc.example.test'))->observe(self::HASH);
        $verification = MerchantDirectPaymentVerifier::evaluate(
            MerchantDirectPaymentChannel::USDT_BEP20,
            '0x'.str_repeat('1', 40),
            '1',
            $observation,
        );

        $this->assertSame('ok', $observation['provider_status']);
        $this->assertNull($observation['finalized_block_number']);
        $this->assertSame('canonical_block_mismatch', $observation['provider_evidence']['finality_reason']);
        $this->assertSame('observed', $verification['status']);
        Http::assertSent(fn (Request $request) => ($request->data()['params'] ?? null) === ['0x64', false]);
        Http::assertNotSent(fn (Request $request) => ($request->data()['params'] ?? null) === ['finalized', false]);
    }

    public function test_bsc_malformed_canonical_block_cannot_confirm_the_receipt(): void
    {
        Http::fake(function (Request $request) {
            $method = $request->data()['method'] ?? null;
            $params = $request->data()['params'] ?? null;

            return match (true) {
                $method === 'eth_chainId' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => '0x38',
                ]),
                $method === 'eth_getTransactionReceipt' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => [
                        'transactionHash' => '0x'.self::HASH,
                        'blockHash' => '0x'.str_repeat('c', 64),
                        'status' => '0x1',
                        'blockNumber' => '0x64',
                        'logs' => [$this->bscLog(0, '0x'.str_repeat('1', 40), '0x1')],
                    ],
                ]),
                $method === 'eth_getBlockByNumber' && $params === ['0x64', false] => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => ['hash' => '0x'.str_repeat('c', 64)],
                ]),
                $method === 'eth_getBlockByNumber' && $params === ['finalized', false] => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => ['number' => '0x64'],
                ]),
                default => Http::response([], 500),
            };
        });

        $observation = (new BscPaymentObservationProvider('https://bsc-rpc.example.test'))->observe(self::HASH);
        $verification = MerchantDirectPaymentVerifier::evaluate(
            MerchantDirectPaymentChannel::USDT_BEP20,
            '0x'.str_repeat('1', 40),
            '1',
            $observation,
        );

        $this->assertSame('unavailable', $observation['provider_status']);
        $this->assertSame('malformed_block_quantity', $observation['provider_evidence']['reason']);
        $this->assertSame('unavailable', $verification['status']);
        $this->assertArrayNotHasKey('events', $observation);
        Http::assertNotSent(fn (Request $request) => ($request->data()['params'] ?? null) === ['finalized', false]);
    }

    public function test_bsc_canonical_block_error_cannot_be_overridden_by_a_result(): void
    {
        Http::fake(function (Request $request) {
            $method = $request->data()['method'] ?? null;
            $params = $request->data()['params'] ?? null;

            return match (true) {
                $method === 'eth_chainId' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => '0x38',
                ]),
                $method === 'eth_getTransactionReceipt' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => [
                        'transactionHash' => '0x'.self::HASH,
                        'blockHash' => '0x'.str_repeat('c', 64),
                        'status' => '0x1',
                        'blockNumber' => '0x64',
                        'logs' => [$this->bscLog(0, '0x'.str_repeat('1', 40), '0x1')],
                    ],
                ]),
                $method === 'eth_getBlockByNumber' && $params === ['0x64', false] => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'error' => ['code' => -32000, 'message' => 'provider detail'],
                    'result' => [
                        'number' => '0x64',
                        'hash' => '0x'.str_repeat('c', 64),
                    ],
                ]),
                $method === 'eth_getBlockByNumber' && $params === ['finalized', false] => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => ['number' => '0x64'],
                ]),
                default => Http::response([], 500),
            };
        });

        $observation = (new BscPaymentObservationProvider('https://bsc-rpc.example.test'))->observe(self::HASH);
        $verification = MerchantDirectPaymentVerifier::evaluate(
            MerchantDirectPaymentChannel::USDT_BEP20,
            '0x'.str_repeat('1', 40),
            '1',
            $observation,
        );

        $this->assertNull($observation['finalized_block_number']);
        $this->assertSame('rpc_error', $observation['provider_evidence']['finality_reason']);
        $this->assertSame('observed', $verification['status']);
        $this->assertStringNotContainsString('provider detail', json_encode($observation, JSON_THROW_ON_ERROR));
        Http::assertNotSent(fn (Request $request) => ($request->data()['params'] ?? null) === ['finalized', false]);
    }

    public function test_bsc_finality_error_cannot_be_overridden_by_a_result(): void
    {
        Http::fake(function (Request $request) {
            $method = $request->data()['method'] ?? null;
            $params = $request->data()['params'] ?? null;

            return match (true) {
                $method === 'eth_chainId' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => '0x38',
                ]),
                $method === 'eth_getTransactionReceipt' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => [
                        'transactionHash' => '0x'.self::HASH,
                        'blockHash' => '0x'.str_repeat('c', 64),
                        'status' => '0x1',
                        'blockNumber' => '0x64',
                        'logs' => [$this->bscLog(0, '0x'.str_repeat('1', 40), '0x1')],
                    ],
                ]),
                $method === 'eth_getBlockByNumber' && $params === ['0x64', false] => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => [
                        'number' => '0x64',
                        'hash' => '0x'.str_repeat('c', 64),
                    ],
                ]),
                $method === 'eth_getBlockByNumber' && $params === ['finalized', false] => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'error' => ['code' => -32000, 'message' => 'provider detail'],
                    'result' => ['number' => '0x64'],
                ]),
                default => Http::response([], 500),
            };
        });

        $observation = (new BscPaymentObservationProvider('https://bsc-rpc.example.test'))->observe(self::HASH);
        $verification = MerchantDirectPaymentVerifier::evaluate(
            MerchantDirectPaymentChannel::USDT_BEP20,
            '0x'.str_repeat('1', 40),
            '1',
            $observation,
        );

        $this->assertNull($observation['finalized_block_number']);
        $this->assertSame('rpc_error', $observation['provider_evidence']['finality_reason']);
        $this->assertSame('observed', $verification['status']);
        $this->assertStringNotContainsString('provider detail', json_encode($observation, JSON_THROW_ON_ERROR));
    }

    public function test_bsc_provider_rejects_non_positive_or_malformed_finalized_block_quantities(): void
    {
        $finalizedNumbers = [
            'unprefixed' => '64',
            'empty_hex' => '0x',
            'garbage' => 'garbage',
            'zero' => '0x0',
            'negative' => '-0x1',
        ];
        $caseIndex = 0;
        $cases = array_keys($finalizedNumbers);

        Http::fake(function (Request $request) use (&$caseIndex, $cases, $finalizedNumbers) {
            $method = $request->data()['method'] ?? null;
            $params = $request->data()['params'] ?? null;
            if ($method === 'eth_chainId') {
                return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x38']);
            }
            if ($method === 'eth_getTransactionReceipt') {
                return Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => [
                        'transactionHash' => '0x'.self::HASH,
                        'blockHash' => '0x'.str_repeat('c', 64),
                        'status' => '0x1',
                        'blockNumber' => '0x64',
                        'logs' => [$this->bscLog(0, '0x'.str_repeat('1', 40), '0x1')],
                    ],
                ]);
            }
            if ($method === 'eth_getBlockByNumber' && $params === ['0x64', false]) {
                return Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => [
                        'number' => '0x64',
                        'hash' => '0x'.str_repeat('c', 64),
                    ],
                ]);
            }
            if ($method === 'eth_getBlockByNumber' && $params === ['finalized', false]) {
                $case = $cases[$caseIndex++];

                return Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => ['number' => $finalizedNumbers[$case]],
                ]);
            }

            return Http::response([], 500);
        });

        foreach ($cases as $case) {
            $observation = (new BscPaymentObservationProvider('https://bsc-rpc.example.test'))->observe(self::HASH);
            $verification = MerchantDirectPaymentVerifier::evaluate(
                MerchantDirectPaymentChannel::USDT_BEP20,
                '0x'.str_repeat('1', 40),
                '1',
                $observation,
            );

            $this->assertSame('unavailable', $observation['provider_status'], $case);
            $this->assertSame('malformed_block_quantity', $observation['provider_evidence']['reason'], $case);
            $this->assertSame('unavailable', $verification['status'], $case);
            $this->assertArrayNotHasKey('events', $observation, $case);
        }
        $this->assertSame(count($cases), $caseIndex);
        Http::assertSentCount(count($cases) * 4);
    }

    public function test_tron_provider_reads_decoded_events_and_requires_solidity_visibility(): void
    {
        Http::fake([
            'https://tron.example.test/v1/transactions/*/events*' => Http::response([
                'success' => true,
                'data' => [[
                    'transaction_id' => self::HASH,
                    'event_index' => 4,
                    'event_name' => 'Transfer',
                    'contract_address' => MerchantDirectPaymentChannel::USDT_TRC20->tokenContract(),
                    'block_number' => 1234,
                    'result' => [
                        'from' => self::TRON_FROM,
                        'to' => self::TRON_DESTINATION,
                        'value' => '2500000',
                    ],
                ]],
            ]),
            'https://tron.example.test/v1/transactions/*' => Http::response([
                'success' => true,
                'data' => [[
                    'txID' => self::HASH,
                    'ret' => [['contractRet' => 'SUCCESS']],
                ]],
            ]),
            'https://tron.example.test/walletsolidity/gettransactioninfobyid' => Http::response([
                'id' => self::HASH,
                'blockNumber' => 1234,
            ]),
        ]);

        $observation = (new TronPaymentObservationProvider('https://tron.example.test', 'test-api-key'))
            ->observe(self::HASH);

        $this->assertSame('ok', $observation['provider_status']);
        $this->assertSame('success', $observation['receipt_status']);
        $this->assertTrue($observation['solidified']);
        $this->assertSame('2500000', $observation['events'][0]['amount_atomic']);
        $this->assertSame(self::TRON_DESTINATION, $observation['events'][0]['to']);

        Http::assertSent(fn (Request $request) => $request->hasHeader('TRON-PRO-API-KEY', 'test-api-key'));
    }

    public function test_tron_provider_reads_all_pages_before_deciding_transfer_ambiguity(): void
    {
        $firstPage = [];
        for ($index = 0; $index < 200; $index++) {
            $event = $this->validTronTransferEvent();
            $event['event_index'] = $index;
            $event['result']['to'] = $index === 0 ? self::TRON_DESTINATION : self::TRON_OTHER_DESTINATION;
            $firstPage[] = $event;
        }
        $secondExact = $this->validTronTransferEvent();
        $secondExact['event_index'] = 200;
        $eventQueries = [];

        Http::fake(function (Request $request) use ($firstPage, $secondExact, &$eventQueries) {
            $url = $request->url();
            if (str_contains($url, '/events')) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                $eventQueries[] = $query;
                if (($query['fingerprint'] ?? null) === 'next-token') {
                    return Http::response(['success' => true, 'data' => [$secondExact]]);
                }

                return Http::response([
                    'success' => true,
                    'data' => $firstPage,
                    'meta' => [
                        'has_more' => true,
                        'fingerprint' => 'next-token',
                        'links' => [
                            'next' => 'https://tron.example.test/v1/transactions/'.self::HASH
                                .'/events?only_confirmed=false&limit=200&event_name=Transfer&fingerprint=next-token',
                        ],
                    ],
                ]);
            }
            if (str_contains($url, '/v1/transactions/')) {
                return Http::response([
                    'success' => true,
                    'data' => [[
                        'txID' => self::HASH,
                        'ret' => [['contractRet' => 'SUCCESS']],
                    ]],
                ]);
            }

            return Http::response(['id' => self::HASH, 'blockNumber' => 1234]);
        });

        $observation = (new TronPaymentObservationProvider('https://tron.example.test'))->observe(self::HASH);
        $verification = MerchantDirectPaymentVerifier::evaluate(
            MerchantDirectPaymentChannel::USDT_TRC20,
            self::TRON_DESTINATION,
            '2500000',
            $observation,
        );

        $this->assertCount(201, $observation['events']);
        $this->assertSame('mismatch', $verification['status']);
        $this->assertSame('ambiguous_transfer_event', $verification['failure_code']);
        $this->assertCount(2, $eventQueries);
        $this->assertSame([
            'only_confirmed' => 'false',
            'limit' => '200',
            'event_name' => 'Transfer',
            'fingerprint' => 'next-token',
        ], $eventQueries[1]);
    }

    public function test_tron_full_page_without_next_or_terminal_proof_fails_closed(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_contains($url, '/events')) {
                return Http::response(['success' => true, 'data' => $this->tronPaginationPage('full')]);
            }
            if (str_contains($url, '/v1/transactions/')) {
                return Http::response($this->validTronTransactionPayload());
            }

            return Http::response([], 500);
        });

        $observation = (new TronPaymentObservationProvider('https://tron.example.test'))->observe(self::HASH);

        $this->assertSame('unavailable', $observation['provider_status']);
        $this->assertSame('pagination_missing_fingerprint', $observation['provider_evidence']['reason']);
        $this->assertTrue($observation['provider_evidence']['transaction_seen']);
        Http::assertSentCount(2);
    }

    public function test_tron_has_more_without_a_fingerprint_never_looks_terminal_even_on_a_short_page(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_contains($url, '/events')) {
                return Http::response([
                    'success' => true,
                    'data' => [['event_name' => 'Ignored']],
                    'meta' => ['has_more' => true],
                ]);
            }
            if (str_contains($url, '/v1/transactions/')) {
                return Http::response($this->validTronTransactionPayload());
            }

            return Http::response([], 500);
        });

        $observation = (new TronPaymentObservationProvider('https://tron.example.test'))->observe(self::HASH);

        $this->assertSame('unavailable', $observation['provider_status']);
        $this->assertSame('pagination_missing_fingerprint', $observation['provider_evidence']['reason']);
        Http::assertSentCount(2);
    }

    public function test_tron_explicitly_unsuccessful_short_event_page_is_unavailable_and_cannot_confirm(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_contains($url, '/events')) {
                return Http::response([
                    'success' => false,
                    'data' => [$this->validTronTransferEvent()],
                ]);
            }
            if (str_contains($url, '/v1/transactions/')) {
                return Http::response($this->validTronTransactionPayload());
            }

            return Http::response(['id' => self::HASH, 'blockNumber' => 1234]);
        });

        $observation = (new TronPaymentObservationProvider('https://tron.example.test'))->observe(self::HASH);
        $verification = MerchantDirectPaymentVerifier::evaluate(
            MerchantDirectPaymentChannel::USDT_TRC20,
            self::TRON_DESTINATION,
            '2500000',
            $observation,
        );

        $this->assertSame('unavailable', $observation['provider_status']);
        $this->assertSame('pagination_malformed_envelope', $observation['provider_evidence']['reason']);
        $this->assertSame('unavailable', $verification['status']);
        $this->assertArrayNotHasKey('events', $observation);
        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'walletsolidity'));
    }

    public function test_tron_event_page_without_explicit_success_is_unavailable_and_cannot_confirm(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_contains($url, '/events')) {
                return Http::response([
                    'data' => [$this->validTronTransferEvent()],
                ]);
            }
            if (str_contains($url, '/v1/transactions/')) {
                return Http::response($this->validTronTransactionPayload());
            }

            return Http::response(['id' => self::HASH, 'blockNumber' => 1234]);
        });

        $observation = (new TronPaymentObservationProvider('https://tron.example.test'))->observe(self::HASH);
        $verification = MerchantDirectPaymentVerifier::evaluate(
            MerchantDirectPaymentChannel::USDT_TRC20,
            self::TRON_DESTINATION,
            '2500000',
            $observation,
        );

        $this->assertSame('unavailable', $observation['provider_status']);
        $this->assertSame('pagination_malformed_envelope', $observation['provider_evidence']['reason']);
        $this->assertSame('unavailable', $verification['status']);
        $this->assertArrayNotHasKey('events', $observation);
        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'walletsolidity'));
    }

    public function test_tron_non_boolean_event_page_success_is_unavailable(): void
    {
        $cases = [
            'null' => null,
            'string' => 'true',
            'zero' => 0,
            'one' => 1,
            'array' => [],
        ];

        foreach ($cases as $case => $success) {
            Http::fake(function (Request $request) use ($success) {
                $url = $request->url();
                if (str_contains($url, '/events')) {
                    return Http::response([
                        'success' => $success,
                        'data' => [],
                    ]);
                }
                if (str_contains($url, '/v1/transactions/')) {
                    return Http::response($this->validTronTransactionPayload());
                }

                return Http::response([], 500);
            });

            $observation = (new TronPaymentObservationProvider('https://tron.example.test'))->observe(self::HASH);

            $this->assertSame('unavailable', $observation['provider_status'], $case);
            $this->assertSame(
                'pagination_malformed_envelope',
                $observation['provider_evidence']['reason'],
                $case,
            );
            $this->assertTrue($observation['provider_evidence']['transaction_seen'], $case);
            Http::assertSentCount(2);
        }

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'walletsolidity'));
    }

    public function test_tron_non_boolean_has_more_cannot_make_a_truncated_short_page_look_complete(): void
    {
        $event = $this->validTronTransferEvent();
        $event['result']['from'] = self::TRON_FROM;
        $event['result']['to'] = self::TRON_DESTINATION;
        $hiddenSecondExact = $event;
        $hiddenSecondExact['event_index'] = 5;
        $eventCalls = 0;
        Http::fake(function (Request $request) use ($event, $hiddenSecondExact, &$eventCalls) {
            $url = $request->url();
            if (str_contains($url, '/events')) {
                $eventCalls++;
                if ($eventCalls > 1) {
                    return Http::response(['success' => true, 'data' => [$hiddenSecondExact]]);
                }

                return Http::response([
                    'success' => true,
                    'data' => [$event],
                    'meta' => ['has_more' => 'true'],
                ]);
            }
            if (str_contains($url, '/v1/transactions/')) {
                return Http::response($this->validTronTransactionPayload());
            }

            return Http::response(['id' => self::HASH, 'blockNumber' => 1234]);
        });

        $observation = (new TronPaymentObservationProvider('https://tron.example.test'))->observe(self::HASH);
        $verification = MerchantDirectPaymentVerifier::evaluate(
            MerchantDirectPaymentChannel::USDT_TRC20,
            self::TRON_DESTINATION,
            '2500000',
            $observation,
        );

        $this->assertSame('unavailable', $observation['provider_status']);
        $this->assertSame('pagination_malformed_meta', $observation['provider_evidence']['reason']);
        $this->assertSame('unavailable', $verification['status']);
        $this->assertArrayNotHasKey('events', $observation);
        $this->assertSame(1, $eventCalls);
        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'walletsolidity'));
    }

    public function test_tron_explicit_terminal_metadata_can_close_an_exactly_full_page(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_contains($url, '/events')) {
                return Http::response([
                    'success' => true,
                    'data' => $this->tronPaginationPage('terminal'),
                    'meta' => [
                        'has_more' => false,
                        'links' => ['next' => null],
                    ],
                ]);
            }
            if (str_contains($url, '/v1/transactions/')) {
                return Http::response($this->validTronTransactionPayload());
            }

            return Http::response(['id' => self::HASH, 'blockNumber' => 1234]);
        });

        $observation = (new TronPaymentObservationProvider('https://tron.example.test'))->observe(self::HASH);

        $this->assertSame('ok', $observation['provider_status']);
        $this->assertSame([], $observation['events']);
        Http::assertSentCount(3);
    }

    public function test_tron_pagination_rejects_a_repeated_canonical_page(): void
    {
        $page = $this->tronPaginationPage('repeated');
        $eventCalls = 0;
        Http::fake(function (Request $request) use ($page, &$eventCalls) {
            $url = $request->url();
            if (str_contains($url, '/events')) {
                $eventCalls++;
                $token = $eventCalls === 1 ? 'token-a' : 'token-b';

                return Http::response([
                    'success' => true,
                    'data' => $page,
                    'meta' => [
                        'fingerprint' => $token,
                        'links' => ['next' => $this->tronNextUrl($token)],
                    ],
                ]);
            }
            if (str_contains($url, '/v1/transactions/')) {
                return Http::response($this->validTronTransactionPayload());
            }

            return Http::response([], 500);
        });

        $observation = (new TronPaymentObservationProvider('https://tron.example.test'))->observe(self::HASH);

        $this->assertSame('unavailable', $observation['provider_status']);
        $this->assertSame('pagination_repeated_page', $observation['provider_evidence']['reason']);
        $this->assertSame(2, $eventCalls);
        Http::assertSentCount(3);
    }

    public function test_tron_pagination_rejects_meta_and_link_fingerprint_drift(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_contains($url, '/events')) {
                return Http::response([
                    'success' => true,
                    'data' => $this->tronPaginationPage('drift'),
                    'meta' => [
                        'fingerprint' => 'token-a',
                        'links' => ['next' => $this->tronNextUrl('token-b')],
                    ],
                ]);
            }
            if (str_contains($url, '/v1/transactions/')) {
                return Http::response($this->validTronTransactionPayload());
            }

            return Http::response([], 500);
        });

        $observation = (new TronPaymentObservationProvider('https://tron.example.test'))->observe(self::HASH);

        $this->assertSame('unavailable', $observation['provider_status']);
        $this->assertSame('pagination_token_drift', $observation['provider_evidence']['reason']);
        Http::assertSentCount(2);
    }

    public function test_tron_pagination_rejects_reused_fingerprints(): void
    {
        $eventCalls = 0;
        Http::fake(function (Request $request) use (&$eventCalls) {
            $url = $request->url();
            if (str_contains($url, '/events')) {
                $eventCalls++;

                return Http::response([
                    'success' => true,
                    'data' => $this->tronPaginationPage('token-'.$eventCalls),
                    'meta' => [
                        'fingerprint' => 'same-token',
                        'links' => ['next' => $this->tronNextUrl('same-token')],
                    ],
                ]);
            }
            if (str_contains($url, '/v1/transactions/')) {
                return Http::response($this->validTronTransactionPayload());
            }

            return Http::response([], 500);
        });

        $observation = (new TronPaymentObservationProvider('https://tron.example.test'))->observe(self::HASH);

        $this->assertSame('unavailable', $observation['provider_status']);
        $this->assertSame('pagination_token_drift', $observation['provider_evidence']['reason']);
        $this->assertSame(2, $eventCalls);
    }

    public function test_tron_pagination_rejects_next_host_path_and_query_anomalies(): void
    {
        $links = [
            'host' => 'https://evil.example/v1/transactions/'.self::HASH
                .'/events?only_confirmed=false&limit=200&event_name=Transfer&fingerprint=host-token',
            'path' => 'https://tron.example.test/v1/transactions/'.str_repeat('b', 64)
                .'/events?only_confirmed=false&limit=200&event_name=Transfer&fingerprint=path-token',
            'query' => 'https://tron.example.test/v1/transactions/'.self::HASH
                .'/events?only_confirmed=false&limit=201&event_name=Transfer&fingerprint=query-token',
        ];
        $eventCalls = 0;
        Http::fake(function (Request $request) use ($links, &$eventCalls) {
            $url = $request->url();
            if (str_contains($url, '/events')) {
                $case = array_keys($links)[$eventCalls++];

                return Http::response([
                    'success' => true,
                    'data' => $this->tronPaginationPage($case),
                    'meta' => [
                        'fingerprint' => $case.'-token',
                        'links' => ['next' => $links[$case]],
                    ],
                ]);
            }
            if (str_contains($url, '/v1/transactions/')) {
                return Http::response($this->validTronTransactionPayload());
            }

            return Http::response([], 500);
        });

        $provider = new TronPaymentObservationProvider('https://tron.example.test');
        foreach (array_keys($links) as $case) {
            $observation = $provider->observe(self::HASH);

            $this->assertSame('unavailable', $observation['provider_status'], $case);
            $this->assertSame('pagination_bad_next', $observation['provider_evidence']['reason'], $case);
        }
        $this->assertSame(count($links), $eventCalls);
        Http::assertSentCount(count($links) * 2);
    }

    public function test_tron_pagination_http_error_on_a_later_page_fails_closed(): void
    {
        $eventCalls = 0;
        Http::fake(function (Request $request) use (&$eventCalls) {
            $url = $request->url();
            if (str_contains($url, '/events')) {
                $eventCalls++;
                if ($eventCalls === 2) {
                    return Http::response([], 429);
                }

                return Http::response([
                    'success' => true,
                    'data' => $this->tronPaginationPage('first'),
                    'meta' => [
                        'fingerprint' => 'next-token',
                        'links' => ['next' => $this->tronNextUrl('next-token')],
                    ],
                ]);
            }
            if (str_contains($url, '/v1/transactions/')) {
                return Http::response($this->validTronTransactionPayload());
            }

            return Http::response([], 500);
        });

        $observation = (new TronPaymentObservationProvider('https://tron.example.test'))->observe(self::HASH);

        $this->assertSame('unavailable', $observation['provider_status']);
        $this->assertSame('pagination_http_error', $observation['provider_evidence']['reason']);
        $this->assertSame(2, $eventCalls);
        Http::assertSentCount(3);
    }

    public function test_tron_pagination_stops_after_five_pages_or_one_thousand_raw_events(): void
    {
        $eventCalls = 0;
        Http::fake(function (Request $request) use (&$eventCalls) {
            $url = $request->url();
            if (str_contains($url, '/events')) {
                $eventCalls++;
                $token = 'token-'.$eventCalls;

                return Http::response([
                    'success' => true,
                    'data' => $this->tronPaginationPage('page-'.$eventCalls),
                    'meta' => [
                        'fingerprint' => $token,
                        'links' => ['next' => $this->tronNextUrl($token)],
                    ],
                ]);
            }
            if (str_contains($url, '/v1/transactions/')) {
                return Http::response($this->validTronTransactionPayload());
            }

            return Http::response([], 500);
        });

        $observation = (new TronPaymentObservationProvider('https://tron.example.test'))->observe(self::HASH);

        $this->assertSame('unavailable', $observation['provider_status']);
        $this->assertSame('pagination_limit', $observation['provider_evidence']['reason']);
        $this->assertSame(5, $eventCalls);
        Http::assertSentCount(6);
    }

    public function test_tron_provider_rejects_an_unsuccessful_transaction_envelope(): void
    {
        Http::fake([
            'https://tron.example.test/v1/transactions/*' => Http::response([
                'success' => false,
                'data' => [],
            ]),
        ]);

        $observation = (new TronPaymentObservationProvider('https://tron.example.test'))->observe(self::HASH);

        $this->assertSame('unavailable', $observation['provider_status']);
        $this->assertSame('malformed_response', $observation['provider_evidence']['reason']);
        Http::assertSentCount(1);
    }

    public function test_tron_provider_rejects_a_transaction_envelope_without_data(): void
    {
        Http::fake([
            'https://tron.example.test/v1/transactions/*' => Http::response([
                'success' => true,
            ]),
        ]);

        $observation = (new TronPaymentObservationProvider('https://tron.example.test'))->observe(self::HASH);

        $this->assertSame('unavailable', $observation['provider_status']);
        $this->assertSame('malformed_response', $observation['provider_evidence']['reason']);
        Http::assertSentCount(1);
    }

    public function test_tron_provider_rejects_scalar_transaction_data(): void
    {
        Http::fake([
            'https://tron.example.test/v1/transactions/*' => Http::response([
                'success' => true,
                'data' => 'not-an-array',
            ]),
        ]);

        $observation = (new TronPaymentObservationProvider('https://tron.example.test'))->observe(self::HASH);

        $this->assertSame('unavailable', $observation['provider_status']);
        $this->assertSame('malformed_response', $observation['provider_evidence']['reason']);
        Http::assertSentCount(1);
    }

    public function test_tron_provider_rejects_non_singleton_transaction_data_envelopes(): void
    {
        $transaction = [
            'txID' => self::HASH,
            'ret' => [['contractRet' => 'SUCCESS']],
        ];

        foreach ([
            'empty object' => new \stdClass,
            'null member' => [null],
            'scalar member' => ['not-a-transaction'],
            'associative outer container' => ['transaction' => $transaction],
            'numeric-key object' => (object) ['0' => $transaction],
            'multiple members' => [$transaction, $transaction],
        ] as $case => $data) {
            Http::fake([
                'https://tron.example.test/v1/transactions/*' => Http::response([
                    'success' => true,
                    'data' => $data,
                ]),
            ]);

            $observation = (new TronPaymentObservationProvider('https://tron.example.test'))->observe(self::HASH);

            $this->assertSame('unavailable', $observation['provider_status'], $case);
            $this->assertSame('malformed_response', $observation['provider_evidence']['reason'], $case);
            Http::assertSentCount(1);
        }
    }

    public function test_tron_provider_rejects_a_scalar_transaction_envelope(): void
    {
        Http::fake([
            'https://tron.example.test/v1/transactions/*' => Http::response('not-an-envelope'),
        ]);

        $observation = (new TronPaymentObservationProvider('https://tron.example.test'))->observe(self::HASH);

        $this->assertSame('unavailable', $observation['provider_status']);
        $this->assertSame('malformed_response', $observation['provider_evidence']['reason']);
        Http::assertSentCount(1);
    }

    public function test_tron_provider_treats_successful_empty_transaction_data_as_not_found(): void
    {
        Http::fake([
            'https://tron.example.test/v1/transactions/*' => Http::response([
                'success' => true,
                'data' => [],
            ]),
        ]);

        $observation = (new TronPaymentObservationProvider('https://tron.example.test'))->observe(self::HASH);

        $this->assertSame('not_found', $observation['provider_status']);
        $this->assertSame(['source' => 'trongrid_v1'], $observation['provider_evidence']);
        Http::assertSentCount(1);
    }

    public function test_tron_provider_rejects_a_transaction_envelope_without_success(): void
    {
        Http::fake([
            'https://tron.example.test/v1/transactions/*' => Http::response([
                'data' => [],
            ]),
        ]);

        $observation = (new TronPaymentObservationProvider('https://tron.example.test'))->observe(self::HASH);

        $this->assertSame('unavailable', $observation['provider_status']);
        $this->assertSame('malformed_response', $observation['provider_evidence']['reason']);
        Http::assertSentCount(1);
    }

    public function test_tron_provider_rejects_a_transaction_payload_bound_to_another_hash(): void
    {
        Http::fake([
            'https://tron.example.test/v1/transactions/*/events*' => Http::response(['success' => true, 'data' => []]),
            'https://tron.example.test/v1/transactions/*' => Http::response([
                'success' => true,
                'data' => [[
                    'txID' => str_repeat('b', 64),
                    'ret' => [['contractRet' => 'SUCCESS']],
                ]],
            ]),
            'https://tron.example.test/walletsolidity/gettransactioninfobyid' => Http::response([
                'id' => self::HASH,
                'blockNumber' => 1234,
            ]),
        ]);

        $observation = (new TronPaymentObservationProvider('https://tron.example.test'))->observe(self::HASH);

        $this->assertSame('unavailable', $observation['provider_status']);
        $this->assertSame('transaction_mismatch', $observation['provider_evidence']['reason']);
        $this->assertArrayNotHasKey('events', $observation);
        Http::assertSentCount(1);
    }

    public function test_tron_missing_contract_result_is_unavailable_through_the_verifier_pipeline(): void
    {
        Http::fake([
            'https://tron.example.test/v1/transactions/*/events*' => Http::response(['success' => true, 'data' => []]),
            'https://tron.example.test/v1/transactions/*' => Http::response([
                'success' => true,
                'data' => [[
                    'txID' => self::HASH,
                ]],
            ]),
            'https://tron.example.test/walletsolidity/gettransactioninfobyid' => Http::response([
                'id' => self::HASH,
                'blockNumber' => 1234,
            ]),
        ]);

        $observation = (new TronPaymentObservationProvider('https://tron.example.test'))->observe(self::HASH);
        $verification = MerchantDirectPaymentVerifier::evaluate(
            MerchantDirectPaymentChannel::USDT_TRC20,
            self::TRON_DESTINATION,
            '1',
            $observation,
        );

        $this->assertSame('unavailable', $observation['provider_status']);
        $this->assertSame('malformed_receipt_status', $observation['provider_evidence']['reason']);
        $this->assertSame('unavailable', $verification['status']);
        $this->assertSame('provider_unavailable', $verification['failure_code']);
        $this->assertArrayNotHasKey('events', $observation);
        Http::assertSentCount(1);
    }

    public function test_tron_contract_result_accepts_exact_success_and_a_finite_failure_enum_only(): void
    {
        $invalidStatuses = ['boolean' => true, 'lowercase success' => 'success', 'default' => 'DEFAULT', 'invented' => 'NEW_STATUS'];
        $statuses = [...array_values($invalidStatuses), 'REVERT'];
        $transactionCalls = 0;
        Http::fake(function (Request $request) use ($statuses, &$transactionCalls) {
            $url = $request->url();
            if (str_contains($url, '/events')) {
                return Http::response(['success' => true, 'data' => []]);
            }
            if (str_contains($url, '/v1/transactions/')) {
                return Http::response([
                    'success' => true,
                    'data' => [[
                        'txID' => self::HASH,
                        'ret' => [['contractRet' => $statuses[$transactionCalls++]]],
                    ]],
                ]);
            }

            return Http::response(['id' => self::HASH, 'blockNumber' => 1234]);
        });

        foreach ($invalidStatuses as $case => $status) {

            $observation = (new TronPaymentObservationProvider('https://tron.example.test'))->observe(self::HASH);

            $this->assertSame('unavailable', $observation['provider_status'], $case);
            $this->assertSame('malformed_receipt_status', $observation['provider_evidence']['reason'], $case);
        }

        $failed = (new TronPaymentObservationProvider('https://tron.example.test'))->observe(self::HASH);

        $this->assertSame('ok', $failed['provider_status']);
        $this->assertSame('failed', $failed['receipt_status']);
        $this->assertSame(count($statuses), $transactionCalls);
        Http::assertSentCount(count($invalidStatuses) + 3);
    }

    public function test_tron_provider_uses_only_transfer_events_bound_to_the_requested_transaction(): void
    {
        $validEvent = [
            'transaction_id' => self::HASH,
            'event_index' => 4,
            'event_name' => 'Transfer',
            'contract_address' => MerchantDirectPaymentChannel::USDT_TRC20->tokenContract(),
            'block_number' => 1234,
            'result' => [
                'from' => self::TRON_FROM,
                'to' => self::TRON_DESTINATION,
                'value' => '2500000',
            ],
        ];
        $wrongTransaction = array_replace($validEvent, [
            'transaction_id' => str_repeat('b', 64),
            'event_index' => 5,
        ]);
        $missingTransaction = $validEvent;
        unset($missingTransaction['transaction_id']);
        $missingTransaction['event_index'] = 6;

        Http::fake([
            'https://tron.example.test/v1/transactions/*/events*' => Http::response([
                'success' => true,
                'data' => [$validEvent, $wrongTransaction, $missingTransaction, 'not-an-event'],
            ]),
            'https://tron.example.test/v1/transactions/*' => Http::response([
                'success' => true,
                'data' => [[
                    'txID' => self::HASH,
                    'ret' => [['contractRet' => 'SUCCESS']],
                ]],
            ]),
            'https://tron.example.test/walletsolidity/gettransactioninfobyid' => Http::response([
                'id' => self::HASH,
                'blockNumber' => 1234,
            ]),
        ]);

        $observation = (new TronPaymentObservationProvider('https://tron.example.test'))->observe(self::HASH);

        $this->assertSame('ok', $observation['provider_status']);
        $this->assertCount(1, $observation['events']);
        $this->assertSame(4, $observation['events'][0]['event_index']);
    }

    public function test_tron_provider_fails_closed_when_transaction_bound_events_repeat_an_event_index(): void
    {
        $first = $this->validTronTransferEvent();
        $second = $first;
        $second['result']['to'] = self::TRON_OTHER_DESTINATION;
        $second['result']['value'] = '1';

        Http::fake([
            'https://tron.example.test/v1/transactions/*/events*' => Http::response([
                'success' => true,
                'data' => [$first, $second],
            ]),
            'https://tron.example.test/v1/transactions/*' => Http::response([
                'success' => true,
                'data' => [[
                    'txID' => self::HASH,
                    'ret' => [['contractRet' => 'SUCCESS']],
                ]],
            ]),
            'https://tron.example.test/walletsolidity/gettransactioninfobyid' => Http::response([
                'id' => self::HASH,
                'blockNumber' => 1234,
            ]),
        ]);

        $observation = (new TronPaymentObservationProvider('https://tron.example.test'))->observe(self::HASH);

        $this->assertSame('unavailable', $observation['provider_status']);
        $this->assertSame('duplicate_event_index', $observation['provider_evidence']['reason']);
        $this->assertArrayNotHasKey('events', $observation);
        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'walletsolidity'));
    }

    public function test_tron_duplicate_index_ledger_precedes_all_event_semantics_in_both_orders(): void
    {
        $valid = $this->validTronTransferEvent();
        $invalidEvents = [];

        $wrongTransaction = $valid;
        $wrongTransaction['transaction_id'] = str_repeat('b', 64);
        $invalidEvents['wrong_transaction'] = $wrongTransaction;

        $wrongContract = $valid;
        $wrongContract['contract_address'] = self::TRON_OTHER_DESTINATION;
        $invalidEvents['wrong_contract'] = $wrongContract;

        $malformed = $valid;
        $malformed['result'] = true;
        $invalidEvents['malformed'] = $malformed;

        $irrelevant = $valid;
        $irrelevant['event_name'] = 'Approval';
        $invalidEvents['irrelevant'] = $irrelevant;

        $pages = [];
        foreach ($invalidEvents as $invalid) {
            $pages[] = [$valid, $invalid];
            $pages[] = [$invalid, $valid];
        }
        $eventCalls = 0;
        Http::fake(function (Request $request) use ($pages, &$eventCalls) {
            $url = $request->url();
            if (str_contains($url, '/events')) {
                return Http::response(['success' => true, 'data' => $pages[$eventCalls++]]);
            }
            if (str_contains($url, '/v1/transactions/')) {
                return Http::response($this->validTronTransactionPayload());
            }

            return Http::response([], 500);
        });

        $provider = new TronPaymentObservationProvider('https://tron.example.test');
        foreach (array_keys($invalidEvents) as $case) {
            foreach (['valid_first', 'invalid_first'] as $order) {
                $observation = $provider->observe(self::HASH);

                $this->assertSame('unavailable', $observation['provider_status'], $case.' '.$order);
                $this->assertSame(
                    'duplicate_event_index',
                    $observation['provider_evidence']['reason'],
                    $case.' '.$order,
                );
                $this->assertArrayNotHasKey('events', $observation, $case.' '.$order);
            }
        }
        $this->assertSame(count($pages), $eventCalls);
        Http::assertSentCount(count($pages) * 2);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'walletsolidity'));
    }

    public function test_tron_duplicate_index_ledger_spans_paginated_pages_in_both_orders(): void
    {
        $valid = $this->validTronTransferEvent();
        $invalid = $valid;
        $invalid['transaction_id'] = str_repeat('b', 64);
        $firstInvalid = array_slice($this->tronPaginationPage('invalid-first'), 0, 199);
        $firstInvalid[] = $invalid;
        $firstValid = array_slice($this->tronPaginationPage('valid-first'), 0, 199);
        $firstValid[] = $valid;
        $pages = [
            $firstInvalid,
            [$valid],
            $firstValid,
            [$invalid],
        ];
        $eventCalls = 0;
        Http::fake(function (Request $request) use ($pages, &$eventCalls) {
            $url = $request->url();
            if (str_contains($url, '/events')) {
                $pageIndex = $eventCalls++;
                $payload = ['success' => true, 'data' => $pages[$pageIndex]];
                if ($pageIndex % 2 === 0) {
                    $token = 'token-'.$pageIndex;
                    $payload['meta'] = [
                        'fingerprint' => $token,
                        'links' => ['next' => $this->tronNextUrl($token)],
                    ];
                }

                return Http::response($payload);
            }
            if (str_contains($url, '/v1/transactions/')) {
                return Http::response($this->validTronTransactionPayload());
            }

            return Http::response([], 500);
        });

        $provider = new TronPaymentObservationProvider('https://tron.example.test');
        foreach (['invalid_first', 'valid_first'] as $case) {
            $observation = $provider->observe(self::HASH);

            $this->assertSame('unavailable', $observation['provider_status'], $case);
            $this->assertSame('duplicate_event_index', $observation['provider_evidence']['reason'], $case);
            $this->assertArrayNotHasKey('events', $observation, $case);
        }
        $this->assertSame(4, $eventCalls);
        Http::assertSentCount(6);
    }

    public function test_tron_provider_ignores_a_transfer_event_with_boolean_amount(): void
    {
        $event = $this->validTronTransferEvent();
        $event['result']['value'] = true;

        $observation = $this->observeSingleTronEvent($event);
        $verification = MerchantDirectPaymentVerifier::evaluate(
            MerchantDirectPaymentChannel::USDT_TRC20,
            self::TRON_DESTINATION,
            '1',
            $observation,
        );

        $this->assertSame([], $observation['events']);
        $this->assertNotSame('confirmed', $verification['status']);
    }

    public function test_tron_provider_ignores_transfer_events_with_non_decimal_amounts(): void
    {
        $invalidAmounts = [
            'float' => 1.5,
            'scientific' => '1e3',
            'negative_integer' => -1,
            'negative_string' => '-1',
            'empty' => '',
        ];

        foreach ($invalidAmounts as $case => $invalidAmount) {
            $event = $this->validTronTransferEvent();
            $event['result']['value'] = $invalidAmount;

            $observation = $this->observeSingleTronEvent($event);

            $this->assertSame([], $observation['events'], $case);
        }
    }

    public function test_tron_provider_ignores_transfer_events_with_non_string_or_empty_addresses(): void
    {
        $invalidEvents = [];
        $invalidContract = $this->validTronTransferEvent();
        $invalidContract['contract_address'] = 123;
        $invalidEvents['contract_integer'] = $invalidContract;

        $invalidFrom = $this->validTronTransferEvent();
        $invalidFrom['result']['from'] = true;
        $invalidEvents['from_boolean'] = $invalidFrom;

        $invalidTo = $this->validTronTransferEvent();
        $invalidTo['result']['to'] = 123;
        $invalidEvents['to_integer'] = $invalidTo;

        $emptyAddress = $this->validTronTransferEvent();
        $emptyAddress['contract_address'] = '';
        $invalidEvents['empty_contract'] = $emptyAddress;

        foreach ($invalidEvents as $case => $event) {
            $observation = $this->observeSingleTronEvent($event);

            $this->assertSame([], $observation['events'], $case);
        }
    }

    public function test_tron_provider_ignores_a_transfer_event_with_boolean_event_index(): void
    {
        $event = $this->validTronTransferEvent();
        $event['event_index'] = true;

        $observation = $this->observeSingleTronEvent($event);

        $this->assertSame([], $observation['events']);
    }

    public function test_tron_provider_ignores_transfer_events_with_negative_or_malformed_event_indices(): void
    {
        $invalidIndices = [
            'negative_integer' => -1,
            'negative_string' => '-1',
            'float' => 1.5,
            'scientific' => '1e3',
            'leading_zero' => '01',
            'empty' => '',
            'out_of_signed_integer_range' => '9223372036854775808',
        ];

        foreach ($invalidIndices as $case => $invalidIndex) {
            $event = $this->validTronTransferEvent();
            $event['event_index'] = $invalidIndex;

            $observation = $this->observeSingleTronEvent($event);

            $this->assertSame([], $observation['events'], $case);
        }

        $missingIndex = $this->validTronTransferEvent();
        unset($missingIndex['event_index']);
        $observation = $this->observeSingleTronEvent($missingIndex);

        $this->assertSame([], $observation['events'], 'missing');
    }

    public function test_tron_provider_normalizes_valid_amount_and_event_index_representations(): void
    {
        $integerAmount = $this->validTronTransferEvent();
        $integerAmount['event_index'] = '4';
        $integerAmount['result']['value'] = 1;

        $observation = $this->observeSingleTronEvent($integerAmount);

        $this->assertSame(4, $observation['events'][0]['event_index']);
        $this->assertSame('1', $observation['events'][0]['amount_atomic']);

        $zeroPaddedAmount = $this->validTronTransferEvent();
        $zeroPaddedAmount['result']['value'] = '0001';

        $observation = $this->observeSingleTronEvent($zeroPaddedAmount);

        $this->assertSame('1', $observation['events'][0]['amount_atomic']);
    }

    public function test_tron_provider_rejects_scalar_event_data(): void
    {
        Http::fake([
            'https://tron.example.test/v1/transactions/*/events*' => Http::response([
                'success' => true,
                'data' => 'not-an-array',
            ]),
            'https://tron.example.test/v1/transactions/*' => Http::response([
                'success' => true,
                'data' => [[
                    'txID' => self::HASH,
                    'ret' => [['contractRet' => 'SUCCESS']],
                ]],
            ]),
            'https://tron.example.test/walletsolidity/gettransactioninfobyid' => Http::response([
                'id' => self::HASH,
                'blockNumber' => 1234,
            ]),
        ]);

        $observation = (new TronPaymentObservationProvider('https://tron.example.test'))->observe(self::HASH);

        $this->assertSame('unavailable', $observation['provider_status']);
        $this->assertSame('malformed_response', $observation['provider_evidence']['reason']);
        $this->assertTrue($observation['provider_evidence']['transaction_seen']);
        $this->assertArrayNotHasKey('events', $observation);
        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'walletsolidity'));
    }

    public function test_tron_provider_ignores_a_transfer_event_without_block_number(): void
    {
        Http::fake([
            'https://tron.example.test/v1/transactions/*/events*' => Http::response([
                'success' => true,
                'data' => [[
                    'transaction_id' => self::HASH,
                    'event_index' => 4,
                    'event_name' => 'Transfer',
                    'contract_address' => MerchantDirectPaymentChannel::USDT_TRC20->tokenContract(),
                    'result' => [
                        'from' => self::TRON_FROM,
                        'to' => self::TRON_DESTINATION,
                        'value' => '2500000',
                    ],
                ]],
            ]),
            'https://tron.example.test/v1/transactions/*' => Http::response([
                'success' => true,
                'data' => [[
                    'txID' => self::HASH,
                    'ret' => [['contractRet' => 'SUCCESS']],
                ]],
            ]),
            'https://tron.example.test/walletsolidity/gettransactioninfobyid' => Http::response([
                'id' => self::HASH,
                'blockNumber' => 1234,
            ]),
        ]);

        $observation = (new TronPaymentObservationProvider('https://tron.example.test'))->observe(self::HASH);
        $verification = MerchantDirectPaymentVerifier::evaluate(
            MerchantDirectPaymentChannel::USDT_TRC20,
            self::TRON_DESTINATION,
            '2500000',
            $observation,
        );

        $this->assertSame([], $observation['events']);
        $this->assertNotSame('confirmed', $verification['status']);
    }

    public function test_tron_provider_ignores_a_transfer_event_with_zero_integer_block_number(): void
    {
        Http::fake([
            'https://tron.example.test/v1/transactions/*/events*' => Http::response([
                'success' => true,
                'data' => [[
                    'transaction_id' => self::HASH,
                    'event_index' => 4,
                    'event_name' => 'Transfer',
                    'contract_address' => MerchantDirectPaymentChannel::USDT_TRC20->tokenContract(),
                    'block_number' => 0,
                    'result' => [
                        'from' => self::TRON_FROM,
                        'to' => self::TRON_DESTINATION,
                        'value' => '2500000',
                    ],
                ]],
            ]),
            'https://tron.example.test/v1/transactions/*' => Http::response([
                'success' => true,
                'data' => [[
                    'txID' => self::HASH,
                    'ret' => [['contractRet' => 'SUCCESS']],
                ]],
            ]),
            'https://tron.example.test/walletsolidity/gettransactioninfobyid' => Http::response([
                'id' => self::HASH,
                'blockNumber' => 1234,
            ]),
        ]);

        $observation = (new TronPaymentObservationProvider('https://tron.example.test'))->observe(self::HASH);
        $verification = MerchantDirectPaymentVerifier::evaluate(
            MerchantDirectPaymentChannel::USDT_TRC20,
            self::TRON_DESTINATION,
            '2500000',
            $observation,
        );

        $this->assertSame([], $observation['events']);
        $this->assertNotSame('confirmed', $verification['status']);
    }

    public function test_tron_provider_ignores_a_transfer_event_with_malformed_block_number(): void
    {
        Http::fake([
            'https://tron.example.test/v1/transactions/*/events*' => Http::response([
                'success' => true,
                'data' => [[
                    'transaction_id' => self::HASH,
                    'event_index' => 4,
                    'event_name' => 'Transfer',
                    'contract_address' => MerchantDirectPaymentChannel::USDT_TRC20->tokenContract(),
                    'block_number' => 'not-a-block',
                    'result' => [
                        'from' => self::TRON_FROM,
                        'to' => self::TRON_DESTINATION,
                        'value' => '2500000',
                    ],
                ]],
            ]),
            'https://tron.example.test/v1/transactions/*' => Http::response([
                'success' => true,
                'data' => [[
                    'txID' => self::HASH,
                    'ret' => [['contractRet' => 'SUCCESS']],
                ]],
            ]),
            'https://tron.example.test/walletsolidity/gettransactioninfobyid' => Http::response([
                'id' => self::HASH,
                'blockNumber' => 1234,
            ]),
        ]);

        $observation = (new TronPaymentObservationProvider('https://tron.example.test'))->observe(self::HASH);
        $verification = MerchantDirectPaymentVerifier::evaluate(
            MerchantDirectPaymentChannel::USDT_TRC20,
            self::TRON_DESTINATION,
            '2500000',
            $observation,
        );

        $this->assertSame([], $observation['events']);
        $this->assertNotSame('confirmed', $verification['status']);
    }

    public function test_tron_provider_ignores_a_transfer_event_with_zero_string_block_number(): void
    {
        Http::fake([
            'https://tron.example.test/v1/transactions/*/events*' => Http::response([
                'success' => true,
                'data' => [[
                    'transaction_id' => self::HASH,
                    'event_index' => 4,
                    'event_name' => 'Transfer',
                    'contract_address' => MerchantDirectPaymentChannel::USDT_TRC20->tokenContract(),
                    'block_number' => '0',
                    'result' => [
                        'from' => self::TRON_FROM,
                        'to' => self::TRON_DESTINATION,
                        'value' => '2500000',
                    ],
                ]],
            ]),
            'https://tron.example.test/v1/transactions/*' => Http::response([
                'success' => true,
                'data' => [[
                    'txID' => self::HASH,
                    'ret' => [['contractRet' => 'SUCCESS']],
                ]],
            ]),
            'https://tron.example.test/walletsolidity/gettransactioninfobyid' => Http::response([
                'id' => self::HASH,
                'blockNumber' => 1234,
            ]),
        ]);

        $observation = (new TronPaymentObservationProvider('https://tron.example.test'))->observe(self::HASH);
        $verification = MerchantDirectPaymentVerifier::evaluate(
            MerchantDirectPaymentChannel::USDT_TRC20,
            self::TRON_DESTINATION,
            '2500000',
            $observation,
        );

        $this->assertSame([], $observation['events']);
        $this->assertNotSame('confirmed', $verification['status']);
    }

    public function test_tron_event_block_must_match_the_solidity_block_to_confirm(): void
    {
        Http::fake([
            'https://tron.example.test/v1/transactions/*/events*' => Http::response([
                'success' => true,
                'data' => [[
                    'transaction_id' => self::HASH,
                    'event_index' => 4,
                    'event_name' => 'Transfer',
                    'contract_address' => MerchantDirectPaymentChannel::USDT_TRC20->tokenContract(),
                    'block_number' => 1233,
                    'result' => [
                        'from' => self::TRON_FROM,
                        'to' => self::TRON_DESTINATION,
                        'value' => '2500000',
                    ],
                ]],
            ]),
            'https://tron.example.test/v1/transactions/*' => Http::response([
                'success' => true,
                'data' => [[
                    'txID' => self::HASH,
                    'ret' => [['contractRet' => 'SUCCESS']],
                ]],
            ]),
            'https://tron.example.test/walletsolidity/gettransactioninfobyid' => Http::response([
                'id' => self::HASH,
                'blockNumber' => 1234,
            ]),
        ]);

        $observation = (new TronPaymentObservationProvider('https://tron.example.test'))->observe(self::HASH);
        $verification = MerchantDirectPaymentVerifier::evaluate(
            MerchantDirectPaymentChannel::USDT_TRC20,
            self::TRON_DESTINATION,
            '2500000',
            $observation,
        );

        $this->assertCount(1, $observation['events']);
        $this->assertFalse($observation['solidified']);
        $this->assertSame('event_block_mismatch', $observation['provider_evidence']['finality_reason']);
        $this->assertSame('observed', $verification['status']);
    }

    public function test_tron_solidity_response_for_another_transaction_cannot_confirm_the_event(): void
    {
        Http::fake([
            'https://tron.example.test/v1/transactions/*/events*' => Http::response([
                'success' => true,
                'data' => [[
                    'transaction_id' => self::HASH,
                    'event_index' => 4,
                    'event_name' => 'Transfer',
                    'contract_address' => MerchantDirectPaymentChannel::USDT_TRC20->tokenContract(),
                    'block_number' => 1234,
                    'result' => [
                        'from' => self::TRON_FROM,
                        'to' => self::TRON_DESTINATION,
                        'value' => '2500000',
                    ],
                ]],
            ]),
            'https://tron.example.test/v1/transactions/*' => Http::response([
                'success' => true,
                'data' => [[
                    'txID' => self::HASH,
                    'ret' => [['contractRet' => 'SUCCESS']],
                ]],
            ]),
            'https://tron.example.test/walletsolidity/gettransactioninfobyid' => Http::response([
                'id' => str_repeat('b', 64),
                'blockNumber' => 1234,
            ]),
        ]);

        $observation = (new TronPaymentObservationProvider('https://tron.example.test'))->observe(self::HASH);
        $verification = MerchantDirectPaymentVerifier::evaluate(
            MerchantDirectPaymentChannel::USDT_TRC20,
            self::TRON_DESTINATION,
            '2500000',
            $observation,
        );

        $this->assertSame('ok', $observation['provider_status']);
        $this->assertFalse($observation['solidified']);
        $this->assertSame('solidity_transaction_mismatch', $observation['provider_evidence']['finality_reason']);
        $this->assertSame('observed', $verification['status']);
    }

    public function test_tron_solidity_response_with_malformed_block_number_cannot_confirm_the_event(): void
    {
        Http::fake([
            'https://tron.example.test/v1/transactions/*/events*' => Http::response([
                'success' => true,
                'data' => [[
                    'transaction_id' => self::HASH,
                    'event_index' => 4,
                    'event_name' => 'Transfer',
                    'contract_address' => MerchantDirectPaymentChannel::USDT_TRC20->tokenContract(),
                    'block_number' => 1234,
                    'result' => [
                        'from' => self::TRON_FROM,
                        'to' => self::TRON_DESTINATION,
                        'value' => '2500000',
                    ],
                ]],
            ]),
            'https://tron.example.test/v1/transactions/*' => Http::response([
                'success' => true,
                'data' => [[
                    'txID' => self::HASH,
                    'ret' => [['contractRet' => 'SUCCESS']],
                ]],
            ]),
            'https://tron.example.test/walletsolidity/gettransactioninfobyid' => Http::response([
                'id' => self::HASH,
                'blockNumber' => 'not-a-block',
            ]),
        ]);

        $observation = (new TronPaymentObservationProvider('https://tron.example.test'))->observe(self::HASH);
        $verification = MerchantDirectPaymentVerifier::evaluate(
            MerchantDirectPaymentChannel::USDT_TRC20,
            self::TRON_DESTINATION,
            '2500000',
            $observation,
        );

        $this->assertSame('ok', $observation['provider_status']);
        $this->assertFalse($observation['solidified']);
        $this->assertSame('malformed_solidity_block', $observation['provider_evidence']['finality_reason']);
        $this->assertSame('observed', $verification['status']);
    }

    public function test_tron_solidity_zero_integer_block_number_cannot_confirm_the_event(): void
    {
        Http::fake([
            'https://tron.example.test/v1/transactions/*/events*' => Http::response([
                'success' => true,
                'data' => [[
                    'transaction_id' => self::HASH,
                    'event_index' => 4,
                    'event_name' => 'Transfer',
                    'contract_address' => MerchantDirectPaymentChannel::USDT_TRC20->tokenContract(),
                    'block_number' => 1234,
                    'result' => [
                        'from' => self::TRON_FROM,
                        'to' => self::TRON_DESTINATION,
                        'value' => '2500000',
                    ],
                ]],
            ]),
            'https://tron.example.test/v1/transactions/*' => Http::response([
                'success' => true,
                'data' => [[
                    'txID' => self::HASH,
                    'ret' => [['contractRet' => 'SUCCESS']],
                ]],
            ]),
            'https://tron.example.test/walletsolidity/gettransactioninfobyid' => Http::response([
                'id' => self::HASH,
                'blockNumber' => 0,
            ]),
        ]);

        $observation = (new TronPaymentObservationProvider('https://tron.example.test'))->observe(self::HASH);
        $verification = MerchantDirectPaymentVerifier::evaluate(
            MerchantDirectPaymentChannel::USDT_TRC20,
            self::TRON_DESTINATION,
            '2500000',
            $observation,
        );

        $this->assertSame('ok', $observation['provider_status']);
        $this->assertFalse($observation['solidified']);
        $this->assertSame('malformed_solidity_block', $observation['provider_evidence']['finality_reason']);
        $this->assertSame('observed', $verification['status']);
    }

    public function test_tron_solidity_zero_string_block_number_cannot_confirm_the_event(): void
    {
        Http::fake([
            'https://tron.example.test/v1/transactions/*/events*' => Http::response([
                'success' => true,
                'data' => [[
                    'transaction_id' => self::HASH,
                    'event_index' => 4,
                    'event_name' => 'Transfer',
                    'contract_address' => MerchantDirectPaymentChannel::USDT_TRC20->tokenContract(),
                    'block_number' => 1234,
                    'result' => [
                        'from' => self::TRON_FROM,
                        'to' => self::TRON_DESTINATION,
                        'value' => '2500000',
                    ],
                ]],
            ]),
            'https://tron.example.test/v1/transactions/*' => Http::response([
                'success' => true,
                'data' => [[
                    'txID' => self::HASH,
                    'ret' => [['contractRet' => 'SUCCESS']],
                ]],
            ]),
            'https://tron.example.test/walletsolidity/gettransactioninfobyid' => Http::response([
                'id' => self::HASH,
                'blockNumber' => '0',
            ]),
        ]);

        $observation = (new TronPaymentObservationProvider('https://tron.example.test'))->observe(self::HASH);
        $verification = MerchantDirectPaymentVerifier::evaluate(
            MerchantDirectPaymentChannel::USDT_TRC20,
            self::TRON_DESTINATION,
            '2500000',
            $observation,
        );

        $this->assertSame('ok', $observation['provider_status']);
        $this->assertFalse($observation['solidified']);
        $this->assertSame('malformed_solidity_block', $observation['provider_evidence']['finality_reason']);
        $this->assertSame('observed', $verification['status']);
    }

    public function test_tron_transaction_lookup_timeout_or_rate_limit_is_unavailable(): void
    {
        Http::fake([
            'https://tron.example.test/v1/transactions/*' => Http::response([], 429),
        ]);

        $observation = (new TronPaymentObservationProvider('https://tron.example.test'))->observe(self::HASH);

        $this->assertSame('unavailable', $observation['provider_status']);
        $this->assertSame('rate_limited', $observation['provider_evidence']['reason']);
    }

    public function test_tron_connection_exception_details_are_not_exposed_in_provider_evidence(): void
    {
        $secretUrl = 'https://rpc.example/SECRET_TOKEN?project=internal';
        Http::fake(fn () => throw new ConnectionException('Connection failed for '.$secretUrl));

        $observation = (new TronPaymentObservationProvider($secretUrl))->observe(self::HASH);
        $evidence = json_encode($observation['provider_evidence'], JSON_THROW_ON_ERROR);

        $this->assertSame('unavailable', $observation['provider_status']);
        $this->assertSame([
            'source' => 'trongrid_v1',
            'reason' => 'timeout_or_connection',
        ], $observation['provider_evidence']);
        $this->assertStringNotContainsString('SECRET_TOKEN', $evidence);
        $this->assertStringNotContainsString('rpc.example', $evidence);
        $this->assertStringNotContainsString('exception', $evidence);
    }

    private function validTronTransferEvent(): array
    {
        return [
            'transaction_id' => self::HASH,
            'event_index' => 4,
            'event_name' => 'Transfer',
            'contract_address' => MerchantDirectPaymentChannel::USDT_TRC20->tokenContract(),
            'block_number' => 1234,
            'result' => [
                'from' => self::TRON_FROM,
                'to' => self::TRON_DESTINATION,
                'value' => '2500000',
            ],
        ];
    }

    private function validTronTransactionPayload(): array
    {
        return [
            'success' => true,
            'data' => [[
                'txID' => self::HASH,
                'ret' => [['contractRet' => 'SUCCESS']],
            ]],
        ];
    }

    private function tronPaginationPage(string $marker): array
    {
        $page = [];
        for ($index = 0; $index < 200; $index++) {
            $page[] = [
                'event_name' => 'Ignored',
                'marker' => $marker,
                'position' => $index,
            ];
        }

        return $page;
    }

    private function tronNextUrl(string $fingerprint): string
    {
        return 'https://tron.example.test/v1/transactions/'.self::HASH
            .'/events?only_confirmed=false&limit=200&event_name=Transfer&fingerprint='.$fingerprint;
    }

    private function observeSingleTronEvent(array $event): array
    {
        Http::fake([
            'https://tron.example.test/v1/transactions/*/events*' => Http::response([
                'success' => true,
                'data' => [$event],
            ]),
            'https://tron.example.test/v1/transactions/*' => Http::response([
                'success' => true,
                'data' => [[
                    'txID' => self::HASH,
                    'ret' => [['contractRet' => 'SUCCESS']],
                ]],
            ]),
            'https://tron.example.test/walletsolidity/gettransactioninfobyid' => Http::response([
                'id' => self::HASH,
                'blockNumber' => 1234,
            ]),
        ]);

        return (new TronPaymentObservationProvider('https://tron.example.test'))->observe(self::HASH);
    }

    private function bscLog(int $index, string $to, string $amountData): array
    {
        if (preg_match('/^0x[0-9a-f]+$/iD', $amountData) === 1) {
            $amountData = '0x'.str_pad(substr($amountData, 2), 64, '0', STR_PAD_LEFT);
        }

        return [
            'address' => MerchantDirectPaymentChannel::USDT_BEP20->tokenContract(),
            'logIndex' => '0x'.dechex($index),
            'transactionHash' => '0x'.self::HASH,
            'blockHash' => '0x'.str_repeat('c', 64),
            'blockNumber' => '0x64',
            'topics' => [
                BscPaymentObservationProvider::TRANSFER_TOPIC,
                '0x'.str_repeat('0', 24).str_repeat('a', 40),
                '0x'.str_repeat('0', 24).substr($to, 2),
            ],
            'data' => $amountData,
        ];
    }

    private function invalidBscBlockQuantities(): array
    {
        return [
            'uppercase_prefix' => '0X64',
            'uppercase_digit' => '0xA',
            'leading_zero' => '0x064',
            'empty_string' => '',
            'empty_quantity' => '0x',
            'integer' => 100,
            'null' => null,
        ];
    }

    private function observeBscBlockQuantities(
        mixed $receiptQuantity,
        mixed $logQuantity,
        mixed $canonicalQuantity,
        mixed $finalizedQuantity,
    ): array {
        $log = array_replace(
            $this->bscLog(0, '0x'.str_repeat('1', 40), '0x1'),
            ['blockNumber' => $logQuantity],
        );

        Http::fake(function (Request $request) use (
            $receiptQuantity,
            $canonicalQuantity,
            $finalizedQuantity,
            $log,
        ) {
            $method = $request->data()['method'] ?? null;
            $params = $request->data()['params'] ?? null;

            return match (true) {
                $method === 'eth_chainId' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => '0x38',
                ]),
                $method === 'eth_getTransactionReceipt' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => [
                        'transactionHash' => '0x'.self::HASH,
                        'blockHash' => '0x'.str_repeat('c', 64),
                        'status' => '0x1',
                        'blockNumber' => $receiptQuantity,
                        'logs' => [$log],
                    ],
                ]),
                $method === 'eth_getBlockByNumber' && $params === [$receiptQuantity, false] => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => [
                        'number' => $canonicalQuantity,
                        'hash' => '0x'.str_repeat('c', 64),
                    ],
                ]),
                $method === 'eth_getBlockByNumber' && $params === ['finalized', false] => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => ['number' => $finalizedQuantity],
                ]),
                default => Http::response([], 500),
            };
        });

        return (new BscPaymentObservationProvider('https://bsc-rpc.example.test'))->observe(self::HASH);
    }

    private function observeBscTransferData(array $dataValues): array
    {
        $logs = [];
        foreach (array_values($dataValues) as $index => $data) {
            $logs[] = array_replace(
                $this->bscLog($index, '0x'.str_repeat('1', 40), '0x1'),
                ['data' => $data],
            );
        }

        Http::fake(function (Request $request) use ($logs) {
            $method = $request->data()['method'] ?? null;
            $params = $request->data()['params'] ?? null;

            return match (true) {
                $method === 'eth_chainId' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => '0x38',
                ]),
                $method === 'eth_getTransactionReceipt' => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => [
                        'transactionHash' => '0x'.self::HASH,
                        'blockHash' => '0x'.str_repeat('c', 64),
                        'status' => '0x1',
                        'blockNumber' => '0x64',
                        'logs' => $logs,
                    ],
                ]),
                $method === 'eth_getBlockByNumber' && $params === ['0x64', false] => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => [
                        'number' => '0x64',
                        'hash' => '0x'.str_repeat('c', 64),
                    ],
                ]),
                $method === 'eth_getBlockByNumber' && $params === ['finalized', false] => Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => ['number' => '0x64'],
                ]),
                default => Http::response([], 500),
            };
        });

        return (new BscPaymentObservationProvider('https://bsc-rpc.example.test'))->observe(self::HASH);
    }
}
