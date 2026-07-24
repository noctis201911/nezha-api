<?php

namespace Tests\Feature;

use App\CentralLogics\NezhaNewOrderNag;
use App\CentralLogics\NezhaOrderTgCard;
use App\CentralLogics\NezhaOrderTgCardActions;
use App\Http\Controllers\Api\V1\TelegramWebhookController;
use App\Models\Order;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NezhaOrderTgCardActionsTest extends TestCase
{
    use DatabaseTransactions;

    /** @var int[] */
    private array $orderIds = [93001, 93002, 93003, 93004, 93005, 93006, 93007, 93008, 93009, 93010];

    /** @var array<string, array<string, mixed>> */
    private array $acceptedCallbackAnswers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setSetting('telegram_bot_token', 'TEST_TOKEN');
        $this->setSetting('nezha_order_tg_card_actions_status', 1);
        $this->setSetting('nezha_notif_log_status', 1);
        $this->setSetting('nezha_default_prep_min', 30);
        $this->setSetting('nezha_sanction_screen_status', 1, false);
        $this->setSetting('nezha_sanction_inconclusive_action', 'hold', false);
        $this->setSetting('nezha_refund_chain_rpc_bsc_list', 'https://bsc.test', false);
        Config::set('mail.status', false);
        $this->acceptedCallbackAnswers = [];

        DB::table('notification_messages')->updateOrInsert(
            ['key' => 'offline_order_accept_message', 'user_type' => 'user'],
            [
                'message' => '订单 :order_id 收款已确认',
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
        DB::table('notification_messages')->updateOrInsert(
            ['key' => 'offline_order_deny_message', 'user_type' => 'user'],
            [
                'message' => '订单 :order_id 收款未通过',
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        foreach ($this->orderIds as $orderId) {
            Cache::forget('nz_tg_confirm_pay_'.$orderId);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->orderIds as $orderId) {
            Cache::forget('nz_tg_confirm_pay_'.$orderId);
        }
        foreach (range(100, 499) as $updateId) {
            Cache::forget('nz_tg_upd_'.$updateId);
        }
        parent::tearDown();
    }

    public function test_cross_restaurant_callback_is_rejected_without_state_change(): void
    {
        $this->fakeTelegram();
        $restaurantA = $this->restaurant(61, '700061');
        $restaurantB = $this->restaurant(62, '700062');
        $this->offlineOrder(93001, $restaurantB, 'pending');
        $this->card(93001, '700061', '55001');

        NezhaOrderTgCardActions::handle(
            $this->callbackPayload('idor', '700061', '55001', 'pay_t', 93001, 30),
            100
        );

        $this->assertDatabaseHas('orders', [
            'id' => 93001,
            'restaurant_id' => 62,
            'order_status' => 'pending',
            'payment_status' => 'unpaid',
            'checked' => 0,
        ]);
        $this->assertDatabaseHas('offline_payments', ['order_id' => 93001, 'status' => 'pending']);
        $this->assertTelegramFeedback('无权操作该订单', true);
        $this->assertSame(61, $restaurantA);
    }

    public function test_terminal_order_callback_is_rejected_with_visible_alert(): void
    {
        $this->fakeTelegram();
        $rid = $this->restaurant(63, '700063');
        $this->offlineOrder(93002, $rid, 'pending', ['order_status' => 'canceled', 'canceled' => now()]);
        $this->card(93002, '700063', '55002');

        NezhaOrderTgCardActions::handle(
            $this->callbackPayload('terminal', '700063', '55002', 'pay', 93002),
            101
        );

        $this->assertDatabaseHas('orders', ['id' => 93002, 'order_status' => 'canceled', 'checked' => 0]);
        $this->assertTelegramFeedback('该订单已结束', true);
    }

    public function test_catch_path_answers_once_with_generic_alert(): void
    {
        $this->fakeTelegram();
        $callback = $this->callbackPayload('catch-once', '700063', '55002', 'pay', 93002);
        $callback['message']['chat']['id'] = new \stdClass;

        NezhaOrderTgCardActions::handle($callback, 102);

        $this->assertTelegramFeedback('操作失败，请稍后重试', true);
    }

    public function test_confirm_payment_is_idempotent_marks_only_one_order_and_notifies_once(): void
    {
        $this->fakeTelegram();
        $rid = $this->restaurant(64, '700064');
        $this->offlineOrder(93003, $rid, 'pending');
        $this->offlineOrder(93004, $rid, 'pending');
        $this->card(93003, '700064', '55003');

        NezhaOrderTgCardActions::handle(
            $this->callbackPayload('open-time', '700064', '55003', 'pay', 93003),
            110
        );
        NezhaOrderTgCardActions::handle(
            $this->callbackPayload('confirm-time', '700064', '55003', 'pay_t', 93003, 30),
            111
        );
        // 同 update_id 重投 + 新 update_id 重复点击都不得再次执行核心。
        NezhaOrderTgCardActions::handle(
            $this->callbackPayload('confirm-time', '700064', '55003', 'pay_t', 93003, 30),
            111
        );
        NezhaOrderTgCardActions::handle(
            $this->callbackPayload('confirm-again', '700064', '55003', 'pay_t', 93003, 45),
            112
        );

        $this->assertDatabaseHas('orders', [
            'id' => 93003,
            'order_status' => 'processing',
            'payment_status' => 'paid',
            'processing_time' => 30,
            'checked' => 1,
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => 93004,
            'order_status' => 'pending',
            'payment_status' => 'unpaid',
            'checked' => 0,
        ]);
        $this->assertDatabaseHas('offline_payments', ['order_id' => 93003, 'status' => 'verified']);
        $this->assertDatabaseHas('nezha_order_tg_cards', [
            'order_id' => 93003,
            'message_id' => '55003',
            'last_state' => 'processing',
            'last_action_by_tg_uid' => '880001',
        ]);
        $this->assertSame(
            1,
            DB::table('user_notifications')->where('user_id', 1)->count(),
            '重复点击不得重复通知顾客'
        );
        $bucketIds = NezhaNewOrderNag::bucketsForRestaurant($rid, true, true)['accept']->concat(
            NezhaNewOrderNag::bucketsForRestaurant($rid, true, true)['payment']
        )->pluck('id')->all();
        $this->assertNotContains(93003, $bucketIds, 'checked=1 后该单必须退出催单三桶');
        $this->assertContains(93004, $bucketIds, '同店另一 checked=0 单不得被批量静音');
    }

    public function test_sanction_hit_stays_unpaid_and_never_leaks_sentinel_to_telegram(): void
    {
        $sentinelAddress = '0x'.str_repeat('b', 40);
        $txHash = '0x'.str_repeat('a', 64);
        $this->fakeTelegram($sentinelAddress);
        $rid = $this->restaurant(65, '700065');
        $this->offlineOrder(93005, $rid, 'pending', [], [
            'method_id' => 2,
            'method_name' => 'BSC USDT',
            'tx_hash' => $txHash,
        ]);
        $this->card(93005, '700065', '55005');
        DB::table('nezha_sanction_addresses')->insert([
            'addr_kind' => 'evm',
            'address' => $sentinelAddress,
            'source' => 'SENTINEL_SDN',
            'sdn_uid' => 'SENTINEL_UID',
            'currency_type' => 'USDT',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        NezhaOrderTgCardActions::handle(
            $this->callbackPayload('sanction-open', '700065', '55005', 'pay', 93005),
            200
        );
        NezhaOrderTgCardActions::handle(
            $this->callbackPayload('sanction-confirm', '700065', '55005', 'pay_t', 93005, 15),
            201
        );

        $this->assertDatabaseHas('orders', [
            'id' => 93005,
            'order_status' => 'pending',
            'payment_status' => 'unpaid',
        ]);
        $this->assertDatabaseHas('offline_payments', ['order_id' => 93005, 'status' => 'denied']);
        $this->assertDatabaseHas('nezha_risk_records', [
            'order_id' => 93005,
            'action' => 'reject',
            'status' => 'auto',
        ]);
        $this->assertTelegramFeedback(
            '该单付款来源命中制裁名单，已自动拒收，请勿出餐并联系平台',
            true
        );

        $telegramPayloads = [];
        foreach (Http::recorded() as [$request]) {
            if (str_contains($request->url(), 'api.telegram.org')) {
                $telegramPayloads[] = json_encode($request->data(), JSON_UNESCAPED_UNICODE);
            }
        }
        $joined = implode("\n", $telegramPayloads);
        foreach (['SENTINEL_SDN', 'SENTINEL_UID', $sentinelAddress, $txHash] as $sentinel) {
            $this->assertStringNotContainsString($sentinel, $joined);
        }
    }

    public function test_sanction_inconclusive_hold_releases_lock_and_can_retry_without_leaking(): void
    {
        $sentinelTx = '0x'.str_repeat('c', 64);
        $sentinelAddress = '0x'.str_repeat('d', 40);
        $sentinelSource = 'SENTINEL_HOLD_SOURCE';
        $sentinelUid = 'SENTINEL_HOLD_UID';
        $this->fakeTelegram();
        $rid = $this->restaurant(70, '700070');
        $this->offlineOrder(93010, $rid, 'pending', [], [
            'method_id' => 2,
            'method_name' => 'BSC USDT',
            'tx_hash' => $sentinelTx,
            'from' => $sentinelAddress,
            'source' => $sentinelSource,
            'sdn_uid' => $sentinelUid,
        ]);
        $this->card(93010, '700070', '55010');

        NezhaOrderTgCardActions::handle(
            $this->callbackPayload('hold-first', '700070', '55010', 'pay_t', 93010, 15),
            220
        );
        $this->assertFalse(Cache::has('nz_tg_confirm_pay_93010'), '核验未决后必须释放订单锁');

        NezhaOrderTgCardActions::handle(
            $this->callbackPayload('hold-retry', '700070', '55010', 'pay_t', 93010, 15),
            221
        );
        $this->assertFalse(Cache::has('nz_tg_confirm_pay_93010'), '重试仍未决时也必须释放订单锁');

        $this->assertDatabaseHas('orders', [
            'id' => 93010,
            'order_status' => 'pending',
            'payment_status' => 'unpaid',
            'checked' => 0,
        ]);
        $this->assertDatabaseHas('offline_payments', ['order_id' => 93010, 'status' => 'pending']);
        $this->assertSame(
            1,
            DB::table('nezha_risk_records')
                ->where('order_id', 93010)
                ->where('action', 'review')
                ->where('status', 'pending')
                ->count(),
            '重复重试只保留一条待人工核验记录'
        );
        $this->assertSame(
            2,
            collect(Http::recorded())->filter(
                fn (array $record): bool => $record[0]->url() === 'https://bsc.test'
            )->count(),
            '释放锁后第二次点击必须真正重新核验'
        );
        $this->assertTelegramFeedback(
            '付款来源核验中，暂不能确认收款，请稍后重试',
            true,
            2
        );

        $telegramPayloads = [];
        foreach (Http::recorded() as [$request]) {
            if (str_contains($request->url(), 'api.telegram.org')) {
                $telegramPayloads[] = json_encode($request->data(), JSON_UNESCAPED_UNICODE);
            }
        }
        $joined = implode("\n", $telegramPayloads);
        foreach ([$sentinelSource, $sentinelUid, $sentinelAddress, $sentinelTx] as $sentinel) {
            $this->assertStringNotContainsString($sentinel, $joined);
        }
    }

    public function test_store_default_button_passes_no_custom_time(): void
    {
        $this->fakeTelegram();
        $this->setSetting('nezha_default_prep_min', 27);
        $rid = $this->restaurant(69, '700069');
        $this->offlineOrder(93009, $rid, 'pending');
        $this->card(93009, '700069', '55009');

        NezhaOrderTgCardActions::handle(
            $this->callbackPayload('default-open', '700069', '55009', 'pay', 93009),
            210
        );
        NezhaOrderTgCardActions::handle(
            $this->callbackPayload('default-confirm', '700069', '55009', 'pay_t', 93009, 0),
            211
        );

        $this->assertDatabaseHas('orders', [
            'id' => 93009,
            'order_status' => 'processing',
            'payment_status' => 'paid',
            'processing_time' => 27,
            'checked' => 1,
        ]);
    }

    public function test_one_card_flow_edits_same_message_and_sends_only_once(): void
    {
        $this->fakeTelegram();
        $rid = $this->restaurant(66, '700066');
        $this->offlineOrder(93006, $rid, 'pending');
        $order = Order::with(['restaurant', 'offline_payments', 'details'])->findOrFail(93006);

        $this->assertTrue(NezhaOrderTgCard::sendAndPersist($order, '700066'));
        $messageId = (string) DB::table('nezha_order_tg_cards')
            ->where('order_id', 93006)
            ->value('message_id');
        NezhaOrderTgCardActions::handle(
            $this->callbackPayload('one-card-open', '700066', $messageId, 'pay', 93006),
            300
        );
        NezhaOrderTgCardActions::handle(
            $this->callbackPayload('one-card-confirm', '700066', $messageId, 'pay_t', 93006, 45),
            301
        );

        $sendRequests = $this->telegramRequests('/sendMessage');
        $editRequests = $this->telegramRequests('/editMessageText');
        $this->assertCount(1, $sendRequests, '正常全流程只允许首发一条卡片');
        $this->assertCount(2, $editRequests, '选择时长与确认成功均应原地编辑');
        foreach ($editRequests as $request) {
            $this->assertSame($messageId, (string) $request['message_id']);
        }
        $this->assertSame(
            $messageId,
            (string) DB::table('nezha_order_tg_cards')->where('order_id', 93006)->value('message_id')
        );
    }

    public function test_edit_failure_resends_once_and_updates_message_id(): void
    {
        Http::fake(function (HttpRequest $request) {
            if (str_contains($request->url(), '/editMessageText')) {
                return Http::response(['ok' => false, 'description' => 'message deleted'], 400);
            }
            if (str_contains($request->url(), '/sendMessage')) {
                return Http::response(['ok' => true, 'result' => ['message_id' => 99123]], 200);
            }

            return Http::response(['ok' => true, 'result' => true], 200);
        });
        $rid = $this->restaurant(67, '700067');
        $this->offlineOrder(93007, $rid, 'pending');
        $this->card(93007, '700067', '55007');

        NezhaOrderTgCardActions::handle(
            $this->callbackPayload('fallback', '700067', '55007', 'pay', 93007),
            400
        );

        $this->assertCount(2, $this->telegramRequests('/editMessageText'), '编辑失败只重试一次');
        $this->assertCount(1, $this->telegramRequests('/sendMessage'), '重试仍失败后只降级重发一次');
        $this->assertDatabaseHas('nezha_order_tg_cards', [
            'order_id' => 93007,
            'message_id' => '99123',
            'last_state' => 'awaiting_prep_time',
        ]);
    }

    public function test_switch_off_and_group_callback_never_change_order(): void
    {
        $this->fakeTelegram();
        $rid = $this->restaurant(68, '-100700068');
        $this->offlineOrder(93008, $rid, 'pending');
        $this->card(93008, '-100700068', '55008');

        NezhaOrderTgCardActions::handle(
            $this->callbackPayload('group', '-100700068', '55008', 'pay_t', 93008, 30),
            401
        );
        $this->assertTelegramFeedback('群绑定暂为只读', true);

        $this->setSetting('nezha_order_tg_card_actions_status', 0);
        NezhaOrderTgCardActions::handle(
            $this->callbackPayload('off', '-100700068', '55008', 'pay_t', 93008, 30),
            402
        );
        $this->assertTelegramFeedback('功能未开启', false);
        $this->assertDatabaseHas('orders', [
            'id' => 93008,
            'order_status' => 'pending',
            'payment_status' => 'unpaid',
            'checked' => 0,
        ]);
    }

    public function test_webhook_secret_gate_routes_callback_query_to_action_handler(): void
    {
        $this->fakeTelegram();
        $this->setSetting('nezha_order_tg_card_actions_status', 0);
        $this->setSetting('nezha_cs_tg_webhook_secret', 'TEST_SECRET');
        $request = Request::create('/api/v1/nezha/telegram-webhook', 'POST', [
            'update_id' => 499,
            'callback_query' => $this->callbackPayload('controller', '700099', '55999', 'pay', 99999),
        ]);
        $request->headers->set('X-Telegram-Bot-Api-Secret-Token', 'TEST_SECRET');

        $response = (new TelegramWebhookController)->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTelegramFeedback('功能未开启', false);
    }

    private function setSetting(string $key, $value, bool $config = true): void
    {
        DB::table('business_settings')->updateOrInsert(
            ['key' => $key],
            ['value' => (string) $value, 'created_at' => now(), 'updated_at' => now()]
        );
        if ($config) {
            Config::set($key.'_conf', ['value' => (string) $value]);
        }
    }

    private function restaurant(int $id, string $chatId): int
    {
        DB::table('restaurants')->insert([
            'id' => $id,
            'name' => 'TG动作测试店 '.$id,
            'vendor_id' => 1,
            'zone_id' => 3,
            'restaurant_model' => 'commission',
            'telegram_chat_id' => $chatId,
            'timeout_notify_telegram' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function offlineOrder(
        int $id,
        int $restaurantId,
        string $offlineStatus,
        array $orderExtra = [],
        array $paymentInfo = []
    ): void {
        DB::table('orders')->insert(array_merge([
            'id' => $id,
            'restaurant_id' => $restaurantId,
            'user_id' => 1,
            'order_status' => 'pending',
            'payment_status' => 'unpaid',
            'payment_method' => 'offline_payment',
            'order_type' => 'delivery',
            'order_amount' => 8500,
            'checked' => 0,
            'scheduled' => 0,
            'is_guest' => 0,
            'delivery_address' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ], $orderExtra));
        DB::table('offline_payments')->insert([
            'order_id' => $id,
            'status' => $offlineStatus,
            'payment_info' => json_encode($paymentInfo ?: [
                'method_id' => 1,
                'method_name' => 'AMD transfer',
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function card(int $orderId, string $chatId, string $messageId): void
    {
        DB::table('nezha_order_tg_cards')->insert([
            'order_id' => $orderId,
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'last_state' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function callbackPayload(
        string $callbackId,
        string $chatId,
        string $messageId,
        string $action,
        int $orderId,
        ?int $time = null
    ): array {
        $data = ['v' => 1, 'a' => $action, 'o' => $orderId];
        if ($time !== null) {
            $data['t'] = $time;
        }

        return [
            'id' => $callbackId,
            'from' => ['id' => 880001],
            'message' => [
                'message_id' => (int) $messageId,
                'chat' => ['id' => (int) $chatId],
            ],
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    private function fakeTelegram(?string $bscFrom = null): void
    {
        Http::fake(function (HttpRequest $request) use ($bscFrom) {
            if ($request->url() === 'https://bsc.test') {
                return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['from' => $bscFrom]], 200);
            }
            if (str_contains($request->url(), '/answerCallbackQuery')) {
                $callbackId = (string) ($request['callback_query_id'] ?? '');
                if (array_key_exists($callbackId, $this->acceptedCallbackAnswers)) {
                    return Http::response([
                        'ok' => false,
                        'description' => 'Bad Request: query is too old and response timeout expired or query ID is invalid',
                    ], 400);
                }
                $this->acceptedCallbackAnswers[$callbackId] = $request->data();

                return Http::response(['ok' => true, 'result' => true], 200);
            }
            if (str_contains($request->url(), '/sendMessage')) {
                return Http::response(['ok' => true, 'result' => ['message_id' => 55006]], 200);
            }
            if (str_contains($request->url(), '/editMessageText')) {
                return Http::response(['ok' => true, 'result' => ['message_id' => $request['message_id']]], 200);
            }

            return Http::response(['ok' => true, 'result' => true], 200);
        });
    }

    /** @return HttpRequest[] */
    private function telegramRequests(string $path): array
    {
        $requests = [];
        foreach (Http::recorded() as [$request]) {
            if (str_contains($request->url(), 'api.telegram.org')
                && str_contains($request->url(), $path)) {
                $requests[] = $request;
            }
        }

        return $requests;
    }

    private function assertTelegramFeedback(string $text, bool $showAlert, int $expectedCount = 1): void
    {
        $accepted = array_filter(
            $this->acceptedCallbackAnswers,
            fn (array $payload): bool => (string) ($payload['text'] ?? '') === $text
                && (bool) ($payload['show_alert'] ?? false) === $showAlert
        );
        $this->assertCount(
            $expectedCount,
            $accepted,
            '必须由 Telegram 接受带指定文字和提示样式的唯一作答'
        );

        foreach (array_keys($accepted) as $callbackId) {
            $this->assertCount(
                1,
                array_filter(
                    $this->telegramRequests('/answerCallbackQuery'),
                    fn (HttpRequest $request): bool => (string) ($request['callback_query_id'] ?? '') === $callbackId
                ),
                "callback {$callbackId} 只能作答一次"
            );
        }
    }
}
