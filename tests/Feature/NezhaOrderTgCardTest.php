<?php

namespace Tests\Feature;

use App\CentralLogics\Helpers;
use App\Jobs\SendTelegramMessageJob;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class NezhaOrderTgCardTest extends TestCase
{
    use DatabaseTransactions;

    /** @var int[] */
    private array $orderIds = [92001, 92002, 92003, 92004, 92005, 92006, 92007, 92008, 92009];

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('telegram_bot_token_conf', ['value' => 'TEST_TOKEN']);
        Config::set('nezha_notif_async_status_conf', ['value' => '1']);
        Config::set('nezha_notif_log_status_conf', ['value' => '1']);
        Config::set('nezha_order_tg_card_actions_status_conf', ['value' => '0']);
        foreach ($this->orderIds as $orderId) {
            Cache::forget('tg_alert_'.$orderId);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->orderIds as $orderId) {
            Cache::forget('tg_alert_'.$orderId);
        }
        parent::tearDown();
    }

    public function test_switch_off_keeps_old_text_path_and_does_not_write_card(): void
    {
        Config::set('nezha_order_tg_card_status_conf', ['value' => '0']);
        Queue::fake();
        Http::fake();
        $order = $this->order(92001, '700001');

        Helpers::sendTelegramOrderAlert($order);

        Http::assertNothingSent();
        Queue::assertPushed(SendTelegramMessageJob::class, fn ($job) => $job->chatId === '700001' && str_contains($job->text, '🔔 哪吒新订单')
        );
        $this->assertDatabaseMissing('nezha_order_tg_cards', ['order_id' => 92001]);
    }

    public function test_switch_on_but_unbound_restaurant_sends_nothing(): void
    {
        Config::set('nezha_order_tg_card_status_conf', ['value' => '1']);
        Queue::fake();
        Http::fake();

        Helpers::sendTelegramOrderAlert($this->order(92002, null));

        Http::assertNothingSent();
        Queue::assertNotPushed(SendTelegramMessageJob::class);
        $this->assertDatabaseMissing('nezha_order_tg_cards', ['order_id' => 92002]);
    }

    public function test_success_sends_one_card_persists_message_id_and_skips_old_text(): void
    {
        Config::set('nezha_order_tg_card_status_conf', ['value' => '1']);
        Queue::fake();
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 445566]], 200),
        ]);

        Helpers::sendTelegramOrderAlert($this->order(92003, '700003'));

        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), '/sendMessage')
                && ($request['chat_id'] ?? null) === '700003'
                && str_contains((string) ($request['text'] ?? ''), '🔔 新订单 #92003')
                && ! isset($request['reply_markup'])
                && ! str_contains((string) ($request['text'] ?? ''), 'SENTINEL_');
        });
        Queue::assertNotPushed(SendTelegramMessageJob::class);
        $this->assertDatabaseHas('nezha_order_tg_cards', [
            'order_id' => 92003,
            'chat_id' => '700003',
            'message_id' => '445566',
            'last_state' => 'pending',
            'last_action_by_tg_uid' => null,
        ]);
        $this->assertDatabaseHas('nezha_notification_log', [
            'channel' => 'telegram',
            'target' => 'merchant',
            'event_type' => 'order_card',
            'outcome' => 'ok',
            'order_id' => 92003,
            'restaurant_id' => 6,
            'detail' => null,
        ]);
    }

    public function test_http_500_falls_back_to_existing_text_job_without_card_row(): void
    {
        $this->assertCardFailureFallsBack(92004, Http::response(['ok' => false], 500));
    }

    public function test_http_ok_false_falls_back_to_existing_text_job_without_card_row(): void
    {
        $this->assertCardFailureFallsBack(92005, Http::response(['ok' => false], 200));
    }

    public function test_connection_exception_falls_back_to_existing_text_job_without_card_row(): void
    {
        $this->assertCardFailureFallsBack(92006, Http::failedConnection());
    }

    public function test_action_switch_on_private_offline_order_renders_only_confirm_payment(): void
    {
        Config::set('nezha_order_tg_card_actions_status_conf', ['value' => '1']);
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 92007]], 200),
        ]);

        $this->assertTrue(
            \App\CentralLogics\NezhaOrderTgCard::sendAndPersist(
                $this->order(92007, '700007', 'offline_payment', 'pending'),
                '700007'
            )
        );

        Http::assertSent(function ($request): bool {
            $markup = json_decode((string) ($request['reply_markup'] ?? ''), true);
            $buttons = collect($markup['inline_keyboard'] ?? [])->flatten(1);
            $callbackData = (string) ($buttons[0]['callback_data'] ?? '');
            $decoded = json_decode($callbackData, true);

            return count($buttons) === 1
                && ($buttons[0]['text'] ?? null) === '💰 确认收款'
                && strlen($callbackData) <= 64
                && $decoded === ['v' => 1, 'a' => 'pay', 'o' => 92007]
                && ! str_contains((string) ($request['reply_markup'] ?? ''), '拒单')
                && ! str_contains((string) ($request['reply_markup'] ?? ''), '出餐');
        });
    }

    public function test_action_keyboard_requires_payment_row_and_nonfinal_order(): void
    {
        $withoutPaymentRow = $this->order(92998, '700098', 'offline_payment');
        $terminal = $this->order(92999, '700099', 'offline_payment', 'pending');
        $terminal->order_status = 'canceled';

        $this->assertNull(
            \App\CentralLogics\NezhaOrderTgCard::keyboardFor($withoutPaymentRow, '700098', true)
        );
        $this->assertNull(
            \App\CentralLogics\NezhaOrderTgCard::keyboardFor($terminal, '700099', true)
        );
    }

    public function test_group_chat_never_gets_action_keyboard(): void
    {
        Config::set('nezha_order_tg_card_actions_status_conf', ['value' => '1']);
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 92008]], 200),
        ]);

        \App\CentralLogics\NezhaOrderTgCard::sendAndPersist(
            $this->order(92008, '-100700008', 'offline_payment', 'pending'),
            '-100700008'
        );

        Http::assertSent(fn ($request) => ! isset($request['reply_markup']));
    }

    public function test_persist_failure_does_not_fall_back_to_duplicate_old_text(): void
    {
        Config::set('nezha_order_tg_card_status_conf', ['value' => '1']);
        Queue::fake();
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 92009]], 200),
        ]);
        DB::statement(
            "CREATE TRIGGER nz_test_tg_card_persist_fail BEFORE INSERT ON nezha_order_tg_cards "
            ."BEGIN SELECT RAISE(ABORT, 'forced persist failure'); END"
        );

        try {
            Helpers::sendTelegramOrderAlert($this->order(92009, '700009'));
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS nz_test_tg_card_persist_fail');
        }

        Http::assertSentCount(1);
        Queue::assertNotPushed(SendTelegramMessageJob::class);
        $this->assertDatabaseMissing('nezha_order_tg_cards', ['order_id' => 92009]);
        $this->assertDatabaseHas('nezha_notification_log', [
            'event_type' => 'order_card',
            'outcome' => 'persist_failed',
            'order_id' => 92009,
        ]);
    }

    public function test_demo_uses_constructed_payload_and_never_persists_card_row(): void
    {
        Config::set('nezha_risk_admin_chat_id_conf', ['value' => '700006']);
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 778899]], 200),
        ]);
        $before = DB::table('nezha_order_tg_cards')->count();

        $this->artisan('nezha:tg-card-demo')->assertExitCode(0);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => ($request['chat_id'] ?? null) === '700006'
            && str_contains((string) ($request['text'] ?? ''), '北方烧烤（演示）')
            && str_contains((string) ($request['text'] ?? ''), '⚠️ 演示卡片 · 构造数据')
        );
        $this->assertSame($before, DB::table('nezha_order_tg_cards')->count());
        $this->assertDatabaseHas('nezha_notification_log', [
            'event_type' => 'order_card_demo',
            'outcome' => 'ok',
            'order_id' => null,
            'restaurant_id' => null,
            'detail' => null,
        ]);
    }

    private function assertCardFailureFallsBack(int $orderId, $response): void
    {
        Config::set('nezha_order_tg_card_status_conf', ['value' => '1']);
        Queue::fake();
        Http::fake(['api.telegram.org/*' => $response]);

        Helpers::sendTelegramOrderAlert($this->order($orderId, '700004'));

        Http::assertSentCount(1);
        Queue::assertPushed(SendTelegramMessageJob::class, fn ($job) => $job->chatId === '700004' && str_contains($job->text, '🔔 哪吒新订单')
        );
        $this->assertDatabaseMissing('nezha_order_tg_cards', ['order_id' => $orderId]);
        $this->assertDatabaseHas('nezha_notification_log', [
            'event_type' => 'order_card',
            'outcome' => 'failed',
            'order_id' => $orderId,
            'restaurant_id' => 6,
            'detail' => null,
        ]);
    }

    private function order(
        int $id,
        ?string $chatId,
        string $paymentMethod = 'cash_on_delivery',
        ?string $offlineStatus = null
    ): object
    {
        return (object) [
            'id' => $id,
            'restaurant_id' => 6,
            'restaurant' => (object) [
                'id' => 6,
                'name' => '测试烧烤店',
                'telegram_chat_id' => $chatId,
                'timeout_notify_telegram' => 1,
            ],
            'order_status' => 'pending',
            'order_type' => 'delivery',
            'order_amount' => 8500,
            'payment_method' => $paymentMethod,
            'offline_payments' => $offlineStatus === null ? null : (object) ['status' => $offlineStatus],
            'scheduled' => 0,
            'schedule_at' => null,
            'created_at' => '2026-07-23 11:15:00',
            'delivery_address' => '{"address":"SENTINEL_ADDR"}',
            'order_note' => 'SENTINEL_NOTE',
            'details' => [
                (object) ['food_details' => json_encode(['name' => '烤羊肉串'], JSON_UNESCAPED_UNICODE), 'quantity' => 2],
                (object) ['food_details' => json_encode(['name' => '拉瓦什'], JSON_UNESCAPED_UNICODE), 'quantity' => 1],
            ],
        ];
    }
}
