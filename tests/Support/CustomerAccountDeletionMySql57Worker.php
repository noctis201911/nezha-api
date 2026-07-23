<?php

use App\Exceptions\AccountDeletionException;
use App\Models\CustomerAccountDeletionEvent;
use App\Models\CustomerAccountDeletionState;
use App\Models\Order;
use App\Models\User;
use App\Services\CustomerAccountDeletion\CustomerAccountDeletionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$root = dirname(__DIR__, 2);
require $root.'/tests/bootstrap-isolated.php';
$runtime = getenv('NEZHA_ACCOUNT_DELETION_RUNTIME_DIR') ?: '';
if ($runtime === '' || ! is_dir($runtime)) {
    throw new RuntimeException('NEZHA_ACCOUNT_DELETION_RUNTIME_DIR must be an existing isolated directory.');
}
$app = require $root.'/bootstrap/app.php';
if (PHP_OS_FAMILY === 'Windows' && strlen($runtime) > 2 && ctype_alpha($runtime[0]) && $runtime[1] === ':') {
    $app->addAbsoluteCachePathPrefix(substr($runtime, 0, 3));
}
$app->make(Kernel::class)->bootstrap();

$database = DB::connection()->getDatabaseName();
if (DB::connection()->getDriverName() !== 'mysql'
    || ! is_string($database)
    || preg_match('/^nezha_account_delete_[a-f0-9]{12}$/', $database) !== 1) {
    throw new RuntimeException("Refusing unsafe account-deletion database: {$database}");
}
if (preg_match('/^5\.7\./', (string) DB::selectOne('SELECT VERSION() AS version')->version) !== 1) {
    throw new RuntimeException('Account-deletion concurrency worker requires MySQL 5.7.');
}

$service = app(CustomerAccountDeletionService::class);
$action = $argv[1] ?? '';
$user = static fn (int $id): User => User::query()->without('storage')->findOrFail($id);
$newUser = static function (string $email): int {
    return DB::table('users')->insertGetId([
        'f_name' => 'Concurrency',
        'l_name' => 'Customer',
        'email' => $email,
        'password' => bcrypt('secret'),
        'status' => 1,
        'is_email_verified' => 1,
        'email_verified_at' => now(),
        'wallet_balance' => 0,
        'loyalty_point' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
};
$activate = static function (int $userId) use ($service, $user): CustomerAccountDeletionState {
    return DB::transaction(function () use ($service, $user, $userId) {
        $customer = $user($userId);
        $service->assertValidCheckoutRequest($customer, true, CustomerAccountDeletionService::COPY_CHECKOUT);
        $state = $service->lockForOrder($customer, true);
        $orderId = DB::table('orders')->insertGetId([
            'user_id' => $userId,
            'is_guest' => 0,
            'order_status' => 'pending',
            'order_amount' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $service->finalizeCreatedOrder(
            $customer,
            Order::query()->findOrFail($orderId),
            $state,
            true,
            CustomerAccountDeletionService::COPY_CHECKOUT,
            'zh-CN'
        );
    }, 5);
};

if ($action === 'migration-cycle') {
    Schema::create('business_settings', function (Blueprint $table): void {
        $table->id();
        $table->string('key');
        $table->text('value')->nullable();
        $table->timestamps();
    });
    $migration = require $root.'/database/migrations/2026_07_22_090000_create_customer_account_deletion_lifecycle.php';
    $migration->up();
    $firstSettings = DB::table('business_settings')->where('key', 'like', 'nezha_account_deletion_%')->count();
    $firstOptions = collect(DB::select(
        "SELECT TABLE_NAME AS table_name, CREATE_OPTIONS AS create_options FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME LIKE 'customer_account_deletion_%' ORDER BY TABLE_NAME",
        [$database]
    ))->mapWithKeys(fn ($row) => [$row->table_name => $row->create_options])->all();
    $migration->down();
    $migration->up();
    $secondSettings = DB::table('business_settings')->where('key', 'like', 'nezha_account_deletion_%')->count();
    $secondTables = collect(DB::select(
        "SELECT TABLE_NAME AS table_name FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME LIKE 'customer_account_deletion_%' ORDER BY TABLE_NAME",
        [$database]
    ))->pluck('table_name')->all();
    $migration->down();
    Schema::dropIfExists('business_settings');
    echo json_encode([
        'first_settings' => $firstSettings,
        'first_options' => $firstOptions,
        'second_settings' => $secondSettings,
        'second_tables' => $secondTables,
    ], JSON_THROW_ON_ERROR);
    exit(0);
}

if ($action === 'setup') {
    Schema::create('business_settings', function (Blueprint $table): void {
        $table->id();
        $table->string('key');
        $table->text('value')->nullable();
        $table->timestamps();
    });
    Schema::create('users', function (Blueprint $table): void {
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
    Schema::create('storages', function (Blueprint $table): void {
        $table->id();
        $table->string('data_type');
        $table->unsignedBigInteger('data_id');
        $table->string('key');
        $table->string('value')->nullable();
        $table->timestamps();
    });
    Schema::create('orders', function (Blueprint $table): void {
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
    Schema::create('customer_addresses', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('user_id')->index();
        $table->string('address')->nullable();
        $table->timestamps();
    });
    Schema::create('oauth_access_tokens', function (Blueprint $table): void {
        $table->string('id')->primary();
        $table->unsignedBigInteger('user_id')->nullable()->index();
        $table->boolean('revoked')->default(0);
    });
    Schema::create('oauth_refresh_tokens', function (Blueprint $table): void {
        $table->string('id')->primary();
        $table->string('access_token_id')->index();
        $table->boolean('revoked')->default(0);
    });
    $migration = require $root.'/database/migrations/2026_07_22_090000_create_customer_account_deletion_lifecycle.php';
    $migration->up();
    DB::table('business_settings')->whereIn('key', [
        'nezha_account_deletion_intake_enabled',
        'nezha_account_deletion_countdown_enabled',
        'nezha_account_deletion_execution_enabled',
        'nezha_account_deletion_purge_enabled',
    ])->update(['value' => '1']);
    $raceUserId = $newUser('race@example.test');
    $orderUserId = $newUser('order@example.test');
    $cancelUserId = $newUser('cancel@example.test');
    $contactUserId = $newUser('old-contact@example.test');
    $crashUserId = $newUser('crash@example.test');
    DB::table('customer_account_deletion_states')->insert([
        'user_id' => $orderUserId,
        'status' => 'open',
        'blocker_mask' => 0,
        'obligation_epoch' => 0,
        'state_version' => 0,
        'purge_matrix_version' => CustomerAccountDeletionService::PURGE_MATRIX,
        'copy_locale' => 'zh-CN',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    echo json_encode([
        'race_user_id' => $raceUserId,
        'order_user_id' => $orderUserId,
        'cancel_user_id' => $cancelUserId,
        'contact_user_id' => $contactUserId,
        'crash_user_id' => $crashUserId,
    ], JSON_THROW_ON_ERROR);
    exit(0);
}

if ($action === 'activate') {
    $userId = (int) ($argv[2] ?? 0);
    $barrier = $argv[3] ?? '';
    $workerId = $argv[4] ?? '';
    file_put_contents($barrier.'/'.$workerId.'.ready', (string) getmypid());
    $deadline = microtime(true) + 10;
    while (! is_file($barrier.'/go')) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Activation barrier timed out.');
        }
        usleep(25_000);
    }
    try {
        $activate($userId);
        $status = 'success';
    } catch (AccountDeletionException $exception) {
        if ($exception->errorCode !== 'ACCOUNT_DELETION_ACTIVE') {
            throw $exception;
        }
        $status = 'active';
    }
    echo json_encode(compact('status'), JSON_THROW_ON_ERROR);
    exit(0);
}

if ($action === 'order-hold') {
    $userId = (int) ($argv[2] ?? 0);
    $barrier = $argv[3] ?? '';
    DB::beginTransaction();
    try {
        $customer = $user($userId);
        $gate = $service->lockForOrder($customer, false);
        file_put_contents($barrier.'/locked', (string) getmypid());
        $deadline = microtime(true) + 10;
        while (! is_file($barrier.'/go')) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Order barrier timed out.');
            }
            usleep(25_000);
        }
        $orderId = DB::table('orders')->insertGetId([
            'user_id' => $userId,
            'is_guest' => 0,
            'order_status' => 'pending',
            'order_amount' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $service->finalizeCreatedOrder(
            $customer,
            Order::query()->findOrFail($orderId),
            $gate,
            false
        );
        DB::commit();
    } catch (Throwable $exception) {
        DB::rollBack();
        throw $exception;
    }
    echo json_encode(['status' => 'success'], JSON_THROW_ON_ERROR);
    exit(0);
}

if ($action === 'unchecked-order') {
    $userId = (int) ($argv[2] ?? 0);
    $state = $activate($userId);
    DB::transaction(function () use ($service, $user, $userId): void {
        $customer = $user($userId);
        $gate = $service->lockForOrder($customer, false);
        $orderId = DB::table('orders')->insertGetId([
            'user_id' => $userId,
            'is_guest' => 0,
            'order_status' => 'pending',
            'order_amount' => 25,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $service->finalizeCreatedOrder(
            $customer,
            Order::query()->findOrFail($orderId),
            $gate,
            false
        );
    }, 5);
    $paused = CustomerAccountDeletionState::query()->findOrFail($state->id);
    echo json_encode([
        'status' => $paused->status,
        'obligation_events' => CustomerAccountDeletionEvent::query()
            ->where('event_type', 'obligation_created_by_order')
            ->where('request_id', $state->request_id)
            ->count(),
    ], JSON_THROW_ON_ERROR);
    exit(0);
}

if ($action === 'activate-started') {
    $userId = (int) ($argv[2] ?? 0);
    $barrier = $argv[3] ?? '';
    file_put_contents($barrier.'/activation-started', (string) getmypid());
    $state = $activate($userId);
    echo json_encode(['status' => 'success', 'request_id' => $state->request_id], JSON_THROW_ON_ERROR);
    exit(0);
}

if ($action === 'contact-hold') {
    $userId = (int) ($argv[2] ?? 0);
    $barrier = $argv[3] ?? '';
    $service->withContactChangeGuard($user($userId), function () use ($userId, $barrier): void {
        file_put_contents($barrier.'/contact-locked', (string) getmypid());
        $deadline = microtime(true) + 10;
        while (! is_file($barrier.'/go')) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Contact barrier timed out.');
            }
            usleep(25_000);
        }
        DB::table('users')->where('id', $userId)->update(['email' => 'new-contact@example.test', 'updated_at' => now()]);
    });
    echo json_encode(['status' => 'success'], JSON_THROW_ON_ERROR);
    exit(0);
}

if ($action === 'crash-resume') {
    $userId = (int) ($argv[2] ?? 0);
    $state = $activate($userId);
    DB::table('orders')->where('id', $state->source_order_id)->update([
        'order_status' => 'delivered',
        'order_amount' => 99,
        'delivery_address_id' => 42,
        'delivery_address' => '{"phone":"secret"}',
        'order_note' => 'private',
    ]);
    DB::table('oauth_access_tokens')->insert(['id' => 'crash-access', 'user_id' => $userId, 'revoked' => 0]);
    $service->reconcileOne($state->request_id);
    $service->revokePendingSessions();
    $state = CustomerAccountDeletionState::query()->whereKey($state->id)->firstOrFail();
    $state->forceFill([
        'status' => 'purging',
        'scheduled_for' => now()->subSecond(),
        'execution_started_at' => now()->subMinute(),
        'account_closed_at' => now()->subMinute(),
        'execution_owner_token' => 'crash-owner',
        'obligation_epoch_at_claim' => $state->obligation_epoch,
    ])->save();
    DB::table('users')->where('id', $userId)->update(['status' => 0, 'password' => bcrypt('invalidated')]);
    $closeDedupe = 'purge-step-completed:'.$state->request_id.':close-account';
    CustomerAccountDeletionEvent::query()->create([
        'state_id' => $state->id,
        'request_id' => $state->request_id,
        'event_type' => 'purge_step_completed',
        'state_version' => $state->state_version,
        'dedupe_key' => $closeDedupe,
        'metadata' => ['step' => 'close-account'],
    ]);
    $service->executeDue();
    $completed = CustomerAccountDeletionState::query()->findOrFail($state->id);
    $completedStepCount = CustomerAccountDeletionEvent::query()->where('dedupe_key', $closeDedupe)->count();

    DB::table('users')->where('id', $userId)->update(['status' => 1, 'email' => 'restored@example.test']);
    DB::table('customer_addresses')->insert([
        'user_id' => $userId,
        'address' => 'must disappear',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $restoreDryRun = $service->replayCompleted(true);
    $restoreApplied = $service->replayCompleted(false);
    $restored = DB::table('users')->where('id', $userId)->first();
    echo json_encode([
        'status' => $completed->status,
        'completed_step_count' => $completedStepCount,
        'restore_dry_run' => $restoreDryRun,
        'restore_applied' => $restoreApplied,
        'restored_user_status' => (int) $restored->status,
        'restored_user_email' => $restored->email,
        'remaining_addresses' => DB::table('customer_addresses')->where('user_id', $userId)->count(),
    ], JSON_THROW_ON_ERROR);
    exit(0);
}

throw new RuntimeException("Unknown account-deletion worker action: {$action}");
