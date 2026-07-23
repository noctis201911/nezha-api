<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'customer_account_deletion_states',
        'customer_account_deletion_events',
        'customer_account_deletion_notices',
    ];

    private const SETTINGS = [
        'nezha_account_deletion_intake_enabled',
        'nezha_account_deletion_countdown_enabled',
        'nezha_account_deletion_execution_enabled',
        'nezha_account_deletion_purge_enabled',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('customer_account_deletion_states')) {
            Schema::create('customer_account_deletion_states', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->unique();
                $table->uuid('request_id')->nullable()->index();
                $table->unsignedBigInteger('source_order_id')->nullable()->index();
                $table->string('source', 24)->nullable();
                $table->string('status', 40)->default('open')->index();
                $table->unsignedInteger('blocker_mask')->default(0);
                $table->unsignedBigInteger('obligation_epoch')->default(0);
                $table->unsignedBigInteger('state_version')->default(0);
                $table->string('purge_matrix_version', 64);
                $table->string('copy_version', 64)->nullable();
                $table->string('copy_locale', 16)->default('zh-CN');
                // MySQL 5.7 with explicit_defaults_for_timestamp=OFF would otherwise
                // silently add ON UPDATE CURRENT_TIMESTAMP to the first non-null TIMESTAMP.
                $table->dateTime('requested_at')->nullable();
                $table->timestamp('last_blocker_cleared_at')->nullable();
                $table->timestamp('countdown_started_at')->nullable();
                $table->timestamp('scheduled_for')->nullable()->index();
                $table->timestamp('sessions_revoke_requested_at')->nullable();
                $table->timestamp('sessions_revoked_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->timestamp('execution_started_at')->nullable();
                $table->timestamp('account_closed_at')->nullable();
                $table->timestamp('purge_completed_at')->nullable();
                $table->unsignedBigInteger('obligation_epoch_at_claim')->nullable();
                $table->string('execution_owner_token', 64)->nullable()->index();
                $table->string('legal_hold_scope', 64)->nullable();
                $table->string('legal_hold_owner', 100)->nullable();
                $table->timestamp('legal_hold_expires_at')->nullable();
                $table->unsignedInteger('attempt_count')->default(0);
                $table->timestamp('next_retry_at')->nullable()->index();
                $table->string('failure_code', 100)->nullable();
                $table->char('challenge_hash', 64)->nullable();
                $table->timestamp('challenge_expires_at')->nullable();
                $table->string('challenge_auth_context', 191)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('customer_account_deletion_events')) {
            Schema::create('customer_account_deletion_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('state_id')->index();
                $table->uuid('request_id')->index();
                $table->string('event_type', 64)->index();
                $table->unsignedBigInteger('state_version')->default(0);
                $table->string('dedupe_key', 191)->unique();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('customer_account_deletion_notices')) {
            Schema::create('customer_account_deletion_notices', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('state_id')->index();
                $table->uuid('request_id')->unique();
                $table->string('channel', 16)->default('email');
                // The only post-destruction routing datum is encrypted at the
                // application layer and erased immediately after send/cancel.
                $table->text('recipient_ciphertext')->nullable();
                $table->string('locale', 16)->default('zh-CN');
                $table->string('status', 32)->default('waiting_execution')->index();
                $table->timestamp('purge_completed_at')->nullable();
                $table->timestamp('send_due_at')->nullable()->index();
                $table->timestamp('legal_due_at')->nullable()->index();
                $table->timestamp('claimed_at')->nullable();
                $table->string('owner_token', 64)->nullable()->index();
                $table->unsignedInteger('attempt_count')->default(0);
                $table->timestamp('next_retry_at')->nullable()->index();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('recipient_cleared_at')->nullable();
                $table->string('last_error_code', 100)->nullable();
                $table->timestamps();
            });
        }

        foreach (self::SETTINGS as $key) {
            if (! DB::table('business_settings')->where('key', $key)->exists()) {
                DB::table('business_settings')->insert([
                    'key' => $key,
                    'value' => '0',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        foreach (self::TABLES as $table) {
            $this->assertTablespaceEncrypted($table);
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("Refusing to roll back non-empty account-deletion table: {$table}");
            }
        }
        if (DB::table('business_settings')
            ->whereIn('key', self::SETTINGS)
            ->where('value', '!=', '0')
            ->exists()) {
            throw new RuntimeException('Refusing to roll back while an account-deletion feature flag is enabled.');
        }

        Schema::dropIfExists('customer_account_deletion_notices');
        Schema::dropIfExists('customer_account_deletion_events');
        Schema::dropIfExists('customer_account_deletion_states');

        DB::table('business_settings')->whereIn('key', self::SETTINGS)->delete();
    }

    private function assertTablespaceEncrypted(string $table): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE `{$table}` ENCRYPTION='Y'");

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $row = DB::selectOne(
                'SELECT CREATE_OPTIONS AS create_options FROM information_schema.TABLES '
                .'WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? LIMIT 1',
                [DB::connection()->getDatabaseName(), $table]
            );
            $options = strtoupper((string) ($row->create_options ?? ''));
            if (str_contains($options, 'ENCRYPTION="Y"') || str_contains($options, "ENCRYPTION='Y'")) {
                return;
            }
            usleep(100_000);
        }

        throw new RuntimeException("MySQL tablespace encryption verification failed: {$table}");
    }
};
