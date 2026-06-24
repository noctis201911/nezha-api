<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 哪吒: 商家手动「暂停营业/打烊」标志。原营业状态toggle用 active=0 会让店从顾客端消失;
// 改用此独立标志(active 保持1店铺仍可见, 顾客端显"休息中"+拦下单)。纯新增列默认0, 不动现有数据。
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('restaurants', 'nezha_temp_closed')) {
            Schema::table('restaurants', function (Blueprint $table) {
                $table->boolean('nezha_temp_closed')->default(0)->after('active');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('restaurants', 'nezha_temp_closed')) {
            Schema::table('restaurants', function (Blueprint $table) {
                $table->dropColumn('nezha_temp_closed');
            });
        }
    }
};
