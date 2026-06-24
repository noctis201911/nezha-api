<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $t) {
            if (!Schema::hasColumn('restaurants', 'timeout_notify_telegram')) {
                $t->boolean('timeout_notify_telegram')->default(1)->after('timeout_notify_email');
            }
            if (!Schema::hasColumn('restaurants', 'nezha_notify_email')) {
                $t->string('nezha_notify_email', 191)->nullable()->after('timeout_notify_telegram');
            }
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $t) {
            foreach (['timeout_notify_telegram', 'nezha_notify_email'] as $c) {
                if (Schema::hasColumn('restaurants', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
