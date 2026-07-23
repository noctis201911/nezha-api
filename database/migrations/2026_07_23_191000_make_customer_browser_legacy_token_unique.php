<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_browser_sessions', function (Blueprint $table) {
            $table->dropIndex(['legacy_access_token_id']);
            $table->unique(
                'legacy_access_token_id',
                'customer_browser_sessions_legacy_token_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('customer_browser_sessions', function (Blueprint $table) {
            $table->dropUnique(
                'customer_browser_sessions_legacy_token_unique'
            );
            $table->index('legacy_access_token_id');
        });
    }
};
