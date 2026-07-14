<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\DatabaseSafety;

class DatabaseSafetyTest extends TestCase
{
    public function test_it_accepts_only_in_memory_sqlite(): void
    {
        DatabaseSafety::assertConfiguration([
            'default' => 'sqlite',
            'connections' => ['sqlite' => ['database' => ':memory:']],
        ]);

        $this->addToAssertionCount(1);
    }

    public function test_it_rejects_a_production_mysql_database_before_boot(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing to run tests outside isolated sqlite :memory:');

        DatabaseSafety::assertConfiguration([
            'default' => 'mysql',
            'connections' => ['mysql' => ['database' => 'sql_api_nezha_am']],
        ]);
    }

    public function test_it_rejects_a_production_config_cache_before_laravel_boots(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'nezha-config-');
        $this->assertNotFalse($path);
        file_put_contents($path, <<<'PHP'
<?php return ['database' => [
    'default' => 'mysql',
    'connections' => ['mysql' => ['database' => 'sql_api_nezha_am']],
]];
PHP);

        try {
            $this->expectException(RuntimeException::class);
            DatabaseSafety::assertCachedConfigIsSafe($path);
        } finally {
            @unlink($path);
        }
    }
}
