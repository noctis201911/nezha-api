<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Tests\Support\DatabaseSafety;
use Tests\Support\IsolatedDatabaseFixtures;

trait CreatesApplication
{
    /**
     * Creates the application.
     *
     * @return \Illuminate\Foundation\Application
     */
    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $configCache = getenv('APP_CONFIG_CACHE');
        if (DIRECTORY_SEPARATOR === '\\'
            && is_string($configCache)
            && preg_match('/^[A-Za-z]:[\\\\\\/]/', $configCache) === 1) {
            $app->addAbsoluteCachePathPrefix(substr($configCache, 0, 3));
        }

        $app->make(Kernel::class)->bootstrap();
        DatabaseSafety::assertConfiguration($app['config']->get('database', []));
        IsolatedDatabaseFixtures::ensure($app);

        return $app;
    }
}
