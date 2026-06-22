<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'system_notif_seen_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('system_notif_seen_at')->nullable();
            });
            // Backfill existing users so pre-existing notifications count as already
            // seen (avoid a sudden large unread badge for everyone on rollout).
            DB::table('users')->update(['system_notif_seen_at' => now()]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'system_notif_seen_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('system_notif_seen_at');
            });
        }
    }
};
