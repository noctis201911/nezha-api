<?php

namespace Tests\Feature;

use App\CentralLogics\Helpers;
use App\CentralLogics\VendorDeviceTokenSessions;
use App\Jobs\DeliverVendorOrderAlarmJob;
use App\Jobs\SendTelegramMessageJob;
use App\Mail\NezhaMerchantNewOrderMail;
use App\Models\Vendor;
use App\Models\VendorEmployee;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class NezhaVendorAlarmDeliveryTest extends TestCase
{
    use DatabaseTransactions;

    public function test_vendor_android_app_registers_an_encrypted_device_token(): void
    {
        $vendor = Vendor::query()->findOrFail(1);
        $this->withoutMiddleware();
        $this->actingAs($vendor, 'vendor');

        $response = $this->postJson('/restaurant-panel/nezha-alarm-token/register', [
            'token' => 'merchant-android-fcm-token-00000001',
            'platform' => 'android',
        ]);

        $response->assertOk()->assertJsonPath('message', 'ok');
        $row = DB::table('vendor_device_tokens')->where('vendor_id', 1)->first();
        $this->assertNotNull($row);
        $this->assertSame(hash('sha256', 'merchant-android-fcm-token-00000001'), $row->token_hash);
        $this->assertNotSame('merchant-android-fcm-token-00000001', $row->fcm_token);
        $this->assertSame('merchant-android-fcm-token-00000001', Crypt::decryptString($row->fcm_token));
        $this->assertSame('android', $row->platform);
        $this->assertSame(1, (int) $row->is_active);
        $this->assertSame(
            hash('sha256', 'merchant-android-fcm-token-00000001'),
            session()->get(VendorDeviceTokenSessions::SESSION_KEY)
        );
    }

    public function test_explicit_vendor_logout_deactivates_only_the_current_app_device(): void
    {
        $vendor = Vendor::query()->findOrFail(1);
        $this->withoutMiddleware();
        $this->actingAs($vendor, 'vendor');

        $currentToken = 'merchant-android-fcm-token-logout-current';
        $otherToken = 'merchant-android-fcm-token-logout-other';
        $this->postJson('/restaurant-panel/nezha-alarm-token/register', [
            'token' => $currentToken,
            'platform' => 'android',
        ])->assertOk();
        DB::table('vendor_device_tokens')->insert([
            'vendor_id' => 1,
            'vendor_employee_id' => null,
            'fcm_token' => Crypt::encryptString($otherToken),
            'token_hash' => hash('sha256', $otherToken),
            'platform' => 'android',
            'is_active' => 1,
            'last_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->get('/logout')->assertRedirect();

        $this->assertDatabaseHas('vendor_device_tokens', [
            'vendor_id' => 1,
            'token_hash' => hash('sha256', $currentToken),
            'is_active' => 0,
        ]);
        $this->assertDatabaseHas('vendor_device_tokens', [
            'vendor_id' => 1,
            'token_hash' => hash('sha256', $otherToken),
            'is_active' => 1,
        ]);
        $this->assertFalse(session()->has(VendorDeviceTokenSessions::SESSION_KEY));
    }

    public function test_explicit_vendor_employee_logout_deactivates_the_current_app_device(): void
    {
        DB::table('vendor_employees')->insert([
            'id' => 31,
            'f_name' => 'Fixture',
            'l_name' => 'Employee',
            'email' => 'fixture-vendor-employee@example.test',
            'employee_role_id' => 1,
            'vendor_id' => 1,
            'restaurant_id' => 6,
            'password' => 'not-used',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $employee = VendorEmployee::query()->findOrFail(31);
        $this->withoutMiddleware();
        $this->actingAs($employee, 'vendor_employee');

        $token = 'merchant-android-fcm-token-employee-logout';
        $this->postJson('/restaurant-panel/nezha-alarm-token/register', [
            'token' => $token,
            'platform' => 'android',
        ])->assertOk();

        $this->get('/logout')->assertRedirect();

        $this->assertDatabaseHas('vendor_device_tokens', [
            'vendor_id' => 1,
            'vendor_employee_id' => 31,
            'token_hash' => hash('sha256', $token),
            'is_active' => 0,
        ]);
        $this->assertFalse(session()->has(VendorDeviceTokenSessions::SESSION_KEY));
    }

    public function test_deregister_deactivates_and_forgets_the_current_app_device(): void
    {
        $vendor = Vendor::query()->findOrFail(1);
        $this->withoutMiddleware();
        $this->actingAs($vendor, 'vendor');

        $token = 'merchant-android-fcm-token-deregister';
        $this->postJson('/restaurant-panel/nezha-alarm-token/register', [
            'token' => $token,
            'platform' => 'android',
        ])->assertOk();

        $this->postJson('/restaurant-panel/nezha-alarm-token/deregister', [
            'token' => $token,
        ])->assertOk();

        $this->assertDatabaseHas('vendor_device_tokens', [
            'vendor_id' => 1,
            'token_hash' => hash('sha256', $token),
            'is_active' => 0,
        ]);
        $this->assertFalse(session()->has(VendorDeviceTokenSessions::SESSION_KEY));
    }

    public function test_old_session_logout_cannot_deactivate_a_token_rebound_to_another_vendor(): void
    {
        $vendor = Vendor::query()->findOrFail(1);
        $this->withoutMiddleware();
        $this->actingAs($vendor, 'vendor');

        $token = 'merchant-android-fcm-token-rebound';
        $this->postJson('/restaurant-panel/nezha-alarm-token/register', [
            'token' => $token,
            'platform' => 'android',
        ])->assertOk();
        DB::table('vendor_device_tokens')
            ->where('token_hash', hash('sha256', $token))
            ->update(['vendor_id' => 2]);

        $this->get('/logout')->assertRedirect();

        $this->assertDatabaseHas('vendor_device_tokens', [
            'vendor_id' => 2,
            'token_hash' => hash('sha256', $token),
            'is_active' => 1,
        ]);
    }

    public function test_new_order_alarm_is_queued_off_the_order_request_path(): void
    {
        Queue::fake();
        Http::fake();
        DB::table('business_settings')->updateOrInsert(
            ['key' => 'nezha_alert_push_status'],
            ['value' => '1', 'created_at' => now(), 'updated_at' => now()]
        );
        Config::set('nezha_alert_push_status_conf', ['value' => '1']);
        $order = (object) [
            'id' => 990001,
            'restaurant_id' => 6,
            'order_status' => 'pending',
            'restaurant' => (object) ['id' => 6, 'vendor_id' => 1],
        ];

        Helpers::dispatchVendorOrderAlarm($order);

        Queue::assertPushed(DeliverVendorOrderAlarmJob::class, function (DeliverVendorOrderAlarmJob $job): bool {
            return $job->orderId === 990001
                && $job->vendorId === 1
                && $job->connection === 'redis';
        });
        Http::assertNothingSent();
        $this->assertDatabaseHas('vendor_alert_outbox', [
            'order_id' => 990001,
            'status' => 'queued',
            'attempts' => 0,
        ]);
    }

    public function test_worker_fans_out_to_every_active_merchant_android_device(): void
    {
        $clientEmail = 'merchant-app-test@example.test';
        Config::set('push_notification_service_file_content_conf', ['value' => [
            'project_id' => 'merchant-app-test-project',
            'client_email' => $clientEmail,
            'private_key' => 'unused-because-access-token-is-cached',
        ]]);
        Cache::put('nezha_fcm_access_token_'.md5($clientEmail), 'fake-oauth-token', now()->addMinutes(5));
        Http::fake([
            'https://fcm.googleapis.com/*' => Http::response(['name' => 'fake-message-id'], 200),
        ]);
        foreach (['merchant-device-token-00000001', 'merchant-device-token-00000002'] as $token) {
            DB::table('vendor_device_tokens')->insert([
                'vendor_id' => 1,
                'vendor_employee_id' => null,
                'fcm_token' => Crypt::encryptString($token),
                'token_hash' => hash('sha256', $token),
                'platform' => 'android',
                'is_active' => 1,
                'last_seen_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        DB::table('vendor_alert_outbox')->insert([
            'order_id' => 990002,
            'restaurant_id' => 6,
            'status' => 'queued',
            'attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $order = (object) [
            'id' => 990002,
            'restaurant_id' => 6,
            'order_status' => 'pending',
        ];

        $this->assertTrue(Helpers::deliverVendorAlarmForOrderNow($order, 1));

        Http::assertSentCount(2);
        foreach (['merchant-device-token-00000001', 'merchant-device-token-00000002'] as $token) {
            Http::assertSent(fn ($request): bool => data_get($request->data(), 'message.token') === $token);
        }
        $this->assertDatabaseHas('vendor_alert_outbox', [
            'order_id' => 990002,
            'status' => 'sent',
            'attempts' => 1,
            'last_error' => null,
        ]);
    }

    public function test_new_order_email_and_telegram_are_queued_for_the_merchant_role(): void
    {
        Mail::fake();
        Queue::fake();
        Config::set('mail.status', true);
        Config::set('telegram_bot_token_conf', ['value' => 'fake-telegram-bot-token']);
        Config::set('nezha_notif_async_status_conf', ['value' => '1']);
        DB::table('restaurant_notification_settings')->insert([
            'restaurant_id' => 6,
            'key' => 'restaurant_order_notification',
            'mail_status' => 'active',
            'sms_status' => 'inactive',
            'push_notification_status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $restaurant = (object) [
            'id' => 6,
            'name' => 'Fixture Restaurant',
            'email' => 'restaurant@example.test',
            'nezha_notify_email' => 'orders@example.test',
            'telegram_chat_id' => '123456789',
            'timeout_notify_telegram' => 1,
        ];
        $order = (object) [
            'id' => 990003,
            'restaurant_id' => 6,
            'restaurant' => $restaurant,
            'order_status' => 'pending',
            'order_type' => 'take_away',
            'order_amount' => 1250,
            'created_at' => now()->toDateTimeString(),
            'details' => [],
        ];

        Helpers::sendMerchantNewOrderEmail($order);
        Helpers::sendTelegramOrderAlert($order);

        Mail::assertQueued(NezhaMerchantNewOrderMail::class, function (NezhaMerchantNewOrderMail $mail): bool {
            return $mail->hasTo('orders@example.test')
                && $mail->orderId === 990003
                && $mail->connection === 'redis';
        });
        Queue::assertPushed(SendTelegramMessageJob::class, function (SendTelegramMessageJob $job): bool {
            return $job->chatId === '123456789'
                && str_contains($job->text, '#990003');
        });
    }
}
