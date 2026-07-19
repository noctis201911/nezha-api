<?php

use Tests\Support\DatabaseSafety;

require __DIR__.'/../vendor/autoload.php';

$root = dirname(__DIR__);
$runtimeName = 'nezha-tests-'.substr(hash('sha256', $root), 0, 12);

// Laravel's cache-path normalizer recognises Unix roots but does not treat a
// Windows drive prefix (for example C:\\...) as absolute. Use an ignored,
// project-relative runtime on Windows so it cannot be prefixed with the base
// path a second time. Other platforms keep the system temp directory.
$runtime = DIRECTORY_SEPARATOR === '\\'
    ? 'storage/framework/testing/'.$runtimeName
    : sys_get_temp_dir().'/'.$runtimeName;
$runtimeDirectory = DIRECTORY_SEPARATOR === '\\' ? $root.'/'.$runtime : $runtime;
DatabaseSafety::assertCachedConfigIsSafe($root.'/bootstrap/cache/config.php');
$inheritedConfigCache = getenv('APP_CONFIG_CACHE');
if (is_string($inheritedConfigCache) && $inheritedConfigCache !== '') {
    DatabaseSafety::assertCachedConfigIsSafe($inheritedConfigCache);
}
DatabaseSafety::applyIsolatedEnvironment($runtime);

foreach ([
    $runtimeDirectory,
    $runtimeDirectory.'/views',
] as $directory) {
    if (! is_dir($directory) && ! mkdir($directory, 0770, true) && ! is_dir($directory)) {
        throw new RuntimeException("Unable to create isolated test runtime directory: {$directory}");
    }
}
