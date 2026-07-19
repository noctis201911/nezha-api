<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_external_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('provider', 32)->collation('utf8mb4_bin');
            $table->string('provider_subject', 191)->collation('utf8mb4_bin');
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

        Schema::create('external_identity_login_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32)->collation('utf8mb4_bin');
            $table->char('state_hash', 64)->unique();
            $table->char('exchange_code_hash', 64)->nullable()->unique();
            $table->char('browser_secret_hash', 64);
            $table->string('oidc_nonce', 191)->nullable();
            $table->text('code_verifier')->nullable();
            $table->string('provider_subject', 191)->nullable()->collation('utf8mb4_bin');
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

    public function down(): void
    {
        Schema::dropIfExists('external_identity_login_attempts');
        Schema::dropIfExists('user_external_identities');
    }
};
