<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 哪吒[多级满减] discounts 加 status 列 = 商家本店满减总开关(1开/0关)。默认1→现有 admin 折扣不受影响。
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('discounts', 'status')) {
            Schema::table('discounts', function (Blueprint $table) {
                $table->tinyInteger('status')->default(1)->after('discount_type');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('discounts', 'status')) {
            Schema::table('discounts', function (Blueprint $table) {
                $table->dropColumn('status');
            });
        }
    }
};
