<?php

use App\Http\Controllers\Api\V1\DeliverymanController;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\IsolatedDatabaseFixtures;

$root = dirname(__DIR__, 2);
require $root.'/tests/bootstrap-isolated.php';

$runtime = getenv('NEZHA_MYSQL57_RUNTIME_DIR') ?: '';
if ($runtime === '' || ! is_dir($runtime)) {
    throw new RuntimeException('NEZHA_MYSQL57_RUNTIME_DIR must be an existing isolated directory.');
}

$app = require $root.'/bootstrap/app.php';
if (PHP_OS_FAMILY === 'Windows' && preg_match('/^[A-Za-z]:[\\\\\/]/', $runtime) === 1) {
    $app->addAbsoluteCachePathPrefix(substr($runtime, 0, 3));
}
$app->make(Kernel::class)->bootstrap();

if (DB::connection()->getDriverName() !== 'mysql') {
    throw new RuntimeException('The concurrency worker requires the mysql driver.');
}
$databaseName = DB::connection()->getDatabaseName();
if (! is_string($databaseName)
    || preg_match('/^nezha_api_concurrency_[a-f0-9]{12}$/', $databaseName) !== 1) {
    throw new RuntimeException("Refusing unsafe concurrency database: {$databaseName}");
}
$version = (string) DB::selectOne('SELECT VERSION() AS version')->version;
if (preg_match('/^5\.7\./', $version) !== 1) {
    throw new RuntimeException("The concurrency worker requires MySQL 5.7, got {$version}.");
}

config()->set('order_confirmation_model', 'deliveryman');
config()->set('dm_maximum_orders', 10);
if (! defined('DOMAIN_POINTED_DIRECTORY')) {
    define('DOMAIN_POINTED_DIRECTORY', 'public');
}

$action = $argv[1] ?? '';
if ($action === 'setup') {
    IsolatedDatabaseFixtures::ensure($app);

    if (! Schema::hasColumn('restaurant_subscriptions', 'self_delivery')) {
        Schema::table('restaurant_subscriptions', function (Blueprint $table): void {
            $table->boolean('self_delivery')->default(false);
        });
    }

    if (! Schema::hasColumn('orders', 'zone_id')) {
        Schema::table('orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('zone_id')->nullable();
            $table->string('order_type')->default('delivery');
            $table->string('payment_method')->default('cash_on_delivery');
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->unsignedBigInteger('vehicle_id')->nullable();
            $table->unsignedBigInteger('delivery_man_id')->nullable();
            $table->decimal('order_amount', 24, 2)->default(0);
            $table->boolean('is_guest')->default(false);
            $table->timestamp('schedule_at')->nullable();
            $table->text('order_proof')->nullable();
        });
    }

    if (! Schema::hasColumn('orders', 'user_id')) {
        Schema::table('orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->nullable();
        });
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

    DB::table('business_settings')->insert([
        ['key' => 'cash_in_hand_overflow_delivery_man', 'value' => '0', 'created_at' => now(), 'updated_at' => now()],
        ['key' => 'dm_max_cash_in_hand', 'value' => '0', 'created_at' => now(), 'updated_at' => now()],
    ]);
    DB::table('users')->where('id', 1)->update([
        'f_name' => 'Concurrency',
        'l_name' => 'Customer',
        'updated_at' => now(),
    ]);
    $restaurantId = (int) DB::table('restaurants')->insertGetId([
        'name' => 'Concurrency restaurant',
        'slug' => 'concurrency-restaurant',
        'vendor_id' => 1,
        'zone_id' => 1,
        'status' => 1,
        'restaurant_model' => 'commission',
        'self_delivery_system' => 0,
        'delivery_time' => '10-20',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $deliveryMen = [];
    foreach (['dm-one-token', 'dm-two-token'] as $token) {
        $deliveryMen[$token] = (int) DB::table('delivery_men')->insertGetId([
            'auth_token' => $token,
            'type' => 'zone_wise',
            'zone_id' => 1,
            'earning' => 0,
            'current_orders' => 0,
            'assigned_order_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
    $orderId = (int) DB::table('orders')->insertGetId([
        'restaurant_id' => $restaurantId,
        'zone_id' => 1,
        'order_type' => 'delivery',
        'order_status' => 'confirmed',
        'payment_method' => 'cash_on_delivery',
        'delivery_man_id' => null,
        'order_amount' => 10,
        'user_id' => 1,
        'is_guest' => 0,
        'scheduled' => 0,
        'schedule_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::unprepared(<<<'SQL'
CREATE TRIGGER nezha_accept_order_race
BEFORE UPDATE ON orders
FOR EACH ROW
BEGIN
    IF OLD.delivery_man_id IS NULL AND NEW.delivery_man_id IS NOT NULL THEN
        DO SLEEP(1);
    END IF;
END
SQL);

    echo json_encode([
        'delivery_men' => $deliveryMen,
        'order_id' => $orderId,
    ], JSON_THROW_ON_ERROR);
    exit(0);
}

if ($action === 'claim') {
    $token = $argv[2] ?? '';
    $orderId = (int) ($argv[3] ?? 0);
    $barrier = $argv[4] ?? '';
    if (! in_array($token, ['dm-one-token', 'dm-two-token'], true)
        || $orderId < 1
        || ! is_dir($barrier)) {
        throw new RuntimeException('Invalid concurrency claim worker arguments.');
    }

    file_put_contents($barrier.DIRECTORY_SEPARATOR.$token.'.ready', (string) getmypid());
    $deadline = microtime(true) + 10;
    while (! is_file($barrier.DIRECTORY_SEPARATOR.'go')) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for the concurrency start barrier.');
        }
        usleep(25_000);
    }

    $response = (new DeliverymanController)->accept_order(Request::create(
        '/api/v1/delivery-man/accept-order',
        'POST',
        ['token' => $token, 'order_id' => $orderId],
    ));
    echo json_encode([
        'status' => $response->getStatusCode(),
        'token' => $token,
    ], JSON_THROW_ON_ERROR);
    exit(0);
}

throw new RuntimeException("Unknown concurrency worker action: {$action}");
