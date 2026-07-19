<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ENCRYPTION_METADATA_ATTEMPTS = 10;

    private const ENCRYPTION_METADATA_RETRY_MICROSECONDS = 100_000;

    public function up(): void
    {
        $isMysql = DB::connection()->getDriverName() === 'mysql';

        if (! Schema::hasTable('user_external_identities')) {
            Schema::create('user_external_identities', function (Blueprint $table) use ($isMysql) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $provider = $table->string('provider', 32);
                $providerSubject = $table->string('provider_subject', 191);
                if ($isMysql) {
                    $provider->collation('utf8mb4_bin');
                    $providerSubject->collation('utf8mb4_bin');
                }
                $table->timestamp('last_login_at')->nullable();
                $table->timestamps();

                $table->unique(
                    ['provider', 'provider_subject'],
                    'user_external_identity_provider_subject_unique'
                );
                $table->unique(
                    ['user_id', 'provider'],
                    'user_external_identity_user_provider_unique'
                );
            });
        }

        if (! Schema::hasTable('external_identity_login_attempts')) {
            Schema::create('external_identity_login_attempts', function (Blueprint $table) use ($isMysql) {
                $table->id();
                $provider = $table->string('provider', 32);
                if ($isMysql) {
                    $provider->collation('utf8mb4_bin');
                }
                $table->char('state_hash', 64)->unique();
                $table->char('exchange_code_hash', 64)->nullable()->unique();
                $table->char('browser_secret_hash', 64);
                $table->string('oidc_nonce', 191)->nullable();
                $table->text('code_verifier')->nullable();
                $providerSubject = $table->string('provider_subject', 191)->nullable();
                if ($isMysql) {
                    $providerSubject->collation('utf8mb4_bin');
                }
                $table->text('provider_payload')->nullable();
                $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('status', 32)->default('initiated');
                $table->timestamp('expires_at')->index();
                $table->timestamp('consumed_at')->nullable();
                $table->timestamps();

                $table->index(
                    ['provider', 'provider_subject'],
                    'external_identity_attempt_provider_subject_index'
                );
            });
        }

        // L1-7: both the stable provider identifier and the temporary OIDC
        // payload are personal data. MySQL 5.7 does not inherit encryption for
        // new tables, so fail closed and verify the actual CREATE_OPTIONS.
        $this->assertTablespaceEncrypted('user_external_identities');
        $this->assertTablespaceEncrypted('external_identity_login_attempts');
    }

    public function down(): void
    {
        Schema::dropIfExists('external_identity_login_attempts');
        Schema::dropIfExists('user_external_identities');
    }

    private function assertTablespaceEncrypted(string $table): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        // The ALTER is idempotent and also repairs a partial first migration
        // run before Laravel records the migration as complete.
        DB::statement("ALTER TABLE `{$table}` ENCRYPTION='Y'");

        // Some MySQL 5.7 installations expose the new CREATE_OPTIONS value
        // shortly after ALTER TABLE returns. Retry only the metadata read;
        // an unconfirmed encrypted tablespace still fails closed.
        for ($attempt = 1; $attempt <= self::ENCRYPTION_METADATA_ATTEMPTS; $attempt++) {
            $row = DB::selectOne(
                'SELECT CREATE_OPTIONS AS create_options FROM information_schema.TABLES '
                .'WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? LIMIT 1',
                [DB::connection()->getDatabaseName(), $table]
            );
            $options = strtoupper((string) ($row->create_options ?? ''));

            if (strpos($options, 'ENCRYPTION="Y"') !== false
                || strpos($options, "ENCRYPTION='Y'") !== false) {
                return;
            }

            if ($attempt < self::ENCRYPTION_METADATA_ATTEMPTS) {
                usleep(self::ENCRYPTION_METADATA_RETRY_MICROSECONDS);
            }
        }

        throw new RuntimeException("MySQL tablespace encryption verification failed: {$table}");
    }
};
