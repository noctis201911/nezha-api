<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 哪吒[今日售罄] food 加 nezha_sold_out_date 列 = 商家「一键今日售罄」日期标记。
// null=正常在售; =今天 -> 顾客端灰置「已售罄」+挡下单/加购; 次日按日期比较自动恢复(无需定时任务)。
// 独立标记, 不碰 total_stock/sell_count(覆盖 unlimited 菜, 不污染真实销量)。默认 null -> 现有菜品不受影响。
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('food', 'nezha_sold_out_date')) {
            Schema::table('food', function (Blueprint $table) {
                $table->date('nezha_sold_out_date')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('food', 'nezha_sold_out_date')) {
            Schema::table('food', function (Blueprint $table) {
                $table->dropColumn('nezha_sold_out_date');
            });
        }
    }
};
