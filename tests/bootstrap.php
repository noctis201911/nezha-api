<?php

use Tests\Support\DatabaseSafety;

require __DIR__.'/../vendor/autoload.php';

$root = dirname(__DIR__);
$runtime = sys_get_temp_dir().'/nezha-tests-'.substr(hash('sha256', $root), 0, 12);
DatabaseSafety::assertCachedConfigIsSafe($root.'/bootstrap/cache/config.php');
$inheritedConfigCache = getenv('APP_CONFIG_CACHE');
if (is_string($inheritedConfigCache) && $inheritedConfigCache !== '') {
    DatabaseSafety::assertCachedConfigIsSafe($inheritedConfigCache);
}
DatabaseSafety::applyIsolatedEnvironment($runtime);

foreach ([
    $runtime,
    $runtime.'/views',
] as $directory) {
    if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
        throw new RuntimeException("Unable to create isolated test runtime directory: {$directory}");
    }
}
