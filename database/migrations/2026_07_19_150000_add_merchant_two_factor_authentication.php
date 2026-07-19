<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['vendors', 'vendor_employees'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->text('two_factor_secret')->nullable();
                $table->boolean('two_factor_enabled')->default(false)->index();
                $table->text('two_factor_recovery_codes')->nullable();
                $table->timestamp('two_factor_required_at')->nullable()->index();
                $table->timestamp('two_factor_enrolled_at')->nullable();
                $table->unsignedBigInteger('two_factor_last_counter')->nullable();
                $table->unsignedBigInteger('auth_generation')->default(0);

                // Rows present during this migration remain in a non-enforcing
                // grace state until the release owner records an exact deadline.
                $table->boolean('two_factor_grace_pending')->default(true)->index();
            });

            if (DB::getDriverName() === 'mysql') {
                DB::statement(
                    "ALTER TABLE `{$tableName}` MODIFY `two_factor_grace_pending` TINYINT(1) NOT NULL DEFAULT 0"
                );
            } else {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->boolean('two_factor_grace_pending')->default(false)->change();
                });
            }
        }

        Schema::create('merchant_two_factor_challenges', function (Blueprint $table): void {
            $table->id();
            $table->char('token_hash', 64)->unique();
            $table->string('actor_type', 16);
            $table->unsignedBigInteger('actor_id');
            $table->string('purpose', 16);
            $table->text('pending_secret')->nullable();
            $table->unsignedBigInteger('auth_generation');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->char('ip_hash', 64)->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['actor_type', 'actor_id', 'expires_at'], 'merchant_2fa_challenge_actor_idx');
        });

        Schema::create('merchant_two_factor_events', function (Blueprint $table): void {
            $table->id();
            $table->string('actor_type', 16);
            $table->unsignedBigInteger('actor_id');
            $table->string('event_type', 64);
            $table->unsignedBigInteger('auth_generation')->default(0);
            $table->string('initiator_type', 24)->nullable();
            $table->unsignedBigInteger('initiator_id')->nullable();
            $table->unsignedBigInteger('approver_one_id')->nullable();
            $table->unsignedBigInteger('approver_two_id')->nullable();
            $table->string('reason', 500)->nullable();
            $table->char('ip_hash', 64)->nullable();
            $table->char('user_agent_hash', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['actor_type', 'actor_id', 'created_at'], 'merchant_2fa_event_actor_idx');
            $table->index(['event_type', 'created_at'], 'merchant_2fa_event_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_two_factor_events');
        Schema::dropIfExists('merchant_two_factor_challenges');

        foreach (['vendors', 'vendor_employees'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn([
                    'two_factor_secret',
                    'two_factor_enabled',
                    'two_factor_recovery_codes',
                    'two_factor_required_at',
                    'two_factor_enrolled_at',
                    'two_factor_last_counter',
                    'auth_generation',
                    'two_factor_grace_pending',
                ]);
            });
        }
    }
};
