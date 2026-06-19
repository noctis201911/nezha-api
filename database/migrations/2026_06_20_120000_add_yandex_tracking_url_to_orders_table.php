<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Nezha B-plan: store the Yandex Go delivery share/tracking URL the merchant pastes
// after calling delivery, so the customer can open Yandex live tracking in browser.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'yandex_tracking_url')) {
                $table->string('yandex_tracking_url', 1024)->nullable()->after('order_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'yandex_tracking_url')) {
                $table->dropColumn('yandex_tracking_url');
            }
        });
    }
};
