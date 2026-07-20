<?php

use App\CentralLogics\NezhaMerchantTwoFactor;
use App\CentralLogics\NezhaTotp;
use App\Models\Vendor;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

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
config()->set('hashing.bcrypt.rounds', 4);
Hash::driver('bcrypt')->setRounds(4);

$databaseName = DB::connection()->getDatabaseName();
if (DB::connection()->getDriverName() !== 'mysql'
    || ! is_string($databaseName)
    || preg_match('/^nezha_merchant_2fa_[a-f0-9]{12}$/', $databaseName) !== 1) {
    throw new RuntimeException("Refusing unsafe merchant 2FA concurrency database: {$databaseName}");
}
$version = (string) DB::selectOne('SELECT VERSION() AS version')->version;
if (preg_match('/^5\.7\./', $version) !== 1) {
    throw new RuntimeException("The merchant 2FA concurrency worker requires MySQL 5.7, got {$version}.");
}

$action = $argv[1] ?? '';
if ($action === 'setup') {
    Schema::create('vendors', function (Blueprint $table): void {
        $table->id();
        $table->string('email')->unique();
        $table->string('password');
        $table->boolean('status')->default(true);
        $table->string('auth_token', 191)->nullable();
        $table->rememberToken();
        $table->timestamps();
    });
    Schema::create('vendor_employees', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('vendor_id');
        $table->unsignedBigInteger('restaurant_id');
        $table->string('email')->unique();
        $table->string('password');
        $table->boolean('status')->default(true);
        $table->string('auth_token', 191)->nullable();
        $table->rememberToken();
        $table->timestamps();
    });

    $migration = require $root.'/database/migrations/2026_07_19_150000_add_merchant_two_factor_authentication.php';
    $migration->up();

    $disableVendor = new Vendor;
    $disableVendor->forceFill([
        'email' => 'mysql57-disable@example.test',
        'password' => password_hash('Correct-Horse-9!', PASSWORD_BCRYPT),
        'status' => true,
    ])->save();
    $disableVendor->refresh();
    $totpVendor = new Vendor;
    $totpVendor->forceFill([
        'email' => 'mysql57-totp@example.test',
        'password' => 'unused',
        'status' => true,
    ])->save();
    $totpVendor->refresh();

    $currentCounter = (int) floor(time() / 30);
    $disableSecret = NezhaTotp::generateSecret();
    NezhaMerchantTwoFactor::completeEnrollment(
        $disableVendor,
        $disableSecret,
        NezhaTotp::codeAt($disableSecret, $currentCounter),
        0,
        ['metadata' => ['channel' => 'mysql57-test']]
    );
    $totpSecret = NezhaTotp::generateSecret();
    NezhaMerchantTwoFactor::completeEnrollment(
        $totpVendor,
        $totpSecret,
        NezhaTotp::codeAt($totpSecret, $currentCounter - 1),
        0,
        ['metadata' => ['channel' => 'mysql57-test']]
    );

    echo json_encode([
        'disable_vendor_id' => $disableVendor->id,
        'disable_code' => NezhaTotp::codeAt($disableSecret, $currentCounter + 1),
        'totp_vendor_id' => $totpVendor->id,
        'totp_code' => NezhaTotp::codeAt($totpSecret, $currentCounter),
        'totp_counter' => $currentCounter,
    ], JSON_THROW_ON_ERROR);
    exit(0);
}

if (in_array($action, ['disable', 'totp'], true)) {
    $vendorId = (int) ($argv[2] ?? 0);
    $code = $argv[3] ?? '';
    $barrier = $argv[4] ?? '';
    $workerId = $argv[5] ?? '';
    if ($vendorId < 1 || $code === '' || ! is_dir($barrier)
        || preg_match('/^worker-[12]$/', $workerId) !== 1) {
        throw new RuntimeException('Invalid merchant 2FA concurrency worker arguments.');
    }

    file_put_contents($barrier.DIRECTORY_SEPARATOR.$workerId.'.ready', (string) getmypid());
    $deadline = microtime(true) + 10;
    while (! is_file($barrier.DIRECTORY_SEPARATOR.'go')) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for the merchant 2FA concurrency barrier.');
        }
        usleep(25_000);
    }

    try {
        $vendor = Vendor::findOrFail($vendorId);
        if ($action === 'disable') {
            NezhaMerchantTwoFactor::disableTwoFactor($vendor, 'Correct-Horse-9!', $code);
        } else {
            NezhaMerchantTwoFactor::verifyTotp($vendor, $code, 1);
        }
        $status = 'success';
    } catch (DomainException) {
        $status = 'invalid';
    }

    echo json_encode(['status' => $status, 'worker' => $workerId], JSON_THROW_ON_ERROR);
    exit(0);
}

throw new RuntimeException("Unknown merchant 2FA concurrency worker action: {$action}");
