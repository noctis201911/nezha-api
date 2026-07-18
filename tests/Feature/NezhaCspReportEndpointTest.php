<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use Psr\Log\AbstractLogger;
use Tests\TestCase;

class NezhaCspReportEndpointTest extends TestCase
{
    public function test_legacy_csp_report_is_accepted_through_post_only(): void
    {
        $payload = json_encode([
            'csp-report' => [
                'document-uri' => 'https://nezha.am/checkout?order_id=SENSITIVE#payment',
                'effective-directive' => 'script-src-elem',
                'blocked-uri' => 'https://cdn.example.test/script.js?token=SENSITIVE',
            ],
        ], JSON_THROW_ON_ERROR);

        $this->call(
            'POST',
            '/api/v1/security/csp-report',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/csp-report'],
            $payload,
        )->assertNoContent();

        $this->get('/api/v1/security/csp-report')->assertStatus(405);
    }

    public function test_legacy_report_is_sanitized_before_logging(): void
    {
        $logger = new class extends AbstractLogger
        {
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = compact('level', 'message', 'context');
            }
        };
        Log::swap($logger);

        $payload = json_encode([
            'csp-report' => [
                'document-uri' => 'https://nezha.am/checkout/order-987?order_id=ORDER-SECRET#payment',
                'referrer' => 'https://nezha.am/info?token=REFERRER-SECRET',
                'effective-directive' => 'script-src-elem',
                'violated-directive' => "script-src 'self' https://tracker.example.test",
                'blocked-uri' => 'https://cdn.example.test/script.js?wallet=FULL-ADDRESS-SECRET#fragment',
                'source-file' => 'https://nezha.am/_next/static/chunk.js?token=SOURCE-SECRET',
                'script-sample' => 'document.cookie + ORDER-SECRET',
                'original-policy' => "default-src 'self'; report-uri /secret",
                'status-code' => 200,
                'line-number' => 42,
                'column-number' => 7,
            ],
        ], JSON_THROW_ON_ERROR);

        $this->call(
            'POST',
            '/api/v1/security/csp-report',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/csp-report'],
            $payload,
        )->assertNoContent();

        $this->assertCount(1, $logger->records);
        $record = $logger->records[0];
        $this->assertSame('notice', $record['level']);
        $this->assertSame('csp.report_only.violation', $record['message']);
        $this->assertSame('https://nezha.am/checkout', $record['context']['document']);
        $this->assertSame('https://cdn.example.test', $record['context']['blocked_source']);
        $this->assertSame('https://nezha.am', $record['context']['source_origin']);
        $this->assertSame('script-src-elem', $record['context']['effective_directive']);

        $serialized = json_encode($record, JSON_THROW_ON_ERROR);
        foreach (['ORDER-SECRET', 'REFERRER-SECRET', 'FULL-ADDRESS-SECRET', 'SOURCE-SECRET', 'document.cookie', 'original-policy', 'referrer'] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }
    }

    public function test_modern_reporting_api_payload_keeps_only_sanitized_csp_violations(): void
    {
        $logger = new class extends AbstractLogger
        {
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = compact('level', 'message', 'context');
            }
        };
        Log::swap($logger);

        $payload = json_encode([
            [
                'type' => 'csp-violation',
                'url' => 'https://nezha.am/info?order=TOP-SECRET',
                'user_agent' => 'SECRET-UA',
                'body' => [
                    'documentURL' => 'https://nezha.am/info?order=TOP-SECRET#address',
                    'effectiveDirective' => 'connect-src',
                    'blockedURL' => 'https://wallet.example.test/address/FULL-WALLET-SECRET?token=TOP-SECRET',
                    'sourceFile' => 'https://nezha.am/_next/chunk.js?token=TOP-SECRET',
                    'referrer' => 'https://nezha.am/checkout?order=TOP-SECRET',
                    'sample' => 'fetch(document.cookie)',
                    'statusCode' => 200,
                    'lineNumber' => 18,
                    'columnNumber' => 3,
                    'disposition' => 'report',
                ],
            ],
            [
                'type' => 'deprecation',
                'body' => ['message' => 'must not be logged'],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->call(
            'POST',
            '/api/v1/security/csp-report',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/reports+json'],
            $payload,
        )->assertNoContent();

        $this->assertCount(1, $logger->records);
        $record = $logger->records[0]['context'];
        $this->assertSame('https://nezha.am/info', $record['document']);
        $this->assertSame('https://wallet.example.test', $record['blocked_source']);
        $this->assertSame('https://nezha.am', $record['source_origin']);
        $this->assertSame('connect-src', $record['effective_directive']);

        $serialized = json_encode($logger->records, JSON_THROW_ON_ERROR);
        foreach (['TOP-SECRET', 'FULL-WALLET-SECRET', 'SECRET-UA', 'document.cookie', 'deprecation'] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }
    }

    public function test_unapproved_report_media_type_is_rejected(): void
    {
        $this->call(
            'POST',
            '/api/v1/security/csp-report',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{"csp-report":{}}',
        )->assertStatus(415);
    }

    public function test_malformed_report_json_is_rejected_without_logging(): void
    {
        $logger = new class extends AbstractLogger
        {
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = compact('level', 'message', 'context');
            }
        };
        Log::swap($logger);

        $this->call(
            'POST',
            '/api/v1/security/csp-report',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/csp-report'],
            '{not-json',
        )->assertStatus(400);

        $this->assertSame([], $logger->records);
    }

    public function test_report_body_larger_than_sixteen_kib_is_rejected(): void
    {
        $this->call(
            'POST',
            '/api/v1/security/csp-report',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/csp-report'],
            str_repeat('x', 16 * 1024 + 1),
        )->assertStatus(413);
    }

    public function test_duplicate_sanitized_report_is_logged_once(): void
    {
        $logger = new class extends AbstractLogger
        {
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = compact('level', 'message', 'context');
            }
        };
        Log::swap($logger);

        $payload = json_encode([
            'csp-report' => [
                'document-uri' => 'https://nezha.am/checkout?first=secret',
                'effective-directive' => 'img-src',
                'blocked-uri' => 'https://images.example.test/a.png?token=secret',
            ],
        ], JSON_THROW_ON_ERROR);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->call(
                'POST',
                '/api/v1/security/csp-report',
                [],
                [],
                [],
                ['CONTENT_TYPE' => 'application/csp-report'],
                $payload,
            )->assertNoContent();
        }

        $this->assertCount(1, $logger->records);
    }

    public function test_report_endpoint_is_limited_to_sixty_requests_per_minute_per_ip(): void
    {
        $logger = new class extends AbstractLogger
        {
            public function log($level, string|\Stringable $message, array $context = []): void
            {
            }
        };
        Log::swap($logger);
        $server = [
            'CONTENT_TYPE' => 'application/csp-report',
            'REMOTE_ADDR' => '203.0.113.77',
        ];
        $payload = '{"csp-report":{}}';

        for ($attempt = 0; $attempt < 60; $attempt++) {
            $this->call('POST', '/api/v1/security/csp-report', [], [], [], $server, $payload)
                ->assertNoContent();
        }

        $this->call('POST', '/api/v1/security/csp-report', [], [], [], $server, $payload)
            ->assertStatus(429);
    }
}
