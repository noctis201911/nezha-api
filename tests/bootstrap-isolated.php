<?php

// Allows an isolated worktree to reuse an installed vendor directory without
// loading App/Tests classes from the production checkout behind that vendor.
$root = dirname(__DIR__);
$vendorAutoload = getenv('NEZHA_TEST_VENDOR_AUTOLOAD') ?: $root.'/vendor/autoload.php';

if (! class_exists(\Illuminate\Foundation\Application::class, false)) {
    require $vendorAutoload;
}

spl_autoload_register(static function (string $class) use ($root): void {
    $prefixes = [
        'App\\' => $root.'/app/',
        'Tests\\' => $root.'/tests/',
    ];

    foreach ($prefixes as $prefix => $directory) {
        if (! str_starts_with($class, $prefix)) {
            continue;
        }

        $relative = substr($class, strlen($prefix));
        $path = $directory.str_replace('\\', DIRECTORY_SEPARATOR, $relative).'.php';
        if (is_file($path)) {
            require $path;
        }

        return;
    }
}, true, true);
