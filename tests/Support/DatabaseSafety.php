<?php

namespace Tests\Support;

use RuntimeException;

final class DatabaseSafety
{
    private const SAFE_CONNECTION = 'sqlite';
    private const SAFE_DATABASE = ':memory:';

    public static function applyIsolatedEnvironment(string $runtimeDirectory): void
    {
        foreach ([
            'APP_ENV' => 'testing',
            'APP_KEY' => 'base64:MDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDA=',
            'APP_CONFIG_CACHE' => $runtimeDirectory.'/config.php',
            'APP_EVENTS_CACHE' => $runtimeDirectory.'/events.php',
            'APP_PACKAGES_CACHE' => $runtimeDirectory.'/packages.php',
            'APP_ROUTES_CACHE' => $runtimeDirectory.'/routes.php',
            'APP_SERVICES_CACHE' => $runtimeDirectory.'/services.php',
            'CACHE_STORE' => 'array',
            'DB_CONNECTION' => self::SAFE_CONNECTION,
            'DB_DATABASE' => self::SAFE_DATABASE,
            'LOG_CHANNEL' => 'stderr',
            'MAIL_MAILER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array',
            'VIEW_COMPILED_PATH' => $runtimeDirectory.'/views',
        ] as $name => $value) {
            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }

    public static function assertCachedConfigIsSafe(string $configPath): void
    {
        if (!is_file($configPath)) {
            return;
        }

        $cached = require $configPath;
        self::assertConfiguration(is_array($cached) ? ($cached['database'] ?? []) : []);
    }

    public static function assertConfiguration(array $database): void
    {
        $connection = $database['default'] ?? null;
        $databaseName = $database['connections'][$connection]['database'] ?? null;

        if ($connection !== self::SAFE_CONNECTION || $databaseName !== self::SAFE_DATABASE) {
            throw new RuntimeException(sprintf(
                'Refusing to run tests outside isolated sqlite :memory: (connection=%s, database=%s).',
                is_scalar($connection) ? (string) $connection : 'unknown',
                is_scalar($databaseName) ? (string) $databaseName : 'unknown'
            ));
        }
    }
}
