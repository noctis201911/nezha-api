<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NezhaTgWebhookSyncTest extends TestCase
{
    use DatabaseTransactions;

    public function test_command_syncs_callback_updates_without_dropping_pending_updates(): void
    {
        Config::set('telegram_bot_token_conf', ['value' => 'TEST_TOKEN']);
        Config::set('nezha_cs_tg_webhook_secret_conf', ['value' => 'TEST_SECRET']);
        Config::set('app.url', 'http://api.example.test');
        $infoCalls = 0;
        Http::fake(function (HttpRequest $request) use (&$infoCalls) {
            if (str_contains($request->url(), '/getWebhookInfo')) {
                $infoCalls++;

                return Http::response([
                    'ok' => true,
                    'result' => [
                        'url' => 'https://api.example.test/api/v1/nezha/telegram-webhook',
                        'allowed_updates' => $infoCalls === 1
                            ? ['message', 'edited_message']
                            : ['message', 'edited_message', 'callback_query'],
                        'pending_update_count' => 0,
                    ],
                ], 200);
            }

            return Http::response(['ok' => true, 'result' => true], 200);
        });

        $this->artisan('nezha:tg-webhook-sync')
            ->expectsOutputToContain('同步前')
            ->expectsOutputToContain('同步后')
            ->expectsOutput('Telegram webhook 已同步。')
            ->assertExitCode(0);

        Http::assertSent(function (HttpRequest $request): bool {
            if (! str_contains($request->url(), '/setWebhook')) {
                return false;
            }
            $allowed = json_decode((string) ($request['allowed_updates'] ?? ''), true);

            return ($request['url'] ?? null) === 'https://api.example.test/api/v1/nezha/telegram-webhook'
                && ($request['secret_token'] ?? null) === 'TEST_SECRET'
                && $allowed === ['message', 'edited_message', 'callback_query']
                && ! isset($request['drop_pending_updates']);
        });
        Http::assertSentCount(3);
    }
}
