<?php

namespace Tests\Feature;

use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class NezhaMerchantTwoFactorMySql57ConcurrencyTest extends TestCase
{
    private PDO $server;

    private string $database;

    private string $runtimeDirectory;

    private string $worker;

    private array $workerEnvironment;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('NEZHA_MERCHANT_2FA_MYSQL57_CONCURRENCY') !== '1') {
            $this->markTestSkipped('Enable only against a disposable MySQL 5.7 instance.');
        }
        if (! extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('The pdo_mysql extension is required.');
        }

        $host = getenv('NEZHA_MYSQL57_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('NEZHA_MYSQL57_PORT') ?: 33321);
        $user = getenv('NEZHA_MYSQL57_USER') ?: 'root';
        $password = getenv('NEZHA_MYSQL57_PASSWORD') ?: '';
        $this->assertSame('127.0.0.1', $host);
        $this->assertNotSame(33317, $port, 'The frozen MySQL 5.7 instance must never be used.');
        $this->assertGreaterThanOrEqual(33318, $port);
        $this->assertLessThanOrEqual(33399, $port);

        $this->server = new PDO(
            "mysql:host={$host};port={$port};charset=utf8mb4",
            $user,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $version = (string) $this->server->query('SELECT VERSION()')->fetchColumn();
        $this->assertMatchesRegularExpression('/^5\.7\./', $version);

        $this->database = 'nezha_merchant_2fa_'.bin2hex(random_bytes(6));
        $this->server->exec(sprintf(
            'CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $this->database,
        ));

        $this->runtimeDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nezha-merchant-2fa-'.bin2hex(random_bytes(6));
        foreach (['', 'views', 'barrier-disable', 'barrier-totp'] as $directory) {
            $path = $directory === ''
                ? $this->runtimeDirectory
                : $this->runtimeDirectory.DIRECTORY_SEPARATOR.$directory;
            if (! mkdir($path, 0770, true) && ! is_dir($path)) {
                throw new RuntimeException("Unable to create runtime directory: {$path}");
            }
        }

        $root = dirname(__DIR__, 2);
        $this->worker = $root.DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'Support'.DIRECTORY_SEPARATOR.'MerchantTwoFactorMySql57Worker.php';
        $vendorAutoload = getenv('NEZHA_TEST_VENDOR_AUTOLOAD') ?: '';
        if (! is_file($vendorAutoload)) {
            throw new RuntimeException('NEZHA_TEST_VENDOR_AUTOLOAD must point to this lockfile vendor/autoload.php.');
        }

        $this->workerEnvironment = $this->baseProcessEnvironment() + [
            'APP_ENV' => 'testing',
            'APP_KEY' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=',
            'APP_CONFIG_CACHE' => $this->runtimeDirectory.DIRECTORY_SEPARATOR.'config.php',
            'APP_EVENTS_CACHE' => $this->runtimeDirectory.DIRECTORY_SEPARATOR.'events.php',
            'APP_PACKAGES_CACHE' => $this->runtimeDirectory.DIRECTORY_SEPARATOR.'packages.php',
            'APP_ROUTES_CACHE' => $this->runtimeDirectory.DIRECTORY_SEPARATOR.'routes.php',
            'APP_SERVICES_CACHE' => $this->runtimeDirectory.DIRECTORY_SEPARATOR.'services.php',
            'BCRYPT_ROUNDS' => '4',
            'CACHE_STORE' => 'array',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $host,
            'DB_PORT' => (string) $port,
            'DB_DATABASE' => $this->database,
            'DB_USERNAME' => $user,
            'DB_PASSWORD' => $password,
            'LOG_CHANNEL' => 'stderr',
            'MAIL_MAILER' => 'array',
            'NEZHA_MYSQL57_RUNTIME_DIR' => $this->runtimeDirectory,
            'NEZHA_TEST_VENDOR_AUTOLOAD' => $vendorAutoload,
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array',
            'VIEW_COMPILED_PATH' => $this->runtimeDirectory.DIRECTORY_SEPARATOR.'views',
        ];
    }

    protected function tearDown(): void
    {
        if (isset($this->server, $this->database)
            && preg_match('/^nezha_merchant_2fa_[a-f0-9]{12}$/', $this->database) === 1) {
            $this->server->exec(sprintf('DROP DATABASE IF EXISTS `%s`', $this->database));
        }
        if (isset($this->runtimeDirectory)) {
            $this->removeRuntimeDirectory($this->runtimeDirectory);
        }

        parent::tearDown();
    }

    public function test_self_disable_and_totp_counter_each_have_exactly_one_concurrent_winner(): void
    {
        $setup = $this->runWorker(['setup']);

        $disableStatuses = $this->race(
            'disable',
            (int) $setup['disable_vendor_id'],
            (string) $setup['disable_code'],
            'barrier-disable'
        );
        $totpStatuses = $this->race(
            'totp',
            (int) $setup['totp_vendor_id'],
            (string) $setup['totp_code'],
            'barrier-totp'
        );

        $database = new PDO(
            sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $this->workerEnvironment['DB_HOST'],
                $this->workerEnvironment['DB_PORT'],
                $this->database,
            ),
            $this->workerEnvironment['DB_USERNAME'],
            $this->workerEnvironment['DB_PASSWORD'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $disabledActor = $database->query(sprintf(
            'SELECT two_factor_enabled, two_factor_secret, auth_generation FROM vendors WHERE id = %d',
            (int) $setup['disable_vendor_id'],
        ))->fetch(PDO::FETCH_ASSOC);
        $totpCounter = (int) $database->query(sprintf(
            'SELECT two_factor_last_counter FROM vendors WHERE id = %d',
            (int) $setup['totp_vendor_id'],
        ))->fetchColumn();

        $this->assertSame(['invalid', 'success'], $disableStatuses);
        $this->assertSame(['invalid', 'success'], $totpStatuses);
        $this->assertSame(0, (int) $disabledActor['two_factor_enabled']);
        $this->assertNull($disabledActor['two_factor_secret']);
        $this->assertSame(2, (int) $disabledActor['auth_generation']);
        $this->assertSame((int) $setup['totp_counter'], $totpCounter);
        $this->assertSame(1, (int) $database->query(
            "SELECT COUNT(*) FROM merchant_two_factor_events WHERE event_type = 'disabled_by_merchant'"
        )->fetchColumn());
        $this->assertSame(1, (int) $database->query(
            "SELECT COUNT(*) FROM merchant_two_factor_events WHERE event_type = 'challenge_passed'"
        )->fetchColumn());
    }

    private function race(string $action, int $vendorId, string $code, string $barrierName): array
    {
        $barrier = $this->runtimeDirectory.DIRECTORY_SEPARATOR.$barrierName;
        $first = $this->startWorker([$action, (string) $vendorId, $code, $barrier, 'worker-1']);
        $second = $this->startWorker([$action, (string) $vendorId, $code, $barrier, 'worker-2']);
        $this->waitForFiles([
            $barrier.DIRECTORY_SEPARATOR.'worker-1.ready',
            $barrier.DIRECTORY_SEPARATOR.'worker-2.ready',
        ]);
        file_put_contents($barrier.DIRECTORY_SEPARATOR.'go', 'go');

        $statuses = [
            $this->finishWorker($first)['status'],
            $this->finishWorker($second)['status'],
        ];
        sort($statuses);

        return $statuses;
    }

    private function baseProcessEnvironment(): array
    {
        $environment = [];
        foreach (['COMSPEC', 'PATHEXT', 'PHPRC', 'SystemRoot', 'TEMP', 'TMP', 'WINDIR'] as $name) {
            $value = getenv($name);
            if (is_string($value) && $value !== '') {
                $environment[$name] = $value;
            }
        }

        return $environment;
    }

    private function startWorker(array $arguments): array
    {
        $pipes = [];
        $process = proc_open(
            array_merge([PHP_BINARY, $this->worker], $arguments),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 2),
            $this->workerEnvironment,
        );
        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start the merchant 2FA MySQL 5.7 worker.');
        }
        fclose($pipes[0]);

        return ['process' => $process, 'pipes' => $pipes];
    }

    private function finishWorker(array $worker, int $timeoutSeconds = 30): array
    {
        $deadline = microtime(true) + $timeoutSeconds;
        do {
            $status = proc_get_status($worker['process']);
            if (! $status['running']) {
                break;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);

        if ($status['running']) {
            proc_terminate($worker['process']);
            throw new RuntimeException('Merchant 2FA MySQL 5.7 worker timed out.');
        }

        $stdout = stream_get_contents($worker['pipes'][1]);
        $stderr = stream_get_contents($worker['pipes'][2]);
        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);
        proc_close($worker['process']);
        if ((int) $status['exitcode'] !== 0) {
            throw new RuntimeException("Merchant 2FA worker failed ({$status['exitcode']}): {$stderr}");
        }

        try {
            return json_decode(trim($stdout), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException(
                "Merchant 2FA worker returned invalid JSON. stdout={$stdout} stderr={$stderr}",
                previous: $exception,
            );
        }
    }

    private function runWorker(array $arguments): array
    {
        return $this->finishWorker($this->startWorker($arguments), 60);
    }

    private function waitForFiles(array $paths): void
    {
        $deadline = microtime(true) + 10;
        do {
            if (count(array_filter($paths, 'is_file')) === count($paths)) {
                return;
            }
            usleep(25_000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException('Merchant 2FA workers did not reach the start barrier.');
    }

    private function removeRuntimeDirectory(string $directory): void
    {
        $temporaryRoot = realpath(sys_get_temp_dir());
        $parent = realpath(dirname($directory));
        if ($temporaryRoot === false
            || $parent === false
            || $parent !== $temporaryRoot
            || ! str_starts_with(basename($directory), 'nezha-merchant-2fa-')) {
            throw new RuntimeException("Refusing to remove unexpected runtime directory: {$directory}");
        }
        if (! is_dir($directory)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
