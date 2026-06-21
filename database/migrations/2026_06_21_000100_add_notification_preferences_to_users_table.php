<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Nezha: per-customer push notification preferences (master / order_progress / chat).
// Nullable JSON, null = all enabled (opt-out model; preserves existing behavior).
// Gates only FCM push, never the in-app notification inbox.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'notification_preferences')) {
                $table->json('notification_preferences')->nullable()->after('cm_firebase_token');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'notification_preferences')) {
                $table->dropColumn('notification_preferences');
            }
        });
    }
};
