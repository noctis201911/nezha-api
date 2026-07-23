<?php

namespace Tests\Feature;

use App\Exceptions\AccountDeletionException;
use App\Mail\CustomerAccountDeletionCompletedMail;
use App\Models\CustomerAccountDeletionEvent;
use App\Models\CustomerAccountDeletionNotice;
use App\Models\CustomerAccountDeletionState;
use App\Models\Order;
use App\Models\User;
use App\Services\CustomerAccountDeletion\CustomerAccountDeletionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CustomerAccountDeletionLifecycleTest extends TestCase
{
    private CustomerAccountDeletionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->createSchema();
        $this->migration()->up();
        $this->service = app(CustomerAccountDeletionService::class);
    }

    public function test_migration_creates_three_encrypted_lifecycle_tables_and_four_disabled_switches(): void
    {
        $this->migration()->up();

        $this->assertTrue(Schema::hasTable('customer_account_deletion_states'));
        $this->assertTrue(Schema::hasTable('customer_account_deletion_events'));
        $this->assertFalse(Schema::hasTable('customer_account_deletion_requests'));
        $this->assertTrue(Schema::hasTable('customer_account_deletion_notices'));
        $this->assertTrue(Schema::hasColumns('customer_account_deletion_states', [
            'request_id',
            'obligation_epoch_at_claim',
            'sessions_revoke_requested_at',
            'sessions_revoked_at',
            'challenge_hash',
        ]));
        $this->assertSame(4, DB::table('business_settings')
            ->where('key', 'like', 'nezha_account_deletion_%')->count());
        $this->assertSame(['0'], DB::table('business_settings')
            ->where('key', 'like', 'nezha_account_deletion_%')
            ->pluck('value')->unique()->values()->all());

        $this->migration()->down();
        $this->assertFalse(Schema::hasTable('customer_account_deletion_states'));
        $this->assertFalse(Schema::hasTable('customer_account_deletion_events'));
        $this->assertFalse(Schema::hasTable('customer_account_deletion_notices'));
    }

    public function test_checkout_requires_verified_notice_email_and_cancel_erases_route(): void
    {
        $this->flag('nezha_account_deletion_intake_enabled', true);
        $unverified = $this->user('unverified@example.test', false);
        try {
            $this->service->assertValidCheckoutRequest(
                $unverified,
                true,
                CustomerAccountDeletionService::COPY_CHECKOUT
            );
            $this->fail('An unverified notice address must be rejected.');
        } catch (AccountDeletionException $exception) {
            $this->assertSame('ACCOUNT_DELETION_NO_NOTICE_CHANNEL', $exception->errorCode);
        }

        $user = $this->user('manual@example.test', true);
        $state = $this->activateFromCheckout($user);

        $this->assertSame('waiting_obligations', $state->status);
        $this->assertSame('checkout', $state->source);
        $this->assertSame(CustomerAccountDeletionService::COPY_CHECKOUT, $state->copy_version);
        $this->assertSame(1, DB::table('customer_account_deletion_states')->where('user_id', $user->id)->count());
        $this->assertSame(1, DB::table('customer_account_deletion_events')->where('event_type', 'request_accepted')->count());
        $notice = CustomerAccountDeletionNotice::query()->where('request_id', $state->request_id)->firstOrFail();
        $this->assertSame('manual@example.test', Crypt::decryptString($notice->recipient_ciphertext));
        $this->assertStringNotContainsString('manual@example.test', (string) $notice->recipient_ciphertext);

        $cancelled = $this->service->cancelForUser($user);
        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame(1, DB::table('customer_account_deletion_states')->where('user_id', $user->id)->count());
        $this->assertFalse($this->service->projection($cancelled)['active']);
        $notice->refresh();
        $this->assertSame('cancelled', $notice->status);
        $this->assertNull($notice->recipient_ciphertext);
    }

    public function test_successful_unchecked_order_pauses_without_cancelling_and_failed_order_preserves_request(): void
    {
        $this->flag('nezha_account_deletion_intake_enabled', true);
        $user = $this->user('unchecked-order@example.test', true);
        $state = $this->activateFromCheckout($user);

        $newOrderId = DB::transaction(function () use ($user) {
            $gate = $this->service->lockForOrder($user, false);
            $orderId = DB::table('orders')->insertGetId([
                'user_id' => $user->id,
                'is_guest' => 0,
                'order_status' => 'pending',
                'order_amount' => 123,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->service->finalizeCreatedOrder(
                $user,
                Order::query()->findOrFail($orderId),
                $gate,
                false
            );

            return $orderId;
        }, 5);
        $paused = CustomerAccountDeletionState::findOrFail($state->id);
        $this->assertSame('waiting_obligations', $paused->status);
        $this->assertSame($state->request_id, $paused->request_id);
        $this->assertSame(1, CustomerAccountDeletionEvent::query()
            ->where('event_type', 'obligation_created_by_order')
            ->where('request_id', $state->request_id)
            ->count());

        $replacement = $this->activateFromCheckout($user);
        $replacementRequestId = $replacement->request_id;
        $orderCount = DB::table('orders')->where('user_id', $user->id)->count();
        try {
            DB::transaction(function () use ($user) {
                $gate = $this->service->lockForOrder($user, false);
                $orderId = DB::table('orders')->insertGetId([
                    'user_id' => $user->id,
                    'is_guest' => 0,
                    'order_status' => 'pending',
                    'order_amount' => 456,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->service->finalizeCreatedOrder(
                    $user,
                    Order::query()->findOrFail($orderId),
                    $gate,
                    false
                );
                throw new \RuntimeException('simulate failed order transaction');
            }, 5);
        } catch (\RuntimeException $exception) {
            $this->assertSame('simulate failed order transaction', $exception->getMessage());
        }
        $preserved = CustomerAccountDeletionState::findOrFail($state->id);
        $this->assertSame($replacementRequestId, $preserved->request_id);
        $this->assertSame('waiting_obligations', $preserved->status);
        $this->assertSame($orderCount, DB::table('orders')->where('user_id', $user->id)->count());
        $this->assertTrue(DB::table('orders')->where('id', $newOrderId)->exists());
    }

    public function test_successful_checked_order_reaffirms_the_same_request(): void
    {
        $this->flag('nezha_account_deletion_intake_enabled', true);
        $user = $this->user('checked-order@example.test', true);
        $first = $this->activateFromCheckout($user);

        $second = DB::transaction(function () use ($user) {
            $gate = $this->service->lockForOrder($user, true);
            $orderId = DB::table('orders')->insertGetId([
                'user_id' => $user->id,
                'is_guest' => 0,
                'order_status' => 'pending',
                'order_amount' => 321,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $this->service->finalizeCreatedOrder(
                $user,
                Order::query()->findOrFail($orderId),
                $gate,
                true,
                CustomerAccountDeletionService::COPY_CHECKOUT,
                'zh-CN'
            );
        }, 5);

        $this->assertSame($first->request_id, $second->request_id);
        $this->assertNotSame($first->source_order_id, $second->source_order_id);
        $this->assertSame('waiting_obligations', $second->status);
        $this->assertSame(1, CustomerAccountDeletionEvent::query()
            ->where('event_type', 'request_reaffirmed_by_order')
            ->where('request_id', $first->request_id)
            ->count());
        $this->assertSame(1, CustomerAccountDeletionEvent::query()
            ->where('event_type', 'request_accepted')
            ->count());
    }

    public function test_active_state_allows_order_lock_but_keeps_contact_guard_and_login_choices(): void
    {
        $this->flag('nezha_account_deletion_intake_enabled', true);
        $user = $this->user('challenge@example.test', true);
        $state = $this->activateFromCheckout($user);

        $this->assertSame($state->id, DB::transaction(
            fn () => $this->service->lockForOrder($user, false)?->id,
            5
        ));
        try {
            $this->service->withContactChangeGuard($user, fn () => true);
            $this->fail('The contact-change fence must block the final write.');
        } catch (AccountDeletionException $exception) {
            $this->assertSame('ACCOUNT_DELETION_CANCEL_BEFORE_CONTACT_CHANGE', $exception->errorCode);
            $this->assertSame('自动注销期间不能修改手机号或邮箱，请先取消注销。', $exception->getMessage());
        }

        $challenge = $this->service->issueLoginChallenge($user, 'test-context');
        $this->assertSame(['cancel_and_login', 'keep_and_exit'], $challenge['actions']);
        $this->service->resolveLoginChallenge($state->request_id, $challenge['challenge'], 'test-context', false);
        try {
            $this->service->resolveLoginChallenge($state->request_id, $challenge['challenge'], 'test-context', true);
            $this->fail('A consumed challenge must not be replayable.');
        } catch (AccountDeletionException $exception) {
            $this->assertSame('ACCOUNT_DELETION_CHALLENGE_REPLAYED', $exception->errorCode);
        }

        $second = $this->service->issueLoginChallenge($user, 'test-context');
        $resolved = $this->service->resolveLoginChallenge($state->request_id, $second['challenge'], 'test-context', true);
        $this->assertSame($user->id, $resolved->id);
        $this->assertSame('cancelled', CustomerAccountDeletionState::findOrFail($state->id)->status);
    }

    public function test_execution_revokes_sessions_retains_transaction_and_isolates_reregistration(): void
    {
        foreach (['nezha_account_deletion_intake_enabled', 'nezha_account_deletion_countdown_enabled', 'nezha_account_deletion_execution_enabled', 'nezha_account_deletion_purge_enabled'] as $flag) {
            $this->flag($flag, true);
        }
        $user = $this->user('old@example.test', true, '+37499123456');
        $state = $this->activateFromCheckout($user);
        $this->assertNull($state->sessions_revoke_requested_at);
        $this->assertNull($state->sessions_revoked_at);
        DB::table('orders')->where('id', $state->source_order_id)->update([
            'order_status' => 'delivered',
            'order_amount' => 999,
            'delivery_address_id' => 55,
            'delivery_address' => json_encode(['contact_person_number' => '+37499123456']),
            'order_note' => 'door code',
            'unavailable_item_note' => 'call me',
            'delivery_instruction' => 'floor 4',
        ]);
        DB::table('customer_addresses')->insert(['user_id' => $user->id, 'address' => 'old address']);
        DB::table('carts')->insert(['user_id' => $user->id]);
        DB::table('user_external_identities')->insert(['user_id' => $user->id, 'provider' => 'telegram']);
        $customerInfoId = DB::table('user_infos')->where('user_id', $user->id)->value('id');
        $conversationId = DB::table('conversations')->insertGetId([
            'sender_id' => $customerInfoId,
            'sender_type' => 'customer',
            'receiver_id' => 1,
            'receiver_type' => 'App\\Models\\Admin',
        ]);
        DB::table('messages')->insert(['conversation_id' => $conversationId, 'message' => 'private']);
        DB::table('oauth_access_tokens')->insert(['id' => 'access-1', 'user_id' => $user->id, 'revoked' => 0]);
        DB::table('oauth_refresh_tokens')->insert(['id' => 'refresh-1', 'access_token_id' => 'access-1', 'revoked' => 0]);
        DB::table('restaurant_reports')->insert(['user_id' => $user->id, 'description' => 'excluded']);
        DB::table('nezha_review_reports')->insert(['user_id' => $user->id, 'detail' => 'excluded']);
        DB::table('local_life_reports')->insert(['user_id' => $user->id, 'detail' => 'excluded']);

        $this->service->reconcileOne($state->request_id);
        $countdown = CustomerAccountDeletionState::findOrFail($state->id);
        $this->assertSame('countdown', $countdown->status);
        $this->assertNotNull($countdown->sessions_revoke_requested_at);
        $this->assertNull($countdown->sessions_revoked_at);
        $this->service->revokePendingSessions();
        DB::table('customer_account_deletion_states')->where('id', $state->id)->update(['scheduled_for' => now()->subSecond()]);

        $this->assertSame(1, $this->service->executeDue());
        $completed = CustomerAccountDeletionState::findOrFail($state->id);
        $this->assertSame('completed', $completed->status);
        $this->assertSame(1, (int) DB::table('oauth_access_tokens')->where('id', 'access-1')->value('revoked'));
        $this->assertSame(1, (int) DB::table('oauth_refresh_tokens')->where('id', 'refresh-1')->value('revoked'));
        $oldUser = DB::table('users')->where('id', $user->id)->first();
        $this->assertSame(0, (int) $oldUser->status);
        $this->assertNull($oldUser->email);
        $this->assertNull($oldUser->phone);
        $retainedOrder = DB::table('orders')->where('id', $state->source_order_id)->first();
        $this->assertSame(999.0, (float) $retainedOrder->order_amount);
        $this->assertSame('delivered', $retainedOrder->order_status);
        $this->assertNull($retainedOrder->delivery_address);
        $this->assertSame(0, DB::table('customer_addresses')->where('user_id', $user->id)->count());
        $this->assertSame(0, DB::table('conversations')->where('id', $conversationId)->count());
        $this->assertNull(DB::table('restaurant_reports')->where('id', 1)->value('description'));
        $this->assertNull(DB::table('restaurant_reports')->where('id', 1)->value('user_id'));
        $this->assertNull(DB::table('nezha_review_reports')->where('id', 1)->value('detail'));
        $this->assertNull(DB::table('local_life_reports')->where('id', 1)->value('detail'));
        $notice = CustomerAccountDeletionNotice::query()->where('request_id', $state->request_id)->firstOrFail();
        $this->assertSame('pending_send', $notice->status);
        $this->assertNotNull($notice->legal_due_at);
        Mail::fake();
        $this->assertSame(1, $this->service->deliverPendingNotices());
        Mail::assertSent(CustomerAccountDeletionCompletedMail::class, 1);
        $notice->refresh();
        $this->assertSame('sent', $notice->status);
        $this->assertNull($notice->recipient_ciphertext);
        $this->assertSame(0, $this->service->deliverPendingNotices());
        Mail::assertSent(CustomerAccountDeletionCompletedMail::class, 1);

        $newUserId = DB::table('users')->insertGetId($this->userRow('old@example.test', '+37499123456', true));
        $this->assertNotSame($user->id, $newUserId);
        $this->assertSame(0, DB::table('orders')->where('user_id', $newUserId)->count());
    }

    public function test_purge_switch_prevents_any_irreversible_account_close(): void
    {
        $this->flag('nezha_account_deletion_intake_enabled', true);
        $this->flag('nezha_account_deletion_countdown_enabled', true);
        $this->flag('nezha_account_deletion_execution_enabled', true);
        $user = $this->user('paused-purge@example.test', true);
        $state = $this->activateFromCheckout($user);
        DB::table('orders')->where('id', $state->source_order_id)->update(['order_status' => 'delivered']);
        $this->service->reconcileOne($state->request_id);
        $this->service->revokePendingSessions();
        DB::table('customer_account_deletion_states')->where('id', $state->id)->update(['scheduled_for' => now()->subSecond()]);

        $this->assertSame(0, $this->service->executeDue());
        $paused = CustomerAccountDeletionState::findOrFail($state->id);
        $this->assertSame('countdown', $paused->status);
        $this->assertNull($paused->account_closed_at);
        $this->assertNull($paused->purge_completed_at);

        $this->flag('nezha_account_deletion_purge_enabled', true);
        $this->service->executeDue();
        $this->assertSame('completed', CustomerAccountDeletionState::findOrFail($state->id)->status);
    }

    public function test_source_contract_has_four_order_guards_four_switches_and_notice_outbox(): void
    {
        $root = base_path();
        foreach ([
            'app/Http/Controllers/Api/V1/OrderController.php',
            'app/Http/Controllers/Admin/POSController.php',
            'app/Http/Controllers/Vendor/POSController.php',
            'app/Http/Controllers/Api/V1/Vendor/POSController.php',
        ] as $path) {
            $source = file_get_contents($root.'/'.$path);
            $this->assertStringContainsString('lockForOrder', $source, $path);
            $this->assertStringContainsString('finalizeCreatedOrder', $source, $path);
        }

        $configSource = file_get_contents($root.'/app/Http/Controllers/Api/V1/ConfigController.php');
        $this->assertGreaterThanOrEqual(2, substr_count($configSource, 'nezha_account_deletion_intake_enabled'));
        $this->assertStringNotContainsString('nezha_account_deletion_profile_intake_enabled', $configSource);
        $controllerSource = file_get_contents($root.'/app/Http/Controllers/Api/V1/CustomerController.php');
        $this->assertStringContainsString('ACCOUNT_DELETION_CHECKOUT_ONLY', $controllerSource);
        $this->assertStringContainsString('withContactChangeGuard', $controllerSource);

        $serviceSource = file_get_contents($root.'/app/Services/CustomerAccountDeletion/CustomerAccountDeletionService.php');
        $this->assertStringContainsString('nezha_account_deletion_countdown_enabled', $serviceSource);
        $this->assertStringContainsString('CustomerAccountDeletionNotice', $serviceSource);
        $this->assertStringContainsString('Mail::to', $serviceSource);
        $this->assertStringContainsString("'recipient_ciphertext' => null", $serviceSource);
        $this->assertStringNotContainsString('request_cancelled_by_order', $serviceSource);
    }

    private function activateFromCheckout(User $user): CustomerAccountDeletionState
    {
        return DB::transaction(function () use ($user) {
            $this->service->assertValidCheckoutRequest($user, true, CustomerAccountDeletionService::COPY_CHECKOUT);
            $state = $this->service->lockForOrder($user, true);
            $orderId = DB::table('orders')->insertGetId([
                'user_id' => $user->id,
                'is_guest' => 0,
                'order_status' => 'pending',
                'order_amount' => 10,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $this->service->finalizeCreatedOrder(
                $user,
                Order::query()->findOrFail($orderId),
                $state,
                true,
                CustomerAccountDeletionService::COPY_CHECKOUT,
                'zh-CN'
            );
        }, 5);
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_07_22_090000_create_customer_account_deletion_lifecycle.php');
    }

    private function flag(string $key, bool $enabled): void
    {
        DB::table('business_settings')->where('key', $key)->update(['value' => $enabled ? '1' : '0']);
    }

    private function user(string $email, bool $verified, ?string $phone = null): User
    {
        $id = DB::table('users')->insertGetId($this->userRow($email, $phone, $verified));
        DB::table('user_infos')->insert([
            'user_id' => $id,
            'email' => $email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::query()->without('storage')->findOrFail($id);
    }

    private function userRow(string $email, ?string $phone, bool $verified): array
    {
        return [
            'f_name' => 'Test',
            'l_name' => 'Customer',
            'email' => $email,
            'phone' => $phone,
            'password' => bcrypt('secret'),
            'status' => 1,
            'is_phone_verified' => $phone ? 1 : 0,
            'is_email_verified' => $verified ? 1 : 0,
            'email_verified_at' => $verified ? now() : null,
            'wallet_balance' => 0,
            'loyalty_point' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function createSchema(): void
    {
        foreach ([
            'customer_account_deletion_notices', 'customer_account_deletion_events', 'customer_account_deletion_states',
            'messages', 'conversations', 'user_infos', 'user_external_identities', 'oauth_refresh_tokens',
            'oauth_access_tokens', 'local_life_reports', 'nezha_review_reports', 'restaurant_reports',
            'nezha_cs_tickets', 'nezha_delivery_appeals', 'nezha_refund_records', 'offline_payments',
            'subscriptions', 'coupon_claims', 'nezha_cart_events', 'recent_searches', 'visitor_logs',
            'user_notifications', 'wishlists', 'carts', 'customer_addresses', 'orders', 'storages',
            'users', 'business_settings',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key');
            $table->text('value')->nullable();
            $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('phone')->nullable()->unique();
            $table->string('email')->nullable();
            $table->string('image')->nullable();
            $table->boolean('status')->default(1);
            $table->boolean('is_phone_verified')->default(0);
            $table->boolean('is_email_verified')->default(0);
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('email_verification_token')->nullable();
            $table->string('cm_firebase_token')->nullable();
            $table->json('notification_preferences')->nullable();
            $table->rememberToken();
            $table->string('social_id')->nullable();
            $table->string('login_medium')->nullable();
            $table->decimal('wallet_balance', 20, 4)->default(0);
            $table->integer('loyalty_point')->default(0);
            $table->timestamps();
        });
        Schema::create('storages', function (Blueprint $table) {
            $table->id();
            $table->string('data_type');
            $table->unsignedBigInteger('data_id');
            $table->string('key');
            $table->string('value')->nullable();
            $table->timestamps();
        });
        Schema::create('user_infos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('email')->nullable();
            $table->timestamps();
        });
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->boolean('is_guest')->default(0);
            $table->string('order_status')->default('pending');
            $table->decimal('order_amount', 20, 3)->default(0);
            $table->unsignedBigInteger('delivery_address_id')->nullable();
            $table->text('delivery_address')->nullable();
            $table->text('order_note')->nullable();
            $table->string('unavailable_item_note')->nullable();
            $table->text('delivery_instruction')->nullable();
            $table->timestamps();
        });
        foreach (['customer_addresses', 'carts', 'wishlists', 'user_notifications', 'visitor_logs', 'recent_searches', 'nezha_cart_events', 'coupon_claims'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table) use ($tableName) {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                if ($tableName === 'customer_addresses') {
                    $table->string('address')->nullable();
                }
                $table->timestamps();
            });
        }
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('status');
            $table->timestamps();
        });
        Schema::create('offline_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->index();
            $table->string('status')->default('pending');
            $table->json('payment_info')->nullable();
            $table->text('note')->nullable();
            $table->text('customer_note')->nullable();
            $table->text('method_fields')->nullable();
            $table->timestamps();
        });
        Schema::create('nezha_refund_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('guest_id')->nullable();
            $table->string('status');
            $table->string('reason_note')->nullable();
            $table->string('route_locked_note')->nullable();
            $table->string('original_tx_hash')->nullable();
            $table->string('locked_to_address')->nullable();
            $table->string('refund_tx_hash')->nullable();
            $table->json('chain_verify_detail')->nullable();
            $table->string('refund_proof_image')->nullable();
            $table->json('risk_hit')->nullable();
            $table->string('review_note')->nullable();
            $table->string('merchant_refund_note')->nullable();
            $table->timestamps();
        });
        Schema::create('nezha_delivery_appeals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('status');
            $table->text('detail')->nullable();
            $table->json('evidence')->nullable();
            $table->text('admin_note')->nullable();
            $table->timestamps();
        });
        Schema::create('nezha_cs_tickets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('conversation_id')->nullable();
            $table->string('status');
            $table->string('note')->nullable();
            $table->timestamps();
        });
        foreach (['restaurant_reports', 'nezha_review_reports', 'local_life_reports'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table) use ($tableName) {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                if ($tableName === 'restaurant_reports') {
                    $table->string('guest_id')->nullable();
                }
                $table->text($tableName === 'restaurant_reports' ? 'description' : 'detail')->nullable();
                $table->timestamps();
            });
        }
        Schema::create('oauth_access_tokens', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->boolean('revoked')->default(0);
        });
        Schema::create('oauth_refresh_tokens', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('access_token_id')->index();
            $table->boolean('revoked')->default(0);
        });
        Schema::create('user_external_identities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('provider');
        });
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sender_id')->nullable();
            $table->string('sender_type')->nullable();
            $table->unsignedBigInteger('receiver_id')->nullable();
            $table->string('receiver_type')->nullable();
        });
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('conversation_id')->index();
            $table->text('message')->nullable();
        });
    }
}
