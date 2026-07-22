<?php

use App\Services\Auth\EmailCanonicalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ENCRYPTION_METADATA_ATTEMPTS = 10;

    public function up(): void
    {
        $this->assertVerifiedEmailsCanBeCanonicalized();
        $isMysql = DB::connection()->getDriverName() === 'mysql';
        $addCanonical = ! Schema::hasColumn('users', 'email_canonical');
        $addVerificationMethod = ! Schema::hasColumn('users', 'email_verification_method');

        Schema::table('users', function (Blueprint $table) use (
            $isMysql,
            $addCanonical,
            $addVerificationMethod,
        ) {
            // The legacy column is 100 characters while the public contract
            // accepts canonical addresses up to the MySQL-5.7-safe index
            // length of 191.
            $table->string('email', 191)->nullable()->change();
            if ($addCanonical) {
                $canonical = $table->string('email_canonical', 191)->nullable()->after('email');
                if ($isMysql) {
                    $canonical->collation('utf8mb4_bin');
                }
            }
            if ($addVerificationMethod) {
                $table->string('email_verification_method', 32)->nullable()->after('email_verified_at');
            }
        });

        $canonicalizer = app(EmailCanonicalizer::class);
        DB::table('users')
            ->whereNotNull('email')
            ->where(function ($query) {
                $query->whereNotNull('email_verified_at')
                    ->orWhere('is_email_verified', 1);
            })
            ->orderBy('id')
            ->chunkById(100, function ($users) use ($canonicalizer) {
                foreach ($users as $user) {
                    DB::table('users')->where('id', $user->id)->update([
                        'email_canonical' => $canonicalizer->canonicalize((string) $user->email),
                        'email_verified_at' => $user->email_verified_at ?: now(),
                        'email_verification_method' => $user->email_verification_method ?? 'legacy_verified',
                    ]);
                }
            });

        if (! Schema::hasIndex('users', 'users_email_canonical_unique')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unique('email_canonical', 'users_email_canonical_unique');
            });
        }

        if (! Schema::hasTable('customer_email_auth_challenges')) {
            Schema::create('customer_email_auth_challenges', function (Blueprint $table) use ($isMysql) {
                $table->id();
                $table->char('public_id', 43)->unique();
                $table->string('purpose', 32)->default('unified_auth');
                $table->text('email_ciphertext');
                $table->char('email_lookup_hash', 64)->index();
                $table->char('active_email_hash', 64)->nullable()->unique();
                $table->char('otp_hash', 64);
                $table->char('browser_secret_hash', 64);
                $table->char('completion_token_hash', 64)->nullable()->unique();
                $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
                $status = $table->string('status', 32)->default('pending_delivery');
                if ($isMysql) {
                    $status->collation('utf8mb4_bin');
                }
                $table->unsignedTinyInteger('attempts_remaining')->default(5);
                $table->unsignedInteger('generation')->default(1);
                $table->text('registration_payload')->nullable();
                $table->timestamp('expires_at')->index();
                $table->timestamp('resend_after');
                $table->timestamp('delivery_succeeded_at')->nullable();
                $table->timestamp('verified_at')->nullable();
                $table->timestamp('consumed_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('customer_auth_consents')) {
            Schema::create('customer_auth_consents', function (Blueprint $table) use ($isMysql) {
                $table->id();
                // Preserve the acceptance event without blocking the platform's
                // existing customer-erasure path. Once the account is deleted the
                // row is no longer directly attributable to that customer.
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('action', 32);
                $table->string('terms_version', 64);
                $table->string('privacy_version', 64);
                $table->string('locale', 16);
                $channel = $table->string('channel', 32);
                $authMethod = $table->string('auth_method', 32);
                if ($isMysql) {
                    $channel->collation('utf8mb4_bin');
                    $authMethod->collation('utf8mb4_bin');
                }
                $table->timestamp('accepted_at');
                $table->index(['user_id', 'action'], 'customer_auth_consent_user_action_index');
            });
        }

        foreach ([
            'email_auth_login_status',
            'email_auth_registration_status',
            'email_auth_mail_status',
            'google_auth_registration_status',
        ] as $key) {
            DB::table('business_settings')->updateOrInsert(
                ['key' => $key],
                ['value' => '0', 'updated_at' => now(), 'created_at' => now()],
            );
        }

        $this->assertTablespaceEncrypted('customer_email_auth_challenges');
        $this->assertTablespaceEncrypted('customer_auth_consents');
    }

    public function down(): void
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('Unified customer identities are forward-only; disable feature flags instead.');
        }

        Schema::dropIfExists('customer_auth_consents');
        Schema::dropIfExists('customer_email_auth_challenges');
        if (Schema::hasIndex('users', 'users_email_canonical_unique')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropUnique('users_email_canonical_unique');
            });
        }
        Schema::table('users', function (Blueprint $table) {
            $columns = array_values(array_filter([
                Schema::hasColumn('users', 'email_canonical') ? 'email_canonical' : null,
                Schema::hasColumn('users', 'email_verification_method') ? 'email_verification_method' : null,
            ]));
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
            // Do not narrow users.email during a test rollback: data written
            // under this migration may legitimately exceed the legacy limit.
        });
    }

    private function assertVerifiedEmailsCanBeCanonicalized(): void
    {
        $canonicalizer = app(EmailCanonicalizer::class);
        $seen = [];

        DB::table('users')
            ->select(['id', 'email'])
            ->whereNotNull('email')
            ->where(function ($query) {
                $query->whereNotNull('email_verified_at')
                    ->orWhere('is_email_verified', 1);
            })
            ->orderBy('id')
            ->chunkById(100, function ($users) use ($canonicalizer, &$seen) {
                foreach ($users as $user) {
                    $canonical = $canonicalizer->canonicalize((string) $user->email);
                    if (isset($seen[$canonical])) {
                        throw new RuntimeException('Duplicate verified canonical email detected.');
                    }
                    $seen[$canonical] = (int) $user->id;
                }
            });
    }

    private function assertTablespaceEncrypted(string $table): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE `{$table}` ENCRYPTION='Y'");
        for ($attempt = 1; $attempt <= self::ENCRYPTION_METADATA_ATTEMPTS; $attempt++) {
            $row = DB::selectOne(
                'SELECT CREATE_OPTIONS AS create_options FROM information_schema.TABLES '
                .'WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? LIMIT 1',
                [DB::connection()->getDatabaseName(), $table]
            );
            $options = strtoupper((string) ($row->create_options ?? ''));
            if (str_contains($options, 'ENCRYPTION="Y"') || str_contains($options, "ENCRYPTION='Y'")) {
                return;
            }
            if ($attempt < self::ENCRYPTION_METADATA_ATTEMPTS) {
                usleep(100_000);
            }
        }

        throw new RuntimeException("MySQL tablespace encryption verification failed: {$table}");
    }
};
