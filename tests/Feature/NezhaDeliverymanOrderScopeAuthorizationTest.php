<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\DeliverymanController;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NezhaDeliverymanOrderScopeAuthorizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());

        Carbon::setTestNow(Carbon::parse('2026-07-18 12:00:00'));
        config()->set('order_confirmation_model', 'deliveryman');
        config()->set('dm_maximum_orders', 10);
        if (! defined('DOMAIN_POINTED_DIRECTORY')) {
            define('DOMAIN_POINTED_DIRECTORY', 'public');
        }

        $this->ensureSchema();
        DB::connection()->getPdo()->sqliteCreateFunction(
            'DATE_FORMAT',
            static fn (?string $value): ?string => $value === null
                ? null
                : Carbon::parse($value)->format('H:i'),
            2,
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_unassigned_cross_zone_details_returns_404_without_side_effects(): void
    {
        $deliveryManId = $this->createDeliveryMan(zoneId: 1);
        $restaurantId = $this->createRestaurant(zoneId: 2);
        $orderId = $this->createOrder(
            restaurantId: $restaurantId,
            zoneId: 2,
        );

        $orderBefore = DB::table('orders')->where('id', $orderId)->first();
        $deliveryManBefore = DB::table('delivery_men')->where('id', $deliveryManId)->first();

        $response = (new DeliverymanController)->get_order_details(Request::create(
            '/api/v1/delivery-man/order-details',
            'GET',
            ['token' => 'dm-token', 'order_id' => $orderId],
        ));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertEquals(
            $orderBefore,
            DB::table('orders')->where('id', $orderId)->first(),
        );
        $this->assertEquals(
            $deliveryManBefore,
            DB::table('delivery_men')->where('id', $deliveryManId)->first(),
        );
        $this->assertSame(0, DB::table('subscription_logs')->count());
        $this->assertSame(0, DB::table('user_notifications')->count());
    }

    public function test_latest_orders_does_not_bypass_scope_for_immediate_orders(): void
    {
        $this->createDeliveryMan(zoneId: 1);
        $restaurantId = $this->createRestaurant(zoneId: 2);
        $this->createOrder(
            restaurantId: $restaurantId,
            zoneId: 2,
            overrides: ['created_at' => now(), 'schedule_at' => now()],
        );

        $response = (new DeliverymanController)->get_latest_orders(Request::create(
            '/api/v1/delivery-man/latest-orders',
            'GET',
            ['token' => 'dm-token', 'limit' => 10, 'offset' => 1],
        ));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)['total_size']);
    }

    public function test_cross_zone_order_cannot_be_accepted_and_has_no_side_effects(): void
    {
        $deliveryManId = $this->createDeliveryMan(zoneId: 1);
        $restaurantId = $this->createRestaurant(zoneId: 2);
        $orderId = $this->createOrder(
            restaurantId: $restaurantId,
            zoneId: 2,
        );

        $orderBefore = DB::table('orders')->where('id', $orderId)->first();
        $deliveryManBefore = DB::table('delivery_men')->where('id', $deliveryManId)->first();

        $response = (new DeliverymanController)->accept_order(Request::create(
            '/api/v1/delivery-man/accept-order',
            'POST',
            ['token' => 'dm-token', 'order_id' => $orderId],
        ));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertEquals(
            $orderBefore,
            DB::table('orders')->where('id', $orderId)->first(),
        );
        $this->assertEquals(
            $deliveryManBefore,
            DB::table('delivery_men')->where('id', $deliveryManId)->first(),
        );
        $this->assertSame(0, DB::table('subscription_logs')->count());
        $this->assertSame(0, DB::table('user_notifications')->count());
    }

    #[DataProvider('unclaimableReasons')]
    public function test_unclaimable_unassigned_order_is_hidden_from_details(string $reason): void
    {
        [$deliveryManId, $orderId] = $this->createUnclaimableScenario($reason);
        $orderBefore = DB::table('orders')->where('id', $orderId)->first();
        $deliveryManBefore = DB::table('delivery_men')->where('id', $deliveryManId)->first();

        $response = (new DeliverymanController)->get_order_details(Request::create(
            '/api/v1/delivery-man/order-details',
            'GET',
            ['token' => 'dm-token', 'order_id' => $orderId],
        ));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertEquals($orderBefore, DB::table('orders')->where('id', $orderId)->first());
        $this->assertEquals($deliveryManBefore, DB::table('delivery_men')->where('id', $deliveryManId)->first());
        $this->assertSame(0, DB::table('subscription_logs')->count());
        $this->assertSame(0, DB::table('user_notifications')->count());
    }

    #[DataProvider('unclaimableReasons')]
    public function test_unclaimable_order_cannot_be_accepted_and_has_no_side_effects(string $reason): void
    {
        [$deliveryManId, $orderId] = $this->createUnclaimableScenario($reason);
        $orderBefore = DB::table('orders')->where('id', $orderId)->first();
        $deliveryManBefore = DB::table('delivery_men')->where('id', $deliveryManId)->first();

        $response = (new DeliverymanController)->accept_order(Request::create(
            '/api/v1/delivery-man/accept-order',
            'POST',
            ['token' => 'dm-token', 'order_id' => $orderId],
        ));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertEquals($orderBefore, DB::table('orders')->where('id', $orderId)->first());
        $this->assertEquals($deliveryManBefore, DB::table('delivery_men')->where('id', $deliveryManId)->first());
        $this->assertSame(0, DB::table('subscription_logs')->count());
        $this->assertSame(0, DB::table('user_notifications')->count());
    }

    public static function unclaimableReasons(): array
    {
        return [
            'cross restaurant' => ['cross_restaurant'],
            'vehicle mismatch' => ['vehicle_mismatch'],
            'outside shift' => ['outside_shift'],
            'commission self delivery' => ['commission_self_delivery'],
            'subscription self delivery' => ['subscription_self_delivery'],
            'invalid status' => ['invalid_status'],
            'restaurant rider pending order without subscription' => ['restaurant_pending_without_subscription'],
            'outside scheduled window' => ['outside_scheduled_window'],
            'pending digital payment' => ['pending_digital_payment'],
            'POS order' => ['pos_order'],
        ];
    }

    public function test_assigned_self_order_remains_readable_when_no_longer_claimable(): void
    {
        $deliveryManId = $this->createDeliveryMan(zoneId: 1);
        $restaurantId = $this->createRestaurant(zoneId: 2);
        $orderId = $this->createOrder(
            restaurantId: $restaurantId,
            zoneId: 2,
            overrides: [
                'delivery_man_id' => $deliveryManId,
                'order_status' => 'delivered',
            ],
        );
        $orderBefore = DB::table('orders')->where('id', $orderId)->first();
        $deliveryManBefore = DB::table('delivery_men')->where('id', $deliveryManId)->first();

        $response = (new DeliverymanController)->get_order_details(Request::create(
            '/api/v1/delivery-man/order-details',
            'GET',
            ['token' => 'dm-token', 'order_id' => $orderId],
        ));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertEquals($orderBefore, DB::table('orders')->where('id', $orderId)->first());
        $this->assertEquals($deliveryManBefore, DB::table('delivery_men')->where('id', $deliveryManId)->first());
        $this->assertSame(0, DB::table('subscription_logs')->count());
        $this->assertSame(0, DB::table('user_notifications')->count());
    }

    public function test_claimable_order_is_listed_readable_and_accepted(): void
    {
        $deliveryManId = $this->createDeliveryMan(zoneId: 1);
        $restaurantId = $this->createRestaurant(zoneId: 1);
        $orderId = $this->createOrder(restaurantId: $restaurantId, zoneId: 1);
        $controller = new DeliverymanController;

        $latestResponse = $controller->get_latest_orders(Request::create(
            '/api/v1/delivery-man/latest-orders',
            'GET',
            ['token' => 'dm-token', 'limit' => 10, 'offset' => 1],
        ));
        $detailsResponse = $controller->get_order_details(Request::create(
            '/api/v1/delivery-man/order-details',
            'GET',
            ['token' => 'dm-token', 'order_id' => $orderId],
        ));
        $acceptResponse = $controller->accept_order(Request::create(
            '/api/v1/delivery-man/accept-order',
            'POST',
            ['token' => 'dm-token', 'order_id' => $orderId],
        ));

        $this->assertSame(200, $latestResponse->getStatusCode());
        $this->assertSame(
            1,
            json_decode($latestResponse->getContent(), true, flags: JSON_THROW_ON_ERROR)['total_size'],
        );
        $this->assertSame(200, $detailsResponse->getStatusCode());
        $this->assertSame(200, $acceptResponse->getStatusCode());
        $this->assertSame($deliveryManId, DB::table('orders')->where('id', $orderId)->value('delivery_man_id'));
        $this->assertSame('accepted', DB::table('orders')->where('id', $orderId)->value('order_status'));
        $this->assertSame(1, DB::table('delivery_men')->where('id', $deliveryManId)->value('current_orders'));
        $this->assertSame(1, DB::table('delivery_men')->where('id', $deliveryManId)->value('assigned_order_count'));
    }

    public function test_serial_double_claim_assigns_order_to_exactly_one_delivery_man(): void
    {
        $firstDeliveryManId = $this->createDeliveryMan(zoneId: 1, token: 'dm-one-token');
        $secondDeliveryManId = $this->createDeliveryMan(zoneId: 1, token: 'dm-two-token');
        $restaurantId = $this->createRestaurant(zoneId: 1);
        $orderId = $this->createOrder(restaurantId: $restaurantId, zoneId: 1);
        $controller = new DeliverymanController;

        $firstResponse = $controller->accept_order(Request::create(
            '/api/v1/delivery-man/accept-order',
            'POST',
            ['token' => 'dm-one-token', 'order_id' => $orderId],
        ));
        $secondResponse = $controller->accept_order(Request::create(
            '/api/v1/delivery-man/accept-order',
            'POST',
            ['token' => 'dm-two-token', 'order_id' => $orderId],
        ));

        $this->assertSame(200, $firstResponse->getStatusCode());
        $this->assertSame(404, $secondResponse->getStatusCode());
        $this->assertSame($firstDeliveryManId, DB::table('orders')->where('id', $orderId)->value('delivery_man_id'));
        $this->assertSame(1, DB::table('delivery_men')->where('id', $firstDeliveryManId)->value('current_orders'));
        $this->assertSame(1, DB::table('delivery_men')->where('id', $firstDeliveryManId)->value('assigned_order_count'));
        $this->assertSame(0, DB::table('delivery_men')->where('id', $secondDeliveryManId)->value('current_orders'));
        $this->assertSame(0, DB::table('delivery_men')->where('id', $secondDeliveryManId)->value('assigned_order_count'));
    }

    private function createUnclaimableScenario(string $reason): array
    {
        if (in_array($reason, ['cross_restaurant', 'restaurant_pending_without_subscription'], true)) {
            $deliveryManRestaurantId = $this->createRestaurant(zoneId: 1);
            $deliveryManId = $this->createDeliveryMan(
                zoneId: 1,
                type: 'restaurant_wise',
                restaurantId: $deliveryManRestaurantId,
            );
            $orderRestaurantId = $reason === 'cross_restaurant'
                ? $this->createRestaurant(zoneId: 1)
                : $deliveryManRestaurantId;

            return [
                $deliveryManId,
                $this->createOrder(
                    restaurantId: $orderRestaurantId,
                    zoneId: 1,
                    overrides: $reason === 'restaurant_pending_without_subscription'
                        ? ['order_status' => 'pending', 'subscription_id' => null]
                        : [],
                ),
            ];
        }

        $deliveryManOptions = [
            'zoneId' => 1,
            'vehicleId' => null,
            'earning' => 0,
        ];
        $restaurantOptions = [
            'zoneId' => 1,
            'model' => 'commission',
            'selfDelivery' => 0,
        ];
        $orderOverrides = [];

        if ($reason === 'vehicle_mismatch') {
            $deliveryManOptions['vehicleId'] = 7;
            $orderOverrides['vehicle_id'] = 8;
        } elseif ($reason === 'outside_shift') {
            $deliveryManOptions['earning'] = 1;
        } elseif ($reason === 'commission_self_delivery') {
            $restaurantOptions['selfDelivery'] = 1;
        } elseif ($reason === 'subscription_self_delivery') {
            $restaurantOptions['model'] = 'subscription';
        } elseif ($reason === 'invalid_status') {
            $orderOverrides['order_status'] = 'delivered';
        } elseif ($reason === 'outside_scheduled_window') {
            $orderOverrides['schedule_at'] = now()->copy()->addMinutes(31);
        } elseif ($reason === 'pending_digital_payment') {
            $orderOverrides['order_status'] = 'pending';
            $orderOverrides['payment_method'] = 'digital_payment';
        } elseif ($reason === 'pos_order') {
            $orderOverrides['order_type'] = 'pos';
        }

        $restaurantId = $this->createRestaurant(...$restaurantOptions);
        if ($reason === 'subscription_self_delivery') {
            DB::table('restaurant_subscriptions')->insert([
                'restaurant_id' => $restaurantId,
                'status' => 1,
                'self_delivery' => 1,
                'max_order' => 'unlimited',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $deliveryManId = $this->createDeliveryMan(...$deliveryManOptions);
        if ($reason === 'outside_shift') {
            $shiftId = DB::table('shifts')->insertGetId([
                'start_time' => '08:00:00',
                'end_time' => '10:00:00',
                'is_full_day' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('delivery_man_shift')->insert([
                'delivery_man_id' => $deliveryManId,
                'shift_id' => $shiftId,
            ]);
        }

        return [
            $deliveryManId,
            $this->createOrder(
                restaurantId: $restaurantId,
                zoneId: 1,
                overrides: $orderOverrides,
            ),
        ];
    }

    private function createDeliveryMan(
        int $zoneId,
        string $type = 'zone_wise',
        ?int $restaurantId = null,
        ?int $vehicleId = null,
        float $earning = 0,
        string $token = 'dm-token',
    ): int {
        return (int) DB::table('delivery_men')->insertGetId([
            'auth_token' => $token,
            'type' => $type,
            'zone_id' => $zoneId,
            'restaurant_id' => $restaurantId,
            'vehicle_id' => $vehicleId,
            'earning' => $earning,
            'current_orders' => 0,
            'assigned_order_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createRestaurant(
        int $zoneId,
        string $model = 'commission',
        int $selfDelivery = 0,
    ): int {
        return (int) DB::table('restaurants')->insertGetId([
            'name' => 'Scope restaurant',
            'slug' => 'scope-restaurant-'.uniqid(),
            'vendor_id' => 1,
            'zone_id' => $zoneId,
            'status' => 1,
            'restaurant_model' => $model,
            'self_delivery_system' => $selfDelivery,
            'delivery_time' => '10-20',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createOrder(
        int $restaurantId,
        int $zoneId,
        array $overrides = [],
    ): int {
        return (int) DB::table('orders')->insertGetId(array_merge([
            'restaurant_id' => $restaurantId,
            'zone_id' => $zoneId,
            'order_type' => 'delivery',
            'order_status' => 'confirmed',
            'payment_method' => 'cash_on_delivery',
            'subscription_id' => null,
            'vehicle_id' => null,
            'delivery_man_id' => null,
            'order_amount' => 10,
            'user_id' => 1,
            'is_guest' => 0,
            'scheduled' => 0,
            'schedule_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function ensureSchema(): void
    {
        if (! Schema::hasColumn('restaurant_subscriptions', 'self_delivery')) {
            Schema::table('restaurant_subscriptions', function (Blueprint $table): void {
                $table->boolean('self_delivery')->default(false);
            });
        }

        // 逐列守卫: 共享 fixture (IsolatedDatabaseFixtures) 随时可能先声明其中某一列,
        // 用单个哨兵列守整批会在那时整批重建 -> "duplicate column name"。
        $orderColumns = [
            'zone_id'         => fn (Blueprint $table) => $table->unsignedBigInteger('zone_id')->nullable(),
            'order_type'      => fn (Blueprint $table) => $table->string('order_type')->default('delivery'),
            'payment_method'  => fn (Blueprint $table) => $table->string('payment_method')->default('cash_on_delivery'),
            'subscription_id' => fn (Blueprint $table) => $table->unsignedBigInteger('subscription_id')->nullable(),
            'vehicle_id'      => fn (Blueprint $table) => $table->unsignedBigInteger('vehicle_id')->nullable(),
            'delivery_man_id' => fn (Blueprint $table) => $table->unsignedBigInteger('delivery_man_id')->nullable(),
            'order_amount'    => fn (Blueprint $table) => $table->decimal('order_amount', 24, 2)->default(0),
            'is_guest'        => fn (Blueprint $table) => $table->boolean('is_guest')->default(false),
            'schedule_at'     => fn (Blueprint $table) => $table->timestamp('schedule_at')->nullable(),
            'order_proof'     => fn (Blueprint $table) => $table->text('order_proof')->nullable(),
            'user_id'         => fn (Blueprint $table) => $table->unsignedBigInteger('user_id')->nullable(),
        ];

        foreach ($orderColumns as $column => $define) {
            if (! Schema::hasColumn('orders', $column)) {
                Schema::table('orders', function (Blueprint $table) use ($define): void {
                    $define($table);
                });
            }
        }

        if (! Schema::hasTable('delivery_men')) {
            Schema::create('delivery_men', function (Blueprint $table): void {
                $table->id();
                $table->string('auth_token')->nullable()->index();
                $table->string('type')->default('zone_wise');
                $table->unsignedBigInteger('zone_id')->nullable();
                $table->unsignedBigInteger('restaurant_id')->nullable();
                $table->unsignedBigInteger('vehicle_id')->nullable();
                $table->decimal('earning', 24, 2)->default(0);
                $table->unsignedInteger('current_orders')->default(0);
                $table->unsignedInteger('assigned_order_count')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('shifts')) {
            Schema::create('shifts', function (Blueprint $table): void {
                $table->id();
                $table->time('start_time');
                $table->time('end_time');
                $table->boolean('is_full_day')->default(false);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('delivery_man_shift')) {
            Schema::create('delivery_man_shift', function (Blueprint $table): void {
                $table->unsignedBigInteger('delivery_man_id');
                $table->unsignedBigInteger('shift_id');
            });
        }

        if (! Schema::hasTable('delivery_man_wallets')) {
            Schema::create('delivery_man_wallets', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('delivery_man_id')->unique();
                $table->decimal('collected_cash', 24, 2)->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('order_details')) {
            Schema::create('order_details', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('order_id')->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('subscription_logs')) {
            Schema::create('subscription_logs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('subscription_id')->nullable();
                $table->unsignedBigInteger('order_id')->index();
                $table->string('order_status')->nullable();
                $table->unsignedBigInteger('delivery_man_id')->nullable();
                $table->timestamp('schedule_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('user_notifications')) {
            Schema::create('user_notifications', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->text('data')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('notification_messages')) {
            Schema::create('notification_messages', function (Blueprint $table): void {
                $table->id();
                $table->string('key')->index();
                $table->string('user_type')->index();
                $table->boolean('status')->default(true);
                $table->text('message')->nullable();
                $table->timestamps();
            });
        }
    }
}
