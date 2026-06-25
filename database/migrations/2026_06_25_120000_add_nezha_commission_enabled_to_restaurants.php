<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        if (!Schema::hasColumn('restaurants', 'nezha_commission_enabled')) {
            Schema::table('restaurants', function (Blueprint $table) {
                $table->boolean('nezha_commission_enabled')->default(0)->after('nezha_temp_closed');
            });
        }
    }
    public function down(): void {
        if (Schema::hasColumn('restaurants', 'nezha_commission_enabled')) {
            Schema::table('restaurants', function (Blueprint $table) {
                $table->dropColumn('nezha_commission_enabled');
            });
        }
    }
};
