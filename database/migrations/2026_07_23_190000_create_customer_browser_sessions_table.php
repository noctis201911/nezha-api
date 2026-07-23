<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_browser_sessions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->text('csrf_token_encrypted');
            $table->string('legacy_access_token_id', 100)->nullable()->index();
            $table->timestamp('last_seen_at');
            $table->timestamp('idle_expires_at')->index();
            $table->timestamp('absolute_expires_at')->index();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->timestamps();

            $table->index(
                ['user_id', 'revoked_at', 'absolute_expires_at'],
                'customer_browser_sessions_active_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_browser_sessions');
    }
};
