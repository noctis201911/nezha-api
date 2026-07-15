<?php

namespace Tests\Feature;

use App\CentralLogics\CustomerNotificationInstallations;
use App\CentralLogics\Helpers;
use App\Jobs\SendPushNotificationJob;
use App\Jobs\SendWebPushNotificationJob;
use App\Models\CustomerNotificationInstallation;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\Token;
use Tests\TestCase;

class NezhaCustomerNotificationInstallationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_authenticated_customer_registers_an_installation_that_receives_push(): void
    {
        Queue::fake();
        Config::set('nezha_notif_async_status_conf', ['value' => '1']);
        $customer = User::query()->findOrFail(1);
        $this->withoutMiddleware();
        $this->actingAs($customer);

        $response = $this->putJson('/api/v1/customer/cm-firebase-token', [
            'installation_id' => 'web-installation-0001',
            'cm_firebase_token' => 'fcm-token-customer-0001',
            'platform' => 'android_chrome',
        ]);

        $response->assertOk()->assertJsonPath('data.installation_id', 'web-installation-0001');

        $installation = CustomerNotificationInstallation::query()
            ->where('installation_id', 'web-installation-0001')
            ->firstOrFail();
        $this->assertSame('fcm-token-customer-0001', $installation->token);
        $this->assertNotSame('fcm-token-customer-0001', $installation->getRawOriginal('token'));

        Helpers::send_push_notif_to_customer($customer->fresh(), [
            'title' => '订单通知',
            'description' => '订单状态有更新',
            'image' => '',
            'order_id' => '1001',
            'type' => 'order_status',
        ]);

        Queue::assertPushed(SendPushNotificationJob::class, function (SendPushNotificationJob $job): bool {
            return data_get($job->payload, 'message.token') === 'fcm-token-customer-0001';
        });
    }

    public function test_ios_home_screen_web_push_subscription_is_registered_without_overwriting_fcm_legacy_token(): void
    {
        $customer = User::query()->findOrFail(1);
        $customer->forceFill(['cm_firebase_token' => 'existing-android-fcm-token'])->save();
        $this->withoutMiddleware();
        $this->actingAs($customer);

        $response = $this->putJson('/api/v1/customer/web-push-subscription', [
            'installation_id' => 'ios-home-screen-installation-0001',
            'platform' => 'ios_web',
            'subscription' => [
                'endpoint' => 'https://web.push.apple.com/QHh5V3-valid-endpoint',
                'keys' => [
                    'p256dh' => 'valid-p256dh-key-material',
                    'auth' => 'valid-auth-secret',
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.installation_id', 'ios-home-screen-installation-0001')
            ->assertJsonPath('data.transport', 'web_push');

        $installation = CustomerNotificationInstallation::query()
            ->where('installation_id', 'ios-home-screen-installation-0001')
            ->firstOrFail();
        $this->assertSame('web_push', $installation->transport);
        $this->assertSame('ios_web', $installation->platform);
        $this->assertSame(
            'https://web.push.apple.com/QHh5V3-valid-endpoint',
            json_decode($installation->token, true, flags: JSON_THROW_ON_ERROR)['endpoint']
        );
        $this->assertStringNotContainsString('web.push.apple.com', $installation->getRawOriginal('token'));
        $this->assertSame('existing-android-fcm-token', $customer->fresh()->cm_firebase_token);
    }

    public function test_customer_push_fans_out_to_android_fcm_and_ios_standard_web_push(): void
    {
        Queue::fake();
        Config::set('nezha_notif_async_status_conf', ['value' => '1']);
        $customer = User::query()->findOrFail(1);
        $this->withoutMiddleware();
        $this->actingAs($customer);

        $this->putJson('/api/v1/customer/cm-firebase-token', [
            'installation_id' => 'android-chrome-installation-0001',
            'cm_firebase_token' => 'android-chrome-fcm-token',
            'platform' => 'android_chrome',
        ])->assertOk();
        $this->putJson('/api/v1/customer/web-push-subscription', [
            'installation_id' => 'ios-home-screen-installation-0001',
            'platform' => 'ios_web',
            'subscription' => [
                'endpoint' => 'https://web.push.apple.com/QHh5V3-valid-endpoint',
                'keys' => [
                    'p256dh' => 'valid-p256dh-key-material',
                    'auth' => 'valid-auth-secret',
                ],
            ],
        ])->assertOk();

        Helpers::send_push_notif_to_customer($customer->fresh(), [
            'title' => 'Order update',
            'description' => 'The order status changed.',
            'image' => '',
            'order_id' => '1001',
            'type' => 'order_status',
        ]);

        Queue::assertPushed(SendPushNotificationJob::class, 1);
        Queue::assertPushed(SendWebPushNotificationJob::class, function (SendWebPushNotificationJob $job): bool {
            return $job->installationId === 'ios-home-screen-installation-0001'
                && data_get($job->subscription, 'endpoint') === 'https://web.push.apple.com/QHh5V3-valid-endpoint'
                && $job->connection === 'redis';
        });
    }

    public function test_customer_push_fans_out_to_each_active_installation(): void
    {
        Queue::fake();
        Config::set('nezha_notif_async_status_conf', ['value' => '1']);
        $customer = User::query()->findOrFail(1);
        $this->withoutMiddleware();
        $this->actingAs($customer);

        foreach ([
            ['installation_id' => 'web-installation-0001', 'cm_firebase_token' => 'fcm-token-customer-0001'],
            ['installation_id' => 'web-installation-0002', 'cm_firebase_token' => 'fcm-token-customer-0002'],
        ] as $installation) {
            $this->putJson('/api/v1/customer/cm-firebase-token', $installation)->assertOk();
        }

        Helpers::send_push_notif_to_customer($customer->fresh(), [
            'title' => 'Order update',
            'description' => 'The order status changed.',
            'image' => '',
            'order_id' => '1001',
            'type' => 'order_status',
        ]);

        Queue::assertPushed(SendPushNotificationJob::class, 2);
        foreach (['fcm-token-customer-0001', 'fcm-token-customer-0002'] as $token) {
            Queue::assertPushed(SendPushNotificationJob::class, function (SendPushNotificationJob $job) use ($token): bool {
                return data_get($job->payload, 'message.token') === $token;
            });
        }
    }

    public function test_android_chrome_notification_payload_has_a_secure_click_target(): void
    {
        Queue::fake();
        Config::set('nezha_notif_async_status_conf', ['value' => '1']);
        Config::set('app.url', 'https://nezha.am');
        $customer = User::query()->findOrFail(1);
        $this->withoutMiddleware();
        $this->actingAs($customer);
        $this->putJson('/api/v1/customer/cm-firebase-token', [
            'installation_id' => 'android-click-target-installation',
            'cm_firebase_token' => 'android-click-target-token',
            'platform' => 'android_chrome',
        ])->assertOk();

        Helpers::send_push_notif_to_customer($customer->fresh(), [
            'title' => 'Order update',
            'description' => 'The order status changed.',
            'image' => '',
            'order_id' => '1001',
            'type' => 'order_status',
        ]);

        Queue::assertPushed(SendPushNotificationJob::class, function (SendPushNotificationJob $job): bool {
            return data_get($job->payload, 'message.webpush.fcm_options.link')
                === 'https://nezha.am/info?page=order&orderId=1001';
        });
    }

    public function test_customer_fanout_is_forced_off_the_request_path_when_the_global_async_switch_is_off(): void
    {
        Queue::fake();
        Http::fake();
        Config::set('nezha_notif_async_status_conf', ['value' => '0']);
        Config::set('queue.default', 'sync');
        $customer = User::query()->findOrFail(1);
        $this->withoutMiddleware();
        $this->actingAs($customer);

        foreach ([
            ['installation_id' => 'sync-safe-installation-0001', 'cm_firebase_token' => 'sync-safe-token-0001'],
            ['installation_id' => 'sync-safe-installation-0002', 'cm_firebase_token' => 'sync-safe-token-0002'],
        ] as $installation) {
            $this->putJson('/api/v1/customer/cm-firebase-token', $installation)->assertOk();
        }

        Helpers::send_push_notif_to_customer($customer->fresh(), [
            'title' => 'Order update',
            'description' => 'The order status changed.',
            'image' => '',
            'order_id' => '1001',
            'type' => 'order_status',
        ]);

        Queue::assertPushed(SendPushNotificationJob::class, 2);
        Queue::assertPushed(SendPushNotificationJob::class, function (SendPushNotificationJob $job): bool {
            return $job->connection === 'redis';
        });
        Http::assertNothingSent();
    }

    public function test_customer_push_candidates_include_legacy_only_and_modern_installations(): void
    {
        $legacyCustomer = User::query()->findOrFail(1);
        $legacyCustomer->forceFill(['cm_firebase_token' => 'legacy-only-token'])->save();
        $modernCustomer = User::query()->forceCreate([
            'id' => 2,
            'f_name' => 'Modern',
            'l_name' => 'Customer',
            'cm_firebase_token' => null,
        ]);
        CustomerNotificationInstallation::query()->create([
            'user_id' => $modernCustomer->id,
            'installation_id' => 'modern-candidate-installation',
            'transport' => 'fcm_web',
            'token' => 'modern-candidate-token',
            'token_hash' => hash('sha256', 'modern-candidate-token'),
            'last_seen_at' => now(),
        ]);
        User::query()->forceCreate([
            'id' => 3,
            'f_name' => 'No',
            'l_name' => 'Target',
            'cm_firebase_token' => '@',
        ]);

        $candidateIds = User::query()->withCustomerPushTarget()->pluck('id')->all();

        $this->assertContains($legacyCustomer->id, $candidateIds);
        $this->assertContains($modernCustomer->id, $candidateIds);
        $this->assertNotContains(3, $candidateIds);
    }

    public function test_logout_revokes_only_the_current_customer_installation(): void
    {
        Queue::fake();
        Config::set('nezha_notif_async_status_conf', ['value' => '1']);
        $customer = User::query()->findOrFail(1);
        $this->withoutMiddleware();
        $this->actingAs($customer);

        $this->putJson('/api/v1/customer/cm-firebase-token', [
            'installation_id' => 'shared-browser-installation',
            'cm_firebase_token' => 'fcm-token-shared-browser',
        ])->assertOk();

        $this->postJson('/api/v1/customer/logout', [
            'installation_id' => 'shared-browser-installation',
        ])->assertOk();

        Helpers::send_push_notif_to_customer($customer->fresh(), [
            'title' => 'Order update',
            'description' => 'The order status changed.',
            'image' => '',
            'order_id' => '1001',
            'type' => 'order_status',
        ]);

        Queue::assertNothingPushed();
    }

    public function test_logout_of_the_latest_installation_repoints_the_legacy_fallback_to_a_remaining_installation(): void
    {
        $customer = User::query()->findOrFail(1);
        $this->withoutMiddleware();
        $this->actingAs($customer);

        $this->putJson('/api/v1/customer/cm-firebase-token', [
            'installation_id' => 'remaining-installation',
            'cm_firebase_token' => 'remaining-installation-token',
        ])->assertOk();
        $this->putJson('/api/v1/customer/cm-firebase-token', [
            'installation_id' => 'latest-installation',
            'cm_firebase_token' => 'latest-installation-token',
        ])->assertOk();

        $this->postJson('/api/v1/customer/logout', [
            'installation_id' => 'latest-installation',
        ])->assertOk();

        $this->assertSame('remaining-installation-token', $customer->fresh()->cm_firebase_token);
        $this->assertDatabaseHas('customer_notification_installations', [
            'user_id' => $customer->id,
            'installation_id' => 'remaining-installation',
            'revoked_at' => null,
        ]);
    }

    public function test_shared_browser_rebind_prevents_the_previous_customer_receiving_push(): void
    {
        Queue::fake();
        Config::set('nezha_notif_async_status_conf', ['value' => '1']);
        $firstCustomer = User::query()->findOrFail(1);
        $secondCustomer = User::query()->forceCreate([
            'id' => 2,
            'f_name' => 'Second',
            'l_name' => 'Customer',
        ]);
        $this->withoutMiddleware();

        $this->actingAs($firstCustomer);
        $this->putJson('/api/v1/customer/cm-firebase-token', [
            'installation_id' => 'shared-browser-installation',
            'cm_firebase_token' => 'fcm-token-first-customer',
        ])->assertOk();

        $this->actingAs($secondCustomer);
        $this->putJson('/api/v1/customer/cm-firebase-token', [
            'installation_id' => 'shared-browser-installation',
            'cm_firebase_token' => 'fcm-token-second-customer',
        ])->assertOk();

        $this->actingAs($firstCustomer);
        $this->postJson('/api/v1/customer/logout', [
            'installation_id' => 'shared-browser-installation',
        ])->assertOk();

        $notification = [
            'title' => 'Order update',
            'description' => 'The order status changed.',
            'image' => '',
            'order_id' => '1001',
            'type' => 'order_status',
        ];
        Helpers::send_push_notif_to_customer($firstCustomer->fresh(), $notification);
        Helpers::send_push_notif_to_customer($secondCustomer->fresh(), $notification);

        Queue::assertPushed(SendPushNotificationJob::class, 1);
        Queue::assertPushed(SendPushNotificationJob::class, function (SendPushNotificationJob $job): bool {
            return data_get($job->payload, 'message.token') === 'fcm-token-second-customer';
        });
    }

    public function test_shared_browser_rebind_repoints_the_previous_customers_legacy_fallback(): void
    {
        $firstCustomer = User::query()->findOrFail(1);
        $secondCustomer = User::query()->forceCreate([
            'id' => 2,
            'f_name' => 'Second',
            'l_name' => 'Customer',
        ]);
        $this->withoutMiddleware();

        $this->actingAs($firstCustomer);
        $this->putJson('/api/v1/customer/cm-firebase-token', [
            'installation_id' => 'first-customers-private-installation',
            'cm_firebase_token' => 'first-customers-private-token',
        ])->assertOk();
        $this->putJson('/api/v1/customer/cm-firebase-token', [
            'installation_id' => 'shared-browser-installation',
            'cm_firebase_token' => 'shared-browser-first-token',
        ])->assertOk();

        $this->actingAs($secondCustomer);
        $this->putJson('/api/v1/customer/cm-firebase-token', [
            'installation_id' => 'shared-browser-installation',
            'cm_firebase_token' => 'shared-browser-second-token',
        ])->assertOk();

        $this->assertSame('first-customers-private-token', $firstCustomer->fresh()->cm_firebase_token);
        $this->assertSame('shared-browser-second-token', $secondCustomer->fresh()->cm_firebase_token);
    }

    public function test_existing_fcm_token_moves_to_a_recreated_installation_without_duplicate_delivery(): void
    {
        Queue::fake();
        Config::set('nezha_notif_async_status_conf', ['value' => '1']);
        $customer = User::query()->findOrFail(1);
        $this->withoutMiddleware();
        $this->actingAs($customer);

        $this->putJson('/api/v1/customer/cm-firebase-token', [
            'installation_id' => 'installation-before-storage-reset',
            'cm_firebase_token' => 'stable-fcm-token',
        ])->assertOk();

        $this->putJson('/api/v1/customer/cm-firebase-token', [
            'installation_id' => 'installation-after-storage-reset',
            'cm_firebase_token' => 'stable-fcm-token',
        ])->assertOk()->assertJsonPath('data.installation_id', 'installation-after-storage-reset');

        Helpers::send_push_notif_to_customer($customer->fresh(), [
            'title' => 'Order update',
            'description' => 'The order status changed.',
            'image' => '',
            'order_id' => '1001',
            'type' => 'order_status',
        ]);

        Queue::assertPushed(SendPushNotificationJob::class, 1);
    }

    public function test_token_rotation_replaces_the_old_token_for_the_same_installation(): void
    {
        Queue::fake();
        Config::set('nezha_notif_async_status_conf', ['value' => '1']);
        $customer = User::query()->findOrFail(1);
        $this->withoutMiddleware();
        $this->actingAs($customer);

        foreach (['old-fcm-token', 'rotated-fcm-token'] as $token) {
            $this->putJson('/api/v1/customer/cm-firebase-token', [
                'installation_id' => 'rotating-installation',
                'cm_firebase_token' => $token,
            ])->assertOk();
        }

        Helpers::send_push_notif_to_customer($customer->fresh(), [
            'title' => 'Order update',
            'description' => 'The order status changed.',
            'image' => '',
            'order_id' => '1001',
            'type' => 'order_status',
        ]);

        Queue::assertPushed(SendPushNotificationJob::class, 1);
        Queue::assertPushed(SendPushNotificationJob::class, function (SendPushNotificationJob $job): bool {
            return data_get($job->payload, 'message.token') === 'rotated-fcm-token';
        });
    }

    public function test_legacy_client_registration_without_installation_id_remains_deliverable(): void
    {
        Queue::fake();
        Config::set('nezha_notif_async_status_conf', ['value' => '1']);
        $customer = User::query()->findOrFail(1);
        $this->withoutMiddleware();
        $this->actingAs($customer);

        $this->putJson('/api/v1/customer/cm-firebase-token', [
            'cm_firebase_token' => 'legacy-fcm-token',
        ])->assertOk();

        $this->assertSame('legacy-fcm-token', $customer->fresh()->cm_firebase_token);

        Helpers::send_push_notif_to_customer($customer->fresh(), [
            'title' => 'Order update',
            'description' => 'The order status changed.',
            'image' => '',
            'order_id' => '1001',
            'type' => 'order_status',
        ]);

        Queue::assertPushed(SendPushNotificationJob::class, function (SendPushNotificationJob $job): bool {
            return data_get($job->payload, 'message.token') === 'legacy-fcm-token';
        });
    }

    public function test_legacy_client_logout_clears_the_fallback_token(): void
    {
        Queue::fake();
        Config::set('nezha_notif_async_status_conf', ['value' => '1']);
        $customer = User::query()->findOrFail(1);
        $customer->forceFill(['cm_firebase_token' => 'legacy-fcm-token'])->save();
        $this->withoutMiddleware();
        $this->actingAs($customer);

        $this->postJson('/api/v1/customer/logout')->assertOk();

        $this->assertNull($customer->fresh()->cm_firebase_token);
        Helpers::send_push_notif_to_customer($customer->fresh(), [
            'title' => 'Order update',
            'description' => 'The order status changed.',
            'image' => '',
            'order_id' => '1001',
            'type' => 'order_status',
        ]);
        Queue::assertNothingPushed();
    }

    public function test_revoked_late_access_token_cannot_restore_a_legacy_binding(): void
    {
        $firstCustomer = User::query()->findOrFail(1);
        $secondCustomer = User::query()->forceCreate([
            'id' => 2,
            'f_name' => 'Second',
            'l_name' => 'Customer',
            'cm_firebase_token' => 'shared-legacy-token',
        ]);
        $revokedAccessToken = new class
        {
            public function fresh(): object
            {
                return (object) ['revoked' => true];
            }
        };

        try {
            CustomerNotificationInstallations::registerLegacy(
                $firstCustomer,
                'shared-legacy-token',
                $revokedAccessToken
            );
            $this->fail('A revoked access token must not restore a legacy binding.');
        } catch (AuthenticationException) {
            // Expected: legacy compatibility writes use the same revoked-token guard.
        }

        $this->assertNull($firstCustomer->fresh()->cm_firebase_token);
        $this->assertSame('shared-legacy-token', $secondCustomer->fresh()->cm_firebase_token);
    }

    public function test_legacy_logout_does_not_clear_a_newer_modern_rollback_token(): void
    {
        $customer = User::query()->findOrFail(1);
        $this->withoutMiddleware();
        $this->actingAs($customer);

        $this->putJson('/api/v1/customer/cm-firebase-token', [
            'cm_firebase_token' => 'legacy-fcm-token',
        ])->assertOk();
        $this->putJson('/api/v1/customer/cm-firebase-token', [
            'installation_id' => 'newer-modern-installation',
            'cm_firebase_token' => 'newer-modern-token',
        ])->assertOk();

        $this->postJson('/api/v1/customer/logout')->assertOk();

        $this->assertSame('newer-modern-token', $customer->fresh()->cm_firebase_token);
        $this->assertDatabaseHas('customer_notification_installations', [
            'user_id' => $customer->id,
            'installation_id' => 'newer-modern-installation',
            'revoked_at' => null,
        ]);
    }

    public function test_new_registration_removes_the_same_legacy_token_from_the_previous_customer(): void
    {
        Queue::fake();
        Config::set('nezha_notif_async_status_conf', ['value' => '1']);
        $firstCustomer = User::query()->findOrFail(1);
        $firstCustomer->forceFill(['cm_firebase_token' => 'shared-legacy-token'])->save();
        $secondCustomer = User::query()->forceCreate([
            'id' => 2,
            'f_name' => 'Second',
            'l_name' => 'Customer',
        ]);
        $this->withoutMiddleware();
        $this->actingAs($secondCustomer);

        $this->putJson('/api/v1/customer/cm-firebase-token', [
            'installation_id' => 'shared-browser-installation',
            'cm_firebase_token' => 'shared-legacy-token',
            'platform' => 'android_chrome',
        ])->assertOk();

        $this->assertNull($firstCustomer->fresh()->cm_firebase_token);
        $this->assertSame('shared-legacy-token', $secondCustomer->fresh()->cm_firebase_token);

        $notification = [
            'title' => 'Order update',
            'description' => 'The order status changed.',
            'image' => '',
            'order_id' => '1001',
            'type' => 'order_status',
        ];
        Helpers::send_push_notif_to_customer($firstCustomer->fresh(), $notification);
        Helpers::send_push_notif_to_customer($secondCustomer->fresh(), $notification);

        Queue::assertPushed(SendPushNotificationJob::class, 1);
        Queue::assertPushed(SendPushNotificationJob::class, function (SendPushNotificationJob $job): bool {
            return data_get($job->payload, 'message.token') === 'shared-legacy-token';
        });
    }

    public function test_legacy_registration_moves_the_same_modern_token_to_the_current_customer(): void
    {
        Queue::fake();
        Config::set('nezha_notif_async_status_conf', ['value' => '1']);
        $firstCustomer = User::query()->findOrFail(1);
        $secondCustomer = User::query()->forceCreate([
            'id' => 2,
            'f_name' => 'Second',
            'l_name' => 'Customer',
        ]);
        $this->withoutMiddleware();

        $this->actingAs($firstCustomer);
        $this->putJson('/api/v1/customer/cm-firebase-token', [
            'installation_id' => 'modern-installation-first-customer',
            'cm_firebase_token' => 'shared-modern-token',
        ])->assertOk();

        $this->actingAs($secondCustomer);
        $this->putJson('/api/v1/customer/cm-firebase-token', [
            'cm_firebase_token' => 'shared-modern-token',
        ])->assertOk();

        $notification = [
            'title' => 'Order update',
            'description' => 'The order status changed.',
            'image' => '',
            'order_id' => '1001',
            'type' => 'order_status',
        ];
        Helpers::send_push_notif_to_customer($firstCustomer->fresh(), $notification);
        Helpers::send_push_notif_to_customer($secondCustomer->fresh(), $notification);

        Queue::assertPushed(SendPushNotificationJob::class, 1);
        $installation = CustomerNotificationInstallation::query()
            ->where('token_hash', hash('sha256', 'shared-modern-token'))
            ->firstOrFail();
        $this->assertSame($secondCustomer->id, $installation->user_id);
        $this->assertSame('fcm_web_legacy', $installation->transport);
    }

    public function test_modern_and_distinct_legacy_customer_devices_both_receive_push(): void
    {
        Queue::fake();
        Config::set('nezha_notif_async_status_conf', ['value' => '1']);
        $customer = User::query()->findOrFail(1);
        $this->withoutMiddleware();
        $this->actingAs($customer);

        $this->putJson('/api/v1/customer/cm-firebase-token', [
            'installation_id' => 'modern-installation',
            'cm_firebase_token' => 'modern-fcm-token',
        ])->assertOk();
        $this->putJson('/api/v1/customer/cm-firebase-token', [
            'cm_firebase_token' => 'legacy-fcm-token',
        ])->assertOk();

        Helpers::send_push_notif_to_customer($customer->fresh(), [
            'title' => 'Order update',
            'description' => 'The order status changed.',
            'image' => '',
            'order_id' => '1001',
            'type' => 'order_status',
        ]);

        Queue::assertPushed(SendPushNotificationJob::class, 2);
        foreach (['modern-fcm-token', 'legacy-fcm-token'] as $token) {
            Queue::assertPushed(SendPushNotificationJob::class, function (SendPushNotificationJob $job) use ($token): bool {
                return data_get($job->payload, 'message.token') === $token;
            });
        }
    }

    public function test_customer_installations_and_push_fanout_are_hard_limited(): void
    {
        Queue::fake();
        Config::set('nezha_notif_async_status_conf', ['value' => '1']);
        $customer = User::query()->findOrFail(1);
        $this->withoutMiddleware();
        $this->actingAs($customer);

        foreach (range(1, CustomerNotificationInstallations::MAX_ACTIVE_INSTALLATIONS + 2) as $number) {
            $this->putJson('/api/v1/customer/cm-firebase-token', [
                'installation_id' => sprintf('bounded-installation-%02d', $number),
                'cm_firebase_token' => sprintf('bounded-fcm-token-%02d', $number),
            ])->assertOk();
        }

        $this->assertSame(
            CustomerNotificationInstallations::MAX_ACTIVE_INSTALLATIONS,
            CustomerNotificationInstallation::query()
                ->where('user_id', $customer->id)
                ->whereNull('revoked_at')
                ->count()
        );

        Helpers::send_push_notif_to_customer($customer->fresh(), [
            'title' => 'Order update',
            'description' => 'The order status changed.',
            'image' => '',
            'order_id' => '1001',
            'type' => 'order_status',
        ]);

        Queue::assertPushed(
            SendPushNotificationJob::class,
            CustomerNotificationInstallations::MAX_ACTIVE_INSTALLATIONS
        );
    }

    public function test_web_push_pruning_clears_a_legacy_fcm_fallback_that_is_no_longer_active(): void
    {
        $customer = User::query()->findOrFail(1);
        CustomerNotificationInstallations::register($customer, [
            'installation_id' => 'android-installation-pruned-by-limit',
            'cm_firebase_token' => 'android-token-pruned-by-limit',
            'platform' => 'android_chrome',
        ]);

        foreach (range(1, CustomerNotificationInstallations::MAX_ACTIVE_INSTALLATIONS) as $number) {
            CustomerNotificationInstallations::registerWebPush($customer, [
                'installation_id' => sprintf('ios-installation-%02d', $number),
                'subscription' => [
                    'endpoint' => sprintf('https://web.push.apple.com/subscription-%02d', $number),
                    'keys' => [
                        'p256dh' => 'valid-p256dh-key-material',
                        'auth' => 'valid-auth-secret',
                    ],
                ],
            ]);
        }

        $this->assertNull($customer->fresh()->cm_firebase_token);
        $this->assertDatabaseMissing('customer_notification_installations', [
            'installation_id' => 'android-installation-pruned-by-limit',
        ]);
    }

    public function test_revoked_late_access_token_cannot_reclaim_an_installation_from_the_new_customer(): void
    {
        $firstCustomer = User::query()->findOrFail(1);
        $secondCustomer = User::query()->forceCreate([
            'id' => 2,
            'f_name' => 'Second',
            'l_name' => 'Customer',
        ]);
        $this->withoutMiddleware();

        $this->actingAs($secondCustomer);
        $this->putJson('/api/v1/customer/cm-firebase-token', [
            'installation_id' => 'late-registration-installation',
            'cm_firebase_token' => 'fcm-token-second-customer',
        ])->assertOk();

        $revokedAccessToken = new class
        {
            public function fresh(): object
            {
                return (object) ['revoked' => true];
            }
        };

        try {
            CustomerNotificationInstallations::register($firstCustomer, [
                'installation_id' => 'late-registration-installation',
                'cm_firebase_token' => 'fcm-token-first-customer',
            ], $revokedAccessToken);
            $this->fail('A revoked access token must not reclaim the installation.');
        } catch (AuthenticationException) {
            // Expected: the server rechecks the token after locking the installation.
        }

        $installation = CustomerNotificationInstallation::query()
            ->where('installation_id', 'late-registration-installation')
            ->firstOrFail();
        $this->assertSame($secondCustomer->id, $installation->user_id);
        $this->assertSame('fcm-token-second-customer', $installation->token);
    }

    public function test_registration_retries_a_transient_database_deadlock(): void
    {
        // Exercise a root transaction: Laravel cannot retry a nested transaction created by DatabaseTransactions.
        DB::rollBack();
        $customer = User::query()->findOrFail(1);
        $accessToken = new class
        {
            public int $freshCalls = 0;

            public function fresh(): object
            {
                $this->freshCalls++;
                if ($this->freshCalls === 1) {
                    throw new \PDOException('Deadlock found when trying to get lock');
                }

                return (object) ['revoked' => false];
            }
        };

        CustomerNotificationInstallations::register($customer, [
            'installation_id' => 'deadlock-retry-installation',
            'cm_firebase_token' => 'deadlock-retry-token',
        ], $accessToken);

        $this->assertSame(2, $accessToken->freshCalls);
        $this->assertDatabaseHas('customer_notification_installations', [
            'user_id' => $customer->id,
            'installation_id' => 'deadlock-retry-installation',
            'revoked_at' => null,
        ]);
    }

    public function test_logout_revokes_a_real_passport_token_and_prevents_a_late_rebind(): void
    {
        $customer = User::query()->findOrFail(1);
        $accessToken = Token::query()->create([
            'id' => 'passport-access-token-customer-1',
            'user_id' => $customer->id,
            'client_id' => 1,
            'name' => 'notification-installation-test',
            'scopes' => [],
            'revoked' => false,
            'expires_at' => now()->addHour(),
        ]);
        $customer->withAccessToken($accessToken);
        $this->withoutMiddleware();
        $this->actingAs($customer);

        $this->putJson('/api/v1/customer/cm-firebase-token', [
            'installation_id' => 'passport-guarded-installation',
            'cm_firebase_token' => 'passport-guarded-token',
        ])->assertOk();
        DB::statement('CREATE TEMP TABLE logout_order_audit (token_revoked INTEGER NOT NULL)');
        DB::statement("CREATE TEMP TRIGGER audit_notification_installation_logout AFTER UPDATE OF revoked_at ON customer_notification_installations BEGIN INSERT INTO logout_order_audit (token_revoked) SELECT revoked FROM oauth_access_tokens WHERE id = 'passport-access-token-customer-1'; END");
        $this->postJson('/api/v1/customer/logout', [
            'installation_id' => 'passport-guarded-installation',
        ])->assertOk();

        $this->assertTrue($accessToken->fresh()->revoked);
        $this->assertSame(1, DB::table('logout_order_audit')->value('token_revoked'));

        $this->expectException(AuthenticationException::class);
        CustomerNotificationInstallations::register($customer, [
            'installation_id' => 'passport-guarded-installation',
            'cm_firebase_token' => 'late-passport-token',
        ], $accessToken);
    }
}
