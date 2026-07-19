<?php

declare(strict_types=1);

$options = getopt('', ['env:', 'socket:', 'database:', 'action:']);
$envPath = $options['env'] ?? null;
$socketPath = $options['socket'] ?? null;
$database = $options['database'] ?? null;
$action = $options['action'] ?? null;

if (
    ! is_string($database)
    || preg_match('/^nezha_tg_identity_test_[a-zA-Z0-9_]+$/', $database) !== 1
    || ! in_array($action, ['create', 'assert', 'assert-rolled-back', 'drop'], true)
) {
    fwrite(STDERR, "Invalid isolated MySQL probe arguments.\n");
    exit(64);
}

if (is_string($socketPath)) {
    if (
        preg_match('#^/www/wwwroot/nezha-tg-mysql57-[a-zA-Z0-9_-]+/mysql\.sock$#', $socketPath) !== 1
        || @filetype($socketPath) !== 'socket'
    ) {
        fwrite(STDERR, "Invalid isolated MySQL socket.\n");
        exit(65);
    }

    $dsn = "mysql:unix_socket={$socketPath};charset=utf8mb4";
    $username = 'root';
    $password = '';
} else {
    if (! is_string($envPath) || ! is_file($envPath)) {
        fwrite(STDERR, "Missing database environment file.\n");
        exit(65);
    }

    $env = parse_ini_file($envPath, false, INI_SCANNER_RAW);
    foreach (['DB_HOST', 'DB_PORT', 'DB_USERNAME', 'DB_PASSWORD'] as $key) {
        if (! array_key_exists($key, $env)) {
            fwrite(STDERR, "Missing database environment key: {$key}\n");
            exit(65);
        }
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;charset=utf8mb4',
        $env['DB_HOST'],
        $env['DB_PORT'],
    );
    $username = $env['DB_USERNAME'];
    $password = $env['DB_PASSWORD'];
}

$pdo = new PDO($dsn, $username, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$quotedDatabase = '`'.$database.'`';

if ($action === 'create') {
    $exists = (int) $pdo->query(
        'SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '.$pdo->quote($database)
    )->fetchColumn();
    if ($exists !== 0) {
        throw new RuntimeException('Refusing to reuse an existing migration probe database.');
    }

    $pdo->exec("CREATE DATABASE {$quotedDatabase} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE {$quotedDatabase}.users (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        phone VARCHAR(191) NULL,
        PRIMARY KEY (id),
        UNIQUE KEY users_phone_unique (phone)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "created\n";
    exit(0);
}

if ($action === 'drop') {
    $pdo->exec("DROP DATABASE IF EXISTS {$quotedDatabase}");
    echo "dropped\n";
    exit(0);
}

$pdo->exec("USE {$quotedDatabase}");
$tables = $pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '.$pdo->quote($database))
    ->fetchAll(PDO::FETCH_COLUMN);

if ($action === 'assert-rolled-back') {
    foreach (['user_external_identities', 'external_identity_login_attempts'] as $table) {
        if (in_array($table, $tables, true)) {
            throw new RuntimeException("Rollback left table behind: {$table}");
        }
    }
    echo "rollback-ok\n";
    exit(0);
}

foreach (['user_external_identities', 'external_identity_login_attempts'] as $table) {
    if (! in_array($table, $tables, true)) {
        throw new RuntimeException("Migration table missing: {$table}");
    }

    $createOptions = (string) $pdo->query(
        'SELECT CREATE_OPTIONS FROM information_schema.TABLES WHERE TABLE_SCHEMA = '
        .$pdo->quote($database).' AND TABLE_NAME = '.$pdo->quote($table)
    )->fetchColumn();
    if (stripos($createOptions, 'ENCRYPTION="Y"') === false
        && stripos($createOptions, "ENCRYPTION='Y'") === false) {
        throw new RuntimeException("Migration table is not encrypted: {$table}");
    }
}

$pdo->exec('DELETE FROM user_external_identities');
$pdo->exec('DELETE FROM external_identity_login_attempts');
$pdo->exec('DELETE FROM users');
$pdo->exec("INSERT INTO users (id, phone) VALUES (1, '+37499001001'), (2, '+37499001002')");
$pdo->exec("INSERT INTO user_external_identities
    (user_id, provider, provider_subject, created_at, updated_at)
    VALUES (1, 'telegram', 'subject-1', NOW(), NOW())");

$expectDuplicate = static function (PDO $pdo, string $sql): void {
    try {
        $pdo->exec($sql);
    } catch (PDOException $error) {
        if ((int) ($error->errorInfo[1] ?? 0) === 1062) {
            return;
        }
        throw $error;
    }
    throw new RuntimeException('Expected MySQL unique constraint did not reject duplicate data.');
};

$expectDuplicate(
    $pdo,
    "INSERT INTO user_external_identities
        (user_id, provider, provider_subject, created_at, updated_at)
        VALUES (2, 'telegram', 'subject-1', NOW(), NOW())",
);
$expectDuplicate(
    $pdo,
    "INSERT INTO user_external_identities
        (user_id, provider, provider_subject, created_at, updated_at)
        VALUES (1, 'telegram', 'subject-2', NOW(), NOW())",
);

$pdo->exec("INSERT INTO external_identity_login_attempts
    (provider, state_hash, exchange_code_hash, browser_secret_hash, status, expires_at, created_at, updated_at)
    VALUES ('telegram', REPEAT('a', 64), REPEAT('b', 64), REPEAT('c', 64), 'initiated', DATE_ADD(NOW(), INTERVAL 10 MINUTE), NOW(), NOW())");
$expectDuplicate(
    $pdo,
    "INSERT INTO external_identity_login_attempts
        (provider, state_hash, browser_secret_hash, status, expires_at, created_at, updated_at)
        VALUES ('telegram', REPEAT('a', 64), REPEAT('d', 64), 'initiated', DATE_ADD(NOW(), INTERVAL 10 MINUTE), NOW(), NOW())",
);

echo "mysql57-schema-encryption-and-constraints-ok\n";
