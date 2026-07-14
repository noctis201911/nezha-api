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

        $app->make(Kernel::class)->bootstrap();
        DatabaseSafety::assertConfiguration($app['config']->get('database', []));
        IsolatedDatabaseFixtures::ensure($app);

        return $app;
    }
}
