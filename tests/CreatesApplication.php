<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Tests\Support\DatabaseSafety;

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

        return $app;
    }
}
