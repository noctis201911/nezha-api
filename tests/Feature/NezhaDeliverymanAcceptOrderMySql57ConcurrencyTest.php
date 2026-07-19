<?php

namespace Tests\Feature;

use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class NezhaDeliverymanAcceptOrderMySql57ConcurrencyTest extends TestCase
{
    private PDO $server;

    private string $database;

    private string $runtimeDirectory;

    private string $worker;

    private array $workerEnvironment;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('NEZHA_MYSQL57_CONCURRENCY') !== '1') {
            $this->markTestSkipped('Set NEZHA_MYSQL57_CONCURRENCY=1 to run the isolated MySQL 5.7 race test.');
        }
        if (! extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('The pdo_mysql extension is required.');
        }

        $host = getenv('NEZHA_MYSQL57_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('NEZHA_MYSQL57_PORT') ?: 33317);
        $user = getenv('NEZHA_MYSQL57_USER') ?: 'root';
        $password = getenv('NEZHA_MYSQL57_PASSWORD') ?: '';

        $this->assertSame('127.0.0.1', $host);
        $this->assertGreaterThanOrEqual(33317, $port);
        $this->assertLessThanOrEqual(33399, $port);

        $this->server = new PDO(
            "mysql:host={$host};port={$port};charset=utf8mb4",
            $user,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $version = (string) $this->server->query('SELECT VERSION()')->fetchColumn();
        $this->assertMatchesRegularExpression('/^5\.7\./', $version);

        $this->database = 'nezha_api_concurrency_'.bin2hex(random_bytes(6));
        $this->assertMatchesRegularExpression('/^nezha_api_concurrency_[a-f0-9]{12}$/', $this->database);
        $this->server->exec(sprintf(
            'CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $this->database,
        ));

        $this->runtimeDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nezha-api-concurrency-'.bin2hex(random_bytes(6));
        if (! mkdir($this->runtimeDirectory, 0770, true) && ! is_dir($this->runtimeDirectory)) {
            throw new RuntimeException('Unable to create the isolated concurrency runtime directory.');
        }
        foreach (['views', 'barrier'] as $directory) {
            $path = $this->runtimeDirectory.DIRECTORY_SEPARATOR.$directory;
            if (! mkdir($path, 0770, true) && ! is_dir($path)) {
                throw new RuntimeException("Unable to create runtime directory: {$path}");
            }
        }

        $root = dirname(__DIR__, 2);
        $this->worker = $root.DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'Support'.DIRECTORY_SEPARATOR.'DeliverymanAcceptOrderMySql57Worker.php';
        $vendorAutoload = getenv('NEZHA_TEST_VENDOR_AUTOLOAD') ?: '';
        if (! is_file($vendorAutoload)) {
            throw new RuntimeException('NEZHA_TEST_VENDOR_AUTOLOAD must point to a same-lockfile vendor/autoload.php.');
        }

        $this->workerEnvironment = $this->baseProcessEnvironment() + [
            'APP_ENV' => 'testing',
            'APP_KEY' => 'base64:MDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDA=',
            'APP_CONFIG_CACHE' => $this->runtimeDirectory.DIRECTORY_SEPARATOR.'config.php',
            'APP_EVENTS_CACHE' => $this->runtimeDirectory.DIRECTORY_SEPARATOR.'events.php',
            'APP_PACKAGES_CACHE' => $this->runtimeDirectory.DIRECTORY_SEPARATOR.'packages.php',
            'APP_ROUTES_CACHE' => $this->runtimeDirectory.DIRECTORY_SEPARATOR.'routes.php',
            'APP_SERVICES_CACHE' => $this->runtimeDirectory.DIRECTORY_SEPARATOR.'services.php',
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
            && preg_match('/^nezha_api_concurrency_[a-f0-9]{12}$/', $this->database) === 1) {
            $this->server->exec(sprintf('DROP DATABASE IF EXISTS `%s`', $this->database));
        }

        if (isset($this->runtimeDirectory)) {
            $this->removeRuntimeDirectory($this->runtimeDirectory);
        }

        parent::tearDown();
    }

    public function test_two_simultaneous_claims_have_exactly_one_winner_and_one_set_of_side_effects(): void
    {
        $setup = $this->runWorker(['setup']);
        $orderId = (int) $setup['order_id'];
        $deliveryMen = $setup['delivery_men'];

        $barrier = $this->runtimeDirectory.DIRECTORY_SEPARATOR.'barrier';
        $first = $this->startWorker(['claim', 'dm-one-token', (string) $orderId, $barrier]);
        $second = $this->startWorker(['claim', 'dm-two-token', (string) $orderId, $barrier]);

        $this->waitForFiles([
            $barrier.DIRECTORY_SEPARATOR.'dm-one-token.ready',
            $barrier.DIRECTORY_SEPARATOR.'dm-two-token.ready',
        ]);
        file_put_contents($barrier.DIRECTORY_SEPARATOR.'go', 'go');

        $firstResult = $this->finishWorker($first);
        $secondResult = $this->finishWorker($second);

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
        $assignedDeliveryManId = (int) $database
            ->query("SELECT delivery_man_id FROM orders WHERE id = {$orderId}")
            ->fetchColumn();
        $riderCounts = $database->query(
            'SELECT id, current_orders, assigned_order_count FROM delivery_men ORDER BY id',
        )->fetchAll(PDO::FETCH_ASSOC);

        $observed = [
            'statuses' => [(int) $firstResult['status'], (int) $secondResult['status']],
            'assigned_delivery_man_id' => $assignedDeliveryManId,
            'current_orders_total' => array_sum(array_column($riderCounts, 'current_orders')),
            'assigned_order_count_total' => array_sum(array_column($riderCounts, 'assigned_order_count')),
            'subscription_logs' => (int) $database->query('SELECT COUNT(*) FROM subscription_logs')->fetchColumn(),
            'user_notifications' => (int) $database->query('SELECT COUNT(*) FROM user_notifications')->fetchColumn(),
        ];
        sort($observed['statuses']);

        $this->assertContains($assignedDeliveryManId, array_map('intval', array_values($deliveryMen)));
        $this->assertSame(
            [
                'statuses' => [200, 404],
                'assigned_delivery_man_id' => $assignedDeliveryManId,
                'current_orders_total' => 1,
                'assigned_order_count_total' => 1,
                'subscription_logs' => 0,
                'user_notifications' => 0,
            ],
            $observed,
            'Concurrent accept_order observations: '.json_encode($observed, JSON_THROW_ON_ERROR),
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
        $command = array_merge([PHP_BINARY, $this->worker], $arguments);
        $pipes = [];
        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            dirname(__DIR__, 2),
            $this->workerEnvironment,
        );
        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start the MySQL 5.7 concurrency worker.');
        }
        fclose($pipes[0]);

        return ['process' => $process, 'pipes' => $pipes];
    }

    private function finishWorker(array $worker, int $timeoutSeconds = 20): array
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
            throw new RuntimeException('MySQL 5.7 concurrency worker timed out.');
        }

        $stdout = stream_get_contents($worker['pipes'][1]);
        $stderr = stream_get_contents($worker['pipes'][2]);
        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);
        proc_close($worker['process']);

        if ((int) $status['exitcode'] !== 0) {
            throw new RuntimeException("Concurrency worker failed ({$status['exitcode']}): {$stderr}");
        }

        try {
            return json_decode(trim($stdout), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException(
                "Concurrency worker returned invalid JSON. stdout={$stdout} stderr={$stderr}",
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

        throw new RuntimeException('Concurrency workers did not reach the start barrier.');
    }

    private function removeRuntimeDirectory(string $directory): void
    {
        $temporaryRoot = realpath(sys_get_temp_dir());
        $parent = realpath(dirname($directory));
        if ($temporaryRoot === false
            || $parent === false
            || $parent !== $temporaryRoot
            || ! str_starts_with(basename($directory), 'nezha-api-concurrency-')) {
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
