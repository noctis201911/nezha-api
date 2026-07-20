<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TelegramIdentityMigrationTest extends TestCase
{
    public function test_migration_is_idempotent_and_sqlite_compatible(): void
    {
        Schema::dropIfExists('external_identity_login_attempts');
        Schema::dropIfExists('user_external_identities');
        Schema::dropIfExists('users');
        Schema::create('users', function (Blueprint $table) {
            $table->id();
        });

        $migration = require database_path(
            'migrations/2026_07_19_090000_create_customer_external_identity_tables.php'
        );

        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasTable('user_external_identities'));
        $this->assertTrue(Schema::hasTable('external_identity_login_attempts'));
        $this->assertTrue(Schema::hasColumn('external_identity_login_attempts', 'provider_payload'));
        $this->assertTrue(Schema::hasColumn('user_external_identities', 'provider_subject'));

        $migration->down();

        $this->assertFalse(Schema::hasTable('external_identity_login_attempts'));
        $this->assertFalse(Schema::hasTable('user_external_identities'));
    }
}
