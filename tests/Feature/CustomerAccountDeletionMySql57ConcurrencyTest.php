<?php

namespace Tests\Feature;

use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class CustomerAccountDeletionMySql57ConcurrencyTest extends TestCase
{
    private PDO $server;

    private string $database;

    private string $runtimeDirectory;

    private string $worker;

    private array $workerEnvironment;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('NEZHA_ACCOUNT_DELETION_MYSQL57') !== '1') {
            $this->markTestSkipped('Enable only against a disposable MySQL 5.7 instance.');
        }
        $host = getenv('NEZHA_MYSQL57_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('NEZHA_MYSQL57_PORT') ?: 33329);
        $user = getenv('NEZHA_MYSQL57_USER') ?: 'root';
        $password = getenv('NEZHA_MYSQL57_PASSWORD') ?: '';
        $this->assertSame('127.0.0.1', $host);
        $this->assertGreaterThanOrEqual(33318, $port);
        $this->assertLessThanOrEqual(33399, $port);

        $this->server = new PDO(
            "mysql:host={$host};port={$port};charset=utf8mb4",
            $user,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $this->assertMatchesRegularExpression('/^5\.7\./', (string) $this->server->query('SELECT VERSION()')->fetchColumn());
        $this->database = 'nezha_account_delete_'.bin2hex(random_bytes(6));
        $this->server->exec("CREATE DATABASE `{$this->database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        $this->runtimeDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nezha-account-delete-'.bin2hex(random_bytes(6));
        foreach (['', 'views', 'barrier-activate', 'barrier-order', 'barrier-contact'] as $directory) {
            $path = $directory === '' ? $this->runtimeDirectory : $this->runtimeDirectory.DIRECTORY_SEPARATOR.$directory;
            if (! mkdir($path, 0770, true) && ! is_dir($path)) {
                throw new RuntimeException("Unable to create runtime directory: {$path}");
            }
        }

        $root = dirname(__DIR__, 2);
        $this->worker = $root.'/tests/Support/CustomerAccountDeletionMySql57Worker.php';
        $autoload = getenv('NEZHA_TEST_VENDOR_AUTOLOAD') ?: '';
        if (! is_file($autoload)) {
            throw new RuntimeException('NEZHA_TEST_VENDOR_AUTOLOAD must point to this workspace vendor/autoload.php.');
        }
        $this->workerEnvironment = $this->baseProcessEnvironment() + [
            'APP_ENV' => 'testing',
            'APP_KEY' => 'base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=',
            'APP_CONFIG_CACHE' => $this->runtimeDirectory.'/config.php',
            'APP_EVENTS_CACHE' => $this->runtimeDirectory.'/events.php',
            'APP_PACKAGES_CACHE' => $this->runtimeDirectory.'/packages.php',
            'APP_ROUTES_CACHE' => $this->runtimeDirectory.'/routes.php',
            'APP_SERVICES_CACHE' => $this->runtimeDirectory.'/services.php',
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
            'NEZHA_ACCOUNT_DELETION_RUNTIME_DIR' => $this->runtimeDirectory,
            'NEZHA_TEST_VENDOR_AUTOLOAD' => $autoload,
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array',
            'VIEW_COMPILED_PATH' => $this->runtimeDirectory.'/views',
        ];
    }

    protected function tearDown(): void
    {
        if (isset($this->server, $this->database)
            && preg_match('/^nezha_account_delete_[a-f0-9]{12}$/', $this->database) === 1) {
            $this->server->exec("DROP DATABASE IF EXISTS `{$this->database}`");
        }
        if (isset($this->runtimeDirectory)) {
            $this->removeRuntimeDirectory($this->runtimeDirectory);
        }
        parent::tearDown();
    }

    public function test_first_gate_race_order_fence_and_crash_recovery_are_serializable(): void
    {
        $cycle = $this->runWorker(['migration-cycle']);
        $this->assertSame(4, $cycle['first_settings']);
        $this->assertSame(4, $cycle['second_settings']);
        $this->assertSame([
            'customer_account_deletion_events',
            'customer_account_deletion_notices',
            'customer_account_deletion_states',
        ], $cycle['second_tables']);
        foreach ($cycle['first_options'] as $options) {
            $this->assertStringContainsString('ENCRYPTION="Y"', strtoupper($options));
        }

        $setup = $this->runWorker(['setup']);

        $barrier = $this->runtimeDirectory.'/barrier-activate';
        $first = $this->startWorker(['activate', (string) $setup['race_user_id'], $barrier, 'worker-1']);
        $second = $this->startWorker(['activate', (string) $setup['race_user_id'], $barrier, 'worker-2']);
        $this->waitForFiles([$barrier.'/worker-1.ready', $barrier.'/worker-2.ready']);
        file_put_contents($barrier.'/go', 'go');
        $activationStatuses = [$this->finishWorker($first)['status'], $this->finishWorker($second)['status']];
        sort($activationStatuses);
        $this->assertSame(['success', 'success'], $activationStatuses);

        $database = $this->databaseConnection();
        $this->assertSame(1, (int) $database->query(
            'SELECT COUNT(*) FROM customer_account_deletion_states WHERE user_id='.(int) $setup['race_user_id']
        )->fetchColumn());

        $orderBarrier = $this->runtimeDirectory.'/barrier-order';
        $order = $this->startWorker(['order-hold', (string) $setup['order_user_id'], $orderBarrier]);
        $this->waitForFiles([$orderBarrier.'/locked']);
        $activation = $this->startWorker(['activate-started', (string) $setup['order_user_id'], $orderBarrier]);
        $this->waitForFiles([$orderBarrier.'/activation-started']);
        file_put_contents($orderBarrier.'/go', 'go');
        $this->assertSame('success', $this->finishWorker($order)['status']);
        $this->assertSame('success', $this->finishWorker($activation)['status']);
        $fenced = $database->query(
            'SELECT status,blocker_mask FROM customer_account_deletion_states '
            .'WHERE user_id='.(int) $setup['order_user_id'].' LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('waiting_obligations', $fenced['status']);
        $this->assertSame(1, ((int) $fenced['blocker_mask']) & 1);

        $uncheckedOrder = $this->runWorker(['unchecked-order', (string) $setup['cancel_user_id']]);
        $this->assertSame('waiting_obligations', $uncheckedOrder['status']);
        $this->assertSame(1, $uncheckedOrder['obligation_events']);

        $contactBarrier = $this->runtimeDirectory.'/barrier-contact';
        $contact = $this->startWorker(['contact-hold', (string) $setup['contact_user_id'], $contactBarrier]);
        $this->waitForFiles([$contactBarrier.'/contact-locked']);
        $contactActivation = $this->startWorker(['activate-started', (string) $setup['contact_user_id'], $contactBarrier]);
        $this->waitForFiles([$contactBarrier.'/activation-started']);
        file_put_contents($contactBarrier.'/go', 'go');
        $this->assertSame('success', $this->finishWorker($contact)['status']);
        $contactActivationResult = $this->finishWorker($contactActivation);
        $this->assertSame('success', $contactActivationResult['status']);
        $this->assertNotEmpty($contactActivationResult['request_id']);

        $recovery = $this->runWorker(['crash-resume', (string) $setup['crash_user_id']]);
        $this->assertSame('completed', $recovery['status']);
        $this->assertSame(1, $recovery['completed_step_count']);
        $this->assertSame(1, $recovery['restore_dry_run']);
        $this->assertSame(1, $recovery['restore_applied']);
        $this->assertSame(0, $recovery['restored_user_status']);
        $this->assertNull($recovery['restored_user_email']);
        $this->assertSame(0, $recovery['remaining_addresses']);
    }

    private function databaseConnection(): PDO
    {
        return new PDO(
            "mysql:host={$this->workerEnvironment['DB_HOST']};port={$this->workerEnvironment['DB_PORT']};dbname={$this->database};charset=utf8mb4",
            $this->workerEnvironment['DB_USERNAME'],
            $this->workerEnvironment['DB_PASSWORD'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
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
            throw new RuntimeException('Unable to start account-deletion MySQL 5.7 worker.');
        }
        fclose($pipes[0]);

        return ['process' => $process, 'pipes' => $pipes];
    }

    private function finishWorker(array $worker, int $timeoutSeconds = 60): array
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
            throw new RuntimeException('Account-deletion MySQL 5.7 worker timed out.');
        }
        $stdout = stream_get_contents($worker['pipes'][1]);
        $stderr = stream_get_contents($worker['pipes'][2]);
        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);
        proc_close($worker['process']);
        if ((int) $status['exitcode'] !== 0) {
            throw new RuntimeException("Worker failed ({$status['exitcode']}): {$stderr}");
        }

        try {
            return json_decode(trim($stdout), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException("Worker returned invalid JSON. stdout={$stdout} stderr={$stderr}", previous: $exception);
        }
    }

    private function runWorker(array $arguments): array
    {
        return $this->finishWorker($this->startWorker($arguments));
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
        throw new RuntimeException('Workers did not reach the concurrency barrier.');
    }

    private function removeRuntimeDirectory(string $directory): void
    {
        $temporaryRoot = realpath(sys_get_temp_dir());
        $parent = realpath(dirname($directory));
        if ($temporaryRoot === false || $parent !== $temporaryRoot
            || ! str_starts_with(basename($directory), 'nezha-account-delete-')) {
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
